@php($model=$summary['model'])
@php($productUrl=route('products.show',array_merge(['product'=>$model],$summary['configuration']??[])))
<article class="product-card" data-product-id="{{ $model->id }}" data-product-url="{{ $productUrl }}">
    <div class="card-image"><a href="{{ $productUrl }}" aria-label="{{ $model->brand }} {{ $model->name }}"><img src="{{ $model->image() }}" alt="{{ $model->brand }} {{ $model->name }} — {{ __('shop.model_image') }}" width="300" height="220" loading="lazy"></a>
        @if($model->discount_percent)<span class="badge discount-badge">−{{ $model->discount_percent }}%</span>@else<span class="badge grade-badge">{{ __('shop.grade') }} {{ $summary['unit']?->grade??'—' }}</span>@endif
        <button class="wishlist-button" data-wish-id="{{ $model->id }}" data-wish-url="{{ route('wishlist.toggle',$model) }}" aria-label="{{ __('shop.wishlist') }}" aria-pressed="false"><i data-lucide="heart"></i></button>
        <button class="quick-button" data-quick-view aria-label="{{ __('shop.quick_view') }}"><i data-lucide="eye"></i></button>
    </div>
    <div class="card-body"><div class="card-brand">{{ $model->brand }}</div><h3><a href="{{ $productUrl }}">{{ $model->name }}</a></h3><p class="card-cpu">{{ $summary['unit']?->processor??'—' }}</p>
        <div class="card-specs"><span><i data-lucide="memory-stick"></i>{{ $summary['configuration']['ram_gb']??'—' }} GB RAM</span><span><i data-lucide="hard-drive"></i>{{ $summary['configuration']['ssd_gb']??'—' }} GB SSD</span><span><i data-lucide="laptop"></i>{{ $summary['unit']?->screen_inches??'—' }}″ {{ $summary['unit']?->resolution??'—' }}</span></div>
        <div class="card-price-row"><div><span class="price-prefix">{{ __('shop.from') }}</span><strong class="card-price">{{ $summary['available']?\App\Support\Money::format($summary['price_cents']):'—' }}</strong>@if($summary['original_cents']>$summary['price_cents'])<del>{{ \App\Support\Money::format($summary['original_cents']) }}</del>@endif</div><span class="stock-label {{ !$summary['available']?'unavailable':'' }}"><span></span>{{ __('shop.'.($summary['available']?'in_stock':'out_of_stock')) }}</span></div>
        <a class="btn btn-card" href="{{ $productUrl }}">{{ __('shop.details') }} <i data-lucide="arrow-right"></i></a><label class="compare-checkbox"><input type="checkbox" data-compare-id="{{ $model->id }}">{{ __('shop.compare') }}</label>
    </div>
</article>
