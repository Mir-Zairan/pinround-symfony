<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Api\ApiTokenAccess;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;

/**
 * The security token TokenAuthenticator creates for a request with a live personal access token: the token's user and
 * what the token allows. Its class is the token-authenticated marker that SymfonyLoginAssurance reads. The firewall is stateless, so it lives for one request and is never stored in a session.
 */
final class ApiTokenAuthentication extends AbstractToken
{
    public function __construct(SecurityUser $user, private readonly ApiTokenAccess $access)
    {
        parent::__construct($user->getRoles());
        $this->setUser($user);
    }

    public function access(): ApiTokenAccess
    {
        return $this->access;
    }
}
