<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Audit;

/**
 * Keeps events in memory.
 *
 * Shipped rather than confined to this package's own tests, because the useful
 * thing to assert in your application's test suite is that a tool call
 * produced the audit record you expected. Bind it in place of the log sink and
 * read events() back.
 */
final class ArraySink implements AuditSink
{
    /** @var AuditEvent[] */
    private array $events = [];

    public function write(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return AuditEvent[] */
    public function events(): array
    {
        return $this->events;
    }

    public function last(): ?AuditEvent
    {
        return $this->events === [] ? null : $this->events[array_key_last($this->events)];
    }

    public function flush(): void
    {
        $this->events = [];
    }
}
