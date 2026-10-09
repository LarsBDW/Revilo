<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CartService;
use App\Services\OrderService;
use App\Support\DeliveryAddress;
use App\Support\Pricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function __construct(private CartService $carts, private OrderService $orders) {}

    public function show(Request $r)
    {
        abort_unless(config('revilo.test_checkout') && app()->environment(['local', 'testing']), 403);
        $cart = $this->carts->current();
        $addresses = $r->user()
            ? DB::table('customer_addresses')->where('user_id', $r->user()->id)->orderBy('id')->get()->map(fn ($row) => ['id' => $row->id, 'label' => $row->label] + json_decode($row->address, true))
            : collect();
        $selectedAddress = $addresses->firstWhere('id', (int) old('selected_address_id', session('checkout_address_id', 0)));
        $defaults = ['name' => $r->user()?->name, 'email' => $r->user()?->email] + ($selectedAddress ?? []);
        $country = old('country', $defaults['country'] ?? $r->query('country', 'NL'));
        $country = in_array($country, config('revilo.countries'), true) ? $country : 'NL';
        $totals = $this->carts->totals($cart, $country, true);
        if (! $totals['items']) {
            return redirect()->route('cart');
        }
        $key = session('checkout_key', Str::random(64));
        session(['checkout_key' => $key, 'checkout_review' => ['subtotal' => $totals['subtotal_cents'], 'discount' => $totals['discount_cents'], 'shipping_nl' => Pricing::delivery('NL'), 'shipping_be' => Pricing::delivery('BE'), 'shipping_de' => Pricing::delivery('DE'), 'threshold' => Pricing::threshold()]]);

        return view('store.checkout', compact('totals', 'key', 'addresses', 'selectedAddress', 'defaults', 'country'));
    }

    public function selectAddress(Request $r)
    {
        abort_unless(config('revilo.test_checkout') && app()->environment(['local', 'testing']), 403);
        $data = $r->validate(['address_id' => 'required|integer|min:0']);
        $contact = $r->only(['name', 'email']);
        if ($data['address_id'] === 0 || $data['address_id'] === '0') {
            $r->session()->forget('checkout_address_id');
            $country = in_array($r->input('country'), config('revilo.countries'), true) ? $r->input('country') : 'NL';

            return redirect()->route('checkout')->withInput($contact + ['selected_address_id' => '', 'street' => '', 'house_number' => '', 'postal_code' => '', 'city' => '', 'country' => $country]);
        }
        $address = DB::table('customer_addresses')->where('user_id', $r->user()->id)->find($data['address_id']);
        abort_unless($address, 403);
        $r->session()->put('checkout_address_id', $address->id);

        return redirect()->route('checkout')->withInput($contact + ['selected_address_id' => $address->id] + json_decode($address->address, true));
    }

    public function create(Request $r)
    {
        $d = DeliveryAddress::normalize($r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190', 'terms' => 'accepted', 'test_acknowledgement' => 'accepted', 'idempotency_key' => 'required|string|size:64', 'selected_address_id' => 'nullable|integer|min:1'] + DeliveryAddress::rules()));
        abort_unless(hash_equals(session('checkout_key', ''), $d['idempotency_key']), 419);
        if (! empty($d['selected_address_id'])) {
            abort_unless($r->user() && DB::table('customer_addresses')->where('user_id', $r->user()->id)->where('id', $d['selected_address_id'])->exists(), 403);
        }
        $order = $this->orders->checkout($this->carts->current(), $d, $d['idempotency_key'], session('checkout_review'));
        $r->session()->forget('checkout_address_id');

        return redirect(URL::temporarySignedRoute('orders.show', now()->addDays(7), ['locale' => app()->getLocale(), 'order' => $order]));
    }

    public function order(Request $r, string $locale, Order $order)
    {
        abort_unless($r->hasValidSignature() || auth()->id() && $order->user_id === auth()->id() || auth()->user()?->isAdmin(), 403);
        $order->load('items');
        $history = DB::table('order_history')->where('order_id', $order->id)->orderBy('id')->get();

        return view('store.order', compact('order', 'history'));
    }

    public function invoice(Request $r, string $locale, Order $order)
    {
        abort_unless($r->hasValidSignature() || auth()->id() && $order->user_id === auth()->id() || auth()->user()?->isAdmin(), 403);
        $order->load('items');

        return response()->view('store.invoice', compact('order'))->header('Content-Disposition', 'inline; filename="'.$order->number.'.html"');
    }
}
