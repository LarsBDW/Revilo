@extends('layouts.store')
@section('title', __('shop.'.$mode))
@section('content')
<div class="container"><section class="panel auth-panel"><h1>{{ __('shop.'.$mode) }}</h1>
@include('components.google-button', ['label' => __('shop.'.($mode==='register'?'google_register':'google_login')), 'intent' => $mode])
@if(!config('services.google.client_id') || !config('services.google.client_secret') || !config('services.google.redirect'))<p class="google-note">{{ __('shop.google_not_configured') }}</p>@endif
<div class="auth-divider"><span>{{ __('shop.or_email') }}</span></div>
<form method="post" action="{{ route($mode.'.submit') }}">@csrf
@if($mode==='register')<div class="form-field"><label for="name">{{ __('shop.name') }}</label><input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name"></div>@endif
<div class="form-field"><label for="email">{{ __('shop.email') }}</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required maxlength="190"></div>
<div class="form-field"><label for="password">{{ __('shop.password') }}</label><input id="password" type="password" name="password" autocomplete="{{ $mode==='register'?'new-password':'current-password' }}" required @if($mode==='register') minlength="10" @endif>@if($mode==='register')<small>{{ __('pages.password_help') }}</small>@endif</div>
@if($mode==='register')<div class="form-field"><label for="password_confirmation">{{ __('shop.confirm_password') }}</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div><label class="consent"><input type="checkbox" name="terms" value="1" required> <span><a href="{{ route('terms') }}">{{ __('shop.accept_terms') }}</a></span></label>
@else<label class="consent"><input type="checkbox" name="remember" value="1"> {{ __('shop.remember') }}</label>@endif
<button class="btn btn-primary" style="margin-top:20px">{{ __('shop.'.$mode) }} <i data-lucide="arrow-right"></i></button>
</form><div class="auth-links"><a href="{{ route('password.request') }}">{{ __('shop.forgot_password') }}</a><a href="{{ route($mode==='login'?'register':'login') }}">{{ __('shop.'.($mode==='login'?'register':'login')) }}</a></div>
</section></div>
@endsection
