<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Tests\Feature;

use Catidegla\AgentKit\Exceptions\NotExposedException;
use Catidegla\AgentKit\Exposure\Resource;
use Catidegla\AgentKit\Registry;
use Catidegla\AgentKit\Tests\Leaky;
use Catidegla\AgentKit\Tests\PrivateNote;
use Catidegla\AgentKit\Tests\TestCase;
use Catidegla\AgentKit\Tests\Ticket;
use Catidegla\AgentKit\Tests\Unpoliced;
use PHPUnit\Framework\Attributes\Test;

final class ExposureTest extends TestCase
{
    private function resource(): Resource
    {
        return Resource::for(Ticket::class);
    }

    /* ------------------------------------------------------- field allowlist */

    #[Test]
    public function undeclared_columns_never_leave_the_application(): void
    {
        $alice = $this->user();
        $this->ticket($alice);

        $result = $this->resource()->list($alice);
        $row = $result['rows'][0];

        // The quietest leak in an MCP integration: Eloquent returns every
        // column, and nothing renders these, so nobody notices.
        $this->assertArrayNotHasKey('internal_notes', $row);
        $this->assertArrayNotHasKey('card_last_four', $row);
        $this->assertSame(['id', 'subject', 'status', 'user_id'], array_keys($row));
    }

    #[Test]
    public function adding_a_column_cannot_widen_exposure(): void
    {
        $alice = $this->user();
        $this->ticket($alice);

        // Projection works from the declared list, never from the model, so a
        // migration adding a column changes nothing about what an agent sees.
        $row = $this->resource()->list($alice)['rows'][0];

        $this->assertCount(4, $row);
    }

    /* ------------------------------------------------------- authorization */

    #[Test]
    public function one_user_never_sees_another_users_records(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');

        $this->ticket($alice, ['subject' => 'Alice ticket']);
        $this->ticket($bob, ['subject' => 'Bob ticket']);

        $result = $this->resource()->list($alice);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Alice ticket', $result['rows'][0]['subject']);
        // Reported rather than silently dropped, so an agent knows the list is
        // partial instead of believing it is complete.
        $this->assertSame(1, $result['denied']);
    }

    #[Test]
    public function an_unauthenticated_agent_sees_nothing(): void
    {
        $alice = $this->user();
        $this->ticket($alice);

        $result = $this->resource()->list(null);

        $this->assertSame([], $result['rows']);
        $this->assertSame(1, $result['denied']);
    }

    #[Test]
    public function a_denied_record_and_a_missing_one_answer_identically(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $bobsTicket = $this->ticket($bob);

        $denied = $this->resource()->get($alice, $bobsTicket->id);
        $missing = $this->resource()->get($alice, 999999);

        // Distinguishing them turns the tool into an id oracle.
        $this->assertNull($denied);
        $this->assertNull($missing);
    }

    #[Test]
    public function search_is_authorized_per_record_too(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');

        $this->ticket($alice, ['subject' => 'Payment failed for invoice 12']);
        $this->ticket($bob, ['subject' => 'Payment failed for invoice 99']);

        $result = $this->resource()->search($alice, 'Payment failed');

        $this->assertCount(1, $result['rows']);
        $this->assertStringContainsString('invoice 12', $result['rows'][0]['subject']);
    }

    /* --------------------------------------------------------- failing closed */

    #[Test]
    public function a_model_with_no_policy_is_refused_rather_than_published(): void
    {
        $this->expectException(NotExposedException::class);
        $this->expectExceptionMessageMatches('/no registered policy/');

        // Forgetting to write a policy must not silently publish the table.
        Resource::for(Unpoliced::class);
    }

    #[Test]
    public function a_model_without_the_attribute_is_unreachable(): void
    {
        $this->expectException(NotExposedException::class);
        $this->expectExceptionMessageMatches('/not reachable by an agent/');

        Resource::for(PrivateNote::class);
    }

    #[Test]
    public function a_model_not_in_the_registry_is_unreachable_even_if_annotated(): void
    {
        $registry = new Registry([Ticket::class]);

        $this->expectException(NotExposedException::class);

        // Exposure is an explicit list, so adding an attribute somewhere in the
        // codebase does not publish a table on its own.
        $registry->resource(Unpoliced::class);
    }

    /* --------------------------------------------------------------- ceilings */

    #[Test]
    public function an_agent_cannot_page_a_table_out_of_the_application(): void
    {
        $alice = $this->user();
        for ($i = 0; $i < 20; $i++) {
            $this->ticket($alice, ['subject' => "Ticket {$i}"]);
        }

        // The resource declares maxResults: 5.
        $result = $this->resource()->list($alice, [], 1000);

        $this->assertCount(5, $result['rows']);
        $this->assertTrue($result['truncated'], 'truncation is reported, not hidden');
    }

    #[Test]
    public function truncation_is_reported_honestly(): void
    {
        $alice = $this->user();
        for ($i = 0; $i < 3; $i++) {
            $this->ticket($alice);
        }

        $exact = $this->resource()->list($alice, [], 3);
        $this->assertCount(3, $exact['rows']);
        $this->assertFalse($exact['truncated'], 'exactly the limit with nothing beyond is not truncated');

        $this->ticket($alice);
        $over = $this->resource()->list($alice, [], 3);
        $this->assertTrue($over['truncated']);
    }

    /* ---------------------------------------------------------------- filters */

    #[Test]
    public function filtering_on_an_undeclared_field_is_refused(): void
    {
        $alice = $this->user();
        $this->ticket($alice);

        $this->expectException(NotExposedException::class);
        $this->expectExceptionMessageMatches('/not an exposed field/');

        // Filtering on a hidden column leaks it one query at a time.
        $this->resource()->list($alice, ['internal_notes' => 'chargeback']);
    }

    #[Test]
    public function filtering_on_a_declared_field_works(): void
    {
        $alice = $this->user();
        $this->ticket($alice, ['status' => 'open']);
        $this->ticket($alice, ['status' => 'closed']);

        $result = $this->resource()->list($alice, ['status' => 'closed']);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('closed', $result['rows'][0]['status']);
    }

    #[Test]
    public function a_field_that_is_visible_but_not_filterable_stays_unfilterable(): void
    {
        $alice = $this->user();
        $this->ticket($alice);

        // subject is exposed and searchable, but not in the filterable list.
        $this->expectException(NotExposedException::class);

        $this->resource()->list($alice, ['subject' => 'Payment failed']);
    }

    /* --------------------------------------------------------------- verify */

    #[Test]
    public function verify_catches_a_searchable_field_that_is_not_exposed(): void
    {
        $registry = new Registry([Leaky::class]);

        $problems = $registry->verify();

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('internal_notes', implode("\n", $problems));
        $this->assertStringContainsString('one query at a time', implode("\n", $problems));
    }

    #[Test]
    public function verify_catches_a_registered_model_with_no_policy(): void
    {
        $problems = (new Registry([Unpoliced::class]))->verify();

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('no registered policy', implode("\n", $problems));
    }

    #[Test]
    public function verify_passes_on_a_correctly_configured_resource(): void
    {
        $this->assertSame([], (new Registry([Ticket::class]))->verify());
    }

    #[Test]
    public function verify_catches_a_model_that_does_not_exist(): void
    {
        $problems = (new Registry(['App\\Models\\Ghost'])->verify());

        $this->assertStringContainsString('does not exist', implode("\n", $problems));
    }

    /* --------------------------------------------------------------- registry */

    #[Test]
    public function the_registry_names_tools_from_the_model(): void
    {
        $registry = new Registry([Ticket::class]);

        $this->assertArrayHasKey('ticket', $registry->all());
        $this->assertSame('ticket', $registry->byName('ticket')->name());
        $this->assertNull($registry->byName('unknown'));
    }

    #[Test]
    public function the_description_reaches_the_agent(): void
    {
        $this->assertStringContainsString('signed in user', $this->resource()->description());
    }
}
