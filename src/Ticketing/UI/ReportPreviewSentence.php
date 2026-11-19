<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

/**
 * The words after "When you send:" on Report a problem (report-a-problem.md, Check reasons and the preview sentence),
 * without the admin's lead-check rule, which is not built yet (P06.T7).
 */
final class ReportPreviewSentence
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $reasonPhrases the check reasons as CheckReason::phrase says them, in order
     * @param string|null  $checker       the checker's first name, "You" when the reporter checks, null when nobody is
     *                                    chosen to check
     * @param string|null  $person        the picked person's first name, or "you"; null for the team or no team
     * @param string|null  $lead          the team's lead's first name; null for no team
     */
    public static function of(string $nextKey, array $reasonPhrases, ?string $checker, ?string $person, ?string $lead, bool $isTeamOnly): string
    {
        $sentence = \sprintf('It becomes ticket %s right away', $nextKey);
        if ([] === $reasonPhrases) {
            $sentence .= match (true) {
                null !== $person => \sprintf(' and goes straight to %s.', $person),
                null !== $lead => \sprintf('. %s plans it like any other ticket.', $lead),
                default => '.',
            };
            $sentence .= ' Nobody needs to check it.';
        } else {
            $because = implode(', ', $reasonPhrases);
            $sentence .= match (true) {
                null === $checker => \sprintf('. It needs a quick look, because %s, but nobody is chosen yet to check tickets with no team.', $because),
                'You' === $checker => \sprintf('. You take a quick look, because %s.', $because),
                default => \sprintf('. %s takes a quick look, because %s.', $checker, $because),
            };
            if (null !== $person) {
                $sentence .= \sprintf(' %s can start already.', 'you' === $person ? 'You' : $person);
            }
        }
        if ($isTeamOnly) {
            $sentence .= ' It mentions security, so only the team and admins can see it.';
        }

        return $sentence;
    }
}
