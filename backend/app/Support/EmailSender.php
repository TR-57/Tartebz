<?php

namespace App\Support;

/**
 * Builds one email from its template (staff's words or the default), in the customer's language – or both languages
 * when it is unknown and the setting says so – and puts it in the outbox.
 */
class EmailSender
{
    /**
     * $c (context): vars, blocks, url (main button), dedupe, orderId, event (order timeline line), name, replyTo,
     *               force (send even if the optional email is switched off – tests and "send me one of every email"),
     *               subjectPrefix (tests), later (campaign batches).
     * Returns the outbox id, or 0 (switched off / unsubscribed / no address).
     */
    public static function send(string $key, string $to, string $lang, array $c = []): int
    {
        $meta = EmailTemplates::meta($key);
        if (! ($c['force'] ?? false) && ! EmailTemplates::enabled($key)) {
            return 0;
        }
        [$subject, $html, $text] = self::build($key, $lang, $c, $to);
        $opts = [
            'kind' => $meta['kind'], 'key' => $key, 'dedupe' => $c['dedupe'] ?? null, 'orderId' => $c['orderId'] ?? null,
            'lang' => $lang === 'both' ? '' : $lang, 'name' => $c['name'] ?? '', 'replyTo' => $c['replyTo'] ?? '', 'text' => $text,
            'event' => $c['event'] ?? null, 'later' => $c['later'] ?? false, 'campaignId' => $c['campaignId'] ?? null,
        ];
        if (in_array($meta['kind'], Outbox::OPTIONAL_KINDS, true)) {
            $opts['headers'] = Subscribers::headers($to);
        }

        return Outbox::queue($to, ($c['subjectPrefix'] ?? '').$subject, $html, $opts);
    }

    /** [subject, html, text] – also used for the admin preview. */
    public static function build(string $key, string $lang, array $c, string $to = ''): array
    {
        $meta = EmailTemplates::meta($key);
        $langs = $lang === 'both' ? ['ar', 'en'] : [$lang === 'en' ? 'en' : 'ar'];
        $vars = $c['vars'] ?? [];
        $parts = [];
        $subjects = [];
        $texts = [];
        foreach ($langs as $l) {
            $tpl = isset($c['template'][$l]) ? $c['template'][$l] : EmailTemplates::get($key, $l);   // campaigns and unsaved previews bring their own words
            $v = is_array($vars[$l] ?? null) ? $vars[$l] : $vars;   // per-language values, or one set for both
            $blocks = is_array($c['blocks'][$l] ?? null) ? $c['blocks'][$l] : ($c['blocks'] ?? []);
            $url = is_array($c['url'] ?? null) ? ($c['url'][$l] ?? '') : (string) ($c['url'] ?? '');
            [$h, $t] = EmailMarkup::render($tpl['body'], $v, $blocks, $url, $key);
            $parts[$l] = $h;
            $texts[] = $t;
            $subjects[] = EmailMarkup::plain($tpl['subject'], $v);
        }
        $subject = implode(' | ', $subjects);
        $unsub = in_array($meta['kind'], Outbox::OPTIONAL_KINDS, true) && $to !== '' ? Subscribers::url($to, $langs[0]) : null;
        if (count($langs) === 2) {
            $body = '<div dir="rtl" style="text-align:right;">'.$parts['ar'].'</div>'
                .'<hr style="border:0;border-top:1px solid #E7E7E7;margin:26px 0;">'
                .'<div dir="ltr" lang="en" style="text-align:left;">'.$parts['en'].'</div>';
            $html = EmailLayout::wrap('ar', $subjects[0], $body, ['unsubscribe' => $unsub]);
        } else {
            $html = EmailLayout::wrap($langs[0], $subjects[0], $parts[$langs[0]], ['unsubscribe' => $unsub]);
        }
        $text = implode("\n\n———\n\n", $texts);
        if ($unsub) {
            $text .= "\n\n".($langs[0] === 'ar' ? 'إلغاء الاشتراك: ' : 'Unsubscribe: ').$unsub;
        }

        return [$subject, $html, $text];
    }

    /** The language for someone we know little about: their saved language, else the setting (ar | en | both). */
    public static function langFor(?string $known): string
    {
        if (in_array($known, ['ar', 'en'], true)) {
            return $known;
        }
        $u = (string) (Settings::get('emails')['unknownLang'] ?? 'ar');

        return in_array($u, ['ar', 'en', 'both'], true) ? $u : 'ar';
    }
}
