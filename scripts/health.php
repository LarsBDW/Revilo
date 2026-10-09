<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    DB::select('SELECT 1');
    echo "Configured MySQL connection is ready.\n";
} catch (Throwable) {
    fwrite(STDERR, "Cannot connect to the configured project database.\n");
    exit(1);
}
