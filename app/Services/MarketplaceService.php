<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\MarketplaceAction;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketplaceService
{
    public function __construct(private MarketplaceCatalog $catalog, private MarketplaceGateway $gateway) {}

    public function enqueue(MarketplaceDraft $draft, array $channels, string $action): MarketplaceAction
    {
        return DB::transaction(function () use ($draft, $channels, $action) {
            $draft = MarketplaceDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($draft->actions()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['action' => 'Er loopt al een actie voor deze laptop.']);
            }
            $p = $this->catalog->serialize($draft);
            if (in_array($action, ['publish', 'update'], true)) {
                if ($p['inventoryStatus'] !== 'available') {
                    throw ValidationException::withMessages(['action' => 'Alleen beschikbare laptops kunnen worden aangeboden. Controleer de voorraadstatus.']);
                }
                if (! $p['photos'] || $p['priceCents'] <= 0 || ! $p['condition'] || strlen($p['description']) < 5) {
                    throw ValidationException::withMessages(['action' => 'Voeg een prijs, echte foto, bevestigde conditie en beschrijving toe.']);
                }
                foreach ($channels as $channel) {
                    if (in_array($channel, ['facebook', 'tweakers'], true)) {
                        continue;
                    }
                    if (! $this->gateway->configured($channel)) {
                        throw ValidationException::withMessages(['action' => 'De laptop is opgeslagen op Revilo. Vul eerst de API-gegevens in voor '.$channel.'.']);
                    }
                    $category = $p[match ($channel) {'ebay' => 'ebayCategoryId', 'belgium' => 'belgiumCategoryId', default => 'mpCategoryId'}] ?? '';
                    if (! ctype_digit((string) $category)) {
                        throw ValidationException::withMessages(['action' => 'Vul het categorie-ID in voor '.$channel.'.']);
                    }
                }
            }
            return $draft->actions()->create(['action' => $action, 'channels' => array_values(array_unique($channels)), 'snapshot' => $p, 'status' => 'queued']);
        }, 3);
    }

    public function process(int $id): void
    {
        $action = DB::transaction(function () use ($id) {
            $action = MarketplaceAction::whereKey($id)->lockForUpdate()->first();
            if (! $action || $action->status !== 'queued') {
                return null;
            }
            $action->update(['status' => 'running', 'started_at' => now()]);
            return $action;
        });
        if (! $action) {
            return;
        }
        $draft = MarketplaceDraft::findOrFail($action->marketplace_draft_id);
        $results = [];
        foreach ($action->channels as $channel) {
            try {
                if (in_array($action->action, ['publish', 'update'], true)) {
                    $current = $this->catalog->serialize($draft->fresh());
                    if ($current['inventoryStatus'] !== 'available' || $current['catalogToken'] !== $action->snapshot['catalogToken']) {
                        throw new MarketplaceGatewayException('De laptop is gewijzigd of niet meer beschikbaar. Controleer de voorraad en sla het product opnieuw op.');
                    }
                }
                $result = in_array($channel, ['facebook', 'tweakers'], true)
                    ? ['status' => 'manual', 'message' => 'Advertentiepakket voorbereid. Nog niet op dit platform geplaatst of aangepast.']
                    : $this->gateway->perform($draft, $action->snapshot, $channel, $action->action);
                $result = array_merge($result, ['channel' => $channel, 'version' => $action->snapshot['version'], 'catalogToken' => $action->snapshot['catalogToken']]);
            } catch (\Throwable $error) {
                $message = $error->getMessage();
                foreach (MarketplaceConnection::forProvider($channel) as $key => $value) {
                    if (preg_match('/secret|token/i', $key) && is_string($value) && strlen($value) > 3) {
                        $message = str_replace($value, '[afgeschermd]', $message);
                    }
                }
                $result = ['channel' => $channel, 'status' => $error instanceof MarketplaceGatewayException && $error->uncertain ? 'unknown' : 'error', 'message' => mb_substr($message, 0, 1500)];
            }
            $this->gateway->remember($draft, $channel, $result);
            $results[] = $result;
            $action->update(['results' => $results]);
        }
        $action->update(['status' => collect($results)->contains(fn ($r) => in_array($r['status'], ['error', 'unknown'], true)) ? 'attention' : 'completed', 'ended_at' => now()]);
    }

    public function sold(MarketplaceDraft $draft): ?MarketplaceAction
    {
        return DB::transaction(function () use ($draft) {
            $draft = MarketplaceDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($draft->actions()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['product' => 'Wacht tot de lopende actie is afgerond.']);
            }
            $unit = InventoryUnit::whereKey($draft->inventory_unit_id)->lockForUpdate()->firstOrFail();
            if (! in_array($unit->status, ['available', 'sold'], true)) {
                throw ValidationException::withMessages(['product' => 'Deze laptop is gereserveerd of niet verkoopbaar. Gebruik de bestel- of retourworkflow.']);
            }
            if ($unit->status !== 'sold') {
                app(InventoryService::class)->move($unit, 'sold', 'Extern verkocht via marketplacebeheer');
            }
            $channels = $draft->listings()->whereIn('channel', ['ebay', 'marktplaats', 'belgium'])->get()->filter(fn ($l) => ($l->data['status'] ?? '') !== 'ended')->pluck('channel')->all();
            return $channels ? $this->enqueue($draft, $channels, 'withdraw') : null;
        }, 3);
    }
}
