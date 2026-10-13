<?php

namespace Tests\Feature;

use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\Spotify\SpotifyTokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One refresh at a time per login, so the watcher and the web requests cannot knock each other out. */
class SpotifyTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $attributes = []): SpotifyAccount
    {
        return SpotifyAccount::create($attributes + [
            'user_id' => User::factory()->create()->id, 'client_id' => 'id', 'client_secret' => 'secret',
            'access_token' => 'old', 'refresh_token' => 'refresh-1', 'token_expires_at' => now()->subMinute(),
        ]);
    }

    private function tokenCalls(): int
    {
        return Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'accounts.spotify.com'))->count();
    }

    private function manager(int $wait = 0): SpotifyTokenManager
    {
        return tap(new SpotifyTokenManager, fn ($m) => $m->lockWaitSeconds = $wait);
    }

    public function test_an_expired_token_is_refreshed_once(): void
    {
        Http::fake(['accounts.spotify.com/*' => Http::response(['access_token' => 'new', 'expires_in' => 3600, 'refresh_token' => 'refresh-2'])]);
        $account = $this->account();

        $this->manager()->ensureFreshToken($account);

        $this->assertSame('new', $account->access_token);
        $this->assertSame('refresh-2', $account->fresh()->refresh_token);
        $this->assertSame(1, $this->tokenCalls());
    }

    public function test_a_process_holding_a_stale_copy_uses_what_another_one_already_refreshed(): void
    {
        Http::fake(['accounts.spotify.com/*' => Http::response(['access_token' => 'should-not-happen', 'expires_in' => 3600])]);
        $stale = $this->account();

        // Another process refreshed it a moment ago.
        SpotifyAccount::find($stale->id)->update(['access_token' => 'fresh', 'refresh_token' => 'refresh-2', 'token_expires_at' => now()->addHour()]);

        $this->manager()->ensureFreshToken($stale);

        $this->assertSame(0, $this->tokenCalls(), 'a second refresh with the old refresh token is what gets an account flagged');
        $this->assertSame('fresh', $stale->access_token);
        $this->assertNull($stale->needs_reconnect_at);
    }

    public function test_a_valid_token_is_left_alone_without_taking_a_lock(): void
    {
        Http::fake();
        $account = $this->account(['token_expires_at' => now()->addHour()]);
        $lock = Cache::lock("spotify-refresh:{$account->id}", 30);
        $this->assertTrue($lock->get());

        $started = microtime(true);
        $this->manager(2)->ensureFreshToken($account);

        $this->assertSame(0, $this->tokenCalls());
        $this->assertLessThan(0.5, microtime(true) - $started, 'every Spotify call goes through here; it must not wait on a lock it does not need');
        $lock->release();
    }

    public function test_when_the_lock_stays_busy_it_looks_again_and_refreshes_if_still_due(): void
    {
        Http::fake(['accounts.spotify.com/*' => Http::response(['access_token' => 'new', 'expires_in' => 3600])]);
        $account = $this->account();
        $lock = Cache::lock("spotify-refresh:{$account->id}", 30);
        $this->assertTrue($lock->get());

        // The holder never finished (it died): after the wait this one does the work rather than leaving the room without a token.
        $this->manager(0)->ensureFreshToken($account);

        $this->assertSame('new', $account->fresh()->access_token);
        $lock->release();
    }

    public function test_the_lock_is_released_afterwards(): void
    {
        Http::fake(['accounts.spotify.com/*' => Http::response(['access_token' => 'new', 'expires_in' => 3600])]);
        $account = $this->account();

        $this->manager()->ensureFreshToken($account);

        $lock = Cache::lock("spotify-refresh:{$account->id}", 5);
        $this->assertTrue($lock->get(), 'free again');
        $lock->release();
    }

    public function test_a_rejected_login_is_still_flagged_and_retried_only_now_and_then(): void
    {
        Http::fake(['accounts.spotify.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $account = $this->account();

        $this->manager()->ensureFreshToken($account);
        $this->assertNotNull($account->fresh()->needs_reconnect_at);

        $this->manager()->ensureFreshToken($account->fresh());
        $this->assertSame(1, $this->tokenCalls(), 'not retried straight away');
    }
}
