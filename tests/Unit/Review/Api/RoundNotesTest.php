<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Api;

use App\Review\Api\PinNote;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundNotes;
use App\Review\Api\ScreenshotNotes;
use PHPUnit\Framework\TestCase;

/**
 * RoundNotes is the shape P09's get_round_notes reads, so its JSON is pinned here: a rename of a key or a change in how
 * a coordinate is written breaks this test before it breaks a tool.
 */
final class RoundNotesTest extends TestCase
{
    public function testItEncodesToTheExactJsonOfTwoPicturesWithPins(): void
    {
        $notes = new RoundNotes('round-1', [
            new ScreenshotNotes('shot-1', 1, 'Banner', null, RoundCommands::VERDICT_NOT_RIGHT_YET, [
                new PinNote(1, 10.0, 33.33, 'Covers the total'),
                new PinNote(3, 99.5, 0.0, ''),
            ], 'media-1'),
            new ScreenshotNotes('shot-2', 2, null, 'Second view', null, [], 'media-2'),
        ]);

        $json = json_encode($notes, \JSON_THROW_ON_ERROR);

        self::assertSame(
            '{"roundId":"round-1","screenshots":['
            .'{"screenshotId":"shot-1","position":1,"title":"Banner","summary":null,"verdict":"not_right","pins":['
            .'{"number":1,"xPercent":10,"yPercent":33.33,"note":"Covers the total"},'
            .'{"number":3,"xPercent":99.5,"yPercent":0,"note":""}],"mediaId":"media-1"},'
            .'{"screenshotId":"shot-2","position":2,"title":null,"summary":"Second view","verdict":null,"pins":[],"mediaId":"media-2"}]}',
            $json,
        );
    }

    public function testADraftWithNoPictureEncodesAnEmptyListNotAnObject(): void
    {
        self::assertSame('{"roundId":"round-1","screenshots":[]}', json_encode(new RoundNotes('round-1', []), \JSON_THROW_ON_ERROR));
    }
}
