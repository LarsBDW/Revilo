<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\ProductModel;
use App\Services\CartService;
use App\Services\CatalogService;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function __construct(private CartService $carts, private CatalogService $catalog) {}

    public function show(Request $r)
    {
        $cart = $this->carts->current();
        $country = in_array($r->country, config('revilo.countries'), true) ? $r->country : 'NL';
        try {
            $totals = $this->carts->totals($cart, $country);
        } catch (ValidationException) {
            $cart->update(['coupon_code' => null]);
            $totals = $this->carts->totals($cart, $country);
            session()->flash('notice', __('shop.invalid_coupon'));
        }

        return view('store.cart', compact('cart', 'totals', 'country'));
    }

    public function add(Request $r, string $locale, ProductModel $product)
    {
        $data = $r->validate(['quantity' => 'required|integer|min:1|max:20']);
        $this->carts->add($product, $r->all(), $data['quantity']);

        return redirect()->route('cart')->with('success', __('shop.added_to_cart'));
    }

    public function update(Request $r, string $locale, CartItem $item)
    {
        abort_unless($item->cart_id === $this->carts->current()->id, 403);
        $d = $r->validate(['quantity' => 'required|integer|min:1|max:20']);
        $this->catalog->quote($item->model, $item->configuration, $d['quantity']);
        $item->update($d);
        $item->cart->touch();

        return back()->with('success', __('shop.cart_updated'));
    }

    public function remove(string $locale, CartItem $item)
    {
        abort_unless($item->cart_id === $this->carts->current()->id, 403);
        $cart = $item->cart;
        $item->delete();
        $cart->touch();

        return back();
    }

    public function coupon(Request $r)
    {
        $d = $r->validate(['code' => 'nullable|string|max:50']);
        $cart = $this->carts->current();
        if (! $d['code']) {
            $cart->update(['coupon_code' => null]);

            return back();
        }$code = strtoupper(trim($d['code']));
        $coupon = Coupon::where('code', $code)->first();
        if (! $coupon) {
            throw ValidationException::withMessages(['coupon' => __('shop.invalid_coupon')]);
        }$previous = $cart->coupon_code;
        $cart->update(['coupon_code' => $code]);
        try {
            $this->carts->totals($cart);
        } catch (\Throwable $e) {
            $cart->update(['coupon_code' => $previous]);
            throw $e;
        }

        return back()->with('success', __('shop.coupon_applied'));
    }

    public function reminder(Request $r, EmailService $emails)
    {
        $d = $r->validate(['email' => 'required|email|max:190', 'consent' => 'accepted']);
        $cart = $this->carts->current();
        abort_unless($cart->items()->exists(), 422);
        $cart->update(['email' => $d['email'], 'reminder_consent' => false, 'reminder_token' => Str::random(64)]);
        $url = URL::temporarySignedRoute('cart.reminders.confirm', now()->addDays(2), ['locale' => app()->getLocale(), 'token' => $cart->reminder_token]);
        $emails->record('cart-consent-'.$cart->id.'-'.$cart->reminder_token, $cart->email, __('shop.confirm_subscription'), __('shop.optin_email')."\n".$url, app()->getLocale());

        return back()->with('success', __('shop.subscription_notice'));
    }

    public function confirmReminder(string $locale, string $token)
    {
        $cart = Cart::where('reminder_token', $token)->firstOrFail();
        $cart->update(['reminder_consent' => true]);

        return redirect()->route('home')->with('success', __('shop.subscription_confirmed'));
    }

    public function stopReminder(string $locale, string $token)
    {
        Cart::where('reminder_token', $token)->firstOrFail()->update(['reminder_consent' => false]);

        return view('store.unsubscribe');
    }

    public function resume(string $locale, string $token)
    {
        $cart = Cart::where('reminder_token', $token)->firstOrFail();
        if ($cart->user_id && $cart->user_id !== auth()->id()) {
            return redirect()->route('login');
        }session(['cart_token' => $cart->token]);

        return redirect()->route('cart');
    }
}
