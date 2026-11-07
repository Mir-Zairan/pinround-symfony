<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\LoginAttempts;
use App\Identity\Infrastructure\Security\LoginAttemptRecorder;
use App\Identity\Infrastructure\Security\ThrottleKeyHasher;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\ConnectionLost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class LoginAttemptRecorderTest extends TestCase
{
    private const string SECRET = 'the-app-secret';
    private const string IP = '10.1.2.3';

    /** @var list<array{string, string, bool}> */
    private array $recorded = [];

    /** @var list<array{string, array<mixed>}> */
    private array $warnings = [];

    private LoginAttemptRecorder $recorder;

    protected function setUp(): void
    {
        $this->recorder = $this->recorderOver(function (string $emailHash, string $ipHash, bool $success): void {
            $this->recorded[] = [$emailHash, $ipHash, $success];
        });
    }

    #[Test]
    public function aFailureIsRecordedWithKeyedHashesOfTheNormalizedEmailAndTheIp(): void
    {
        $this->recorder->onLoginFailure($this->failure(self::loginRequest('  Omar@Acme-Foods.Example ')));

        $hasher = new ThrottleKeyHasher(self::SECRET);
        self::assertSame([[$hasher->bytesOf('omar@acme-foods.example'), $hasher->bytesOf(self::IP), false]], $this->recorded);
        self::assertSame(32, \strlen($this->recorded[0][0]));
        self::assertNotSame('omar@acme-foods.example', $this->recorded[0][0]);
        self::assertNotSame(self::IP, $this->recorded[0][1]);
    }

    #[Test]
    public function aSuccessIsRecordedAsASuccess(): void
    {
        $this->recorder->onLoginSuccess($this->success(self::loginRequest('omar@acme-foods.example')));

        self::assertCount(1, $this->recorded);
        self::assertTrue($this->recorded[0][2]);
    }

    #[Test]
    public function differentLetterCasesOfOneEmailGiveOneHash(): void
    {
        $this->recorder->onLoginFailure($this->failure(self::loginRequest('Omar@Acme-Foods.Example')));
        $this->recorder->onLoginFailure($this->failure(self::loginRequest('omar@acme-foods.example')));

        self::assertSame($this->recorded[0][0], $this->recorded[1][0]);
    }

    #[Test]
    public function aTryWithoutAnEmailIsStillRecordedAndAnotherEmailHashesDifferently(): void
    {
        $this->recorder->onLoginFailure($this->failure(new Request(attributes: ['_route' => 'identity_login'])));
        $this->recorder->onLoginFailure($this->failure(self::loginRequest('mia@acme-foods.example')));

        self::assertCount(2, $this->recorded);
        self::assertNotSame($this->recorded[0][0], $this->recorded[1][0]);
    }

    #[Test]
    public function aTryOnAnotherRouteIsNotRecorded(): void
    {
        $request = new Request(attributes: ['_route' => 'web_home', SecurityRequestAttributes::LAST_USERNAME => 'omar@acme-foods.example']);

        $this->recorder->onLoginFailure($this->failure($request));
        $this->recorder->onLoginSuccess($this->success($request));

        self::assertSame([], $this->recorded);
    }

    #[Test]
    public function aFailingWriteIsLoggedWithoutItsMessageAndDoesNotReachTheLoginPage(): void
    {
        $recorder = $this->recorderOver(static function (): void {
            throw new ConnectionLost(new class('SECRET omar@acme-foods.example', '08006', 7) extends \Exception implements DriverExceptionInterface {
                public function __construct(string $message, private readonly string $sqlState, int $code)
                {
                    parent::__construct($message, $code);
                } public function getSQLState(): string
                {
                    return $this->sqlState;
                }
            }, null);
        });

        $recorder->onLoginFailure($this->failure(self::loginRequest('omar@acme-foods.example')));

        self::assertCount(1, $this->warnings);
        self::assertSame(ConnectionLost::class, $this->warnings[0][1]['exception_class']);
        self::assertSame('08006', $this->warnings[0][1]['sqlstate']);
        self::assertSame(7, $this->warnings[0][1]['code']);
        self::assertStringNotContainsString('omar', json_encode($this->warnings, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('SECRET', json_encode($this->warnings, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aCodingErrorWhileWritingIsNotSwallowed(): void
    {
        $recorder = $this->recorderOver(static function (): void {
            throw new \LogicException('a bug');
        });

        $this->expectException(\LogicException::class);

        try {
            $recorder->onLoginFailure($this->failure(self::loginRequest('omar@acme-foods.example')));
        } finally {
            self::assertSame([], $this->warnings);
        }
    }

    /**
     * @param \Closure(string, string, bool): void $onRecord
     */
    private function recorderOver(\Closure $onRecord): LoginAttemptRecorder
    {
        $attempts = new readonly class($onRecord) implements LoginAttempts {
            public function __construct(private \Closure $onRecord)
            {
            }

            public function record(string $emailHash, string $ipHash, bool $success): void
            {
                ($this->onRecord)($emailHash, $ipHash, $success);
            }
        };
        $logger = new class(function (string $message, array $context): void {
            $this->warnings[] = [$message, $context];
        }) extends AbstractLogger {
            /** @param \Closure(string, array<mixed>): void $onLog */
            public function __construct(private readonly \Closure $onLog)
            {
            }

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                ($this->onLog)((string) $message, $context);
            }
        };

        return new LoginAttemptRecorder($attempts, new ThrottleKeyHasher(self::SECRET), $logger);
    }

    private static function loginRequest(string $email): Request
    {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => self::IP]);
        $request->attributes->set('_route', 'identity_login');
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return $request;
    }

    private function failure(Request $request): LoginFailureEvent
    {
        return new LoginFailureEvent(new BadCredentialsException(), self::createStub(AuthenticatorInterface::class), $request, null, 'main');
    }

    private function success(Request $request): LoginSuccessEvent
    {
        $passport = new SelfValidatingPassport(new UserBadge('omar@acme-foods.example', static fn () => null));

        return new LoginSuccessEvent(self::createStub(AuthenticatorInterface::class), $passport, self::createStub(TokenInterface::class), $request, null, 'main');
    }
}
