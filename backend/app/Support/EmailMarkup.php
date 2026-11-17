<?php

namespace App\Support;

/**
 * Turns staff's plain-text email words into the email body (HTML) and its plain-text copy. Never raw HTML:
 *   a blank line          – a new paragraph
 *   **words**             – bold
 *   {name} …              – the customer's details (escaped)
 *   {items} on its own line (also {review_items}, {code_box}, {review_list}) – a block
 *   {payment_note} on its own line – shown in a soft box
 *   {button: Label}       – the email's main button (its link depends on the email)
 *   [button: Label | https://…]  and  [image: https://…]  – for marketing emails
 */
class EmailMarkup
{
    /** Variables that become a soft box when they stand on their own line. */
    private const BOXED = ['payment_note'];

    /**
     * @param array $vars    name => plain text
     * @param array $blocks  name => ['html' => …, 'text' => …]
     * @return array{0: string, 1: string} [html, text]
     */
    public static function render(string $body, array $vars, array $blocks, string $mainUrl, string $utmKey = ''): array
    {
        $body = str_replace("\r\n", "\n", $body);
        $html = [];
        $text = [];
        foreach (preg_split('/\n\s*\n/', trim($body)) as $para) {
            $buf = [];
            $flush = function () use (&$buf, &$html, &$text) {
                if (! $buf) {
                    return;
                }
                $html[] = '<p style="margin:0 0 14px;">'.implode('<br>', array_column($buf, 0)).'</p>';
                $text[] = implode("\n", array_column($buf, 1));
                $buf = [];
            };
            foreach (explode("\n", $para) as $line) {
                $t = trim($line);
                if ($t === '') {
                    continue;
                }
                if (preg_match('/^\{(\w+)\}$/', $t, $m) && isset($blocks[$m[1]])) {
                    $flush();
                    if ($blocks[$m[1]]['html'] !== '') {
                        $html[] = $blocks[$m[1]]['html'];
                        $text[] = $blocks[$m[1]]['text'];
                    }
                    continue;
                }
                if (preg_match('/^\{(\w+)\}$/', $t, $m) && in_array($m[1], self::BOXED, true)) {
                    $flush();
                    $v = trim((string) ($vars[$m[1]] ?? ''));
                    if ($v !== '') {
                        $html[] = EmailLayout::box(nl2br(e($v)));
                        $text[] = $v;
                    }
                    continue;
                }
                if (preg_match('/^\{button:\s*(.+?)\s*\}$/u', $t, $m)) {
                    $flush();
                    if ($mainUrl !== '') {
                        $label = self::plain($m[1], $vars);
                        $url = self::utm($mainUrl, $utmKey);
                        $html[] = EmailLayout::button($label, $url);
                        $text[] = $label.': '.$url;
                    }
                    continue;
                }
                if (preg_match('/^\[button:\s*(.+?)\s*\|\s*(.+?)\s*\]$/u', $t, $m)) {
                    $flush();
                    $url = self::plain($m[2], $vars);
                    if (preg_match('#^https?://#i', $url)) {
                        $label = self::plain($m[1], $vars);
                        $url = self::utm($url, $utmKey);
                        $html[] = EmailLayout::button($label, $url, true);
                        $text[] = $label.': '.$url;
                    }
                    continue;
                }
                if (preg_match('/^\[image:\s*(.+?)\s*\]$/u', $t, $m)) {
                    $flush();
                    $src = self::plain($m[1], $vars);
                    if (preg_match('#^https://#i', $src)) {
                        $html[] = '<p style="margin:0 0 14px;"><img src="'.e($src).'" alt="" width="544" style="width:100%;max-width:544px;height:auto;border:0;border-radius:12px;display:block;"></p>';
                    }
                    continue;
                }
                $buf[] = [self::inline($t, $vars), self::plain($t, $vars)];
            }
            $flush();
        }

        return [implode("\n", $html), trim(implode("\n\n", array_filter($text, fn ($x) => trim($x) !== '')))];
    }

    /** One line of text: escaped, **bold**, {variables}. */
    public static function inline(string $line, array $vars): string
    {
        $tokens = [];
        $line = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$tokens, $vars) {
            $tokens[] = e((string) ($vars[$m[1]] ?? ''));

            return "\x01".(count($tokens) - 1)."\x02";
        }, $line);
        $line = e($line);
        $line = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $line);

        return preg_replace_callback("/\x01(\d+)\x02/", fn ($m) => $tokens[(int) $m[1]], $line);
    }

    /** The same line as plain text. */
    public static function plain(string $line, array $vars): string
    {
        $line = preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $line);

        return trim(preg_replace('/\*\*(.+?)\*\*/u', '$1', $line));
    }

    /** utm_source=email on links to the shop (setting), so their sales show in analytics. */
    public static function utm(string $url, string $key): string
    {
        if ($key === '' || ! (Settings::get('emails')['utm'] ?? true)) {
            return $url;
        }
        $site = (string) parse_url((string) config('store.store_url'), PHP_URL_HOST);
        if ($site === '' || parse_url($url, PHP_URL_HOST) !== $site || str_contains($url, '/api/')) {
            return $url;
        }
        $hash = '';
        if (($i = strpos($url, '#')) !== false) {
            $hash = substr($url, $i);
            $url = substr($url, 0, $i);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'utm_source=email&utm_medium=email&utm_campaign='.rawurlencode($key).$hash;
    }
}
