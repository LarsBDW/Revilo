@foreach(config('revilo.countries') as $destination)
    <option value="{{ $destination }}" @selected(($selected ?? 'NL') === $destination)>{{ App\Support\DeliveryAddress::countryName($destination) }}</option>
@endforeach
