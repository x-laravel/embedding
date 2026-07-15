<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

trait ResolvesEmbeddableModels
{
    /**
     * @return array<int, string>|null  null = explicit model failed validation
     */
    private function resolveModels(): ?array
    {
        $arg = $this->argument('model');

        if ($arg !== null) {
            return $this->validateModel($arg) ? [$arg] : null;
        }

        return $this->discoverModels();
    }

    private function validateModel(string $modelClass): bool
    {
        if (! class_exists($modelClass)) {
            $this->error("Class [{$modelClass}] does not exist.");

            return false;
        }

        if (! is_a($modelClass, HasEmbeddings::class, true)) {
            $this->error("Class [{$modelClass}] does not implement HasEmbeddings.");

            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function discoverModels(): array
    {
        $path = is_dir(app_path('Models')) ? app_path('Models') : app_path();

        if (! is_dir($path)) {
            return [];
        }

        $namespace = $this->laravel->getNamespace();
        $basePath = realpath(app_path()) . DIRECTORY_SEPARATOR;

        $found = [];

        foreach ((new Finder())->in($path)->files()->name('*.php') as $file) {
            $class = $namespace . str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($file->getRealPath(), $basePath)
            );

            try {
                if (! class_exists($class)) {
                    continue;
                }
            } catch (Throwable $e) {
                if ($this->getOutput()->isVerbose()) {
                    $this->line("  <comment>Skipped {$file->getRealPath()}</comment>: {$e->getMessage()}");
                }

                continue;
            }

            if (! is_a($class, HasEmbeddings::class, true)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $found;
    }

    /**
     * @param  array<int, string>  $models
     */
    private function confirmModels(array $models): bool
    {
        $this->line('Found <info>' . count($models) . '</info> models implementing HasEmbeddings:');
        foreach ($models as $model) {
            $this->line("  - <comment>{$model}</comment>");
        }

        return $this->confirm('Process all of them?', true);
    }
}
