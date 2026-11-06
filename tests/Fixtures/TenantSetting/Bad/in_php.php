<?php

declare(strict_types=1);

$sql = "SELECT set_config('".TenantSetting::NAME."', ?, false)";
