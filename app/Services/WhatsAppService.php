<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business API Service
 * 
 * Handles Meta WhatsApp Cloud API integration
 */
class WhatsAppService
{
    private string $token;
    private string $phoneNumberId;
    private string $businessAccountId;
    private string $apiVersion;
    private string $baseUrl;

    public function __construct()
    {
        $this->token = config('services.meta.access_token');
        $this->phoneNumberId = config('services.meta.phone_number_id');
        $this->businessAccountId = config('services.meta.business_account_id');
        $this->apiVersion = config('services.meta.api_version', 'v18.0');
        $this->baseUrl = "https://graph.facebook.com/{$this->apiVersion}";

        if (empty($this->token) || empty($this->phoneNumberId)) {
            throw new \RuntimeException('WhatsApp API credentials not configured');
        }
    }

    /**
     * Send text message
     */
    public function sendMessage(string $to, string $message, array $options = []): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatPhoneNumber($to),
            'type' => 'text',
            'text' => [
                'body' => $message,
                'preview_url' => $options['preview_url'] ?? false,
            ],
        ];

        return $this->makeApiRequest($payload);
    }

    /**
     * Send template message
     */
    public function sendTemplate(string $to, string $templateName, array $parameters = [], string $language = 'ar'): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatPhoneNumber($to),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $language,
                ],
                'components' => $parameters,
            ],
        ];

        return $this->makeApiRequest($payload);
    }

    /**
     * Send media message (image, document, video)
     */
    public function sendMedia(string $to, string $mediaType, string $mediaUrl, string $caption = null): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatPhoneNumber($to),
            'type' => $mediaType,
        ];

        $payload[$mediaType] = [
            'link' => $mediaUrl,
        ];

        if ($caption) {
            $payload[$mediaType]['caption'] = $caption;
        }

        return $this->makeApiRequest($payload);
    }

    /**
     * Send interactive message with buttons
     */
    public function sendInteractiveButtons(string $to, string $body, array $buttons): array
    {
        $formattedButtons = [];
        foreach ($buttons as $index => $button) {
            $formattedButtons[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => $button['id'] ?? "btn_{$index}",
                    'title' => $button['title'],
                ],
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatPhoneNumber($to),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => [
                    'text' => $body,
                ],
                'action' => [
                    'buttons' => $formattedButtons,
                ],
            ],
        ];

        return $this->makeApiRequest($payload);
    }

    /**
     * Send location
     */
    public function sendLocation(string $to, float $latitude, float $longitude, string $name = null, string $address = null): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->formatPhoneNumber($to),
            'type' => 'location',
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
        ];

        if ($name) {
            $payload['location']['name'] = $name;
        }
        if ($address) {
            $payload['location']['address'] = $address;
        }

        return $this->makeApiRequest($payload);
    }

    /**
     * Download media file
     */
    public function downloadMedia(string $mediaId): ?string
    {
        try {
            // Get media URL
            $response = Http::withToken($this->token)
                ->get("{$this->baseUrl}/{$mediaId}");

            if (!$response->successful()) {
                throw new \RuntimeException('Failed to get media URL');
            }

            $mediaUrl = $response->json('url');
            $mimeType = $response->json('mime_type');

            // Download actual file
            $fileResponse = Http::withToken($this->token)
                ->get($mediaUrl);

            if (!$fileResponse->successful()) {
                throw new \RuntimeException('Failed to download media file');
            }

            $extension = $this->getExtensionFromMimeType($mimeType);
            $fileName = "media_{$mediaId}_{$extension}";
            $filePath = storage_path("app/whatsapp/{$fileName}");

            // Ensure directory exists
            if (!file_exists(storage_path('app/whatsapp'))) {
                mkdir(storage_path('app/whatsapp'), 0755, true);
            }

            file_put_contents($filePath, $fileResponse->body());

            return $filePath;

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Media download failed', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get media URL
     */
    public function getMediaUrl(string $mediaId): ?string
    {
        try {
            $response = Http::withToken($this->token)
                ->get("{$this->baseUrl}/{$mediaId}");

            if ($response->successful()) {
                return $response->json('url');
            }

            return null;

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Failed to get media URL', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Mark message as read
     */
    public function markAsRead(string $messageId): bool
    {
        try {
            $payload = [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId,
            ];

            $response = Http::withToken($this->token)
                ->post("{$this->baseUrl}/{$this->phoneNumberId}/messages", $payload);

            return $response->successful();

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Failed to mark message as read', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verify webhook
     */
    public function verifyWebhook(string $verifyToken, string $mode, string $challenge): bool
    {
        $expectedToken = config('services.meta.verify_token');
        
        if ($mode === 'subscribe' && $verifyToken === $expectedToken) {
            return true;
        }

        Log::channel('whatsapp')->warning('Webhook verification failed', [
            'mode' => $mode,
            'token' => $verifyToken,
        ]);

        return false;
    }

    /**
     * Process webhook payload
     */
    public function processWebhook(array $payload): array
    {
        $processed = [];

        try {
            $entries = $payload['entry'] ?? [];

            foreach ($entries as $entry) {
                $changes = $entry['changes'] ?? [];

                foreach ($changes as $change) {
                    $value = $change['value'] ?? [];
                    $messages = $value['messages'] ?? [];

                    foreach ($messages as $messageData) {
                        $processedMessage = $this->processMessage($messageData, $value);
                        if ($processedMessage) {
                            $processed[] = $processedMessage;
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

        return $processed;
    }

    /**
     * Process individual message from webhook
     */
    private function processMessage(array $messageData, array $value): ?array
    {
        $messageId = $messageData['id'] ?? null;
        $from = $messageData['from'] ?? null;
        $type = $messageData['type'] ?? 'unknown';
        $timestamp = $messageData['timestamp'] ?? null;
        $profileName = $value['contacts'][0]['profile']['name'] ?? null;

        if (empty($messageId) || empty($from)) {
            return null;
        }

        // Check for duplicate
        $existingMessage = Message::where('message_id', $messageId)->first();
        if ($existingMessage) {
            return null;
        }

        // Extract content based on message type
        $content = null;
        $mediaUrl = null;
        $mediaMimeType = null;
        $mediaCaption = null;
        $mediaSize = null;

        switch ($type) {
            case 'text':
                $content = $messageData['text']['body'] ?? null;
                break;
            
            case 'audio':
                $mediaId = $messageData['audio']['id'] ?? null;
                $mediaMimeType = $messageData['audio']['mime_type'] ?? null;
                if ($mediaId) {
                    $mediaUrl = $this->getMediaUrl($mediaId);
                }
                break;
            
            case 'image':
                $mediaId = $messageData['image']['id'] ?? null;
                $mediaMimeType = $messageData['image']['mime_type'] ?? null;
                $mediaCaption = $messageData['image']['caption'] ?? null;
                if ($mediaId) {
                    $mediaUrl = $this->getMediaUrl($mediaId);
                }
                break;
            
            case 'video':
                $mediaId = $messageData['video']['id'] ?? null;
                $mediaMimeType = $messageData['video']['mime_type'] ?? null;
                $mediaCaption = $messageData['video']['caption'] ?? null;
                if ($mediaId) {
                    $mediaUrl = $this->getMediaUrl($mediaId);
                }
                break;
            
            case 'document':
                $mediaId = $messageData['document']['id'] ?? null;
                $mediaMimeType = $messageData['document']['mime_type'] ?? null;
                $content = $messageData['document']['filename'] ?? null;
                if ($mediaId) {
                    $mediaUrl = $this->getMediaUrl($mediaId);
                }
                break;
            
            case 'location':
                $latitude = $messageData['location']['latitude'] ?? null;
                $longitude = $messageData['location']['longitude'] ?? null;
                $content = "Location: {$latitude}, {$longitude}";
                break;
            
            case 'button':
                $content = $messageData['button']['text'] ?? null;
                break;
            
            case 'interactive':
                if (isset($messageData['interactive']['button_reply'])) {
                    $content = $messageData['interactive']['button_reply']['title'] ?? null;
                } elseif (isset($messageData['interactive']['list_reply'])) {
                    $content = $messageData['interactive']['list_reply']['title'] ?? null;
                }
                break;
        }

        // Create message record
        $message = Message::create([
            'message_id' => $messageId,
            'user_phone' => $from,
            'whatsapp_profile_name' => $profileName,
            'message_type' => $type,
            'content' => $content,
            'raw_payload' => $messageData,
            'media_url' => $mediaUrl,
            'media_mime_type' => $mediaMimeType,
            'media_caption' => $mediaCaption,
            'media_size' => $mediaSize,
            'received_at' => $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now(),
        ]);

        // Update or create conversation
        $conversation = Conversation::findOrCreateByPhone($from, $profileName);
        $conversation->renewSession();
        $conversation->incrementMessageCount();

        // Mark message as read
        $this->markAsRead($messageId);

        return [
            'message_id' => $message->id,
            'user_phone' => $from,
            'message_type' => $type,
            'content' => $content,
            'conversation_id' => $conversation->id,
            'has_media' => !empty($mediaUrl),
        ];
    }

    /**
     * Make API request
     */
    private function makeApiRequest(array $payload): array
    {
        try {
            $response = Http::withToken($this->token)
                ->post("{$this->baseUrl}/{$this->phoneNumberId}/messages", $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::channel('whatsapp')->info('Message sent successfully', [
                    'message_id' => $data['messages'][0]['id'] ?? null,
                    'recipient' => $payload['to'],
                ]);

                return [
                    'success' => true,
                    'message_id' => $data['messages'][0]['id'] ?? null,
                    'data' => $data,
                ];
            }

            $error = $response->json('error.message', $response->body());
            
            Log::channel('whatsapp')->error('Failed to send message', [
                'recipient' => $payload['to'],
                'error' => $error,
                'status' => $response->status(),
            ]);

            return [
                'success' => false,
                'error' => $error,
                'status_code' => $response->status(),
            ];

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Exception sending message', [
                'recipient' => $payload['to'],
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Format phone number
     */
    private function formatPhoneNumber(string $phone): string
    {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Ensure it has country code
        if (strlen($phone) == 9) {
            $phone = '212' . $phone; // Morocco
        } elseif (strlen($phone) == 10 && $phone[0] == '0') {
            $phone = '212' . substr($phone, 1);
        }
        
        return $phone;
    }

    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $map = [
            'audio/ogg' => '.ogg',
            'audio/mpeg' => '.mp3',
            'audio/wav' => '.wav',
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'video/mp4' => '.mp4',
            'application/pdf' => '.pdf',
            'text/plain' => '.txt',
        ];

        return $map[$mimeType] ?? '.bin';
    }
}
