<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Catalog;
use App\Support\Settings;
use App\Support\Thumbs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /product/<slug> – the product page with everything a link preview (WhatsApp, Instagram, Facebook, X) and Google
 * need already in the HTML: title, description, picture, price, availability and Product structured data.
 * The page itself is product.html; the storefront script draws the rest as on every other page.
 * Old WordPress addresses (/product/<arabic-name>/) are sent here with a permanent redirect.
 */
class ProductPageController extends Controller
{
    public function show(Request $r, string $slug)
    {
        $slug = trim(str_contains($slug, '%') ? rawurldecode($slug) : $slug, '/');
        $lang = $r->query('lang') === 'en' ? 'en' : 'ar';
        $site = config('store.store_url');
        $p = DB::table('products')->where('slug', $slug)->first();

        if (! $p) {
            $old = DB::table('products')->whereIn('permalink', ['/product/'.$slug.'/', '/product/'.$slug])->first();
            if ($old) {
                return redirect('/product/'.rawurlencode($old->slug).($lang === 'en' ? '?lang=en' : ''), 301);
            }
        }
        $html = $this->template();
        $tree = Catalog::categoryTree();
        $live = $p && $p->status === 'active' && $p->category_id && ($tree[$p->category_id]->live ?? false);
        if (! $live) {
            $html = str_replace('</head>', '<meta name="robots" content="noindex">'."\n</head>", $html);

            return response($html, 404)->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-cache');
        }

        $brand = Settings::get('brand');
        $store = $lang === 'ar' ? ($brand['name_ar'] ?: $brand['name']) : $brand['name'];
        $name = $lang === 'ar' && $p->name_ar ? $p->name_ar : $p->name;
        $desc = $lang === 'ar' && $p->description_ar ? $p->description_ar : (string) $p->description;
        $seoTitle = $lang === 'ar' ? ($p->seo_title_ar ?: $p->seo_title) : $p->seo_title;
        $seoDesc = $lang === 'ar' ? ($p->seo_desc_ar ?: $p->seo_desc) : $p->seo_desc;
        $title = ($seoTitle ?: $name).' | '.$store;
        $metaDesc = mb_substr(trim(preg_replace('/\s+/u', ' ', $seoDesc ?: $desc)), 0, 160);
        $url = $site.'/product/'.rawurlencode($p->slug);
        $canonical = $url.($lang === 'en' ? '?lang=en' : '');
        $images = json_decode((string) $p->images, true) ?: [];
        $og = $images ? $site.Thumbs::ogUrl($images[0]) : '';
        $price = number_format((float) $p->price, 3, '.', '');
        $inStock = Catalog::available($p);
        $cat = $tree[$p->category_id];

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ld = [
            [
                '@context' => 'https://schema.org', '@type' => 'Product',
                'name' => $name, 'description' => $metaDesc, 'sku' => $p->sku ?: (string) $p->id,
                'image' => array_map(fn ($u) => $site.Thumbs::url($u, 1200), $images),
                'brand' => ['@type' => 'Brand', 'name' => $brand['name']],
                'category' => $lang === 'ar' && $cat->name_ar ? $cat->name_ar : $cat->name,
                'offers' => [
                    '@type' => 'Offer', 'url' => $canonical, 'priceCurrency' => 'BHD', 'price' => $price,
                    'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'itemCondition' => 'https://schema.org/NewCondition',
                    'seller' => ['@type' => 'Organization', 'name' => $brand['name']],
                ],
            ] + ($p->barcode ? ['gtin' => $p->barcode] : []),
            [
                '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => $store, 'item' => $site.'/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $lang === 'ar' && $cat->name_ar ? $cat->name_ar : $cat->name, 'item' => $site.'/shop?cat='.rawurlencode($cat->slug)],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => $canonical],
                ],
            ],
        ];
        $json = json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        $head = implode("\n  ", array_filter([
            '<link rel="canonical" href="'.$e($canonical).'">',
            '<link rel="alternate" hreflang="ar" href="'.$e($url).'">',
            '<link rel="alternate" hreflang="en" href="'.$e($url.'?lang=en').'">',
            '<link rel="alternate" hreflang="x-default" href="'.$e($url).'">',
            '<meta property="og:type" content="product">',
            '<meta property="og:site_name" content="'.$e($store).'">',
            '<meta property="og:locale" content="'.($lang === 'ar' ? 'ar_BH' : 'en_GB').'">',
            '<meta property="og:title" content="'.$e($name).'">',
            '<meta property="og:description" content="'.$e($metaDesc).'">',
            '<meta property="og:url" content="'.$e($canonical).'">',
            $og ? '<meta property="og:image" content="'.$e($og).'">' : '',
            $og ? '<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">' : '',
            $og ? '<meta property="og:image:alt" content="'.$e($name).'">' : '',
            '<meta property="product:price:amount" content="'.$price.'">',
            '<meta property="product:price:currency" content="BHD">',
            '<meta property="product:availability" content="'.($inStock ? 'in stock' : 'out of stock').'">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<script type="application/ld+json">'.$json.'</script>',
        ]));

        // Swap the generic title / description / social tags of product.html for this product's.
        $html = preg_replace('#<title>.*?</title>#s', '<title>'.$e($title).'</title>', $html, 1);
        $html = preg_replace('#<meta name="description"[^>]*>#', '<meta name="description" content="'.$e($metaDesc).'">', $html, 1);
        $html = preg_replace('#\s*<meta (property="og:[^"]+"|name="twitter:[^"]+")[^>]*>#', '', $html);
        $html = preg_replace('#\s*<link rel="canonical"[^>]*>#', '', $html);
        if ($lang === 'en') {
            $html = preg_replace('#<html lang="ar" dir="rtl">#', '<html lang="en" dir="ltr">', $html, 1);
        }
        $html = str_replace('</head>', '  '.$head."\n</head>", $html);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300, s-maxage=300')
            ->header('Vary', 'Accept-Encoding');
    }

    private function template(): string
    {
        $file = rtrim(config('store.public_dir'), '/').'/product.html';
        $html = (string) @file_get_contents($file);

        return str_replace('__SITE__', config('store.store_url'), $html);
    }
}
