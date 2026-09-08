<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Audit;

/**
 * Where audit events go.
 *
 * Implement this to write to a database table, a queue, or whatever your
 * organisation already reads during an incident. Bind your implementation to
 * this interface in a service provider and the package will use it.
 *
 * A sink is allowed to throw. What happens next is the caller's decision, and
 * by default a failed write fails the read too: see AuditTrail.
 */
interface AuditSink
{
    public function write(AuditEvent $event): void;
}
