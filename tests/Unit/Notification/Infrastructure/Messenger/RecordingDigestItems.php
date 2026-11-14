<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Messenger;

use App\Notification\Application\Digest\Digest;
use App\Notification\Application\Digest\DigestItems;
use App\SharedKernel\Domain\UserId;

/**
 * Answers every read with one digest and keeps the window each read asked for. Like DbalDigestItems, it answers an
 * empty digest when $since is not before the window's end.
 */
final class RecordingDigestItems implements DigestItems
{
    /** @var list<array{string, string}> since and now of each read, in UTC */
    public array $calls = [];

    public function __construct(private readonly Digest $digest)
    {
    }

    public function digestOf(UserId $recipientId, \DateTimeImmutable $since, \DateTimeImmutable $now): Digest
    {
        $utc = new \DateTimeZone('UTC');
        $this->calls[] = [$since->setTimezone($utc)->format(\DATE_ATOM), $now->setTimezone($utc)->format(\DATE_ATOM)];
        $coveredUntil = Digest::coveredUntilAt($now);

        return $since >= $coveredUntil ? new Digest([], 0, $coveredUntil) : $this->digest;
    }
}
