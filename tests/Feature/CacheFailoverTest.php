<?php

namespace Tests\Feature;

use App\Http\Middleware\ShowRevokedSessionNotice;
use App\Models\User;
use App\Services\Auth\QrLogin;
use App\Services\Auth\UserSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Cache service (Redis) is an optimisation, not a dependency: when it cannot be
 * reached the cache falls over to the database, so rate limits, locks, the QR login
 * and the sign-out check keep working, only slower.
 */
class CacheFailoverTest extends TestCase
{
    use RefreshDatabase;

    /** Production's setup, with Redis pointing at a port nothing listens on. */
    private function redisDown(): void
    {
        config([
            'cache.default' => 'failover',
            'database.redis.client' => 'predis',
            'database.redis.cache.host' => '127.0.0.1',
            'database.redis.cache.port' => 1,
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
        ]);

        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance('redis');
        Cache::clearResolvedInstances();
    }

    public function test_the_failover_store_tries_redis_first_and_the_database_second(): void
    {
        $this->assertSame(['redis', 'database'], config('cache.stores.failover.stores'));
    }

    public function test_redis_timeouts_are_short_so_a_dead_cache_cannot_stall_requests(): void
    {
        foreach (['default', 'cache'] as $connection) {
            $this->assertLessThanOrEqual(1.0, (float) config("database.redis.{$connection}.timeout"));
            $this->assertLessThanOrEqual(1.0, (float) config("database.redis.{$connection}.read_write_timeout"));
        }
    }

    public function test_the_app_has_a_redis_client_that_needs_no_php_extension(): void
    {
        $this->assertTrue(class_exists(\Predis\Client::class), 'predis is installed');
        $this->assertStringContainsString('REDIS_CLIENT=predis', file_get_contents(base_path('.env.example')));
    }

    public function test_with_redis_down_the_cache_still_stores_and_returns_values(): void
    {
        $this->redisDown();
        $started = microtime(true);

        Cache::put('failover-probe', ['ok' => true], 60);

        $this->assertSame(['ok' => true], Cache::get('failover-probe'));
        $this->assertTrue(Cache::add('failover-once', 1, 60));
        $this->assertFalse(Cache::add('failover-once', 1, 60));
        $this->assertLessThan(3.0, microtime(true) - $started, 'it failed fast and used the database');
    }

    public function test_with_redis_down_rate_limits_still_count(): void
    {
        $this->redisDown();

        RateLimiter::clear('probe');
        foreach (range(1, 3) as $i) {
            RateLimiter::hit('probe', 60);
        }

        $this->assertSame(3, RateLimiter::attempts('probe'));
        $this->assertTrue(RateLimiter::tooManyAttempts('probe', 3));
    }

    public function test_with_redis_down_the_qr_login_still_runs_end_to_end_with_its_locks(): void
    {
        $this->redisDown();
        $qr = app(QrLogin::class);
        $user = User::factory()->create();

        $request = $qr->start('computer-session-id-0000000000000000001', 'ua');
        $this->assertSame(QrLogin::APPROVED, $qr->approve($user, $request['token'], 'phone-session-id-00000000000000000000001', $request['code']));
        $this->assertSame($user->id, $qr->claim($request['token'], 'computer-session-id-0000000000000000001'));
        $this->assertNull($qr->claim($request['token'], 'computer-session-id-0000000000000000001'), 'still single use');
    }

    public function test_with_redis_down_a_held_lock_still_makes_the_other_caller_wait_then_give_up(): void
    {
        $this->redisDown();
        $qr = new QrLogin(lockWaitSeconds: 0);
        $user = User::factory()->create();
        $request = $qr->start('computer-session-id-0000000000000000001', 'ua');

        // Someone else holds this token's lock in the database (where the fallback takes it).
        $held = Cache::store('database')->lock('qr-login-lock:'.$request['token'], 10);
        $this->assertTrue($held->get());

        $this->assertSame(QrLogin::BUSY, $qr->approve($user, $request['token'], 'phone-session-id-00000000000000000000001', $request['code']));

        $held->release();
    }

    public function test_a_broken_cache_never_takes_a_page_down_through_the_sign_out_check(): void
    {
        $this->mock(UserSessions::class, fn ($mock) => $mock->shouldReceive('note')->andThrow(new \RuntimeException('cache is down')));

        $store = app('session')->driver();
        $store->setId(Str::random(40));
        $request = Request::create('/x', 'GET');
        $request->setLaravelSession($store);

        $response = app(ShowRevokedSessionNotice::class)->handle($request, fn () => response('page'));

        $this->assertSame('page', $response->getContent());
    }
}
