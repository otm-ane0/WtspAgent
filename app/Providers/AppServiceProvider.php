<?php

namespace App\Providers;

use App\Services\AIService;
use App\Services\MessageProcessingService;
use App\Services\OrderManagementService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AIService::class, function ($app) {
            return new AIService();
        });

        $this->app->singleton(WhatsAppService::class, function ($app) {
            return new WhatsAppService();
        });

        $this->app->singleton(OrderManagementService::class, function ($app) {
            return new OrderManagementService($app->make(AIService::class));
        });

        $this->app->singleton(MessageProcessingService::class, function ($app) {
            return new MessageProcessingService(
                $app->make(AIService::class),
                $app->make(WhatsAppService::class),
                $app->make(OrderManagementService::class)
            );
        });
    }

    public function boot(): void
    {
        Log::info('Application booted');
    }
}
