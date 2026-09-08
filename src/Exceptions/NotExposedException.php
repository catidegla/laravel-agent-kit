<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Exceptions;

use RuntimeException;

final class NotExposedException extends RuntimeException
{
    public static function noPolicy(string $modelClass): self
    {
        return new self(sprintf(
            '%s is marked #[AgentResource] but has no registered policy. Exposure without a policy is refused '.
            'rather than allowed, because forgetting to write one would otherwise publish the table. '.
            'Register a policy with a "view" method, or remove the attribute.',
            class_basename($modelClass),
        ));
    }

    public static function notAResource(string $modelClass): self
    {
        return new self(sprintf(
            '%s has no #[AgentResource] attribute, so it is not reachable by an agent.',
            class_basename($modelClass),
        ));
    }

    public static function fieldNotExposed(string $modelClass, string $field, array $available): self
    {
        return new self(sprintf(
            '"%s" is not an exposed field of %s. Exposed: %s.',
            $field,
            class_basename($modelClass),
            $available === [] ? 'none' : implode(', ', $available),
        ));
    }
}
