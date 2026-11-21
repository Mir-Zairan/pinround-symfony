<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthClients;
use Doctrine\ORM\EntityManagerInterface;

/**
 * OAuthClients on idn_oauth_client, a global table without row-level security (Version20261121200000).
 */
final readonly class DoctrineOAuthClients implements OAuthClients
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(OAuthClient $client): void
    {
        $this->entityManager->persist($client);
        $this->entityManager->flush();
    }

    public function byClientId(string $clientId): ?OAuthClient
    {
        return $this->entityManager->find(OAuthClient::class, $clientId);
    }
}
