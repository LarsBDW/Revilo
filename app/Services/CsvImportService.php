<?php

namespace App\Services;

use App\Models\InventoryHistory;
use App\Models\InventoryUnit;
use App\Models\ProductModel;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CsvImportService
{
    public const FIELDS = ['brand', 'model', 'inventory_id', 'serial_number', 'processor', 'processor_family', 'processor_generation', 'ram_gb', 'ssd_gb', 'screen_inches', 'resolution', 'keyboard_layout', 'operating_system', 'battery_health', 'grade', 'defects', 'purchase_price', 'refurbishment_cost', 'selling_price', 'status', 'purchase_date', 'internal_notes', 'vat_treatment'];

    public function parse(string $path, array $mapping = []): array
    {
        $handle = fopen($path, 'r');
        $first = fgets($handle);
        if (! $first) {
            fclose($handle);
            throw ValidationException::withMessages(['csv' => 'Het CSV bestand is leeg']);
        }rewind($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        $headers = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)), $headers ?? []);
        $rows = [];
        $seenIds = [];
        $seenSerials = [];
        $line = 1;
        if (count($headers) !== count(array_unique($headers))) {
            fclose($handle);
            throw ValidationException::withMessages(['csv' => 'Dubbele kolomnaam in CSV']);
        }
        while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($line > 5001) {
                fclose($handle);
                throw ValidationException::withMessages(['csv' => 'Maximaal 5000 rijen']);
            }if (count($values) === 1 && trim($values[0] ?? '') === '') {
                continue;
            }
            $raw = array_combine($headers, array_pad(array_slice($values, 0, count($headers)), count($headers), ''));
            $data = [];
            foreach (self::FIELDS as $field) {
                $source = $mapping[$field] ?? $field;
                $data[$field] = trim($raw[$source] ?? '');
            }
            $errors = [];
            $data['status'] = $data['status'] ?: 'awaiting_inspection';
            $data['keyboard_layout'] = $data['keyboard_layout'] ?: 'QWERTY';
            $data['operating_system'] = $data['operating_system'] ?: 'Windows 11 Pro';
            $data['resolution'] = $data['resolution'] ?: 'Full HD';
            $data['vat_treatment'] = $data['vat_treatment'] ?: 'standard';
            $rules = ['brand' => 'required|string|max:60', 'model' => 'required|string|max:120', 'inventory_id' => 'required|string|max:100', 'serial_number' => 'nullable|string|max:100', 'processor' => 'required|string|max:100', 'processor_family' => 'required|in:i3,i5,i7,i9,Ryzen', 'processor_generation' => 'required|integer|min:1|max:30', 'ram_gb' => 'required|integer|min:4|max:128', 'ssd_gb' => 'required|integer|min:64|max:8192', 'screen_inches' => 'required|numeric|min:10|max:18', 'battery_health' => 'nullable|integer|min:0|max:100', 'grade' => 'required|in:A,B,C', 'status' => 'in:'.implode(',', InventoryUnit::STATUSES), 'keyboard_layout' => 'in:QWERTY,AZERTY', 'vat_treatment' => 'in:standard,margin', 'purchase_date' => 'nullable|date'];
            $data['screen_inches'] = str_replace(',', '.', $data['screen_inches']);
            if ($data['battery_health'] === '') {
                $data['battery_health'] = null;
            }if ($data['purchase_date'] === '') {
                $data['purchase_date'] = null;
            }
            $validator = Validator::make($data, $rules);
            $errors = array_merge($errors, $validator->errors()->all());
            if (! in_array($data['resolution'], ['HD', 'Full HD', '4K'], true)) {
                $errors[] = 'Unknown resolution';
            }
            if (! in_array($data['operating_system'], ['Windows 11 Pro', 'Linux', 'No OS'], true)) {
                $errors[] = 'Unknown operating system';
            }
            if (in_array($data['status'], ['reserved', 'sold'], true)) {
                $errors[] = 'Reserved/sold status requires an order workflow';
            }
            foreach (['purchase_price' => 'purchase_cents', 'refurbishment_cost' => 'refurbishment_cents', 'selling_price' => 'selling_cents'] as $source => $target) {
                try {
                    $data[$target] = Money::parse($data[$source] ?: ($source === 'refurbishment_cost' ? '0' : ''));
                } catch (\InvalidArgumentException) {
                    $errors[] = 'Invalid '.$source;
                }
            }
            $serialHash = $data['serial_number'] !== '' ? hash('sha256', strtoupper($data['serial_number'])) : null;
            if (isset($seenIds[$data['inventory_id']])) {
                $errors[] = 'Duplicate inventory ID within this file';
            }$seenIds[$data['inventory_id']] = true;
            if ($serialHash && isset($seenSerials[$serialHash])) {
                $errors[] = 'Duplicate serial within this file';
            }if ($serialHash) {
                $seenSerials[$serialHash] = true;
            }
            $existing = InventoryUnit::where('inventory_id', $data['inventory_id'])->first();
            $serialExisting = $serialHash ? InventoryUnit::where('serial_hash', $serialHash)->first() : null;
            if ($serialExisting && (! $existing || $existing->id !== $serialExisting->id)) {
                $errors[] = 'Serial belongs to another inventory ID';
            }
            if ($existing && in_array($existing->status, ['reserved', 'sold'], true)) {
                $errors[] = 'Reserved or sold inventory cannot be overwritten';
            }
            $data['serial_hash'] = $serialHash;
            $rows[] = ['line' => $line, 'data' => $data, 'errors' => $errors, 'duplicate_id' => $existing?->id];
        }
        fclose($handle);

        return ['headers' => $headers, 'mapping' => $mapping, 'rows' => $rows, 'valid' => count(array_filter($rows, fn ($r) => ! $r['errors'])), 'invalid' => count(array_filter($rows, fn ($r) => (bool) $r['errors']))];
    }

    public function import(array $preview, string $duplicateAction, int $userId, string $filename): array
    {
        $report = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($preview['rows'] as $row) {
            if ($row['errors']) {
                $report['errors'][] = ['line' => $row['line'], 'errors' => $row['errors']];

                continue;
            }
            if ($row['duplicate_id'] && $duplicateAction === 'skip') {
                $report['skipped']++;

                continue;
            }
            try {
                DB::transaction(function () use ($row, $userId, &$report) {
                    $d = $row['data'];
                    $slug = Str::slug($d['brand'].' '.$d['model']);
                    $model = ProductModel::firstOrCreate(['slug' => $slug], ['brand' => $d['brand'], 'name' => $d['model'], 'description' => ['nl' => 'Nieuwe import. Vul de productomschrijving aan voordat u publiceert.'], 'category' => 'business', 'images' => [], 'supported_ram' => [(int) $d['ram_gb']], 'supported_ssd' => [(int) $d['ssd_gb']], 'operating_systems' => [$d['operating_system']], 'specifications' => [], 'published' => false]);
                    $existing = $row['duplicate_id'] ? InventoryUnit::whereKey($row['duplicate_id'])->lockForUpdate()->first() : null;
                    if ($existing && in_array($existing->status, ['reserved', 'sold'], true)) {
                        throw new \RuntimeException('Inventory became reserved or sold after preview');
                    }
                    $fields = ['inventory_id', 'serial_number', 'serial_hash', 'processor', 'processor_family', 'processor_generation', 'ram_gb', 'ssd_gb', 'screen_inches', 'resolution', 'keyboard_layout', 'operating_system', 'battery_health', 'grade', 'defects', 'purchase_cents', 'refurbishment_cents', 'selling_cents', 'status', 'purchase_date', 'internal_notes', 'vat_treatment'];
                    $attributes = array_intersect_key($d, array_flip($fields));
                    $attributes['product_model_id'] = $model->id;
                    $attributes['serial_number'] = $attributes['serial_number'] ?: null;
                    $old = $existing?->status;
                    $unit = $existing ?? new InventoryUnit;
                    $unit->fill($attributes)->save();
                    InventoryHistory::create(['inventory_unit_id' => $unit->id, 'user_id' => $userId, 'from_status' => $old, 'to_status' => $unit->status, 'note' => 'CSV import']);
                    DB::table('admin_audit_logs')->insert(['user_id' => $userId, 'action' => $existing ? 'inventory.csv_update' : 'inventory.csv_create', 'entity' => 'inventory_units', 'entity_id' => $unit->id, 'created_at' => now(), 'updated_at' => now()]);
                    $report[$existing ? 'updated' : 'imported']++;
                }, 5);
            } catch (\Throwable $e) {
                $report['errors'][] = ['line' => $row['line'], 'errors' => ['Could not import row: '.$e->getMessage()]];
            }
        }
        DB::table('csv_imports')->insert(['user_id' => $userId, 'filename' => basename($filename), 'status' => 'completed', 'report' => json_encode($report), 'created_at' => now(), 'updated_at' => now()]);

        return $report;
    }
}
