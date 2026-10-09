<?php

use App\Models\Order;
use App\Services\InventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Used only by the independent-process MySQL concurrency test.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
    fwrite(STDERR, 'Testing database required.');
    exit(1);
}
[$script,$modelId,$orderId,$gate] = $argv;
echo "READY\n";
flush();
$deadline = microtime(true) + 20;
while (! is_file($gate)) {
    if (microtime(true) > $deadline) {
        exit(2);
    }usleep(20000);
}
try {
    DB::transaction(function () use ($app, $modelId, $orderId) {
        $service = $app->make(InventoryService::class);
        $order = Order::findOrFail($orderId);
        $plans = $service->allocate([['product_model_id' => (int) $modelId, 'configuration' => ['ram_gb' => 16, 'ssd_gb' => 256, 'grade' => 'A', 'processor' => 'i5', 'operating_system' => 'Windows 11 Pro'], 'quantity' => 1]]);
        usleep(200000);
        $service->reserve($order, $plans);
    }, 5);
    echo "RESERVED\n";
} catch (ValidationException) {
    echo "UNAVAILABLE\n";
}
