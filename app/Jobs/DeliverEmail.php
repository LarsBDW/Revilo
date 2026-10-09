<?php

namespace App\Jobs;

use App\Models\EmailRecord;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class DeliverEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $recordId) {}

    public function handle(): void
    {
        $r = EmailRecord::findOrFail($this->recordId);
        if ($r->status === 'sent') {
            return;
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $r->update(['status' => 'preview']);

            return;
        }
        Mail::html($r->html, fn ($m) => $m->to($r->recipient)->subject($r->subject));
        $r->update(['status' => 'sent', 'sent_at' => now()]);
    }

    public function failed(?\Throwable $error): void
    {
        EmailRecord::whereKey($this->recordId)->update(['status' => 'failed', 'error' => $error?->getMessage()]);
    }
}
