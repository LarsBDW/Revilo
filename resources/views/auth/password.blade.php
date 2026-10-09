@extends('layouts.store')
@section('title', __('shop.reset_password'))
@section('content')
<div class="container"><section class="panel auth-panel"><h1>{{ __('shop.reset_password') }}</h1><form method="post" action="{{ route($mode==='forgot'?'password.email':'password.update') }}">@csrf
@if($mode==='reset')<input type="hidden" name="token" value="{{ $token }}">@endif
<div class="form-field"><label for="email">{{ __('shop.email') }}</label><input id="email" name="email" type="email" value="{{ old('email',$email??'') }}" required autocomplete="email"></div>
@if($mode==='reset')<div class="form-field"><label for="password">{{ __('shop.password') }}</label><input id="password" name="password" type="password" autocomplete="new-password" required minlength="10"><small>{{ __('pages.password_help') }}</small></div><div class="form-field"><label for="password_confirmation">{{ __('shop.confirm_password') }}</label><input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required></div>@endif
<button class="btn btn-primary">{{ __('shop.'.($mode==='forgot'?'send_link':'reset_password')) }}</button></form><div class="auth-links"><a href="{{ route('login') }}">{{ __('shop.login') }}</a></div></section></div>
@endsection
