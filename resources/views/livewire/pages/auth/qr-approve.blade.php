<?php

use App\Services\Auth\QrLogin;
use App\Support\UserAgent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';

    public string $typed = '';

    // ready | approved | denied | expired | blocked
    #[Locked]
    public string $outcome = 'ready';

    public string $error = '';

    /** The guide that explains $error, shown as a link beside it. */
    #[Locked]
    public string $errorCode = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function approve(QrLogin $qr): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $key = 'qr-approve:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->fail('AUTH-QR-LIMIT', 'Too many tries. Wait a few minutes and start again on the other device.');

            return;
        }

        RateLimiter::hit($key, 600);

        $result = $qr->approve($user, $this->token, Session::getId(), $this->typed);

        match ($result) {
            QrLogin::APPROVED => $this->approved($qr),
            QrLogin::WRONG_NUMBER => $this->fail('AUTH-QR-CODE', "That number doesn't match the one on the other screen. Check it and try again."),
            QrLogin::TOO_MANY => $this->finish('blocked'),
            QrLogin::SAME_DEVICE => $this->fail('AUTH-QR-CODE', 'This is the same device that asked. Scan the code with your phone instead.'),
            QrLogin::BUSY => $this->fail('AUTH-QR-BUSY', 'That took a moment too long. Press Approve again.'),
            default => $this->finish('expired'),
        };
    }

    public function deny(QrLogin $qr): void
    {
        // Like approve(): only a logged-in account may decide. The page renders for visitors, so this must check.
        if (! auth()->check()) {
            return;
        }

        $qr->deny($this->token);

        $this->finish('denied');
    }

    private function fail(string $code, string $message): void
    {
        $this->error = $message;
        $this->errorCode = $code;
    }

    private function approved(QrLogin $qr): void
    {
        $this->error = '';
        $this->errorCode = '';
        $this->outcome = 'approved';

        Log::info('QR login approved', ['user_id' => auth()->id(), 'asking_agent' => $qr->find($this->token)['agent'] ?? null]);
    }

    private function finish(string $outcome): void
    {
        $this->error = '';
        $this->errorCode = '';
        $this->outcome = $outcome;
    }

    public function with(QrLogin $qr): array
    {
        $request = $qr->find($this->token);

        return [
            'request' => $request,
            'device' => $request ? UserAgent::parse($request['agent'])['label'] : null,
        ];
    }
}; ?>

<div class="text-center">
    <h1 class="text-xl font-semibold text-aux-text">Log in another device</h1>

    @guest
        <p class="mt-3 text-sm text-aux-muted">
            To log in the other device, approve it from a phone where you're already logged in to AuxRoom:
            open the AuxRoom menu, choose <span class="font-semibold text-aux-text">Log in another device</span>, and scan the code again.
        </p>
        <a href="{{ route('login') }}" class="mt-5 inline-flex px-4 py-2 rounded-full bg-aux-accent text-black text-sm font-semibold">Log in on this device instead</a>
    @else
        @if ($outcome === 'approved')
            <p class="mt-4 text-sm text-aux-accent">Approved. The other device is logging in now.</p>
            <a href="/" class="mt-5 inline-flex px-4 py-2 rounded-full border border-aux-border text-sm">Done</a>
        @elseif ($outcome === 'denied')
            <p class="mt-4 text-sm text-aux-muted">Denied. The other device was not logged in.</p>
            <a href="/" class="mt-5 inline-flex px-4 py-2 rounded-full border border-aux-border text-sm">Done</a>
        @elseif ($outcome === 'blocked')
            <p class="mt-4 text-sm text-red-400">Too many wrong numbers, so this request was cancelled. Start again on the other device. <a href="{{ route('help') }}#auth-qr-code" target="_blank" class="underline">[AUTH-QR-CODE]</a></p>
        @elseif (! $request || $outcome === 'expired')
            <p class="mt-4 text-sm text-amber-400">That code has expired or was already used. Codes last 2 minutes and work once. Ask the other device for a new one. <a href="{{ route('help') }}#auth-qr-expired" target="_blank" class="underline">[AUTH-QR-EXPIRED]</a></p>
        @else
            <p class="mt-3 text-sm text-aux-muted">
                <span class="font-semibold text-aux-text">{{ $device }}</span> wants to log in as
                <span class="font-semibold text-aux-text">{{ auth()->user()->name }}</span>.
            </p>

            <p class="mt-3 text-sm text-aux-muted">Type the number shown on that device's screen to approve it.</p>

            <form wire:submit="approve" class="mt-4 space-y-3">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" wire:model="typed" autofocus
                       class="w-24 text-center text-3xl tracking-widest rounded-lg bg-aux-card border border-aux-border py-2 focus:outline-none focus:border-aux-accent"
                       aria-label="The number shown on the other device">
                @if ($error)
                    <p class="text-sm text-red-400">{{ $error }}
                        @if ($errorCode)
                            <a href="{{ route('help') }}#{{ strtolower($errorCode) }}" target="_blank" class="underline">[{{ $errorCode }}]</a>
                        @endif
                    </p>
                @endif

                <div class="flex justify-center gap-3">
                    <button type="button" wire:click="deny" class="px-4 py-2 rounded-full border border-aux-border text-sm hover:bg-white/5">Deny</button>
                    <button type="submit" class="px-4 py-2 rounded-full bg-aux-accent text-black text-sm font-semibold">Approve</button>
                </div>
            </form>

            <p class="mt-4 text-xs text-aux-faint">Only approve this if you started it yourself, on a screen in front of you. Anyone you approve gets full access to your account.</p>
        @endif
    @endguest
</div>
