<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\InventoryUnit;
use App\Models\Order;
use App\Models\ProductModel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }

    public function test_six_simultaneous_buyers_reserve_one_physical_laptop_only_once(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrency test requires MySQL, not SQLite.');
        }
        $this->assertStringEndsWith('_testing', DB::connection()->getDatabaseName());
        $p = ProductModel::create(['slug' => 'race-laptop', 'brand' => 'Dell', 'name' => 'Race laptop', 'description' => [], 'category' => 'business', 'images' => [], 'supported_ram' => [8, 16], 'supported_ssd' => [256], 'operating_systems' => ['Windows 11 Pro'], 'specifications' => [], 'published' => true]);
        $u = InventoryUnit::create(['product_model_id' => $p->id, 'inventory_id' => 'RACE-UNIT', 'processor' => 'i5', 'processor_family' => 'i5', 'processor_generation' => 8, 'ram_gb' => 8, 'ssd_gb' => 256, 'screen_inches' => 14, 'resolution' => 'Full HD', 'keyboard_layout' => 'QWERTY', 'grade' => 'A', 'purchase_cents' => 4000, 'selling_cents' => 13900, 'status' => 'available']);
        $part = Component::create(['sku' => 'RACE-RAM', 'name' => '16 GB RAM', 'type' => 'ram', 'capacity' => 16, 'compatible_models' => [$p->id], 'quantity' => 1, 'cost_cents' => 1000, 'upgrade_cents' => 2500]);
        $gate = storage_path('app/private/race-'.Str::random(12));
        $processes = [];
        $db = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'];
        try {
            for ($i = 0; $i < 6; $i++) {
                $o = Order::create(['public_id' => Str::uuid(), 'number' => 'RACE-'.$i, 'idempotency_key' => Str::uuid(), 'email' => 'race'.$i.'@example.test', 'name' => 'Race', 'shipping_address' => [], 'subtotal_cents' => 16400, 'total_cents' => 16400, 'reservation_expires_at' => now()->addMinutes(30)]);
                $worker = new Process([PHP_BINARY, base_path('scripts/reservation-worker.php'), (string) $p->id, (string) $o->id, $gate], base_path(), $env);
                $worker->setTimeout(25);
                $worker->start();
                $processes[] = $worker;
            }
            $deadline = microtime(true) + 15;
            do {
                $ready = count(array_filter($processes, fn ($w) => str_contains($w->getOutput(), 'READY')));
                if ($ready === 6) {
                    break;
                }usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(6, $ready, 'All independent MySQL workers must reach the shared start gate.');
            file_put_contents($gate, 'go');
            $won = 0;
            $unavailable = 0;
            foreach ($processes as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $output = $worker->getOutput();
                $won += substr_count($output, 'RESERVED');
                $unavailable += substr_count($output, 'UNAVAILABLE');
            }
            $this->assertSame(1, $won);
            $this->assertSame(5, $unavailable);
            $this->assertSame('reserved', $u->fresh()->status);
            $this->assertSame(1, $part->fresh()->reserved);
            $this->assertSame(1, DB::table('inventory_reservations')->where('status', 'active')->count());
        } finally {
            foreach ($processes as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }if (is_file($gate)) {
                unlink($gate);
            }
        }
    }
}
