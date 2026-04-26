<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Order Management Service
 * 
 * Handles order lifecycle and state management
 */
class OrderManagementService
{
    private AIService $aiService;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Process incoming message in order flow context
     */
    public function processOrderMessage(Conversation $conversation, string $content, array $metadata = []): array
    {
        $activeOrder = $conversation->activeOrder;
        $currentState = $conversation->current_state;

        // Extract intent and information using AI
        $extractionPrompt = $this->buildExtractionPrompt($content, $conversation);
        
        $aiResult = $this->aiService->generateResponse($extractionPrompt, [
            'conversation_id' => $conversation->id,
            'order_id' => $activeOrder?->id,
        ]);

        if (!$aiResult['success']) {
            return [
                'action' => 'error',
                'response' => $aiResult['response'],
            ];
        }

        $extractedData = $this->parseExtractedData($aiResult['response']);
        
        // Handle based on current state and extracted intent
        return match ($currentState) {
            Conversation::STATE_PRODUCT_INQUIRY => $this->handleProductInquiry($conversation, $extractedData),
            Conversation::STATE_COLLECTING_INFO => $this->handleInfoCollection($conversation, $activeOrder, $extractedData),
            Conversation::STATE_AWAITING_CONFIRM => $this->handleConfirmation($conversation, $activeOrder, $extractedData, $content),
            default => $this->initiateOrderFlow($conversation, $extractedData),
        };
    }

    /**
     * Initiate new order flow
     */
    public function initiateOrderFlow(Conversation $conversation, array $extractedData): array
    {
        // Create new order
        $order = Order::create([
            'phone' => $conversation->user_phone,
            'status' => Order::STATUS_COLLECTING_INFO,
            'collected_data' => [
                'started_at' => now()->toDateTimeString(),
                'extracted_info' => $extractedData,
            ],
        ]);

        $conversation->setActiveOrder($order->id);
        $conversation->transitionTo(Conversation::STATE_COLLECTING_INFO);

        // Determine what information to ask for first
        $missing = $order->getMissingFields();
        $firstField = $missing[0] ?? null;

        if ($firstField) {
            $response = $this->getPromptForField($firstField);
        } else {
            // Move to confirmation if all fields present
            $conversation->transitionTo(Conversation::STATE_AWAITING_CONFIRM);
            $response = $order->getSummaryForDarija() . "\n\n" . "واش نأكد هاد الطلب ديالك؟ جاوب بـ 'ايه' أو 'لا'";
        }

        return [
            'action' => 'ask_info',
            'order_id' => $order->id,
            'response' => $response,
        ];
    }

    /**
     * Handle product inquiry
     */
    private function handleProductInquiry(Conversation $conversation, array $data): array
    {
        $product = $data['product'] ?? null;
        
        if ($product) {
            // Check product catalog
            $productInfo = $this->getProductInfo($product);
            
            if ($productInfo) {
                $conversation->transitionTo(Conversation::STATE_COLLECTING_INFO);
                $order = $conversation->activeOrder;
                
                if ($order) {
                    $order->update(['product' => $product]);
                }
                
                return [
                    'action' => 'product_found',
                    'response' => $productInfo['description'] . "\n\n" . 
                        "الثمن: {$productInfo['price']} درهم\n" .
                        "بغيتي تكمل الطلب؟ قول لي: الاسم، المدينة، العنوان، وقداش بغيتي",
                ];
            }
        }

        return [
            'action' => 'product_not_found',
            'response' => "ما عرفتش هاد المنتج. واش تقدر توضح لي أكثر؟ ولا شوف المنتجات اللي عندنا:\n" .
                $this->getAvailableProductsList(),
        ];
    }

    /**
     * Handle information collection
     */
    private function handleInfoCollection(Conversation $conversation, ?Order $order, array $data): array
    {
        if (!$order) {
            return $this->initiateOrderFlow($conversation, $data);
        }

        // Update order with extracted data
        $updates = [];
        
        if (!empty($data['customer_name'])) {
            $updates['customer_name'] = $this->sanitizeName($data['customer_name']);
        }
        if (!empty($data['city'])) {
            $updates['city'] = $this->sanitizeCity($data['city']);
        }
        if (!empty($data['address'])) {
            $updates['address'] = $this->sanitizeAddress($data['address']);
        }
        if (!empty($data['product'])) {
            $updates['product'] = $data['product'];
        }
        if (!empty($data['quantity']) && is_numeric($data['quantity'])) {
            $updates['quantity'] = (int) $data['quantity'];
        }

        if (!empty($updates)) {
            $order->update($updates);
            
            // Merge with collected_data
            $collectedData = $order->collected_data ?? [];
            $collectedData = array_merge($collectedData, $updates);
            $order->update(['collected_data' => $collectedData]);
        }

        // Check if all required fields are filled
        $missing = $order->getMissingFields();

        if (empty($missing)) {
            // All info collected, move to confirmation
            $conversation->transitionTo(Conversation::STATE_AWAITING_CONFIRM);
            $order->update(['status' => Order::STATUS_AWAITING_CONFIRM]);
            
            return [
                'action' => 'awaiting_confirmation',
                'response' => $order->getSummaryForDarija() . "\n\n" .
                    "✅ *واش نأكد هاد الطلب ديالك؟*\n\n" .
                    "جاوب بـ:\n" .
                    "• *ايه* - باش نأكد الطلب\n" .
                    "• *لا* - باش نبدل شي حاجة\n" .
                    "• *لغي* - باش نلغي الطلب",
            ];
        }

        // Ask for next missing field
        $nextField = $missing[0];
        
        // Acknowledge what was received
        $acknowledgment = $this->buildAcknowledgment($updates);
        $prompt = $this->getPromptForField($nextField);
        
        return [
            'action' => 'ask_info',
            'response' => $acknowledgment ? $acknowledgment . "\n\n" . $prompt : $prompt,
        ];
    }

    /**
     * Handle order confirmation
     */
    private function handleConfirmation(Conversation $conversation, ?Order $order, array $data, string $rawContent): array
    {
        if (!$order) {
            return [
                'action' => 'error',
                'response' => 'وقع مشكل فالنظام. حاول تبدأ من الأول.',
            ];
        }

        $confirmation = $this->parseConfirmationResponse($rawContent);

        switch ($confirmation) {
            case 'confirm':
                return $this->confirmOrder($conversation, $order);
                
            case 'cancel':
                return $this->cancelOrder($conversation, $order);
                
            case 'modify':
                return $this->handleModification($conversation, $order);
                
            default:
                return [
                    'action' => 'clarify',
                    'response' => "مافهمتش. واش بغيتي:\n" .
                        "• *ايه* - تأكيد الطلب\n" .
                        "• *لا* - تعديل شي حاجة\n" .
                        "• *لغي* - إلغاء الطلب",
                ];
        }
    }

    /**
     * Confirm order
     */
    public function confirmOrder(Conversation $conversation, Order $order): array
    {
        // Validate order data
        $validation = $this->validateOrderData($order);
        
        if (!$validation['valid']) {
            $conversation->transitionTo(Conversation::STATE_COLLECTING_INFO);
            
            return [
                'action' => 'validation_failed',
                'response' => "مازال خاصنا شي معلومات:\n" . 
                    implode("\n", $validation['errors']) . "\n\n" .
                    "جاوبني بش نكمل الطلب.",
            ];
        }

        // Confirm order
        $order->confirm();
        $conversation->incrementOrderCount();
        $conversation->clearActiveOrder();
        $conversation->transitionTo(Conversation::STATE_ORDER_CONFIRMED);

        // Notify admin
        $this->notifyAdmin($order);

        return [
            'action' => 'order_confirmed',
            'order_id' => $order->id,
            'response' => "✅ *تم تأكيد الطلب بنجاح!*\n\n" .
                $order->getSummaryForDarija() . "\n\n" .
                "رقم الطلب ديالك: *#{$order->id}*\n" .
                "غادي نتواصلو معاك فأقرب وقت. شكراً لك! 🙏",
        ];
    }

    /**
     * Cancel order
     */
    public function cancelOrder(Conversation $conversation, Order $order, string $reason = null): array
    {
        $order->cancel($reason);
        $conversation->clearActiveOrder();
        $conversation->transitionTo(Conversation::STATE_IDLE);

        return [
            'action' => 'order_cancelled',
            'response' => "❌ *تم إلغاء الطلب.*\n\n" .
                "إلا بغيتي شي حاجة أخرى، مرحبا!",
        ];
    }

    /**
     * Handle order modification request
     */
    private function handleModification(Conversation $conversation, Order $order): array
    {
        $conversation->transitionTo(Conversation::STATE_COLLECTING_INFO);
        $order->update(['status' => Order::STATUS_COLLECTING_INFO]);

        $missing = $order->getMissingFields();

        if (empty($missing)) {
            return [
                'action' => 'ask_what_to_modify',
                'response' => "شنو بغيتي تبدل؟ قول لي شنو خاصك تعدل:\n" .
                    "• الاسم\n" .
                    "• المدينة\n" .
                    "• العنوان\n" .
                    "• المنتج\n" .
                    "• الكمية\n\n" .
                    "ولا كتب *تأكيد* باش نمشي للتأكيد.",
            ];
        }

        return [
            'action' => 'ask_info',
            'response' => "مازال خاصنا هاد المعلومات:\n" .
                implode("، ", $missing) . "\n\n" .
                "شحال عندك من وقت باش تكمل؟",
        ];
    }

    /**
     * Validate order data
     */
    private function validateOrderData(Order $order): array
    {
        $validator = Validator::make([
            'customer_name' => $order->customer_name,
            'phone' => $order->phone,
            'city' => $order->city,
            'address' => $order->address,
            'product' => $order->product,
            'quantity' => $order->quantity,
        ], [
            'customer_name' => 'required|string|min:3|max:255',
            'phone' => 'required|string|regex:/^([0-9\s\-\+\(\)]*)$/|min:9',
            'city' => 'required|string|min:2|max:100',
            'address' => 'required|string|min:5|max:500',
            'product' => 'required|string|min:2|max:255',
            'quantity' => 'required|integer|min:1|max:1000',
        ]);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->all(),
            ];
        }

        return ['valid' => true];
    }

    /**
     * Build extraction prompt for AI
     */
    private function buildExtractionPrompt(string $content, Conversation $conversation): string
    {
        $currentData = $conversation->activeOrder?->collected_data ?? [];
        $currentState = $conversation->current_state;

        $prompt = "حلل هاد الرسالة وجبد المعلومات المهمة:\n\n";
        $prompt .= "الرسالة: \"{$content}\"\n\n";
        $prompt .= "الحالة الحالية: {$currentState}\n";
        $prompt .= "المعلومات اللي جمعناها: " . json_encode($currentData, JSON_UNESCAPED_UNICODE) . "\n\n";
        $prompt .= "رد فقط بهاد الشكل (JSON):\n";
        $prompt .= '{"intent": "order|inquiry|confirmation|modification|cancellation", ';
        $prompt .= '"customer_name": "الاسم", ';
        $prompt .= '"city": "المدينة", ';
        $prompt .= '"address": "العنوان", ';
        $prompt .= '"product": "المنتج", ';
        $prompt .= '"quantity": الرقم, ';
        $prompt .= '"confirmation": true/false, ';
        $prompt .= '"modification_requested": true/false, ';
        $prompt .= '"cancel": true/false}';

        return $prompt;
    }

    /**
     * Parse extracted data from AI response
     */
    private function parseExtractedData(string $response): array
    {
        // Try to extract JSON
        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $json = $matches[0];
            $data = json_decode($json, true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Fallback: parse manually
        $data = [];
        
        if (str_contains($response, 'طلب') || str_contains($response, 'شراء')) {
            $data['intent'] = 'order';
        }
        
        // Extract name
        if (preg_match('/(?:اسمي|سميتي|أنا)\s*[:\s]*([^\n,]+)/u', $response, $matches)) {
            $data['customer_name'] = trim($matches[1]);
        }
        
        // Extract city
        if (preg_match('/(?:مدينة|ساكن\s+ف|من)\s*[:\s]*([^\n,]+)/u', $response, $matches)) {
            $data['city'] = trim($matches[1]);
        }
        
        // Extract product
        if (preg_match('/(?:بغيت|حاب|عندك|بغي\s+نشرى)\s*[:\s]*([^\n,]+)/u', $response, $matches)) {
            $data['product'] = trim($matches[1]);
        }
        
        // Extract quantity
        if (preg_match('/(\d+)\s*(?:وحدة|حبة|كيلو|طن|عدد)/u', $response, $matches)) {
            $data['quantity'] = (int) $matches[1];
        }

        return $data;
    }

    /**
     * Parse confirmation response
     */
    private function parseConfirmationResponse(string $content): string
    {
        $content = strtolower(trim($content));
        
        $confirmWords = ['ايه', 'نعم', 'أيه', 'آيه', 'اي', 'ok', 'yes', 'confirm', 'تأكيد'];
        $cancelWords = ['لغي', ' cancel', ' cancel', 'cancel', 'canceled', 'cancelled'];
        $modifyWords = ['لا', 'لأ', 'no', 'modify', 'edit', 'change', 'تعديل', 'بدل'];

        foreach ($confirmWords as $word) {
            if (str_contains($content, $word)) {
                return 'confirm';
            }
        }

        foreach ($cancelWords as $word) {
            if (str_contains($content, $word)) {
                return 'cancel';
            }
        }

        foreach ($modifyWords as $word) {
            if (str_contains($content, $word)) {
                return 'modify';
            }
        }

        return 'unknown';
    }

    /**
     * Get prompt for specific field
     */
    private function getPromptForField(string $field): string
    {
        $prompts = [
            'customer_name' => '👤 *شنو اسمك الكامل؟*',
            'city' => '🏙️ *منين كاين؟ (المدينة)*',
            'address' => '📍 *شنو عنوانك بالضبط؟*',
            'product' => '📦 *شنو المنتج اللي بغيتي؟*',
            'quantity' => '🔢 *قداش بغيتي؟ (الكمية)*',
        ];

        return $prompts[$field] ?? 'خاصك تكمل المعلومات. شنو خاصك تزيد؟';
    }

    /**
     * Build acknowledgment for collected data
     */
    private function buildAcknowledgment(array $updates): ?string
    {
        if (empty($updates)) {
            return null;
        }

        $ack = '✓ *تم حفظ:* ';
        $parts = [];

        if (!empty($updates['customer_name'])) {
            $parts[] = 'الاسم';
        }
        if (!empty($updates['city'])) {
            $parts[] = 'المدينة';
        }
        if (!empty($updates['address'])) {
            $parts[] = 'العنوان';
        }
        if (!empty($updates['product'])) {
            $parts[] = 'المنتج';
        }
        if (!empty($updates['quantity'])) {
            $parts[] = 'الكمية';
        }

        return $ack . implode('، ', $parts);
    }

    /**
     * Get product info from catalog
     */
    private function getProductInfo(string $productName): ?array
    {
        $catalog = $this->loadProductCatalog();
        $productName = strtolower(trim($productName));

        foreach ($catalog as $product) {
            if (str_contains(strtolower($product['name']), $productName) ||
                str_contains($productName, strtolower($product['name']))) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Get available products list
     */
    private function getAvailableProductsList(): string
    {
        $catalog = $this->loadProductCatalog();
        $list = "📋 *المنتجات المتوفرة:*\n\n";

        foreach ($catalog as $product) {
            $list .= "• {$product['name']} - {$product['price']} درهم/{$product['unit']}\n";
        }

        return $list;
    }

    /**
     * Load product catalog
     */
    private function loadProductCatalog(): array
    {
        $path = config('services.products.catalog_file');
        
        if (file_exists($path)) {
            $content = file_get_contents($path);
            return json_decode($content, true) ?? [];
        }

        // Default catalog
        return [
            [
                'name' => 'الزيتون',
                'price' => 35,
                'unit' => 'كيلو',
                'description' => 'زيتون مغربي أصلي وعالي الجودة',
            ],
            [
                'name' => 'الزيت',
                'price' => 60,
                'unit' => 'لتر',
                'description' => 'زيت زيتون بكر ممتاز',
            ],
            [
                'name' => 'العسل',
                'price' => 150,
                'unit' => 'كيلو',
                'description' => 'عسل طبيعي 100%',
            ],
            [
                'name' => 'اللوز',
                'price' => 80,
                'unit' => 'كيلو',
                'description' => 'لوز مغربي فاخر',
            ],
        ];
    }

    /**
     * Notify admin about new order
     */
    private function notifyAdmin(Order $order): void
    {
        // TODO: Implement notification (email, SMS, dashboard)
        Log::channel('orders')->info('New order confirmed', [
            'order_id' => $order->id,
            'customer' => $order->customer_name,
            'phone' => $order->phone,
            'product' => $order->product,
            'quantity' => $order->quantity,
        ]);
    }

    /**
     * Sanitize name
     */
    private function sanitizeName(string $name): string
    {
        return trim(preg_replace('/[^\p{L}\s\-]/u', '', $name));
    }

    /**
     * Sanitize city
     */
    private function sanitizeCity(string $city): string
    {
        return trim(preg_replace('/[^\p{L}\s\-]/u', '', $city));
    }

    /**
     * Sanitize address
     */
    private function sanitizeAddress(string $address): string
    {
        return trim(substr($address, 0, 500));
    }

    /**
     * Get order statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_orders' => Order::count(),
            'pending' => Order::whereIn('status', [Order::STATUS_NEW, Order::STATUS_COLLECTING_INFO])->count(),
            'awaiting_confirmation' => Order::where('status', Order::STATUS_AWAITING_CONFIRM)->count(),
            'confirmed' => Order::where('status', Order::STATUS_CONFIRMED)->count(),
            'delivered' => Order::where('status', Order::STATUS_DELIVERED)->count(),
            'cancelled' => Order::where('status', Order::STATUS_CANCELLED)->count(),
            'today' => Order::whereDate('created_at', today())->count(),
            'this_week' => Order::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
        ];
    }
}
