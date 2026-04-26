<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Conversation::with(['activeOrder', 'orders'])
            ->orderBy('last_message_at', 'desc');

        if ($request->has('state')) {
            $query->byState($request->input('state'));
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $conversations = $query->paginate(20);

        return response()->json($conversations);
    }

    public function show(int $id): JsonResponse
    {
        $conversation = Conversation::with(['messages', 'orders'])->findOrFail($id);

        return response()->json([
            'conversation' => $conversation,
            'recent_messages' => $conversation->getRecentMessages(10),
        ]);
    }
}
