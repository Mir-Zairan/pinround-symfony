<?php

declare(strict_types=1);

namespace App\Assist\Application\Gateway;

use App\Assist\Application\AiAnswerCheck;
use App\Assist\Application\AiAnswerRejected;
use App\Assist\Application\AiCallLimit;
use App\Assist\Application\AiCallUsage;
use App\Assist\Application\AiGateway;
use App\Assist\Application\AiNotAllowed;
use App\Assist\Application\AiPrompt;
use App\Assist\Application\AiProvider;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiResult;
use App\Assist\Application\AiTask;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\Metering;
use App\Assist\Application\Policy\PolicyEvaluator;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Assist\Application\PromptRenderer;
use App\Assist\Application\PromptVersion;
use App\Assist\Application\ProviderChoice;
use App\Assist\Domain\AiFeature;
use App\Organization\Api\ActorContext;
use App\Organization\Api\AiSettings;
use App\Organization\Api\AiSettingsQueries;
use App\SharedKernel\Application\UndecryptableField;
use App\SharedKernel\Domain\OrganizationId;

/**
 * The gateway in the order of ai-architecture.md: the org policy with the ticket as the viewer may see it, the provider
 * and its key, the prompt, pinround's limit per person, then the provider, the answer check and one usage row per
 * provider call. A data answer whose check allows it gets one more call with the retry instruction; that second call is
 * metered too but not counted against the person's limit again.
 *
 * The server never sends to a laptop, so the policy is decided with the laptop not ready: "laptop first" goes to the
 * cloud here, and "laptop" only refuses with the setup sheet's line.
 */
final readonly class GuardedAiGateway implements AiGateway
{
    public function __construct(
        private AiSettingsQueries $aiSettingsQueries,
        private PolicyEvaluator $policyEvaluator,
        private AskedTicketQueries $askedTicketQueries,
        private PromptRenderer $promptRenderer,
        private AiCallLimit $aiCallLimit,
        private AiProvider $aiProvider,
        private AiAnswerCheck $aiAnswerCheck,
        private Metering $metering,
    ) {
    }

    public function run(AiTask $task, ActorContext $viewer): AiResult
    {
        // TODO(P08.T6): a draft report's word check decides whether it may go to cloud AI; until intake brings it, a
        // task without a ticket is refused instead of sent.
        $ticketId = $task->ticketId ?? throw new \InvalidArgumentException('A server AI task needs a ticket.');

        $settings = $this->aiSettingsQueries->aiSettingsOf($viewer->organizationId());
        $viewerRefusal = $this->policyEvaluator->refusalForViewer($settings, $viewer);
        if (null !== $viewerRefusal) {
            throw new AiNotAllowed($viewerRefusal);
        }
        $ticket = $this->askedTicketQueries->visibleTicketOf($ticketId, $viewer);
        if (null === $ticket) {
            throw new AiNotAllowed(PolicyRefusal::CannotSeeTicket);
        }
        $decision = $this->policyEvaluator->decide($settings, $viewer, true, $ticket->isTeamOnly, false);
        if (null !== $decision->refusal) {
            throw new AiNotAllowed($decision->refusal);
        }

        $choice = $this->providerChoiceOf($settings, $viewer->organizationId());
        $version = $this->promptRenderer->currentVersion($task->feature);
        $prompt = $this->promptRenderer->build($version, $ticket->promptTicket, $decision->isTitleOnly);
        $this->aiCallLimit->consume($viewer);

        return $this->ask($prompt, $choice, $version, $viewer, false);
    }

    /**
     * @throws AiUnavailable when the organization stored a key that cannot be read; the operator's key is not used then
     */
    private function providerChoiceOf(AiSettings $settings, OrganizationId $organizationId): ProviderChoice
    {
        $operatorKeyChoice = ProviderChoice::fromSettings($settings->provider, $settings->providerModel);
        try {
            $organizationKey = $this->aiSettingsQueries->providerKeyOf($organizationId);
        } catch (UndecryptableField) {
            throw AiUnavailable::unreadableOrganizationKey($operatorKeyChoice->provider->value);
        }

        return null === $organizationKey
            ? $operatorKeyChoice
            : ProviderChoice::fromSettings($settings->provider, $settings->providerModel, $organizationKey);
    }

    private function ask(AiPrompt $prompt, ProviderChoice $choice, PromptVersion $version, ActorContext $viewer, bool $isRetry): AiResult
    {
        $label = self::labelOf($version->feature);
        $startedAt = hrtime(true);
        try {
            $answer = $this->aiProvider->complete($prompt, $choice);
        } catch (AiUnavailable|AiRateLimited $failure) {
            $this->metering->record(AiCallUsage::failed($choice, self::millisecondsSince($startedAt)), $version->feature, $viewer);

            return AiResult::failed($failure->getMessage(), $choice->model, $choice->provider->value, $version->name(), $label);
        }

        try {
            $checkedAnswer = AiFeature::Intake === $version->feature
                ? AiResult::withData($this->aiAnswerCheck->checkData($version, $answer->text, [], $isRetry), $answer->model, $answer->provider, $version->name(), $label)
                : AiResult::withText($this->aiAnswerCheck->checkText($version, $answer->text), $answer->model, $answer->provider, $version->name(), $label);
        } catch (AiAnswerRejected $rejection) {
            $this->metering->record(AiCallUsage::rejected($answer), $version->feature, $viewer);
            if ($rejection->isRetryAllowed && !$isRetry) {
                $retryPrompt = new AiPrompt($prompt->feature, $prompt->instructions, $prompt->content."\n\n".$this->aiAnswerCheck->retryInstruction());

                return $this->ask($retryPrompt, $choice, $version, $viewer, true);
            }

            return AiResult::rejected($rejection->getMessage(), $answer->model, $answer->provider, $version->name(), $label);
        }

        $this->metering->record(AiCallUsage::answered($answer), $version->feature, $viewer);

        return $checkedAnswer;
    }

    private static function labelOf(AiFeature $feature): string
    {
        return match ($feature) {
            AiFeature::Summary => 'AI summary',
            AiFeature::Explain => 'AI explanation',
            AiFeature::Intake => 'AI suggestion',
        };
    }

    private static function millisecondsSince(int|float $startedAtNanoseconds): int
    {
        return (int) round((hrtime(true) - $startedAtNanoseconds) / 1_000_000);
    }
}
