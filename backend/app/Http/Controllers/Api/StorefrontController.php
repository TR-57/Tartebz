<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Catalog;
use App\Support\Currency;
use App\Support\Media;
use App\Support\Settings;
use App\Support\Thumbs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/storefront – everything the shop pages need in one cached answer: shop details, logo, currencies,
 * live categories and the products on sale. Prices are in BHD; the page converts them with the currency rates.
 * Answers 304 when nothing changed since the browser's copy (ETag).
 */
class StorefrontController extends Controller
{
    public function index(Request $r)
    {
        $stamp = implode('|', [
            DB::table('products')->max('updated_at'), DB::table('products')->count(),
            DB::table('categories')->max('updated_at'), DB::table('categories')->count(),
            DB::table('settings')->max('updated_at'), DB::table('media')->max('updated_at'),
        ]);
        $etag = '"'.substr(sha1($stamp), 0, 20).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=60, must-revalidate'];
        if (trim((string) $r->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        $tree = Catalog::categoryTree();
        $live = array_filter($tree, fn ($c) => $c->live);
        $products = DB::table('products')->where('status', 'active')->whereIn('category_id', array_keys($live) ?: [0])
            ->orderBy('sort_rank')->orderByDesc('id')->get();

        $brand = Settings::get('brand');
        $biz = Settings::get('business');
        $logo = $brand['logo'] ? Media::url($brand['logo'], (int) DB::table('media')->where('path', $brand['logo'])->value('version')) : '';

        return response()->json(['ok' => true,
            'site' => [
                'name' => ['en' => $brand['name'], 'ar' => $brand['name_ar'] ?: $brand['name']],
                'logo' => $logo ? ['url' => $logo, 'small' => Thumbs::url($logo, 200), 'medium' => Thumbs::url($logo, 400)] : null,
                'email' => $biz['email'], 'phone' => $biz['phone'], 'whatsapp' => $biz['whatsapp'] ?: $biz['phone'], 'cr' => $biz['cr'],
                'address' => ['en' => $biz['addressLine1'], 'ar' => $biz['addressLine1_ar'] ?: $biz['addressLine1']],
                'mapsUrl' => $brand['mapsUrl'], 'social' => ['instagram' => $brand['instagram'], 'tiktok' => $brand['tiktok']],
            ],
            'baseCurrency' => Currency::base(),
            'currencies' => Currency::forStorefront(),
            'categories' => array_values(array_map(fn ($c) => [
                'id' => $c->slug, 'name' => ['en' => $c->name, 'ar' => $c->name_ar ?: $c->name],
                'desc' => ['en' => (string) $c->blurb, 'ar' => (string) ($c->blurb_ar ?: $c->blurb)],
                'icon' => $c->icon ?: 'star', 'image' => $c->image ? Thumbs::url($c->image, 800) : null,
                'inMenu' => (bool) $c->in_menu, 'onHome' => (bool) $c->on_home,
            ], $live)),
            'products' => $products->map(function ($p) use ($tree) {
                $images = json_decode((string) $p->images, true) ?: [];
                $cat = $tree[$p->category_id] ?? null;
                $cats = $cat ? [$cat->slug] : [];
                if ($p->best_seller) {
                    array_unshift($cats, 'best-sellers');
                }

                return [
                    'id' => $p->id, 'slug' => $p->slug, 'order' => (int) $p->sort_rank,
                    'price' => (float) $p->price, 'regularPrice' => (float) $p->regular_price,
                    'inStock' => Catalog::available($p), 'stockLeft' => $p->manage_stock && $p->type === 'simple' ? max(0, (int) $p->stock_qty) : null,
                    'bestSeller' => (bool) $p->best_seller, 'isNew' => (bool) $p->is_new, 'cats' => $cats,
                    'sour' => $p->sour_level ? (int) $p->sour_level : null, 'weight' => Catalog::weightGrams($p, $tree) / 1000,
                    'name' => ['en' => $p->name, 'ar' => $p->name_ar ?: $p->name],
                    'flavor' => ['en' => $p->flavor, 'ar' => $p->flavor_ar ?: $p->flavor],
                    'desc' => ['en' => (string) $p->description, 'ar' => (string) ($p->description_ar ?: $p->description)],
                    'images' => array_map(fn ($u) => ['src' => Thumbs::url($u, 800), 'small' => Thumbs::url($u, 400), 'large' => Thumbs::url($u, 1600), 'original' => $u], $images),
                ];
            })->values(),
        ], 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
