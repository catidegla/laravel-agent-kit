<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Audit;

use Illuminate\Contracts\Log\Logger as LoggerContract;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Writes to a Laravel log channel.
 *
 * The default, because it works on a fresh install with no migration and no
 * table to forget. It is a starting point rather than a destination: a log file
 * rotates, and "what did the agent read three months ago" is a question you
 * will eventually want to answer with a query. Implement AuditSink against
 * your own store when that day comes.
 *
 * The event is passed as structured context as well as a readable line, so a
 * log driver that ships JSON keeps every field intact.
 */
final class LogSink implements AuditSink
{
    public function __construct(
        private readonly ?string $channel = null,
        private readonly string $level = 'info',
    ) {}

    public function write(AuditEvent $event): void
    {
        $this->logger()->log($this->level, $event->summary(), $event->toArray());
    }

    private function logger(): LoggerInterface|LoggerContract
    {
        return $this->channel === null ? Log::getFacadeRoot() : Log::channel($this->channel);
    }
}
