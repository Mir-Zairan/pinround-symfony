<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Credentials;
use App\SharedKernel\Domain\UserId;

/**
 * Credentials by user id, recording every add.
 */
final class InMemoryCredentials implements Credentials
{
    /** @var list<Credential> */
    public array $added = [];

    public function byUserId(UserId $userId): ?Credential
    {
        foreach ($this->added as $credential) {
            if ($credential->userId()->equals($userId)) {
                return $credential;
            }
        }

        return null;
    }

    public function add(Credential $credential): void
    {
        $this->added[] = $credential;
    }

    public function save(Credential $credential): void
    {
        throw new \LogicException('Not used.');
    }
}
