<?php

declare(strict_types=1);

namespace App\Notification\UI\Component;

use App\Notification\Api\BellGroup;
use App\Notification\UI\Controller\BellController;
use Psr\Clock\ClockInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * One group of the bell (dialogs-and-popovers.md, Pings popover): the sender's first name, what they did or how many
 * they sent, how long ago the newest came, the newest ping's text, the ticket's key, Done and Open.
 */
#[AsTwigComponent]
final class PingCard
{
    /** What one notification of each kind says after the sender's name. */
    private const array PHRASES = [
        'ping' => 'pinged you',
        'question' => 'asked you',
        'answer' => 'answered you',
        'reply' => 'replied to you',
        'assigned' => 'gave you a ticket',
        'round_shared' => 'shared a round',
        'closed_duplicate' => 'closed your report as a duplicate',
    ];

    public BellGroup $group;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function senderFirstName(): string
    {
        return self::firstNameOf($this->group->senderName);
    }

    public function label(): string
    {
        return self::labelOf($this->group);
    }

    public function age(): string
    {
        return self::ageOf($this->group->latestAt, $this->clock->now());
    }

    public function doneRoute(): string
    {
        return BellController::DONE_ROUTE;
    }

    public function openRoute(): string
    {
        return BellController::OPEN_ROUTE;
    }

    public function csrfId(): string
    {
        return BellController::CSRF_ID;
    }

    public static function firstNameOf(string $name): string
    {
        $trimmed = trim($name);

        return '' === $trimmed ? $name : explode(' ', $trimmed)[0];
    }

    /**
     * A group of one says what the sender did ("pinged you"), a group of pings counts them ("3 pings"), and a mixed
     * group counts its notifications ("2 notifications"); an unknown kind reads "notified you".
     */
    public static function labelOf(BellGroup $group): string
    {
        if (1 === $group->size) {
            return self::PHRASES[$group->newestKind] ?? 'notified you';
        }

        return \sprintf($group->isAllPings ? '%d pings' : '%d notifications', $group->size);
    }

    /**
     * "now" within a minute (or for a time ahead of $now), then "5 min ago", "3 h ago", "1 day ago", "4 days ago".
     */
    public static function ageOf(\DateTimeImmutable $at, \DateTimeImmutable $now): string
    {
        $seconds = $now->getTimestamp() - $at->getTimestamp();
        if ($seconds < 60) {
            return 'now';
        }
        if ($seconds < 3600) {
            return \sprintf('%d min ago', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return \sprintf('%d h ago', intdiv($seconds, 3600));
        }
        $days = intdiv($seconds, 86400);

        return 1 === $days ? '1 day ago' : \sprintf('%d days ago', $days);
    }
}
