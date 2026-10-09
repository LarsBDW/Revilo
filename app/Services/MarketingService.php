<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\EmailRecord;
use App\Models\ProductModel;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class MarketingService
{
    public function __construct(private EmailService $emails, private CatalogService $catalog, private CartService $carts) {}

    private function unsubscribe(Subscription $s): string
    {
        return route('subscriptions.unsubscribe', ['locale' => $s->locale, 'token' => $s->token]);
    }

    public function stock(): int
    {
        $count = 0;
        foreach (Subscription::where('type', 'stock')->whereNotNull('confirmed_at')->whereNull('unsubscribed_at')->whereNull('notified_at')->get() as $s) {
            $model = ProductModel::whereKey($s->product_model_id)->where('published', true)->with('units')->first();
            if (! $model) {
                continue;
            }
            $filters = ['in_stock' => 1];
            foreach (['ram_gb' => 'ram', 'ssd_gb' => 'ssd', 'grade' => 'grade'] as $key => $filter) {
                if (! empty($s->configuration[$key])) {
                    $filters[$filter] = [$s->configuration[$key]];
                }
            }
            $match = $this->catalog->matching($filters)->first(fn ($summary) => $summary['model']->id === $model->id);
            if (! $match) {
                continue;
            }
            $url = route('products.show', array_merge(['locale' => $s->locale, 'product' => $model], $match['configuration']));
            $this->emails->record('stock-'.$s->id, $s->email, __('shop.notify_stock', [], $s->locale), $model->brand.' '.$model->name."\n".$url, $s->locale, $this->unsubscribe($s));
            $s->update(['notified_at' => now()]);
            $count++;
        }

        return $count;
    }

    public function campaigns(): int
    {
        $count = 0;
        foreach (DB::table('email_campaigns')->where('status', 'scheduled')->where('scheduled_at', '<=', now())->get() as $campaign) {
            DB::transaction(function () use ($campaign, &$count) {
                $c = DB::table('email_campaigns')->where('id', $campaign->id)->lockForUpdate()->first();
                if ($c->status !== 'scheduled') {
                    return;
                }
                $query = Subscription::where('type', 'newsletter')->whereNotNull('confirmed_at')->whereNull('unsubscribed_at');
                if ($c->segment !== 'all') {
                    $query->where('locale', $c->segment);
                }
                $recipients = $query->get();
                foreach ($recipients as $s) {
                    $this->emails->record('campaign-'.$c->id.'-subscriber-'.$s->id, $s->email, $c->subject, $c->body, $s->locale, $this->unsubscribe($s));
                }
                DB::table('email_campaigns')->where('id', $c->id)->update(['status' => in_array(config('mail.default'), ['log', 'array']) ? 'previewed' : 'queued', 'recipient_count' => $recipients->count(), 'updated_at' => now()]);
                $count += $recipients->count();
            }, 5);
        }

        return $count;
    }

    public function abandoned(): int
    {
        $count = 0;
        foreach (Cart::where('reminder_consent', true)->whereNotNull('email')->whereNotNull('reminder_token')->whereHas('items')->where('updated_at', '<=', now()->subMinutes(30))->where('updated_at', '>=', now()->subDays(4))->get() as $cart) {
            try {
                $totals = $this->carts->totals($cart, 'NL', true);
                if (! $totals['items']) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            // Only the earliest due unsent stage is generated per scheduler run.
            foreach ([30, 1440, 4320] as $stage) {
                if ($cart->updated_at->gt(now()->subMinutes($stage))) {
                    continue;
                }
                $key = 'cart-'.$cart->id.'-'.$cart->updated_at->timestamp.'-'.$stage;
                if (EmailRecord::where('idempotency_key', $key)->exists()) {
                    continue;
                }
                $url = URL::temporarySignedRoute('cart.resume', now()->addDays(7), ['locale' => 'nl', 'token' => $cart->reminder_token]);
                $stop = route('cart.reminders.stop', ['locale' => 'nl', 'token' => $cart->reminder_token]);
                $this->emails->record($key, $cart->email, __('pages.cart_reminder', [], 'nl'), __('pages.cart_reminder_body', [], 'nl')."\n".$url, 'nl', $stop);
                $count++;
                break;
            }
        }

        return $count;
    }
}
