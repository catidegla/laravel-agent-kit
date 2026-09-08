<?php

declare(strict_types=1);

namespace Catidegla\AgentKit;

use Illuminate\Support\ServiceProvider;

class AgentKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-kit.php', 'agent-kit');

        $this->app->singleton(Registry::class, fn ($app) => new Registry(
            config('agent-kit.models', []),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/agent-kit.php' => config_path('agent-kit.php'),
            ], 'agent-kit-config');
        }
    }
}
