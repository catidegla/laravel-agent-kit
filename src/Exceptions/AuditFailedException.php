<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Exceptions;

use Catidegla\AgentKit\Audit\AuditEvent;
use RuntimeException;
use Throwable;

/**
 * The read happened but could not be recorded, so the caller is not given the
 * result.
 *
 * This is the least popular decision in the package and the one most likely to
 * be turned off, so the reasoning is worth stating. The purpose of an audit
 * trail is to answer "what did the agent read" after something goes wrong. A
 * trail with silent gaps in it cannot answer that, and the moment a gap opens
 * is exactly the moment an attacker would choose to be reading. Serving data
 * you cannot account for is the failure this package exists to prevent.
 *
 * Set agent-kit.audit.strict to false if availability matters more to you than
 * a complete record. That is a legitimate trade and it is yours to make.
 */
final class AuditFailedException extends RuntimeException
{
    public static function sinkFailed(AuditEvent $event, Throwable $previous): self
    {
        return new self(sprintf(
            'The audit sink refused to record %s.%s, so the result was withheld rather than served unrecorded. '.
            'Set agent-kit.audit.strict to false to serve anyway. Underlying error: %s',
            $event->resource,
            $event->operation,
            $previous->getMessage(),
        ), 0, $previous);
    }
}
