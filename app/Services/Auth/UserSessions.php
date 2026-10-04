<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\UserAgent;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The logins an account currently has, one per device, read from the database
 * session table (a row per session, with the account id once someone is
 * logged in). Used to decide whether a logout is the last one (so the room
 * closes), to list devices on the profile page, and to sign a device out
 * remotely with a message it can show.
 *
 * "Live" means active within the session lifetime: a session idle longer than
 * that is already expired, whatever the table still holds.
 */
class UserSessions
{
    private const NOTE_TTL_HOURS = 24;

    /**
     * Device tracking reads the database session table. With another driver (redis,
     * file) there is nothing to read, so every login would look like the only one.
     * Callers ask first, and the answer is logged once, loudly, instead of silently
     * degrading.
     */
    public function supported(): bool
    {
        $supported = config('session.driver') === 'database';

        if (! $supported && ! self::$warned) {
            self::$warned = true;
            \Illuminate\Support\Facades\Log::warning('Device tracking needs SESSION_DRIVER=database; logouts will always close the room and the devices list is empty.', ['driver' => config('session.driver')]);
        }

        return $supported;
    }

    private static bool $warned = false;

    private function table(): Builder
    {
        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));
    }

    /** @return Collection<int, object> raw session rows, newest activity first */
    public function live(User $user, ?string $exceptId = null): Collection
    {
        if (! $this->supported()) {
            return collect();
        }

        return $this->table()
            ->where('user_id', $user->id)
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->when($exceptId, fn (Builder $q) => $q->where('id', '!=', $exceptId))
            ->orderByDesc('last_activity')
            ->get();
    }

    public function otherLiveCount(User $user, string $currentId): int
    {
        return $this->live($user, $currentId)->count();
    }

    /**
     * @return array<int, array{id: string, label: string, browser: string, os: string, lastActive: Carbon, current: bool, via: ?string}>
     */
    public function devices(User $user, string $currentId): array
    {
        return $this->live($user)->map(function (object $row) use ($currentId) {
            $agent = UserAgent::parse($row->user_agent);

            return [
                'id' => $row->id,
                'label' => $agent['label'],
                'browser' => $agent['browser'],
                'os' => $agent['os'],
                'lastActive' => Carbon::createFromTimestamp($row->last_activity),
                'current' => $row->id === $currentId,
                'via' => Cache::get(self::viaKey($row->id)),
            ];
        })->sortByDesc(fn (array $d) => [$d['current'], $d['lastActive']->getTimestamp()])->values()->all();
    }

    /** Marks how a session was created (shown in the devices list), e.g. "qr". */
    public function markVia(string $sessionId, string $via): void
    {
        Cache::put(self::viaKey($sessionId), $via, now()->addDay());
    }

    /** Signs one of the account's other devices out. False when it isn't that account's session. */
    public function revoke(User $user, string $sessionId, string $reason): bool
    {
        $deleted = $this->table()->where('id', $sessionId)->where('user_id', $user->id)->delete();

        if ($deleted) {
            Cache::put(self::noteKey($sessionId), ['reason' => $reason, 'at' => now()->toIso8601String()], now()->addHours(self::NOTE_TTL_HOURS));
        }

        return (bool) $deleted;
    }

    /**
     * Signs out every device except $currentId. Returns how many LIVE devices that
     * was: the number people see ("logged out on 3 devices") must match the one in
     * the confirmation, which counts live sessions only.
     */
    public function revokeOthers(User $user, string $currentId, string $reason): int
    {
        $live = $this->live($user, $currentId)->pluck('id')->all();
        $revokedLive = 0;

        // Every row of the account, live or not: an expired session still holds a login payload.
        foreach ($this->table()->where('user_id', $user->id)->where('id', '!=', $currentId)->pluck('id') as $id) {
            if ($this->revoke($user, $id, $reason) && in_array($id, $live, true)) {
                $revokedLive++;
            }
        }

        return $revokedLive;
    }

    /** Remember-me cookies are signed with this token, so changing it ends them all. */
    public function rotateRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /** The note left for a revoked session, if any. */
    public function note(string $sessionId): ?array
    {
        return Cache::get(self::noteKey($sessionId));
    }

    public function clearNote(string $sessionId): void
    {
        Cache::forget(self::noteKey($sessionId));
    }

    public static function noteMessage(array $note): string
    {
        return match ($note['reason'] ?? null) {
            'everywhere' => 'You were signed out because you chose to log out everywhere on another device.',
            default => 'You were signed out on this device from another one of your devices.',
        };
    }

    private static function noteKey(string $sessionId): string
    {
        return 'session-revoked:'.$sessionId;
    }

    private static function viaKey(string $sessionId): string
    {
        return 'session-via:'.$sessionId;
    }
}
