<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Exposure;

use Catidegla\AgentKit\Attributes\AgentResource;
use Catidegla\AgentKit\Audit\AuditEvent;
use Catidegla\AgentKit\Audit\AuditTrail;
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
    ) {
        $this->projection = new Projection($attribute);
        $this->gate = new Gate($attribute);
    }

    /**
     * @throws NotExposedException when the model is not exposed, or is exposed without a policy
     */
    public static function for(string $modelClass, ?AuditTrail $audit = null): self
    {
        $reflection = new ReflectionClass($modelClass);
        $attributes = $reflection->getAttributes(AgentResource::class);

        if ($attributes === []) {
            throw NotExposedException::notAResource($modelClass);
        }

        $resource = new self($modelClass, $attributes[0]->newInstance(), $audit);
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
                // Recorded before it is thrown. A refused call is part of the
                // record: a run of them is what probing looks like.
                $this->record($user, 'list', AuditEvent::REFUSED, ['filters' => $filters, 'limit' => $limit]);

                throw NotExposedException::fieldNotExposed($this->modelClass, $field, $this->projection->filterable());
            }

            $query->where($field, $value);
        }

        $result = $this->authorizeAndProject($user, $query->limit($limit + 1)->get(), $limit);

        $this->record(
            $user,
            'list',
            AuditEvent::OK,
            ['filters' => $filters, 'limit' => $limit],
            $result['ids'],
            $result['denied'],
            $result['truncated'],
        );

        unset($result['ids']);

        return $result;
    }

    public function get(?Authenticatable $user, mixed $id): ?array
    {
        $model = $this->modelClass::query()->find($id);

        // The agent gets null either way, so it cannot discover which ids
        // exist. The audit trail records which of the two it was, because the
        // operator needs the distinction and the agent never sees the record.
        if ($model === null) {
            $this->record($user, 'get', AuditEvent::MISSING, ['id' => $id]);

            return null;
        }

        if (! $this->gate->allows($user, $model)) {
            $this->record($user, 'get', AuditEvent::DENIED, ['id' => $id], [], 1);

            return null;
        }

        $row = $this->projection->apply($model);

        // Recorded before the row is handed back, so a failed write in strict
        // mode withholds it rather than serving something unaccounted for.
        $this->record($user, 'get', AuditEvent::OK, ['id' => $id], [$model->getKey()]);

        return $row;
    }

    /**
     * @return array{rows: array, denied: int, truncated: bool}
     */
    public function search(?Authenticatable $user, string $term, ?int $limit = null): array
    {
        $searchable = $this->projection->searchable();

        if ($searchable === []) {
            $this->record($user, 'search', AuditEvent::OK, ['term' => $term, 'limit' => $this->cap($limit)]);

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

        $result = $this->authorizeAndProject($user, $query->limit($limit + 1)->get(), $limit);

        $this->record(
            $user,
            'search',
            AuditEvent::OK,
            ['term' => $term, 'limit' => $limit],
            $result['ids'],
            $result['denied'],
            $result['truncated'],
        );

        unset($result['ids']);

        return $result;
    }

    /**
     * One row over the limit is fetched so truncation can be reported honestly.
     * A tool that returns exactly the limit with no signal makes an agent
     * believe it has seen everything.
     *
     * @return array{rows: array, ids: array, denied: int, truncated: bool}
     */
    private function authorizeAndProject(?Authenticatable $user, iterable $models, int $limit): array
    {
        [$allowed, $denied] = $this->gate->filter($user, $models);

        $truncated = count($allowed) > $limit;
        $allowed = array_slice($allowed, 0, $limit);

        return [
            'rows' => array_map(fn (Model $m) => $this->projection->apply($m), $allowed),
            'ids' => array_map(fn (Model $m) => $m->getKey(), $allowed),
            'denied' => $denied,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param array<string, mixed> $arguments what the agent supplied
     * @param array<int, mixed>    $ids       identifiers returned, never values
     */
    private function record(
        ?Authenticatable $user,
        string $operation,
        string $outcome,
        array $arguments = [],
        array $ids = [],
        int $denied = 0,
        bool $truncated = false,
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
        ));
    }
}
