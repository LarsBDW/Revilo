<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeliveryAddress
{
    public static function rules(): array
    {
        return [
            'street' => 'required|string|max:120',
            'house_number' => 'required|string|max:20',
            'postal_code' => 'required|string|max:12',
            'city' => 'required|string|max:120',
            'country' => ['required', Rule::in(config('revilo.countries'))],
        ];
    }

    public static function normalize(array $address): array
    {
        foreach (['street', 'house_number', 'postal_code', 'city'] as $field) {
            $address[$field] = trim($address[$field]);
        }
        $pattern = match ($address['country']) {
            'NL' => '/^[1-9][0-9]{3}\s?[A-Za-z]{2}$/',
            'BE' => '/^[1-9][0-9]{3}$/',
            'DE' => '/^[0-9]{5}$/',
            default => '/^$/',
        };
        if (! preg_match($pattern, $address['postal_code'])) {
            throw ValidationException::withMessages(['postal_code' => __('shop.invalid_postcode')]);
        }
        if ($address['country'] === 'NL') {
            $postcode = strtoupper(preg_replace('/\s/', '', $address['postal_code']));
            $address['postal_code'] = substr($postcode, 0, 4).' '.substr($postcode, 4);
        }

        return $address;
    }

    public static function countryName(string $country): string
    {
        return __('shop.'.(['NL' => 'netherlands', 'BE' => 'belgium', 'DE' => 'germany'][$country] ?? $country));
    }
}
