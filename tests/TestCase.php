<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Tests;

use Catidegla\AgentKit\AgentKitServiceProvider;
use Catidegla\AgentKit\Attributes\AgentResource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/* ------------------------------------------------------------------ models */

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
)]
class Ticket extends Model
{
    use HasFactory;

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

/** No attribute at all. Must be unreachable. */
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

/* --------------------------------------------------------------- policies */

class TicketPolicy
{
    public function view(TestUser $user, Ticket $ticket): bool
    {
        return $ticket->user_id === $user->id;
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
        $app['config']->set('agent-kit.models', [Ticket::class]);
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
}
