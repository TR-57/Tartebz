<?php

namespace App\Support;

use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Support\Facades\Mail;

/**
 * How email leaves the website. In order:
 *  1. smtp    – the SMTP account saved in Admin › Settings › Email (Hostinger: smtp.hostinger.com, port 465, SSL,
 *               the mailbox address and its password).
 *  2. env     – the SMTP in backend/.env (MAIL_HOST …), used when the admin fields are empty.
 *  3. website – no SMTP anywhere: the website sends by itself through the server's mail program (sendmail).
 *               On Hostinger this works for a From address on a domain whose email is at Hostinger.
 * The From address is always the one in the settings (default sales@tartebz.com).
 */
class Mailer
{
    public static function cfg(): array
    {
        return Settings::get('mail');
    }

    /** smtp | env | website | test (local testing mailers) */
    public static function mode(): string
    {
        $m = self::cfg();
        if (trim((string) ($m['host'] ?? '')) !== '') {
            return 'smtp';
        }
        $default = (string) config('mail.default');
        if (in_array($default, ['array', 'log'], true)) {
            return 'test';
        }
        if ($default === 'smtp' && trim((string) config('mail.mailers.smtp.host')) !== '' && config('mail.mailers.smtp.host') !== '127.0.0.1') {
            return 'env';
        }

        return 'website';
    }

    public static function mailer(): MailerContract
    {
        $m = self::cfg();

        return match (self::mode()) {
            'smtp' => Mail::build([
                'transport' => 'smtp',
                'host' => trim((string) $m['host']),
                'port' => (int) ($m['port'] ?: (($m['scheme'] ?? '') === 'ssl' ? 465 : 587)),
                // 465 = SSL from the first byte (smtps); 587/25 = plain, upgraded with STARTTLS when the server offers it
                'scheme' => ($m['scheme'] ?? '') === 'ssl' || (int) $m['port'] === 465 ? 'smtps' : 'smtp',
                'username' => trim((string) ($m['username'] ?? '')) ?: null,
                'password' => Secret::open($m['password'] ?? '') ?: null,
                'timeout' => 20,
                'local_domain' => parse_url((string) config('store.store_url'), PHP_URL_HOST) ?: null,
            ]),
            'website' => Mail::mailer('sendmail'),
            default => Mail::mailer(),
        };
    }

    /** {address, name, replyTo} – the settings first, then .env. */
    public static function from(): array
    {
        $m = self::cfg();
        $address = trim((string) ($m['fromAddress'] ?? '')) ?: (string) config('mail.from.address');
        $name = trim((string) ($m['fromName'] ?? '')) ?: (string) (config('mail.from.name') ?: config('store.name'));

        return ['address' => $address, 'name' => $name, 'replyTo' => trim((string) ($m['replyTo'] ?? ''))];
    }

    /** Store notification addresses: new orders, order updates, reviews waiting. */
    public static function notifyAddresses(): array
    {
        $m = self::cfg();
        $list = array_merge((array) ($m['notifyEmails'] ?? []), [$m['notifyEmail'] ?? '', config('store.notify_email')]);

        return array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), $list), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }

    /** A mail-server error in words staff can act on. Never contains the password. */
    public static function explain(\Throwable $e): string
    {
        $msg = $e->getMessage();
        $hint = match (true) {
            (bool) preg_match('/535|authentication|auth.*fail|username and password/i', $msg) => ' – the mail server refused the username or password. Check them in Settings › Email (the username is the full address, e.g. sales@tartebz.com).',
            (bool) preg_match('/Connection could not be established|timed out|getaddrinfo|Connection refused|unable to connect/i', $msg) => ' – could not reach the mail server. Check the host and port (Hostinger: smtp.hostinger.com, 465 with SSL).',
            (bool) preg_match('/certificate|SSL|TLS/i', $msg) => ' – a secure-connection problem. Try port 465 with SSL, or 587 with TLS.',
            (bool) preg_match('/550|553|not permitted|sender.*(rejected|denied)|not owned/i', $msg) => ' – the server would not send from this address. The From address must be a mailbox on your Hostinger email (e.g. sales@tartebz.com).',
            (bool) preg_match('/sendmail|proc_open|No such file/i', $msg) => ' – the website cannot send by itself on this server. Fill in the SMTP settings in Settings › Email.',
            default => '',
        };
        $secret = Secret::open(self::cfg()['password'] ?? '');

        return ($secret !== '' ? str_replace($secret, '•••', $msg) : $msg).$hint;
    }

    /** Sends a test straight away (not through the outbox) and says what happened. */
    public static function test(string $to): array
    {
        $from = self::from();
        $mode = self::mode();
        $html = EmailLayout::wrap('en', 'Test email', '<h1 style="margin:0 0 10px;font-size:22px;">It works</h1><p>This test email was sent by your website ('
            .e(['smtp' => 'SMTP: '.self::cfg()['host'], 'env' => 'SMTP from backend/.env', 'website' => 'sent by the website itself', 'test' => 'test mailer'][$mode])
            .') from <b>'.e($from['address']).'</b>.</p><p>هذه رسالة تجريبية من موقعك. إذا وصلتك، فالإيميلات تعمل.</p>');
        try {
            self::mailer()->html($html, function (\Illuminate\Mail\Message $msg) use ($to, $from) {
                $msg->to($to)->subject('Test email from your shop · رسالة تجريبية')->from($from['address'], $from['name']);
                $msg->text("It works – this test email was sent by your website from {$from['address']}.");
            });

            return ['sent' => true, 'mode' => $mode, 'message' => 'Sent to '.$to.' from '.$from['address'].'. Check the inbox (and the spam folder).'];
        } catch (\Throwable $e) {
            return ['sent' => false, 'mode' => $mode, 'message' => 'Not sent: '.mb_substr(self::explain($e), 0, 400)];
        }
    }
}
