<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class LoginController extends AbstractController
{
    public const string WRONG_CREDENTIALS = 'Email or password is wrong';
    /** With the minutes until the limiter that refused lets the next try in. */
    public const string TOO_MANY_TRIES = 'Too many tries. Wait %s.';
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';

    /**
     * POST is the firewall's form_login check path: the authenticator answers it before this controller runs.
     */
    #[Route('/login', name: 'identity_login', methods: ['GET', 'POST'])]
    public function __invoke(AuthenticationUtils $authenticationUtils): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('web_home');
        }

        return $this->render('@Identity/login.html.twig', [
            'lastEmail' => $authenticationUtils->getLastUsername(),
            'errorMessage' => self::messageFor($authenticationUtils->getLastAuthenticationError()),
        ]);
    }

    /**
     * One message for every refusal that is about the account (unknown email, wrong password, blocked user), so the
     * page never tells which emails exist.
     */
    private static function messageFor(?AuthenticationException $error): ?string
    {
        return match (true) {
            null === $error => null,
            $error instanceof TooManyLoginAttemptsAuthenticationException => self::tooManyTries($error),
            $error instanceof InvalidCsrfTokenException => self::FORM_EXPIRED,
            default => self::WRONG_CREDENTIALS,
        };
    }

    private static function tooManyTries(TooManyLoginAttemptsAuthenticationException $error): string
    {
        // LoginThrottlingListener already rounds the wait up to whole minutes; it can be 0 at the very end of a window.
        $wait = $error->getMessageData()['%minutes%'] ?? null;
        $minutes = \is_int($wait) || \is_float($wait) ? max(1, (int) ceil($wait)) : 1;

        return \sprintf(self::TOO_MANY_TRIES, 1 === $minutes ? '1 minute' : $minutes.' minutes');
    }
}
