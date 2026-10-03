<?php

namespace App\Livewire\Dashboard\Concerns;

use App\Services\Spotify\CommandFailure;
use Livewire\Attributes\Locked;

/**
 * The error banner above the dashboard: a short message, plus a catalog code so
 * "More info" can show what happened and what to do (see ErrorCatalog and
 * <x-error-notice>). $controlError is still the plain message.
 */
trait ReportsErrors
{
    /** Which catalog entry explains $controlError. Only ever set here. */
    #[Locked]
    public string $controlErrorCode = '';

    /** What Spotify said, for the copy-details block. Never holds a token or URL. */
    #[Locked]
    public string $controlErrorDetails = '';

    private function fail(string $code, string $message, ?string $details = null): void
    {
        $this->controlError = $message;
        $this->controlErrorCode = $code;
        $this->controlErrorDetails = (string) $details;
    }

    private function clearError(): void
    {
        $this->controlError = '';
        $this->controlErrorCode = '';
        $this->controlErrorDetails = '';
    }

    /**
     * A Spotify command just failed. Names the real cause when Spotify said what
     * it was (device asleep, Premium needed), else uses $generic under $code.
     */
    private function failCommand(string $code, string $generic): void
    {
        $failure = app(CommandFailure::class);

        $specific = match (true) {
            $failure->deviceAsleep() => 'SP-ASLEEP',
            $failure->premiumRequired() => 'SP-PREMIUM',
            default => $code,
        };

        $this->fail($specific, $failure->message($generic), $failure->summary());
    }
}
