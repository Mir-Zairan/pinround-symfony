<?php

declare(strict_types=1);

namespace App\Audit\Api;

final class AuditEntryForAnotherTenantRefused extends \LogicException
{
    public function __construct()
    {
        parent::__construct('An audit entry for another organization cannot be recorded while this organization is set.');
    }
}
