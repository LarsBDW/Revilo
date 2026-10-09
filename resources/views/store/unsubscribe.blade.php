@extends('layouts.store')
@section('title', __('shop.unsubscribed'))
@section('content')<div class="container"><section class="panel auth-panel"><h1>{{ __('shop.unsubscribed') }}</h1><a class="btn btn-primary" href="{{ route('home') }}">{{ __('shop.home') }}</a></section></div>@endsection
