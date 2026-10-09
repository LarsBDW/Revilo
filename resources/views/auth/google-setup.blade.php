@extends('layouts.store')
@section('title', __('shop.google_login'))
@section('content')
<div class="container"><section class="panel auth-panel"><h1>{{ __('shop.google_login') }}</h1><p class="auth-intro">{{ __('shop.google_not_configured') }} {{ __('shop.google_email_alternative') }}</p><div class="hero-buttons"><a class="btn btn-primary" href="{{ route('login') }}">{{ __('shop.login') }}</a><a class="btn btn-outline" href="{{ route('register') }}">{{ __('shop.register') }}</a></div></section></div>
@endsection
