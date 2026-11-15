<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application;

use App\Media\Domain\UploadPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The presigned-upload controller refuses a file in the browser with the texts and limits the server uses, so a person
 * reads the same words either way. The server's texts are literals in DirectUploads, so its source is read.
 */
final class UploadMessagesMatchBrowserTest extends TestCase
{
    private const string BROWSER_CHECKS = __DIR__.'/../../../../assets/review/upload_checks.js';
    private const string BROWSER_STATUS = __DIR__.'/../../../../assets/review/upload_status.js';
    private const string SCREENSHOT_TEMPLATE = __DIR__.'/../../../../src/Review/UI/templates/components/Screenshot.html.twig';
    private const string SERVER_UPLOADS = __DIR__.'/../../../../src/Media/Application/DirectUploads.php';

    #[Test]
    public function theBrowserRefusesATypeAndASizeWithTheServersTexts(): void
    {
        $serverSource = (string) file_get_contents(self::SERVER_UPLOADS);

        foreach (['WRONG_TYPE_MESSAGE', 'WRONG_SIZE_MESSAGE'] as $browserConstant) {
            $browserText = $this->browserString($browserConstant);
            self::assertNotSame('', $browserText);
            self::assertStringContainsString(\sprintf("new UploadRefused('%s')", $browserText), $serverSource, $browserConstant.' differs from the server text.');
        }
    }

    #[Test]
    public function theBrowserAllowsTheServersTypesAndLargestSize(): void
    {
        $script = (string) file_get_contents(self::BROWSER_CHECKS);

        self::assertSame(1, preg_match('/ALLOWED_TYPES = \[(.*?)\];/s', $script, $types));
        self::assertSame(UploadPolicy::MEDIA_TYPES, array_map(static fn (string $type): string => trim($type, " '"), explode(',', $types[1])));
        self::assertStringContainsString(\sprintf('MAX_BYTES = %d * 1024 * 1024;', UploadPolicy::MAX_BYTES / (1024 * 1024)), $script);
    }

    #[Test]
    public function theBrowserShowsARejectedPictureWithTheScreenshotComponentsText(): void
    {
        $script = (string) file_get_contents(self::BROWSER_STATUS);
        self::assertSame(1, preg_match("/export const REJECTED_MESSAGE = '([^']*)';/", $script, $found));
        $template = (string) file_get_contents(self::SCREENSHOT_TEMPLATE);

        self::assertNotSame('', $found[1]);
        self::assertStringContainsString('>'.$found[1].'</div>', $template);
    }

    private function browserString(string $constant): string
    {
        $script = (string) file_get_contents(self::BROWSER_CHECKS);
        self::assertSame(1, preg_match('/export const '.$constant." = '([^']*)';/", $script, $found), $constant.' is not in upload_checks.js.');

        return $found[1];
    }
}
