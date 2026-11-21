<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * OAuth clients registered through POST /oauth/register, and the removal of them and of the authorization codes of the
 * organizations the test made. Use it next to LogsInUsers, and call removeOAuthClientsAndCodes() in tearDown before
 * LogsInUsers' own.
 */
trait RegistersOAuthClients
{
    /** @var list<string> */
    private array $registeredClientIds = [];

    /**
     * @param list<string> $redirectUris
     *
     * @return string the client id
     */
    private function registerClient(KernelBrowser $client, string $clientName, array $redirectUris): string
    {
        $client->request('POST', '/oauth/register', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['client_name' => $clientName, 'redirect_uris' => $redirectUris], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        self::assertResponseStatusCodeSame(201);
        $answer = json_decode((string) $client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $clientId = $answer['client_id'] ?? null;
        self::assertIsString($clientId);
        $this->registeredClientIds[] = $clientId;

        return $clientId;
    }

    private function removeOAuthClientsAndCodes(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->readAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM idn_oauth_authorization_code'));
        }
        if ([] !== $this->registeredClientIds) {
            $this->migrator()->executeStatement('DELETE FROM idn_oauth_client WHERE client_id IN (?)', [$this->registeredClientIds], [ArrayParameterType::STRING]);
            $this->registeredClientIds = [];
        }
    }

    /**
     * Through the migrator, which owns the table; FORCE ROW LEVEL SECURITY binds it too, so it runs under the tenant.
     *
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function readAs(OrganizationId $organizationId, \Closure $work): mixed
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->reset();
        $tenantContext->set($organizationId);
        try {
            return $this->migrator()->transactional($work);
        } finally {
            $tenantContext->reset();
        }
    }
}
