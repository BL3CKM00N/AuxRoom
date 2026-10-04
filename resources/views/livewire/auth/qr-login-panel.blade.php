<?php

use App\Livewire\Actions\CompleteQrLogin;
use App\Services\Auth\QrLogin;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * "Log in with QR" on the login page. A phone scans and a computer shows, but either
 * can do both (the popup has a switch), because the other device decides:
 *  - show: this device asks, shows a QR code and a number; a logged-in phone scans it.
 *  - scan: this device scans a code a logged-in computer is showing, is given a number
 *    to read out to it, and logs in once that computer approves.
 */
new class extends Component
{
    /** The request's one-time token. Locked: only start()/scanned() set it, and it names the broadcast channel. */
    #[Locked]
    public string $token = '';

    /** The 2-digit number: shown for others to type (show) or to read out to the computer (scan). */
    #[Locked]
    public string $code = '';

    // idle | pending (show: waiting for a phone) | claimed (scan: waiting for the computer) | denied | expired | limited
    #[Locked]
    public string $state = 'idle';

    // show | scan | '' (nothing started)
    #[Locked]
    public string $role = '';

    /** The QR code, drawn once per request: the link it holds never changes while the request lives. */
    #[Locked]
    public string $svg = '';

    /** Why a scanned code could not be used, in words (scan only). */
    #[Locked]
    public string $problem = '';

    /** Show mode. Pressed, not automatic, so a bot loading the login page creates nothing. */
    public function start(QrLogin $qr): void
    {
        if ($this->limited()) {
            $this->state = 'limited';
            $this->role = 'show';

            return;
        }

        $request = $qr->start(Session::getId(), request()->userAgent());

        $this->token = $request['token'];
        $this->code = $request['code'];
        $this->svg = (string) QrCode::size(176)->margin(0)->generate(route('qr-login.show', $this->token));
        $this->role = 'show';
        $this->problem = '';
        $this->state = 'pending';
    }

    /** Scan mode: the camera read a code. Claims it and gets the number to read out. */
    public function scanned(string $token, QrLogin $qr): void
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            $this->problem = "That QR code isn't an AuxRoom login code.";

            return;
        }

        if ($this->limited()) {
            $this->state = 'limited';
            $this->role = 'scan';

            return;
        }

        $result = $qr->claimOffer($token, Session::getId(), request()->userAgent());

        $this->role = 'scan';

        if (str_starts_with($result, QrLogin::CLAIMED_PREFIX)) {
            $this->token = $token;
            $this->code = substr($result, strlen(QrLogin::CLAIMED_PREFIX));
            $this->problem = '';
            $this->state = 'claimed';

            return;
        }

        $this->problem = match ($result) {
            QrLogin::SAME_DEVICE => 'This is the device showing the code. Scan it with the device you want to log in.',
            QrLogin::BUSY => 'That took a moment too long. Scan the code again.',
            default => 'That code has expired or was already used. Ask for a new one on the other device.',
        };
        $this->state = 'idle';
    }

    /** Closing the popup with Cancel drops the request, so nothing is left waiting to be approved. */
    public function cancel(QrLogin $qr): void
    {
        if ($this->token !== '') {
            $qr->deny($this->token);
        }

        $this->token = '';
        $this->code = '';
        $this->svg = '';
        $this->problem = '';
        $this->role = '';
        $this->state = 'idle';
    }

    /** Polled, and also run when the live push arrives. */
    #[On('echo:qr-login.{token},QrLoginUpdated')]
    public function check(QrLogin $qr, CompleteQrLogin $complete): void
    {
        if (! in_array($this->state, ['pending', 'claimed'], true)) {
            return;
        }

        $request = $qr->find($this->token);

        if (! $request) {
            $this->state = 'expired';

            return;
        }

        if ($request['status'] === 'denied') {
            $this->state = 'denied';

            return;
        }

        if ($request['status'] !== 'approved') {
            return;
        }

        $outcome = $complete($this->token);

        if ($outcome === CompleteQrLogin::GONE) {
            $this->state = 'expired';

            return;
        }

        if ($outcome === CompleteQrLogin::LOGGED_IN) {
            // Home sends a logged-in visitor to their room, or to room creation.
            $this->redirect('/', navigate: true);
        }
    }

    /** Every request is a cache entry for two minutes, so how many can be made is limited per session and address. */
    private function limited(): bool
    {
        $keys = ['qr-start:session:'.Session::getId(), 'qr-start:ip:'.request()->ip()];

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, 10)) {
                return true;
            }
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, 60);
        }

        return false;
    }
}; ?>

<div class="ms-3" @if (in_array($state, ['pending', 'claimed'], true)) wire:poll.3s="check" @endif>
    {{-- A small button in the login form's action row; everything else opens in a popup. On a phone it opens
         the camera side (scan), on a computer the code side (show); the popup switches either way. --}}
    <button type="button"
            x-data
            x-on:click="
                const phone = matchMedia('(pointer: coarse)').matches;
                (phone ? $wire.cancel() : $wire.start()).then(() => {
                    $dispatch('qr-mode', phone ? 'scan' : 'show');
                    $dispatch('open-modal', 'qr-login');
                });
            "
            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-full border border-aux-border text-xs font-medium text-aux-muted hover:text-aux-text hover:bg-white/5">
        <x-icon name="qr" class="w-4 h-4" /> Log in with QR
    </button>

    <x-modal name="qr-login" maxWidth="sm" centered>
        <div class="p-6 text-center" x-data="{ mode: 'show' }" x-on:qr-mode.window="mode = $event.detail"
             x-on:qr-token-scanned.window="mode = 'scan'; $wire.scanned($event.detail.token)">

            @if ($state === 'pending')
                <h2 class="text-base font-semibold text-aux-text">Log in with your phone</h2>

                <div class="mt-4 inline-block p-3 bg-white rounded-xl">{!! $svg !!}</div>

                <p class="mt-4 text-sm text-aux-muted">
                    On your phone, open <span class="font-semibold text-aux-text">Profile</span>, choose <span class="font-semibold text-aux-text">Log in another device</span>, scan this code and type the number:
                </p>
                <p class="mt-2 text-4xl font-bold tracking-widest text-aux-text" aria-label="Number to type on your phone">{{ $code }}</p>
                <p class="mt-3 text-xs text-aux-faint">Valid for 2 minutes. Only approve this on your phone if you started it yourself.</p>

                <button type="button" x-on:click="mode = 'scan'; $wire.cancel()" class="mt-4 text-xs text-aux-muted underline underline-offset-2 hover:text-aux-text">Scan a code instead</button>
            @elseif ($state === 'claimed')
                <h2 class="text-base font-semibold text-aux-text">Waiting for your computer</h2>
                <p class="mt-3 text-sm text-aux-muted">Type this number on the computer where you are logged in, then press Approve there:</p>
                <p class="mt-2 text-5xl font-bold tracking-widest text-aux-text" aria-label="Number to type on the other device">{{ $code }}</p>
                <p class="mt-3 text-xs text-aux-faint">This device logs in by itself once it is approved. Valid for 2 minutes.</p>
            @elseif ($state === 'denied')
                <p class="text-sm text-red-400">The login was denied, so nothing happened. <a href="{{ route('help') }}#auth-qr-denied" target="_blank" class="underline">[AUTH-QR-DENIED]</a></p>
                <button type="button" wire:click="cancel" class="mt-3 px-4 py-2 rounded-full border border-aux-border text-sm hover:bg-white/5">Try again</button>
            @elseif ($state === 'limited')
                <p class="text-sm text-amber-400">Too many codes were requested just now. Wait a minute and try again. <a href="{{ route('help') }}#auth-qr-limit" target="_blank" class="underline">[AUTH-QR-LIMIT]</a></p>
            @elseif ($state === 'expired')
                <p class="text-sm text-amber-400">That code expired. They last 2 minutes and work once. <a href="{{ route('help') }}#auth-qr-expired" target="_blank" class="underline">[AUTH-QR-EXPIRED]</a></p>
                <button type="button" wire:click="cancel" class="mt-3 px-4 py-2 rounded-full border border-aux-border text-sm hover:bg-white/5">Start again</button>
            @else
                {{-- Nothing started yet: the scan side (phones start here), or the show side before its code arrives. --}}
                <div x-show="mode === 'scan'">
                    <h2 class="text-base font-semibold text-aux-text">Scan the code on your computer</h2>
                    <p class="mt-3 text-sm text-aux-muted">
                        On a computer where you are logged in, open <span class="font-semibold text-aux-text">Profile</span>, choose <span class="font-semibold text-aux-text">Log in another device</span>, then scan the code it shows.
                    </p>

                    @if ($problem)
                        <p class="mt-3 text-sm text-amber-400">{{ $problem }} <a href="{{ route('help') }}#auth-qr-expired" target="_blank" class="underline">[AUTH-QR-EXPIRED]</a></p>
                    @endif

                    <div class="mt-4">
                        <x-qr-scan-button mode="token" label="Scan the code" hint="Point your camera at the QR code on your computer" />
                    </div>

                    <button type="button" x-on:click="mode = 'show'; $wire.start()" class="mt-4 text-xs text-aux-muted underline underline-offset-2 hover:text-aux-text">Show a code instead</button>
                </div>
            @endif

            <div class="mt-5">
                <button type="button" wire:click="cancel" x-on:click="$dispatch('close')" class="text-xs text-aux-faint underline underline-offset-2 hover:text-aux-text">Cancel</button>
            </div>
        </div>
    </x-modal>
</div>
