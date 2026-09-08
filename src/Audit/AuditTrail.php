<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Audit;

use Catidegla\AgentKit\Exceptions\AuditFailedException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records what an agent read, and decides what happens when it cannot.
 *
 * Strict by default, which means a sink that throws takes the read down with
 * it. Everything else in this package fails closed and an audit trail that
 * quietly degraded would be the one exception, in the one place where a gap is
 * worth the most to whoever caused it.
 */
final class AuditTrail
{
    public function __construct(
        private readonly AuditSink $sink,
        private readonly bool $strict = true,
    ) {}

    /**
     * @throws AuditFailedException in strict mode, when the sink refuses
     */
    public function record(AuditEvent $event): void
    {
        try {
            $this->sink->write($event);
        } catch (Throwable $e) {
            if ($this->strict) {
                throw AuditFailedException::sinkFailed($event, $e);
            }

            $this->reportQuietly($event, $e);
        }
    }

    /**
     * An actor identifier, never the user object.
     *
     * A name or an email in an audit line is personal data in a second place,
     * and the identifier is enough to find the account.
     */
    public static function actor(?Authenticatable $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    /**
     * Lenient mode still says something, because a trail that stopped without
     * a word is indistinguishable from one that had nothing to record.
     */
    private function reportQuietly(AuditEvent $event, Throwable $e): void
    {
        try {
            Log::error('agent-kit could not record an audit event, and served the result anyway', [
                'event' => $event->toArray(),
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // The fallback logger is the last thing available; if it is also
            // gone there is nowhere left to say so.
        }
    }
}
