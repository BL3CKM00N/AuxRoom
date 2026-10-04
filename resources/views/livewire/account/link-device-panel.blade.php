<?php

use App\Services\Auth\QrLogin;
use App\Support\UserAgent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * "Log in another device" on the profile page, for a logged-in device. A computer shows
 * a QR code that the logged-out phone scans and then approves by typing the number the
 * phone shows; a phone scans the code a logged-out computer shows (the scanner opens
 * the approval page). The page switches between the two with a link.
 */
new class extends Component
{
    #[Locked]
    public string $token = '';

    // idle | open (showing a code) | claimed (a device scanned it) | approved | denied | expired | limited
    #[Locked]
    public string $state = 'idle';

    #[Locked]
    public string $svg = '';

    /** The device that scanned the code, as the person would recognise it. */
    #[Locked]
    public string $device = '';

    public string $typed = '';

    #[Locked]
    public string $error = '';

    #[Locked]
    public string $errorCode = '';

    /** Shows a code for another device to scan. */
    public function showCode(QrLogin $qr): void
    {
        $key = 'qr-start:user:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->state = 'limited';

            return;
        }

        RateLimiter::hit($key, 60);

        $this->stop($qr);

        $this->token = $qr->offer(auth()->user(), Session::getId())['token'];
        $this->svg = (string) QrCode::size(176)->margin(0)->generate(route('qr-login.show', $this->token));
        $this->state = 'open';
    }

    /** Stops showing a code; whoever had scanned it is turned down. */
    public function stop(QrLogin $qr): void
    {
        if ($this->token !== '') {
            $qr->deny($this->token);
        }

        $this->reset('token', 'svg', 'device', 'typed', 'error', 'errorCode');
        $this->state = 'idle';
    }

    /** Polled, and run when the live push arrives. */
    #[On('echo:qr-login.{token},QrLoginUpdated')]
    public function check(QrLogin $qr): void
    {
        if (! in_array($this->state, ['open', 'claimed'], true)) {
            return;
        }

        $request = $qr->find($this->token);

        if (! $request) {
            $this->state = 'expired';

            return;
        }

        $this->device = $request['agent'] !== '' ? UserAgent::parse($request['agent'])['label'] : '';

        $this->state = match ($request['status']) {
            'denied' => 'denied',
            'claimed' => 'claimed',
            'approved' => 'approved',
            default => $this->state,
        };
    }

    public function approve(QrLogin $qr): void
    {
        $key = 'qr-approve:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->fail('AUTH-QR-LIMIT', 'Too many tries. Wait a few minutes and start again.');

            return;
        }

        RateLimiter::hit($key, 600);

        $result = $qr->approveClaim(auth()->user(), $this->token, Session::getId(), $this->typed);

        match ($result) {
            QrLogin::APPROVED => $this->approved(),
            QrLogin::WRONG_NUMBER => $this->fail('AUTH-QR-CODE', "That number doesn't match the one on the other device. Check it and try again."),
            QrLogin::BUSY => $this->fail('AUTH-QR-BUSY', 'That took a moment too long. Press Approve again.'),
            QrLogin::TOO_MANY => $this->state = 'denied',
            default => $this->state = 'expired',
        };
    }

    public function deny(QrLogin $qr): void
    {
        $qr->deny($this->token);

        $this->state = 'denied';
    }

    private function approved(): void
    {
        $this->reset('error', 'errorCode');
        $this->state = 'approved';

        Log::info('QR login approved (offer)', ['user_id' => auth()->id(), 'asking_agent' => $this->device]);
    }

    private function fail(string $code, string $message): void
    {
        $this->error = $message;
        $this->errorCode = $code;
    }
}; ?>

<div x-data="{ mode: 'show' }"
     x-init="mode = matchMedia('(pointer: coarse)').matches ? 'scan' : 'show'; if (mode === 'show') $wire.showCode()"
     @if (in_array($state, ['open', 'claimed'], true)) wire:poll.3s="check" @endif>

    {{-- Scan side: for a phone, which scans the code a logged-out computer is showing. --}}
    <div x-show="mode === 'scan'" x-cloak>
        <ol class="list-decimal pl-5 space-y-2 text-sm text-aux-muted">
            <li>On the other device, open AuxRoom's login page and press <span class="font-semibold text-aux-text">Log in with QR</span>.</li>
            <li>Scan the QR code it shows with the button below.</li>
            <li>Type the number shown on that screen and approve.</li>
        </ol>

        <div class="mt-6">
            <x-qr-scan-button mode="login" label="Scan the login code" hint="Point your camera at the QR code on the other device's login page" />
        </div>

        <button type="button" x-on:click="mode = 'show'; $wire.showCode()" class="mt-4 text-xs text-aux-muted underline underline-offset-2 hover:text-aux-text">Show a code instead</button>
    </div>

    {{-- Show side: for a computer, which shows a code the logged-out phone scans. --}}
    <div x-show="mode === 'show'" x-cloak>
        @if ($state === 'claimed')
            <h3 class="text-base font-semibold text-aux-text">{{ $device ?: 'A device' }} wants to log in as you</h3>
            <p class="mt-2 text-sm text-aux-muted">Type the number shown on that device to approve it.</p>

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

                <div class="flex gap-3">
                    <button type="button" wire:click="deny" class="px-4 py-2 rounded-full border border-aux-border text-sm hover:bg-white/5">Deny</button>
                    <button type="submit" class="px-4 py-2 rounded-full bg-aux-accent text-black text-sm font-semibold">Approve</button>
                </div>
            </form>

            <p class="mt-4 text-xs text-aux-faint">Only approve this if you started it yourself, on a device in front of you. Anyone you approve gets full access to your account.</p>
        @elseif ($state === 'open')
            <ol class="list-decimal pl-5 space-y-2 text-sm text-aux-muted">
                <li>On the other device, open AuxRoom's login page and press <span class="font-semibold text-aux-text">Log in with QR</span>.</li>
                <li>Scan this code with it.</li>
                <li>That device shows a number. Type it here and approve.</li>
            </ol>

            <div class="mt-5 inline-block p-3 bg-white rounded-xl">{!! $svg !!}</div>
            <p class="mt-3 text-xs text-aux-faint">Valid for 2 minutes and one use.</p>
        @elseif ($state === 'approved')
            <p class="text-sm text-aux-accent">Approved. The other device is logging in now. It will appear under Signed-in devices.</p>
            <button type="button" wire:click="showCode" class="mt-3 px-4 py-2 rounded-full border border-aux-border text-sm hover:bg-white/5">Log in another device</button>
        @elseif ($state === 'denied')
            <p class="text-sm text-aux-muted">Denied. Nothing was logged in. <a href="{{ route('help') }}#auth-qr-denied" target="_blank" class="underline">[AUTH-QR-DENIED]</a></p>
            <button type="button" wire:click="showCode" class="mt-3 px-4 py-2 rounded-full border border-aux-border text-sm hover:bg-white/5">Show a new code</button>
        @elseif ($state === 'expired')
            <p class="text-sm text-amber-400">That code expired. They last 2 minutes and work once. <a href="{{ route('help') }}#auth-qr-expired" target="_blank" class="underline">[AUTH-QR-EXPIRED]</a></p>
            <button type="button" wire:click="showCode" class="mt-3 px-4 py-2 rounded-full border border-aux-border text-sm hover:bg-white/5">Show a new code</button>
        @elseif ($state === 'limited')
            <p class="text-sm text-amber-400">Too many codes were requested just now. Wait a minute and try again. <a href="{{ route('help') }}#auth-qr-limit" target="_blank" class="underline">[AUTH-QR-LIMIT]</a></p>
        @endif

        <button type="button" x-on:click="mode = 'scan'; $wire.stop()" class="mt-4 block text-xs text-aux-muted underline underline-offset-2 hover:text-aux-text">Scan a code instead</button>
    </div>
</div>
