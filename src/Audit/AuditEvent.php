<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Audit;

/**
 * One answered question: what did the agent ask for, and what did it get.
 *
 * The record holds identifiers and field names, never field values. That is
 * the whole discipline of this class. An audit trail that stored the rows it
 * saw would become a second copy of every record an agent ever read, sitting
 * in a table nobody wrote a policy for, and the log would be a softer target
 * than the data it was meant to protect.
 *
 * What you can answer from this: which records were exposed, to whom, when,
 * through which tool, and which fields of them. To see the values, go and read
 * those ids from the source, where the policy still applies.
 *
 * `arguments` is the exception, and deliberately so: it is what the agent
 * supplied, not what the database returned. A search term is therefore stored,
 * because "what was it looking for" is half of any incident. Treat it as
 * untrusted text when you read it back; it can carry whatever a prompt put
 * there.
 */
final class AuditEvent
{
    public const OK = 'ok';
    public const DENIED = 'denied';
    public const MISSING = 'missing';
    public const REFUSED = 'refused';

    /**
     * @param string               $resource   the tool name the agent called
     * @param string               $operation  list, get or search
     * @param string               $outcome    ok, denied, missing or refused
     * @param string|null          $actor      the identifier the agent acted as
     * @param array<string, mixed> $arguments  what the agent supplied
     * @param array<int, mixed>    $ids        identifiers of the records returned
     * @param string[]             $fields     field names exposed, not their values
     * @param int                  $denied     records withheld by policy
     */
    public function __construct(
        public readonly string $resource,
        public readonly string $modelClass,
        public readonly string $operation,
        public readonly string $outcome,
        public readonly ?string $actor,
        public readonly array $arguments = [],
        public readonly array $ids = [],
        public readonly array $fields = [],
        public readonly int $denied = 0,
        public readonly bool $truncated = false,
        public readonly ?string $at = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'at' => $this->at ?? gmdate(DATE_ATOM),
            'resource' => $this->resource,
            'model' => $this->modelClass,
            'operation' => $this->operation,
            'outcome' => $this->outcome,
            'actor' => $this->actor,
            'arguments' => $this->arguments,
            'ids' => $this->ids,
            'fields' => $this->fields,
            'returned' => count($this->ids),
            'denied' => $this->denied,
            'truncated' => $this->truncated,
        ];
    }

    /**
     * A single line for a log file, which is where most of these will live.
     */
    public function summary(): string
    {
        return sprintf(
            'agent %s %s.%s -> %s (%d returned, %d denied%s)',
            $this->actor === null ? 'anonymous' : $this->actor,
            $this->resource,
            $this->operation,
            $this->outcome,
            count($this->ids),
            $this->denied,
            $this->truncated ? ', truncated' : '',
        );
    }
}
