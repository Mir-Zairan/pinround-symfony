<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Prompt;

use App\Assist\Application\AiPrompt;
use App\Assist\Application\PromptRenderer;
use App\Assist\Application\PromptTicket;
use App\Assist\Application\PromptVersion;
use App\Assist\Application\UnknownPromptTemplate;
use App\Assist\Domain\AiFeature;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders a versioned prompt template (`templates/{feature}.v{n}.md.twig`, blocks `instructions` and `content`) into a
 * prompt. Ticket data reaches the template only as `DelimitedTicketData`, so it is always marked as data (ADR-0020).
 *
 * The templates have their own Twig environment, apart from the app's: a prompt is plain text, so auto-escaping is off
 * here and nowhere else.
 */
final readonly class PromptBuilder implements PromptRenderer
{
    /** The template version each feature uses now; a feature missing here has no server prompt yet. */
    private const array CURRENT_VERSIONS = [
        'summary' => 1,
        'intake' => 1,
    ];

    private Environment $twig;

    public function __construct(string $templateDirectory = __DIR__.'/templates')
    {
        $this->twig = new Environment(new FilesystemLoader($templateDirectory), [
            'autoescape' => false,
            'strict_variables' => true,
            'cache' => false,
        ]);
    }

    public function currentVersion(AiFeature $feature): PromptVersion
    {
        $number = self::CURRENT_VERSIONS[$feature->value] ?? throw UnknownPromptTemplate::noCurrentVersion($feature);

        return new PromptVersion($feature, $number);
    }

    public function build(PromptVersion $version, PromptTicket $ticket, bool $isTitleOnly, array $duplicateCandidates = []): AiPrompt
    {
        $templateName = $version->name().'.md.twig';
        if (!$this->twig->getLoader()->exists($templateName)) {
            throw UnknownPromptTemplate::noTemplate($version);
        }

        $template = $this->twig->load($templateName);
        $ticketData = DelimitedTicketData::ofTicket($ticket->title, $isTitleOnly ? null : $ticket->text);
        $context = [
            'ticket' => $ticketData,
            'candidates' => DelimitedTicketData::ofCandidates($duplicateCandidates),
        ];
        if (!$template->hasBlock('instructions', $context) || !$template->hasBlock('content', $context)) {
            throw UnknownPromptTemplate::incomplete($version);
        }

        $instructions = trim($template->renderBlock('instructions', $context));
        $content = trim($template->renderBlock('content', $context));
        if ('' === $instructions || !str_contains($content, (string) $ticketData)) {
            throw UnknownPromptTemplate::incomplete($version);
        }

        return new AiPrompt($version->feature, $instructions, $content);
    }
}
