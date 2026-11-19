<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\AddNotebookScreenshot;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Media\Api\CompletedUpload;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadTarget;
use App\Review\Api\NotebookView;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Domain\Notebook\NotebookRefused as NotebookRule;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * add_notebook_screenshot over ScriptedNotebooks and ScriptedUploadCompletions: the answer is what Notebooks::addScreenshot
 * read and saved over the version it read, not what get() read before. Lena's notebook shows one picture when get()
 * reads it; another request may add more before the add reads it again. The clock is frozen on 10 Nov 2026, not today.
 */
final class AddNotebookScreenshotTest extends TestCase
{
    /** Public, for the anonymous fakes. */
    public const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000c001';
    public const string LENA = '0197a3c0-0000-7000-8000-00000000c002';
    private const string NOTEBOOK = '0197a3c0-0000-7000-8000-00000000c003';
    private const string FIRST_PICTURE = '0197a3c0-0000-7000-8000-00000000c011';
    private const string UPLOAD = '0197a3c0-0000-7000-8000-00000000c021';
    private const string NOW = '2026-11-10 09:00:20 UTC';

    private ScriptedUploadCompletions $uploads;

    protected function setUp(): void
    {
        $this->uploads = new ScriptedUploadCompletions();
        $this->uploads->add(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::UPLOAD, new UploadTarget('notebook', self::NOTEBOOK), new CompletedUpload(self::UPLOAD, MediaImageStatus::Ready));
    }

    /**
     * get() shows one picture; when the add reads it the notebook holds three, so the add makes four, not two.
     */
    #[Test]
    public function theCountIsTheOneNotebooksSavedNotTheOneGetRead(): void
    {
        $notebooks = new ScriptedNotebooks(self::notebookWithOnePicture(), mediaIdsAtAdd: [self::FIRST_PICTURE, '0197a3c0-0000-7000-8000-00000000c012', '0197a3c0-0000-7000-8000-00000000c013']);

        $answer = $this->callWith($notebooks);

        self::assertSame(['notebook_id' => self::NOTEBOOK, 'media_id' => self::UPLOAD, 'status' => AddNotebookScreenshot::ADDED, 'screenshot_count' => 4], $answer);
        self::assertEquals([[self::NOTEBOOK, new ScreenshotWithPins(self::UPLOAD), new \DateTimeImmutable(self::NOW)]], $notebooks->added);
    }

    /**
     * get() shows room for the picture; before the add read it, another request filled the notebook.
     */
    #[Test]
    public function aNotebookFilledSinceGetReadItIsRefusedWithReviewsTextAndNothingIsAdded(): void
    {
        $tenPictures = array_map(static fn (int $number): string => \sprintf('0197a3c0-0000-7000-8000-0000000c01%02d', $number), range(1, 10));
        $notebooks = new ScriptedNotebooks(self::notebookWithOnePicture(), mediaIdsAtAdd: $tenPictures);

        $refusal = $this->refusalOf($notebooks);

        self::assertSame(NotebookRule::TOO_MANY_SCREENSHOTS, $refusal->getMessage());
        self::assertSame([], $notebooks->added);
    }

    /**
     * The call sent again, as after a lost answer: the picture is in the notebook already, as the tenth of ten, so the
     * answer is already_in_notebook with the count the add read, not the cap's refusal, and nothing is added.
     */
    #[Test]
    public function thePictureSentAgainIsAlreadyInTheFullNotebookAndNothingIsAdded(): void
    {
        $ninePictures = array_map(static fn (int $number): string => \sprintf('0197a3c0-0000-7000-8000-0000000c01%02d', $number), range(1, 9));
        $notebooks = new ScriptedNotebooks(self::notebookWithOnePicture(), mediaIdsAtAdd: [...$ninePictures, self::UPLOAD]);

        $answer = $this->callWith($notebooks);

        self::assertSame(['notebook_id' => self::NOTEBOOK, 'media_id' => self::UPLOAD, 'status' => AddNotebookScreenshot::ALREADY_IN_NOTEBOOK, 'screenshot_count' => 10], $answer);
        self::assertSame([], $notebooks->added);
    }

    #[Test]
    public function theToolSaysARepeatedCallChangesNothing(): void
    {
        $annotations = $this->tool(new ScriptedNotebooks(null))->definition()->annotations;

        self::assertSame([true, false], [$annotations?->idempotentHint, $annotations?->readOnlyHint]);
    }

    /**
     * @return array<mixed> the structured answer
     */
    private function callWith(ScriptedNotebooks $notebooks): array
    {
        $tool = $this->tool($notebooks);
        $arguments = ['notebook' => self::NOTEBOOK, 'upload_id' => self::UPLOAD];
        $result = $tool->call($arguments, $tool->targetOf($arguments));
        self::assertFalse($result->isError);
        $structured = $result->structuredContent;
        self::assertIsArray($structured);

        return $structured;
    }

    private function refusalOf(ScriptedNotebooks $notebooks): ToolRefusal
    {
        $tool = $this->tool($notebooks);
        $arguments = ['notebook' => self::NOTEBOOK, 'upload_id' => self::UPLOAD];
        $target = $tool->targetOf($arguments);
        try {
            $tool->call($arguments, $target);
        } catch (ToolRefusal $refusal) {
            return $refusal;
        }
        self::fail('The call was not refused.');
    }

    private function tool(ScriptedNotebooks $notebooks): AddNotebookScreenshot
    {
        return new AddNotebookScreenshot(
            new class implements CurrentApiToken {
                public function access(): ApiTokenAccess
                {
                    return new ApiTokenAccess('0197a3c0-0000-7000-8000-00000000c031', new OrganizationId(AddNotebookScreenshotTest::ACME_FOODS), ['notebooks:write'], new \DateTimeImmutable('2027-02-08 09:00:00 UTC'));
                }
            },
            new class implements CurrentUser {
                public function id(): UserId
                {
                    return new UserId(AddNotebookScreenshotTest::LENA);
                }

                public function idOf(mixed $tokenUser): ?UserId
                {
                    return null;
                }
            },
            $notebooks,
            $this->uploads,
            new MockClock(self::NOW),
        );
    }

    private static function notebookWithOnePicture(): NotebookView
    {
        $at = new \DateTimeImmutable('2026-11-05 08:00:00 UTC');

        return new NotebookView(self::NOTEBOOK, new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), 'Coupon questions', [new ScreenshotWithPins(self::FIRST_PICTURE)], [], 2, $at, $at, $at);
    }
}
