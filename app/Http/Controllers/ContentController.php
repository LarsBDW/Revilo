<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContentController extends Controller
{
    public function page(Request $r)
    {
        $page = $r->route()->getName();

        return view('store.page', compact('page'));
    }

    public function contact(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190', 'message' => 'required|string|min:10|max:5000', 'privacy' => 'accepted', 'website' => 'nullable|max:0', 'type' => 'nullable|in:trade-in']);
        DB::table('inquiries')->insert(['type' => $d['type'] ?? 'contact', 'name' => $d['name'], 'email' => $d['email'], 'details' => json_encode(['message' => $d['message']]), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', __('shop.contact_received'));
    }

    public function business(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190', 'organization' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'country' => ['required', Rule::in(config('revilo.countries'))], 'quantity' => 'required|integer|min:1|max:5000', 'ram' => 'required|integer|min:4|max:128', 'ssd' => 'required|integer|min:64|max:8192', 'budget' => 'required|numeric|min:50|max:10000', 'requirements' => 'nullable|string|max:5000', 'privacy' => 'accepted', 'website' => 'nullable|max:0']);
        DB::table('inquiries')->insert(['type' => 'business', 'name' => $d['name'], 'email' => $d['email'], 'details' => json_encode($d), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', __('shop.business_received'));
    }

    public function newsletter(Request $r, EmailService $emails)
    {
        $d = $r->validate(['email' => 'required|email|max:190', 'consent' => 'accepted', 'product_model_id' => 'nullable|exists:product_models,id', 'ram_gb' => 'nullable|integer|min:4|max:128', 'ssd_gb' => 'nullable|integer|min:64|max:8192', 'grade' => 'nullable|in:A,B,C', 'website' => 'nullable|max:0']);
        $type = isset($d['product_model_id']) ? 'stock' : 'newsletter';
        $configuration = array_intersect_key($d, array_flip(['ram_gb', 'ssd_gb', 'grade']));
        $s = Subscription::where('email', $d['email'])->where('type', $type)->where('product_model_id', $d['product_model_id'] ?? null)->whereNull('unsubscribed_at')->whereNull('notified_at')->get()->first(fn ($existing) => ($existing->configuration ?? []) == $configuration);
        $s ??= Subscription::create(['email' => $d['email'], 'type' => $type, 'product_model_id' => $d['product_model_id'] ?? null, 'configuration' => array_intersect_key($d, array_flip(['ram_gb', 'ssd_gb', 'grade'])), 'locale' => app()->getLocale(), 'token' => Str::random(64), 'consent_at' => now()]);
        $link = URL::temporarySignedRoute('subscriptions.confirm', now()->addDays(2), ['locale' => app()->getLocale(), 'token' => $s->token]);
        $emails->record('optin-'.$s->id.'-'.now()->format('YmdHi'), $s->email, __('shop.confirm_subscription'), __('shop.optin_email')."\n".$link, $s->locale);

        return back()->with('success', __('shop.subscription_notice'));
    }

    public function confirm(Request $r, string $locale, string $token)
    {
        abort_unless($r->hasValidSignature(), 403);
        $s = Subscription::where('token', $token)->firstOrFail();
        if (! $s->unsubscribed_at) {
            $s->update(['confirmed_at' => now()]);
        }

        return redirect()->route('home')->with('success', __('shop.subscription_confirmed'));
    }

    public function unsubscribe(string $locale, string $token)
    {
        $s = Subscription::where('token', $token)->firstOrFail();
        $s->update(['unsubscribed_at' => now()]);

        return view('store.unsubscribe');
    }
}
