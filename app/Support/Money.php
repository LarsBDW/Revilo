<?php

namespace App\Support;

class Money
{
    public static function format(int $cents): string
    {
        return app()->getLocale() === 'en' ? '€ '.number_format($cents / 100, 2, '.', ',') : '€ '.number_format($cents / 100, 2, ',', '.');
    }

    public static function parse(string $value): int
    {
        $value = trim(str_replace(['€', ' '], '', $value));
        if (str_contains($value, ',')) {
            $value = str_replace(',', '.', str_replace('.', '', $value));
        }
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException('Invalid money value');
        }
        [$whole,$fraction] = array_pad(explode('.', $value), 2, '');
        if (strlen(ltrim($whole, '0')) > 6) {
            throw new \InvalidArgumentException('Money amount too large');
        }
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if ($cents > 10000000) {
            throw new \InvalidArgumentException('Maximum amount €100,000');
        }

        return $cents;
    }

    public static function vat(int $gross, string $treatment, int $purchase = 0): int
    {
        $taxable = $treatment === 'margin' ? max(0, $gross - $purchase) : $gross;

        return intdiv($taxable * 21 + 60, 121);
    }
}
