<?php

declare(strict_types=1);

$sql = 'SELECT set_config(\'app.org_id\', ?, true)';
$set = "SET LOCAL \"app.org_id\" = '1'";
