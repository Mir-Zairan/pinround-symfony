<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResets;
use App\Identity\Domain\PasswordResetToken;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * PasswordResets on idn_password_reset: added through the entity manager, read and used up in SQL, so the single use
 * is decided by the database row, never by an entity loaded earlier.
 */
final readonly class DoctrinePasswordResets implements PasswordResets
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    public function add(PasswordReset $reset): void
    {
        $this->entityManager->persist($reset);
        $this->entityManager->flush();
    }

    public function isUsable(PasswordResetToken $token, \DateTimeImmutable $now): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM idn_password_reset WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [$token->hash(), self::timestamp($now)],
            [ParameterType::BINARY],
        );
    }

    /**
     * A second request with the same token waits on the row lock of the first and then sees used_at set, so it
     * updates nothing (PostgreSQL re-checks the WHERE clause after the lock).
     */
    public function consume(PasswordResetToken $token, \DateTimeImmutable $now): ?UserId
    {
        $at = self::timestamp($now);
        $userId = $this->entityManager->getConnection()->fetchOne(
            'UPDATE idn_password_reset SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? RETURNING user_id',
            [$at, $token->hash(), $at],
            [1 => ParameterType::BINARY],
        );

        return \is_string($userId) ? new UserId($userId) : null;
    }

    private static function timestamp(\DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }
}
