<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Component;

use App\Review\UI\Component\ScreenshotReportField;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The screenshot-report controller rewrites the line under the pins with the texts ScreenshotReportField prints, so a
 * person reads the same words before and after adding a pin.
 */
final class PinCountTextMatchesBrowserTest extends TestCase
{
    private const string BROWSER_PIN_COUNT = __DIR__.'/../../../../../assets/review/pin_count.js';

    #[Test]
    public function theBrowserCountsPinsWithTheComponentsTexts(): void
    {
        self::assertSame(ScreenshotReportField::PINS_NONE, $this->browserString('PINS_NONE'));
        self::assertSame(ScreenshotReportField::PINS_BECOME_CHECKS, $this->browserString('PINS_BECOME_CHECKS'));
    }

    private function browserString(string $constant): string
    {
        $script = (string) file_get_contents(self::BROWSER_PIN_COUNT);
        self::assertSame(1, preg_match('/export const '.$constant." = '([^']*)';/", $script, $found), $constant.' is not in pin_count.js.');

        return $found[1];
    }
}
