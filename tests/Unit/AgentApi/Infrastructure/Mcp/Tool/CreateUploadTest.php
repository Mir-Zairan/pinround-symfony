<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\CreateUpload;
use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\CurrentUser;
use App\Media\Api\MediaPermission;
use App\Media\Api\UploadRefused;
use App\Media\Api\UploadTarget;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * create_upload over fakes of Review's, Ticketing's and Media's Api: its input schema as the gate's SchemaValidator
 * reads it, the target and voter attribute with and without a draft, the draft it starts, the slot it answers, Media's
 * refusals as its own, and the begin limiter. Omar owns an open unshared draft on SHOP-1 and none on SHOP-2, where Mia
 * owns one.
 */
final class CreateUploadTest extends TestCase
{
    private const string OMARS_DRAFT = '019a5f00-0000-7000-8000-00000000e741';
    private const string MIAS_DRAFT = '019a5f00-0000-7000-8000-00000000e742';
    private const string SHA256 = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
    private const string NOW = '2026-11-10 09:00:20 UTC';
    private const int BEGINS_PER_MINUTE = 3;

    private CreateUploadFakes $fakes;
    private RateLimiterFactory $beginsPerUser;

    protected function setUp(): void
    {
        $this->fakes = new CreateUploadFakes();
        $this->fakes->openDrafts = [
            self::OMARS_DRAFT => [CreateUploadFakes::SHOP_1, CreateUploadFakes::OMAR],
            self::MIAS_DRAFT => [CreateUploadFakes::SHOP_2, CreateUploadFakes::MIA],
        ];
        $this->beginsPerUser = new RateLimiterFactory(['id' => 'media_upload_begin_per_user', 'policy' => 'fixed_window', 'limit' => self::BEGINS_PER_MINUTE, 'interval' => '1 minute'], new InMemoryStorage());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validArguments(): iterable
    {
        yield 'a PNG' => [self::arguments()];
        yield 'an uppercase checksum' => [self::arguments(['sha256' => strtoupper(self::SHA256)])];
        // Uploads refuses these with the web's plain messages; the schema lets them through to it.
        yield 'a GIF' => [self::arguments(['mime' => 'image/gif'])];
        yield 'an empty file' => [self::arguments(['bytes' => 0])];
        yield 'a file of 16 MB' => [self::arguments(['bytes' => 16 * 1024 * 1024])];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('validArguments')]
    public function theInputSchemaLetsTheTypeAndSizeThroughToUploads(array $arguments): void
    {
        self::assertSame([], $this->problemsOf($arguments));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidArguments(): iterable
    {
        $withoutChecksum = self::arguments();
        unset($withoutChecksum['sha256']);
        yield 'no checksum' => [$withoutChecksum];
        yield 'a checksum one digit short' => [self::arguments(['sha256' => substr(self::SHA256, 1)])];
        yield 'a checksum with a trailing newline' => [self::arguments(['sha256' => self::SHA256."\n"])];
        yield 'a checksum that is not hex' => [self::arguments(['sha256' => str_repeat('g', 64)])];
        yield 'a size as text' => [self::arguments(['bytes' => '245760'])];
        yield 'a size with a fraction' => [self::arguments(['bytes' => 1.5])];
        yield 'base64 content' => [self::arguments(['content' => 'iVBORw0KGgo='])];
        yield 'a key past the cap' => [self::arguments(['key' => str_repeat('A', 65)])];
        yield 'a type past the cap' => [self::arguments(['mime' => str_repeat('a', 101)])];
        yield 'an empty type' => [self::arguments(['mime' => ''])];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('invalidArguments')]
    public function aMissingOrMalformedFieldFailsTheInputSchema(array $arguments): void
    {
        self::assertNotSame([], $this->problemsOf($arguments));
    }

    #[Test]
    public function itNeedsTheRoundsWriteScopeAndTellsTheAgentToPutTheFileWithCurl(): void
    {
        $definition = $this->createUpload()->definition();

        self::assertSame('rounds:write', $this->createUpload()->requiredScope());
        self::assertSame(CreateUpload::NAME, $definition->name);
        self::assertStringContainsString('curl -T <file> -H "Content-Type: image/png" "<put_url>"', (string) $definition->description);
    }

    #[Test]
    public function theCallersOwnDraftIsGuardedByMediaUploadOnItAndAuditedAsTheRound(): void
    {
        $target = $this->createUpload()->targetOf(self::arguments());

        self::assertSame(MediaPermission::UPLOAD, $target->voterAttribute);
        self::assertEquals(new UploadTarget('round', self::OMARS_DRAFT), $target->subject);
        self::assertSame([CreateUpload::ROUND_AUDIT_TYPE, self::OMARS_DRAFT], [$target->auditType, $target->auditId]);
        self::assertSame([], $this->fakes->draftsStarted);
        self::assertCount(1, $this->fakes->ticketViewsAsked);
        self::assertEquals(new OrganizationId(CreateUploadFakes::ACME_FOODS), $this->fakes->ticketViewsAsked[0]->organizationId);
        self::assertEquals(new UserId(CreateUploadFakes::OMAR), $this->fakes->ticketViewsAsked[0]->assigneeId);
    }

    /**
     * The owner's facts grant MediaPermission UPLOAD on the draft without the ticket being read, so a ticket Omar may
     * no longer see, or one Ticketing does not read, is not found before any voter is asked or anything begins.
     *
     * @return iterable<string, array{bool, bool}>
     */
    public static function ticketsHiddenFromTheDraftsOwner(): iterable
    {
        yield 'a ticket TicketPermission VIEW denies' => [true, false];
        yield 'a ticket Ticketing does not read' => [false, true];
    }

    #[Test]
    #[DataProvider('ticketsHiddenFromTheDraftsOwner')]
    public function theOwnersDraftOnATicketTheyMayNotSeeIsNotFoundAndNothingBegins(bool $isTicketHidden, bool $isTicketUnreadable): void
    {
        $this->fakes->isTicketHidden = $isTicketHidden;
        $this->fakes->isTicketUnreadable = $isTicketUnreadable;

        $refusal = self::refusalOf(fn () => $this->callFor(self::arguments()));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame(ToolRefusal::NOT_FOUND, $refusal->getMessage());
        self::assertSame([], $this->fakes->begins);
        self::assertSame([], $this->fakes->draftsStarted);
    }

    /**
     * Mia's draft on SHOP-2 is never Omar's target, so the voter asked is whether he may start one of his own; that
     * voter grants the assignee alone, so the ticket is not asked for TicketPermission VIEW here.
     */
    #[Test]
    public function withoutADraftOfTheCallersOwnTheTicketIsGuardedByDraftCreate(): void
    {
        $target = $this->createUpload()->targetOf(self::arguments(['key' => 'SHOP-2']));

        self::assertSame(RoundPermission::DRAFT_CREATE, $target->voterAttribute);
        self::assertEquals(new TicketId(CreateUploadFakes::SHOP_2), $target->subject);
        self::assertSame([CreateUpload::TICKET_AUDIT_TYPE, CreateUploadFakes::SHOP_2], [$target->auditType, $target->auditId]);
        self::assertSame([], $this->fakes->draftsStarted, 'targetOf writes nothing');
        self::assertSame([], $this->fakes->ticketViewsAsked);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysNotFound(): iterable
    {
        yield 'an unknown key' => ['SHOP-9'];
        yield 'a lowercase key' => ['shop-1'];
    }

    #[Test]
    #[DataProvider('keysNotFound')]
    public function aKeyTheOrganizationHasNoTicketWithIsNotFound(string $key): void
    {
        $refusal = self::refusalOf(fn () => $this->createUpload()->targetOf(self::arguments(['key' => $key])));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame(ToolRefusal::NOT_FOUND, $refusal->getMessage());
    }

    #[Test]
    public function anUploadIntoTheCallersDraftBeginsOnceAndAnswersWhereToPutTheFile(): void
    {
        $result = $this->callFor(self::arguments(['mime' => 'image/webp', 'bytes' => 245_760]));

        self::assertSame([[CreateUploadFakes::ACME_FOODS, CreateUploadFakes::OMAR, 'round', self::OMARS_DRAFT, 'screenshot', 'image/webp', 245_760, '2026-11-10 09:00:20']], $this->fakes->begins);
        $answer = [
            'upload_id' => CreateUploadFakes::INTENT,
            'put_url' => CreateUploadFakes::PUT_URL,
            'headers' => ['Content-Type' => 'image/webp', 'Content-Length' => '245760'],
            'expires_at' => '2026-11-10T09:05:20+00:00',
        ];
        self::assertSame($answer, $result['structured']);
        self::assertSame(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), $result['text']);
        self::assertSame([], $this->fakes->draftsStarted);
    }

    #[Test]
    public function withoutADraftTheCallerStartsOneAndTheUploadTargetsIt(): void
    {
        $result = $this->callFor(self::arguments(['key' => 'SHOP-2']));

        self::assertSame([[CreateUploadFakes::SHOP_2, CreateUploadFakes::OMAR, '2026-11-10 09:00:20']], $this->fakes->draftsStarted);
        self::assertSame([[CreateUploadFakes::ACME_FOODS, CreateUploadFakes::OMAR, 'round', CreateUploadFakes::STARTED_DRAFT, 'screenshot', 'image/png', 245_760, '2026-11-10 09:00:20']], $this->fakes->begins);
        self::assertSame(CreateUploadFakes::INTENT, $result['structured']['upload_id'] ?? null);
    }

    #[Test]
    public function aNewDraftTheUploadVoterDeniesIsNotFoundAndNothingBegins(): void
    {
        $this->fakes->deniesEveryUploadTarget = true;

        $refusal = self::refusalOf(fn () => $this->callFor(self::arguments(['key' => 'SHOP-2'])));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame([], $this->fakes->begins);
    }

    /**
     * Another request started Omar's draft between targetOf and call: Review's refusal goes on as a failure, which the
     * gate rolls back.
     */
    #[Test]
    public function aDraftStartedMeanwhileGoesOnAsAFailure(): void
    {
        $tool = $this->createUpload();
        $arguments = self::arguments(['key' => 'SHOP-2']);
        $target = $tool->targetOf($arguments);
        $this->fakes->openDrafts['019a5f00-0000-7000-8000-00000000e743'] = [CreateUploadFakes::SHOP_2, CreateUploadFakes::OMAR];

        $this->expectExceptionObject(new RoundRefused(RoundRefused::DRAFT_EXISTS));

        $tool->call($arguments, $target);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function filesUploadsRefuses(): iterable
    {
        yield 'a GIF' => [['mime' => 'image/gif'], 'This file is not a PNG, JPEG or WebP screenshot.'];
        yield 'a PNG type in capitals' => [['mime' => 'IMAGE/PNG'], 'This file is not a PNG, JPEG or WebP screenshot.'];
        yield 'an empty file' => [['bytes' => 0], 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.'];
        yield 'a file one byte over 15 MB' => [['bytes' => 15 * 1024 * 1024 + 1], 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('filesUploadsRefuses')]
    public function aFileUploadsRefusesIsRefusedWithItsPlainMessage(array $fields, string $message): void
    {
        $refusal = self::refusalOf(fn () => $this->callFor(self::arguments($fields)));

        self::assertSame(ToolCallOutcome::Refused, $refusal->outcome);
        self::assertSame($message, $refusal->getMessage());
        self::assertSame([], $this->fakes->begins);
    }

    #[Test]
    public function aFullStorageIsRefusedWithItsPlainMessage(): void
    {
        $this->fakes->refusal = new UploadRefused("Your company's storage is full. Ask your admin.");

        $refusal = self::refusalOf(fn () => $this->callFor(self::arguments()));

        self::assertSame(ToolCallOutcome::Refused, $refusal->outcome);
        self::assertSame("Your company's storage is full. Ask your admin.", $refusal->getMessage());
    }

    #[Test]
    public function aTargetUploadsDeniesIsNotFound(): void
    {
        $this->fakes->deniesInsideUploads = true;

        $refusal = self::refusalOf(fn () => $this->callFor(self::arguments()));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame([], $this->fakes->begins);
    }

    /**
     * A refused begin spends one too, as on the web, so the call after the limit is held back before Uploads is asked.
     */
    #[Test]
    public function aBeginPastTheCallersLimitIsRefusedWithTheWebsMessageAndBeginsNothing(): void
    {
        $this->callFor(self::arguments());
        self::refusalOf(fn () => $this->callFor(self::arguments(['mime' => 'image/gif'])));
        $this->callFor(self::arguments());
        $beginsBefore = $this->fakes->begins;
        self::assertCount(2, $beginsBefore);

        $refusal = self::refusalOf(fn () => $this->callFor(self::arguments()));

        self::assertSame(ToolCallOutcome::Refused, $refusal->outcome);
        self::assertSame(CreateUpload::ADDING_TOO_FAST, $refusal->getMessage());
        self::assertSame($beginsBefore, $this->fakes->begins);
    }

    #[Test]
    public function aBeginLimitIsKeptPerPerson(): void
    {
        for ($begin = 0; $begin < self::BEGINS_PER_MINUTE; ++$begin) {
            $this->callFor(self::arguments());
        }
        $this->fakes->caller = new UserId(CreateUploadFakes::MIA);

        $this->callFor(self::arguments(['key' => 'SHOP-2']));

        self::assertCount(self::BEGINS_PER_MINUTE + 1, $this->fakes->begins);
        self::assertSame([CreateUploadFakes::MIA, self::MIAS_DRAFT], [$this->fakes->begins[self::BEGINS_PER_MINUTE][1], $this->fakes->begins[self::BEGINS_PER_MINUTE][3]]);
    }

    #[Test]
    public function aTargetOfAnotherKindIsAFailure(): void
    {
        $this->expectExceptionObject(new \LogicException('create_upload ran without a target of its own.'));

        $this->createUpload()->call(self::arguments(), new ToolTarget('ROUND_VIEW', 'a round', 'round', self::OMARS_DRAFT));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function arguments(array $overrides = []): array
    {
        return [...['key' => 'SHOP-1', 'mime' => 'image/png', 'bytes' => 245_760, 'sha256' => self::SHA256], ...$overrides];
    }

    /**
     * Grants nothing itself: targetOf and the voter the gate asks are left out, and call() runs on targetOf's answer.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{structured: array<mixed>, text: string}
     */
    private function callFor(array $arguments): array
    {
        $tool = $this->createUpload();
        $result = $tool->call($arguments, $tool->targetOf($arguments));
        self::assertFalse($result->isError);
        $structured = $result->structuredContent;
        self::assertIsArray($structured);
        $content = $result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $content);
        $text = $content->text;
        self::assertIsString($text);

        return ['structured' => $structured, 'text' => $text];
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function refusalOf(\Closure $call): ToolRefusal
    {
        try {
            $call();
        } catch (ToolRefusal $refusal) {
            return $refusal;
        }
        self::fail('The call was not refused.');
    }

    /**
     * Through the gate's own validator, on the arguments as a JSON object decodes.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<array{pointer: string, keyword: string, message: string}>
     */
    private function problemsOf(array $arguments): array
    {
        return new SchemaValidator()->validateAgainstJsonSchema(
            json_decode(json_encode($arguments, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), false, flags: \JSON_THROW_ON_ERROR),
            $this->createUpload()->definition()->inputSchema,
        );
    }

    private function createUpload(): CreateUpload
    {
        $fakes = $this->fakes;

        return new CreateUpload(
            $fakes,
            new class($fakes) implements CurrentUser {
                public function __construct(private readonly CreateUploadFakes $fakes)
                {
                }

                public function id(): UserId
                {
                    return $this->fakes->caller;
                }

                public function idOf(mixed $tokenUser): ?UserId
                {
                    return null;
                }
            },
            $fakes,
            $fakes,
            $fakes,
            $fakes,
            $fakes,
            $fakes,
            $this->beginsPerUser,
            new MockClock(self::NOW),
        );
    }
}
