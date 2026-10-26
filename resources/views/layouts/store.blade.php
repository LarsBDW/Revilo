@php
    $locale=app()->getLocale(); $cartCount=app(\App\Services\CartService::class)->current()->items()->sum('quantity');
    $wishCount=auth()->check()?\Illuminate\Support\Facades\DB::table('wishlists')->where('user_id',auth()->id())->count():0;
    $metaTitle=$metaTitle??trim($__env->yieldContent('title','Refurbished laptops')).' | Revilo';
    $metaDescription=$metaDescription??__('shop.hero_note').' '.__('shop.configuration_text');
    $shareImage=url(($product??null)?->image()??'/assets/share-cover-v1.png');
    $canonical=request()->url();$currentRoute=request()->route()?->getName();$routeParams=request()->route()?->parameters()??[];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ $metaTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}"><link rel="canonical" href="{{ $canonical }}">
    <meta property="og:title" content="{{ $metaTitle }}"><meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:image" content="{{ $shareImage }}"><meta property="og:type" content="{{ isset($product)?'product':'website' }}">
    <meta property="og:url" content="{{ $canonical }}"><meta property="og:site_name" content="Revilo">
    <meta property="og:image:alt" content="{{ isset($product)?$product->brand.' '.$product->name:'Revilo — refurbished laptops' }}">
    @unless(isset($product))<meta property="og:image:width" content="1709"><meta property="og:image:height" content="920"><meta property="og:image:type" content="image/png">@endunless
    <meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="{{ $metaTitle }}"><meta name="twitter:description" content="{{ $metaDescription }}"><meta name="twitter:image" content="{{ $shareImage }}">
    @if(request()->query() || in_array($currentRoute,['cart','checkout','account','login','register','auth.google.register','orders.show','orders.invoice','wishlist']))<meta name="robots" content="noindex,follow">@endif
    @foreach(config('revilo.locales') as $alternate)
        @if($currentRoute && !str_starts_with($currentRoute,'orders.'))<link rel="alternate" hreflang="{{ $alternate }}" href="{{ route($currentRoute,array_merge($routeParams,['locale'=>$alternate])) }}">@endif
    @endforeach
    <link rel="icon" type="image/png" href="/assets/icon.png"><link rel="apple-touch-icon" href="/assets/icon.png">
    <link rel="preload" href="/assets/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css','resources/js/app.js'])
    @stack('head')
</head>
<body data-locale="{{ $locale }}" data-authenticated="{{ auth()->check()?'1':'0' }}" data-wishlist-merge="{{ route('wishlist.merge') }}">
<a class="skip-link" href="#main-content">{{ __('pages.skip') }}</a>
<div class="announcement"><div class="container announcement-inner"><span><i data-lucide="leaf"></i>{{ \App\Models\Setting::translated('announcement',__('shop.footer_tagline')) }}</span><a href="{{ route('shipping') }}">{{ __('shop.shipping_short',['amount'=>\App\Support\Money::format(\App\Support\Pricing::threshold())]) }} <i data-lucide="arrow-right"></i></a></div></div>
<header class="site-header">
    <div class="container header-main">
        <button class="icon-button mobile-menu-button" data-menu-toggle aria-label="{{ __('shop.menu') }}" aria-expanded="false"><i data-lucide="menu"></i></button>
        <a class="brand" href="{{ route('home') }}" aria-label="Revilo {{ __('shop.home') }}"><img src="/assets/brand-warm.png" alt="Revilo" width="174" height="58"></a>
        <form class="header-search" action="{{ route('search') }}" role="search" data-search-form data-suggestions-url="{{ route('search.suggestions') }}">
            <i data-lucide="search"></i><input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('shop.search_placeholder') }}" aria-label="{{ __('shop.search') }}" autocomplete="off" aria-controls="search-results" aria-expanded="false"><button type="submit" aria-label="{{ __('shop.search') }}"><i data-lucide="arrow-right"></i></button><div id="search-results" class="search-suggestions" role="listbox" hidden></div>
        </form>
        <div class="header-actions">
            <a class="header-action account-link" href="{{ route(auth()->check()?'account':'login') }}"><i data-lucide="user-round"></i><span>{{ __('shop.account') }}</span></a>
            <a class="header-action" href="{{ route('wishlist') }}" aria-label="{{ __('shop.wishlist') }}"><span class="icon-with-count"><i data-lucide="heart"></i><b class="wishlist-count" @if(!$wishCount) hidden @endif>{{ $wishCount }}</b></span><span>{{ __('shop.wishlist') }}</span></a>
            <a class="header-action" href="{{ route('cart') }}" aria-label="{{ __('shop.cart') }}"><span class="icon-with-count"><i data-lucide="shopping-bag"></i><b>{{ $cartCount }}</b></span><span>{{ __('shop.cart') }}</span></a>
        </div>
    </div>
    <div class="nav-row"><div class="container nav-inner"><nav id="main-navigation" aria-label="{{ __('shop.menu') }}">
        <a href="{{ route('shop') }}" class="{{ request()->routeIs('shop','products.*')?'active':'' }}">{{ __('shop.laptops') }} <i data-lucide="chevron-down"></i></a>
        <a href="{{ route('shop',['category'=>'student']) }}">{{ __('shop.student') }}</a><a href="{{ route('shop',['category'=>'business']) }}">{{ __('shop.business') }}</a><a href="{{ route('deals') }}" class="deal-link">{{ __('shop.deals') }} <span class="nav-dot"></span></a><a href="{{ route('about') }}">{{ __('shop.about') }}</a><a href="{{ route('contact') }}">{{ __('shop.contact') }}</a><a class="mobile-account-nav" href="{{ route(auth()->check()?'account':'login') }}">{{ __('shop.account') }}</a>
    </nav><div class="nav-end"><a class="finder-nav" href="{{ route('finder') }}"><i data-lucide="circle-help"></i>{{ __('shop.finder_button') }}</a><details class="language-picker"><summary>{{ strtoupper($locale) }} <i data-lucide="chevron-down"></i></summary><div>@foreach(config('revilo.locales') as $lang)<a href="{{ $currentRoute && !str_starts_with($currentRoute,'orders.') ? route($currentRoute,array_merge($routeParams,['locale'=>$lang],request()->query())) : route('home',['locale'=>$lang]) }}" lang="{{ $lang }}">{{ ['nl'=>'Nederlands','en'=>'English','de'=>'Deutsch'][$lang] }}</a>@endforeach</div></details></div></div></div>
</header>
@if(session('success') || session('notice'))<div class="container flash success" role="status"><i data-lucide="circle-check"></i>{{ session('success')??session('notice') }}</div>@endif
@if($errors->any())<div class="container flash error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<main id="main-content">@yield('content')</main><section class="section container" hidden data-recent-section data-recent-url="{{ route('recent') }}"><div class="section-heading"><div><h2>{{ __('shop.recently_viewed') }}</h2><p>{{ __('pages.recent_text') }}</p></div></div><div class="product-grid" data-recent-products></div></section>
<section class="newsletter"><div class="container newsletter-inner"><div><span class="eyebrow">REVILO MAIL</span><h2>{{ __('shop.newsletter_title') }}</h2><p>{{ __('shop.newsletter_text') }}</p></div><form action="{{ route('subscriptions.create') }}" method="post">@csrf<div class="input-button"><label class="sr-only" for="newsletter-email">{{ __('shop.email') }}</label><input id="newsletter-email" type="email" name="email" placeholder="{{ __('shop.email') }}" required><button class="btn btn-primary" type="submit">{{ __('shop.subscribe') }} <i data-lucide="arrow-right"></i></button></div><label class="checkbox-label"><input type="checkbox" name="consent" value="1" required><span>{{ __('shop.newsletter_consent') }} <a href="{{ route('privacy') }}">{{ __('shop.privacy') }}</a></span></label><input class="honeypot" name="website" tabindex="-1" autocomplete="off" aria-hidden="true"></form></div></section>
<footer class="site-footer"><div class="container footer-grid"><div class="footer-brand"><a href="{{ route('home') }}"><img src="/assets/brand-warm.png" width="150" height="50" alt="Revilo"></a><p>{{ __('shop.footer_tagline') }}</p><p class="muted">{{ __('shop.hero_note') }}</p><a href="{{ route('trade-in') }}">{{ __('pages.trade_in') }}</a><div class="footer-countries">@foreach(config('revilo.countries') as $destination)<span>{{ $destination }}</span>@endforeach</div></div>
    <div><h3>{{ __('shop.service') }}</h3>@foreach(['contact','faq','shipping','warranty','tracking'] as $link)<a href="{{ route($link) }}">{{ __('shop.'.$link) }}</a>@endforeach</div>
    <div><h3>{{ __('shop.discover') }}</h3>@foreach(['about','process','condition_guide'=>'condition','business_orders'=>'business','finder_button'=>'finder'] as $label=>$link)<a href="{{ route($link) }}">{{ __('shop.'.(is_int($label)?$link:$label)) }}</a>@endforeach</div>
    <div><h3>{{ __('shop.information') }}</h3>@foreach(['privacy','terms','cookies'] as $link)<a href="{{ route($link) }}">{{ __('shop.'.$link) }}</a>@endforeach<a href="{{ route('admin.dashboard') }}">{{ __('pages.admin') }}</a><p class="muted small">{{ __('shop.payment_methods') }}</p></div></div>
    <div class="container footer-bottom"><span>© {{ date('Y') }} Revilo</span><span>{{ __('shop.vat_included') }}</span></div><div class="demo-strip">{{ __('shop.demo_notice') }}</div>
</footer>
<aside class="cookie-notice" hidden data-cookie-notice><p>{{ __('shop.cookie_text') }} <a href="{{ route('cookies') }}">{{ __('shop.cookies') }}</a></p><button class="btn btn-secondary" data-cookie-dismiss>{{ __('shop.cookie_accept') }}</button></aside>
<dialog id="quick-view" class="quick-dialog"><button class="icon-button dialog-close" data-close-dialog aria-label="{{ __('shop.close') }}"><i data-lucide="x"></i></button><div data-quick-content></div></dialog>
<div class="compare-bar" hidden data-compare-bar><span data-compare-count></span><a class="btn btn-primary" href="{{ route('compare') }}" data-compare-link>{{ __('shop.compare') }} <i data-lucide="arrow-right"></i></a></div>
@stack('scripts')
</body></html>
