<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\ProductModel;
use App\Support\Pricing;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function __construct(private CatalogService $catalog) {}

    public function current(): Cart
    {
        $token = session('cart_token') ?? request()->cookie('revilo_cart');
        $cart = $token ? Cart::where('token', $token)->first() : null;
        if ($cart && $cart->user_id && $cart->user_id !== auth()->id()) {
            $cart = null;
        }
        if (auth()->check() && (! $cart || $cart->user_id !== auth()->id())) {
            $account = Cart::firstOrCreate(['user_id' => auth()->id()], ['token' => Str::random(64)]);
            if ($cart && ! $cart->user_id) {
                foreach ($cart->items as $item) {
                    $existing = $account->items()->firstOrCreate(['product_model_id' => $item->product_model_id, 'configuration_hash' => $item->configuration_hash], ['configuration' => $item->configuration, 'quantity' => 0]);
                    $existing->update(['quantity' => min(20, $existing->quantity + $item->quantity)]);
                }
                $cart->delete();
            }
            $cart = $account;
        }
        $cart ??= Cart::create(['token' => Str::random(64)]);
        session(['cart_token' => $cart->token]);
        Cookie::queue(cookie('revilo_cart', $cart->token, 43200, '/', null, request()->isSecure(), true, false, 'lax'));

        return $cart;
    }

    public function add(ProductModel $model, array $input, int $quantity): CartItem
    {
        $cart = $this->current();
        $c = $this->catalog->configuration($model, $input);
        $hash = hash('sha256', json_encode($c));

        return DB::transaction(function () use ($cart, $model, $c, $hash, $quantity) {
            Cart::whereKey($cart->id)->lockForUpdate()->first();
            $item = $cart->items()->where('product_model_id', $model->id)->where('configuration_hash', $hash)->first();
            $new = min(20, ($item?->quantity ?? 0) + $quantity);
            $this->catalog->quote($model, $c, $new);
            if ($item) {
                $item->update(['quantity' => $new]);
                $cart->touch();

                return $item;
            }
            $cart->touch();

            return $cart->items()->create(['product_model_id' => $model->id, 'configuration' => $c, 'configuration_hash' => $hash, 'quantity' => $new]);
        });
    }

    public function totals(Cart $cart, string $country = 'NL', bool $strict = false): array
    {
        $items = [];
        $subtotal = 0;
        $eligible = 0;
        $errors = [];
        $coupon = $cart->coupon_code ? Coupon::where('code', $cart->coupon_code)->first() : null;
        foreach ($cart->items()->with('model.units')->get() as $item) {
            try {
                if (! $item->model->published) {
                    throw ValidationException::withMessages(['cart' => __('shop.cart_changed')]);
                }$q = $this->catalog->quote($item->model, $item->configuration, $item->quantity);
                $subtotal += $q['total_cents'];
                if ($coupon && (! $coupon->product_model_id || $coupon->product_model_id === $item->product_model_id) && (! $coupon->category || $coupon->category === $item->model->category)) {
                    $eligible += $q['total_cents'];
                }
                $items[] = ['item' => $item, 'quote' => $q, 'error' => null];
            } catch (ValidationException $e) {
                $errors[] = $e->getMessage();
                $items[] = ['item' => $item, 'quote' => null, 'error' => __('shop.unavailable_configuration')];
            }
        }
        if (! $errors && $items) {
            try {
                $plans = app(InventoryService::class)->allocate(array_map(fn ($line) => ['id' => $line['item']->id, 'product_model_id' => $line['item']->product_model_id, 'configuration' => $line['item']->configuration, 'quantity' => $line['item']->quantity], $items), false);
                $subtotal = 0;
                $eligible = 0;
                foreach ($items as &$line) {
                    $assigned = array_values(array_filter($plans, fn ($p) => $p['line_id'] === $line['item']->id));
                    $line['quote']['unit_prices'] = array_column($assigned, 'price_cents');
                    $line['quote']['total_cents'] = array_sum($line['quote']['unit_prices']);
                    $line['quote']['price_cents'] = $assigned[0]['price_cents'];
                    $subtotal += $line['quote']['total_cents'];
                    if ($coupon && (! $coupon->product_model_id || $coupon->product_model_id === $line['item']->product_model_id) && (! $coupon->category || $coupon->category === $line['item']->model->category)) {
                        $eligible += $line['quote']['total_cents'];
                    }
                }unset($line);
            } catch (ValidationException) {
                $errors[] = __('shop.cart_changed');
                foreach ($items as &$line) {
                    $line['error'] = __('shop.cart_changed');
                }unset($line);
            }
        }
        if ($strict && $errors) {
            throw ValidationException::withMessages(['cart' => __('shop.cart_changed')]);
        }
        $discount = $coupon ? $this->couponDiscount($coupon, $subtotal, $eligible) : 0;
        $shipping = Pricing::shipping($subtotal - $discount, $country);
        if (! $items) {
            $shipping = 0;
        }

        return ['items' => $items, 'subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'shipping_cents' => $shipping, 'total_cents' => $subtotal - $discount + $shipping, 'errors' => $errors, 'coupon' => $coupon];
    }

    public function couponDiscount(Coupon $coupon, int $subtotal, int $eligible): int
    {
        if (! $coupon->active || $coupon->expires_at?->isPast() || $coupon->usage_limit !== null && $coupon->uses >= $coupon->usage_limit || $subtotal < $coupon->minimum_cents || ! $eligible) {
            throw ValidationException::withMessages(['coupon' => __('shop.invalid_coupon')]);
        }

        return min($eligible, $coupon->type === 'percent' ? intdiv($eligible * $coupon->value, 100) : $coupon->value);
    }
}
