@extends('layouts.store')
@section('title', __('shop.wishlist'))
@section('content')
<div class="container" data-guest-wishlist><div class="page-top"><h1>{{ __('shop.wishlist') }}</h1></div><div class="section" style="padding-top:0">@if($products->isEmpty())<div class="empty-state"><i data-lucide="heart"></i><h2>{{ __('shop.wishlist_empty') }}</h2><a class="btn btn-primary" href="{{ route('shop') }}">{{ __('shop.shop_laptops') }}</a></div>@else<div class="product-grid">@foreach($products as $summary)@include('components.product-card', ['summary'=>$summary])@endforeach</div>@endif</div></div>
@endsection
