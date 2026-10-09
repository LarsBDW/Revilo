<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Models\Coupon;
use App\Models\EmailRecord;
use App\Models\InventoryHistory;
use App\Models\InventoryUnit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductModel;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Services\CsvImportService;
use App\Services\EmailService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\AdminResources;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    private function audit(string $action, ?string $entity = null, ?int $id = null): void
    {
        DB::table('admin_audit_logs')->insert(['user_id' => auth()->id(), 'action' => $action, 'entity' => $entity, 'entity_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function dashboard()
    {
        app(InventoryService::class)->expire();
        $paid = Order::where('is_test', false)->whereNotNull('paid_at')->whereNotIn('status', ['cancelled', 'refunded']);
        $revenue = (clone $paid)->sum('total_cents');
        $paidCount = (clone $paid)->count();
        $costs = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.is_test', false)->whereNotNull('orders.paid_at')->whereNotIn('orders.status', ['cancelled', 'refunded'])->selectRaw('COALESCE(SUM(order_items.purchase_cents + order_items.refurbishment_cents + order_items.upgrade_cost_cents + order_items.vat_cents),0) AS costs')->value('costs');
        $extraCosts = (clone $paid)->sum('packaging_cost_cents') + (clone $paid)->sum('shipping_cost_cents') + (clone $paid)->sum('payment_fee_cents');
        $shippingVat = (clone $paid)->get()->sum(fn ($o) => Money::vat($o->shipping_cents, 'standard'));
        $stats = ['revenue' => $revenue, 'paid_orders' => $paidCount, 'average' => $paidCount ? intdiv($revenue, $paidCount) : 0, 'profit' => $revenue - $costs - $extraCosts - $shippingVat, 'units' => InventoryUnit::where('status', 'available')->count(), 'inventory_value' => InventoryUnit::whereIn('status', ['available', 'reserved', 'under_repair', 'awaiting_inspection'])->sum('purchase_cents'), 'pending' => Order::where('status', 'awaiting_payment')->count(), 'returns' => DB::table('return_requests')->where('status', 'requested')->count()];
        $recent = Order::latest()->take(8)->get();
        $lowStock = Component::whereRaw('quantity-reserved <= 2')->get();
        $monthExpression = DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m',created_at)" : "DATE_FORMAT(created_at,'%Y-%m')";
        $monthly = (clone $paid)->selectRaw($monthExpression.' as month, SUM(total_cents) as total')->groupBy('month')->orderBy('month')->get();

        return view('admin.dashboard', compact('stats', 'recent', 'lowStock', 'monthly'));
    }

    public function listing(Request $r, string $resource)
    {
        $definition = AdminResources::definitions()[$resource] ?? null;
        abort_unless($definition, 404);
        $class = $definition['model'];
        $q = $class::query();
        if ($r->filled('q')) {
            $term = '%'.$r->q.'%';
            $columns = array_filter($definition['columns'], fn ($c) => ! in_array($c, ['published', 'featured', 'ram_gb', 'ssd_gb', 'selling_cents', 'capacity', 'quantity', 'reserved', 'upgrade_cents', 'value', 'uses', 'active'], true));
            $q->where(function ($q) use ($columns, $term) {
                foreach ($columns as $c) {
                    $q->orWhere($c, 'like', $term);
                }
            });
        }
        if ($resource === 'inventory' && $r->filled('status')) {
            $q->where('status', $r->status);
        }
        $records = $q->latest()->paginate(20)->withQueryString();

        return view('admin.list', compact('definition', 'records', 'resource'));
    }

    public function form(string $resource, ?int $id = null)
    {
        $definition = AdminResources::definitions()[$resource] ?? null;
        abort_unless($definition, 404);
        $class = $definition['model'];
        $record = $id ? $class::findOrFail($id) : new $class;
        $models = ProductModel::orderBy('brand')->get();
        $history = $resource === 'inventory' && $id ? $record->history()->latest()->get() : collect();
        $tests = $resource === 'inventory' && $id ? $record->tests->keyBy('test') : collect();

        return view('admin.form', compact('definition', 'record', 'resource', 'models', 'history', 'tests'));
    }

    public function save(Request $r, string $resource, ?int $id = null)
    {
        $definition = AdminResources::definitions()[$resource] ?? null;
        abort_unless($definition, 404);
        $class = $definition['model'];
        $record = $id ? $class::findOrFail($id) : new $class;
        $rules = array_map(fn ($field) => $field[2], $definition['fields']);
        $d = $r->validate($rules);
        foreach ($definition['fields'] as $field => $details) {
            if ($details[1] === 'checkbox') {
                $d[$field] = $r->boolean($field);
            }if ($details[1] === 'money') {
                try {
                    $d[$field] = Money::parse($d[$field]);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages([$field => 'Ongeldig bedrag']);
                }
            }
        }
        if ($resource === 'products') {
            $d['description'] = ['nl' => $d['description_nl'], 'en' => $d['description_en'] ?? '', 'de' => $d['description_de'] ?? ''];
            unset($d['description_nl'],$d['description_en'],$d['description_de']);
            foreach (['supported_ram', 'supported_ssd'] as $f) {
                $d[$f] = array_values(array_unique(array_map('intval', explode(',', $d[$f]))));
                if (in_array(0, $d[$f], true)) {
                    throw ValidationException::withMessages([$f => 'Gebruik positieve GB waarden']);
                }
            }
            $d['operating_systems'] = array_map('trim', explode(',', $d['operating_systems']));
            if (array_diff($d['operating_systems'], ['Windows 11 Pro', 'Linux', 'No OS'])) {
                throw ValidationException::withMessages(['operating_systems' => 'Onbekend besturingssysteem']);
            }
            $d['seo'] = ['title' => $d['seo_title'] ?? null, 'description' => $d['seo_description'] ?? null];
            unset($d['seo_title'],$d['seo_description']);
            if (ProductModel::where('slug', $d['slug'])->where('id', '!=', $id ?? 0)->exists()) {
                throw ValidationException::withMessages(['slug' => 'Deze slug bestaat al']);
            }
            $d['images'] = $record->images ?? [];
            $d['specifications'] = $record->specifications ?? [];
        }
        if ($resource === 'inventory') {
            if (InventoryUnit::where('inventory_id', $d['inventory_id'])->where('id', '!=', $id ?? 0)->exists()) {
                throw ValidationException::withMessages(['inventory_id' => 'Voorraad ID bestaat al']);
            }
            $d['serial_hash'] = ! empty($d['serial_number']) ? hash('sha256', strtoupper(trim($d['serial_number']))) : null;
            if ($d['serial_hash'] && InventoryUnit::where('serial_hash', $d['serial_hash'])->where('id', '!=', $id ?? 0)->exists()) {
                throw ValidationException::withMessages(['serial_number' => 'Serienummer bestaat al']);
            }
            if ($record->exists && in_array($record->status, ['reserved', 'sold'], true)) {
                throw ValidationException::withMessages(['status' => 'Gereserveerde/verkochte laptops beheert u via bestellingen en retouren']);
            }
            if (in_array($d['status'], ['reserved', 'sold'], true)) {
                throw ValidationException::withMessages(['status' => 'Deze status wordt uitsluitend door de bestelworkflow beheerd']);
            }
        }
        if ($resource === 'components') {
            $d['compatible_models'] = array_map('intval', explode(',', $d['compatible_models']));
            if (ProductModel::whereIn('id', $d['compatible_models'])->count() !== count(array_unique($d['compatible_models']))) {
                throw ValidationException::withMessages(['compatible_models' => 'Onbekend product ID']);
            }if ($d['quantity'] < ($record->reserved ?? 0)) {
                throw ValidationException::withMessages(['quantity' => 'Aantal mag niet lager zijn dan gereserveerd']);
            }if (Component::where('sku', $d['sku'])->where('id', '!=', $id ?? 0)->exists()) {
                throw ValidationException::withMessages(['sku' => 'SKU bestaat al']);
            }
        }
        if ($resource === 'discounts') {
            $d['code'] = strtoupper($d['code']);
            if ($d['type'] === 'percent' && $d['value'] > 100) {
                throw ValidationException::withMessages(['value' => 'Percentage maximaal 100']);
            }if (Coupon::where('code', $d['code'])->where('id', '!=', $id ?? 0)->exists()) {
                throw ValidationException::withMessages(['code' => 'Code bestaat al']);
            }
        }
        DB::transaction(function () use ($record, $d, $resource) {
            if ($record->exists) {
                $locked = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                if ($resource === 'inventory' && in_array($locked->status, ['reserved', 'sold'], true)) {
                    throw ValidationException::withMessages(['status' => 'Laptop is inmiddels gereserveerd of verkocht']);
                }
                if ($resource === 'components' && $d['quantity'] < $locked->reserved) {
                    throw ValidationException::withMessages(['quantity' => 'Aantal mag niet lager zijn dan gereserveerd']);
                }
                if ($resource === 'components' && $locked->reserved > 0 && ($locked->type !== $d['type'] || $locked->capacity != (int) $d['capacity'] || $locked->compatible_models !== $d['compatible_models'])) {
                    throw ValidationException::withMessages(['type' => 'Compatibiliteit en type van gereserveerde onderdelen kunnen niet wijzigen']);
                }
                $record->setRawAttributes($locked->getAttributes(), true);
            }
            $old = $record->status;
            $record->fill($d)->save();
            if ($resource === 'inventory' && $old !== $record->status) {
                InventoryHistory::create(['inventory_unit_id' => $record->id, 'user_id' => auth()->id(), 'from_status' => $old, 'to_status' => $record->status, 'note' => 'Admin wijziging']);
            }$this->audit($resource.'.save', $record->getTable(), $record->id);
        }, 5);

        return redirect()->route('admin.edit', ['resource' => $resource, 'id' => $record->id])->with('success', 'Opgeslagen');
    }

    public function image(Request $r, string $resource, int $id)
    {
        abort_unless(in_array($resource, ['products', 'inventory']), 404);
        $d = $r->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=6000,max_height=6000']);
        $class = $resource === 'products' ? ProductModel::class : InventoryUnit::class;
        $record = $class::findOrFail($id);
        $path = $r->file('image')->store('photos', 'public');
        $images = $record->images ?? [];
        $images[] = Storage::url($path);
        $record->update(['images' => $images]);
        $this->audit('image.upload', $record->getTable(), $record->id);

        return back()->with('success', 'Foto toegevoegd');
    }

    public function tests(Request $r, int $id)
    {
        $unit = InventoryUnit::findOrFail($id);
        $d = $r->validate(['tests' => 'required|array', 'tests.*' => 'in:pass,fail,not_tested', 'notes' => 'nullable|string|max:3000']);
        $allowed = ['display', 'keyboard', 'trackpad', 'ram', 'ssd', 'battery', 'wifi', 'bluetooth', 'webcam', 'microphone', 'speakers', 'usb', 'video_outputs', 'charger', 'cooling', 'physical'];
        foreach ($d['tests'] as $test => $result) {
            abort_unless(in_array($test, $allowed, true), 422);
            $unit->tests()->updateOrCreate(['test' => $test], ['result' => $result, 'notes' => $d['notes'] ?? null, 'tested_at' => $result === 'not_tested' ? null : now(), 'user_id' => auth()->id()]);
        }$this->audit('inventory.tests', 'inventory_units', $id);

        return back()->with('success', 'Testresultaten opgeslagen');
    }

    public function orders(Request $r)
    {
        $q = Order::query();
        if ($r->filled('user_id')) {
            $q->where('user_id', (int) $r->user_id);
        }if ($r->filled('q')) {
            $q->where(fn ($q) => $q->where('number', 'like', '%'.$r->q.'%')->orWhere('email', 'like', '%'.$r->q.'%'));
        }if ($r->filled('status')) {
            $q->where('status', $r->status);
        }$orders = $q->latest()->paginate(20)->withQueryString();

        return view('admin.orders', compact('orders'));
    }

    public function costs(Request $r, Order $order)
    {
        $d = $r->validate(['packaging_cost_cents' => 'required|integer|min:0|max:1000000', 'shipping_cost_cents' => 'required|integer|min:0|max:1000000', 'payment_fee_cents' => 'required|integer|min:0|max:1000000']);
        $order->update($d);
        $this->audit('order.costs', 'orders', $order->id);

        return back()->with('success', 'Werkelijke kosten opgeslagen');
    }

    public function settings()
    {
        return view('admin.settings');
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['extended_warranty_cents' => 'required|integer|min:0|max:100000', 'shipping_nl_cents' => 'required|integer|min:0|max:100000', 'shipping_be_cents' => 'required|integer|min:0|max:100000', 'shipping_de_cents' => 'required|integer|min:0|max:100000', 'free_shipping_threshold_cents' => 'required|integer|min:0|max:1000000']);
        foreach ($d as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => ['cents' => (int) $value]]);
        }$this->audit('settings.pricing');

        return back()->with('success', 'Tarieven opgeslagen');
    }

    public function analytics()
    {
        $paid = Order::where('is_test', false)->whereNotNull('paid_at')->whereNotIn('status', ['cancelled', 'refunded'])->with('items')->get();
        $series = $paid->groupBy(fn ($o) => $o->created_at->format('Y-m'))->map(fn ($orders) => ['revenue' => $orders->sum('total_cents'), 'profit' => $orders->sum(fn ($o) => $o->total_cents - $o->vat_cents - $o->packaging_cost_cents - $o->shipping_cost_cents - $o->payment_fee_cents - $o->items->sum(fn ($i) => $i->purchase_cents + $i->refurbishment_cents + $i->upgrade_cost_cents))]);
        $daily = $paid->groupBy(fn ($o) => $o->created_at->format('Y-m-d'))->map(fn ($orders) => $orders->sum('total_cents'));
        $performance = $paid->flatMap(fn ($o) => $o->items)->groupBy('product_model_id')->map(fn ($items) => ['name' => $items->first()->snapshot['name'], 'quantity' => $items->count(), 'revenue' => $items->sum(fn ($i) => $i->price_cents - $i->discount_cents), 'profit' => $items->sum(fn ($i) => $i->price_cents - $i->discount_cents - $i->vat_cents - $i->purchase_cents - $i->refurbishment_cents - $i->upgrade_cost_cents)]);

        return view('admin.analytics', compact('series', 'daily', 'performance'));
    }

    public function order(Order $order)
    {
        $order->load('items.unit');
        $history = DB::table('order_history')->where('order_id', $order->id)->get();

        return view('admin.order', compact('order', 'history'));
    }

    public function transition(Request $r, Order $order, OrderService $service)
    {
        $d = $r->validate(['status' => 'required|string', 'carrier' => 'nullable|string|max:100', 'tracking_number' => 'nullable|string|max:120', 'tracking_url' => 'nullable|url:http,https|max:500', 'internal_notes' => 'nullable|string|max:5000']);
        $service->transition($order, $d['status'], $d);
        $this->audit('order.transition', 'orders', $order->id);

        return back()->with('success', 'Bestelstatus bijgewerkt');
    }

    public function importPage()
    {
        return view('admin.import', ['preview' => session('csv_preview'), 'report' => session('import_report')]);
    }

    public function preview(Request $r, CsvImportService $service)
    {
        $r->validate(['csv' => 'nullable|file|mimes:csv,txt|max:5120', 'mapping' => 'nullable|array', 'mapping.*' => 'nullable|string|max:100']);
        if ($r->hasFile('csv')) {
            $path = $r->file('csv')->store('imports', 'local');
            session(['csv_path' => $path, 'csv_filename' => $r->file('csv')->getClientOriginalName()]);
        }abort_unless(session('csv_path'), 422);
        $preview = $service->parse(Storage::disk('local')->path(session('csv_path')), $r->input('mapping', []));
        session(['csv_preview' => $preview]);

        return redirect()->route('admin.import');
    }

    public function import(Request $r, CsvImportService $service)
    {
        $d = $r->validate(['duplicate_action' => 'required|in:skip,update', 'confirm' => 'accepted']);
        $preview = session('csv_preview');
        abort_unless($preview, 422);
        $report = $service->import($preview, $d['duplicate_action'], auth()->id(), session('csv_filename'));
        session()->forget(['csv_preview', 'csv_path']);

        return redirect()->route('admin.import')->with('import_report', $report);
    }

    public function template()
    {
        return response()->streamDownload(function () {
            $h = fopen('php://output', 'w');
            fputcsv($h, CsvImportService::FIELDS, ';', '"', '');
            fputcsv($h, ['Dell', 'Latitude 5490', 'RV-EXAMPLE-001', 'SERIAL-EXAMPLE', 'Intel Core i5-8350U', 'i5', 8, 8, 256, 14, 'Full HD', 'QWERTY', 'Windows 11 Pro', 85, 'B', 'Lichte krassen', '40,00', '10,00', '149,00', 'awaiting_inspection', now()->format('Y-m-d'), 'Demo rij — vervangen door eigen gegevens', 'standard'], ';', '"', '');
            fclose($h);
        }, 'revilo-inventory-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function content()
    {
        $settings = Setting::orderBy('key')->get()->filter(fn ($s) => ! isset($s->value['cents']));

        return view('admin.content', compact('settings'));
    }

    public function saveContent(Request $r)
    {
        $d = $r->validate(['key' => 'required|regex:/^[a-z0-9_.-]+$/|max:100', 'nl' => 'nullable|string|max:10000', 'en' => 'nullable|string|max:10000', 'de' => 'nullable|string|max:10000']);
        Setting::updateOrCreate(['key' => $d['key']], ['value' => ['nl' => $d['nl'] ?? '', 'en' => $d['en'] ?? '', 'de' => $d['de'] ?? '']]);
        $this->audit('content.save');

        return back()->with('success', 'Inhoud opgeslagen');
    }

    public function emails()
    {
        $records = EmailRecord::latest()->paginate(20);
        $campaigns = DB::table('email_campaigns')->latest()->get();

        return view('admin.emails', compact('records', 'campaigns'));
    }

    public function email(EmailRecord $email)
    {
        return response($email->html)->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:;");
    }

    public function campaign(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'subject' => 'required|string|max:190', 'body' => 'required|string|max:10000', 'scheduled_at' => 'nullable|date|after:now', 'segment' => 'required|in:all,nl,en,de']);
        DB::table('email_campaigns')->insert(array_merge($d, ['status' => $r->filled('scheduled_at') ? 'scheduled' : 'draft', 'created_at' => now(), 'updated_at' => now()]));
        $this->audit('campaign.create');

        return back()->with('success', 'Campagne opgeslagen');
    }

    public function campaignPreview(int $campaign, EmailService $emails)
    {
        $c = DB::table('email_campaigns')->find($campaign);
        abort_unless($c, 404);
        $emails->record('campaign-preview-'.$c->id.'-'.now()->timestamp, auth()->user()->email, $c->subject, $c->body);

        return back()->with('success', in_array(config('mail.default'), ['log', 'array']) ? 'E-mailvoorbeeld aangemaakt; niet verzonden.' : 'Testbericht in wachtrij geplaatst.');
    }

    public function data(Request $r, string $section)
    {
        $definitions = ['customers' => ['table' => 'users', 'title' => 'Klanten', 'columns' => ['name', 'email', 'role', 'active', 'created_at']], 'returns' => ['table' => 'return_requests', 'title' => 'Retouren', 'columns' => ['order_id', 'order_item_id', 'reason', 'status', 'refund_status']], 'reviews' => ['table' => 'reviews', 'title' => 'Reviews', 'columns' => ['product_model_id', 'rating', 'body', 'approved', 'verified_purchase']], 'business' => ['table' => 'inquiries', 'title' => 'Zakelijke aanvragen', 'columns' => ['type', 'name', 'email', 'status', 'details']], 'audit' => ['table' => 'admin_audit_logs', 'title' => 'Activiteitenlog', 'columns' => ['user_id', 'action', 'entity', 'entity_id', 'created_at']], 'imports' => ['table' => 'csv_imports', 'title' => 'Importgeschiedenis', 'columns' => ['filename', 'status', 'report', 'created_at']], 'subscribers' => ['table' => 'subscriptions', 'title' => 'Abonnees', 'columns' => ['email', 'type', 'consent_at', 'confirmed_at', 'unsubscribed_at']]];
        $definitions['reports'] = ['table' => 'review_reports', 'title' => 'Reviewmeldingen', 'columns' => ['review_id', 'user_id', 'reason', 'created_at']];
        $definition = $definitions[$section] ?? null;
        abort_unless($definition, 404);
        $records = DB::table($definition['table'])->latest()->paginate(20);

        return view('admin.data', compact('section', 'definition', 'records'));
    }

    public function manageData(Request $r, string $section, int $id, InventoryService $inventory)
    {
        if ($section === 'reviews') {
            $d = $r->validate(['approved' => 'required|boolean', 'reply' => 'nullable|string|max:2000']);
            Review::findOrFail($id)->update($d);
        } elseif ($section === 'business') {
            $d = $r->validate(['status' => 'required|in:new,reviewing,quoted,accepted,rejected,completed', 'internal_notes' => 'nullable|string|max:3000', 'quoted_cents' => 'nullable|integer|min:0']);
            abort_unless(DB::table('inquiries')->where('id', $id)->exists(), 404);
            DB::table('inquiries')->where('id', $id)->update($d);
        } elseif ($section === 'customers') {
            $d = $r->validate(['active' => 'required|boolean']);
            $u = User::findOrFail($id);
            abort_if($u->isAdmin(), 422);
            $u->forceFill($d)->save();
            if (! $d['active']) {
                DB::table('sessions')->where('user_id', $id)->delete();
            }
        } elseif ($section === 'returns') {
            $d = $r->validate(['status' => 'required|in:requested,approved,received,inspected,rejected,closed', 'inspection_notes' => 'nullable|string|max:3000', 'refund_status' => 'required|in:not_started,pending,recorded', 'restock' => 'nullable|boolean']);
            DB::transaction(function () use ($id, $d, $inventory) {
                $return = DB::table('return_requests')->where('id', $id)->lockForUpdate()->first();
                abort_unless($return, 404);
                if (! empty($d['restock'])) {
                    abort_unless($d['status'] === 'inspected' && ! empty($d['inspection_notes']), 422);
                    $item = OrderItem::findOrFail($return->order_item_id);
                    $unit = InventoryUnit::whereKey($item->inventory_unit_id)->lockForUpdate()->firstOrFail();
                    abort_unless(in_array($unit->status, ['sold', 'returned'], true), 422);
                    $inventory->move($unit, 'awaiting_inspection', 'Returned; inspect and retest before availability');
                }unset($d['restock']);
                DB::table('return_requests')->where('id', $id)->update($d);
            });
        } else {
            abort(404);
        }$this->audit($section.'.update', null, $id);

        return back()->with('success', 'Bijgewerkt');
    }

    public function export(string $type)
    {
        abort_unless(in_array($type, ['inventory', 'financials', 'business']), 404);

        return response()->streamDownload(function () use ($type) {
            $h = fopen('php://output', 'w');
            $rows = $type === 'inventory' ? InventoryUnit::with('model')->get()->map(fn ($u) => ['inventory_id' => $u->inventory_id, 'brand' => $u->model->brand, 'model' => $u->model->name, 'status' => $u->status, 'purchase_cents' => $u->purchase_cents, 'selling_cents' => $u->selling_cents]) : ($type === 'financials' ? Order::where('is_test', false)->whereNotNull('paid_at')->get()->map(fn ($o) => ['number' => $o->number, 'status' => $o->status, 'total_cents' => $o->total_cents, 'vat_cents' => $o->vat_cents, 'date' => $o->created_at->toDateString()]) : DB::table('inquiries')->where('type', 'business')->get()->map(fn ($i) => ['name' => $i->name, 'email' => $i->email, 'status' => $i->status, 'details' => $i->details]));
            if ($rows->isNotEmpty()) {
                fputcsv($h, array_keys($rows->first()), ';', '"', '');
                foreach ($rows as $row) {
                    $safe = array_map(fn ($v) => is_string($v) && preg_match('/^[=+@-]/', $v) ? "'".$v : $v, $row);
                    fputcsv($h, $safe, ';', '"', '');
                }
            }fclose($h);
        }, 'revilo-'.$type.'.csv');
    }
}
