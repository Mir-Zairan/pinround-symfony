<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Api\CurrentUser;
use App\Identity\Api\OrganizationOAuthConnections;
use App\Identity\Application\EndOAuthConnection;
use App\Identity\Application\OAuthConnectionsOfOrganization;
use App\Identity\Application\RevokeOAuthToken;
use App\Identity\Domain\OAuthRefreshTokens;
use App\Identity\Infrastructure\Doctrine\DbalOAuthConnections;
use App\Identity\UI\Voter\SymfonyOAuthConnectionAccess;
use App\SharedKernel\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The connected apps use cases as the container wires them, built from its services by hand: no page uses them yet, so
 * the container drops them. Everything they use is the container's, so the app's voters decide, on its token storage.
 */
final readonly class HandWiredOAuthConnections
{
    /**
     * @param ContainerInterface $container the test container, test.service_container
     */
    public function __construct(private ContainerInterface $container)
    {
    }

    public function endOAuthConnection(): EndOAuthConnection
    {
        return new EndOAuthConnection(
            $this->service('test.identity_oauth_refresh_tokens', OAuthRefreshTokens::class),
            new SymfonyOAuthConnectionAccess($this->service('security.authorization_checker', AuthorizationCheckerInterface::class)),
            $this->service(CurrentUser::class, CurrentUser::class),
            $this->service(RevokeOAuthToken::class, RevokeOAuthToken::class),
            $this->service(TenantContext::class, TenantContext::class),
        );
    }

    public function organizationOAuthConnections(): OrganizationOAuthConnections
    {
        return new OAuthConnectionsOfOrganization(
            new DbalOAuthConnections($this->service('doctrine.dbal.default_connection', Connection::class), $this->service(TenantContext::class, TenantContext::class)),
            $this->endOAuthConnection(),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $id, string $class): object
    {
        $service = $this->container->get($id);
        if (!$service instanceof $class) {
            throw new \LogicException(\sprintf('The container has no %s as %s.', $class, $id));
        }

        return $service;
    }
}
