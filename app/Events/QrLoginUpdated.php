<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the computer showing a login QR code that it was approved or denied,
 * so it can react at once instead of waiting for its next poll. The channel
 * name is the request's own unguessable token, and the event carries only the
 * word "approved" or "denied": the login itself is claimed through the session
 * that asked (see QrLogin::claim()), never through this message.
 */
class QrLoginUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public string $token, public string $outcome) {}

    public static function broadcastFor(string $token, string $outcome): void
    {
        try {
            broadcast(new self($token, $outcome));
        } catch (Throwable $e) {
            // The computer also polls, so a missed push only costs a couple of seconds.
            Log::warning('Failed to broadcast QrLoginUpdated', ['exception' => $e->getMessage()]);
        }
    }

    public function broadcastOn(): array
    {
        return [new Channel('qr-login.'.$this->token)];
    }

    public function broadcastAs(): string
    {
        return 'QrLoginUpdated';
    }

    public function broadcastWith(): array
    {
        return ['outcome' => $this->outcome];
    }
}
