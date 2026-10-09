<?php

namespace App\Support;

use App\Models\Component;
use App\Models\Coupon;
use App\Models\InventoryUnit;
use App\Models\ProductModel;

class AdminResources
{
    public static function definitions(): array
    {
        return [
            'products' => ['title' => 'Producten', 'model' => ProductModel::class, 'columns' => ['brand', 'name', 'category', 'published', 'featured'], 'fields' => [
                'brand' => ['Merk', 'text', 'required|string|max:60'], 'name' => ['Model', 'text', 'required|string|max:120'], 'slug' => ['URL slug', 'text', 'required|alpha_dash|max:190'],
                'category' => ['Categorie', 'select', 'required|in:business,student,budget,premium,everyday', ['business', 'student', 'budget', 'premium', 'everyday']],
                'description_nl' => ['Omschrijving Nederlands', 'textarea', 'required|string|max:10000'], 'description_en' => ['Description English', 'textarea', 'nullable|string|max:10000'], 'description_de' => ['Beschreibung Deutsch', 'textarea', 'nullable|string|max:10000'],
                'supported_ram' => ['Ondersteunde RAM (GB, komma gescheiden)', 'text', 'required|string|max:100'], 'supported_ssd' => ['Ondersteunde SSD (GB, komma gescheiden)', 'text', 'required|string|max:100'],
                'operating_systems' => ['OS opties (komma gescheiden)', 'text', 'required|string|max:120'], 'discount_percent' => ['Korting (%)', 'number', 'required|integer|min:0|max:80'],
                'published' => ['Gepubliceerd', 'checkbox', 'boolean'], 'featured' => ['Uitgelicht', 'checkbox', 'boolean'],
                'seo_title' => ['SEO titel', 'text', 'nullable|string|max:190'], 'seo_description' => ['SEO omschrijving', 'textarea', 'nullable|string|max:500'],
            ]],
            'inventory' => ['title' => 'Laptopvoorraad', 'model' => InventoryUnit::class, 'columns' => ['inventory_id', 'processor', 'ram_gb', 'ssd_gb', 'grade', 'status', 'selling_cents'], 'fields' => [
                'product_model_id' => ['Productmodel', 'product', 'required|exists:product_models,id'], 'inventory_id' => ['Voorraad ID', 'text', 'required|string|max:100'], 'serial_number' => ['Privé serienummer', 'text', 'nullable|string|max:100'],
                'processor' => ['Processor', 'text', 'required|string|max:100'], 'processor_family' => ['Processor familie', 'select', 'required|in:i3,i5,i7,i9,Ryzen', ['i3', 'i5', 'i7', 'i9', 'Ryzen']],
                'processor_generation' => ['Generatie', 'number', 'required|integer|min:1|max:30'], 'ram_gb' => ['RAM (GB)', 'number', 'required|integer|min:4|max:128'], 'ssd_gb' => ['SSD (GB)', 'number', 'required|integer|min:64|max:8192'],
                'screen_inches' => ['Scherm (inch)', 'number', 'required|numeric|min:10|max:18'], 'resolution' => ['Resolutie', 'select', 'required|in:HD,Full HD,4K', ['HD', 'Full HD', '4K']],
                'graphics' => ['Grafische kaart', 'text', 'required|string|max:100'], 'dedicated_graphics' => ['Dedicated graphics', 'checkbox', 'boolean'], 'touchscreen' => ['Touchscreen', 'checkbox', 'boolean'],
                'keyboard_layout' => ['Toetsenbord', 'select', 'required|in:QWERTY,AZERTY', ['QWERTY', 'AZERTY']], 'operating_system' => ['Besturingssysteem', 'select', 'required|in:Windows 11 Pro,Linux,No OS', ['Windows 11 Pro', 'Linux', 'No OS']],
                'battery_health' => ['Batterijgezondheid (%)', 'number', 'nullable|integer|min:0|max:100'], 'grade' => ['Conditie', 'select', 'required|in:A,B,C', ['A', 'B', 'C']],
                'defects' => ['Bekende gebreken', 'textarea', 'nullable|string|max:3000'], 'purchase_cents' => ['Inkoopprijs (€)', 'money', 'required|string|max:30'], 'refurbishment_cents' => ['Refurbishment kosten (€)', 'money', 'required|string|max:30'], 'selling_cents' => ['Verkoopprijs incl. btw (€)', 'money', 'required|string|max:30'],
                'vat_treatment' => ['BTW behandeling', 'select', 'required|in:standard,margin', ['standard', 'margin']],
                'status' => ['Status', 'select', 'required|in:'.implode(',', InventoryUnit::STATUSES), InventoryUnit::STATUSES],
                'purchase_date' => ['Inkoopdatum', 'date', 'nullable|date'], 'internal_notes' => ['Interne notities', 'textarea', 'nullable|string|max:5000'],
            ]],
            'components' => ['title' => 'Upgrade onderdelen', 'model' => Component::class, 'columns' => ['sku', 'name', 'type', 'capacity', 'quantity', 'reserved', 'upgrade_cents'], 'fields' => [
                'sku' => ['SKU', 'text', 'required|string|max:100'], 'name' => ['Naam', 'text', 'required|string|max:120'], 'type' => ['Type', 'select', 'required|in:ram,ssd,battery,charger', ['ram', 'ssd', 'battery', 'charger']],
                'capacity' => ['Capaciteit (GB; batterij/lader = 1)', 'number', 'required|integer|min:1|max:8192'], 'compatible_models' => ['Compatibele productmodel IDs (komma gescheiden)', 'text', 'required|string|max:500'],
                'quantity' => ['Aantal op voorraad', 'number', 'required|integer|min:0|max:100000'], 'cost_cents' => ['Inkoopkosten (€)', 'money', 'required|string|max:30'], 'upgrade_cents' => ['Upgradeprijs (€)', 'money', 'required|string|max:30'],
            ]],
            'discounts' => ['title' => 'Kortingscodes', 'model' => Coupon::class, 'columns' => ['code', 'type', 'value', 'uses', 'active'], 'fields' => [
                'code' => ['Code', 'text', 'required|alpha_dash|max:50'], 'type' => ['Type', 'select', 'required|in:percent,fixed', ['percent', 'fixed']],
                'value' => ['Waarde (% of centen)', 'number', 'required|integer|min:1|max:100000'], 'minimum_cents' => ['Minimum bestelling (€)', 'money', 'required|string|max:30'],
                'usage_limit' => ['Gebruikslimiet', 'number', 'nullable|integer|min:1'], 'expires_at' => ['Verloopt op', 'datetime-local', 'nullable|date'],
                'category' => ['Categorie (optioneel)', 'text', 'nullable|in:business,student,budget,premium,everyday'], 'product_model_id' => ['Product (optioneel)', 'product', 'nullable|exists:product_models,id'], 'active' => ['Actief', 'checkbox', 'boolean'],
            ]],
        ];
    }
}
