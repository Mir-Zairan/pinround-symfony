<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * A summary of a ticket about to become its current one (ai-architecture.md › Summaries pipeline). An AI summary is
 * labeled with the person who asked for it, the model and the prompt version that made it (ADR-0020).
 */
final readonly class Summary
{
    public const int TEXT_MAX_LENGTH = 2000;
    /** The same cap as ast_usage.model. */
    public const int MODEL_MAX_LENGTH = 200;
    public const int PROMPT_VERSION_MAX_LENGTH = 100;

    // C0 controls except tab, line feed and carriage return, and DEL; \z, because $ would also accept a final newline.
    private const string PLAIN_TEXT = '/\A[^\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}]*\z/u';
    private const string ONE_LINE = '/\A[^\p{Cc}\p{Zl}\p{Zp}\x{FEFF}]*\z/u';

    private function __construct(
        private TicketId $ticketId,
        private string $text,
        private SummarySource $source,
        private ?UserId $authorId,
        private ?string $model,
        private ?string $promptVersion,
        private \DateTimeImmutable $createdAt,
        private ?UserId $chosenById = null,
    ) {
    }

    /**
     * The text, model and prompt version are kept trimmed. No refusal message quotes the text (ADR-0027).
     *
     * @param UserId $requester who pressed "Summarize with AI", the summary's author
     *
     * @throws SummaryRefused when nothing is left of the text after trimming, it has more than TEXT_MAX_LENGTH
     *                        characters, holds a control character other than a tab or line break, or is not UTF-8;
     *                        or when the model or prompt version is blank, too long or not one line of UTF-8 text
     */
    public static function generatedByAi(
        TicketId $ticketId,
        string $text,
        UserId $requester,
        string $model,
        string $promptVersion,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $ticketId,
            self::textOf($text),
            SummarySource::Ai,
            $requester,
            self::labelOf($model, 'model', self::MODEL_MAX_LENGTH),
            self::labelOf($promptVersion, 'prompt version', self::PROMPT_VERSION_MAX_LENGTH),
            $createdAt,
        );
    }

    /**
     * A laptop's result a person made the main summary with "Use as main summary". The same rules as generatedByAi.
     *
     * @param UserId $poster  who posted the result from their laptop, the summary's author
     * @param string $model   the local model that wrote it
     * @param UserId $chooser who made it the main summary
     *
     * @throws SummaryRefused as generatedByAi
     */
    public static function chosenFromLaptop(
        TicketId $ticketId,
        string $text,
        UserId $poster,
        string $model,
        string $promptVersion,
        UserId $chooser,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $ticketId,
            self::textOf($text),
            SummarySource::Laptop,
            $poster,
            self::labelOf($model, 'model', self::MODEL_MAX_LENGTH),
            self::labelOf($promptVersion, 'prompt version', self::PROMPT_VERSION_MAX_LENGTH),
            $createdAt,
            $chooser,
        );
    }

    public function ticketId(): TicketId
    {
        return $this->ticketId;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function source(): SummarySource
    {
        return $this->source;
    }

    /**
     * Null when no person wrote or asked for it, as for a summary made on sync.
     */
    public function authorId(): ?UserId
    {
        return $this->authorId;
    }

    /**
     * Null when no AI made it.
     */
    public function model(): ?string
    {
        return $this->model;
    }

    /**
     * Null when no AI made it.
     */
    public function promptVersion(): ?string
    {
        return $this->promptVersion;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Who made it the main summary; null when it became the main summary as it was written.
     */
    public function chosenById(): ?UserId
    {
        return $this->chosenById;
    }

    /**
     * @throws SummaryRefused when nothing is left after trimming, it holds a control character other than a tab or line
     *                        break, is not UTF-8, or has more than TEXT_MAX_LENGTH characters
     */
    private static function textOf(string $text): string
    {
        $trimmedText = trim($text);
        if ('' === $trimmedText) {
            throw new SummaryRefused('A summary has text; this one is blank.');
        }
        if (1 !== preg_match(self::PLAIN_TEXT, $trimmedText)) {
            throw new SummaryRefused('A summary is plain UTF-8 text; this one holds a control character or is not UTF-8.');
        }
        if (mb_strlen($trimmedText) > self::TEXT_MAX_LENGTH) {
            throw new SummaryRefused(\sprintf('A summary has at most %d characters.', self::TEXT_MAX_LENGTH));
        }

        return $trimmedText;
    }

    /**
     * @throws SummaryRefused when nothing is left after trimming, it is longer than $maxLength or not one line of UTF-8
     */
    private static function labelOf(string $label, string $name, int $maxLength): string
    {
        $trimmedLabel = trim($label);
        if ('' === $trimmedLabel) {
            throw new SummaryRefused(\sprintf('An AI summary names its %s; it is blank.', $name));
        }
        if (1 !== preg_match(self::ONE_LINE, $trimmedLabel)) {
            throw new SummaryRefused(\sprintf('The %s of an AI summary is one line of UTF-8 text.', $name));
        }
        if (mb_strlen($trimmedLabel) > $maxLength) {
            throw new SummaryRefused(\sprintf('The %s of an AI summary has at most %d characters.', $name, $maxLength));
        }

        return $trimmedLabel;
    }
}
