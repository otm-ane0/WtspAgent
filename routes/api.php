<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toDateTimeString(),
        'service' => 'wa-agent-api',
    ]);
});

Route::prefix('webhook')->group(function () {
    Route::get('/whatsapp', [WhatsAppWebhookController::class, 'verify'])
        ->name('webhook.whatsapp.verify');
    
    Route::post('/whatsapp', [WhatsAppWebhookController::class, 'handle'])
        ->name('webhook.whatsapp.handle');
    
    Route::post('/whatsapp/status', [WhatsAppWebhookController::class, 'status'])
        ->name('webhook.whatsapp.status');
    
    Route::get('/whatsapp/test', [WhatsAppWebhookController::class, 'test'])
        ->name('webhook.whatsapp.test');
});

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/orders', [\App\Http\Controllers\Admin\OrderController::class, 'index']);
    Route::get('/orders/{id}', [\App\Http\Controllers\Admin\OrderController::class, 'show']);
    Route::post('/orders/{id}/confirm', [\App\Http\Controllers\Admin\OrderController::class, 'confirm']);
    Route::post('/orders/{id}/cancel', [\App\Http\Controllers\Admin\OrderController::class, 'cancel']);
    
    Route::get('/conversations', [\App\Http\Controllers\Admin\ConversationController::class, 'index']);
    Route::get('/stats', [\App\Http\Controllers\Admin\DashboardController::class, 'stats']);
});
