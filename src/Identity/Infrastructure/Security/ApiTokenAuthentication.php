<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Api\ApiTokenAccess;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;

/**
 * The security token TokenAuthenticator creates for a request with a live personal access token: the token's user,
 * what the token allows and when its use was last recorded. Its class is the token-authenticated marker that
 * SymfonyLoginAssurance and SymfonyCurrentApiToken read. The firewall is stateless, so it lives for one request and is
 * never stored in a session.
 */
final class ApiTokenAuthentication extends AbstractToken
{
    /**
     * @param \DateTimeImmutable|null $lastUsedAt the token's last recorded use as the login loaded it; null for never
     */
    public function __construct(SecurityUser $user, private readonly ApiTokenAccess $access, private readonly ?\DateTimeImmutable $lastUsedAt)
    {
        parent::__construct($user->getRoles());
        $this->setUser($user);
    }

    public function access(): ApiTokenAccess
    {
        return $this->access;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }
}
