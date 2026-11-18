<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Assist\Api\LocalAi;
use App\Assist\Api\LocalAiPurpose;
use App\Assist\Api\LocalModelName;
use App\Assist\Api\LocalPackageId;
use App\Assist\Api\LocalPackageRefused;
use App\Assist\Api\LocalResult;
use App\Assist\Api\LocalResultRefused;
use App\Assist\Api\MainSummaryRefused;
use App\Assist\Api\PromptPackage;
use App\Assist\Application\Gateway\AiTaskAdmission;
use App\Assist\Application\LocalAi\IssuedLocalPackage;
use App\Assist\Application\LocalAi\LocalPackageIds;
use App\Assist\Application\LocalAi\LocalPackageLimit;
use App\Assist\Application\LocalAi\LocalPackages;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Assist\Domain\AiFeature;
use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditLog;
use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberNameQueries;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\GeneratedSummaryRefused;
use App\Ticketing\Api\TicketSummaryCommands;
use Psr\Clock\ClockInterface;

/**
 * Laptop AI on the server's side: the policy and the prompt come from the parts the server path uses
 * (AiTaskAdmission, PromptRenderer), and the laptop's answer passes the same AiAnswerCheck. A person who is no member
 * is refused as one who cannot see the ticket, so the refusal never tells whether it exists. Nothing here logs.
 */
final readonly class LocalAiService implements LocalAi
{
    /** A comment's cap (Conversation's Message::MAX_BODY_LENGTH): the result is user-provided content. */
    public const int MAX_RESULT_CHARACTERS = 10000;

    /** The audit action of a refused laptop result; its data holds the refusal code and the model name only. */
    public const string AUDIT_RESULT_REFUSED = 'local_ai.result_refused';

    public const string AUDIT_TARGET_TYPE = 'local_ai_package';

    public function __construct(
        private AiRequesters $aiRequesters,
        private AiTaskAdmission $aiTaskAdmission,
        private PromptRenderer $promptRenderer,
        private AiAnswerCheck $aiAnswerCheck,
        private LocalPackages $localPackages,
        private LocalPackageIds $localPackageIds,
        private MemberNameQueries $memberNameQueries,
        private AuditLog $auditLog,
        private Transactions $transactions,
        private TicketSummaryCommands $ticketSummaryCommands,
        private ClockInterface $clock,
    ) {
    }

    public function proposalOf(TicketId $ticketId, ?\DateTimeImmutable $currentSummaryCreatedAt, OrganizationId $organizationId): ?LocalResult
    {
        return $this->localPackages->proposalOf($organizationId, $ticketId, $currentSummaryCreatedAt)?->result;
    }

    public function makeMainSummary(LocalPackageId $packageId, TicketId $ticketId, ?\DateTimeImmutable $currentSummaryCreatedAt, UserId $chooserId, OrganizationId $organizationId): void
    {
        $madeMainAt = $this->clock->now();
        $posted = $this->localPackages->markMadeMain($organizationId, $packageId, $ticketId, $currentSummaryCreatedAt, $madeMainAt)
            ?? throw MainSummaryRefused::notAProposal();
        try {
            $this->ticketSummaryCommands->storeChosenLaptopSummary(
                $organizationId,
                $ticketId,
                $posted->result->text,
                $posted->result->posterId,
                $posted->result->model->value,
                $posted->promptVersion,
                $chooserId,
                $madeMainAt,
            );
        } catch (GeneratedSummaryRefused $refused) {
            throw MainSummaryRefused::summaryRejected($refused->getMessage());
        }
    }

    public function package(LocalAiPurpose $purpose, TicketId $ticketId, UserId $requesterId, OrganizationId $organizationId): PromptPackage
    {
        $requester = $this->aiRequesters->actorOf($requesterId, $organizationId) ?? throw self::refusedFor(PolicyRefusal::CannotSeeTicket);
        $feature = self::featureOf($purpose);
        try {
            $admitted = $this->aiTaskAdmission->admitToLaptop(new AiTask($feature, $ticketId), $requester);
        } catch (AiNotAllowed $notAllowed) {
            throw self::refusedFor($notAllowed->refusal);
        }

        $version = $this->promptRenderer->currentVersion($feature);
        $prompt = $this->promptRenderer->build($version, $admitted->promptTicket, $admitted->decision->isTitleOnly);
        $packageId = $this->localPackageIds->next();
        $expiresAt = $this->localPackages->issue($packageId, $organizationId, $requesterId, $ticketId, $purpose, $version, self::PACKAGE_LIFETIME_SECONDS, self::MAX_OPEN_PACKAGES_PER_PERSON, self::MAX_PACKAGES_PER_PERSON_PER_HOUR);
        if ($expiresAt instanceof LocalPackageLimit) {
            throw match ($expiresAt) {
                LocalPackageLimit::OpenPackages => LocalPackageRefused::tooManyOpenPackages(self::MAX_OPEN_PACKAGES_PER_PERSON),
                LocalPackageLimit::PackagesPerHour => LocalPackageRefused::tooManyPackages(self::MAX_PACKAGES_PER_PERSON_PER_HOUR),
            };
        }

        return new PromptPackage($packageId, $prompt->instructions, $prompt->content, null, $expiresAt);
    }

    public function accept(LocalPackageId $packageId, string $model, string $text, UserId $posterId, OrganizationId $organizationId): LocalResult
    {
        // The refusal leaves the closure as a value, so the transaction that holds its audit entry commits.
        $accepted = $this->transactions->run(function () use ($packageId, $model, $text, $posterId, $organizationId): LocalResult|LocalResultRefused {
            try {
                return $this->acceptInTransaction($packageId, $model, $text, $posterId, $organizationId);
            } catch (LocalResultRefused $refusal) {
                $this->auditLog->record(AuditEntry::byUser(
                    $organizationId,
                    $posterId->toString(),
                    self::AUDIT_RESULT_REFUSED,
                    self::AUDIT_TARGET_TYPE,
                    $packageId->toString(),
                    ['reason' => $refusal->refusalCode, 'model' => LocalModelName::isValid($model) ? $model : null],
                ));

                return $refusal;
            }
        });
        if ($accepted instanceof LocalResultRefused) {
            throw $accepted;
        }

        return $accepted;
    }

    /**
     * @throws LocalResultRefused
     */
    private function acceptInTransaction(LocalPackageId $packageId, string $model, string $text, UserId $posterId, OrganizationId $organizationId): LocalResult
    {
        $owner = $this->localPackages->ownerOf($packageId);
        if (null === $owner || !$owner->organizationId->equals($organizationId) || !$owner->userId->equals($posterId)) {
            throw LocalResultRefused::packageNotFound();
        }
        $poster = $this->aiRequesters->actorOf($posterId, $organizationId) ?? throw LocalResultRefused::packageNotFound();
        $posterFirstName = $this->firstNameOf($posterId, $organizationId) ?? throw LocalResultRefused::packageNotFound();
        $package = $this->localPackages->issuedOf($organizationId, $packageId, $posterId) ?? throw LocalResultRefused::packageNotFound();
        if (!$package->isOpen) {
            throw LocalResultRefused::packageGone();
        }
        $this->requireLaptopAiStillAllowed($package, $poster);

        if (!LocalModelName::isValid($model)) {
            throw LocalResultRefused::resultRejected(LocalModelName::RULE);
        }
        $modelName = new LocalModelName($model);
        $checkedText = $this->checkedTextOf($package->promptVersion, $text);

        $label = \sprintf(LocalResult::LABEL_FORMAT, $posterFirstName, $modelName->value);
        $postedAt = $this->localPackages->post($organizationId, $packageId, $posterId, $checkedText, $modelName, $label)
            ?? throw LocalResultRefused::packageGone();

        return new LocalResult(
            $packageId,
            $checkedText,
            $modelName,
            $posterId,
            $label,
            $postedAt,
        );
    }

    /**
     * The policy and the poster's sight of the ticket may have changed since the package was issued.
     *
     * @throws LocalResultRefused package_not_found when the poster may no longer see the ticket, as for an unknown
     *                            package; the policy's own code when it no longer gives them laptop AI
     */
    private function requireLaptopAiStillAllowed(IssuedLocalPackage $package, ActorContext $poster): void
    {
        try {
            $this->aiTaskAdmission->admitToLaptop(new AiTask(self::featureOf($package->purpose), $package->ticketId), $poster);
        } catch (AiNotAllowed $notAllowed) {
            throw PolicyRefusal::CannotSeeTicket === $notAllowed->refusal ? LocalResultRefused::packageNotFound() : LocalResultRefused::notAllowed($notAllowed->refusal->value, $notAllowed->refusal->sentence());
        }
    }

    /**
     * @throws LocalResultRefused when the answer check rejects the text or it is longer than a comment may be
     */
    private function checkedTextOf(PromptVersion $version, string $text): string
    {
        try {
            $checkedText = $this->aiAnswerCheck->checkText($version, $text);
        } catch (AiAnswerRejected $rejection) {
            throw LocalResultRefused::resultRejected($rejection->getMessage());
        }
        if (mb_strlen($checkedText, 'UTF-8') > self::MAX_RESULT_CHARACTERS) {
            throw LocalResultRefused::resultRejected(\sprintf('The AI answer is longer than %s characters.', number_format(self::MAX_RESULT_CHARACTERS)));
        }

        return $checkedText;
    }

    /**
     * The first word of the poster's display name, the rule the ticket page names people by.
     *
     * @return string|null null when the poster is no longer a member or has a blank name
     */
    private function firstNameOf(UserId $posterId, OrganizationId $organizationId): ?string
    {
        $displayName = trim($this->memberNameQueries->displayNamesOf($organizationId, [$posterId])[$posterId->toString()] ?? '');

        return '' === $displayName ? null : explode(' ', $displayName, 2)[0];
    }

    private static function featureOf(LocalAiPurpose $purpose): AiFeature
    {
        return match ($purpose) {
            LocalAiPurpose::Summary => AiFeature::Summary,
        };
    }

    private static function refusedFor(PolicyRefusal $refusal): LocalPackageRefused
    {
        return new LocalPackageRefused($refusal->value, $refusal->sentence());
    }
}
