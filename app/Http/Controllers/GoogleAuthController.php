<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CartService;
use App\Services\GoogleAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $request->session()->forget(['google.pending', 'google.oauth']);
        if (! $this->configured()) {
            return response()->view('auth.google-setup', [], 503);
        }
        $intent = $request->query('intent', 'login');
        abort_unless(in_array($intent, ['login', 'register', 'link'], true), 422);
        if ($intent === 'link' && ! $request->user()) {
            return redirect()->route('login')->withErrors(['email' => __('shop.google_link_password')]);
        }
        $request->session()->put('google.oauth', ['intent' => $intent, 'user_id' => $request->user()?->id, 'expires_at' => now()->addMinutes(10)->timestamp]);

        return Socialite::driver('google')->enablePKCE()->with(['prompt' => 'select_account'])->redirect();
    }

    public function callback(Request $request, GoogleAuthService $google)
    {
        $context = $request->session()->pull('google.oauth');
        $request->session()->forget('google.pending');
        if (! $this->configured()) {
            return response()->view('auth.google-setup', [], 503);
        }
        $destination = ($context['intent'] ?? '') === 'link' ? 'account' : (($context['intent'] ?? '') === 'register' ? 'register' : 'login');
        if (! is_array($context) || ($context['expires_at'] ?? 0) < now()->timestamp || $request->has('error')) {
            $request->session()->forget(['state', 'code_verifier']);

            return redirect()->route($destination)->withErrors(['email' => __('shop.google_failed')]);
        }
        try {
            $profile = Socialite::driver('google')->enablePKCE()->user();
        } catch (\Throwable) {
            $request->session()->forget(['state', 'code_verifier']);

            return redirect()->route($destination)->withErrors(['email' => __('shop.google_failed')]);
        }
        try {
            $identity = $google->identity($profile);
            $linking = ($context['intent'] ?? '') === 'link';
            if ($linking && (! $request->user() || $request->user()->id !== $context['user_id'])) {
                throw ValidationException::withMessages(['email' => __('shop.google_link_password')]);
            }
            $user = $google->resolve($identity, $linking ? $request->user() : null);
            if ($linking) {
                Auth::setUser($user);

                return redirect()->route('account')->with('success', __('shop.google_linked_success'));
            }
            if ($user) {
                return $this->signIn($request, $user);
            }
            $request->session()->put('google.pending', $identity + ['expires_at' => now()->addMinutes(10)->timestamp]);

            return redirect()->route('auth.google.register');
        } catch (ValidationException $e) {
            return redirect()->route($destination)->withErrors($e->errors());
        }
    }

    public function registration(Request $request)
    {
        if ($request->user()) {
            return redirect()->route('account');
        }
        $identity = $this->pending($request);
        if (! $identity) {
            return redirect()->route('register')->withErrors(['email' => __('shop.google_expired')]);
        }

        return view('auth.google-register', ['identity' => $identity]);
    }

    public function register(Request $request, GoogleAuthService $google)
    {
        if ($request->user()) {
            return redirect()->route('account');
        }
        $identity = $this->pending($request);
        if (! $identity) {
            return redirect()->route('register')->withErrors(['email' => __('shop.google_expired')]);
        }
        $request->validate(['terms' => 'accepted'], ['terms.accepted' => __('shop.google_accept_terms')]);
        try {
            $user = $google->register($identity);
        } catch (ValidationException $e) {
            $request->session()->forget('google.pending');

            return redirect()->route('login')->withErrors($e->errors());
        }
        $request->session()->forget('google.pending');

        return $this->signIn($request, $user);
    }

    private function pending(Request $request): ?array
    {
        $identity = $request->session()->get('google.pending');
        if (! is_array($identity) || ($identity['expires_at'] ?? 0) < now()->timestamp) {
            $request->session()->forget('google.pending');

            return null;
        }

        return $identity;
    }

    private function configured(): bool
    {
        return (bool) (config('services.google.client_id') && config('services.google.client_secret') && config('services.google.redirect'));
    }

    private function signIn(Request $request, User $user)
    {
        Auth::login($user);
        $request->session()->regenerate();
        app(CartService::class)->current();

        return redirect()->intended(route($user->isAdmin() ? 'admin.dashboard' : 'account'));
    }
}
