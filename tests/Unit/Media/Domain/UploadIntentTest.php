<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadIntentUnusable;
use App\Media\Domain\UploadPurpose;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
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
        self::assertSame(1000, $intent->maxBytes());
        self::assertSame(['image/png', 'image/jpeg'], $intent->allowedMimeTypes());
        self::assertSame('uploads/org/intent', $intent->storageKey());
        self::assertSame($expiresAt, $intent->expiresAt());
        self::assertNull($intent->usedAt());
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
    private function open(int $maxBytes = 1000, array $allowedMimeTypes = ['image/png', 'image/jpeg'], string $storageKey = 'uploads/org/intent', ?\DateTimeImmutable $expiresAt = null): UploadIntent
    {
        return UploadIntent::open(
            new UploadIntentId(self::INTENT),
            new OrganizationId(self::ORGANIZATION),
            new UserId(self::USER),
            UploadPurpose::Screenshot,
            $maxBytes,
            $allowedMimeTypes,
            $storageKey,
            $expiresAt ?? $this->clock->now()->modify('+15 minutes'),
        );
    }
}
