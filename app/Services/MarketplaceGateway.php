<?php

namespace App\Services;

use App\Models\MarketplaceConnection;
use App\Models\MarketplaceDraft;
use App\Models\MarketplaceListing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MarketplaceGateway
{
    public const CHANNELS = [
        ['id' => 'ebay', 'provider' => 'ebay', 'name' => 'eBay', 'short' => 'eb', 'mode' => 'api', 'url' => 'https://www.ebay.nl/', 'note' => 'Gratis API-toegang; eventuele eBay-plaatsings- en verkoopkosten blijven gelden.'],
        ['id' => 'marktplaats', 'provider' => 'marktplaats', 'name' => 'Marktplaats', 'short' => 'mp', 'mode' => 'restricted', 'url' => 'https://www.marktplaats.nl/', 'note' => 'Directe API: vooraf goedgekeurde toegang vereist. Geen GoedGeplaatst-abonnement; platformtarieven kunnen gelden.'],
        ['id' => 'belgium', 'provider' => 'belgium', 'name' => '2dehands / 2ememain', 'short' => '2h', 'mode' => 'restricted', 'url' => 'https://www.2dehands.be/', 'note' => 'Directe Belgische API met vooraf goedgekeurde toegang. Eén advertentie voor beide taalversies.'],
        ['id' => 'facebook', 'name' => 'Facebook Marketplace', 'short' => 'f', 'mode' => 'manual', 'url' => 'https://www.facebook.com/marketplace/create/item/', 'note' => 'Alleen een advertentiepakket; geen geverifieerde algemene plaatsings-API ingebouwd.'],
        ['id' => 'tweakers', 'name' => 'Tweakers Vraag & Aanbod', 'short' => 'tw', 'mode' => 'manual', 'url' => 'https://tweakers.net/aanbod/', 'note' => 'Alleen een advertentiepakket; geen geverifieerde openbare plaatsings-API ingebouwd.'],
    ];

    public function configured(string $provider): bool
    {
        $c = MarketplaceConnection::forProvider($provider);
        $keys = $provider === 'ebay' ? ['clientId', 'clientSecret', 'refreshToken', 'locationKey', 'fulfillmentPolicyId', 'paymentPolicyId', 'returnPolicyId'] : ['clientId', 'clientSecret', 'refreshToken', 'postcode'];
        foreach ($keys as $key) {
            if (empty($c[$key])) {
                return false;
            }
        }
        return true;
    }

    public function send(string $url, string $method = 'GET', ?array $body = null, array $headers = [], bool $form = false): array
    {
        try {
            $request = Http::timeout(25)->connectTimeout(10)->withoutRedirecting()->withHeaders($headers);
            if ($form) {
                $request = $request->asForm();
            }
            $response = $request->send($method, $url, $body === null ? [] : [$form ? 'form_params' : 'json' => $body]);
        } catch (ConnectionException) {
            throw new MarketplaceGatewayException('Verbinding onderbroken. Controleer de externe advertentie voordat je opnieuw plaatst.', $method !== 'GET');
        }
        $data = $response->json();
        if ($response->failed() || $response->redirect()) {
            throw new MarketplaceGatewayException('Platform HTTP '.$response->status().': '.substr(json_encode($data['errors'] ?? $data['error_description'] ?? $data['error'] ?? 'Verzoek afgewezen'), 0, 1000), $response->serverError() && $method !== 'GET');
        }
        if ($response->body() !== '' && ! is_array($data)) {
            throw new MarketplaceGatewayException('Onleesbaar antwoord van het platform.', $method !== 'GET');
        }
        return $data ?? [];
    }

    public function auth(string $provider, bool $application = false): array
    {
        $c = MarketplaceConnection::forProvider($provider);
        if (empty($c['clientId']) || empty($c['clientSecret']) || (! $application && empty($c['refreshToken']))) {
            throw new MarketplaceGatewayException('Vul eerst je eigen OAuth-gegevens in bij Koppelingen.');
        }
        if ($provider === 'ebay') {
            $origin = ($c['sandbox'] ?? true) ? 'https://api.sandbox.ebay.com' : 'https://api.ebay.com';
            $form = $application ? ['grant_type' => 'client_credentials', 'scope' => 'https://api.ebay.com/oauth/api_scope'] : ['grant_type' => 'refresh_token', 'refresh_token' => $c['refreshToken'], 'scope' => 'https://api.ebay.com/oauth/api_scope/sell.inventory https://api.ebay.com/oauth/api_scope/sell.account'];
            $d = $this->send($origin.'/identity/v1/oauth2/token', 'POST', $form, ['Authorization' => 'Basic '.base64_encode($c['clientId'].':'.$c['clientSecret'])], true);
        } else {
            $domain = $provider === 'belgium' ? '2dehands.be' : 'marktplaats.nl';
            $origin = 'https://api.'.$domain;
            $d = $this->send('https://auth.'.$domain.'/accounts/oauth/token', 'POST', ['grant_type' => 'refresh_token', 'client_id' => $c['clientId'], 'client_secret' => $c['clientSecret'], 'refresh_token' => $c['refreshToken']], [], true);
            if (! empty($d['refresh_token']) && $d['refresh_token'] !== $c['refreshToken']) {
                MarketplaceConnection::updateOrCreate(['provider' => $provider], ['credentials' => array_merge($c, ['refreshToken' => $d['refresh_token']])]);
            }
        }
        if (empty($d['access_token'])) {
            throw new MarketplaceGatewayException('Het platform gaf geen toegangstoken terug.');
        }
        return [$origin, ['Authorization' => 'Bearer '.$d['access_token'], 'Accept' => 'application/hal+json, application/json']];
    }

    public function listing(MarketplaceDraft $draft, string $channel): array
    {
        return $draft->listings()->where('channel', $channel)->first()?->data ?? [];
    }

    public function remember(MarketplaceDraft $draft, string $channel, array $value): void
    {
        MarketplaceListing::updateOrCreate(['marketplace_draft_id' => $draft->id, 'channel' => $channel], ['data' => array_merge($this->listing($draft, $channel), $value)]);
    }

    private function images(array $p): array
    {
        if (! str_starts_with(config('app.url'), 'https://')) {
            throw new MarketplaceGatewayException('Stel APP_URL in op het openbare HTTPS-adres van Revilo voordat je foto’s publiceert.');
        }
        return array_map(fn ($photo) => rtrim(config('app.url'), '/').'/marketplace-photos/'.$photo['file'], $p['photos']);
    }

    private function html(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    public function perform(MarketplaceDraft $draft, array $p, string $channel, string $action): array
    {
        return $channel === 'ebay' ? $this->ebay($draft, $p, $action) : $this->classified($draft, $p, $channel, $action);
    }

    private function ebay(MarketplaceDraft $draft, array $p, string $action): array
    {
        [$origin, $headers] = $this->auth('ebay');
        $c = MarketplaceConnection::forProvider('ebay');
        $headers['Content-Language'] = $c['locale'] ?? 'nl-NL';
        $call = fn ($path, $method = 'GET', $body = null) => $this->send($origin.'/sell/inventory/v1'.$path, $method, $body, $headers);
        $old = $this->listing($draft, 'ebay');
        $offerId = $old['offerId'] ?? null;
        $sku = $p['sku'];
        if (! $offerId) {
            try {
                $offers = $call('/offer?sku='.rawurlencode($sku).'&marketplace_id='.rawurlencode($c['marketplaceId'] ?? 'EBAY_NL'));
            } catch (MarketplaceGatewayException $error) {
                if (! str_contains($error->getMessage(), '404') && ! str_contains($error->getMessage(), '25713')) {
                    throw $error;
                }
                $offers = [];
            }
            $offerId = $offers['offers'][0]['offerId'] ?? null;
            if ($offerId) {
                $this->remember($draft, 'ebay', ['offerId' => $offerId]);
            }
        }
        $current = $offerId ? $call('/offer/'.rawurlencode($offerId)) : null;
        if ($current && (($current['sku'] ?? $sku) !== $sku || ($current['marketplaceId'] ?? $c['marketplaceId']) !== $c['marketplaceId'])) {
            throw new MarketplaceGatewayException('Het gekoppelde eBay-offer hoort bij een andere SKU of marktplaats.');
        }
        if ($action === 'refresh') {
            if (! $current) {
                throw new MarketplaceGatewayException('Geen eBay-offer gekoppeld.');
            }
            return ['status' => $current['status'] === 'PUBLISHED' ? 'live' : 'ended', 'message' => 'eBay-status: '.$current['status']];
        }
        if ($action === 'withdraw') {
            if ($current) {
                $call('/offer/'.rawurlencode($offerId).'/withdraw', 'POST', []);
            }
            return ['status' => 'ended', 'message' => 'eBay-advertentie beëindigd.'];
        }
        if ($action === 'update' && (($current['status'] ?? '') !== 'PUBLISHED' || ($current['availableQuantity'] ?? 1) === 0)) {
            throw new MarketplaceGatewayException('Deze eBay-advertentie is niet actief of heeft geen voorraad. Controleer of de laptop verkocht is.');
        }
        $product = ['title' => $p['title'], 'description' => $this->html($p['description']), 'aspects' => (object) ($p['ebayAspects'] ?? []), 'imageUrls' => $this->images($p)];
        $call('/inventory_item/'.rawurlencode($sku), 'PUT', ['availability' => ['shipToLocationAvailability' => ['quantity' => 1]], 'condition' => $p['condition'], 'conditionDescription' => $p['conditionDescription'] ?: null, 'product' => $product]);
        $payload = ['availableQuantity' => 1, 'categoryId' => (string) $p['ebayCategoryId'], 'listingDescription' => $this->html($p['description']), 'listingDuration' => 'GTC', 'merchantLocationKey' => $c['locationKey'], 'listingPolicies' => ['fulfillmentPolicyId' => $c['fulfillmentPolicyId'], 'paymentPolicyId' => $c['paymentPolicyId'], 'returnPolicyId' => $c['returnPolicyId']], 'pricingSummary' => ['price' => ['value' => number_format($p['priceCents'] / 100, 2, '.', ''), 'currency' => 'EUR']]];
        if ($offerId) {
            $call('/offer/'.rawurlencode($offerId), 'PUT', $payload);
        } else {
            $created = $call('/offer', 'POST', array_merge($payload, ['sku' => $sku, 'marketplaceId' => $c['marketplaceId'], 'format' => 'FIXED_PRICE']));
            $offerId = $created['offerId'] ?? null;
            if (! $offerId) {
                throw new MarketplaceGatewayException('Geen eBay-offer-ID ontvangen. Controleer het platform vóór opnieuw proberen.', true);
            }
            $this->remember($draft, 'ebay', ['offerId' => $offerId]);
        }
        $remoteId = $current['listing']['listingId'] ?? $old['remoteId'] ?? '';
        if (($current['status'] ?? '') !== 'PUBLISHED') {
            $created = $call('/offer/'.rawurlencode($offerId).'/publish', 'POST', []);
            $remoteId = $created['listingId'] ?? $remoteId;
        }
        return ['offerId' => (string) $offerId, 'remoteId' => (string) $remoteId, 'status' => 'live', 'message' => ($c['sandbox'] ?? true) ? 'eBay bevestigt de sandbox-advertentie; niet publiek.' : 'eBay bevestigt plaatsing / wijziging.'];
    }

    private function classified(MarketplaceDraft $draft, array $p, string $channel, string $action): array
    {
        [$origin, $headers] = $this->auth($channel);
        $call = fn ($path, $method = 'GET', $body = null) => $this->send($origin.'/v2'.$path, $method, $body, $headers);
        $old = $this->listing($draft, $channel);
        $id = $old['remoteId'] ?? null;
        if ($action === 'refresh') {
            if (! $id) {
                throw new MarketplaceGatewayException('Geen advertentie-ID gekoppeld.');
            }
            $d = $call('/advertisements/'.rawurlencode($id));
            return ['status' => ($d['status'] ?? '') === 'online' ? 'live' : 'inactive', 'message' => 'Platformstatus: '.($d['status'] ?? 'onbekend')];
        }
        if ($action === 'withdraw') {
            if ($id) {
                $call('/advertisements/'.rawurlencode($id), 'DELETE');
            }
            return ['status' => 'ended', 'message' => 'Advertentie verwijderd.'];
        }
        $category = (int) ($p[$channel === 'belgium' ? 'belgiumCategoryId' : 'mpCategoryId'] ?? 0);
        if (isset($old['categoryId']) && $old['categoryId'] !== $category) {
            throw new MarketplaceGatewayException('Wijzig de categorie van een gekoppelde advertentie niet.');
        }
        $translations = [['locale' => $channel === 'belgium' ? 'nl-BE' : 'nl-NL', 'title' => $p['title'], 'description' => $this->html($p['description'])]];
        if ($channel === 'belgium' && ! empty($p['titleFr']) && ! empty($p['descriptionFr'])) {
            $translations[] = ['locale' => 'fr-BE', 'title' => $p['titleFr'], 'description' => $this->html($p['descriptionFr'])];
        }
        $payload = array_merge($p[$channel === 'belgium' ? 'belgiumAttributes' : 'mpAttributes'] ?? [], ['translations' => $translations, 'priceModel' => ['modelType' => 'fixed', 'askingPrice' => $p['priceCents']]]);
        if (! $id) {
            if ($old['uncertain'] ?? false) {
                throw new MarketplaceGatewayException('Eerdere plaatsing onzeker. Controleer je account en koppel het bestaande advertentie-ID.');
            }
            if ($action === 'update') {
                throw new MarketplaceGatewayException('Plaats of koppel eerst de advertentie.');
            }
            $c = MarketplaceConnection::forProvider($channel);
            $this->remember($draft, $channel, ['uncertain' => true]);
            try {
                $d = $call('/advertisements', 'POST', array_merge($payload, ['categoryId' => $category, 'location' => ['postcode' => str_replace(' ', '', $c['postcode'])]]));
            } catch (MarketplaceGatewayException $error) {
                if (! $error->uncertain) {
                    $this->remember($draft, $channel, ['uncertain' => false]);
                }
                throw $error;
            }
            $id = $d['itemId'] ?? basename($d['_links']['self']['href'] ?? '');
            if (! preg_match('/^[a-z]\d+$/', $id)) {
                throw new MarketplaceGatewayException('Geen geldig advertentie-ID teruggekregen. Controleer het platform.', true);
            }
            $this->remember($draft, $channel, ['remoteId' => $id, 'categoryId' => $category, 'uncertain' => false]);
        } else {
            $patch = [];
            foreach ($payload as $key => $value) {
                $patch[] = ['op' => 'add', 'path' => '/'.str_replace(['~', '/'], ['~0', '~1'], $key), 'value' => $value];
            }
            $call('/advertisements/'.rawurlencode($id), 'PATCH', $patch);
        }
        $call('/advertisements/'.rawurlencode($id).'/images', 'POST', ['urls' => $this->images($p), 'replaceAll' => true]);
        return ['remoteId' => $id, 'categoryId' => $category, 'status' => 'pending', 'message' => 'Gegevens verzonden; foto’s worden verwerkt. Vernieuw de externe status.'];
    }

    public function lookup(string $provider, string $kind, string $query): array
    {
        [$origin, $headers] = $this->auth($provider, $provider === 'ebay' && $kind !== 'account');
        if ($provider === 'ebay') {
            $c = MarketplaceConnection::forProvider('ebay');
            if ($kind === 'account') {
                $data = [];
                foreach (['fulfillment_policy', 'payment_policy', 'return_policy'] as $name) {
                    $data[$name] = $this->send($origin.'/sell/account/v1/'.$name.'?marketplace_id='.rawurlencode($c['marketplaceId'] ?? 'EBAY_NL'), headers: $headers);
                }
                $data['locations'] = $this->send($origin.'/sell/inventory/v1/location', headers: $headers);
                return $data;
            }
            $tree = $this->send($origin.'/commerce/taxonomy/v1/get_default_category_tree_id?marketplace_id='.rawurlencode($c['marketplaceId'] ?? 'EBAY_NL'), headers: $headers);
            $path = $origin.'/commerce/taxonomy/v1/category_tree/'.rawurlencode($tree['categoryTreeId']);
            return $this->send($path.($kind === 'categories' ? '/get_category_suggestions?q='.rawurlencode($query) : '/get_item_aspects_for_category?category_id='.rawurlencode($query)), headers: $headers);
        }
        if ($kind === 'attributes' && ! preg_match('~^\d+/\d+$~', $query)) {
            throw new MarketplaceGatewayException('Gebruik hoofdgroep/categorie uit de categorie-uitvoer.');
        }
        return $this->send($origin.'/v2/'.match ($kind) {'account' => 'me', 'attributes' => 'categories/'.$query.'/attributes', default => 'categories'}, headers: $headers);
    }
}
