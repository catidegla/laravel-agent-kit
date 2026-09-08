<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Exposure;

use Catidegla\AgentKit\Attributes\AgentResource;
use Catidegla\AgentKit\Audit\AuditEvent;
use Catidegla\AgentKit\Audit\AuditTrail;
use Catidegla\AgentKit\Exceptions\NotExposedException;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;

/**
 * One exposed model, and the only three things an agent may do with it.
 *
 * Every operation applies the same three constraints in the same order: cap the
 * number of rows, authorize each one individually, then project it down to the
 * declared fields. Doing them in that order matters. Projecting before
 * authorizing would mean building a payload the caller is not allowed to see,
 * and capping after authorizing would let a denied row consume a slot.
 *
 * A fourth step follows all of them: the call is recorded before the result is
 * returned. In strict mode a sink that refuses takes the read down with it, so
 * nothing is ever served that could not be accounted for afterwards.
 */
final class Resource
{
    public readonly Projection $projection;
    public readonly Gate $gate;

    private function __construct(
        public readonly string $modelClass,
        public readonly AgentResource $attribute,
        private readonly ?AuditTrail $audit = null,
        /**
         * Maps a related model class to its own Resource, or null when it is
         * not a registered one. Supplied by the Registry, because a relation
         * is only ever followed into something already exposed in its own
         * right.
         */
        private readonly ?Closure $resolveRelated = null,
    ) {
        $this->projection = new Projection($attribute);
        $this->gate = new Gate($attribute);
    }

    /**
     * @throws NotExposedException when the model is not exposed, or is exposed without a policy
     */
    public static function for(
        string $modelClass,
        ?AuditTrail $audit = null,
        ?Closure $resolveRelated = null,
    ): self {
        $reflection = new ReflectionClass($modelClass);
        $attributes = $reflection->getAttributes(AgentResource::class);

        if ($attributes === []) {
            throw NotExposedException::notAResource($modelClass);
        }

        $resource = new self($modelClass, $attributes[0]->newInstance(), $audit, $resolveRelated);
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

    /** @return string[] relation names an agent may ask to have expanded */
    public function relations(): array
    {
        return $this->attribute->relations;
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
     * @param string[]             $include relations to expand, each declared
     * @return array{rows: array, denied: int, truncated: bool}
     */
    public function list(?Authenticatable $user, array $filters = [], ?int $limit = null, array $include = []): array
    {
        $limit = $this->cap($limit);

        try {
            $this->assertRelationsAllowed($include);
        } catch (NotExposedException $e) {
            $this->record($user, 'list', AuditEvent::REFUSED, ['filters' => $filters, 'limit' => $limit, 'include' => $include]);

            throw $e;
        }

        $query = $this->modelClass::query();

        foreach ($filters as $field => $value) {
            if (! in_array($field, $this->projection->filterable(), true)) {
                // Recorded before it is thrown. A refused call is part of the
                // record: a run of them is what probing looks like.
                $this->record($user, 'list', AuditEvent::REFUSED, ['filters' => $filters, 'limit' => $limit, 'include' => $include]);

                throw NotExposedException::fieldNotExposed($this->modelClass, $field, $this->projection->filterable());
            }

            $query->where($field, $value);
        }

        // Eager loaded so that expanding a relation across a page of rows is
        // one extra query rather than one per row. Every name has already been
        // checked against the declared list, so nothing arbitrary reaches here.
        if ($include !== []) {
            $query->with($include);
        }

        $result = $this->authorizeAndProject($user, $query->limit($limit + 1)->get(), $limit, $include);

        $this->record(
            $user,
            'list',
            AuditEvent::OK,
            ['filters' => $filters, 'limit' => $limit, 'include' => $include],
            $result['ids'],
            $result['denied'],
            $result['truncated'],
            $result['related'],
        );

        unset($result['ids'], $result['related']);

        return $result;
    }

    /** @param string[] $include */
    public function get(?Authenticatable $user, mixed $id, array $include = []): ?array
    {
        try {
            $this->assertRelationsAllowed($include);
        } catch (NotExposedException $e) {
            $this->record($user, 'get', AuditEvent::REFUSED, ['id' => $id, 'include' => $include]);

            throw $e;
        }

        $query = $this->modelClass::query();

        if ($include !== []) {
            $query->with($include);
        }

        $model = $query->find($id);

        // The agent gets null either way, so it cannot discover which ids
        // exist. The audit trail records which of the two it was, because the
        // operator needs the distinction and the agent never sees the record.
        if ($model === null) {
            $this->record($user, 'get', AuditEvent::MISSING, ['id' => $id, 'include' => $include]);

            return null;
        }

        if (! $this->gate->allows($user, $model)) {
            $this->record($user, 'get', AuditEvent::DENIED, ['id' => $id, 'include' => $include], [], 1);

            return null;
        }

        $row = $this->projection->apply($model);
        $related = [];

        if ($include !== []) {
            $row['_relations'] = $this->expand($user, $model, $include, $related);
        }

        // Recorded before the row is handed back, so a failed write in strict
        // mode withholds it rather than serving something unaccounted for.
        $this->record($user, 'get', AuditEvent::OK, ['id' => $id, 'include' => $include], [$model->getKey()], 0, false, $related);

        return $row;
    }

    /**
     * @param string[] $include
     * @return array{rows: array, denied: int, truncated: bool}
     */
    public function search(?Authenticatable $user, string $term, ?int $limit = null, array $include = []): array
    {
        $searchable = $this->projection->searchable();

        try {
            $this->assertRelationsAllowed($include);
        } catch (NotExposedException $e) {
            $this->record($user, 'search', AuditEvent::REFUSED, ['term' => $term, 'limit' => $this->cap($limit), 'include' => $include]);

            throw $e;
        }

        if ($searchable === []) {
            $this->record($user, 'search', AuditEvent::OK, ['term' => $term, 'limit' => $this->cap($limit), 'include' => $include]);

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

        if ($include !== []) {
            $query->with($include);
        }

        $result = $this->authorizeAndProject($user, $query->limit($limit + 1)->get(), $limit, $include);

        $this->record(
            $user,
            'search',
            AuditEvent::OK,
            ['term' => $term, 'limit' => $limit, 'include' => $include],
            $result['ids'],
            $result['denied'],
            $result['truncated'],
            $result['related'],
        );

        unset($result['ids'], $result['related']);

        return $result;
    }

    /* ------------------------------------------------------------ relations */

    /**
     * The class a declared relation points at, without loading anything.
     *
     * @throws NotExposedException when the relation is not declared or the method does not exist
     */
    public function relationTargetClass(string $name): string
    {
        if (! in_array($name, $this->attribute->relations, true)) {
            throw NotExposedException::relationNotExposed($this->modelClass, $name, $this->attribute->relations);
        }

        $model = new $this->modelClass();

        if (! method_exists($model, $name)) {
            throw NotExposedException::relationMissing($this->modelClass, $name);
        }

        $relation = $model->{$name}();

        if (! $relation instanceof Relation) {
            throw NotExposedException::relationMissing($this->modelClass, $name);
        }

        return $relation->getRelated()::class;
    }

    /**
     * @param string[] $include
     * @throws NotExposedException
     */
    private function assertRelationsAllowed(array $include): void
    {
        foreach ($include as $name) {
            // Exact names only. Anything with a dot in it would be a second hop
            // asking to be treated as one, and each hop is its own tool call.
            if (! is_string($name) || ! in_array($name, $this->attribute->relations, true)) {
                throw NotExposedException::relationNotExposed(
                    $this->modelClass,
                    is_string($name) ? $name : gettype($name),
                    $this->attribute->relations,
                );
            }

            $target = $this->relationTargetClass($name);

            if ($this->related($target) === null) {
                throw NotExposedException::relationTargetNotRegistered($this->modelClass, $name, $target);
            }
        }
    }

    private function related(string $modelClass): ?self
    {
        if ($this->resolveRelated === null) {
            return null;
        }

        return ($this->resolveRelated)($modelClass);
    }

    /**
     * Expand one level, never two.
     *
     * Expanded records do not themselves expand relations. An agent that needs
     * the next hop makes another tool call, which is separately authorized and
     * separately recorded. Walking the graph inside a single call is how one
     * question becomes a full export.
     *
     * @param  string[] $include
     * @param  array<string, array> $related collects the ids reached, for the audit record
     * @return array<string, mixed>
     */
    private function expand(?Authenticatable $user, Model $model, array $include, array &$related): array
    {
        $out = [];

        foreach ($include as $name) {
            $target = $this->related($this->relationTargetClass($name));
            $value = $model->getRelation($name);

            if ($value instanceof EloquentCollection) {
                $limit = $target->cap(null);
                [$allowed, $denied] = $target->gate->filter($user, $value);

                $truncated = count($allowed) > $limit;
                $allowed = array_slice($allowed, 0, $limit);

                $out[$name] = [
                    // The target's own projection, so a relation returns exactly
                    // what that model declares and never a field it does not.
                    'rows' => array_map(fn (Model $m) => $target->projection->apply($m), $allowed),
                    'denied' => $denied,
                    'truncated' => $truncated,
                ];

                $ids = array_map(fn (Model $m) => $m->getKey(), $allowed);
            } elseif ($value instanceof Model) {
                $allowed = $target->gate->allows($user, $value);
                $out[$name] = $allowed ? $target->projection->apply($value) : null;
                $ids = $allowed ? [$value->getKey()] : [];
            } else {
                $out[$name] = null;
                $ids = [];
            }

            $related[$name] = array_merge($related[$name] ?? [], $ids);
        }

        return $out;
    }

    /**
     * One row over the limit is fetched so truncation can be reported honestly.
     * A tool that returns exactly the limit with no signal makes an agent
     * believe it has seen everything.
     *
     * @param  string[] $include
     * @return array{rows: array, ids: array, denied: int, truncated: bool, related: array}
     */
    private function authorizeAndProject(
        ?Authenticatable $user,
        iterable $models,
        int $limit,
        array $include = [],
    ): array {
        [$allowed, $denied] = $this->gate->filter($user, $models);

        $truncated = count($allowed) > $limit;
        $allowed = array_slice($allowed, 0, $limit);
        $related = [];

        $rows = array_map(function (Model $m) use ($user, $include, &$related): array {
            $row = $this->projection->apply($m);

            if ($include !== []) {
                $row['_relations'] = $this->expand($user, $m, $include, $related);
            }

            return $row;
        }, $allowed);

        return [
            'rows' => $rows,
            'ids' => array_map(fn (Model $m) => $m->getKey(), $allowed),
            'denied' => $denied,
            'truncated' => $truncated,
            'related' => $related,
        ];
    }

    /**
     * @param array<string, mixed>  $arguments what the agent supplied
     * @param array<int, mixed>     $ids       identifiers returned, never values
     * @param array<string, array>  $related   relation name to ids reached
     */
    private function record(
        ?Authenticatable $user,
        string $operation,
        string $outcome,
        array $arguments = [],
        array $ids = [],
        int $denied = 0,
        bool $truncated = false,
        array $related = [],
    ): void {
        if ($this->audit === null) {
            return;
        }

        $this->audit->record(new AuditEvent(
            resource: $this->name(),
            modelClass: $this->modelClass,
            operation: $operation,
            outcome: $outcome,
            actor: AuditTrail::actor($user),
            arguments: $arguments,
            ids: $ids,
            // Field names, so you know what was exposed. Not the values, which
            // would make this log a second copy of the data it protects.
            fields: $outcome === AuditEvent::OK ? $this->projection->fields() : [],
            denied: $denied,
            truncated: $truncated,
            related: $related,
        ));
    }
}
