<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Things the production start command must keep doing. */
class DeployConfigTest extends TestCase
{
    private function command(): string
    {
        return file_get_contents(base_path('nixpacks.toml'));
    }

    public function test_the_web_server_has_several_workers(): void
    {
        $command = $this->command();

        // Laravel ignores the variable without --no-reload and silently runs one worker.
        $this->assertMatchesRegularExpression('/PHP_CLI_SERVER_WORKERS=[2-9]\d* php artisan serve [^"]*--no-reload/', $command);
    }

    public function test_the_other_background_processes_are_still_started(): void
    {
        $command = $this->command();

        $this->assertStringContainsString('php artisan migrate --force', $command);
        $this->assertStringContainsString('php artisan reverb:start', $command);
        $this->assertStringContainsString('php artisan spotify:watch', $command);
    }
}
