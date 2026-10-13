<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * An event broadcast under a custom name (broadcastAs) has to be listened for with a leading dot. Without it Echo
 * listens for App\Events\<Name>, nothing ever matches, and the page silently falls back to polling. That is what
 * happened for every live update until this test existed.
 */
class EchoEventNamesTest extends TestCase
{
    /** @return array<int, array{file: string, channel: string, dot: string, name: string}> */
    private function listeners(): array
    {
        $found = [];

        foreach (['app', 'resources/views'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                if (preg_match_all("/echo:([^,'\"]+),(\.?)(\w+)['\"]/", $file->getContents(), $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $found[] = ['file' => $file->getRelativePathname(), 'channel' => $m[1], 'dot' => $m[2], 'name' => $m[3]];
                    }
                }
            }
        }

        return $found;
    }

    private function broadcastName(string $short): ?string
    {
        $class = "App\\Events\\{$short}";

        if (! class_exists($class)) {
            return null;
        }

        $event = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        return method_exists($event, 'broadcastAs') ? $event->broadcastAs() : '';
    }

    public function test_the_app_listens_for_live_updates_somewhere(): void
    {
        $this->assertGreaterThanOrEqual(4, count($this->listeners()), 'ShowRoom, PartyScreen and the two QR panels');
    }

    public function test_every_listener_names_its_event_the_way_it_is_broadcast(): void
    {
        foreach ($this->listeners() as $listener) {
            $custom = $this->broadcastName($listener['name']);

            $this->assertNotNull($custom, "{$listener['file']} listens for {$listener['name']}, which is not an event of this app");

            if ($custom !== '') {
                $this->assertSame($listener['name'], $custom, "{$listener['file']}: the event is broadcast as {$custom}");
                $this->assertSame('.', $listener['dot'], "{$listener['file']}: {$listener['name']} is broadcast under a custom name, so the listener needs a leading dot (echo:{$listener['channel']},.{$listener['name']})");
            } else {
                $this->assertSame('', $listener['dot'], "{$listener['file']}: {$listener['name']} has no custom name, so no dot");
            }
        }
    }

    public function test_the_checks_would_catch_the_old_mistake(): void
    {
        $this->assertSame('RoomUpdated', $this->broadcastName('RoomUpdated'));
        $this->assertSame('QrLoginUpdated', $this->broadcastName('QrLoginUpdated'));
    }
}
