<?php

namespace App\Support;

/**
 * Keeps only simple inline HTML in translated text:
 *   tags b strong i em u br small span bdi sup sub a; attributes dir class href target rel lang.
 * No scripts, no event handlers, no other URL schemes than http(s), mailto, tel and site-relative paths.
 */
class HtmlSanitizer
{
    private const TAGS = ['b', 'strong', 'i', 'em', 'u', 'br', 'small', 'span', 'bdi', 'sup', 'sub', 'a'];

    private const ATTRS = ['dir', 'class', 'href', 'target', 'rel', 'lang'];

    public static function inline(string $html): string
    {
        if (! preg_match('/[<&]/', $html)) {
            return $html;
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $doc->getElementById('root') ?? $doc->documentElement;
        self::clean($root);
        $out = '';
        foreach ($root->childNodes as $n) {
            $out .= $doc->saveHTML($n);
        }

        return $out;
    }

    private static function clean(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (! in_array($tag, self::TAGS, true)) {
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template'], true)) {
                        $node->removeChild($child);
                    } else {
                        self::clean($child);
                        while ($child->firstChild) {
                            $node->insertBefore($child->firstChild, $child);
                        }
                        $node->removeChild($child);
                    }

                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $ok = in_array($name, self::ATTRS, true);
                    if ($ok && $name === 'href') {
                        $v = trim($attr->value);
                        $ok = (bool) preg_match('#^(https?://|mailto:|tel:|/|\#|[a-z0-9_-]+(\.html)?([?\#].*)?$)#i', $v) && ! preg_match('#^\s*javascript:#i', $v);
                    }
                    if (! $ok) {
                        $child->removeAttribute($attr->name);
                    }
                }
                self::clean($child);
            } elseif ($child instanceof \DOMComment) {
                $node->removeChild($child);
            }
        }
    }
}
