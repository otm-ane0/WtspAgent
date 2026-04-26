<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

class MessageProcessingService
{
    public function __construct(
        private readonly AIService $aiService,
        private readonly WhatsAppService $whatsAppService,
        private readonly OrderManagementService $orderManagementService
    ) {
    }

    public function process(Message $message): void
    {
        if (!$message->needsProcessing()) {
            $message->markAsProcessed();
            return;
        }

        $message->markAsProcessing();

        try {
            $conversation = Conversation::firstOrCreate(
                ['user_phone' => $message->user_phone],
                [
                    'profile_name' => $message->whatsapp_profile_name,
                    'last_message_at' => now(),
                ]
            );

            $conversation->incrementMessageCount();
            if ($conversation->isSessionExpired()) {
                $conversation->renewSession();
            }

            $content = $this->resolveMessageContent($message);
            if (empty($content)) {
                $fallback = 'I could not understand your message. Please send text or a clear media message.';
                $this->whatsAppService->sendMessage($message->user_phone, $fallback);
                $message->markAsFailed('No content extracted for processing.');
                return;
            }

            $result = $this->orderManagementService->processOrderMessage($conversation, $content, [
                'message_id' => $message->id,
                'message_type' => $message->message_type,
            ]);

            $replyText = $result['response'] ?? 'Received. Please continue with your order details.';
            $sendResult = $this->whatsAppService->sendMessage($message->user_phone, $replyText);

            $message->markAsProcessed($replyText, [
                'action' => $result['action'] ?? null,
                'order_id' => $result['order_id'] ?? null,
                'send_success' => (bool) ($sendResult['success'] ?? false),
                'send_message_id' => $sendResult['message_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('Message processing failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            $message->markAsFailed($e->getMessage());
        }
    }

    private function resolveMessageContent(Message $message): ?string
    {
        if ($message->message_type === Message::TYPE_AUDIO && !empty($message->media_url)) {
            $transcription = $this->aiService->transcribeAudio($message->media_url, [
                'message_id' => $message->id,
            ]);

            if (!empty($transcription['success']) && !empty($transcription['text'])) {
                $message->update(['processed_content' => $transcription['text']]);
                return $transcription['text'];
            }
        }

        if ($message->message_type === Message::TYPE_IMAGE && !empty($message->media_url)) {
            $analysis = $this->aiService->analyzeImage($message->media_url, null, [
                'message_id' => $message->id,
            ]);

            if (!empty($analysis['success']) && !empty($analysis['description'])) {
                $message->update(['processed_content' => $analysis['description']]);
                return $analysis['description'];
            }
        }

        return $message->getContentForProcessing();
    }
}
