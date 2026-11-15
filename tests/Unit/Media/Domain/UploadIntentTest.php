<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadIntentUnusable;
use App\Media\Domain\UploadPurpose;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class UploadIntentTest extends TestCase
{
    private const string INTENT = '0192f3a0-aaaa-7bbb-8ccc-ddddeeeeffff';
    private const string ORGANIZATION = '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f01';
    private const string USER = '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f02';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-03-02T10:00:00Z');
    }

    #[Test]
    public function anOpenedIntentCarriesWhatItWasOpenedWithAndIsNotUsed(): void
    {
        $expiresAt = $this->clock->now()->modify('+15 minutes');
        $intent = $this->open(allowedMimeTypes: ['image/png', 'image/jpeg', 'image/png'], expiresAt: $expiresAt);

        self::assertSame(self::INTENT, $intent->id()->toString());
        self::assertSame(self::ORGANIZATION, $intent->organizationId()->toString());
        self::assertSame(self::USER, $intent->userId()->toString());
        self::assertSame(['ticket', 'SHOP-12'], [$intent->targetKind(), $intent->targetId()]);
        self::assertSame(1000, $intent->maxBytes());
        self::assertSame(['image/png', 'image/jpeg'], $intent->allowedMimeTypes());
        self::assertSame('uploads/org/intent', $intent->storageKey());
        self::assertSame($expiresAt, $intent->expiresAt());
        self::assertNull($intent->usedAt());
    }

    #[Test]
    public function aTargetAtTheLengthBoundsIsTakenCountedInCharacters(): void
    {
        $longestKind = str_repeat('é', UploadIntent::MAX_TARGET_KIND_LENGTH);
        $longestId = str_repeat('é', UploadIntent::MAX_TARGET_ID_LENGTH);

        $longest = $this->open(targetKind: $longestKind, targetId: $longestId);
        $shortest = $this->open(targetKind: 't', targetId: '1');

        self::assertSame([$longestKind, $longestId], [$longest->targetKind(), $longest->targetId()]);
        self::assertSame(['t', '1'], [$shortest->targetKind(), $shortest->targetId()]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function targetsOutOfBounds(): iterable
    {
        $kindRefusal = 'An upload intent needs a target kind of 1 to 40 characters.';
        $idRefusal = 'An upload intent needs a target id of 1 to 100 characters.';

        yield 'an empty kind' => ['', 'SHOP-12', $kindRefusal];
        yield 'a kind a character too long' => [str_repeat('é', 41), 'SHOP-12', $kindRefusal];
        yield 'an empty id' => ['ticket', '', $idRefusal];
        yield 'an id a character too long' => ['ticket', str_repeat('é', 101), $idRefusal];
    }

    #[Test]
    #[DataProvider('targetsOutOfBounds')]
    public function aTargetOutOfBoundsIsRefused(string $targetKind, string $targetId, string $refusal): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($refusal);

        $this->open(targetKind: $targetKind, targetId: $targetId);
    }

    #[Test]
    public function aSlotWithoutRoomForOneByteIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An upload intent needs a maximum size of at least one byte.');

        $this->open(maxBytes: 0);
    }

    #[Test]
    public function aSlotWithoutAllowedTypesOrWithAnEmptyTypeIsRefused(): void
    {
        foreach ([[], ['image/png', '']] as $types) {
            try {
                $this->open(allowedMimeTypes: $types);
                self::fail('Not refused: '.json_encode($types));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('An upload intent needs at least one allowed media type.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function aSlotWithoutStorageKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An upload intent needs a storage key.');

        $this->open(storageKey: '');
    }

    #[Test]
    public function aFileThatFitsTheSlotIsAccepted(): void
    {
        $intent = $this->open();

        $intent->checkFile(1, 'image/png');
        $intent->checkFile(1000, 'image/jpeg');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function aFileOfZeroOrNegativeSizeIsRefused(): void
    {
        $intent = $this->open();

        foreach ([0, -5] as $bytes) {
            $this->assertCheckFileRefused($intent, $bytes, 'image/png', 'An upload needs a size of at least one byte.');
        }
    }

    #[Test]
    public function aFileOverTheMaximumSizeIsRefused(): void
    {
        $this->assertCheckFileRefused($this->open(), 1001, 'image/png', 'This upload may be at most 1000 bytes.');
    }

    #[Test]
    public function aMediaTypeThatIsNotListedIsRefusedWithTheListedOnes(): void
    {
        $intent = $this->open();

        foreach (['image/gif', 'IMAGE/PNG', 'image/png; charset=binary', ''] as $mime) {
            $this->assertCheckFileRefused($intent, 10, $mime, 'This upload must be one of: image/png, image/jpeg.');
        }
    }

    #[Test]
    public function aRefusedFileLeavesTheIntentUnchanged(): void
    {
        $intent = $this->open();

        $this->assertCheckFileRefused($intent, 1001, 'image/gif', 'This upload may be at most 1000 bytes.');

        self::assertNull($intent->usedAt());
        self::assertSame(1000, $intent->maxBytes());
        self::assertSame(['image/png', 'image/jpeg'], $intent->allowedMimeTypes());
        $intent->markUsed($this->clock->now());
        self::assertEquals($this->clock->now(), $intent->usedAt());
    }

    #[Test]
    public function markingUsedRecordsWhenBeforeItExpires(): void
    {
        $intent = $this->open(expiresAt: $this->clock->now()->modify('+15 minutes'));
        $at = $this->clock->now()->modify('+14 minutes +59 seconds');

        $intent->markUsed($at);

        self::assertSame($at, $intent->usedAt());
    }

    #[Test]
    public function anIntentUsedAlreadyCannotBeUsedAgainAndKeepsItsFirstUse(): void
    {
        $intent = $this->open(expiresAt: $this->clock->now()->modify('+15 minutes'));
        $firstUse = $this->clock->now();
        $intent->markUsed($firstUse);

        try {
            $intent->markUsed($this->clock->now()->modify('+1 minute'));
            self::fail('Not refused.');
        } catch (UploadIntentUnusable $exception) {
            self::assertSame('The upload intent is used already.', $exception->getMessage());
        }

        self::assertSame($firstUse, $intent->usedAt());
    }

    #[Test]
    public function anIntentCannotBeUsedFromTheMomentItExpires(): void
    {
        $expiresAt = $this->clock->now()->modify('+15 minutes');
        $intent = $this->open(expiresAt: $expiresAt);

        foreach ([$expiresAt, $expiresAt->modify('+1 hour')] as $at) {
            try {
                $intent->markUsed($at);
                self::fail('Not refused.');
            } catch (UploadIntentUnusable $exception) {
                self::assertSame('The upload intent has expired.', $exception->getMessage());
            }
            self::assertNull($intent->usedAt());
        }
    }

    private function assertCheckFileRefused(UploadIntent $intent, int $bytes, string $mime, string $reason): void
    {
        try {
            $intent->checkFile($bytes, $mime);
            self::fail(\sprintf('Not refused: %d bytes, %s', $bytes, $mime));
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }

    /**
     * @param list<string> $allowedMimeTypes
     */
    private function open(int $maxBytes = 1000, array $allowedMimeTypes = ['image/png', 'image/jpeg'], string $storageKey = 'uploads/org/intent', ?\DateTimeImmutable $expiresAt = null, string $targetKind = 'ticket', string $targetId = 'SHOP-12'): UploadIntent
    {
        return UploadIntent::open(
            new UploadIntentId(self::INTENT),
            new OrganizationId(self::ORGANIZATION),
            new UserId(self::USER),
            UploadPurpose::Screenshot,
            $targetKind,
            $targetId,
            $maxBytes,
            $allowedMimeTypes,
            $storageKey,
            $expiresAt ?? $this->clock->now()->modify('+15 minutes'),
        );
    }
}
