<?php

namespace Dan\AiLoomPlanner;

use Dan\AiLoomPlanner\Commands\LoomPlanCommand;
use Dan\AiLoomPlanner\Commands\LoomTranscriptCommand;
use Illuminate\Support\ServiceProvider;

class LoomPlannerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->packageConfigPath(), 'loom-planner');
    }

    public function boot(): void
    {
        $templatesPath = $this->packageResourcePath('templates');

        if ($this->app->runningInConsole()) {
            $this->commands([
                LoomPlanCommand::class,
                LoomTranscriptCommand::class,
            ]);

            $this->publishes([
                $this->packageConfigPath() => config_path('loom-planner.php'),
            ], 'loom-planner-config');

            if (is_dir($templatesPath)) {
                $this->publishes([
                    $templatesPath => resource_path('views/vendor/loom-planner'),
                ], 'loom-planner-templates');
            }
        }

        if (is_dir($templatesPath)) {
            $this->loadViewsFrom($templatesPath, 'loom-planner');
        }
    }

    protected function packageConfigPath(): string
    {
        return dirname(__DIR__) . '/config/loom-planner.php';
    }

    public static function packageResourcePath(string $path = ''): string
    {
        $base = dirname(__DIR__) . '/resources';

        return $path ? "{$base}/{$path}" : $base;
    }
}
