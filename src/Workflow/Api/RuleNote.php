<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The plain note under the verb buttons that says why a move is not offered, for example "Paused until Mia answers
 * the question below." People are kept as ids, so the page that shows the note names them.
 */
final readonly class RuleNote
{
    private const string PLACEHOLDER = '/\{([a-zA-Z]+)\}/';
    private const string SENTENCE_BREAK = '. ';

    /**
     * @param string                $template the note with each person as a {placeholder}
     * @param array<string, UserId> $people   the person of each placeholder, keyed by its name without the braces
     *
     * @throws \InvalidArgumentException when a placeholder has no person or a person has no placeholder
     */
    public function __construct(public string $template, public array $people = [])
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);
        $placeholders = array_unique($matches[1]);
        sort($placeholders);
        $names = array_keys($people);
        sort($names);
        if ($placeholders !== $names) {
            throw new \InvalidArgumentException('Every placeholder of a rule note needs exactly one person.');
        }
    }

    /**
     * A name that starts the note or a sentence in it is capitalized, so a fallback such as "an unknown person" reads
     * right there.
     *
     * @param callable(UserId): string $nameOf the name to show for a person
     */
    public function render(callable $nameOf): string
    {
        $names = [];
        foreach ($this->people as $placeholder => $userId) {
            $name = $nameOf($userId);
            $names['{'.$placeholder.'}'] = $name;
            $names[self::SENTENCE_BREAK.'{'.$placeholder.'}'] = self::SENTENCE_BREAK.mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
        }

        // strtr tries the longer key first; the break put before the template makes its start a sentence start too.
        return substr(strtr(self::SENTENCE_BREAK.$this->template, $names), \strlen(self::SENTENCE_BREAK));
    }
}
