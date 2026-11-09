<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationAlreadyOpen;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\Invitations;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineInvitations implements Invitations
{
    private const string OPEN_EMAIL_KEY = 'org_invitation_organization_id_email_key';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): InvitationId
    {
        return new InvitationId(Uuid::v7()->toRfc4122());
    }

    public function add(Invitation $invitation): void
    {
        $this->entityManager->persist($invitation);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // PostgreSQL names the violated index only in the message; it is checked, never logged.
            if (!str_contains($exception->getMessage(), self::OPEN_EMAIL_KEY)) {
                throw $exception;
            }

            throw new InvitationAlreadyOpen('An invitation of this email is open already.', 0, $exception);
        }
    }

    /**
     * The key is (organization_id, id); the tenant filter supplies the organization.
     */
    public function byId(InvitationId $id): ?Invitation
    {
        return $this->entityManager->getRepository(Invitation::class)->findOneBy(['id' => $id->toString()]);
    }

    /**
     * email is citext, so the comparison ignores letter case.
     */
    public function removeNotAcceptedFor(OrganizationId $organizationId, string $email): void
    {
        $this->entityManager->createQuery(
            'DELETE FROM '.Invitation::class.' i WHERE i.organizationId = :organizationId AND i.email = :email AND i.acceptedAt IS NULL',
        )
            ->setParameter('organizationId', $organizationId->toString())
            ->setParameter('email', trim($email))
            ->execute();
    }
}
