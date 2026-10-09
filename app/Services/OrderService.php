<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Component;
use App\Models\Coupon;
use App\Models\InventoryUnit;
use App\Models\Order;
use App\Support\Money;
use App\Support\Pricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(private InventoryService $inventory, private CartService $carts, private EmailService $emails) {}

    public function checkout(Cart $cart, array $customer, string $idempotency, ?array $reviewed = null): Order
    {
        abort_unless(config('revilo.test_checkout') && app()->environment(['local', 'testing']), 403);

        return DB::transaction(function () use ($cart, $customer, $idempotency, $reviewed) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $existing = Order::where('idempotency_key', $idempotency)->first();
            if ($existing) {
                abort_unless($existing->user_id === auth()->id() && $existing->email === $customer['email'], 403);

                return $existing;
            }
            $lines = $cart->items()->get()->map(fn ($i) => ['id' => $i->id, 'product_model_id' => $i->product_model_id, 'configuration' => $i->configuration, 'quantity' => $i->quantity])->all();
            if (! $lines) {
                throw ValidationException::withMessages(['cart' => __('shop.empty_cart')]);
            }
            $plans = $this->inventory->allocate($lines);
            $subtotal = array_sum(array_column($plans, 'price_cents'));
            $discount = 0;
            $coupon = null;
            if ($cart->coupon_code) {
                $coupon = Coupon::where('code', $cart->coupon_code)->lockForUpdate()->first();
                if (! $coupon) {
                    throw ValidationException::withMessages(['coupon' => __('shop.invalid_coupon')]);
                }
                $eligible = array_sum(array_map(fn ($p) => (! $coupon->product_model_id || $coupon->product_model_id === $p['unit']->product_model_id) && (! $coupon->category || $coupon->category === $p['unit']->model->category) ? $p['price_cents'] : 0, $plans));
                $discount = $this->carts->couponDiscount($coupon, $subtotal, $eligible);
            }
            $shipping = Pricing::shipping($subtotal - $discount, $customer['country']);
            if ($reviewed && ($reviewed['subtotal'] !== $subtotal || $reviewed['discount'] !== $discount || $reviewed['shipping_nl'] !== Pricing::delivery('NL') || $reviewed['shipping_be'] !== Pricing::delivery('BE') || ($reviewed['shipping_de'] ?? null) !== Pricing::delivery('DE') || $reviewed['threshold'] !== Pricing::threshold())) {
                throw ValidationException::withMessages(['cart' => __('shop.cart_changed')]);
            }
            if ($coupon) {
                $coupon->increment('uses');
            }
            $order = Order::create(['public_id' => Str::uuid(), 'number' => 'RV-'.now()->format('ymd').'-'.strtoupper(Str::random(6)),
                'idempotency_key' => $idempotency, 'user_id' => auth()->id(), 'email' => $customer['email'], 'name' => $customer['name'],
                'shipping_address' => array_intersect_key($customer, array_flip(['street', 'house_number', 'postal_code', 'city', 'country'])),
                'locale' => app()->getLocale(), 'subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'shipping_cents' => $shipping,
                'total_cents' => $subtotal - $discount + $shipping, 'is_test' => true, 'coupon_code' => $coupon?->code, 'reservation_expires_at' => now()->addMinutes(config('revilo.reservation_minutes'))]);
            $leftDiscount = $discount;
            $leftEligible = $eligible ?? $subtotal;
            $vat = Money::vat($shipping, 'standard');
            foreach ($plans as $index => $p) {
                $u = $p['unit'];
                $m = $u->model;
                $isEligible = ! $coupon || (! $coupon->product_model_id || $coupon->product_model_id === $m->id) && (! $coupon->category || $coupon->category === $m->category);
                $partDiscount = $isEligible && $leftEligible > 0 ? min($leftDiscount, intdiv($leftDiscount * $p['price_cents'], $leftEligible)) : 0;
                if ($isEligible) {
                    $leftDiscount -= $partDiscount;
                    $leftEligible -= $p['price_cents'];
                }
                $itemVat = Money::vat($p['price_cents'] - $partDiscount, $u->vat_treatment, $u->purchase_cents);
                $vat += $itemVat;
                $order->items()->create(['inventory_unit_id' => $u->id, 'product_model_id' => $m->id, 'snapshot' => [
                    'name' => $m->brand.' '.$m->name, 'image' => $m->image(), 'configuration' => $p['configuration'], 'processor' => $u->processor, 'battery_health' => $u->battery_health,
                    'defects' => $u->defects, 'vat_treatment' => $u->vat_treatment, 'breakdown' => $p['breakdown'], 'accessories' => $u->accessories ?? []],
                    'components' => $p['components'], 'price_cents' => $p['price_cents'], 'purchase_cents' => $u->purchase_cents,
                    'refurbishment_cents' => $u->refurbishment_cents, 'upgrade_cost_cents' => $p['upgrade_cost_cents'], 'discount_cents' => $partDiscount, 'vat_cents' => $itemVat]);
            }
            $order->update(['vat_cents' => $vat]);
            $this->inventory->reserve($order, $plans);
            DB::table('payments')->insert(['order_id' => $order->id, 'provider' => 'test', 'status' => 'simulated_pending', 'amount_cents' => $order->total_cents, 'provider_confirmed' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('invoices')->insert(['order_id' => $order->id, 'number' => 'PROFORMA-'.$order->number, 'snapshot' => json_encode(['name' => $order->name, 'address' => $order->shipping_address, 'total_cents' => $order->total_cents, 'is_test' => true]), 'is_proforma' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('order_history')->insert(['order_id' => $order->id, 'status' => 'awaiting_payment', 'note' => 'TEST order; no real payment collected.', 'created_at' => now(), 'updated_at' => now()]);
            $cart->items()->delete();
            $cart->update(['coupon_code' => null]);
            $link = URL::temporarySignedRoute('orders.show', now()->addDays(7), ['locale' => $order->locale, 'order' => $order->public_id]);
            $this->emails->record('order-'.$order->public_id, $order->email, __('shop.order_received'), __('shop.test_order_email', ['number' => $order->number])."\n".$link, $order->locale);

            return $order;
        }, 5);
    }

    public function transition(Order $order, string $next, array $details = []): void
    {
        $allowed = ['awaiting_payment' => ['cancelled', 'processing'], 'processing' => ['preparing_shipment'], 'preparing_shipment' => ['shipped'], 'shipped' => ['delivered'], 'delivered' => ['returned'], 'returned' => ['refunded']];
        DB::transaction(function () use ($order, $next, $details, $allowed) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($next, $allowed[$order->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('shop.invalid_transition')]);
            }
            if ($order->is_test && ! app()->environment(['local', 'testing'])) {
                throw ValidationException::withMessages(['status' => 'Test order processing is disabled in production.']);
            }
            if ($next === 'cancelled') {
                $this->inventory->release($order, 'Order cancelled');
            }
            if ($next === 'processing') {
                // Only local test orders can be simulated here. Real payment confirmation belongs to a verified provider webhook.
                if (! $order->is_test) {
                    throw ValidationException::withMessages(['status' => 'Provider-confirmed payment required.']);
                }
                if ($order->reservation_expires_at?->isPast()) {
                    throw ValidationException::withMessages(['status' => __('shop.reservation_expired')]);
                }
                $reservations = $order->reservations()->where('status', 'active')->orderBy('inventory_unit_id')->get();
                $units = InventoryUnit::whereIn('id', $reservations->pluck('inventory_unit_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                Component::whereIn('id', $reservations->flatMap(fn ($r) => $r->components)->unique())->orderBy('id')->lockForUpdate()->get();
                foreach ($reservations as $r) {
                    $this->inventory->move($units[$r->inventory_unit_id], 'sold', 'Local TEST fulfillment '.$order->number);
                    foreach ($r->components as $id) {
                        Component::whereKey($id)->decrementEach(['quantity' => 1, 'reserved' => 1]);
                    }
                    $r->update(['status' => 'consumed']);
                }
                DB::table('payments')->where('order_id', $order->id)->update(['status' => 'simulated_complete']);
            }
            $order->update(array_merge(['status' => $next], array_intersect_key($details, array_flip(['carrier', 'tracking_number', 'tracking_url', 'internal_notes']))));
            DB::table('order_history')->insert(['order_id' => $order->id, 'user_id' => auth()->id(), 'status' => $next, 'note' => $order->is_test ? 'TEST workflow' : null, 'created_at' => now(), 'updated_at' => now()]);
            if ($next === 'shipped') {
                $this->emails->record('shipment-'.$order->public_id, $order->email, __('shop.shipped'), __('shop.shipment_email', ['number' => $order->number])."\n".($order->tracking_url ?? ''), $order->locale);
            }
        }, 5);
    }
}
