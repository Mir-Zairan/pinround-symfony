<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Media\Api\MediaPermission;
use App\Media\Api\UploadRefused;
use App\Media\Api\Uploads;
use App\Media\Api\UploadSlot;
use App\Media\Api\UploadTarget;
use App\Media\Api\UploadTargetDenied;
use App\Review\Api\OpenDraftQueries;
use App\Review\Api\OpenDraftScreenshots;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The collaborators of CreateUpload, each recording what it was asked. Keys match exactly and openDraftOf reads only
 * the owner's own open unshared draft, as the real queries do. factsOf reads SHOP-1 and SHOP-2, Mia's report, unless
 * $isTicketUnreadable. startDraft refuses DRAFT_EXISTS for an owner who has one and adds the draft it starts.
 * isGranted decides MediaPermission UPLOAD on a round as RoundMediaTarget does for an unshared draft (its owner alone),
 * and TicketPermission VIEW as $isTicketHidden says. begin refuses the purpose, type and size in Uploads' order with
 * its messages; the room checks it cannot know are scripted with $refusal. The commands create_upload never uses throw.
 */
final class CreateUploadFakes implements CurrentApiToken, TicketKeyQueries, TicketTurnQueries, OpenDraftQueries, RoundCommands, Uploads, AuthorizationCheckerInterface
{
    public const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000e701';
    public const string OMAR = '019a5f00-0000-7000-8000-00000000e721';
    public const string MIA = '019a5f00-0000-7000-8000-00000000e722';
    public const string SHOP_1 = '019a5f00-0000-7000-8000-00000000e731';
    public const string SHOP_2 = '019a5f00-0000-7000-8000-00000000e732';
    public const string STARTED_DRAFT = '019a5f00-0000-7000-8000-00000000e749';
    public const string INTENT = '019a5f00-0000-7000-8000-00000000e751';
    public const string PUT_URL = 'http://localhost:9000/pinround/uploads/019a5f00-0000-7000-8000-00000000e701/019a5f00-0000-7000-8000-00000000e751?X-Amz-Signature=abc';

    private const array ALLOWED_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
    private const int MAX_BYTES = 15 * 1024 * 1024;

    /** The caller the test's CurrentUser answers with, which isGranted decides for. */
    public UserId $caller;

    /** @var array<string, array{string, string}> the ticket id and owner id of each open unshared draft, by round id */
    public array $openDrafts = [];

    /** @var list<array{string, string, string}> ticket, owner and time of each startDraft */
    public array $draftsStarted = [];

    /** @var list<array{string, string, string, string, string, string, int, string}> organization, user, target kind and id, purpose, mime, bytes and time of each begin */
    public array $begins = [];

    /** Thrown by begin after its file checks, as a full storage or too many uploads waiting would be. */
    public ?UploadRefused $refusal = null;

    public bool $deniesEveryUploadTarget = false;

    public bool $deniesInsideUploads = false;

    public bool $isTicketUnreadable = false;

    public bool $isTicketHidden = false;

    /** @var list<TicketVisibilityFacts> the subject of each TicketPermission VIEW asked */
    public array $ticketViewsAsked = [];

    public function __construct()
    {
        $this->caller = new UserId(self::OMAR);
    }

    public function access(): ApiTokenAccess
    {
        return new ApiTokenAccess('019a5f00-0000-7000-8000-00000000e7a1', new OrganizationId(self::ACME_FOODS), ['read', 'rounds:write'], new \DateTimeImmutable('2027-02-08 09:00:00 UTC'));
    }

    public function idOf(string $key): ?TicketId
    {
        $ticketId = ['SHOP-1' => self::SHOP_1, 'SHOP-2' => self::SHOP_2][$key] ?? null;

        return null === $ticketId ? null : new TicketId($ticketId);
    }

    public function keyOf(TicketId $ticketId): ?string
    {
        throw new \LogicException('create_upload never asks for a key.');
    }

    public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot
    {
        if ($this->isTicketUnreadable || !\in_array($ticketId->toString(), [self::SHOP_1, self::SHOP_2], true)) {
            return null;
        }

        return new TicketTurnSnapshot($ticketId, '019a5f00-0000-7000-8000-00000000e761', '019a5f00-0000-7000-8000-00000000e762', 'todo', 'todo', null, null, null, [], [], TicketVisibilityFacts::FOR_EVERYONE, 'major', null, new UserId(self::MIA), new UserId(self::OMAR));
    }

    public function openDraftOf(TicketId $ticketId, UserId $owner): ?RoundAccessFacts
    {
        foreach ($this->openDrafts as $roundId => [$draftTicketId, $ownerId]) {
            if ($draftTicketId === $ticketId->toString() && $ownerId === $owner->toString()) {
                return new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), $roundId, $ticketId, $owner, true, false);
            }
        }

        return null;
    }

    public function openDraftScreenshotsOf(TicketId $ticketId, UserId $owner): ?OpenDraftScreenshots
    {
        throw new \LogicException('create_upload never lists a draft\'s screenshots.');
    }

    public function startDraft(TicketId $ticketId, UserId $ownerId, \DateTimeImmutable $startedAt): string
    {
        if (null !== $this->openDraftOf($ticketId, $ownerId)) {
            throw new RoundRefused(RoundRefused::DRAFT_EXISTS);
        }
        $this->draftsStarted[] = [$ticketId->toString(), $ownerId->toString(), $startedAt->format('Y-m-d H:i:s')];
        $this->openDrafts[self::STARTED_DRAFT] = [$ticketId->toString(), $ownerId->toString()];

        return self::STARTED_DRAFT;
    }

    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
    {
        if (TicketPermission::VIEW === $attribute && $subject instanceof TicketVisibilityFacts) {
            $this->ticketViewsAsked[] = $subject;

            return !$this->isTicketHidden;
        }
        if (MediaPermission::UPLOAD !== $attribute || !$subject instanceof UploadTarget || 'round' !== $subject->kind || $this->deniesEveryUploadTarget) {
            return false;
        }
        $draft = $this->openDrafts[$subject->id] ?? null;

        return null !== $draft && $draft[1] === $this->caller->toString();
    }

    public function begin(OrganizationId $organizationId, UserId $userId, UploadTarget $target, string $purpose, string $mime, int $bytes, \DateTimeImmutable $at): UploadSlot
    {
        if ('screenshot' !== $purpose) {
            throw new UploadRefused('Only screenshots can be uploaded here.');
        }
        if (!\in_array($mime, self::ALLOWED_TYPES, true)) {
            throw new UploadRefused('This file is not a PNG, JPEG or WebP screenshot.');
        }
        if ($bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new UploadRefused('This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.');
        }
        if (null !== $this->refusal) {
            throw $this->refusal;
        }
        if ($this->deniesInsideUploads) {
            throw new UploadTargetDenied('This person may not add a file to the target.');
        }
        $this->begins[] = [$organizationId->toString(), $userId->toString(), $target->kind, $target->id, $purpose, $mime, $bytes, $at->format('Y-m-d H:i:s')];

        return new UploadSlot(self::INTENT, self::PUT_URL, ['Content-Type' => $mime, 'Content-Length' => (string) $bytes], $at->modify('+5 minutes'));
    }

    public function complete(OrganizationId $organizationId, UserId $userId, string $intentId, \DateTimeImmutable $at): string
    {
        throw new \LogicException('create_upload never completes an upload.');
    }

    public function createReport(TicketId $ticketId, array $screenshots, UserId $reporterId, \DateTimeImmutable $reportedAt): string
    {
        throw new \LogicException('create_upload never creates a report.');
    }

    public function addDraftScreenshot(string $roundId, UserId $ownerId, string $mediaId, \DateTimeImmutable $addedAt): string
    {
        throw new \LogicException('create_upload never adds a screenshot.');
    }

    public function removeDraftScreenshot(string $roundId, UserId $ownerId, string $screenshotId, \DateTimeImmutable $at): void
    {
        throw new \LogicException('create_upload never removes a screenshot.');
    }

    public function editDraftScreenshot(string $roundId, UserId $ownerId, string $screenshotId, ?string $title, ?string $summary, \DateTimeImmutable $at): void
    {
        throw new \LogicException('create_upload never edits a screenshot.');
    }

    public function setDraftPins(string $roundId, UserId $ownerId, string $screenshotId, array $pins, \DateTimeImmutable $at): void
    {
        throw new \LogicException('create_upload never sets pins.');
    }

    public function setDraftVerdict(string $roundId, UserId $ownerId, string $screenshotId, ?string $verdict, \DateTimeImmutable $at): void
    {
        throw new \LogicException('create_upload never sets a verdict.');
    }

    public function setDraftPinVerdict(string $roundId, UserId $ownerId, string $pinId, ?string $verdict, \DateTimeImmutable $at): void
    {
        throw new \LogicException('create_upload never marks a pin.');
    }

    public function setDraftVerdictWithSummary(string $roundId, UserId $ownerId, string $screenshotId, string $verdict, ?string $summary, \DateTimeImmutable $at): string
    {
        throw new \LogicException('create_upload never sets a verdict.');
    }

    public function shareDraft(string $roundId, UserId $ownerId, \DateTimeImmutable $sharedAt): int
    {
        throw new \LogicException('create_upload never shares a draft.');
    }

    public function shareDraftAtHandOff(string $roundId, UserId $ownerId, \DateTimeImmutable $sharedAt): int
    {
        throw new \LogicException('create_upload never shares a draft.');
    }

    public function deleteDraft(string $roundId, UserId $ownerId, \DateTimeImmutable $deletedAt): void
    {
        throw new \LogicException('create_upload never deletes a draft.');
    }
}
