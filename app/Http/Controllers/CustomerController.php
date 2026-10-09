<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use App\Models\ProductModel;
use App\Models\Review;
use App\Services\CatalogService;
use App\Support\DeliveryAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function account()
    {
        $user = auth()->user();
        $orders = $user->orders()->with('items')->latest()->paginate(10);
        $addresses = DB::table('customer_addresses')->where('user_id', $user->id)->get();

        return view('store.account', compact('user', 'orders', 'addresses'));
    }

    public function profile(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190|unique:users,email,'.auth()->id()]);
        $u = auth()->user();
        if ($d['email'] !== $u->email) {
            $u->email_verified_at = null;
            $u->google_id = null;
        }$u->fill($d)->save();
        if (! $u->hasVerifiedEmail()) {
            $u->sendEmailVerificationNotification();
        }

        return back()->with('success', __('shop.saved'));
    }

    public function address(Request $r)
    {
        $d = DeliveryAddress::normalize($r->validate(['label' => 'required|string|max:60'] + DeliveryAddress::rules()));
        DB::table('customer_addresses')->insert(['user_id' => auth()->id(), 'label' => $d['label'], 'address' => json_encode($d), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', __('shop.saved'));
    }

    public function removeAddress(string $locale, int $address)
    {
        $row = DB::table('customer_addresses')->find($address);
        abort_unless($row && $row->user_id === auth()->id(), 403);
        DB::table('customer_addresses')->where('id', $address)->delete();

        return back();
    }

    public function wishlist(Request $r, CatalogService $catalog)
    {
        $ids = auth()->check() ? DB::table('wishlists')->where('user_id', auth()->id())->pluck('product_model_id')->all() : array_slice(array_filter(explode(',', $r->input('ids', '')), fn ($id) => ctype_digit($id)), 0, 50);
        $products = ProductModel::where('published', true)->whereIn('id', $ids)->with('units')->get()->map(fn ($m) => $catalog->summary($m));

        return view('store.wishlist', compact('products'));
    }

    public function toggleWish(Request $r, string $locale, ProductModel $product)
    {
        abort_unless($product->published, 404);
        $q = DB::table('wishlists')->where('user_id', auth()->id())->where('product_model_id', $product->id);
        if ($q->exists()) {
            $q->delete();
            $saved = false;
        } else {
            DB::table('wishlists')->insert(['user_id' => auth()->id(), 'product_model_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);
            $saved = true;
        }

        return $r->expectsJson() ? response()->json(['saved' => $saved, 'count' => DB::table('wishlists')->where('user_id', auth()->id())->count()]) : back();
    }

    public function mergeWish(Request $r)
    {
        $d = $r->validate(['ids' => 'required|array|max:50', 'ids.*' => 'integer']);
        foreach (ProductModel::where('published', true)->whereIn('id', $d['ids'])->pluck('id') as $id) {
            DB::table('wishlists')->insertOrIgnore(['user_id' => auth()->id(), 'product_model_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['count' => DB::table('wishlists')->where('user_id', auth()->id())->count()]);
    }

    public function review(Request $r, string $locale, ProductModel $product)
    {
        $d = $r->validate(['rating' => 'required|integer|min:1|max:5', 'body' => 'required|string|min:10|max:2000']);
        $verified = OrderItem::where('product_model_id', $product->id)->whereHas('order', fn ($q) => $q->where('user_id', auth()->id())->where('is_test', false)->whereNotNull('paid_at'))->exists();
        Review::updateOrCreate(['user_id' => auth()->id(), 'product_model_id' => $product->id], array_merge($d, ['verified_purchase' => $verified, 'approved' => false]));

        return back()->with('success', __('shop.review_pending'));
    }

    public function returnRequest(Request $r, string $locale, OrderItem $item)
    {
        abort_unless($item->order->user_id === auth()->id(), 403);
        abort_unless(in_array($item->order->status, ['shipped', 'delivered'], true), 422);
        $d = $r->validate(['reason' => 'required|string|min:10|max:2000']);
        if (! DB::table('return_requests')->where('order_item_id', $item->id)->whereNotIn('status', ['rejected', 'closed'])->exists()) {
            DB::table('return_requests')->insert(['order_id' => $item->order_id, 'order_item_id' => $item->id, 'reason' => $d['reason'], 'created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('success', __('shop.return_received'));
    }

    public function reportReview(Request $r, string $locale, Review $review)
    {
        abort_unless($review->approved, 404);
        $d = $r->validate(['reason' => 'required|string|min:10|max:1000']);
        DB::table('review_reports')->updateOrInsert(['review_id' => $review->id, 'user_id' => auth()->id()], ['reason' => $d['reason'], 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', __('shop.saved'));
    }
}
