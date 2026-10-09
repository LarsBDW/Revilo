<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function form(Request $r)
    {
        if ($r->user()) {
            return redirect()->route('account');
        }

        return view('auth.form', ['mode' => str_contains($r->path(), 'register') ? 'register' : 'login']);
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email|max:190', 'password' => 'required|string']);
        if (! Auth::attempt(array_merge($d, ['active' => true]), $r->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('shop.login_failed')]);
        }
        $r->session()->regenerate();
        app(CartService::class)->current();

        return redirect()->intended(route('account'));
    }

    public function register(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190|unique:users,email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()], 'terms' => 'accepted']);
        $u = User::create($d);
        $u->forceFill(['terms_accepted_at' => now()])->save();
        Auth::login($u);
        $r->session()->regenerate();
        $u->sendEmailVerificationNotification();

        return redirect()->route('account')->with('success', __('shop.verification_notice'));
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('home');
    }

    public function forgot()
    {
        return view('auth.password', ['mode' => 'forgot']);
    }

    public function sendReset(Request $r)
    {
        $r->validate(['email' => 'required|email|max:190']);
        Password::sendResetLink($r->only('email'));

        return back()->with('success', __('shop.password_link_notice'));
    }

    public function resetForm(Request $r, string $locale, string $token)
    {
        return view('auth.password', ['mode' => 'reset', 'token' => $token, 'email' => $r->email]);
    }

    public function reset(Request $r)
    {
        $d = $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $status = Password::reset($d, function (User $u, string $password) {
            $u->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('login')->with('success', __('shop.password_changed'));
    }

    public function verify(Request $r, string $locale, int $id, string $hash)
    {
        abort_unless(auth()->id() === $id && hash_equals(sha1(auth()->user()->getEmailForVerification()), $hash), 403);
        auth()->user()->markEmailAsVerified();

        return redirect()->route('account')->with('success', __('shop.email_verified'));
    }

    public function resend()
    {
        if (! auth()->user()->hasVerifiedEmail()) {
            auth()->user()->sendEmailVerificationNotification();
        }

        return back()->with('success', __('shop.verification_notice'));
    }
}
