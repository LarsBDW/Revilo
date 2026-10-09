@extends('layouts.store')
@section('title', __('shop.google_finish_title'))
@section('content')
<div class="container"><section class="panel auth-panel">
    <h1>{{ __('shop.google_finish_title') }}</h1>
    <p class="auth-intro">{{ __('shop.google_finish_text') }}</p>
    <div class="google-identity"><img src="/assets/google-g.png" alt="" width="24" height="24"><div><strong>{{ $identity['name'] }}</strong><span>{{ $identity['email'] }}</span></div><i data-lucide="circle-check"></i></div>
    <form method="post" action="{{ route('auth.google.register.submit') }}">@csrf
        <label class="consent"><input type="checkbox" name="terms" value="1" required><span>{{ __('shop.google_terms_prefix') }} <a href="{{ route('terms') }}" target="_blank" rel="noopener">{{ __('shop.terms') }}</a>. <a href="{{ route('privacy') }}" target="_blank" rel="noopener">{{ __('shop.privacy') }}</a></span></label>
        <button class="btn btn-primary auth-submit">{{ __('shop.register') }} <i data-lucide="arrow-right"></i></button>
    </form>
    <p class="google-note">{{ __('shop.google_password_free') }}</p>
    <a class="text-link" href="{{ route('register') }}">{{ __('shop.google_other_method') }}</a>
</section></div>
@endsection
