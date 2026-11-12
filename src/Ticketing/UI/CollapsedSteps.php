<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The steps a person has collapsed in the tickets list, kept in their session so a reload shows them collapsed. It
 * holds only step ids the person sent, never read back to look anything up, so an id of another organization shows
 * nothing and leaks nothing. Stateless: the state lives in the session passed in.
 */
final class CollapsedSteps
{
    private const string SESSION_KEY = 'ticketing.list.collapsed_steps';

    /** Oldest ids drop out past this, so a session never grows without bound. */
    public const int MAX = 200;

    /**
     * @return list<string> step ids
     */
    public static function idsIn(SessionInterface $session): array
    {
        $ids = $session->get(self::SESSION_KEY, []);

        return \is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
    }

    public static function set(SessionInterface $session, string $stepId, bool $isCollapsed): void
    {
        $others = array_values(array_filter(self::idsIn($session), static fn (string $id): bool => $id !== $stepId));
        $session->set(self::SESSION_KEY, $isCollapsed ? \array_slice([...$others, $stepId], -self::MAX) : $others);
    }
}
