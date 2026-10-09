<?php

namespace App\Services;

use App\Jobs\DeliverEmail;
use App\Models\EmailRecord;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    public function record(string $key, string $email, string $subject, string $body, string $locale = 'nl', ?string $unsubscribe = null): EmailRecord
    {
        preg_match('~https?://[^\s]+~', $body, $links);
        $url = $links[0] ?? null;
        $html = view('emails.notification', compact('subject', 'body', 'locale', 'url', 'unsubscribe'))->render();
        $record = EmailRecord::firstOrCreate(['idempotency_key' => $key], ['recipient' => $email, 'subject' => $subject, 'html' => $html, 'status' => 'preview']);
        // The log and array mail drivers are previews, never successful real deliveries.
        if ($record->wasRecentlyCreated && ! in_array(config('mail.default'), ['log', 'array'], true)) {
            $record->update(['status' => 'queued']);
            DeliverEmail::dispatch($record->id)->afterCommit();
        }

        return $record;
    }
}
