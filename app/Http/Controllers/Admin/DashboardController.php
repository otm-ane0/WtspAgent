<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use App\Models\Conversation;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $orderStats = [
            'total' => Order::count(),
            'pending' => Order::whereIn('status', [
                Order::STATUS_NEW,
                Order::STATUS_COLLECTING_INFO,
                Order::STATUS_AWAITING_CONFIRM,
            ])->count(),
            'confirmed' => Order::where('status', Order::STATUS_CONFIRMED)->count(),
            'delivered' => Order::where('status', Order::STATUS_DELIVERED)->count(),
            'today' => Order::whereDate('created_at', today())->count(),
        ];

        $conversationStats = [
            'active' => Conversation::where('is_active', true)->count(),
            'in_order_flow' => Conversation::whereIn('current_state', [
                Conversation::STATE_PRODUCT_INQUIRY,
                Conversation::STATE_COLLECTING_INFO,
                Conversation::STATE_AWAITING_CONFIRM,
            ])->count(),
            'recent' => Conversation::where('last_message_at', '>=', now()->subHours(24))->count(),
        ];

        $aiStats = AiInteraction::getStatistics(now()->subDays(7));

        return response()->json([
            'orders' => $orderStats,
            'conversations' => $conversationStats,
            'ai_usage' => $aiStats,
            'timestamp' => now()->toDateTimeString(),
        ]);
    }
}
