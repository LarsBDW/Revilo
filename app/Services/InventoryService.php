<?php

namespace App\Services;

use App\Models\Component;
use App\Models\Coupon;
use App\Models\InventoryHistory;
use App\Models\InventoryReservation;
use App\Models\InventoryUnit;
use App\Models\Order;
use App\Models\ProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(private CatalogService $catalog) {}

    /** Caller must hold a transaction. Always lock physical units, then components, in ID order. */
    public function allocate(array $lines, bool $lock = true): array
    {
        $modelIds = array_values(array_unique(array_column($lines, 'product_model_id')));
        sort($modelIds);
        $models = ProductModel::whereIn('id', $modelIds)->where('published', true)->get()->keyBy('id');
        $unitQuery = InventoryUnit::whereIn('product_model_id', $modelIds)->where('status', 'available')->orderBy('id')->with('model');
        if ($lock) {
            $unitQuery->lockForUpdate();
        }$units = $unitQuery->get();
        $partQuery = Component::orderBy('id');
        if ($lock) {
            $partQuery->lockForUpdate();
        }$components = $partQuery->get();
        $remaining = $components->mapWithKeys(fn ($p) => [$p->id => $p->available()])->all();
        $selected = [];
        $demands = [];
        foreach ($lines as $line) {
            $model = $models->get($line['product_model_id']);
            if (! $model) {
                throw ValidationException::withMessages(['cart' => __('shop.cart_changed')]);
            }
            $c = $this->catalog->configuration($model, $line['configuration']);
            for ($i = 0; $i < $line['quantity']; $i++) {
                $demands[] = ['model_id' => $model->id, 'configuration' => $c, 'line_id' => $line['id'] ?? null];
            }
        }
        if (count($demands) > 50) {
            throw ValidationException::withMessages(['cart' => 'Maximum 50 laptops per online order. Please use the business request form.']);
        }
        $visited = 0;
        $selected = $this->solve($demands, $units, $components, $remaining, $visited);
        if ($selected === null) {
            throw ValidationException::withMessages(['cart' => __('shop.unavailable_configuration')]);
        }

        return $selected;
    }

    /** Assign constrained configurations first, then backtrack physical-unit choices. */
    private function solve(array $demands, $units, $components, array $remaining, int &$visited): ?array
    {
        if (! $demands) {
            return [];
        }if (++$visited > 10000) {
            return null;
        }
        $bestIndex = null;
        $bestChoices = null;
        foreach ($demands as $index => $d) {
            $choices = $units->where('product_model_id', $d['model_id'])->map(fn ($u) => $this->catalog->candidate($u, $d['configuration'], $components, $remaining))->filter()->sortBy('price_cents')->values();
            if ($choices->isEmpty()) {
                return null;
            }
            if ($bestChoices === null || $choices->count() < $bestChoices->count()) {
                $bestIndex = $index;
                $bestChoices = $choices;
            }
        }
        $d = $demands[$bestIndex];
        unset($demands[$bestIndex]);
        foreach ($bestChoices as $plan) {
            $stock = $remaining;
            foreach ($plan['components'] as $id) {
                $stock[$id]--;
            }
            $tail = $this->solve($demands, $units->reject(fn ($u) => $u->id === $plan['unit']->id), $components, $stock, $visited);
            if ($tail !== null) {
                $plan['configuration'] = $d['configuration'];
                $plan['line_id'] = $d['line_id'];

                return array_merge([$plan], $tail);
            }
        }

        return null;
    }

    public function reserve(Order $order, array $plans): void
    {
        foreach ($plans as $plan) {
            $unit = $plan['unit'];
            if ($unit->status !== 'available') {
                throw ValidationException::withMessages(['cart' => __('shop.unavailable_configuration')]);
            }
            $this->move($unit, 'reserved', 'Reservation for '.$order->number);
            foreach ($plan['components'] as $id) {
                $changed = Component::whereKey($id)->whereRaw('quantity > reserved')->increment('reserved');
                if (! $changed) {
                    throw ValidationException::withMessages(['cart' => __('shop.unavailable_configuration')]);
                }
            }
            InventoryReservation::create(['order_id' => $order->id, 'inventory_unit_id' => $unit->id, 'components' => $plan['components'], 'expires_at' => $order->reservation_expires_at]);
        }
    }

    public function release(Order $order, string $reason = 'Reservation released'): void
    {
        DB::transaction(function () use ($order, $reason) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $reservations = $order->reservations()->where('status', 'active')->orderBy('inventory_unit_id')->get();
            $units = InventoryUnit::whereIn('id', $reservations->pluck('inventory_unit_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $partIds = $reservations->flatMap(fn ($r) => $r->components)->unique()->sort()->values();
            Component::whereIn('id', $partIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($reservations as $r) {
                $unit = $units[$r->inventory_unit_id];
                if ($unit->status === 'reserved') {
                    $this->move($unit, 'available', $reason);
                }
                foreach ($r->components as $id) {
                    Component::whereKey($id)->where('reserved', '>', 0)->decrement('reserved');
                }
                $r->update(['status' => 'released']);
            }
            if ($order->status === 'awaiting_payment') {
                if ($order->coupon_code) {
                    Coupon::where('code', $order->coupon_code)->where('uses', '>', 0)->decrement('uses');
                }
                $order->update(['status' => 'cancelled']);
                DB::table('payments')->where('order_id', $order->id)->where('provider_confirmed', false)->update(['status' => 'cancelled']);
                DB::table('order_history')->insert(['order_id' => $order->id, 'status' => 'cancelled', 'note' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            }
        }, 5);
    }

    public function expire(): int
    {
        $orders = Order::where('status', 'awaiting_payment')->where('reservation_expires_at', '<=', now())->get();
        foreach ($orders as $order) {
            $this->release($order, 'Reservation expired');
        }

        return $orders->count();
    }

    public function move(InventoryUnit $unit, string $status, string $note): void
    {
        $previous = $unit->status;
        $unit->update(['status' => $status]);
        InventoryHistory::create(['inventory_unit_id' => $unit->id, 'user_id' => auth()->id(), 'from_status' => $previous, 'to_status' => $status, 'note' => $note]);
    }
}
