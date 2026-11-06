<?php

declare(strict_types=1);

$notTransactionLocal = 'SELECT set_config(\''.TenantSetting::NAME.'\', ?, false)';
$outsideMiddleware = 'SELECT set_config(\''.TenantSetting::NAME.'\', ?, true)';
$set = 'SET LOCAL \''.TenantSetting::NAME.'\' = 1';
$reset = 'RESET \''.self::NAME;
