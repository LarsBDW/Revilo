@extends('layouts.store')
@section('title', __('shop.checkout'))
@section('content')
<div class="container page-top">
    <div class="breadcrumb"><a href="{{ route('cart') }}">{{ __('shop.cart') }}</a><i data-lucide="chevron-right"></i>{{ __('shop.checkout') }}</div>
    <h1>{{ __('shop.test_checkout') }}</h1>
</div>
<div class="container">
    <div class="test-banner"><strong>{{ __('shop.test_checkout') }}</strong>{{ __('shop.test_checkout_text') }}</div>
    <form action="{{ route('checkout.create') }}" method="post" data-checkout-form data-subtotal="{{ $totals['subtotal_cents'] }}" data-discount="{{ $totals['discount_cents'] }}" data-free-threshold="{{ App\Support\Pricing::threshold() }}" data-shipping-nl="{{ App\Support\Pricing::delivery('NL') }}" data-shipping-be="{{ App\Support\Pricing::delivery('BE') }}" data-shipping-de="{{ App\Support\Pricing::delivery('DE') }}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ $key }}">
        <input type="hidden" name="selected_address_id" value="{{ $selectedAddress['id'] ?? '' }}">
        <div class="two-column">
            <div class="panel">
                <h2>{{ __('shop.customer_details') }}</h2>
                @if($addresses->isNotEmpty())
                    <section class="saved-addresses" aria-labelledby="saved-address-heading">
                        <h3 id="saved-address-heading">{{ __('shop.use_saved_address_question') }}</h3>
                        <p>{{ __('shop.saved_address_hint') }}</p>
                        <div class="saved-address-grid">
                            @foreach($addresses as $address)
                                <article class="saved-address-card {{ ($selectedAddress['id'] ?? null) === $address['id'] ? 'is-selected' : '' }}" data-address-card="{{ $address['id'] }}">
                                    <div class="saved-address-label"><i data-lucide="map-pin"></i><strong>{{ $address['label'] }}</strong></div>
                                    <p>{{ $address['street'] }} {{ $address['house_number'] }}<br>{{ $address['postal_code'] }} {{ $address['city'] }}<br>{{ App\Support\DeliveryAddress::countryName($address['country']) }}</p>
                                    <button class="btn btn-outline" type="submit" name="address_id" value="{{ $address['id'] }}" formaction="{{ route('checkout.address') }}" formnovalidate data-use-address="{{ json_encode($address) }}" aria-pressed="{{ ($selectedAddress['id'] ?? null) === $address['id'] ? 'true' : 'false' }}">{{ __('shop.use_this_address') }} <i data-lucide="arrow-right"></i></button>
                                </article>
                            @endforeach
                        </div>
                        <button class="text-link" type="submit" name="address_id" value="0" formaction="{{ route('checkout.address') }}" formnovalidate data-new-address>{{ __('shop.use_different_address') }}</button>
                        <p class="address-selection-status" data-address-status role="status" aria-live="polite">{{ $selectedAddress ? __('shop.saved_address_applied') : '' }}</p>
                    </section>
                @endif
                <div class="config-grid">
                    @foreach(['name','email','street','house_number','postal_code','city'] as $field)
                        <div class="form-field {{ in_array($field,['name','email','street']) ? 'full-width' : '' }}">
                            <label for="checkout-{{ $field }}">{{ __('shop.'.$field) }}</label>
                            <input id="checkout-{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" name="{{ $field }}" value="{{ old($field, $defaults[$field] ?? '') }}" required maxlength="{{ ['house_number'=>20, 'postal_code'=>12, 'email'=>190][$field] ?? 120 }}" autocomplete="{{ ['name'=>'name','email'=>'email','street'=>'address-line1','house_number'=>'address-line2','postal_code'=>'postal-code','city'=>'address-level2'][$field] }}">
                        </div>
                    @endforeach
                    <div class="form-field full-width">
                        <label for="checkout-country">{{ __('shop.country') }}</label>
                        <select name="country" id="checkout-country" data-checkout-country>@include('components.country-options', ['selected' => $country])</select>
                    </div>
                </div>
                <label class="checkbox-label"><input type="checkbox" name="terms" value="1" required><span>{{ __('shop.accept_terms') }} <a href="{{ route('terms') }}">{{ __('shop.terms') }}</a></span></label>
                <label class="checkbox-label"><input type="checkbox" name="test_acknowledgement" value="1" required>{{ __('shop.test_acknowledgement') }}</label>
            </div>
            <aside class="panel summary-panel">
                <h2>{{ __('shop.order_summary') }}</h2>
                @foreach($totals['items'] as $line)
                    <div class="summary-row"><span>{{ $line['item']->quantity }} × {{ $line['item']->model->name }}</span><strong>{{ App\Support\Money::format($line['quote']['total_cents']) }}</strong></div>
                @endforeach
                <div class="summary-row"><span>{{ __('shop.subtotal') }}</span><strong>{{ App\Support\Money::format($totals['subtotal_cents']) }}</strong></div>
                @if($totals['discount_cents'])<div class="summary-row"><span>{{ __('shop.discount_label') }}</span><strong>−{{ App\Support\Money::format($totals['discount_cents']) }}</strong></div>@endif
                <div class="summary-row"><span>{{ __('shop.shipping_cost') }}</span><strong data-shipping-estimate>{{ App\Support\Money::format($totals['shipping_cents']) }}</strong></div>
                <div class="summary-row total-row"><span>{{ __('shop.total') }}</span><strong data-total-estimate>{{ App\Support\Money::format($totals['total_cents']) }}</strong></div>
                <p class="small">{{ __('shop.vat_included') }}</p>
                <button class="btn btn-primary" type="submit">{{ __('shop.place_test_order') }} <i data-lucide="arrow-right"></i></button>
            </aside>
        </div>
        <span hidden data-address-applied-text>{{ __('shop.saved_address_applied') }}</span>
        <span hidden data-address-different-text>{{ __('shop.different_address_selected') }}</span>
    </form>
</div>
@endsection
