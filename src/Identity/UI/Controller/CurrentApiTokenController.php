<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Identity\Application\AccountPermission;
use App\Identity\Application\RevokeApiToken;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/v1/me/token/revoke (P10.T8): the browser extension's sign-out revokes the token it was paired with, so a
 * token left in a browser profile stops working at once. It acts only on the request's own token, under the api_token
 * firewall; the write and ApiTokenRevoked share the request transaction, as on the API tokens page.
 */
final class CurrentApiTokenController extends AbstractController
{
    public function __construct(
        private readonly CurrentApiToken $currentApiToken,
        private readonly CurrentUser $currentUser,
        private readonly RevokeApiToken $revokeApiToken,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/api/v1/me/token/revoke', name: 'api_v1_revoke_current_token', methods: ['POST'])]
    public function revoke(): JsonResponse
    {
        $this->denyAccessUnlessGranted(AccountPermission::REVOKE_CURRENT_TOKEN);
        $access = $this->currentApiToken->access() ?? throw new \LogicException('A request with a personal access token has its access.');
        $ownerId = $this->currentUser->id() ?? throw new \LogicException('A request with a personal access token has its user.');
        ($this->revokeApiToken)($ownerId, $access->tokenId, $this->clock->now());

        return new JsonResponse(['revoked' => true]);
    }
}
