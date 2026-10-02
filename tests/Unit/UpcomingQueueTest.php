<?php

namespace Tests\Unit;

use App\Services\Spotify\UpcomingQueue;
use PHPUnit\Framework\TestCase;

/**
 * Invented tracks only (a, b, c ...): this repo is public, so no real
 * Spotify responses live in tests, just the shapes they really have.
 */
class UpcomingQueueTest extends TestCase
{
    /** @param array<int, string> $ids */
    private function queue(array $ids): array
    {
        return array_map(fn (string $id) => [
            'id' => $id, 'uri' => "spotify:track:{$id}", 'name' => "Track {$id}",
            'artist' => 'Nobody', 'album_art_url' => null, 'duration_ms' => 180000,
        ], $ids);
    }

    private function ids(array $tracks): array
    {
        return array_column($tracks, 'id');
    }

    public function test_on_the_last_track_with_repeat_off_nothing_is_coming_up(): void
    {
        // Spotify pads with the wrapped playlist, current track included.
        $raw = $this->queue(['a', 'b', 'c', 'a', 'b', 'c', 'a', 'b', 'c']);

        $this->assertSame([], UpcomingQueue::trim($raw, 'c', 'off', ['a', 'b', 'c'], []));
    }

    public function test_mid_playlist_only_the_tracks_after_it_are_shown(): void
    {
        $raw = $this->queue(['c', 'd', 'a', 'b', 'c', 'd', 'a', 'b']);

        $this->assertSame(['c', 'd'], $this->ids(UpcomingQueue::trim($raw, 'b', 'off', ['a', 'b', 'c', 'd'], [])));
    }

    public function test_on_the_first_track_everything_after_it_is_shown(): void
    {
        $raw = $this->queue(['b', 'c', 'a', 'b', 'c']);

        $this->assertSame(['b', 'c'], $this->ids(UpcomingQueue::trim($raw, 'a', 'off', ['a', 'b', 'c'], [])));
    }

    public function test_a_long_playlist_is_cut_by_its_real_length_not_by_the_padding(): void
    {
        $order = array_map(fn (int $n) => "t{$n}", range(1, 30));
        // On track 25: five real tracks, then Spotify wraps to the start.
        $raw = $this->queue([...array_slice($order, 25, 5), ...array_slice($order, 0, 15)]);

        $this->assertSame(['t26', 't27', 't28', 't29', 't30'], $this->ids(UpcomingQueue::trim($raw, 't25', 'off', $order, [])));
    }

    public function test_with_repeat_all_the_wrap_is_real_up_to_the_current_track(): void
    {
        $last = $this->queue(['a', 'b', 'c', 'a', 'b', 'c']);
        $this->assertSame(['a', 'b'], $this->ids(UpcomingQueue::trim($last, 'c', 'context', ['a', 'b', 'c'], [])));

        $mid = $this->queue(['c', 'a', 'b', 'c', 'a', 'b']);
        $this->assertSame(['c', 'a'], $this->ids(UpcomingQueue::trim($mid, 'b', 'context', ['a', 'b', 'c'], [])));
    }

    public function test_with_repeat_track_only_guest_queued_tracks_are_listed(): void
    {
        $raw = $this->queue(['q', 'a', 'a', 'a', 'a']);

        $this->assertSame(['q'], $this->ids(UpcomingQueue::trim($raw, 'a', 'track', ['a', 'b'], ['q'])));
        $this->assertSame([], UpcomingQueue::trim($this->queue(['a', 'a', 'a']), 'a', 'track', null, []));
    }

    public function test_guest_queued_tracks_lead_and_are_flagged_and_the_playlist_tail_is_still_trimmed(): void
    {
        $raw = $this->queue(['x', 'c', 'd', 'a', 'b', 'c', 'd']);

        $result = UpcomingQueue::trim($raw, 'b', 'off', ['a', 'b', 'c', 'd'], ['x']);

        $this->assertSame(['x', 'c', 'd'], $this->ids($result));
        $this->assertSame([true, false, false], array_column($result, 'is_queued'));
    }

    public function test_a_guest_queued_track_that_is_also_in_the_playlist_does_not_hide_the_real_tail(): void
    {
        // d is queued by a guest AND is the playlist's last track.
        $raw = $this->queue(['d', 'b', 'c', 'd', 'a', 'b']);

        $result = UpcomingQueue::trim($raw, 'a', 'off', ['a', 'b', 'c', 'd'], ['d']);

        $this->assertSame(['d', 'b', 'c', 'd'], $this->ids($result));
        $this->assertSame([true, false, false, false], array_column($result, 'is_queued'));
    }

    public function test_when_the_order_is_unreadable_the_list_stops_where_the_current_track_comes_round_again(): void
    {
        $raw = $this->queue(['b', 'c', 'a', 'b', 'c', 'a']);

        $this->assertSame(['b', 'c'], $this->ids(UpcomingQueue::trim($raw, 'a', 'off', null, [])));
    }

    public function test_a_queue_that_never_wraps_is_left_alone(): void
    {
        $raw = $this->queue(['b', 'c', 'd']);

        $this->assertSame(['b', 'c', 'd'], $this->ids(UpcomingQueue::trim($raw, 'a', 'off', null, [])));
    }
}
