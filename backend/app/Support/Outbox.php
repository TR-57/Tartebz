<?php

namespace App\Support;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Every email is written to mail_outbox first, then sent after the HTTP response has gone out
 * (checkout never waits on SMTP). A send first claims the row, so two workers never send it twice.
 * Failed transactional mail is retried by housekeeping (up to 5 attempts within 3 days).
 */
class Outbox
{
    private static array $pending = [];

    private static bool $hooked = false;

    public static function queue(string $to, string $subject, string $html, array $opts = []): int
    {
        $id = (int) DB::table('mail_outbox')->insertGetId([
            'kind' => $opts['kind'] ?? 'transactional',
            'to_email' => mb_substr(strtolower(trim($to)), 0, 190),
            'to_name' => mb_substr($opts['name'] ?? '', 0, 190),
            'reply_to' => mb_substr($opts['replyTo'] ?? '', 0, 190),
            'subject' => mb_substr($subject, 0, 255),
            'html' => $html,
            'text_body' => $opts['text'] ?? self::toText($html),
            'status' => 'queued',
            'campaign_id' => $opts['campaignId'] ?? null,
            'created_at' => now(),
        ]);
        self::$pending[] = $id;
        if (! self::$hooked) {
            self::$hooked = true;
            app()->terminating(fn () => self::flushPending());
        }

        return $id;
    }

    /** Records an email we deliberately did not send (with the reason), so staff can see why. */
    public static function skipped(string $to, string $subject, string $reason): void
    {
        DB::table('mail_outbox')->insert([
            'kind' => 'transactional', 'to_email' => mb_substr(strtolower(trim($to)), 0, 190),
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
        try {
            self::deliver($row);
            DB::table('mail_outbox')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now(), 'locked_until' => null, 'last_error' => '']);

            return true;
        } catch (\Throwable $e) {
            $msg = mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 500);
            DB::table('mail_outbox')->where('id', $id)->update(['status' => 'failed', 'locked_until' => null, 'last_error' => $msg]);
            Log::warning('mail failed', ['id' => $id, 'to' => $row->to_email, 'error' => $msg]);

            return false;
        }
    }

    /** Retries failed and stuck mail (housekeeping). */
    public static function retry(int $limit = 50): int
    {
        $ids = DB::table('mail_outbox')
            ->whereIn('status', ['queued', 'failed', 'sending'])
            ->where('kind', '<>', 'marketing')
            ->where('attempts', '<', 5)
            ->where('created_at', '>', now()->subDays(3))
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->orderBy('id')->limit($limit)->pluck('id');
        $n = 0;
        foreach ($ids as $id) {
            $n += self::send((int) $id) ? 1 : 0;
        }

        return $n;
    }

    public static function configured(): bool
    {
        $m = Settings::get('mail');

        return ($m['host'] ?? '') !== '' || (config('mail.default') === 'smtp' && config('mail.mailers.smtp.host'));
    }

    private static function deliver(object $row): void
    {
        $m = Settings::get('mail');
        $from = $m['fromAddress'] ?: config('mail.from.address');
        $fromName = $m['fromName'] ?: config('mail.from.name') ?: config('store.name');
        $mailer = ($m['host'] ?? '') !== ''
            ? Mail::build([
                'transport' => 'smtp',
                'host' => $m['host'],
                'port' => (int) ($m['port'] ?: 587),
                'scheme' => $m['scheme'] === 'smtps' ? 'smtps' : null,
                'username' => $m['username'] ?: null,
                'password' => Secret::open($m['password'] ?? '') ?: null,
                'timeout' => 20,
            ])
            : Mail::mailer();
        $mailer->html($row->html, function (Message $msg) use ($row, $from, $fromName) {
            $msg->to($row->to_email, $row->to_name ?: null)->subject($row->subject)->from($from, $fromName);
            if ($row->reply_to) {
                $msg->replyTo($row->reply_to);
            }
            if ($row->text_body) {
                $msg->text(new \Illuminate\Support\HtmlString($row->text_body));
            }
        });
    }

    public static function toText(string $html): string
    {
        $t = preg_replace(['#<(br|/p|/div|/tr|/h[1-6]|/li)[^>]*>#i', '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is'], ["\n", '$2 ($1)'], $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $t)));
    }
}
