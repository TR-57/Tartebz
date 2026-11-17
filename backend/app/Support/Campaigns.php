<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Marketing campaigns (Admin › Campaigns). Everyone in the audience is queued at once; the scheduler sends a batch
 * every minute so the mail service is never overloaded. Each person gets their own language and name and an
 * unsubscribe link; unsubscribed addresses are always left out.
 */
class Campaigns
{
    public const BATCH = 40;

    /** [email => {email, name, lang, country}] – one row per address. */
    public static function audience(array $a): array
    {
        $type = $a['type'] ?? 'all';
        $out = [];
        if ($type === 'list') {
            foreach ((array) ($a['emails'] ?? []) as $e) {
                $e = strtolower(trim((string) $e));
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $known = DB::table('orders')->where('email', $e)->orderByDesc('id')->first(['first_name', 'lang', 'country']);
                    $out[$e] = ['email' => $e, 'name' => $known->first_name ?? '', 'lang' => $known->lang ?? '', 'country' => $known->country ?? ''];
                }
            }
        } else {
            $countries = $type === 'countries' ? array_map('strtoupper', (array) ($a['countries'] ?? [])) : [];
            // customers who bought (guests – one row per address, their latest order) …
            $orders = DB::table('orders')->whereNotNull('paid_at')->where('email', '<>', '')->orderBy('id')
                ->when($countries, fn ($q) => $q->whereIn('country', $countries))->get(['email', 'first_name', 'lang', 'country']);
            foreach ($orders as $o) {
                $e = strtolower($o->email);
                $out[$e] = ['email' => $e, 'name' => $o->first_name, 'lang' => $o->lang, 'country' => $o->country];
            }
            // … and people who signed up for offers
            $subs = DB::table('mail_subscribers')->whereNull('unsubscribed_at')->whereNotNull('consent_at')
                ->when($countries, fn ($q) => $q->whereIn('country', $countries))->get(['email', 'name', 'lang', 'country']);
            foreach ($subs as $s) {
                $out[$s->email] ??= ['email' => $s->email, 'name' => $s->name, 'lang' => $s->lang, 'country' => $s->country];
            }
        }
        $off = DB::table('mail_subscribers')->whereNotNull('unsubscribed_at')->whereIn('email', array_keys($out) ?: [''])->pluck('email')->all();

        return array_diff_key($out, array_flip($off));
    }

    /** The words of a campaign as a template, per language. */
    public static function template(object $c): array
    {
        return ['en' => ['subject' => $c->subject ?: $c->subject_ar, 'body' => (string) ($c->body ?: $c->body_ar)],
            'ar' => ['subject' => $c->subject_ar ?: $c->subject, 'body' => (string) ($c->body_ar ?: $c->body)]];
    }

    public static function sendOne(object $c, array $p, bool $later = true, string $prefix = ''): int
    {
        $lang = EmailSender::langFor($p['lang'] ?? '');

        return EmailSender::send('campaign', $p['email'], $lang, [
            'template' => self::template($c), 'vars' => ['name' => $p['name'] ?? ''], 'url' => config('store.store_url'),
            'dedupe' => $later ? "campaign:{$c->id}:{$p['email']}" : null, 'later' => $later, 'subjectPrefix' => $prefix, 'force' => true,
            'campaignId' => $later ? $c->id : null,
        ]);
    }

    /** Queues everyone now; the scheduler sends them in batches. */
    public static function start(int $id): int
    {
        $c = DB::table('campaigns')->find($id) ?? throw ApiError::notFound('That campaign does not exist.');
        if ($c->status !== 'draft') {
            throw new ApiError('started', 'This campaign has already been sent.', 409);
        }
        $people = self::audience((array) json_decode((string) $c->audience, true));
        if (! $people) {
            throw new ApiError('empty', 'Nobody to send to: the audience is empty (or everyone in it unsubscribed).', 409);
        }
        DB::table('campaigns')->where('id', $id)->update(['status' => 'sending', 'started_at' => now(), 'total' => count($people), 'updated_at' => now()]);
        foreach ($people as $p) {
            self::sendOne($c, $p);
        }

        return count($people);
    }

    /** Scheduler, every minute: one batch of campaign email. */
    public static function batch(int $size = self::BATCH): int
    {
        $ids = DB::table('mail_outbox')->whereNotNull('campaign_id')->whereIn('status', ['queued', 'failed'])
            ->where('attempts', '<', 3)->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit($size)->pluck('id');
        $n = 0;
        foreach ($ids as $id) {
            $n += Outbox::send((int) $id) ? 1 : 0;
        }
        // finished campaigns
        foreach (DB::table('campaigns')->where('status', 'sending')->get() as $c) {
            $left = DB::table('mail_outbox')->where('campaign_id', $c->id)->where(fn ($q) => $q->whereIn('status', ['queued', 'sending'])
                ->orWhere(fn ($q) => $q->where('status', 'failed')->where('attempts', '<', 3)))->count();
            if ($left === 0) {
                DB::table('campaigns')->where('id', $c->id)->update(['status' => 'done', 'finished_at' => now(), 'updated_at' => now()]);
            }
        }

        return $n;
    }

    public static function progress(int $id): array
    {
        $rows = DB::table('mail_outbox')->where('campaign_id', $id)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $c = DB::table('campaigns')->find($id);

        return ['total' => (int) ($c->total ?? 0), 'sent' => (int) ($rows['sent'] ?? 0), 'failed' => (int) ($rows['failed'] ?? 0),
            'skipped' => (int) ($rows['skipped'] ?? 0), 'remaining' => (int) (($rows['queued'] ?? 0) + ($rows['sending'] ?? 0))];
    }
}
