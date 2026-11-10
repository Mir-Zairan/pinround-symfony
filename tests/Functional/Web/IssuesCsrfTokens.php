<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

/**
 * CSRF tokens for functional tests that post without rendering the form first.
 */
trait IssuesCsrfTokens
{
    /**
     * A token for $tokenId, stored in the client's session as rendering the form would store it.
     */
    private function csrfTokenFor(KernelBrowser $client, string $tokenId): string
    {
        $session = $client->getSession();
        self::assertNotNull($session);
        $request = Request::create('https://localhost/');
        $request->setSession($session);
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $requestStack->pop();
        }
        $session->save();

        return $token;
    }
}
