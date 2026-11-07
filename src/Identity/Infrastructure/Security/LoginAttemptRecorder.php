<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\LoginAttempts;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Writes one idn_login_attempt row for each try on the login form: wrong password, unknown email, blocked user, wrong
 * CSRF token and throttled try as a failure, a login as a success.
 *
 * The firewall runs before RequestTransaction begins, so the row is committed on its own and a failed try, which
 * only redirects, still keeps it. A database failure writing it is logged and swallowed (a coding error is not): the person sees the same page, and
 * the throttle, not this history, is what limits the tries.
 */
final readonly class LoginAttemptRecorder
{
    private const string LOGIN_ROUTE = 'identity_login';

    public function __construct(
        private LoginAttempts $loginAttempts,
        private ThrottleKeyHasher $keyHasher,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->record($event->getRequest(), true);
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $this->record($event->getRequest(), false);
    }

    private function record(Request $request, bool $success): void
    {
        if (self::LOGIN_ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $email = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME);
        try {
            $this->loginAttempts->record(
                $this->keyHasher->bytesOf(ThrottleKeyHasher::normalizeEmail(\is_string($email) ? $email : '')),
                $this->keyHasher->bytesOf((string) $request->getClientIp()),
                $success,
            );
        } catch (DbalException $failure) {
            // Class and code only: a database message can quote row values.
            $this->logger->warning('Recording a login try failed.', [
                'exception_class' => $failure::class,
                'sqlstate' => $failure instanceof DriverException ? $failure->getSQLState() : null,
                'code' => $failure->getCode(),
            ]);
        }
    }
}
