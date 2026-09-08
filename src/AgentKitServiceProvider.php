<?php

declare(strict_types=1);

namespace Catidegla\AgentKit;

use Catidegla\AgentKit\Audit\AuditSink;
use Catidegla\AgentKit\Audit\AuditTrail;
use Catidegla\AgentKit\Audit\LogSink;
use Catidegla\AgentKit\Audit\NullSink;
use Illuminate\Support\ServiceProvider;

class AgentKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-kit.php', 'agent-kit');

        // Bound to the interface so an application can swap in its own store
        // by binding AuditSink in a provider of its own, without touching this
        // one or the config.
        $this->app->singleton(AuditSink::class, fn () => config('agent-kit.audit.enabled', true)
            ? new LogSink(
                config('agent-kit.audit.channel'),
                config('agent-kit.audit.level', 'info'),
            )
            : new NullSink());

        $this->app->singleton(AuditTrail::class, fn ($app) => new AuditTrail(
            $app->make(AuditSink::class),
            (bool) config('agent-kit.audit.strict', true),
        ));

        $this->app->singleton(Registry::class, fn ($app) => new Registry(
            config('agent-kit.models', []),
            $app->make(AuditTrail::class),
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
