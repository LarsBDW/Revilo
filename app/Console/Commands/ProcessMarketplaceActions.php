<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAction;
use App\Services\MarketplaceService;
use Illuminate\Console\Command;

class ProcessMarketplaceActions extends Command
{
    protected $signature = 'marketplaces:process {--limit=10}';
    protected $description = 'Process queued marketplace actions without blindly retrying interrupted publications';

    public function handle(MarketplaceService $service): int
    {
        MarketplaceAction::where('status', 'running')->where('started_at', '<', now()->subMinutes(10))->update(['status' => 'interrupted', 'message' => 'Onderbroken verwerking. Controleer externe status en ID vóór opnieuw plaatsen.', 'ended_at' => now()]);
        foreach (MarketplaceAction::where('status', 'queued')->oldest('id')->limit(max(1, min(100, (int) $this->option('limit'))))->get() as $action) {
            $service->process($action->id);
        }
        return self::SUCCESS;
    }
}
