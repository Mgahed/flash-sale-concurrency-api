<?php

namespace App\Providers;

use App\Services\HoldService;
use App\Services\HoldServiceInterface;
use App\Services\OrderService;
use App\Services\OrderServiceInterface;
use App\Services\PaymentWebhookService;
use App\Services\PaymentWebhookServiceInterface;
use App\Services\ProductService;
use App\Services\ProductServiceInterface;
use Illuminate\Support\ServiceProvider;

class ServiceBindingProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ProductServiceInterface::class, ProductService::class);
        $this->app->singleton(HoldServiceInterface::class, HoldService::class);
        $this->app->singleton(OrderServiceInterface::class, OrderService::class);
        $this->app->singleton(PaymentWebhookServiceInterface::class, PaymentWebhookService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

