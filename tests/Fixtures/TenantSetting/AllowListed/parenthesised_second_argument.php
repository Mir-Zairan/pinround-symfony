<?php

declare(strict_types=1);

// Runs set_config('app.org_id', …) after BEGIN.
$list = implode(', ', ['a', 'b']);
$sql = "SELECT set_config('app.org_id', CAST(:org AS text), false)";
