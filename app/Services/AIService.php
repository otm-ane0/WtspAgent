<?php

namespace App\Services;

use App\Models\AiInteraction;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI Service for NVIDIA API Integration
 * 
 * Handles LLM, Speech-to-Text, and Vision API calls
 */
class AIService
{
    private string $baseUrl;
    private string $apiKey;
    private string $llmModel;
    private string $sttModel;
    private string $visionModel;
    private int $timeout;
    private int $maxRetries;

    public function __construct()
    {
        $this->baseUrl = config('services.nvidia.base_url', 'https://integrate.api.nvidia.com');
        $this->apiKey = config('services.nvidia.api_key');
        $this->llmModel = config('services.nvidia.llm_model', 'meta/llama-3.1-405b-instruct');
        $this->sttModel = config('services.nvidia.stt_model', 'nvidia/parakeet-rnnt-1.1b');
        $this->visionModel = config('services.nvidia.vision_model', 'microsoft/kosmos-2');
        // Keep webhook processing under PHP execution limits and allow tuning from .env.
        $this->timeout = (int) config('services.nvidia.timeout', 15);
        $this->maxRetries = (int) config('services.nvidia.max_retries', 1);

        if (empty($this->apiKey)) {
            throw new \RuntimeException('NVIDIA API key not configured');
        }
    }

    /**
     * Generate response using LLM
     */
    public function generateResponse(string $prompt, array $context = [], string $systemPrompt = null): array
    {
        $startTime = microtime(true);
        $messageId = $context['message_id'] ?? null;
        $orderId = $context['order_id'] ?? null;

        $defaultSystemPrompt = $this->getDarijaSystemPrompt();
        $finalSystemPrompt = $systemPrompt ?? $defaultSystemPrompt;

        $messages = [
            [
                'role' => 'system',
                'content' => $finalSystemPrompt,
            ],
        ];

        // Add conversation history
        if (!empty($context['conversation_history'])) {
            foreach ($context['conversation_history'] as $msg) {
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                ];
            }
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $payload = [
            'model' => $this->llmModel,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 1024,
            'top_p' => 0.9,
        ];

        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/v1/chat/completions", $payload);

                if ($response->successful()) {
                    $data = $response->json();
                    $content = $data['choices'][0]['message']['content'] ?? null;
                    
                    if (empty($content)) {
                        throw new \RuntimeException('Empty response from LLM');
                    }

                    $latency = (microtime(true) - $startTime) * 1000;
                    $tokens = $data['usage']['total_tokens'] ?? 0;

                    // Log interaction
                    $this->logInteraction([
                        'message_id' => $messageId,
                        'order_id' => $orderId,
                        'service_type' => AiInteraction::SERVICE_LLM,
                        'model_name' => $this->llmModel,
                        'input_prompt' => json_encode($messages),
                        'output_response' => $content,
                        'system_prompt' => ['content' => $finalSystemPrompt],
                        'input_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                        'output_tokens' => $data['usage']['completion_tokens'] ?? 0,
                        'latency_ms' => $latency,
                        'status' => AiInteraction::STATUS_SUCCESS,
                    ]);

                    return [
                        'success' => true,
                        'response' => $this->cleanResponse($content),
                        'tokens_used' => $tokens,
                        'latency_ms' => $latency,
                    ];
                }

                throw new \RuntimeException("API returned status: {$response->status()}");

            } catch (\Exception $e) {
                $lastError = $e;
                $attempt++;
                
                if ($attempt < $this->maxRetries) {
                    usleep(500000 * $attempt); // Exponential backoff
                }
            }
        }

        // Log failed interaction
        $latency = (microtime(true) - $startTime) * 1000;
        $this->logInteraction([
            'message_id' => $messageId,
            'order_id' => $orderId,
            'service_type' => AiInteraction::SERVICE_LLM,
            'model_name' => $this->llmModel,
            'input_prompt' => json_encode($messages),
            'output_response' => null,
            'system_prompt' => ['content' => $finalSystemPrompt],
            'latency_ms' => $latency,
            'status' => AiInteraction::STATUS_ERROR,
            'error_message' => $lastError?->getMessage(),
            'retry_count' => $attempt,
        ]);

        Log::channel('ai')->error('LLM generation failed', [
            'error' => $lastError?->getMessage(),
            'retries' => $attempt,
        ]);

        return [
            'success' => false,
            'error' => $lastError?->getMessage() ?? 'Failed to generate response',
            'response' => $this->getFallbackResponse(),
        ];
    }

    /**
     * Convert audio to text using NVIDIA STT
     */
    public function transcribeAudio(string $audioUrl, array $context = []): array
    {
        $startTime = microtime(true);
        $messageId = $context['message_id'] ?? null;

        try {
            // Download audio file
            $audioContent = file_get_contents($audioUrl);
            if ($audioContent === false) {
                throw new \RuntimeException('Failed to download audio file');
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'audio_');
            file_put_contents($tempFile, $audioContent);

            // Send to NVIDIA STT API
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])
            ->attach('file', file_get_contents($tempFile), 'audio.ogg')
            ->timeout($this->timeout)
            ->post("{$this->baseUrl}/v1/stt/{$this->sttModel}");

            unlink($tempFile);

            if ($response->successful()) {
                $data = $response->json();
                $transcription = $data['text'] ?? $data['transcription'] ?? null;

                if (empty($transcription)) {
                    throw new \RuntimeException('Empty transcription result');
                }

                $latency = (microtime(true) - $startTime) * 1000;

                $this->logInteraction([
                    'message_id' => $messageId,
                    'service_type' => AiInteraction::SERVICE_STT,
                    'model_name' => $this->sttModel,
                    'input_prompt' => $audioUrl,
                    'output_response' => $transcription,
                    'latency_ms' => $latency,
                    'status' => AiInteraction::STATUS_SUCCESS,
                ]);

                return [
                    'success' => true,
                    'text' => $transcription,
                    'latency_ms' => $latency,
                ];
            }

            throw new \RuntimeException("STT API returned status: {$response->status()}");

        } catch (\Exception $e) {
            $latency = (microtime(true) - $startTime) * 1000;
            
            $this->logInteraction([
                'message_id' => $messageId,
                'service_type' => AiInteraction::SERVICE_STT,
                'model_name' => $this->sttModel,
                'input_prompt' => $audioUrl,
                'output_response' => null,
                'latency_ms' => $latency,
                'status' => AiInteraction::STATUS_ERROR,
                'error_message' => $e->getMessage(),
            ]);

            Log::channel('ai')->error('Audio transcription failed', [
                'error' => $e->getMessage(),
                'audio_url' => $audioUrl,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'text' => null,
            ];
        }
    }

    /**
     * Analyze image using NVIDIA Vision API
     */
    public function analyzeImage(string $imageUrl, string $prompt = null, array $context = []): array
    {
        $startTime = microtime(true);
        $messageId = $context['message_id'] ?? null;

        $defaultPrompt = 'اشنو كاين فهاد الصورة؟ واش هادا شي منتج؟ واش كتقدر تقول لي شنو هو بالضبط؟';
        $finalPrompt = $prompt ?? $defaultPrompt;

        try {
            // Download and encode image
            $imageContent = file_get_contents($imageUrl);
            if ($imageContent === false) {
                throw new \RuntimeException('Failed to download image');
            }

            $base64Image = base64_encode($imageContent);
            $mimeType = $this->getMimeType($imageContent);

            $payload = [
                'model' => $this->visionModel,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $finalPrompt,
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:{$mimeType};base64,{$base64Image}",
                                ],
                            ],
                        ],
                    ],
                ],
                'max_tokens' => 1024,
            ];

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])
            ->timeout($this->timeout)
            ->post("{$this->baseUrl}/v1/chat/completions", $payload);

            if ($response->successful()) {
                $data = $response->json();
                $description = $data['choices'][0]['message']['content'] ?? null;

                if (empty($description)) {
                    throw new \RuntimeException('Empty vision analysis result');
                }

                $latency = (microtime(true) - $startTime) * 1000;
                $tokens = $data['usage']['total_tokens'] ?? 0;

                $this->logInteraction([
                    'message_id' => $messageId,
                    'service_type' => AiInteraction::SERVICE_VISION,
                    'model_name' => $this->visionModel,
                    'input_prompt' => $finalPrompt,
                    'output_response' => $description,
                    'total_tokens' => $tokens,
                    'latency_ms' => $latency,
                    'status' => AiInteraction::STATUS_SUCCESS,
                ]);

                return [
                    'success' => true,
                    'description' => $this->cleanResponse($description),
                    'tokens_used' => $tokens,
                    'latency_ms' => $latency,
                ];
            }

            throw new \RuntimeException("Vision API returned status: {$response->status()}");

        } catch (\Exception $e) {
            $latency = (microtime(true) - $startTime) * 1000;

            $this->logInteraction([
                'message_id' => $messageId,
                'service_type' => AiInteraction::SERVICE_VISION,
                'model_name' => $this->visionModel,
                'input_prompt' => $finalPrompt,
                'output_response' => null,
                'latency_ms' => $latency,
                'status' => AiInteraction::STATUS_ERROR,
                'error_message' => $e->getMessage(),
            ]);

            Log::channel('ai')->error('Image analysis failed', [
                'error' => $e->getMessage(),
                'image_url' => $imageUrl,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'description' => 'ماقدرتش نحلل الصورة. جرب ترسل نص باش نقدر نعاونك.',
            ];
        }
    }

    /**
     * Get system prompt optimized for Darija responses
     */
    private function getDarijaSystemPrompt(): string
    {
        return <<<'PROMPT'
أنت مساعد مبيعات ذكي لمتجر إلكتروني مغربي. مهمتك:

1. التحدث بالدارجة المغربية فقط (مثلاً: "كيفاش", "واش", "بغيتي", "عندك", "ديالك")
2. مساعدة الزبائن في طلب المنتجات
3. جمع المعلومات التالية: الاسم الكامل، المدينة، العنوان، المنتج، الكمية
4. عدم تأكيد الطلبات إلا بعد موافقة الزبون الصريحة
5. الرد بأسلوب ودي ومختصر
6. عند طلب التأكيد، قول: "واش نأكد هاد الطلب ديالك؟"

قواعد مهمة:
- لا تستخدم اللغة الفصحى
- لا تستخدم الفرنسية
- كن واضحاً ومباشراً
- استخدم الرموز التعبيرية المناسبة

مثال على رد جيد:
"مرحبا بيك! واش بغيتي تشري شي حاجة؟ قول لي شنو خاصك واش قداش بغيتي."
PROMPT;
    }

    /**
     * Clean AI response
     */
    private function cleanResponse(string $response): string
    {
        // Remove markdown code blocks if present
        $response = preg_replace('/```[\w]*\n?/', '', $response);
        $response = str_replace('```', '', $response);
        
        // Remove extra whitespace
        $response = trim($response);
        
        // Ensure response is in reasonable length
        if (strlen($response) > 2000) {
            $response = substr($response, 0, 2000) . '...';
        }
        
        return $response;
    }

    /**
     * Get fallback response when AI fails
     */
    private function getFallbackResponse(): string
    {
        return 'سمح ليا، وقع ليا مشكل فالخدمة. جرب ترسل رسالة أخرى من فضلك.';
    }

    /**
     * Log AI interaction
     */
    private function logInteraction(array $data): void
    {
        try {
            AiInteraction::create($data);
        } catch (\Exception $e) {
            Log::channel('ai')->error('Failed to log AI interaction', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Detect MIME type from content
     */
    private function getMimeType(string $content): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $content);
        finfo_close($finfo);
        
        return $mimeType ?: 'image/jpeg';
    }

    /**
     * Check if service is healthy
     */
    public function healthCheck(): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])
            ->timeout(10)
            ->get("{$this->baseUrl}/v1/models");

            return $response->successful();
        } catch (\Exception $e) {
            Log::channel('ai')->error('Health check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
