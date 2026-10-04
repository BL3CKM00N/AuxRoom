<?php

namespace App\Services\Auth;

use App\Events\QrLoginUpdated;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Log in a device by approving it from another one that is already logged in,
 * the way WhatsApp Web does. A phone always scans and a computer always shows the
 * code, so there are two directions over the same token:
 *
 *  - ask: the logged-out device (a computer) shows the QR code and a 2-digit number;
 *    the logged-in phone scans it, types the number and approves.
 *  - offer: the logged-in device (a computer) shows the QR code; the logged-out phone
 *    scans it ("claims" it) and is shown a 2-digit number; the logged-in device types
 *    that number and approves.
 *
 * Either way the number is shown on the device that is asking to be logged in and
 * typed on the device that approves, and the asking device then collects the login.
 *
 * It is a way into someone's account, so it is deliberately strict:
 *  - a request lives 2 minutes and can be claimed once;
 *  - only the browser session that asked can claim it (it is tied to that session);
 *  - the person approving has to type the number shown on the asking screen, which
 *    is what stops a stranger's QR code, shown to you remotely, from being approved
 *    blind; three wrong numbers deny the request;
 *  - the phone approving cannot be the same session that asked.
 */
class QrLogin
{
    public const TTL_SECONDS = 120;

    /** How long approve/claim wait for each other on one request before giving up (seconds). */
    private const LOCK_WAIT_SECONDS = 3;

    public const MAX_WRONG_NUMBERS = 3;

    public const APPROVED = 'approved';

    public const WRONG_NUMBER = 'wrong_number';

    public const TOO_MANY = 'too_many';

    public const EXPIRED = 'expired';

    public const SAME_DEVICE = 'same_device';

    public const NOT_PENDING = 'not_pending';

    /** The request was being handled by another call at that moment: try again. */
    public const BUSY = 'busy';

    /** claimOffer() returns this prefix followed by the 2-digit number the claiming device must show. */
    public const CLAIMED_PREFIX = 'claimed:';

    public function __construct(private int $lockWaitSeconds = self::LOCK_WAIT_SECONDS) {}

    /** @return array{token: string, code: string} */
    public function start(string $sessionId, ?string $userAgent): array
    {
        $token = Str::random(40);
        $code = (string) random_int(10, 99);

        Cache::put(self::key($token), [
            'direction' => 'ask',
            'status' => 'pending',
            'code' => $code,
            'session' => $sessionId,
            'agent' => (string) $userAgent,
            'at' => now()->getTimestamp(),
            'wrong' => 0,
            'user_id' => null,
        ], self::TTL_SECONDS);

        return ['token' => $token, 'code' => $code];
    }

    /**
     * The logged-in device offers a login: it shows this token as a QR code and waits
     * for another device to claim it. $sessionId is the offering browser session, the
     * only one that may approve the claim later.
     *
     * @return array{token: string}
     */
    public function offer(User $user, string $sessionId): array
    {
        $token = Str::random(40);

        Cache::put(self::key($token), [
            'direction' => 'offer',
            'status' => 'open',
            'code' => null,
            'session' => null,
            'offer_session' => $sessionId,
            'agent' => '',
            'at' => now()->getTimestamp(),
            'wrong' => 0,
            'user_id' => $user->id,
        ], self::TTL_SECONDS);

        return ['token' => $token];
    }

    /**
     * A logged-out device scans an offer. It becomes the asking device: from here only
     * its own browser session can collect the login, and it is given the number to read
     * out to the offering device. Returns that number, or a result constant on failure.
     */
    public function claimOffer(string $token, string $requesterSessionId, ?string $userAgent): string
    {
        return $this->locked($token, self::BUSY, function () use ($token, $requesterSessionId, $userAgent) {
            $request = $this->find($token);

            if (! $request) {
                return self::EXPIRED;
            }

            if (($request['direction'] ?? 'ask') !== 'offer' || $request['status'] !== 'open') {
                return self::NOT_PENDING;
            }

            // The offering browser scanning its own code would defeat the point.
            if (hash_equals((string) $request['offer_session'], $requesterSessionId)) {
                return self::SAME_DEVICE;
            }

            $request['status'] = 'claimed';
            $request['session'] = $requesterSessionId;
            $request['agent'] = (string) $userAgent;
            $request['code'] = (string) random_int(10, 99);
            $this->store($token, $request);
            QrLoginUpdated::broadcastFor($token, 'claimed');

            return self::CLAIMED_PREFIX.$request['code'];
        });
    }

    /**
     * The offering device approves the claim by typing the number shown on the claiming
     * device. Only the offering browser session may do it, and three wrong numbers
     * cancel the request, exactly as in the other direction.
     */
    public function approveClaim(User $user, string $token, string $approvingSessionId, string $typedCode): string
    {
        return $this->locked($token, self::BUSY, function () use ($user, $token, $approvingSessionId, $typedCode) {
            $request = $this->find($token);

            if (! $request) {
                return self::EXPIRED;
            }

            if (($request['direction'] ?? 'ask') !== 'offer' || $request['status'] !== 'claimed') {
                return self::NOT_PENDING;
            }

            if ((int) $request['user_id'] !== $user->id || ! hash_equals((string) $request['offer_session'], $approvingSessionId)) {
                return self::NOT_PENDING;
            }

            return $this->decide($token, $request, $typedCode);
        });
    }

    /** @return array{status: string, code: string, session: string, agent: string, at: int, wrong: int, user_id: ?int}|null */
    public function find(string $token): ?array
    {
        return Cache::get(self::key($token));
    }

    /** The result is one of the class constants. Approving needs the right number. */
    public function approve(User $user, string $token, string $approvingSessionId, string $typedCode): string
    {
        return $this->locked($token, self::BUSY, function () use ($user, $token, $approvingSessionId, $typedCode) {
            $request = $this->find($token);

            if (! $request) {
                return self::EXPIRED;
            }

            if (($request['direction'] ?? 'ask') !== 'ask' || $request['status'] !== 'pending') {
                return self::NOT_PENDING;
            }

            // Approving your own request would defeat the point: the asking screen must be another device.
            if (hash_equals($request['session'], $approvingSessionId)) {
                return self::SAME_DEVICE;
            }

            $request['user_id'] = $user->id;

            return $this->decide($token, $request, $typedCode);
        });
    }

    public function deny(string $token): void
    {
        $request = $this->find($token);

        if ($request && in_array($request['status'], ['pending', 'open', 'claimed'], true)) {
            $request['status'] = 'denied';
            $this->store($token, $request);
            QrLoginUpdated::broadcastFor($token, 'denied');
        }
    }

    /**
     * The computer collecting its login. Returns the approved user's id once, and
     * only to the browser session that asked: the request is consumed, so a second
     * try (or anyone else) gets nothing.
     */
    public function claim(string $token, string $sessionId): ?int
    {
        return $this->locked($token, null, function () use ($token, $sessionId) {
            $request = $this->find($token);

            if (! $request || $request['status'] !== 'approved' || ! hash_equals($request['session'], $sessionId)) {
                return null;
            }

            Cache::forget(self::key($token));

            return $request['user_id'];
        });
    }

    /**
     * Runs $work while holding this token's lock, so approve and claim cannot
     * interleave. A lock that stays busy gives $busy back (the caller can retry)
     * instead of surfacing a LockTimeoutException as a server error.
     */
    private function locked(string $token, mixed $busy, \Closure $work): mixed
    {
        try {
            return Cache::lock('qr-login-lock:'.$token, 5)->block($this->lockWaitSeconds, $work);
        } catch (LockTimeoutException) {
            return $busy;
        }
    }

    /** Checks the typed number. Right: approved. Wrong: counted, and the third cancels the request. */
    private function decide(string $token, array $request, string $typedCode): string
    {
        if (! hash_equals((string) $request['code'], trim($typedCode))) {
            $request['wrong']++;

            if ($request['wrong'] >= self::MAX_WRONG_NUMBERS) {
                $request['status'] = 'denied';
                $this->store($token, $request);
                QrLoginUpdated::broadcastFor($token, 'denied');

                return self::TOO_MANY;
            }

            $this->store($token, $request);

            return self::WRONG_NUMBER;
        }

        $request['status'] = 'approved';
        $this->store($token, $request);
        QrLoginUpdated::broadcastFor($token, 'approved');

        return self::APPROVED;
    }

    private function store(string $token, array $request): void
    {
        // Keep the original expiry: a wrong guess must not buy more time.
        $remaining = max(1, self::TTL_SECONDS - (now()->getTimestamp() - $request['at']));

        Cache::put(self::key($token), $request, $remaining);
    }

    private static function key(string $token): string
    {
        return 'qr-login:'.$token;
    }
}
