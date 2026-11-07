<?php

declare(strict_types=1);

namespace App\Web\UI\Controller;

use App\SharedKernel\Infrastructure\Health\ReadinessCheck;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness and readiness for the container healthcheck and the load balancer (ADR-0029), on internal networks only.
 * The body says ok or fail and nothing else; which check failed goes to the log. Neither route opens the request
 * transaction or a session, so both answer while PostgreSQL or Valkey is down.
 */
#[AsController]
final readonly class HealthController
{
    /**
     * @param iterable<ReadinessCheck> $readinessChecks
     */
    public function __construct(
        #[AutowireIterator(ReadinessCheck::class)]
        private iterable $readinessChecks,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/health/live', name: 'health_live', methods: ['GET'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function live(Request $request): JsonResponse
    {
        self::refuseCallersOutsideInternalNetworks($request);

        return self::status(true);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function ready(Request $request): JsonResponse
    {
        self::refuseCallersOutsideInternalNetworks($request);

        // The first failure answers: the checks after it may need what failed (the database), and each could wait
        // its own timeout.
        foreach ($this->readinessChecks as $readinessCheck) {
            try {
                $readinessCheck->verify();
            } catch (\Throwable $failure) {
                // The check's name and the failure's class and codes only: a message can quote a host or a DSN.
                $this->logger->warning('A readiness check failed.', [
                    'check' => $readinessCheck->name(),
                    'exception_class' => $failure::class,
                    'sqlstate' => $failure instanceof DriverException ? $failure->getSQLState() : null,
                    'code' => $failure->getCode(),
                ]);

                return self::status(false);
            }
        }

        return self::status(true);
    }

    /**
     * Answers as a missing route would. PRIVATE_RANGES, not PRIVATE_SUBNETS: the latter also holds documentation
     * and IPv4-mapped ranges that a remote client can present as its own (see IpUtils). Behind a reverse proxy the
     * client IP is the proxy's until trusted proxies are configured.
     */
    private static function refuseCallersOutsideInternalNetworks(Request $request): void
    {
        $clientIp = $request->getClientIp();
        if (null === $clientIp || !IpUtils::checkIp($clientIp, IpUtils::PRIVATE_RANGES)) {
            throw new NotFoundHttpException(\sprintf('No route found for "%s %s"', $request->getMethod(), $request->getUriForPath($request->getPathInfo())));
        }
    }

    private static function status(bool $isOk): JsonResponse
    {
        $response = new JsonResponse(['status' => $isOk ? 'ok' : 'fail'], $isOk ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
