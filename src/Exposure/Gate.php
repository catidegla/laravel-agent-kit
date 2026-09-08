<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Exposure;

use Catidegla\AgentKit\Attributes\AgentResource;
use Catidegla\AgentKit\Exceptions\NotExposedException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate as LaravelGate;

/**
 * Authorization, per record, every time.
 *
 * The agent acts as a signed in user, never as the application. That
 * distinction is the whole point: an agent with application level access can
 * read every tenant's data the moment a prompt talks it into asking, and no
 * amount of careful tool descriptions prevents that.
 *
 * Two rules, both of which fail closed:
 *
 * 1. A resource with no registered policy is refused, not exposed. The
 *    alternative is that forgetting to write a policy silently publishes a
 *    table.
 * 2. Every record is checked individually. Filtering a query by user id is not
 *    a substitute, because the next person to add a scope or a relation gets to
 *    be the one who breaks it.
 */
final class Gate
{
    public function __construct(private readonly AgentResource $resource) {}

    /**
     * @throws NotExposedException when the model has no policy at all
     */
    public function assertPolicyExists(string $modelClass): void
    {
        if (LaravelGate::getPolicyFor($modelClass) === null) {
            throw NotExposedException::noPolicy($modelClass);
        }
    }

    public function allows(?Authenticatable $user, Model $model): bool
    {
        if ($user === null) {
            // An unauthenticated agent has no records of its own to read.
            return false;
        }

        return LaravelGate::forUser($user)->allows($this->resource->ability, $model);
    }

    /**
     * Filter a result set down to what this user may actually see.
     *
     * The count of what was removed is returned alongside, because a tool that
     * silently drops rows makes an agent believe a list is complete when it is
     * not, and that produces confidently wrong answers rather than errors.
     *
     * @param  iterable<Model> $models
     * @return array{0: array<Model>, 1: int}
     */
    public function filter(?Authenticatable $user, iterable $models): array
    {
        $allowed = [];
        $denied = 0;

        foreach ($models as $model) {
            if ($this->allows($user, $model)) {
                $allowed[] = $model;
            } else {
                $denied++;
            }
        }

        return [$allowed, $denied];
    }
}
