<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Catalog;
use App\Support\Media;
use App\Support\Thumbs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Products: list and view (staff), create, edit, stock and delete (managers). */
class ProductsController extends Controller
{
    private const TABS = ['all', 'active', 'hidden', 'out', 'low', 'missing'];

    public function index(Request $r)
    {
        $tree = Catalog::categoryTree();
        $tab = in_array($r->query('tab'), self::TABS, true) ? $r->query('tab') : 'all';
        $all = DB::table('products')->orderBy('sort_rank')->orderByDesc('id')->get();

        $term = mb_strtolower($this->str($r, 'q', 100));
        $cat = (int) $r->query('category', 0);
        $rows = $all->filter(function ($p) use ($term, $cat) {
            if ($term !== '' && ! str_contains(mb_strtolower($p->name.' '.$p->name_ar.' '.$p->sku.' '.$p->barcode.' '.$p->id.' '.$p->flavor.' '.$p->flavor_ar), $term)) {
                return false;
            }

            return ! $cat || (int) $p->category_id === $cat;
        });

        $counts = array_fill_keys(self::TABS, 0);
        $list = [];
        foreach ($rows as $p) {
            $item = $this->row($p, $tree);
            foreach (self::TABS as $t) {
                if ($this->inTab($t, $p, $item)) {
                    $counts[$t]++;
                }
            }
            if ($this->inTab($tab, $p, $item)) {
                $list[] = $item;
            }
        }
        $per = 50;
        $page = max(1, (int) $r->query('page', 1));

        return $this->ok([
            'products' => array_slice($list, ($page - 1) * $per, $per),
            'total' => count($list), 'page' => $page, 'per' => $per, 'counts' => $counts,
            'categories' => $this->categoryOptions($tree),
            'lowStock' => Catalog::LOW_STOCK,
        ]);
    }

    public function show(int $id)
    {
        $p = $this->find($id);
        $tree = Catalog::categoryTree();

        return $this->ok([
            'product' => $this->full($p, $tree),
            'categories' => $this->categoryOptions($tree),
        ]);
    }

    public function store(Request $r)
    {
        $id = Catalog::save($this->input($r));
        Audit::log($r, 'product_created', 'product', $id, ['name' => $this->str($r, 'name')]);

        return $this->ok(['product' => $this->full(DB::table('products')->find($id), Catalog::categoryTree())], 201);
    }

    public function update(Request $r, int $id)
    {
        $before = $this->find($id);
        Catalog::save($this->input($r), $id);
        $after = DB::table('products')->find($id);
        $changed = [];
        foreach (['name', 'name_ar', 'price', 'regular_price', 'status', 'stock_qty', 'category_id', 'images', 'sour_level', 'best_seller'] as $k) {
            if ((string) $before->$k !== (string) $after->$k) {
                $changed[$k] = [$before->$k, $after->$k];
            }
        }
        Audit::log($r, 'product_updated', 'product', $id, $changed);

        return $this->ok(['product' => $this->full($after, Catalog::categoryTree())]);
    }

    /** Quick stock change from the list: {stock_qty} or {in_stock}. No other rule is checked. */
    public function stock(Request $r, int $id)
    {
        $p = $this->find($id);
        if ($p->type === 'variable') {
            throw ApiError::validation(['stock_qty' => 'This product has variations – change the stock of each one in the product.']);
        }
        $upd = ['updated_at' => now()];
        if ($r->has('stock_qty')) {
            $q = filter_var($r->input('stock_qty'), FILTER_VALIDATE_INT);
            if ($q === false || $q < -999999 || $q > 9999999) {
                throw ApiError::validation(['stock_qty' => 'Enter a whole number.']);
            }
            $upd += ['stock_qty' => $q, 'manage_stock' => true, 'in_stock' => $q > 0];
        } elseif ($r->has('in_stock')) {
            $upd += ['in_stock' => $this->bool($r, 'in_stock'), 'manage_stock' => false];
        }
        DB::table('products')->where('id', $id)->update($upd);
        Audit::log($r, 'stock_changed', 'product', $id, ['from' => $p->manage_stock ? (int) $p->stock_qty : ($p->in_stock ? 'in stock' : 'out of stock'),
            'to' => $upd['stock_qty'] ?? ($upd['in_stock'] ? 'in stock' : 'out of stock')]);

        return $this->ok(['product' => $this->row(DB::table('products')->find($id), Catalog::categoryTree())]);
    }

    /** {ids:[…], action: show|hide|delete|category|best_seller|not_best_seller, category_id?} */
    public function bulk(Request $r)
    {
        $ids = array_slice(array_values(array_filter(array_map('intval', (array) $r->input('ids', [])))), 0, 2000);
        $action = $this->str($r, 'action', 30);
        if (! $ids) {
            throw ApiError::validation(['ids' => 'Select at least one product.']);
        }
        $q = fn () => DB::table('products')->whereIn('id', $ids);
        $done = 0;
        $skipped = [];
        switch ($action) {
            case 'show':
                // Only products that pass the rules can go on the shop.
                foreach ($q()->get() as $p) {
                    if (! json_decode((string) $p->images, true) || ! $p->category_id || $p->price <= 0) {
                        $skipped[] = "#{$p->id} {$p->name}";

                        continue;
                    }
                    DB::table('products')->where('id', $p->id)->update(['status' => 'active', 'updated_at' => now()]);
                    $done++;
                }
                break;
            case 'hide':
                $done = $q()->update(['status' => 'hidden', 'updated_at' => now()]);
                break;
            case 'best_seller':
            case 'not_best_seller':
                $done = $q()->update(['best_seller' => $action === 'best_seller', 'updated_at' => now()]);
                break;
            case 'category':
                $cat = (int) $r->input('category_id');
                if (! DB::table('categories')->where('id', $cat)->exists()) {
                    throw ApiError::validation(['category_id' => 'Choose a category for the product.']);
                }
                $done = $q()->update(['category_id' => $cat, 'updated_at' => now()]);
                break;
            case 'delete':
                foreach ($q()->get() as $p) {
                    if ($this->hasOrders($p->id)) {
                        $skipped[] = "#{$p->id} {$p->name}";

                        continue;
                    }
                    DB::table('products')->where('id', $p->id)->delete();
                    $done++;
                }
                break;
            default:
                throw ApiError::validation(['action' => 'Choose what to do with the selected products.']);
        }
        Audit::log($r, 'products_bulk_'.$action, 'product', '', ['ids' => $ids, 'done' => $done, 'skipped' => $skipped]);

        return $this->ok(['done' => $done, 'skipped' => $skipped]);
    }

    public function destroy(Request $r, int $id)
    {
        $p = $this->find($id);
        if ($this->hasOrders($id)) {
            throw new ApiError('has_orders', 'This product is in past orders, so it cannot be deleted. Hide it instead – it disappears from the shop and the orders keep their details.', 409);
        }
        DB::table('products')->where('id', $id)->delete();
        Audit::log($r, 'product_deleted', 'product', $id, ['name' => $p->name]);

        return $this->ok();
    }

    /* ---------------------------------------------------------------- helpers */

    private function hasOrders(int $id): bool
    {
        return Schema::hasTable('order_items') && DB::table('order_items')->where('product_id', $id)->exists();
    }

    private function find(int $id): object
    {
        return DB::table('products')->find($id) ?? throw ApiError::notFound('That product does not exist any more.');
    }

    /** Request body → the keys Catalog::clean understands (only keys that were sent, so a partial save keeps the rest). */
    private function input(Request $r): array
    {
        $keys = ['type', 'name', 'name_ar', 'brand', 'sku', 'barcode', 'price', 'regular_price', 'tax_status', 'sizes', 'colours', 'lengths',
            'images', 'description', 'description_ar', 'short_desc', 'short_desc_ar', 'flavor', 'flavor_ar', 'sour_level', 'weight_grams',
            'own_weight', 'manage_stock', 'stock_qty', 'in_stock', 'status', 'seo_title', 'seo_title_ar', 'seo_desc', 'seo_desc_ar',
            'category_id', 'sort_rank', 'is_new', 'best_seller', 'variations', 'colour_images'];
        $in = [];
        foreach ($keys as $k) {
            if ($r->has($k)) {
                $in[$k] = $r->input($k);
            }
        }
        // Descriptions are plain text (blank line = new paragraph); the storefront escapes them when it shows them.
        foreach (['description', 'description_ar', 'short_desc', 'short_desc_ar'] as $k) {
            if (isset($in[$k])) {
                $in[$k] = trim(strip_tags(str_replace("\r\n", "\n", (string) $in[$k])));
            }
        }

        return $in;
    }

    /** What is still missing before the product is complete on both languages. */
    public static function missing(object $p, array $tree): array
    {
        $m = [];
        if (! json_decode((string) $p->images, true)) {
            $m[] = 'photo';
        }
        if (trim($p->name_ar) === '') {
            $m[] = 'arabic_name';
        }
        if (trim(strip_tags((string) $p->description)) === '') {
            $m[] = 'description';
        }
        if (trim(strip_tags((string) $p->description_ar)) === '') {
            $m[] = 'arabic_description';
        }
        if (! $p->category_id || ! isset($tree[$p->category_id])) {
            $m[] = 'category';
        }
        if (Catalog::weightGrams($p, $tree) <= 0) {
            $m[] = 'weight';
        }

        return $m;
    }

    private function inTab(string $tab, object $p, array $item): bool
    {
        return match ($tab) {
            'active' => $p->status === 'active',
            'hidden' => $p->status === 'hidden',
            'out' => ! $item['available'] && $p->status === 'active',
            'low' => $p->type === 'simple' && $p->manage_stock && $p->stock_qty > 0 && $p->stock_qty <= Catalog::LOW_STOCK,
            'missing' => (bool) $item['missing'],
            default => true,
        };
    }

    private function row(object $p, array $tree): array
    {
        $images = json_decode((string) $p->images, true) ?: [];

        return [
            'id' => $p->id, 'slug' => $p->slug, 'type' => $p->type, 'name' => $p->name, 'nameAr' => $p->name_ar,
            'sku' => $p->sku, 'price' => (float) $p->price, 'regularPrice' => (float) $p->regular_price,
            'status' => $p->status, 'manageStock' => (bool) $p->manage_stock, 'stockQty' => (int) $p->stock_qty, 'inStock' => (bool) $p->in_stock,
            'available' => Catalog::available($p),
            'categoryId' => $p->category_id, 'category' => Catalog::categoryLabel($p->category_id, $tree),
            'thumb' => $images ? Thumbs::url($images[0], 200) : null,
            'flavor' => $p->flavor, 'flavorAr' => $p->flavor_ar, 'sourLevel' => $p->sour_level,
            'bestSeller' => (bool) $p->best_seller, 'isNew' => (bool) $p->is_new,
            'missing' => self::missing($p, $tree), 'updatedAt' => $p->updated_at,
        ];
    }

    private function full(object $p, array $tree): array
    {
        $images = json_decode((string) $p->images, true) ?: [];

        return $this->row($p, $tree) + [
            'brand' => $p->brand, 'barcode' => $p->barcode, 'taxStatus' => $p->tax_status,
            'images' => array_map(fn ($u) => ['url' => $u, 'thumb' => Thumbs::url($u, 400), 'mediaId' => ($path = Media::pathFromUrl($u))
                ? DB::table('media')->where('path', $path)->value('id') : null], $images),
            'description' => (string) $p->description, 'descriptionAr' => (string) $p->description_ar,
            'shortDesc' => (string) $p->short_desc, 'shortDescAr' => (string) $p->short_desc_ar,
            'weightGrams' => (int) $p->weight_grams, 'ownWeight' => (bool) $p->own_weight, 'effectiveWeight' => Catalog::weightGrams($p, $tree),
            'seoTitle' => $p->seo_title, 'seoTitleAr' => $p->seo_title_ar, 'seoDesc' => $p->seo_desc, 'seoDescAr' => $p->seo_desc_ar,
            'sortRank' => (int) $p->sort_rank, 'permalink' => $p->permalink,
            'sizes' => json_decode((string) $p->sizes, true) ?: [], 'colours' => json_decode((string) $p->colours, true) ?: [],
            'lengths' => json_decode((string) $p->lengths, true) ?: [], 'colourImages' => json_decode((string) $p->colour_images, true) ?: [],
            'variations' => DB::table('product_variations')->where('product_id', $p->id)->orderBy('position')->get()->map(fn ($v) => [
                'id' => $v->id, 'colour' => $v->colour, 'size' => $v->size, 'length' => $v->length, 'sku' => $v->sku,
                'barcode' => (string) $v->barcode, 'barcode2' => $v->barcode2, 'stockQty' => (int) $v->stock_qty, 'enabled' => (bool) $v->enabled,
            ])->values(),
            'createdAt' => $p->created_at,
            'storeUrl' => config('store.store_url').'/product.html?p='.rawurlencode($p->slug),
        ];
    }

    private function categoryOptions(array $tree): array
    {
        return array_values(array_map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'nameAr' => $c->name_ar, 'depth' => $c->depth, 'live' => $c->live,
            'label' => Catalog::categoryLabel($c->id, $tree),
        ], $tree));
    }
}
