<?php

namespace NoriaLabs\Payments\Providers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use NoriaLabs\Payments\MpesaClient;
use NoriaLabs\Payments\PaymentsManager;
use NoriaLabs\Payments\SasaPayCallbackVerifier;
use NoriaLabs\Payments\SasaPayClient;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/payments.php', 'payments');

        $this->app->singleton(PaymentsManager::class, function ($app): PaymentsManager {
            return new PaymentsManager(
                http: $app->make(Factory::class),
                config: $app['config'],
                cache: $app->bound(CacheFactory::class) ? $app->make(CacheFactory::class) : null,
            );
        });

        $this->app->bind(MpesaClient::class, function ($app): MpesaClient {
            return $app->make(PaymentsManager::class)->mpesa();
        });

        $this->app->bind(SasaPayClient::class, function ($app): SasaPayClient {
            return $app->make(PaymentsManager::class)->sasapay();
        });

        $this->app->bind(SasaPayCallbackVerifier::class, function ($app): SasaPayCallbackVerifier {
            return $app->make(PaymentsManager::class)->sasapayCallbackVerifier();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/payments.php' => config_path('payments.php'),
        ], 'payments-config');
    }
}
