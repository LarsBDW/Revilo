<?php

namespace App\Http\Controllers;

use App\Models\InventoryUnit;
use App\Models\MarketplaceAction;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceDraft;
use App\Models\MarketplacePhoto;
use App\Services\MarketplaceCatalog;
use App\Services\MarketplaceGateway;
use App\Services\MarketplaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MarketplaceController extends Controller
{
    public function __construct(private MarketplaceCatalog $catalog, private MarketplaceGateway $gateway, private MarketplaceService $service) {}

    public function index()
    {
        return view('admin.marketplaces');
    }

    public function session()
    {
        return response()->json(['csrf' => csrf_token(), 'storage' => 'Revilo', 'baseUrl' => config('app.url'), 'starterImported' => true]);
    }

    public function products()
    {
        return response()->json(MarketplaceDraft::latest('id')->get()->map(fn ($d) => $this->catalog->serialize($d)));
    }

    public function inventory()
    {
        return response()->json(InventoryUnit::with('model')->whereNotIn('status', ['sold', 'archived'])->orderByDesc('id')->limit(300)->get()->map(fn ($u) => ['id' => $u->id, 'label' => $u->inventory_id.' · '.$u->model->brand.' '.$u->model->name.' · '.$u->ram_gb.'GB / '.$u->ssd_gb.'GB · '.$u->status]));
    }

    public function attach(Request $request)
    {
        $data = $request->validate(['unitId' => 'required|integer|exists:inventory_units,id']);
        return response()->json($this->catalog->serialize($this->catalog->attach(InventoryUnit::findOrFail($data['unitId']))));
    }

    public function save(Request $request)
    {
        $input = $request->validate([
            'id' => 'nullable|integer|exists:marketplace_drafts,id', 'version' => 'nullable|integer|min:1', 'catalogToken' => 'nullable|string|size:64',
            'title' => 'required|string|min:5|max:60', 'description' => 'required|string|min:5|max:10000',
            'titleFr' => 'nullable|string|max:60', 'descriptionFr' => 'nullable|string|max:10000',
            'priceCents' => 'required|integer|min:0|max:10000000', 'condition' => ['nullable', Rule::in(['NEW', 'USED_EXCELLENT', 'USED_VERY_GOOD', 'USED_GOOD', 'USED_ACCEPTABLE', 'FOR_PARTS_OR_NOT_WORKING'])],
            'conditionDescription' => 'nullable|string|max:3000', 'notes' => 'nullable|string|max:5000',
            'photos' => 'present|array|max:12', 'photos.*.file' => 'required|string|max:60|distinct|exists:marketplace_photos,file',
            'photos.*.name' => 'nullable|string|max:255', 'channels' => 'present|array|max:5', 'channels.*' => Rule::in(array_column(MarketplaceGateway::CHANNELS, 'id')),
            'ebayCategoryId' => 'nullable|digits_between:1,12', 'mpCategoryId' => 'nullable|digits_between:1,12', 'belgiumCategoryId' => 'nullable|digits_between:1,12',
            'ebayAspects' => 'present|array|max:100', 'ebayAspects.*' => 'array|max:30', 'ebayAspects.*.*' => 'string|max:1000',
            'mpAttributes' => 'present|array|max:100', 'belgiumAttributes' => 'present|array|max:100',
            'brand' => 'required|string|max:60', 'modelName' => 'required|string|max:120',
            'websiteCategory' => 'required|in:business,student,budget,premium,everyday', 'websitePublished' => 'required|boolean',
            'processor' => 'required|string|max:100', 'processorFamily' => 'required|in:i3,i5,i7,i9,Ryzen',
            'processorGeneration' => 'required|integer|min:1|max:30', 'ramGb' => 'required|integer|min:4|max:128',
            'ssdGb' => 'required|integer|min:64|max:8192', 'screenInches' => 'required|numeric|min:10|max:18',
            'resolution' => 'required|in:HD,Full HD,4K', 'graphics' => 'required|string|max:100',
            'keyboardLayout' => 'required|in:QWERTY,AZERTY', 'operatingSystem' => 'required|in:Windows 11 Pro,Linux,No OS',
            'grade' => 'required|in:A,B,C',
        ]);
        foreach (['mpAttributes', 'belgiumAttributes'] as $name) {
            if (array_intersect(array_keys($input[$name]), ['itemId', 'categoryId', 'translations', 'priceModel', 'location', 'seller', 'status'])) {
                throw ValidationException::withMessages([$name => 'Gebruik alleen toegestane categoriekenmerken.']);
            }
        }
        return response()->json($this->catalog->serialize($this->catalog->save($input)));
    }

    public function upload(Request $request)
    {
        $input = $request->validate(['base64' => 'required|string|max:6000000', 'name' => 'nullable|string|max:255']);
        $bytes = base64_decode($input['base64'], true);
        $image = $bytes ? @getimagesizefromstring($bytes) : false;
        if (! $image || strlen($bytes) > 4 * 1024 * 1024 || ! in_array($image['mime'], ['image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['photo' => 'Gebruik een echte JPG of PNG kleiner dan 4 MB.']);
        }
        $file = bin2hex(random_bytes(24)).($image['mime'] === 'image/jpeg' ? '.jpg' : '.png');
        Storage::disk('public')->put('marketplaces/'.$file, $bytes);
        $photo = MarketplacePhoto::create(['file' => $file, 'disk' => 'public', 'path' => 'marketplaces/'.$file, 'name' => $input['name'] ?? 'Productfoto']);
        return response()->json(['file' => $file, 'name' => $photo->name]);
    }

    public function media(string $file)
    {
        abort_unless(preg_match('/^[a-f0-9]{48}\.(jpg|jpeg|png|webp)$/', $file), 404);
        $photo = MarketplacePhoto::where('file', $file)->firstOrFail();
        abort_if(str_contains($photo->path, '..'), 404);
        $type = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg'};
        if ($photo->disk === 'assets') {
            abort_unless(str_starts_with($photo->path, 'assets/') && is_file(public_path($photo->path)), 404);
            return response()->file(public_path($photo->path), ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=86400']);
        }
        abort_unless(Storage::disk('public')->exists($photo->path), 404);
        return response(Storage::disk('public')->get($photo->path), 200, ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=86400']);
    }

    public function channels()
    {
        return response()->json(array_map(fn ($c) => array_merge($c, ['configured' => isset($c['provider']) && $this->gateway->configured($c['provider']), 'sandbox' => ($c['provider'] ?? '') === 'ebay' && (MarketplaceConnection::forProvider('ebay')['sandbox'] ?? true)]), MarketplaceGateway::CHANNELS));
    }

    public function connection(Request $request, string $provider)
    {
        $current = MarketplaceConnection::forProvider($provider);
        if ($request->isMethod('GET')) {
            foreach (['clientSecret', 'refreshToken'] as $name) {
                $current[$name.'Saved'] = ! empty($current[$name]);
                $current[$name] = '';
            }
            return response()->json($current);
        }
        $input = $request->validate(['clientId' => 'nullable|string|max:2000', 'clientSecret' => 'nullable|string|max:16000', 'refreshToken' => 'nullable|string|max:16000', 'postcode' => 'nullable|string|max:20', 'locationKey' => 'nullable|string|max:200', 'fulfillmentPolicyId' => 'nullable|string|max:40', 'paymentPolicyId' => 'nullable|string|max:40', 'returnPolicyId' => 'nullable|string|max:40', 'marketplaceId' => 'nullable|in:EBAY_NL,EBAY_BE,EBAY_DE', 'locale' => 'nullable|in:nl-NL,nl-BE,fr-BE,de-DE', 'sandbox' => 'sometimes|boolean']);
        if (MarketplaceAction::whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['connection' => 'Wacht tot lopende acties klaar zijn.']);
        }
        foreach (['clientSecret', 'refreshToken'] as $name) {
            $input[$name] = ($input[$name] ?? '') ?: ($current[$name] ?? '');
        }
        $linked = \App\Models\MarketplaceListing::where('channel', $provider)->exists();
        if ($linked && (($current['clientId'] ?? '') !== ($input['clientId'] ?? '') || ($current['sandbox'] ?? false) !== ($input['sandbox'] ?? false) || ($current['marketplaceId'] ?? '') !== ($input['marketplaceId'] ?? ''))) {
            throw ValidationException::withMessages(['connection' => 'Bestaande advertenties horen bij dit account, land en omgeving. Wissel niet naar een ander account of sandbox.']);
        }
        MarketplaceConnection::updateOrCreate(['provider' => $provider], ['credentials' => $input]);
        return response()->json(['ok' => true]);
    }

    public function operation(Request $request, MarketplaceDraft $draft)
    {
        $input = $request->validate(['action' => 'required|in:publish,update,withdraw,refresh', 'channels' => 'required|array|min:1|max:5', 'channels.*' => Rule::in(array_column(MarketplaceGateway::CHANNELS, 'id'))]);
        $action = $this->service->enqueue($draft, $input['channels'], $input['action']);
        app()->terminating(fn () => app(MarketplaceService::class)->process($action->id));
        return response()->json(['id' => $action->id, 'status' => 'queued'], 202);
    }

    public function sold(MarketplaceDraft $draft)
    {
        $action = $this->service->sold($draft);
        if ($action) {
            app()->terminating(fn () => app(MarketplaceService::class)->process($action->id));
        }
        return response()->json(['ok' => true, 'status' => $action ? 'queued' : 'completed']);
    }

    public function jobs()
    {
        return response()->json(MarketplaceAction::latest('id')->limit(100)->get()->map(fn ($a) => ['id' => $a->id, 'product' => $a->marketplace_draft_id, 'title' => $a->snapshot['title'], 'action' => $a->action, 'channels' => $a->channels, 'status' => $a->status, 'results' => $a->results, 'message' => $a->message, 'createdAt' => $a->created_at->toIso8601String()]));
    }

    public function link(Request $request, MarketplaceDraft $draft)
    {
        $input = $request->validate(['channel' => 'required|in:ebay,marktplaats,belgium', 'remoteId' => 'required|string|max:60']);
        if ($draft->actions()->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['action' => 'Wacht tot de lopende actie klaar is.']);
        }
        if (! preg_match($input['channel'] === 'ebay' ? '/^\d+$/' : '/^[a-z]\d+$/', $input['remoteId'])) {
            throw ValidationException::withMessages(['remoteId' => 'Gebruik een eBay-offer-ID, of een advertentie-ID met letter voor Marktplaats/2dehands.']);
        }
        $this->gateway->remember($draft, $input['channel'], ['status' => 'linked', 'uncertain' => false, $input['channel'] === 'ebay' ? 'offerId' : 'remoteId' => $input['remoteId']]);
        return response()->json(['ok' => true]);
    }

    public function lookup(Request $request)
    {
        $input = $request->validate(['provider' => 'required|in:ebay,marktplaats,belgium', 'kind' => 'required|in:account,categories,attributes', 'q' => 'nullable|string|max:200']);
        try {
            return response()->json($this->gateway->lookup($input['provider'], $input['kind'], $input['q'] ?? ''));
        } catch (\Throwable $error) {
            $message = $error->getMessage();
            foreach (MarketplaceConnection::forProvider($input['provider']) as $name => $value) {
                if (preg_match('/secret|token/i', $name) && is_string($value) && strlen($value) > 3) {
                    $message = str_replace($value, '[afgeschermd]', $message);
                }
            }
            return response()->json(['error' => $message], 422);
        }
    }

    public function pack(?MarketplaceDraft $draft = null)
    {
        $products = $draft ? [$this->catalog->serialize($draft)] : MarketplaceDraft::all()->map(fn ($d) => $this->catalog->serialize($d))->all();
        return response()->json(['products' => collect($products)->map(fn ($p) => collect($p)->only(['title', 'description', 'priceCents', 'photos', 'webshopUrl'])->all()), 'note' => 'Advertentiepakket; geen bevestiging van plaatsing.'])->header('Content-Disposition', 'attachment; filename="revilo-marketplaces.json"');
    }
}
