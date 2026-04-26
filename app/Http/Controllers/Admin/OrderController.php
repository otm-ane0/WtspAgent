<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['messages', 'aiInteractions'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->byStatus($request->input('status'));
        }

        if ($request->has('phone')) {
            $query->byPhone($request->input('phone'));
        }

        $orders = $query->paginate(20);

        return response()->json($orders);
    }

    public function show(int $id): JsonResponse
    {
        $order = Order::with(['messages', 'aiInteractions'])->findOrFail($id);

        return response()->json([
            'order' => $order,
            'status_label' => $order->getStatusLabelInDarija(),
        ]);
    }

    public function confirm(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        if (!$order->canBeModified()) {
            return response()->json([
                'success' => false,
                'message' => 'Order cannot be modified',
            ], 422);
        }

        $order->confirm();

        return response()->json([
            'success' => true,
            'order' => $order,
            'message' => 'Order confirmed successfully',
        ]);
    }

    public function cancel(int $id, Request $request): JsonResponse
    {
        $order = Order::findOrFail($id);
        $reason = $request->input('reason');

        $order->cancel($reason);

        return response()->json([
            'success' => true,
            'order' => $order,
            'message' => 'Order cancelled successfully',
        ]);
    }
}
