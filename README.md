<div align="center">

# Laravel Agent Kit

**Expose Eloquent models to an AI agent without handing it your database.**

Field allowlists, per-record policy checks, result ceilings. Every one of them fails closed.

[![Tests](https://github.com/catidegla/laravel-agent-kit/actions/workflows/tests.yml/badge.svg)](https://github.com/catidegla/laravel-agent-kit/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.2-777bb4)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-12-ff2d20)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

</div>

---

`laravel/mcp` has 34 million installs and gives you the primitive: define a tool, an agent can call it. What it does not give you is any reason to believe the tool is safe to expose.

The usual first version looks like this:

```php
class TicketTool extends Tool
{
    public function handle(Request $request)
    {
        return Ticket::find($request->id);   // three problems, none visible
    }
}
```

It returns every column, including the ones nothing renders. It has no idea who is asking. And nothing stops an agent iterating ids until it has the table.

This package makes those three the default rather than the thing you remember to add.

```php
#[AgentResource(
    fields: ['id', 'subject', 'status'],
    searchable: ['subject'],
    filterable: ['status'],
    description: 'Support tickets belonging to the signed in user.',
    maxResults: 25,
)]
class Ticket extends Model {}
```

That is the whole configuration. What follows is what it buys.

## Undeclared columns never leave

Projection works from the declared field list, never from the model. Adding a column to the table cannot widen what an agent sees, which matters because the migration that adds `internal_notes` is written months after the tool and by somebody else.

```php
$result = $resource->list($user);
// ['id' => 4, 'subject' => 'Payment failed', 'status' => 'open']
// internal_notes and card_last_four are on the table. They are not here.
```

Anything that is not a scalar is replaced with `[not exposed]` rather than serialised, because a cast returning a structured value would otherwise ship whatever it contains without any of it having been declared.

## Every record is authorized individually

The agent acts **as a signed in user**, never as the application. An agent with application-level access reads every tenant's data the moment a prompt talks it into asking, and no amount of careful tool descriptions prevents that.

```php
$result = $resource->list($alice);
// ['rows' => [...alice's only...], 'denied' => 1, 'truncated' => false]
```

`denied` is reported rather than silently dropped. A tool that quietly removes rows makes an agent believe a list is complete when it is not, and that produces confidently wrong answers instead of errors.

Filtering the query by `user_id` is not a substitute for this. It works until the next person adds a scope or a relation, and then it does not, quietly.

## It fails closed, everywhere

| Situation | What happens |
| :--- | :--- |
| Model has no `#[AgentResource]` | Unreachable |
| Model has the attribute but **no policy** | **Refused**, not published |
| Model has both but is not in the config list | Unreachable |
| Field not in `fields` | Never returned |
| Field not in `filterable` | Cannot be filtered on |
| Field not in `fields` but listed in `searchable` | Caught by `verify()` |
| Agent is unauthenticated | Sees nothing |

The second row is the important one. Forgetting to write a policy must not silently publish a table, so a model annotated without one throws rather than being exposed openly.

The last one is subtle: a field you can search but cannot read still leaks, one query at a time. `verify()` treats that as a configuration error.

## Ids cannot be enumerated

A denied record and a missing one answer identically:

```php
$resource->get($alice, $bobsTicketId);  // null
$resource->get($alice, 999999);         // null
```

Distinguishing them turns the tool into an oracle for which ids exist.

## An agent cannot page out your table

`maxResults` is a ceiling, not a default. Whatever an agent asks for, it gets at most what the resource declares, and truncation is reported rather than hidden:

```php
$resource->list($user, [], 1000);
// ['rows' => [...5 rows...], 'denied' => 0, 'truncated' => true]
```

One row beyond the limit is fetched purely so `truncated` can be honest. A tool that returns exactly the limit with no signal makes an agent believe it has seen everything.

## Verify before you deploy

```php
$problems = app(Registry::class)->verify();
// [
//   'Ticket is marked #[AgentResource] but has no registered policy...',
//   'Leaky marks internal_notes searchable but does not expose it, which would leak the field one query at a time',
// ]
```

Every problem in one pass, not the first one. Fixing a configuration should not be a game of whack-a-mole where each run reveals one more thing.

Put it in a test and a misconfigured model fails the build rather than being discovered when an agent asks for it in production:

```php
public function test_nothing_is_over_exposed(): void
{
    $this->assertSame([], app(Registry::class)->verify());
}
```

## Exposure is an explicit list

```php
// config/agent-kit.php
'models' => [
    App\Models\Ticket::class,
],
```

Deliberately not a scan for the attribute. With a scan, adding an attribute anywhere in the codebase publishes a table, and the reviewer of that pull request sees one line in a model rather than a change to the application's exposed surface.

## Install

```bash
composer require catidegla/laravel-agent-kit
php artisan vendor:publish --tag=agent-kit-config
```

Requires PHP 8.2 and Laravel 12.

## Scope

This is the authorization and exposure layer. It is deliberately not an MCP server: `laravel/mcp` already is one, and is very good at it. Wire a resource into a tool and let it handle the protocol.

**Not built yet, and not pretended otherwise:** an audit trail of tool calls, relation traversal (the `relations` argument is accepted and currently unused), and a generator that emits `laravel/mcp` tool classes from a resource. The audit trail is the next one, because "what did the agent read" is the first question anyone asks after an incident.

## Testing

```bash
composer install
vendor/bin/phpunit
```

20 tests. They assert the security properties directly rather than describing them: that `internal_notes` is absent from a payload, that Bob's ticket is not in Alice's list, that a model without a policy throws, that a denied `get` is indistinguishable from a missing one.

## License

[MIT](LICENSE)
