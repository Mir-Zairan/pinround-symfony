<?php

declare(strict_types=1);

$setLocal = 'SET LOCAL '.TenantSetting::NAME." = '1'";
$reset = 'RESET '.TenantSetting::NAME;
$setQuoted = 'SET "'.TenantSetting::NAME.'" = 1';
