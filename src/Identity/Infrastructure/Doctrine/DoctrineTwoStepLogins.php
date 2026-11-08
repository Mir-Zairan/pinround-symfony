<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\TwoStepLogins;
use App\SharedKernel\Domain\UserId;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TwoStepLogins on idn_mfa_secret through the entity manager.
 */
final readonly class DoctrineTwoStepLogins implements TwoStepLogins
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function byUserId(UserId $userId): ?TwoStepLogin
    {
        return $this->entityManager->find(TwoStepLogin::class, ['user' => $userId->toString()]);
    }

    /**
     * Locks the idn_user row, not the idn_mfa_secret one: a user who has none yet has no row to lock, and two first
     * starts would both insert. FOR NO KEY UPDATE still lets other requests add rows that point to the user.
     */
    public function byUserIdForChange(UserId $userId): ?TwoStepLogin
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Changing a two-step login needs an open transaction to hold the lock.');
        }
        $connection->executeStatement('SELECT 1 FROM idn_user WHERE id = ? FOR NO KEY UPDATE', [$userId->toString()]);

        return $this->byUserId($userId);
    }

    public function save(TwoStepLogin $twoStepLogin): void
    {
        $this->entityManager->persist($twoStepLogin);
        $this->entityManager->flush();
    }
}
