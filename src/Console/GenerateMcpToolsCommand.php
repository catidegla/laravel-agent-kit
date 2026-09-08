<?php

declare(strict_types=1);

namespace Catidegla\AgentKit\Console;

use Catidegla\AgentKit\Mcp\ToolGenerator;
use Catidegla\AgentKit\Registry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class GenerateMcpToolsCommand extends Command
{
    protected $signature = 'agent-kit:mcp
        {--path= : Directory to write into, defaults to app/Mcp/Tools}
        {--namespace= : Namespace for the generated classes, defaults to App\\Mcp\\Tools}
        {--force : Overwrite files that already exist}';

    protected $description = 'Generate laravel/mcp tool classes from the exposed resources';

    public function handle(Registry $registry, Filesystem $files): int
    {
        // Nothing is generated from an exposure that does not hold together.
        // A tool built on a resource with no policy is a tool that throws the
        // first time an agent calls it, and the error would arrive in
        // production rather than here.
        $problems = $registry->verify();

        if ($problems !== []) {
            $this->components->error('Exposure is not sound, so nothing was generated.');

            foreach ($problems as $problem) {
                $this->line('  '.$problem);
            }

            return self::FAILURE;
        }

        if ($registry->models() === []) {
            $this->components->warn('No models are registered in agent-kit.models, so there is nothing to generate.');

            return self::SUCCESS;
        }

        $namespace = $this->option('namespace') ?: 'App\\Mcp\\Tools';
        $path = $this->option('path') ?: $this->laravel->basePath('app/Mcp/Tools');
        $generator = new ToolGenerator($namespace);

        $files->ensureDirectoryExists($path);

        $written = [];
        $skipped = [];

        foreach ($registry->all() as $resource) {
            foreach ($generator->forResource($resource) as $class => $source) {
                $file = rtrim($path, '/\\').DIRECTORY_SEPARATOR.$class.'.php';

                // Someone will have edited a generated file, and losing that
                // silently is worse than making them ask for it.
                if ($files->exists($file) && ! $this->option('force')) {
                    $skipped[] = $class;
                    continue;
                }

                $files->put($file, $source);
                $written[] = $class;
            }
        }

        foreach ($written as $class) {
            $this->components->info('Generated '.$class);
        }

        if ($skipped !== []) {
            $this->components->warn(
                'Left alone because they already exist: '.implode(', ', $skipped).'. Pass --force to overwrite.',
            );
        }

        if ($written !== []) {
            $this->newLine();
            $this->line('Register them with your server:');
            $this->newLine();
            $this->line('    protected array $tools = [');

            foreach ($written as $class) {
                $this->line('        '.$class.'::class,');
            }

            $this->line('    ];');
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
