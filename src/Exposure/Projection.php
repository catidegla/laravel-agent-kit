<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Exposure;

use Catidegla\AgentKit\Attributes\AgentResource;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a model into the subset an agent is allowed to see.
 *
 * This is the piece that stops the quietest leak in an MCP integration.
 * Eloquent returns every column by default, and a tool that hands the model
 * straight back sends the password hash, the Stripe customer id and whatever
 * column somebody added last week, because none of that is rendered anywhere a
 * developer would notice.
 *
 * Projection works from the declared field list only. It never reads the model
 * to decide what to include, so adding a column cannot widen exposure.
 */
final class Projection
{
    public function __construct(private readonly AgentResource $resource) {}

    /**
     * @return array<string, mixed> only the declared fields
     */
    public function apply(Model $model, int $depth = 0): array
    {
        $out = [];

        foreach ($this->resource->fields as $field) {
            // getAttribute rather than direct array access, so accessors and
            // casts apply and a hidden attribute stays hidden.
            $value = $model->getAttribute($field);

            $out[$field] = $value instanceof \DateTimeInterface
                ? $value->format(DATE_ATOM)
                : $this->scalarise($value);
        }

        return $out;
    }

    /**
     * Anything that is not a scalar is refused rather than serialised.
     *
     * A nested array or object arriving here means a cast produced something
     * structured, and passing it through would return whatever it contains
     * without any of it having been declared.
     */
    private function scalarise(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        // Deliberately lossy. Better a placeholder than an undeclared payload.
        return '[not exposed]';
    }

    /** @return string[] */
    public function fields(): array
    {
        return $this->resource->fields;
    }

    /**
     * Fields an agent may search, always a subset of what it may see. Searching
     * a field you cannot read still leaks its contents, one query at a time.
     */
    public function searchable(): array
    {
        return array_values(array_intersect($this->resource->searchable, $this->resource->fields));
    }

    public function filterable(): array
    {
        return array_values(array_intersect($this->resource->filterable, $this->resource->fields));
    }
}
