<?php

namespace Database\Seeders;

use App\Models\Component;
use App\Models\Coupon;
use App\Models\InventoryUnit;
use App\Models\ProductModel;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class ShopSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['dell-latitude-5490', 'Dell', 'Latitude 5490', 'business', 'Intel Core i5-8350U', 8, 14, 18900, 8, 256, 'A', 92, 16],
            ['lenovo-thinkpad-t480', 'Lenovo', 'ThinkPad T480', 'student', 'Intel Core i5-8250U', 8, 14, 22900, 16, 256, 'B', 86, 0],
            ['hp-elitebook-840-g6', 'HP', 'EliteBook 840 G6', 'business', 'Intel Core i5-8365U', 8, 14, 27900, 16, 512, 'A', 91, 10],
            ['dell-latitude-5400', 'Dell', 'Latitude 5400', 'everyday', 'Intel Core i5-8365U', 8, 14, 24900, 8, 256, 'B', 84, 0],
            ['lenovo-thinkpad-t14', 'Lenovo', 'ThinkPad T14 Gen 1', 'premium', 'Intel Core i5-10210U', 10, 14, 39900, 16, 512, 'A', 90, 0],
            ['hp-probook-450-g7', 'HP', 'ProBook 450 G7', 'everyday', 'Intel Core i5-10210U', 10, 15.6, 31900, 16, 512, 'B', 88, 8],
        ];
        $ids = [];
        foreach ($products as $index => [$slug,$brand,$name,$category,$cpu,$gen,$screen,$price,$ram,$ssd,$grade,$battery,$discount]) {
            $p = ProductModel::firstOrCreate(['slug' => $slug], ['brand' => $brand, 'name' => $name, 'category' => $category,
                'description' => ['nl' => 'Een veelzijdige zakelijke laptop voor werk, studie en dagelijks gebruik. Kies de configuratie die bij je past. Conditie, batterij en eventuele gebreken worden per beschikbare laptop vermeld.', 'en' => 'A versatile business laptop for work, study and everyday use. Choose your configuration. Condition, battery health and any defects are listed for each available laptop.', 'de' => 'Ein vielseitiger Business-Laptop für Arbeit, Studium und Alltag. Wählen Sie Ihre Konfiguration. Zustand, Akkugesundheit und mögliche Mängel werden pro Gerät angegeben.'],
                'images' => ['/assets/products/'.$slug.'.png'], 'supported_ram' => [8, 16, 32], 'supported_ssd' => [128, 256, 512, 1024], 'operating_systems' => ['Windows 11 Pro', 'Linux', 'No OS'],
                'specifications' => ['weight_kg' => 1.65, 'ports' => 'USB-A · USB-C · HDMI · Ethernet', 'connectivity' => 'Wi-Fi · Bluetooth', 'screen' => 'Full HD IPS', 'demo' => true], 'published' => true, 'featured' => $index < 4, 'discount_percent' => $discount]);
            $ids[] = $p->id;
            for ($i = 0; $i < 3; $i++) {
                InventoryUnit::firstOrCreate(['inventory_id' => 'DEMO-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'-'.($i + 1)], ['product_model_id' => $p->id, 'serial_number' => 'DEMO-PRIVATE-'.$index.'-'.$i, 'serial_hash' => hash('sha256', 'DEMO-PRIVATE-'.$index.'-'.$i), 'processor' => $cpu, 'processor_family' => 'i5', 'processor_generation' => $gen, 'ram_gb' => $i === 2 ? 16 : $ram, 'ssd_gb' => $i === 2 ? 512 : $ssd, 'screen_inches' => $screen, 'resolution' => 'Full HD', 'keyboard_layout' => 'QWERTY', 'operating_system' => 'Windows 11 Pro', 'grade' => $i === 1 ? 'C' : $grade, 'battery_health' => $battery - $i * 5, 'defects' => $i === 1 ? 'Demo: zichtbare gebruikssporen op de behuizing.' : null, 'purchase_cents' => 6000 + $index * 2000, 'refurbishment_cents' => 1500, 'selling_cents' => $i === 1 ? $price - 2500 : ($i === 2 ? $price + 2500 : $price), 'status' => 'available', 'vat_treatment' => $index % 2 ? 'margin' : 'standard', 'images' => [], 'accessories' => ['Compatible charger'], 'internal_notes' => 'SAMPLE INVENTORY — replace with real inspected stock before launch', 'purchase_date' => now()->subDays(10)]);
            }
        }
        foreach (['ram' => [16 => [2500, 1200], 32 => [5500, 2800]], 'ssd' => [512 => [2500, 1500], 1024 => [5500, 3500]], 'battery' => [1 => [5900, 3500]], 'charger' => [1 => [2500, 1200]]] as $type => $options) {
            foreach ($options as $capacity => [$upgrade,$cost]) {
                Component::firstOrCreate(['sku' => 'DEMO-'.strtoupper($type).'-'.$capacity], ['name' => match ($type) {
                    'ram' => $capacity.' GB RAM upgrade','ssd' => ($capacity === 1024 ? '1 TB' : $capacity.' GB').' SSD upgrade','battery' => 'Nieuwe compatibele batterij','charger' => 'Extra compatibele lader'
                }, 'type' => $type, 'capacity' => $capacity, 'compatible_models' => $ids, 'quantity' => 10, 'reserved' => 0, 'cost_cents' => $cost, 'upgrade_cents' => $upgrade, 'specifications' => ['demo' => true]]);
            }
        }
        foreach ([
            'announcement' => ['nl' => 'Een tweede leven. Een slimme keuze.', 'en' => 'A second life. A smarter choice.', 'de' => 'Ein zweites Leben. Eine kluge Wahl.'],
            'hero_title' => ['nl' => "Een goede laptop.\nEen slimme keuze.", 'en' => "A great laptop.\nA smarter choice.", 'de' => "Ein guter Laptop.\nEine kluge Wahl."],
            'hero_text' => ['nl' => 'Meer mogelijkheden, minder betalen. Ontdek refurbished laptops voor werk, studie en alles daartussen.', 'en' => 'More possibilities, a smaller price. Explore refurbished laptops for work, study and everything in between.', 'de' => 'Mehr Möglichkeiten, weniger bezahlen. Entdecken Sie refurbished Laptops für Arbeit, Studium und alles dazwischen.'],
            'contact_email' => ['nl' => 'Nog in te stellen', 'en' => 'To be configured', 'de' => 'Noch einzurichten'],
            'contact_phone' => ['nl' => 'Nog in te stellen', 'en' => 'To be configured', 'de' => 'Noch einzurichten'],
            'company_address' => ['nl' => 'Bedrijfsgegevens worden vóór de opening toegevoegd.', 'en' => 'Company details will be added before opening.', 'de' => 'Unternehmensdaten werden vor der Eröffnung ergänzt.'],
        ] as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
        Coupon::firstOrCreate(['code' => 'REVILO10'], ['type' => 'percent', 'value' => 10, 'minimum_cents' => 15000, 'usage_limit' => 100, 'expires_at' => now()->addMonths(3), 'active' => true]);
    }
}
