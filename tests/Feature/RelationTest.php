<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Tests\Feature;

use Catidegla\AgentKit\Audit\ArraySink;
use Catidegla\AgentKit\Audit\AuditSink;
use Catidegla\AgentKit\Audit\AuditTrail;
use Catidegla\AgentKit\Exceptions\NotExposedException;
use Catidegla\AgentKit\Exposure\Resource;
use Catidegla\AgentKit\Registry;
use Catidegla\AgentKit\Tests\Comment;
use Catidegla\AgentKit\Tests\Overreaching;
use Catidegla\AgentKit\Tests\Phantom;
use Catidegla\AgentKit\Tests\TestCase;
use Catidegla\AgentKit\Tests\TestUser;
use Catidegla\AgentKit\Tests\Ticket;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class RelationTest extends TestCase
{
    private ArraySink $sink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sink = new ArraySink();
        $this->app->instance(AuditSink::class, $this->sink);
        $this->app->forgetInstance(AuditTrail::class);
        $this->app->forgetInstance(Registry::class);
    }

    private function resource(): Resource
    {
        return $this->app->make(Registry::class)->resource(Ticket::class);
    }

    /* ------------------------------------------------- the target's own rules */

    #[Test]
    public function a_relation_returns_the_targets_declared_fields_and_nothing_else(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);
        $this->comment($ticket, $alice);

        $row = $this->resource()->get($alice, $ticket->id, ['comments']);
        $comment = $row['_relations']['comments']['rows'][0];

        // Comment declares id and body. Not moderator_note, not user_id, even
        // though the policy that authorized this record read user_id.
        $this->assertSame(['id', 'body'], array_keys($comment));
        $this->assertArrayNotHasKey('moderator_note', $comment);
    }

    #[Test]
    public function a_relation_is_authorized_by_the_targets_own_policy(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $ticket = $this->ticket($alice);

        $this->comment($ticket, $alice, ['body' => 'Mine']);
        $this->comment($ticket, $bob, ['body' => 'Not mine']);

        $row = $this->resource()->get($alice, $ticket->id, ['comments']);
        $comments = $row['_relations']['comments'];

        // Reaching a record through a relation is still reaching it. Bob's
        // comment sits on a ticket Alice may read, and it is still not hers.
        $this->assertCount(1, $comments['rows']);
        $this->assertSame('Mine', $comments['rows'][0]['body']);
        $this->assertSame(1, $comments['denied']);
    }

    #[Test]
    public function a_to_one_relation_the_viewer_cannot_see_comes_back_null(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $ticket = $this->ticket($alice, ['escalated_to' => $bob->id]);

        $row = $this->resource()->get($alice, $ticket->id, ['author', 'escalatedTo']);

        // Alice may see herself, so the author resolves.
        $this->assertSame($alice->id, $row['_relations']['author']['id']);

        // She may not see Bob, so the relation is null rather than his record.
        $this->assertNull($row['_relations']['escalatedTo']);
    }

    #[Test]
    public function a_to_many_relation_is_capped_by_the_targets_own_ceiling(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);

        for ($i = 0; $i < 5; $i++) {
            $this->comment($ticket, $alice, ['body' => 'Comment '.$i]);
        }

        $row = $this->resource()->get($alice, $ticket->id, ['comments']);
        $comments = $row['_relations']['comments'];

        // Comment declares maxResults 2. A hasMany with no ceiling is how one
        // question becomes a full export.
        $this->assertCount(2, $comments['rows']);
        $this->assertTrue($comments['truncated']);
    }

    /* ------------------------------------------------------------- refusals */

    #[Test]
    public function an_undeclared_relation_is_refused(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);

        $this->expectException(NotExposedException::class);
        $this->expectExceptionMessage('is not a traversable relation');

        $this->resource()->get($alice, $ticket->id, ['secrets']);
    }

    #[Test]
    public function a_relation_cannot_reach_a_model_that_was_never_registered(): void
    {
        $alice = $this->user();

        // Overreaching declares notes, which points at PrivateNote. Nobody
        // exposed PrivateNote, so it has no field list and no policy, and a
        // relation is not a way round that.
        $registry = new Registry([Overreaching::class], $this->app->make(AuditTrail::class));

        $this->expectException(NotExposedException::class);
        $this->expectExceptionMessage('is not a registered resource');

        $registry->resource(Overreaching::class)->list($alice, [], null, ['notes']);
    }

    #[Test]
    public function a_second_hop_written_as_one_name_is_refused(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);

        $this->expectException(NotExposedException::class);

        // Each hop is its own tool call, separately authorized and separately
        // recorded. Dot notation asking for two at once is not a shortcut.
        $this->resource()->get($alice, $ticket->id, ['comments.ticket']);
    }

    #[Test]
    public function a_refused_relation_is_recorded_before_the_exception(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);

        try {
            $this->resource()->get($alice, $ticket->id, ['secrets']);
            $this->fail('an undeclared relation should be refused');
        } catch (NotExposedException) {
            // expected
        }

        $this->assertSame('refused', $this->sink->last()->outcome);
    }

    /* ------------------------------------------------------------- one level */

    #[Test]
    public function expanded_records_do_not_themselves_expand(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);
        $this->comment($ticket, $alice);

        $row = $this->resource()->get($alice, $ticket->id, ['comments']);

        // One level, never two. Walking the graph inside a single call is how
        // an agent reads everything from one question.
        $this->assertArrayNotHasKey('_relations', $row['_relations']['comments']['rows'][0]);
    }

    #[Test]
    public function a_call_without_include_carries_no_relations_at_all(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);
        $this->comment($ticket, $alice);

        $row = $this->resource()->get($alice, $ticket->id);

        // Relations are opt in per call. Expanding them by default would put
        // records in every payload that nobody asked for.
        $this->assertArrayNotHasKey('_relations', $row);
    }

    /* --------------------------------------------------------------- queries */

    #[Test]
    public function expanding_across_a_page_does_not_query_once_per_row(): void
    {
        $alice = $this->user();

        for ($i = 0; $i < 4; $i++) {
            $ticket = $this->ticket($alice, ['subject' => 'Ticket '.$i]);
            $this->comment($ticket, $alice);
        }

        DB::enableQueryLog();
        $this->resource()->list($alice, [], 4, ['comments', 'author']);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One for the tickets and one per eager loaded relation. Without the
        // eager load this is four extra queries, and it grows with the page.
        $this->assertLessThanOrEqual(3, $queries);
    }

    /* ---------------------------------------------------------------- verify */

    #[Test]
    public function verify_catches_a_relation_pointing_at_an_unexposed_model(): void
    {
        $problems = (new Registry([Overreaching::class]))->verify();

        $this->assertStringContainsString('is not a registered resource', implode("\n", $problems));
    }

    #[Test]
    public function verify_catches_a_relation_that_does_not_exist(): void
    {
        $problems = (new Registry([Phantom::class]))->verify();

        $this->assertStringContainsString('no such relation method', implode("\n", $problems));
    }

    /* ----------------------------------------------------------------- audit */

    #[Test]
    public function the_trail_records_which_related_records_were_reached(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);
        $first = $this->comment($ticket, $alice, ['body' => 'One']);
        $second = $this->comment($ticket, $alice, ['body' => 'Two']);

        $this->resource()->get($alice, $ticket->id, ['comments']);

        $event = $this->sink->last();

        // "What did the agent read" has to include what it read through a
        // relation, or the answer is wrong in exactly the interesting case.
        $this->assertSame([$first->id, $second->id], $event->related['comments']);
        $this->assertStringContainsString('via comments[2]', $event->summary());

        // Still identifiers, never values.
        $this->assertStringNotContainsString('Flagged for tone', json_encode($event->toArray()));
    }

    #[Test]
    public function relation_only_exposure_is_possible(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);

        $row = $this->resource()->get($alice, $ticket->id, ['author']);

        // TestUser declares abilities: [], so no tool enumerates users, yet it
        // is registered and therefore reachable through a declared relation.
        $this->assertSame([], $this->app->make(Registry::class)->resource(TestUser::class)->abilities());
        $this->assertSame($alice->name, $row['_relations']['author']['name']);
    }
}
