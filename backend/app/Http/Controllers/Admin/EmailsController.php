<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\EmailPreview;
use App\Support\EmailSender;
use App\Support\EmailTemplates;
use App\Support\Mailer;
use App\Support\Outbox;
use App\Support\Secret;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin › Emails (managers): every email, its words, on/off, preview, test send, reset; the outbox log.
 * Admin › Settings › Email (owners): how email is sent (SMTP, or the website itself) and the test email.
 */
class EmailsController extends Controller
{
    // ---------- Settings › Email (owner) ----------

    public function settings()
    {
        $m = Settings::get('mail');

        return $this->ok(['mail' => [
            'host' => $m['host'], 'port' => (int) $m['port'], 'scheme' => $m['scheme'], 'username' => $m['username'],
            'password' => Secret::describe($m['password'] ?? ''), 'fromAddress' => $m['fromAddress'], 'fromName' => $m['fromName'],
            'replyTo' => $m['replyTo'] ?? '', 'notifyEmails' => Mailer::notifyAddresses(),
        ], 'mode' => Mailer::mode(), 'from' => Mailer::from(), 'domain' => substr(strrchr((string) Mailer::from()['address'], '@') ?: '', 1)]);
    }

    public function saveSettings(Request $r)
    {
        $m = Settings::get('mail');
        $f = [];
        $host = strtolower($this->str($r, 'host', 190));
        if ($host !== '' && ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            $f['host'] = 'Enter the mail server name, e.g. smtp.hostinger.com.';
        }
        $port = (int) $r->input('port', 465);
        if ($port < 1 || $port > 65535) {
            $f['port'] = 'Enter a port number (465 or 587).';
        }
        $scheme = in_array($r->input('scheme'), ['ssl', 'tls', ''], true) ? (string) $r->input('scheme') : 'ssl';
        $from = strtolower($this->str($r, 'fromAddress', 190));
        if (! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $f['fromAddress'] = 'Enter the address emails come from, e.g. sales@tartebz.com.';
        }
        $reply = strtolower($this->str($r, 'replyTo', 190));
        if ($reply !== '' && ! filter_var($reply, FILTER_VALIDATE_EMAIL)) {
            $f['replyTo'] = 'Enter a valid email, or leave it empty.';
        }
        $notify = [];
        foreach (preg_split('/[\s,;]+/', (string) (is_array($r->input('notifyEmails')) ? implode(',', $r->input('notifyEmails')) : $r->input('notifyEmails', ''))) as $e) {
            $e = strtolower(trim($e));
            if ($e === '') {
                continue;
            }
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $f['notifyEmails'] = 'One of the addresses is not an email: '.$e;
            }
            $notify[] = $e;
        }
        if ($f) {
            throw ApiError::validation($f);
        }
        $m = array_merge($m, [
            'host' => $host, 'port' => $port, 'scheme' => $scheme, 'username' => $this->str($r, 'username', 190), 'fromAddress' => $from,
            'fromName' => $this->str($r, 'fromName', 120), 'replyTo' => $reply, 'notifyEmails' => array_values(array_unique($notify)), 'notifyEmail' => '',
        ]);
        if ($this->bool($r, 'clearPassword')) {
            $m['password'] = '';
        } elseif (($pw = (string) $r->input('password', '')) !== '') {
            $m['password'] = Secret::seal($pw);
        }
        Settings::set('mail', $m);
        Audit::log($r, 'mail_settings', 'settings', 'mail', ['host' => $host, 'from' => $from]);

        return $this->settings();
    }

    /** {to?} – sends a test email straight away and reports the exact result. */
    public function testSend(Request $r)
    {
        $to = strtolower($this->str($r, 'to', 190)) ?: $this->admin($r)->email;
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw ApiError::validation(['to' => 'Enter an email address.']);
        }

        return $this->ok(Mailer::test($to));
    }

    // ---------- Emails (manager) ----------

    public function index()
    {
        $e = Settings::get('emails');
        $list = [];
        foreach (array_keys(EmailTemplates::all()) as $key) {
            $m = EmailTemplates::meta($key);
            if ($key === 'campaign') {
                continue;
            }
            $last = DB::table('mail_outbox')->where('email_key', $key)->where('status', 'sent')->max('sent_at');
            $list[] = $m + ['enabled' => EmailTemplates::enabled($key), 'custom' => isset($e['templates'][$key]), 'lastSent' => $last];
        }

        return $this->ok(['emails' => $list, 'groups' => EmailTemplates::GROUPS, 'settings' => $this->emailSettings(), 'mode' => Mailer::mode()]);
    }

    public function show(string $key)
    {
        $m = $this->meta($key);

        return $this->ok(['email' => $m + [
            'enabled' => EmailTemplates::enabled($key),
            'en' => EmailTemplates::get($key, 'en'), 'ar' => EmailTemplates::get($key, 'ar'),
            'defaults' => EmailTemplates::defaults()[$key],
        ]]);
    }

    /** {en: {subject, body}, ar: {subject, body}} */
    public function save(Request $r, string $key)
    {
        $this->meta($key);
        $langs = ['en' => (array) $r->input('en', []), 'ar' => (array) $r->input('ar', [])];
        foreach ($langs as $l => $v) {
            if (preg_match('/<\s*(script|style|iframe|a |img )/i', (string) ($v['body'] ?? ''))) {
                throw ApiError::validation([$l.'.body' => 'Write plain text – no HTML. Use **bold**, {button: Label} and [image: https://…] instead.']);
            }
        }
        EmailTemplates::save($key, $langs);
        Audit::log($r, 'email_saved', 'email', $key);

        return $this->show($key);
    }

    public function reset(Request $r, string $key)
    {
        $this->meta($key);
        EmailTemplates::reset($key);
        Audit::log($r, 'email_reset', 'email', $key);

        return $this->show($key);
    }

    /** {on} – optional emails only. */
    public function toggle(Request $r, string $key)
    {
        $m = $this->meta($key);
        if (! $m['optional']) {
            throw new ApiError('required', 'This email is always sent.', 409);
        }
        EmailTemplates::setEnabled($key, $this->bool($r, 'on'));
        Audit::log($r, 'email_toggled', 'email', $key, ['on' => $this->bool($r, 'on')]);

        return $this->ok(['enabled' => EmailTemplates::enabled($key)]);
    }

    /** {key, lang: ar|en|both, en?, ar?} (unsaved words from the editor) → the email as HTML. */
    public function preview(Request $r)
    {
        $key = $this->str($r, 'key', 60);
        $this->meta($key);
        $lang = in_array($r->input('lang'), ['ar', 'en', 'both'], true) ? (string) $r->input('lang') : 'ar';
        [$subject, $html, $text] = EmailSender::build($key, $lang, $this->previewContext($r, $key, $lang), 'customer@example.com');

        return $this->ok(['subject' => $subject, 'html' => $html, 'text' => $text]);
    }

    /** {key, lang, en?, ar?} – sends this email to the person signed in, with sample data. */
    public function test(Request $r)
    {
        $key = $this->str($r, 'key', 60);
        $this->meta($key);
        $lang = in_array($r->input('lang'), ['ar', 'en', 'both'], true) ? (string) $r->input('lang') : 'ar';
        $me = $this->admin($r);
        $id = EmailSender::send($key, $me->email, $lang, $this->previewContext($r, $key, $lang) + ['force' => true, 'subjectPrefix' => '[Test] ']);
        Outbox::flushPending();
        $row = DB::table('mail_outbox')->find($id);

        return $this->ok(['sent' => ($row->status ?? '') === 'sent', 'status' => $row->status ?? 'skipped', 'error' => $row->last_error ?? '', 'to' => $me->email]);
    }

    /** "Send me one of every email" – queued, sent in the background. */
    public function sendAll(Request $r)
    {
        $me = $this->admin($r);
        $lang = in_array($r->input('lang'), ['ar', 'en'], true) ? (string) $r->input('lang') : 'ar';
        $n = 0;
        foreach (array_keys(EmailTemplates::all()) as $key) {
            if ($key === 'campaign') {
                continue;
            }
            $n += EmailSender::send($key, $me->email, $lang, EmailPreview::context($key, $lang) + ['force' => true, 'subjectPrefix' => '[Test] ']) ? 1 : 0;
        }
        Audit::log($r, 'email_send_all', 'email', 'all', ['count' => $n]);

        return $this->ok(['queued' => $n, 'to' => $me->email]);
    }

    public function saveEmailSettings(Request $r)
    {
        $e = Settings::get('emails');
        $e['unknownLang'] = in_array($r->input('unknownLang'), ['ar', 'en', 'both'], true) ? $r->input('unknownLang') : 'ar';
        $e['staffLang'] = $r->input('staffLang') === 'ar' ? 'ar' : 'en';
        $e['reminderMinutes'] = max(5, min(1440, (int) $r->input('reminderMinutes', 30)));
        $e['reviewDays'] = max(0, min(60, (int) $r->input('reviewDays', 3)));
        $e['utm'] = $this->bool($r, 'utm', true);
        foreach (['payNote', 'payNote_ar', 'payMethods', 'payMethods_ar'] as $k) {
            $e[$k] = $this->str($r, $k, 400);
        }
        Settings::set('emails', $e);
        $rv = Settings::get('reviews');
        $rv['enabled'] = $this->bool($r, 'reviewsEnabled', (bool) $rv['enabled']);
        $rv['ask'] = $this->bool($r, 'reviewsAsk', (bool) $rv['ask']);
        Settings::set('reviews', $rv);
        Audit::log($r, 'email_settings', 'settings', 'emails');

        return $this->ok(['settings' => $this->emailSettings()]);
    }

    // ---------- outbox ----------

    /** ?q (email or order number) &status &page */
    public function outbox(Request $r)
    {
        $q = DB::table('mail_outbox')->leftJoin('orders', 'orders.id', '=', 'mail_outbox.order_id');
        $s = mb_strtolower($this->str($r, 'q', 190));
        if ($s !== '') {
            $q->where(fn ($w) => $w->where('mail_outbox.to_email', 'like', '%'.$s.'%')->orWhere('orders.number', $s));
        }
        if (in_array($r->query('status'), ['queued', 'sending', 'sent', 'failed', 'skipped'], true)) {
            $q->where('mail_outbox.status', $r->query('status'));
        }
        $page = max(1, (int) $r->query('page', 1));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('mail_outbox.id')->offset(($page - 1) * 50)->limit(50)->get([
            'mail_outbox.id', 'mail_outbox.kind', 'mail_outbox.email_key', 'mail_outbox.to_email', 'mail_outbox.subject', 'mail_outbox.status',
            'mail_outbox.attempts', 'mail_outbox.last_error', 'mail_outbox.created_at', 'mail_outbox.sent_at', 'mail_outbox.next_attempt_at',
            'mail_outbox.order_id', 'orders.number as order_number',
        ]);

        return $this->ok(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / 50),
            'counts' => DB::table('mail_outbox')->where('created_at', '>', now()->subDays(7))->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')]);
    }

    public function outboxShow(int $id)
    {
        $row = DB::table('mail_outbox')->find($id) ?? throw ApiError::notFound('That email does not exist.');

        return $this->ok(['email' => ['id' => $row->id, 'to' => $row->to_email, 'subject' => $row->subject, 'html' => $row->html, 'text' => $row->text_body,
            'status' => $row->status, 'error' => $row->last_error, 'attempts' => $row->attempts]]);
    }

    public function retry(Request $r, int $id)
    {
        $row = DB::table('mail_outbox')->find($id) ?? throw ApiError::notFound('That email does not exist.');
        if (! in_array($row->status, ['failed', 'queued'], true)) {
            throw new ApiError('not_failed', 'Only emails that failed (or are waiting) can be sent again.', 409);
        }
        $ok = Outbox::retryNow($id);
        Audit::log($r, 'email_retry', 'email', (string) $id, ['ok' => $ok]);
        $row = DB::table('mail_outbox')->find($id);

        return $this->ok(['sent' => $ok, 'status' => $row->status, 'error' => $row->last_error]);
    }

    // ---------- helpers ----------

    private function meta(string $key): array
    {
        if (! isset(EmailTemplates::all()[$key])) {
            throw ApiError::notFound('That email does not exist.');
        }

        return EmailTemplates::meta($key);
    }

    private function previewContext(Request $r, string $key, string $lang): array
    {
        $c = EmailPreview::context($key, $lang === 'both' ? 'ar' : $lang);
        if ($lang === 'both') {
            $en = EmailPreview::context($key, 'en');
            $c['vars'] = ['ar' => $c['vars'] ?? [], 'en' => $en['vars'] ?? []];
            $c['blocks'] = ['ar' => $c['blocks'] ?? [], 'en' => $en['blocks'] ?? []];
        }
        foreach (['en', 'ar'] as $l) {   // unsaved words from the editor
            $v = $r->input($l);
            if (is_array($v) && (isset($v['subject']) || isset($v['body']))) {
                $saved = EmailTemplates::get($key, $l);
                $c['template'][$l] = ['subject' => (string) ($v['subject'] ?? '') ?: $saved['subject'], 'body' => (string) ($v['body'] ?? '') ?: $saved['body']];
            }
        }
        if (isset($c['template']) && count($c['template']) === 1) {
            $other = isset($c['template']['en']) ? 'ar' : 'en';
            $c['template'][$other] = EmailTemplates::get($key, $other);
        }

        return $c;
    }

    private function emailSettings(): array
    {
        $e = Settings::get('emails');
        $rv = Settings::get('reviews');

        return ['unknownLang' => $e['unknownLang'], 'staffLang' => $e['staffLang'], 'reminderMinutes' => (int) $e['reminderMinutes'],
            'reviewDays' => (int) $e['reviewDays'], 'utm' => (bool) $e['utm'], 'payNote' => $e['payNote'] ?? '', 'payNote_ar' => $e['payNote_ar'] ?? '',
            'payMethods' => $e['payMethods'] ?? '', 'payMethods_ar' => $e['payMethods_ar'] ?? '', 'reviewsEnabled' => (bool) $rv['enabled'], 'reviewsAsk' => (bool) $rv['ask']];
    }
}
