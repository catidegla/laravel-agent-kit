<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Tests\Feature;

use Catidegla\AgentKit\Mcp\ToolGenerator;
use Catidegla\AgentKit\Registry;
use Catidegla\AgentKit\Tests\Overreaching;
use Catidegla\AgentKit\Tests\TestCase;
use Catidegla\AgentKit\Tests\TestUser;
use Catidegla\AgentKit\Tests\Ticket;
use PHPUnit\Framework\Attributes\Test;

final class McpGeneratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agentkit-'.uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    private function resource(string $model = Ticket::class)
    {
        return $this->app->make(Registry::class)->resource($model);
    }

    /**
     * Write the generated source out, load it, and hand it to laravel/mcp.
     *
     * The namespace is unique per call so the same class name can be loaded
     * more than once in a suite.
     */
    private function load(string $source, string $class, string $namespace)
    {
        $file = $this->dir.DIRECTORY_SEPARATOR.$class.'.php';
        file_put_contents($file, $source);
        require $file;

        $fqn = $namespace.'\\'.$class;

        return new $fqn();
    }

    /* ------------------------------------------------- it actually compiles */

    #[Test]
    public function the_generated_class_is_a_working_mcp_tool(): void
    {
        $namespace = 'Catidegla\\AgentKit\\Tests\\Generated'.random_int(1000, 9999);
        $generator = new ToolGenerator($namespace);

        $classes = $generator->forResource($this->resource());
        $tool = $this->load($classes['TicketGetTool'], 'TicketGetTool', $namespace);

        // Straight through laravel/mcp's own serialiser. If the generated
        // schema calls a method that does not exist, this is where it fails,
        // rather than the first time an agent connects.
        $described = $tool->toArray();

        $this->assertSame('ticket-get', $described['name']);
        $this->assertSame('Support tickets belonging to the signed in user.', $described['description']);
        $this->assertSame(['id'], $described['inputSchema']['required']);
        $this->assertArrayHasKey('include', $described['inputSchema']['properties']);
        $this->assertSame('array', $described['inputSchema']['properties']['include']['type']);
    }

    #[Test]
    public function every_declared_ability_produces_a_tool_that_serialises(): void
    {
        $namespace = 'Catidegla\\AgentKit\\Tests\\Generated'.random_int(10000, 99999);
        $classes = (new ToolGenerator($namespace))->forResource($this->resource());

        $this->assertSame(
            ['TicketListTool', 'TicketGetTool', 'TicketSearchTool'],
            array_keys($classes),
        );

        foreach ($classes as $class => $source) {
            $described = $this->load($source, $class, $namespace)->toArray();
            $this->assertNotEmpty($described['name'], $class);
            $this->assertIsArray($described['inputSchema']['properties'], $class);
        }
    }

    #[Test]
    public function the_search_tool_requires_a_query_and_the_list_tool_does_not(): void
    {
        $namespace = 'Catidegla\\AgentKit\\Tests\\Generated'.random_int(100000, 999999);
        $classes = (new ToolGenerator($namespace))->forResource($this->resource());

        $search = $this->load($classes['TicketSearchTool'], 'TicketSearchTool', $namespace)->toArray();
        $list = $this->load($classes['TicketListTool'], 'TicketListTool', $namespace)->toArray();

        $this->assertSame(['query'], $search['inputSchema']['required']);
        $this->assertArrayNotHasKey('required', $list['inputSchema']);
        $this->assertArrayHasKey('filters', $list['inputSchema']['properties']);
    }

    /* --------------------------------------------------- what the body does */

    #[Test]
    public function the_body_delegates_to_the_resource_and_touches_no_model_directly(): void
    {
        $source = (new ToolGenerator())->forResource($this->resource())['TicketGetTool'];

        // Every guarantee lives in the resource. A generated tool that queried
        // the model itself would sit outside all of them.
        $this->assertStringContainsString('$registry->resource(Ticket::class)->get(', $source);
        $this->assertStringContainsString('$request->user()', $source);

        $this->assertStringNotContainsString('Ticket::query(', $source);
        $this->assertStringNotContainsString('Ticket::find(', $source);
        $this->assertStringNotContainsString('Ticket::all(', $source);
    }

    #[Test]
    public function a_missing_or_denied_record_answers_with_one_message(): void
    {
        $source = (new ToolGenerator())->forResource($this->resource())['TicketGetTool'];

        // The same sentence for both, or the tool becomes a way to find out
        // which ids exist.
        $this->assertStringContainsString('No such record, or it is not yours to read.', $source);
        $this->assertSame(1, substr_count($source, 'Response::error('));
    }

    #[Test]
    public function abilities_decide_which_tools_exist(): void
    {
        // TestUser is registered with abilities: [], so it is reachable through
        // a relation and has no tool of its own.
        $this->assertSame([], (new ToolGenerator())->forResource($this->resource(TestUser::class)));
    }

    /* ------------------------------------------------------------- command */

    #[Test]
    public function the_command_writes_the_files_and_names_them(): void
    {
        $this->artisan('agent-kit:mcp', ['--path' => $this->dir])
            ->assertSuccessful();

        $this->assertFileExists($this->dir.'/TicketGetTool.php');
        $this->assertFileExists($this->dir.'/CommentListTool.php');
        $this->assertFileDoesNotExist($this->dir.'/TestUserListTool.php');
    }

    #[Test]
    public function the_command_leaves_an_existing_file_alone_unless_forced(): void
    {
        $file = $this->dir.'/TicketGetTool.php';
        file_put_contents($file, '<?php // edited by hand');

        $this->artisan('agent-kit:mcp', ['--path' => $this->dir])->assertSuccessful();

        // Somebody will have edited a generated file, and losing that quietly
        // is worse than making them ask.
        $this->assertSame('<?php // edited by hand', file_get_contents($file));

        $this->artisan('agent-kit:mcp', ['--path' => $this->dir, '--force' => true])->assertSuccessful();

        $this->assertStringContainsString('extends Tool', file_get_contents($file));
    }

    #[Test]
    public function the_command_generates_nothing_from_an_exposure_that_does_not_hold_together(): void
    {
        config()->set('agent-kit.models', [Overreaching::class]);
        $this->app->forgetInstance(Registry::class);

        $this->artisan('agent-kit:mcp', ['--path' => $this->dir])
            ->assertFailed();

        // A tool built on a broken exposure fails the first time an agent
        // calls it, which is the wrong place to find out.
        $this->assertSame([], glob($this->dir.'/*.php'));
    }
}
