<?php

use App\Livewire\Actions\CompleteQrLogin;
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

    /** Set once a logged-out browser has claimed an offered code: the number to read out to the offering device. */
    #[Locked]
    public string $claimedCode = '';

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

    /**
     * A logged-out browser that opened an offered code (scanned with a camera app) claims
     * it: it is given a number to read out to the logged-in device that is showing the code.
     */
    public function claim(QrLogin $qr): void
    {
        if (auth()->check()) {
            return;
        }

        $result = $qr->claimOffer($this->token, Session::getId(), request()->userAgent());

        if (str_starts_with($result, QrLogin::CLAIMED_PREFIX)) {
            $this->claimedCode = substr($result, strlen(QrLogin::CLAIMED_PREFIX));

            return;
        }

        $result === QrLogin::BUSY
            ? $this->fail('AUTH-QR-BUSY', 'That took a moment too long. Press the button again.')
            : $this->finish('expired');
    }

    /** Polled while waiting for the logged-in device to approve; logs this browser in once it does. */
    public function check(QrLogin $qr, CompleteQrLogin $complete): void
    {
        if ($this->claimedCode === '') {
            return;
        }

        $request = $qr->find($this->token);

        if (! $request) {
            $this->finish('expired');

            return;
        }

        if ($request['status'] === 'denied') {
            $this->finish('denied');

            return;
        }

        if ($request['status'] === 'approved' && $complete($this->token) === CompleteQrLogin::LOGGED_IN) {
            $this->redirect('/', navigate: true);
        }
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
            'offered' => ($request['direction'] ?? 'ask') === 'offer',
        ];
    }
}; ?>

<div class="text-center" @if ($claimedCode !== '') wire:poll.3s="check" @endif>
    <h1 class="text-xl font-semibold text-aux-text">Log in another device</h1>

    @guest
        @if ($offered && ! in_array($outcome, ['expired', 'denied'], true))
            {{-- A logged-in device is showing this code to log in this browser (scanned with a camera app, so it landed here). --}}
            @if ($claimedCode === '')
                <p class="mt-3 text-sm text-aux-muted">A device where you are logged in to AuxRoom is offering to log in this browser.</p>
                <button type="button" wire:click="claim" class="mt-5 inline-flex px-4 py-2 rounded-full bg-aux-accent text-black text-sm font-semibold">Log in this browser</button>
                @if ($error)
                    <p class="mt-3 text-sm text-red-400">{{ $error }} <a href="{{ route('help') }}#{{ strtolower($errorCode) }}" target="_blank" class="underline">[{{ $errorCode }}]</a></p>
                @endif
            @else
                <p class="mt-3 text-sm text-aux-muted">Type this number on the device where you are logged in, then press Approve there:</p>
                <p class="mt-2 text-5xl font-bold tracking-widest text-aux-text" aria-label="Number to type on the other device">{{ $claimedCode }}</p>
                <p class="mt-3 text-xs text-aux-faint">This browser logs in by itself once it is approved.</p>
            @endif
        @elseif ($offered)
            <p class="mt-4 text-sm {{ $outcome === 'denied' ? 'text-aux-muted' : 'text-amber-400' }}">
                {{ $outcome === 'denied' ? 'Denied. This browser was not logged in.' : 'That code has expired or was already used. Ask for a new one on the other device.' }}
                <a href="{{ route('help') }}#auth-qr-{{ $outcome === 'denied' ? 'denied' : 'expired' }}" target="_blank" class="underline">[AUTH-QR-{{ $outcome === 'denied' ? 'DENIED' : 'EXPIRED' }}]</a>
            </p>
        @else
        <p class="mt-3 text-sm text-aux-muted">
            To log in the other device, approve it from a phone where you're already logged in to AuxRoom:
            open <span class="font-semibold text-aux-text">Profile</span>, choose <span class="font-semibold text-aux-text">Log in another device</span>, and scan the code again.
        </p>
        <a href="{{ route('login') }}" class="mt-5 inline-flex px-4 py-2 rounded-full bg-aux-accent text-black text-sm font-semibold">Log in on this device instead</a>
        @endif
    @else
        @if ($offered)
            <p class="mt-4 text-sm text-aux-muted">You're already logged in on this device. To log in another device, scan this code from that device's login page.</p>
            <a href="/" class="mt-5 inline-flex px-4 py-2 rounded-full border border-aux-border text-sm">Back</a>
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
        @endif
    @endguest
</div>
