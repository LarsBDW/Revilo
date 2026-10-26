@php($details=$optionPrices[$field][(string)$value]??['available'=>false,'price_cents'=>null,'extra_cents'=>null])
<label class="config-choice">
    <input class="sr-only" type="radio" name="{{ $field }}" value="{{ $value }}" @checked((string)$configuration[$field]===(string)$value) @disabled(!$details['available'])>
    <span class="config-choice-card" @if($field==='grade') data-condition-card @endif>
        <strong>{{ $label }}</strong>
        @if($field==='grade')<span class="condition-stars" aria-hidden="true">{{ str_repeat('★', ['C'=>3,'B'=>4,'A'=>5][$value]) }}</span>@endif
        <span class="config-choice-price" data-option-price>{{ !$details['available']?__('shop.option_unavailable'):($field==='grade'?\App\Support\Money::format($details['price_cents']):'('.($details['extra_cents']>0?'+':'').\App\Support\Money::format($details['extra_cents']).')') }}</span>
    </span>
</label>
