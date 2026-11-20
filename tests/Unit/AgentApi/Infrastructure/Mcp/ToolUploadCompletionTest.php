<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolUploadCompletion;
use App\Media\Api\CompletedUpload;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadRefused;
use App\Media\Api\UploadTarget;
use App\Media\Api\UploadTargetDenied;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool\ScriptedUploadCompletions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lena's upload finished over ScriptedUploadCompletions, which answers as DirectUploads does. The time is 10 Nov 2026,
 * not today.
 */
final class ToolUploadCompletionTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000d001';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000d002';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000d003';
    private const string UPLOAD = '0197a3c0-0000-7000-8000-00000000d021';
    private const string EXPIRED = 'This upload expired before it was added. Begin a new one with create_notebook_upload.';
    private const string NOW = '2026-11-10 09:00:20';

    #[Test]
    public function aFinishedUploadIsMediasPicture(): void
    {
        $picture = new CompletedUpload(self::UPLOAD, MediaImageStatus::Pending);
        $uploads = self::uploadsCompletingWith($picture);

        $completed = self::completeAs($uploads, self::LENA);

        self::assertSame($picture, $completed);
        self::assertSame([[self::UPLOAD, self::LENA, self::NOW]], $uploads->completions);
    }

    #[Test]
    public function anUploadAnotherPersonBeganIsNotFound(): void
    {
        $uploads = self::uploadsCompletingWith(new CompletedUpload(self::UPLOAD, MediaImageStatus::Ready));

        $refusal = self::refusalOf($uploads, self::OMAR);

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame(ToolRefusal::NOT_FOUND, $refusal->getMessage());
    }

    #[Test]
    public function anUploadWhoseTargetNowRefusesTheCallerIsNotFound(): void
    {
        $uploads = self::uploadsCompletingWith(new UploadTargetDenied('The notebook is no longer yours.'));

        $refusal = self::refusalOf($uploads, self::LENA);

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame(ToolRefusal::NOT_FOUND, $refusal->getMessage());
    }

    #[Test]
    public function anExpiredUploadIsRefusedWithTheToolsOwnMessage(): void
    {
        $uploads = self::uploadsCompletingWith(new UploadRefused('Media\'s own words, never shown.'));

        $refusal = self::refusalOf($uploads, self::LENA);

        self::assertSame(ToolCallOutcome::Refused, $refusal->outcome);
        self::assertSame(self::EXPIRED, $refusal->getMessage());
    }

    private static function uploadsCompletingWith(CompletedUpload|\Throwable $completion): ScriptedUploadCompletions
    {
        $uploads = new ScriptedUploadCompletions();
        $uploads->add(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::UPLOAD, new UploadTarget('notebook', '0197a3c0-0000-7000-8000-00000000d011'), $completion);

        return $uploads;
    }

    private static function completeAs(ScriptedUploadCompletions $uploads, string $callerId): CompletedUpload
    {
        return ToolUploadCompletion::completed($uploads, new OrganizationId(self::ACME_FOODS), new UserId($callerId), self::UPLOAD, new \DateTimeImmutable(self::NOW.' UTC'), self::EXPIRED);
    }

    private static function refusalOf(ScriptedUploadCompletions $uploads, string $callerId): ToolRefusal
    {
        try {
            self::completeAs($uploads, $callerId);
        } catch (ToolRefusal $refusal) {
            return $refusal;
        }
        self::fail('The completion was not refused.');
    }
}
