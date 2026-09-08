<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Attributes;

use Attribute;

/**
 * Marks an Eloquent model as reachable by an agent, and states exactly how much
 * of it is reachable.
 *
 * Everything here is an allowlist. A model with no attribute is not exposed, a
 * field not named in $fields is never returned, and a model with no policy is
 * refused rather than exposed openly. Exposure is opt in at every level because
 * the failure mode of the opposite arrangement is an agent quietly reading a
 * column somebody added last week.
 *
 *     #[AgentResource(
 *         fields: ['id', 'title', 'status', 'created_at'],
 *         searchable: ['title'],
 *         description: 'Support tickets belonging to the signed in user.',
 *     )]
 *     class Ticket extends Model {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AgentResource
{
    /**
     * @param string[] $fields      the only attributes that may ever be returned
     * @param string[] $searchable  a subset of $fields that free text search may look at
     * @param string[] $filterable  a subset of $fields an agent may filter on
     * @param string[] $abilities   which tools to generate: list, get, search
     * @param int      $maxResults  hard ceiling per call, so an agent cannot page a table out
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $searchable = [],
        public readonly array $filterable = [],
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly array $abilities = ['list', 'get', 'search'],
        public readonly int $maxResults = 25,
        /**
         * The policy method checked before a record is returned. Null means use
         * the conventional "view". There is no option to skip the check.
         */
        public readonly string $ability = 'view',
        /**
         * Relations an agent may traverse, each of which must itself be an
         * exposed resource. Without this a single tool call walks the whole
         * object graph.
         */
        public readonly array $relations = [],
    ) {}
}
