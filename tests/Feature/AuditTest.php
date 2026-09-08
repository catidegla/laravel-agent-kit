<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Tests\Feature;

use Catidegla\AgentKit\Audit\ArraySink;
use Catidegla\AgentKit\Audit\AuditEvent;
use Catidegla\AgentKit\Audit\AuditSink;
use Catidegla\AgentKit\Audit\AuditTrail;
use Catidegla\AgentKit\Audit\NullSink;
use Catidegla\AgentKit\Exceptions\AuditFailedException;
use Catidegla\AgentKit\Exceptions\NotExposedException;
use Catidegla\AgentKit\Exposure\Resource;
use Catidegla\AgentKit\Registry;
use Catidegla\AgentKit\Tests\TestCase;
use Catidegla\AgentKit\Tests\Ticket;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** A sink that always refuses, for the two strictness tests. */
final class BrokenSink implements AuditSink
{
    public function write(AuditEvent $event): void
    {
        throw new RuntimeException('the audit table is gone');
    }
}

final class AuditTest extends TestCase
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

    /* ------------------------------------------------------ what is recorded */

    #[Test]
    public function the_record_holds_identifiers_and_field_names_but_never_field_values(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);

        $this->resource()->list($alice);

        $event = $this->sink->last();
        $this->assertSame([$ticket->id], $event->ids);
        $this->assertSame(['id', 'subject', 'status', 'user_id'], $event->fields);

        // The point of the whole design. An audit trail that stored the rows
        // would be a second copy of everything the agent read, in a table
        // nobody wrote a policy for.
        $serialised = json_encode($event->toArray());
        $this->assertStringNotContainsString('Payment failed', $serialised);
        $this->assertStringNotContainsString('Customer threatened chargeback', $serialised);
        $this->assertStringNotContainsString('4242', $serialised);
    }

    #[Test]
    public function the_actor_is_the_identifier_rather_than_the_user(): void
    {
        $alice = $this->user('alice');
        $this->ticket($alice);

        $this->resource()->list($alice);

        $event = $this->sink->last();
        $this->assertSame((string) $alice->id, $event->actor);

        // A name in an audit line is personal data in a second place, and the
        // identifier is enough to find the account.
        $this->assertStringNotContainsString('alice', json_encode($event->toArray()));
    }

    #[Test]
    public function an_unauthenticated_call_records_a_null_actor(): void
    {
        $this->ticket($this->user());

        $this->resource()->list(null);

        $this->assertNull($this->sink->last()->actor);
    }

    /* ------------------------------------------------------------- outcomes */

    #[Test]
    public function a_denied_read_and_a_missing_one_are_recorded_apart_although_the_agent_cannot_tell(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $ticket = $this->ticket($alice);

        $denied = $this->resource()->get($bob, $ticket->id);
        $missing = $this->resource()->get($bob, 99999);

        // Identical to the agent, so the tool cannot be used to discover which
        // ids exist.
        $this->assertNull($denied);
        $this->assertNull($missing);

        // Different to the operator, who needs to know which it was and never
        // shows this record to the agent.
        $events = $this->sink->events();
        $this->assertSame(AuditEvent::DENIED, $events[0]->outcome);
        $this->assertSame(AuditEvent::MISSING, $events[1]->outcome);
        $this->assertSame(1, $events[0]->denied);
    }

    #[Test]
    public function a_refused_filter_is_recorded_before_the_exception_is_thrown(): void
    {
        $alice = $this->user();
        $this->ticket($alice);

        try {
            $this->resource()->list($alice, ['internal_notes' => 'anything']);
            $this->fail('an unexposed filter should be refused');
        } catch (NotExposedException) {
            // expected
        }

        // A run of these is what probing looks like, so it belongs in the
        // record even though nothing was returned.
        $this->assertSame(AuditEvent::REFUSED, $this->sink->last()->outcome);
        $this->assertSame([], $this->sink->last()->ids);
    }

    #[Test]
    public function records_withheld_by_policy_are_counted(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $this->ticket($alice);
        $this->ticket($alice, ['subject' => 'Second']);
        $this->ticket($bob, ['subject' => 'Bobs own']);

        $this->resource()->list($bob);

        $event = $this->sink->last();
        $this->assertSame(2, $event->denied);
        $this->assertCount(1, $event->ids);
    }

    #[Test]
    public function truncation_is_recorded(): void
    {
        $alice = $this->user();
        for ($i = 0; $i < 4; $i++) {
            $this->ticket($alice, ['subject' => 'Ticket '.$i]);
        }

        $this->resource()->list($alice, [], 2);

        $event = $this->sink->last();
        $this->assertTrue($event->truncated);
        $this->assertCount(2, $event->ids);
    }

    #[Test]
    public function a_search_records_the_term_because_what_it_looked_for_is_half_the_incident(): void
    {
        $alice = $this->user();
        $this->ticket($alice);

        $this->resource()->search($alice, 'Payment');

        $event = $this->sink->last();
        $this->assertSame('search', $event->operation);
        $this->assertSame('Payment', $event->arguments['term']);
    }

    #[Test]
    public function the_log_line_reads_as_one_sentence(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $ticket = $this->ticket($alice);

        $this->resource()->get($bob, $ticket->id);

        // Pinned, because this line is what someone greps for at 2am and the
        // README quotes it verbatim.
        $this->assertSame(
            sprintf('agent %d ticket.get -> denied (0 returned, 1 denied)', $bob->id),
            $this->sink->last()->summary(),
        );
    }

    /* ----------------------------------------------------------- strictness */

    #[Test]
    public function a_sink_that_refuses_withholds_the_result_rather_than_serving_it_unrecorded(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);

        $this->app->instance(AuditSink::class, new BrokenSink());
        $this->app->forgetInstance(AuditTrail::class);
        $this->app->forgetInstance(Registry::class);

        $this->expectException(AuditFailedException::class);

        // The row was read, but it never reaches the caller. Serving data you
        // cannot account for is the failure this package exists to prevent.
        $this->resource()->get($alice, $ticket->id);
    }

    #[Test]
    public function turning_strict_off_serves_the_result_anyway(): void
    {
        $alice = $this->user();
        $ticket = $this->ticket($alice);

        config()->set('agent-kit.audit.strict', false);
        $this->app->instance(AuditSink::class, new BrokenSink());
        $this->app->forgetInstance(AuditTrail::class);
        $this->app->forgetInstance(Registry::class);

        $row = $this->resource()->get($alice, $ticket->id);

        // A legitimate trade between availability and a complete record, and
        // it has to actually work when chosen.
        $this->assertSame($ticket->id, $row['id']);
    }

    /* ----------------------------------------------------------- the wiring */

    #[Test]
    public function disabling_the_trail_swaps_in_the_sink_that_records_nothing(): void
    {
        $this->app->forgetInstance(AuditSink::class);
        config()->set('agent-kit.audit.enabled', false);

        $this->assertInstanceOf(NullSink::class, $this->app->make(AuditSink::class));
    }

    #[Test]
    public function the_trail_is_on_without_any_configuration(): void
    {
        $alice = $this->user();
        $this->ticket($alice);

        $this->resource()->get($alice, 1);

        // Nothing in the test set audit.enabled. A trail you have to remember
        // to switch on is a trail that is off during the incident.
        $this->assertNotNull($this->sink->last());
    }

    #[Test]
    public function verifying_configuration_records_nothing(): void
    {
        $this->app->make(Registry::class)->verify();

        // verify() is a deploy check, not an agent reading data. Filling the
        // trail with it would bury the calls that matter.
        $this->assertSame([], $this->sink->events());
    }
}
