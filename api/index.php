<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Core\Request;

$router = require __DIR__ . '/routes/api.php';
$router->dispatch(new Request());
