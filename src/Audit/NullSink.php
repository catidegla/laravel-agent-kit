<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Audit;

/**
 * Records nothing.
 *
 * Only reachable by setting audit.enabled to false, which is a deliberate act
 * with a comment in the config file explaining what it costs you. There is no
 * path that lands here by accident.
 */
final class NullSink implements AuditSink
{
    public function write(AuditEvent $event): void
    {
        // Intentionally empty.
    }
}
