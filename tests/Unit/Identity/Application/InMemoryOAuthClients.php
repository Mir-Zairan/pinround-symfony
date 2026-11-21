<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthClients;
use App\Identity\Domain\OAuthGrantType;

/**
 * Clients as idn_oauth_client keeps them: client_id the primary key, compared case-sensitively as a TEXT column is, and
 * every CHECK constraint of Version20261121200000 refusing a row that breaks it.
 */
final class InMemoryOAuthClients implements OAuthClients
{
    /** @var array<string, OAuthClient> by client_id */
    private array $rows = [];

    public function add(OAuthClient $client): void
    {
        if (isset($this->rows[$client->clientId()])) {
            throw new \LogicException('duplicate key value violates unique constraint "idn_oauth_client_pkey"');
        }
        if (1 !== preg_match('/\A[A-Za-z0-9_-]{32}\z/', $client->clientId())) {
            throw new \LogicException('new row violates check constraint "idn_oauth_client_client_id_check"');
        }
        $clientName = $client->clientName();
        if (null !== $clientName && (mb_strlen($clientName) < 1 || mb_strlen($clientName) > 100)) {
            throw new \LogicException('new row violates check constraint "idn_oauth_client_client_name_check"');
        }
        if (\count($client->redirectUris()) < 1 || \count($client->redirectUris()) > 5) {
            throw new \LogicException('new row violates check constraint "idn_oauth_client_redirect_uris_check"');
        }
        if (!\in_array(OAuthGrantType::AuthorizationCode, $client->grantTypes(), true)) {
            throw new \LogicException('new row violates check constraint "idn_oauth_client_grant_types_check"');
        }
        $this->rows[$client->clientId()] = $client;
    }

    public function byClientId(string $clientId): ?OAuthClient
    {
        return $this->rows[$clientId] ?? null;
    }

    /**
     * @return list<OAuthClient> every stored client, in the order added
     */
    public function all(): array
    {
        return array_values($this->rows);
    }
}
