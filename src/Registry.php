<?php

declare(strict_types=1);

namespace Catidegla\AgentKit;

use Catidegla\AgentKit\Audit\AuditTrail;
use Catidegla\AgentKit\Exceptions\NotExposedException;
use Catidegla\AgentKit\Exposure\Resource;

/**
 * The list of what an agent can reach.
 *
 * Deliberately an explicit list in config rather than a scan for the attribute.
 * A scan means that adding an attribute anywhere in the codebase publishes a
 * table, and the reviewer of that pull request sees one line in a model rather
 * than a change to the application's exposed surface. An explicit list makes
 * exposure a visible, one file decision.
 */
final class Registry
{
    /** @var array<string, Resource> */
    private array $resolved = [];

    /** @param string[] $modelClasses */
    public function __construct(
        private array $modelClasses = [],
        private readonly ?AuditTrail $audit = null,
    ) {}

    /** @param string[] $modelClasses */
    public function register(array $modelClasses): self
    {
        $this->modelClasses = array_values(array_unique([...$this->modelClasses, ...$modelClasses]));
        $this->resolved = [];

        return $this;
    }

    /** @return string[] */
    public function models(): array
    {
        return $this->modelClasses;
    }

    /**
     * @throws NotExposedException when a listed model is missing its attribute or its policy
     */
    public function resource(string $modelClass): Resource
    {
        if (! in_array($modelClass, $this->modelClasses, true)) {
            throw NotExposedException::notAResource($modelClass);
        }

        return $this->resolved[$modelClass] ??= Resource::for($modelClass, $this->audit);
    }

    /** @return array<string, Resource> keyed by tool name */
    public function all(): array
    {
        $out = [];

        foreach ($this->modelClasses as $modelClass) {
            $resource = $this->resource($modelClass);
            $out[$resource->name()] = $resource;
        }

        return $out;
    }

    public function byName(string $name): ?Resource
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Check every registered model up front.
     *
     * Meant to run in a test or a deploy check, so a model listed without a
     * policy fails the build rather than being discovered when an agent asks
     * for it in production.
     *
     * @return string[] the problems, empty when everything is sound
     */
    public function verify(): array
    {
        $problems = [];

        foreach ($this->modelClasses as $modelClass) {
            if (! class_exists($modelClass)) {
                $problems[] = "{$modelClass} is registered but does not exist";
                continue;
            }

            // The policy check and the attribute checks are independent, and
            // both are reported. Stopping at the first problem turns fixing a
            // configuration into a game of whack-a-mole, where each run reveals
            // one more thing.
            try {
                Resource::for($modelClass);
            } catch (NotExposedException $e) {
                $problems[] = $e->getMessage();
            }

            $attribute = Resource::attributeFor($modelClass);
            if ($attribute === null) {
                continue;
            }

            if ($attribute->fields === []) {
                $problems[] = class_basename($modelClass).' exposes no fields, so every tool returns empty rows';
            }

            // Searching a field you cannot read still leaks it, one query at a
            // time, so the two lists have to agree.
            $undeclared = array_diff($attribute->searchable, $attribute->fields);
            if ($undeclared !== []) {
                $problems[] = sprintf(
                    '%s marks %s searchable but does not expose it, which would leak the field one query at a time',
                    class_basename($modelClass),
                    implode(', ', $undeclared),
                );
            }

            $undeclaredFilters = array_diff($attribute->filterable, $attribute->fields);
            if ($undeclaredFilters !== []) {
                $problems[] = sprintf(
                    '%s marks %s filterable but does not expose it',
                    class_basename($modelClass),
                    implode(', ', $undeclaredFilters),
                );
            }
        }

        return $problems;
    }
}
