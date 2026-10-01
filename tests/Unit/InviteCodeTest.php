<?php

namespace Tests\Unit;

use App\Models\Room;
use PHPUnit\Framework\TestCase;

class InviteCodeTest extends TestCase
{
    public function test_codes_are_three_hyphenated_segments_of_unambiguous_characters(): void
    {
        foreach (range(1, 2000) as $_) {
            $code = Room::generateInviteCode();

            $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{6}(-[A-HJKMNP-Z2-9]{6}){2}$/', $code);
            $this->assertDoesNotMatchRegularExpression('/[01OIL]/', $code);
        }
    }

    public function test_codes_do_not_repeat(): void
    {
        $codes = array_map(fn () => Room::generateInviteCode(), range(1, 2000));

        $this->assertCount(2000, array_unique($codes));
    }
}
