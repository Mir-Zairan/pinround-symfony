<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application;

use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageId;
use App\Conversation\Domain\Messages;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Messages by organization and id, as the cnv_message key is; save refuses a message not read through lockedOf, as
 * DoctrineMessages refuses one it does not manage.
 */
final class InMemoryMessages implements Messages
{
    /** @var array<string, Message> by organization and id */
    private array $stored = [];

    /** @var array<string, string> organization and id of every message read through lockedOf */
    private array $read = [];

    /** @var list<string> ids of the messages saved, in order */
    private array $savedIds = [];

    public function nextIdentity(): MessageId
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function add(Message $message): void
    {
        $key = self::keyOf($message->organizationId(), $message->id());
        if (isset($this->stored[$key])) {
            throw new \LogicException('A message with that id is already stored.');
        }

        $this->stored[$key] = $message;
    }

    public function lockedOf(OrganizationId $organizationId, MessageId $id): ?Message
    {
        $key = self::keyOf($organizationId, $id);
        if (!isset($this->stored[$key])) {
            return null;
        }
        $this->read[$key] = $key;

        return $this->stored[$key];
    }

    public function save(Message $message): void
    {
        if (!isset($this->read[self::keyOf($message->organizationId(), $message->id())])) {
            throw new \LogicException('Only a message read in this transaction can be saved; a new one is added.');
        }

        $this->savedIds[] = $message->id()->toString();
    }

    /**
     * @return list<string>
     */
    public function savedIds(): array
    {
        return $this->savedIds;
    }

    private static function keyOf(OrganizationId $organizationId, MessageId $id): string
    {
        return $organizationId->toString().'/'.$id->toString();
    }
}
