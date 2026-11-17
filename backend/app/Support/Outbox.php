<?php

namespace App\Support;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Every email is written to mail_outbox first, then sent after the HTTP response has gone out
 * (checkout and payment pages never wait on the mail server). A send first claims the row, so two workers never send it twice.
 *
 * - One email per event: a `dedupe` key (e.g. "order_paid:123") is unique, so a payment notice that arrives twice
 *   finds the first email and sends nothing.
 * - Failed email is retried by the scheduler with a growing pause (1, 5, 30 min, 2 h, 6 h), at most 6 attempts within 3 days.
 * - How it is sent: the SMTP account saved in Admin › Settings › Email, else the SMTP in backend/.env, else the website
 *   sends it by itself (the server's own mail program – on Hostinger that works for an address on your own domain).
 * - Marketing and abandoned-cart email is never sent to an address that unsubscribed, and carries a one-click unsubscribe.
 */
class Outbox
{
    public const MAX_ATTEMPTS = 6;

    /** Minutes to wait after attempt n fails. */
    private const BACKOFF = [1 => 1, 2 => 5, 3 => 30, 4 => 120, 5 => 360];

    /** Kinds that only go to people who did not unsubscribe. */
    public const OPTIONAL_KINDS = ['marketing', 'recovery'];

    private static array $pending = [];

    private static bool $hooked = false;

    /**
     * $opts: kind (transactional|marketing|recovery|staff), key (which email), dedupe (one per event), orderId, lang,
     *        name, replyTo, text, headers, campaignId, event (line for the order's timeline).
     * Returns the outbox id (the existing one for a duplicate event), or 0 when not sent (unsubscribed / no address).
     */
    public static function queue(string $to, string $subject, string $html, array $opts = []): int
    {
        $to = strtolower(trim($to));
        $kind = $opts['kind'] ?? 'transactional';
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }
        $dedupe = isset($opts['dedupe']) ? mb_substr((string) $opts['dedupe'], 0, 190) : null;
        if ($dedupe !== null && ($id = DB::table('mail_outbox')->where('dedupe_key', $dedupe)->value('id'))) {
            return (int) $id;
        }
        if (in_array($kind, self::OPTIONAL_KINDS, true) && Subscribers::unsubscribed($to)) {
            self::skipped($to, $subject, 'Unsubscribed – not sent.', $opts);

            return 0;
        }
        try {
            $id = (int) DB::table('mail_outbox')->insertGetId([
                'kind' => $kind,
                'email_key' => mb_substr((string) ($opts['key'] ?? ''), 0, 60),
                'dedupe_key' => $dedupe,
                'order_id' => $opts['orderId'] ?? null,
                'lang' => mb_substr((string) ($opts['lang'] ?? ''), 0, 2),
                'to_email' => mb_substr($to, 0, 190),
                'to_name' => mb_substr($opts['name'] ?? '', 0, 190),
                'reply_to' => mb_substr($opts['replyTo'] ?? '', 0, 190),
                'subject' => mb_substr($subject, 0, 255),
                'html' => $html,
                'text_body' => $opts['text'] ?? self::toText($html),
                'headers' => ! empty($opts['headers']) ? json_encode($opts['headers'], JSON_UNESCAPED_SLASHES) : null,
                'status' => 'queued',
                'campaign_id' => $opts['campaignId'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return (int) DB::table('mail_outbox')->where('dedupe_key', $dedupe)->value('id');   // the same event, at the same moment
        }
        if (! empty($opts['orderId']) && ! empty($opts['event'])) {
            Orders::event((int) $opts['orderId'], 'email', $opts['event'], ['outbox' => $id]);
        }
        if (! ($opts['later'] ?? false)) {   // campaigns are sent by the scheduler in batches
            self::$pending[] = $id;
            if (! self::$hooked) {
                self::$hooked = true;
                app()->terminating(fn () => self::flushPending());
            }
        }

        return $id;
    }

    /** Records an email we deliberately did not send (with the reason), so staff can see why. */
    public static function skipped(string $to, string $subject, string $reason, array $opts = []): void
    {
        DB::table('mail_outbox')->insert([
            'kind' => $opts['kind'] ?? 'transactional', 'email_key' => mb_substr((string) ($opts['key'] ?? ''), 0, 60),
            'order_id' => $opts['orderId'] ?? null, 'campaign_id' => $opts['campaignId'] ?? null,
            'to_email' => mb_substr(strtolower(trim($to)), 0, 190),
            'subject' => mb_substr($subject, 0, 255), 'html' => '', 'status' => 'skipped',
            'last_error' => mb_substr($reason, 0, 500), 'created_at' => now(),
        ]);
    }

    public static function flushPending(): void
    {
        $ids = self::$pending;
        self::$pending = [];
        foreach ($ids as $id) {
            self::send($id);
        }
    }

    /** Sends one row now. Returns true when sent. Safe to call from several workers. */
    public static function send(int $id): bool
    {
        $claimed = DB::table('mail_outbox')
            ->where('id', $id)
            ->whereIn('status', ['queued', 'failed', 'sending'])
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'locked_until' => now()->addMinutes(10)]);
        if (! $claimed) {
            return false;
        }
        $row = DB::table('mail_outbox')->where('id', $id)->first();
        if (in_array($row->kind, self::OPTIONAL_KINDS, true) && Subscribers::unsubscribed($row->to_email)) {
            DB::table('mail_outbox')->where('id', $id)->update(['status' => 'skipped', 'locked_until' => null, 'last_error' => 'Unsubscribed before it was sent.']);

            return false;
        }
        try {
            self::deliver($row);
            DB::table('mail_outbox')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now(), 'locked_until' => null, 'next_attempt_at' => null, 'last_error' => '']);

            return true;
        } catch (\Throwable $e) {
            $msg = mb_substr(preg_replace('/\s+/', ' ', Mailer::explain($e)), 0, 500);
            $wait = self::BACKOFF[(int) $row->attempts] ?? null;
            DB::table('mail_outbox')->where('id', $id)->update([
                'status' => 'failed', 'locked_until' => null, 'last_error' => $msg,
                'next_attempt_at' => $wait !== null && $row->attempts < self::MAX_ATTEMPTS ? now()->addMinutes($wait) : null,
            ]);
            Log::warning('mail failed', ['id' => $id, 'to' => Subscribers::mask($row->to_email), 'error' => $msg]);

            return false;
        }
    }

    /** Retries failed and stuck mail whose pause is over (scheduler). Campaign mail has its own batches. */
    public static function retry(int $limit = 50): int
    {
        $ids = DB::table('mail_outbox')
            ->whereIn('status', ['queued', 'failed', 'sending'])
            ->whereNull('campaign_id')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('created_at', '>', now()->subDays(3))
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($q) => $q->where('status', '<>', 'queued')->orWhere('created_at', '<', now()->subMinute()))   // just queued: the request sends it
            ->orderBy('id')->limit($limit)->pluck('id');
        $n = 0;
        foreach ($ids as $id) {
            $n += self::send((int) $id) ? 1 : 0;
        }

        return $n;
    }

    /** Staff pressed Retry: try again now, even after the last automatic attempt. */
    public static function retryNow(int $id): bool
    {
        DB::table('mail_outbox')->where('id', $id)->whereIn('status', ['failed', 'queued'])
            ->update(['status' => 'failed', 'locked_until' => null, 'next_attempt_at' => null]);

        return self::send($id);
    }

    public static function configured(): bool
    {
        return Mailer::mode() !== 'none';
    }

    private static function deliver(object $row): void
    {
        $from = Mailer::from();
        $headers = $row->headers ? (array) json_decode($row->headers, true) : [];
        Mailer::mailer()->html($row->html, function (Message $msg) use ($row, $from, $headers) {
            $msg->to($row->to_email, $row->to_name ?: null)->subject($row->subject)->from($from['address'], $from['name']);
            $reply = $row->reply_to ?: $from['replyTo'];
            if ($reply && strcasecmp($reply, $from['address']) !== 0) {
                $msg->replyTo($reply);
            }
            if ($row->text_body) {
                $msg->text((string) $row->text_body);   // the plain-text copy (a string: the mailer refuses anything else)
            }
            foreach ($headers as $name => $value) {
                $msg->getHeaders()->addTextHeader((string) $name, (string) $value);
            }
        });
    }

    public static function toText(string $html): string
    {
        $t = preg_replace(['#<(br|/p|/div|/tr|/h[1-6]|/li)[^>]*>#i', '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is'], ["\n", '$2 ($1)'], $html);
        $t = html_entity_decode(strip_tags(preg_replace('#<(style|head)[^>]*>.*?</\1>#is', '', $t)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $t)));
    }
}
