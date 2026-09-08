<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Tests;

use Catidegla\AgentKit\AgentKitServiceProvider;
use Catidegla\AgentKit\Attributes\AgentResource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/* ------------------------------------------------------------------ models */

/**
 * Exposed with no abilities of its own, which is how you make a model
 * reachable through a relation without also publishing a tool that can
 * enumerate it.
 */
#[AgentResource(fields: ['id', 'name'], abilities: [])]
class TestUser extends Authenticatable
{
    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;
}

/**
 * The fields list deliberately omits internal_notes and card_last_four. Those
 * columns exist on the table, and the point of the tests is that they never
 * come out.
 */
#[AgentResource(
    fields: ['id', 'subject', 'status', 'user_id'],
    searchable: ['subject'],
    filterable: ['status'],
    description: 'Support tickets belonging to the signed in user.',
    maxResults: 5,
    relations: ['comments', 'author', 'escalatedTo'],
)]
class Ticket extends Model
{
    use HasFactory;

    protected $guarded = [];
    public $timestamps = false;

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }

    /** Points at somebody the viewer generally cannot see. */
    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'escalated_to');
    }
}

/**
 * maxResults is 2 so relation truncation is reachable in a test. The policy
 * reads user_id, which is deliberately not in the exposed field list: what
 * authorizes a record and what is returned for it are different questions.
 */
#[AgentResource(fields: ['id', 'body'], maxResults: 2)]
class Comment extends Model
{
    protected $guarded = [];
    public $timestamps = false;
}

/** Exposed, but with no policy registered. Must be refused, not published. */
#[AgentResource(fields: ['id', 'body'])]
class Unpoliced extends Model
{
    protected $table = 'unpoliced';
    protected $guarded = [];
    public $timestamps = false;
}

/** No attribute at all. Must be unreachable, including through a relation. */
class PrivateNote extends Model
{
    protected $table = 'private_notes';
    protected $guarded = [];
    public $timestamps = false;
}

/** Declares a searchable field it does not expose, which verify() must catch. */
#[AgentResource(fields: ['id', 'subject'], searchable: ['subject', 'internal_notes'])]
class Leaky extends Model
{
    protected $table = 'tickets';
    protected $guarded = [];
    public $timestamps = false;
}

/** Points a relation at a model nobody exposed, which verify() must catch. */
#[AgentResource(fields: ['id'], relations: ['notes'])]
class Overreaching extends Model
{
    protected $table = 'tickets';
    protected $guarded = [];
    public $timestamps = false;

    public function notes(): HasMany
    {
        return $this->hasMany(PrivateNote::class, 'id');
    }
}

/** Declares a relation that does not exist, which verify() must catch. */
#[AgentResource(fields: ['id'], relations: ['ghost'])]
class Phantom extends Model
{
    protected $table = 'tickets';
    protected $guarded = [];
    public $timestamps = false;
}

/* --------------------------------------------------------------- policies */

class TicketPolicy
{
    public function view(TestUser $user, Ticket $ticket): bool
    {
        return $ticket->user_id === $user->id;
    }
}

class CommentPolicy
{
    public function view(TestUser $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id;
    }
}

/**
 * Permissive on purpose. Overreaching exists to test the relation check, and
 * without a policy it would be refused for that reason first.
 */
class OverreachingPolicy
{
    public function view(TestUser $user, Overreaching $model): bool
    {
        return true;
    }
}

class UserPolicy
{
    public function view(TestUser $user, TestUser $subject): bool
    {
        return $user->id === $subject->id;
    }
}

/* -------------------------------------------------------------- test case */

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('subject');
            $table->string('status')->default('open');
            $table->foreignId('user_id');
            // Present on the table, absent from the exposed field list.
            $table->text('internal_notes')->nullable();
            $table->string('card_last_four')->nullable();
            $table->foreignId('escalated_to')->nullable();
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->text('body');
            $table->foreignId('ticket_id');
            $table->foreignId('user_id');
            // Again present, again never exposed.
            $table->text('moderator_note')->nullable();
        });

        Schema::create('unpoliced', function (Blueprint $table): void {
            $table->id();
            $table->text('body');
        });

        Schema::create('private_notes', function (Blueprint $table): void {
            $table->id();
            $table->text('body');
        });

        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(Comment::class, CommentPolicy::class);
        Gate::policy(TestUser::class, UserPolicy::class);
        Gate::policy(Overreaching::class, OverreachingPolicy::class);
    }

    protected function getPackageProviders($app): array
    {
        return [AgentKitServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('agent-kit.models', [Ticket::class, Comment::class, TestUser::class]);
    }

    protected function user(string $name = 'alice'): TestUser
    {
        return TestUser::create(['name' => $name]);
    }

    protected function ticket(TestUser $owner, array $attributes = []): Ticket
    {
        return Ticket::create(array_merge([
            'subject' => 'Payment failed',
            'status' => 'open',
            'user_id' => $owner->id,
            'internal_notes' => 'Customer threatened chargeback',
            'card_last_four' => '4242',
        ], $attributes));
    }

    protected function comment(Ticket $ticket, TestUser $owner, array $attributes = []): Comment
    {
        return Comment::create(array_merge([
            'body' => 'Any update on this',
            'ticket_id' => $ticket->id,
            'user_id' => $owner->id,
            'moderator_note' => 'Flagged for tone',
        ], $attributes));
    }
}
