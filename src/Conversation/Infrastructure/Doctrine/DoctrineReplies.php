<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Doctrine;

use App\Conversation\Domain\Replies;
use App\Conversation\Domain\Reply;
use App\Conversation\Domain\ReplyId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineReplies implements Replies
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): ReplyId
    {
        return new ReplyId(Uuid::v7()->toRfc4122());
    }

    public function add(Reply $reply): void
    {
        $this->entityManager->persist($reply);
        $this->entityManager->flush();
    }
}
