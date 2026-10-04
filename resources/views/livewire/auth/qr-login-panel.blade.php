<?php

use App\Services\Auth\QrLogin;
use App\Services\Auth\UserSessions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

new class extends Component
{
    /** The request's one-time token. Locked: only start() sets it, and it names the broadcast channel. */
    #[Locked]
    public string $token = '';

    /** The 2-digit number the phone has to type. */
    #[Locked]
    public string $code = '';

    // idle | pending | denied | expired | limited
    #[Locked]
    public string $state = 'idle';

    /** The QR code, drawn once per request: the link it holds never changes while the request lives. */
    #[Locked]
    public string $svg = '';

    /** Shown only after pressing the button, so a bot loading the login page creates nothing. */
    public function start(QrLogin $qr): void
    {
        // Every request is a cache entry for two minutes, so how many can be made is limited
        // per browser session and per address.
        $keys = ['qr-start:session:'.Session::getId(), 'qr-start:ip:'.request()->ip()];

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, 10)) {
                $this->state = 'limited';

                return;
            }
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, 60);
        }

        $request = $qr->start(Session::getId(), request()->userAgent());

        $this->token = $request['token'];
        $this->code = $request['code'];
        $this->svg = (string) QrCode::size(176)->margin(0)->generate(route('qr-login.show', $this->token));
        $this->state = 'pending';
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
        $this->state = 'idle';
    }

    /** Polled, and also run when the live push arrives. */
    #[On('echo:qr-login.{token},QrLoginUpdated')]
    public function check(QrLogin $qr, UserSessions $sessions): void
    {
        if ($this->state !== 'pending') {
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

        $userId = $qr->claim($this->token, Session::getId());

        if (! $userId) {
            return;
        }

        // The account can be gone by now (deleted between approval and claim).
        if (! Auth::loginUsingId($userId)) {
            $this->state = 'expired';

            return;
        }

        Session::regenerate();
        $sessions->markVia(Session::getId(), 'qr');

        Log::info('QR login completed', ['user_id' => $userId, 'agent' => request()->userAgent()]);

        // Home sends a logged-in visitor to their room, or to room creation.
        $this->redirect('/', navigate: true);
    }

}; ?>

<div class="ms-3" @if ($state === 'pending') wire:poll.3s="check" @endif>
    {{-- Just a small button in the login form's action row; the code itself opens in a popup. --}}
    <button type="button"
            x-data x-on:click="$wire.start().then(() => $dispatch('open-modal', 'qr-login'))"
            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-full border border-aux-border text-xs font-medium text-aux-muted hover:text-aux-text hover:bg-white/5">
        <x-icon name="qr" class="w-4 h-4" /> Log in with QR
    </button>

    <x-modal name="qr-login" maxWidth="sm" centered>
        <div class="p-6 text-center">
            @if ($state === 'pending')
                <h2 class="text-base font-semibold text-aux-text">Log in with your phone</h2>

                <div class="mt-4 inline-block p-3 bg-white rounded-xl">{!! $svg !!}</div>

                <p class="mt-4 text-sm text-aux-muted">
                    On your phone, open the AuxRoom menu, choose <span class="font-semibold text-aux-text">Log in another device</span>, scan this code and type the number:
                </p>
                <p class="mt-2 text-4xl font-bold tracking-widest text-aux-text" aria-label="Number to type on your phone">{{ $code }}</p>
                <p class="mt-3 text-xs text-aux-faint">Valid for 2 minutes. Only approve this on your phone if you started it yourself.</p>
            @elseif ($state === 'denied')
                <p class="text-sm text-red-400">The login was denied on the phone, so nothing happened. <a href="{{ route('help') }}#auth-qr-denied" target="_blank" class="underline">[AUTH-QR-DENIED]</a></p>
                <button type="button" wire:click="start" class="mt-3 px-4 py-2 rounded-full border border-aux-border text-sm hover:bg-white/5">Try again</button>
            @elseif ($state === 'limited')
                <p class="text-sm text-amber-400">Too many codes were requested just now. Wait a minute and try again. <a href="{{ route('help') }}#auth-qr-limit" target="_blank" class="underline">[AUTH-QR-LIMIT]</a></p>
            @elseif ($state === 'expired')
                <p class="text-sm text-amber-400">That code expired. They last 2 minutes and work once. <a href="{{ route('help') }}#auth-qr-expired" target="_blank" class="underline">[AUTH-QR-EXPIRED]</a></p>
                <button type="button" wire:click="start" class="mt-3 px-4 py-2 rounded-full border border-aux-border text-sm hover:bg-white/5">Show a new code</button>
            @endif

            <div class="mt-5">
                <button type="button" wire:click="cancel" x-on:click="$dispatch('close')" class="text-xs text-aux-faint underline underline-offset-2 hover:text-aux-text">Cancel</button>
            </div>
        </div>
    </x-modal>
</div>
