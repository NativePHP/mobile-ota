<?php

namespace Nativephp\MobileOta;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Testing\FakeBridge;
use Nativephp\MobileOta\Commands\CopyAssetsCommand;
use Nativephp\MobileOta\Facades\Ota as OtaFacade;
use Nativephp\MobileOta\Testing\OtaMacros;

class OtaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nativephp-ota.php', 'nativephp-ota');

        $this->app->singleton(Ota::class, fn () => new Ota);

        // Test sugar (assertOtaQueued() etc.) — only under a test runner, and
        // only on a core whose FakeBridge is macroable (the method_exists
        // guard keeps older v4 and v3 cores fatal-free).
        if ($this->app->runningUnitTests()
            && class_exists(FakeBridge::class)
            && method_exists(FakeBridge::class, 'macro')) {
            OtaMacros::register();
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/nativephp-ota.php' => config_path('nativephp-ota.php'),
        ], 'nativephp-ota-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CopyAssetsCommand::class,
            ]);
        }

        if (function_exists('nativephp_call') && ! $this->app->runningUnitTests()) {
            $this->app->booted(function () {
                OtaFacade::onLaunch();
            });
        }
    }
}
