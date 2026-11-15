<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application;

use App\Conversation\Domain\Replies;
use App\Conversation\Domain\Reply;
use App\Conversation\Domain\ReplyId;
use App\SharedKernel\Domain\OrganizationId;
use Symfony\Component\Uid\Uuid;

/**
 * Replies by organization and id, as the cnv_reply key is: add refuses an id already stored, as the primary key does.
 * New ids are UUIDv7, as DoctrineReplies hands out.
 */
final class InMemoryReplies implements Replies
{
    /** @var array<string, Reply> by organization and id, in the order added */
    private array $stored = [];

    public function nextIdentity(): ReplyId
    {
        return new ReplyId(Uuid::v7()->toRfc4122());
    }

    public function add(Reply $reply): void
    {
        $key = self::keyOf($reply->organizationId(), $reply->id());
        if (isset($this->stored[$key])) {
            throw new \LogicException('A reply with that id is already stored.');
        }

        $this->stored[$key] = $reply;
    }

    /**
     * @return list<Reply> in the order added
     */
    public function added(): array
    {
        return array_values($this->stored);
    }

    private static function keyOf(OrganizationId $organizationId, ReplyId $id): string
    {
        return $organizationId->toString().'/'.$id->toString();
    }
}
