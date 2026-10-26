<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Catalog;
use Illuminate\Support\Facades\DB;

/** /sitemap.xml and /robots.txt, made from the live catalogue so they are never out of date. */
class SeoController extends Controller
{
    public function sitemap()
    {
        $site = config('store.store_url');
        $tree = Catalog::categoryTree();
        $urls = [];
        $add = function (string $path, ?string $lastmod = null, string $freq = 'weekly', string $prio = '0.6') use (&$urls, $site) {
            $sep = str_contains($path, '?') ? '&' : '?';
            $urls[] = ['loc' => $site.$path, 'en' => $site.$path.$sep.'lang=en', 'lastmod' => $lastmod, 'freq' => $freq, 'prio' => $prio];
        };
        $newest = DB::table('products')->where('status', 'active')->max('updated_at');
        $add('/', $newest ? substr($newest, 0, 10) : null, 'daily', '1.0');
        $add('/shop', $newest ? substr($newest, 0, 10) : null, 'daily', '0.9');
        $add('/about', null, 'monthly', '0.5');
        $add('/contact', null, 'monthly', '0.5');
        foreach ($tree as $c) {
            if ($c->live) {
                $add('/shop?cat='.rawurlencode($c->slug), null, 'weekly', '0.7');
            }
        }
        foreach (DB::table('products')->where('status', 'active')->orderBy('sort_rank')->get() as $p) {
            if ($p->category_id && ($tree[$p->category_id]->live ?? false)) {
                $add('/product/'.rawurlencode($p->slug), substr((string) $p->updated_at, 0, 10), 'weekly', '0.8');
            }
        }
        $x = fn ($v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
        foreach ($urls as $u) {
            $out .= '<url><loc>'.$x($u['loc']).'</loc>'
                .'<xhtml:link rel="alternate" hreflang="ar" href="'.$x($u['loc']).'"/>'
                .'<xhtml:link rel="alternate" hreflang="en" href="'.$x($u['en']).'"/>'
                .($u['lastmod'] ? '<lastmod>'.$u['lastmod'].'</lastmod>' : '')
                .'<changefreq>'.$u['freq'].'</changefreq><priority>'.$u['prio'].'</priority></url>'."\n";
        }
        $out .= '</urlset>';

        return response($out, 200)->header('Content-Type', 'application/xml; charset=utf-8')->header('Cache-Control', 'public, max-age=3600');
    }

    public function robots()
    {
        $site = config('store.store_url');
        $body = "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/\nDisallow: /checkout\n\nSitemap: {$site}/sitemap.xml\n";

        return response($body, 200)->header('Content-Type', 'text/plain; charset=utf-8')->header('Cache-Control', 'public, max-age=86400');
    }
}
