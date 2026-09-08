<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Exposure;

use Catidegla\AgentKit\Attributes\AgentResource;
use Catidegla\AgentKit\Exceptions\NotExposedException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * One exposed model, and the only three things an agent may do with it.
 *
 * Every operation applies the same three constraints in the same order: cap the
 * number of rows, authorize each one individually, then project it down to the
 * declared fields. Doing them in that order matters. Projecting before
 * authorizing would mean building a payload the caller is not allowed to see,
 * and capping after authorizing would let a denied row consume a slot.
 */
final class Resource
{
    public readonly Projection $projection;
    public readonly Gate $gate;

    private function __construct(
        public readonly string $modelClass,
        public readonly AgentResource $attribute,
    ) {
        $this->projection = new Projection($attribute);
        $this->gate = new Gate($attribute);
    }

    /**
     * @throws NotExposedException when the model is not exposed, or is exposed without a policy
     */
    public static function for(string $modelClass): self
    {
        $reflection = new ReflectionClass($modelClass);
        $attributes = $reflection->getAttributes(AgentResource::class);

        if ($attributes === []) {
            throw NotExposedException::notAResource($modelClass);
        }

        $resource = new self($modelClass, $attributes[0]->newInstance());
        $resource->gate->assertPolicyExists($modelClass);

        return $resource;
    }

    public static function isExposed(string $modelClass): bool
    {
        return self::attributeFor($modelClass) !== null;
    }

    /**
     * Read the attribute without asserting a policy exists.
     *
     * Verification needs to report attribute problems and policy problems in
     * the same pass, so it cannot go through for(), which refuses early.
     */
    public static function attributeFor(string $modelClass): ?AgentResource
    {
        if (! class_exists($modelClass)) {
            return null;
        }

        $attributes = (new ReflectionClass($modelClass))->getAttributes(AgentResource::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    public function name(): string
    {
        return $this->attribute->name ?? str($this->modelClass)->classBasename()->snake()->toString();
    }

    public function description(): string
    {
        return $this->attribute->description ?? sprintf('%s records.', class_basename($this->modelClass));
    }

    public function abilities(): array
    {
        return $this->attribute->abilities;
    }

    /** Never above the declared ceiling, whatever the agent asks for. */
    public function cap(?int $requested): int
    {
        $max = $this->attribute->maxResults;

        if ($requested === null || $requested < 1) {
            return min(10, $max);
        }

        return min($requested, $max);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array, denied: int, truncated: bool}
     */
    public function list(?Authenticatable $user, array $filters = [], ?int $limit = null): array
    {
        $limit = $this->cap($limit);
        $query = $this->modelClass::query();

        foreach ($filters as $field => $value) {
            if (! in_array($field, $this->projection->filterable(), true)) {
                throw NotExposedException::fieldNotExposed($this->modelClass, $field, $this->projection->filterable());
            }
            $query->where($field, $value);
        }

        return $this->authorizeAndProject($user, $query->limit($limit + 1)->get(), $limit);
    }

    public function get(?Authenticatable $user, mixed $id): ?array
    {
        $model = $this->modelClass::query()->find($id);

        if ($model === null || ! $this->gate->allows($user, $model)) {
            // A denied record and a missing one answer identically, so the tool
            // cannot be used to discover which ids exist.
            return null;
        }

        return $this->projection->apply($model);
    }

    /**
     * @return array{rows: array, denied: int, truncated: bool}
     */
    public function search(?Authenticatable $user, string $term, ?int $limit = null): array
    {
        $searchable = $this->projection->searchable();

        if ($searchable === []) {
            return ['rows' => [], 'denied' => 0, 'truncated' => false];
        }

        $limit = $this->cap($limit);

        $query = $this->modelClass::query()->where(function ($q) use ($searchable, $term): void {
            foreach ($searchable as $field) {
                // Bound, never interpolated. The term arrives from a model,
                // which makes it exactly as untrusted as anything else a user
                // can influence.
                $q->orWhere($field, 'like', '%'.$term.'%');
            }
        });

        return $this->authorizeAndProject($user, $query->limit($limit + 1)->get(), $limit);
    }

    /**
     * One row over the limit is fetched so truncation can be reported honestly.
     * A tool that returns exactly the limit with no signal makes an agent
     * believe it has seen everything.
     */
    private function authorizeAndProject(?Authenticatable $user, iterable $models, int $limit): array
    {
        [$allowed, $denied] = $this->gate->filter($user, $models);

        $truncated = count($allowed) > $limit;
        $allowed = array_slice($allowed, 0, $limit);

        return [
            'rows' => array_map(fn (Model $m) => $this->projection->apply($m), $allowed),
            'denied' => $denied,
            'truncated' => $truncated,
        ];
    }
}
