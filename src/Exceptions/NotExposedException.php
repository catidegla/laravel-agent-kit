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

    public static function relationNotExposed(string $modelClass, string $relation, array $available): self
    {
        return new self(sprintf(
            '"%s" is not a traversable relation of %s. Traversable: %s.',
            $relation,
            class_basename($modelClass),
            $available === [] ? 'none' : implode(', ', $available),
        ));
    }

    public static function relationTargetNotRegistered(string $modelClass, string $relation, string $target): self
    {
        return new self(sprintf(
            '%s declares "%s" as traversable but %s is not a registered resource. A relation is not a way to reach '.
            'a model that was never exposed: the target carries its own field list and its own policy, and without '.
            'them there is nothing to enforce. Add it to agent-kit.models, with abilities: [] if you want it '.
            'reachable only through relations.',
            class_basename($modelClass),
            $relation,
            class_basename($target),
        ));
    }

    public static function relationMissing(string $modelClass, string $relation): self
    {
        return new self(sprintf(
            '%s declares "%s" as traversable but has no such relation method.',
            class_basename($modelClass),
            $relation,
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
