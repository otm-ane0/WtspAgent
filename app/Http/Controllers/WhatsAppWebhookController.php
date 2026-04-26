<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\MessageProcessingService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    private WhatsAppService $whatsappService;
    private MessageProcessingService $processingService;

    public function __construct(
        WhatsAppService $whatsappService,
        MessageProcessingService $processingService
    ) {
        $this->whatsappService = $whatsappService;
        $this->processingService = $processingService;
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            Log::channel('whatsapp')->info('Webhook received', [
                'payload' => $request->all(),
            ]);

            $processedMessages = $this->whatsappService->processWebhook($request->all());

            if (empty($processedMessages)) {
                return response()->json(['success' => true, 'processed' => 0]);
            }

            foreach ($processedMessages as $messageData) {
                $message = Message::find($messageData['message_id']);
                
                if ($message) {
                    $this->processingService->process($message);
                }
            }

            return response()->json([
                'success' => true,
                'processed' => count($processedMessages),
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    public function status(Request $request): JsonResponse
    {
        try {
            $entries = $request->input('entry', []);
            
            foreach ($entries as $entry) {
                $changes = $entry['changes'] ?? [];
                
                foreach ($changes as $change) {
                    $value = $change['value'] ?? [];
                    $statuses = $value['statuses'] ?? [];
                    
                    foreach ($statuses as $status) {
                        $messageId = $status['id'] ?? null;
                        $statusValue = $status['status'] ?? null;
                        
                        if ($messageId) {
                            Message::where('message_id', $messageId)->update([
                                'raw_payload' => DB::raw("JSON_MERGE_PATCH(COALESCE(raw_payload, '{}'), '" . json_encode(['status_update' => $status]) . "')"),
                            ]);
                            
                            Log::channel('whatsapp')->info('Message status updated', [
                                'message_id' => $messageId,
                                'status' => $statusValue,
                            ]);
                        }
                    }
                }
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Status webhook error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to process status'], 500);
        }
    }

    public function verify(Request $request): Response
    {
        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        Log::channel('whatsapp')->info('Webhook verification attempt', [
            'mode' => $mode,
            'token' => $token,
        ]);

        if ($this->whatsappService->verifyWebhook($token, $mode, $challenge)) {
            Log::channel('whatsapp')->info('Webhook verified successfully');
            return response($challenge, 200);
        }

        Log::channel('whatsapp')->warning('Webhook verification failed');
        return response('Forbidden', 403);
    }

    public function test(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toDateTimeString(),
            'service' => 'WhatsApp Webhook Controller',
        ]);
    }
}
