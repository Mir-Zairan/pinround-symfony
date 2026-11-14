<?php

declare(strict_types=1);

namespace App\Notification\UI\Controller;

/**
 * Carries a refused ping's toast out of Transactions::run, so the transaction rolls back: the recipient's name is read
 * inside it, but nothing of a refused ping may be committed.
 */
final class PingRefusedWithToast extends \RuntimeException
{
    /**
     * @param \DateTimeImmutable|null $againAt when the sender may ping this person again, for a ping sent too soon
     */
    public function __construct(private readonly string $toast, private readonly ?\DateTimeImmutable $againAt = null)
    {
        parent::__construct('The ping was refused.');
    }

    public function toast(): string
    {
        return $this->toast;
    }

    public function againAt(): ?\DateTimeImmutable
    {
        return $this->againAt;
    }
}
