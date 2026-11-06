<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * @internal built by TenantMiddleware
 */
final class TenantDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $wrappedDriver, private readonly TenantContext $tenantContext)
    {
        parent::__construct($wrappedDriver);
    }

    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): Connection {
        return new TenantConnection(parent::connect($params), $this->tenantContext);
    }
}
