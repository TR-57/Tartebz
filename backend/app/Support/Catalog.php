<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Products: ONE cleaning function and ONE rule set, shared by the product editor and the spreadsheet importer,
 * so both store exactly the same thing. Plus variation saving/sync, slugs and brand normalising.
 */
class Catalog
{
    public const LOW_STOCK = 5;

    public const MAX_VARIATIONS = 1000;

    /* ---------------------------------------------------------------- helpers */

    public static function money(mixed $v): float
    {
        return round((float) $v, 3);
    }

    /** Lists: arrays, or "a, b, c" text. Blanks dropped, each item trimmed and capped. */
    public static function list(mixed $v, int $max = 255): array
    {
        if (is_string($v)) {
            $v = preg_split('/\s*[,|\n]\s*/u', $v);
        }
        $out = [];
        foreach ((array) $v as $x) {
            $x = mb_substr(trim((string) $x), 0, $max);
            if ($x !== '' && ! in_array($x, $out, true)) {
                $out[] = $x;
            }
        }

        return $out;
    }

    public static function slug(string $name, string $table = 'products', ?int $exceptId = null, int $max = 150): string
    {
        $base = Str::slug(Str::ascii($name)) ?: 'item';
        $base = substr($base, 0, $max);
        $slug = $base;
        $i = 2;
        while (DB::table($table)->where('slug', $slug)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists()) {
            $slug = substr($base, 0, $max - 4).'-'.$i++;
        }

        return $slug;
    }

    /** Brand spelling: whitespace collapsed, then the alias map (keyed on letters and digits only, lower case). */
    public static function brand(string $b): string
    {
        $b = trim(preg_replace('/\s+/u', ' ', $b));
        if ($b === '') {
            return '';
        }
        $aliases = (array) (Settings::get('brand_aliases') ?? []);
        $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $b));

        return mb_substr($aliases[$key] ?? $b, 0, 120);
    }

    public static function barcodeOk(string $b): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9-]{4,64}$/', $b);
    }

    /** Who already uses a barcode (any product, any variation, both barcode columns). */
    public static function barcodeOwner(string $code, ?int $exceptProduct = null, array $exceptVariationIds = []): ?string
    {
        $p = DB::table('products')->where('barcode', $code)->when($exceptProduct, fn ($q) => $q->where('id', '<>', $exceptProduct))->first(['id', 'name']);
        if ($p) {
            return "\"{$p->name}\" (#{$p->id})";
        }
        $v = DB::table('product_variations as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where(fn ($q) => $q->where('v.barcode', $code)->orWhere('v.barcode2', $code))
            ->when($exceptProduct, fn ($q) => $q->where('v.product_id', '<>', $exceptProduct))
            ->when($exceptVariationIds, fn ($q) => $q->whereNotIn('v.id', $exceptVariationIds))
            ->first(['p.id', 'p.name', 'v.colour', 'v.size', 'v.length']);

        return $v ? '"'.$v->name.'" '.self::optionLabel($v).' (#'.$v->id.')' : null;
    }

    public static function optionLabel(object|array $v): string
    {
        $v = (object) $v;

        return implode(' / ', array_filter([$v->colour ?? '', $v->size ?? '', $v->length ?? ''], fn ($x) => $x !== '')) ?: 'Any';
    }

    /* ---------------------------------------------------------------- clean + rules */

    /**
     * Cleans editor / spreadsheet input into a products row. Missing keys keep $existing's value.
     * Returns [row, variations|null, colourImages].
     */
    public static function clean(array $in, ?object $existing = null): array
    {
        $old = $existing ? (array) $existing : [];
        $get = fn (string $k, $def = null) => array_key_exists($k, $in) ? $in[$k] : ($old[$k] ?? $def);
        $txt = fn (string $k, int $max, $def = '') => mb_substr(trim((string) ($get($k, $def) ?? '')), 0, $max);
        $json = function (string $k) use ($in, $old) {
            if (array_key_exists($k, $in)) {
                return $in[$k];
            }

            return isset($old[$k]) ? (json_decode((string) $old[$k], true) ?: []) : [];
        };

        $type = $get('type', 'simple') === 'variable' ? 'variable' : 'simple';
        $price = self::money($get('price', 0));
        $regular = self::money($get('regular_price', 0));
        if ($regular < $price) {
            $regular = $price;
        }
        $barcode = preg_replace('/\s+/', '', (string) $get('barcode', ''));
        $row = [
            'type' => $type,
            'name' => $txt('name', 255),
            'name_ar' => $txt('name_ar', 255),
            'brand' => self::brand((string) $get('brand', '')),
            'sku' => $txt('sku', 80),
            'barcode' => $type === 'variable' ? '' : $barcode,
            'price' => $price,
            'regular_price' => $regular,
            'tax_status' => $get('tax_status', 'taxable') === 'none' ? 'none' : 'taxable',
            'certs' => self::list($json('certs')),
            'industries' => self::industries($json('industries')),
            'sizes' => self::list($json('sizes'), 40),
            'colours' => self::list($json('colours'), 60),
            'lengths' => self::list($json('lengths'), 20),
            'images' => array_values(array_filter(array_map(fn ($u) => trim((string) $u), (array) $json('images')))),
            'description' => mb_substr((string) ($get('description', '') ?? ''), 0, 20000),
            'description_ar' => mb_substr((string) ($get('description_ar', '') ?? ''), 0, 20000),
            'short_desc' => mb_substr((string) ($get('short_desc', '') ?? ''), 0, 2000),
            'short_desc_ar' => mb_substr((string) ($get('short_desc_ar', '') ?? ''), 0, 2000),
            'flavor' => $txt('flavor', 120),
            'flavor_ar' => $txt('flavor_ar', 120),
            'sour_level' => ($s = (int) $get('sour_level', 0)) >= 1 && $s <= 5 ? $s : null,
            'weight_grams' => max(0, (int) $get('weight_grams', 0)),
            'own_weight' => (bool) $get('own_weight', false),
            'manage_stock' => (bool) $get('manage_stock', false),
            'stock_qty' => max(-999999, min(9999999, (int) $get('stock_qty', 0))),
            'in_stock' => (bool) $get('in_stock', true),
            'status' => $get('status', 'active') === 'hidden' ? 'hidden' : 'active',
            'seo_title' => $txt('seo_title', 200), 'seo_title_ar' => $txt('seo_title_ar', 200),
            'seo_desc' => $txt('seo_desc', 400), 'seo_desc_ar' => $txt('seo_desc_ar', 400),
            'category_id' => ($c = (int) $get('category_id', 0)) > 0 ? $c : null,
            'sort_rank' => (int) ($get('sort_rank', 9999) ?? 9999),
            'gender' => in_array($get('gender', ''), ['men', 'women', 'unisex'], true) ? $get('gender') : '',
            'is_new' => (bool) $get('is_new', false),
            'best_seller' => (bool) $get('best_seller', false),
        ];
        $colourImages = [];
        foreach ((array) $json('colour_images') as $ci) {
            $ci = (array) $ci;
            $name = trim((string) ($ci['name'] ?? ''));
            $img = trim((string) ($ci['image'] ?? ''));
            if ($name !== '' && $img !== '') {
                $colourImages[] = ['name' => $name, 'image' => $img];
            }
        }
        $variations = array_key_exists('variations', $in) ? array_values((array) $in['variations']) : null;
        if ($variations !== null) {
            $variations = array_map(fn ($v) => [
                'id' => (int) (((array) $v)['id'] ?? 0) ?: null,
                'colour' => mb_substr(trim((string) (((array) $v)['colour'] ?? '')), 0, 60),
                'size' => mb_substr(trim((string) (((array) $v)['size'] ?? '')), 0, 40),
                'length' => mb_substr(trim((string) (((array) $v)['length'] ?? '')), 0, 20),
                'sku' => mb_substr(trim((string) (((array) $v)['sku'] ?? '')), 0, 80),
                'barcode' => preg_replace('/\s+/', '', (string) (((array) $v)['barcode'] ?? '')),
                'barcode2' => preg_replace('/\s+/', '', (string) (((array) $v)['barcode2'] ?? '')),
                'stock_qty' => max(-999999, min(9999999, (int) (((array) $v)['stock_qty'] ?? 0))),
                'enabled' => (bool) (((array) $v)['enabled'] ?? true),
            ], $variations);
        }

        return [$row, $variations, $colourImages];
    }

    private static function industries(mixed $v): array
    {
        $known = array_column((array) (Settings::get('industries') ?? []), 'code');
        $want = self::list($v, 30);

        return $known ? array_values(array_filter($known, fn ($c) => in_array($c, $want, true))) : [];
    }

    /** The rule set. Throws ApiError with every bad field at once. */
    public static function validate(array $row, ?array $variations, ?int $id, bool $hiddenImport = false): void
    {
        $f = [];
        if (mb_strlen($row['name']) < 2) {
            $f['name'] = 'Give the product a name.';
        }
        if ($row['price'] <= 0) {
            $f['price'] = 'Enter a price above zero.';
        }
        if (! $row['images'] && ! ($hiddenImport && $row['status'] === 'hidden')) {
            $f['images'] = 'Add at least one photo.';
        }
        if (! $row['category_id'] || ! DB::table('categories')->where('id', $row['category_id'])->exists()) {
            $f['category_id'] = 'Choose a category for the product.';
        }
        if ($row['type'] === 'simple' && $row['barcode'] !== '') {
            if (! self::barcodeOk($row['barcode'])) {
                $f['barcode'] = 'A barcode is digits (or letters and digits), without spaces.';
            } elseif ($owner = self::barcodeOwner($row['barcode'], $id)) {
                $f['barcode'] = "This barcode is already used by {$owner}.";
            }
        }
        if ($row['type'] === 'variable' && $variations !== null) {
            if (! $variations) {
                $f['variations'] = 'Add at least one variation (a colour, size or length), or make it a simple product.';
            }
            if (count($variations) > self::MAX_VARIATIONS) {
                $f['variations'] = 'A product can have up to 1000 variations.';
            }
            $seen = [];
            $codes = [];
            $ownIds = array_filter(array_column($variations, 'id'));
            foreach ($variations as $i => $v) {
                if ($v['colour'] === '' && $v['size'] === '' && $v['length'] === '') {
                    $f["variations.$i.colour"] = 'Give this variation a colour, size or length.';
                }
                $key = mb_strtolower($v['colour'].'|'.$v['size'].'|'.$v['length']);
                if (isset($seen[$key])) {
                    $f["variations.$i.colour"] = self::optionLabel($v).' is in the list twice.';
                }
                $seen[$key] = true;
                foreach (['barcode', 'barcode2'] as $col) {
                    $code = $v[$col];
                    if ($code === '') {
                        continue;
                    }
                    if (! self::barcodeOk($code)) {
                        $f["variations.$i.$col"] = 'A barcode is digits (or letters and digits), without spaces.';
                    } elseif (isset($codes[$code]) && ! ($col === 'barcode2' && $code === $v['barcode'])) {
                        $f["variations.$i.$col"] = "Barcode {$code} is used twice in this product.";
                    } elseif ($owner = self::barcodeOwner($code, $id, $ownIds)) {
                        $f["variations.$i.$col"] = "Barcode {$code} is already used by {$owner}.";
                    }
                    $codes[$code] = true;
                }
            }
        }
        if ($f) {
            throw ApiError::validation($f);
        }
    }

    /* ---------------------------------------------------------------- save */

    /** Saves a product with its variations and colour photos in one transaction. Returns the id. */
    public static function save(array $in, ?int $id = null, bool $hiddenImport = false, ?int $forceId = null): int
    {
        $existing = $id ? DB::table('products')->find($id) : null;
        [$row, $variations, $colourImages] = self::clean($in, $existing);
        if ($row['type'] === 'variable' && $variations === null && ! $existing) {
            $variations = [];
        }
        self::validate($row, $variations, $id, $hiddenImport);

        // Colour photos: only for known colours, and each must also be one of the product's photos.
        $colours = $row['type'] === 'variable' && $variations !== null
            ? array_values(array_unique(array_filter(array_column(array_filter($variations, fn ($v) => $v['enabled']), 'colour'))))
            : $row['colours'];
        $colourImages = array_values(array_filter($colourImages, fn ($c) => in_array($c['name'], $colours, true)));
        foreach ($colourImages as $c) {
            if (! in_array($c['image'], $row['images'], true)) {
                $row['images'][] = $c['image'];
            }
        }

        return DB::transaction(function () use ($row, $variations, $colourImages, $id, $existing, $forceId) {
            $data = $row;
            foreach (['certs', 'industries', 'sizes', 'colours', 'lengths', 'images'] as $k) {
                $data[$k] = json_encode($row[$k], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $data['colour_images'] = json_encode($colourImages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $data['updated_at'] = now();
            if ($existing) {
                DB::table('products')->where('id', $id)->update($data);
            } else {
                $data['slug'] = self::slug($row['name']);
                $data['created_at'] = now();
                if ($forceId) {
                    $data['id'] = $forceId;
                }
                $id = (int) DB::table('products')->insertGetId($data);
            }
            if ($row['type'] === 'simple' && $existing && $existing->type === 'variable') {
                DB::table('product_variations')->where('product_id', $id)->delete();
            }
            if ($row['type'] === 'variable' && $variations !== null) {
                self::saveVariations($id, $variations);
            }
            if ($row['type'] === 'variable') {
                self::sync($id);
            }

            return $id;
        });
    }

    /** Replaces a product's variations: matched by id, then by option combination; unknown ones inserted; missing ones deleted. */
    public static function saveVariations(int $productId, array $rows): void
    {
        $current = DB::table('product_variations')->where('product_id', $productId)->get()->keyBy('id');
        $byCombo = [];
        foreach ($current as $c) {
            $byCombo[mb_strtolower($c->colour.'|'.$c->size.'|'.$c->length)] = $c->id;
        }
        $keep = [];
        $plan = [];
        foreach ($rows as $i => $v) {
            $vid = ($v['id'] && isset($current[$v['id']])) ? $v['id'] : ($byCombo[mb_strtolower($v['colour'].'|'.$v['size'].'|'.$v['length'])] ?? null);
            if ($vid && in_array($vid, $keep, true)) {
                $vid = null;
            }
            if ($vid) {
                $keep[] = $vid;
            }
            $plan[] = [$vid, $v, $i];
        }
        DB::table('product_variations')->where('product_id', $productId)->whereNotIn('id', $keep ?: [0])->delete();
        // Free the unique option and barcode values first, so two rows can swap them.
        foreach ($keep as $vid) {
            DB::table('product_variations')->where('id', $vid)->update(['colour' => '__tmp'.$vid, 'barcode' => null]);
        }
        foreach ($plan as [$vid, $v, $pos]) {
            $data = [
                'colour' => $v['colour'], 'size' => $v['size'], 'length' => $v['length'], 'sku' => $v['sku'],
                'barcode' => $v['barcode'] === '' ? null : $v['barcode'],
                'barcode2' => $v['barcode2'] === $v['barcode'] ? '' : $v['barcode2'],
                'stock_qty' => $v['stock_qty'], 'enabled' => $v['enabled'], 'position' => $pos, 'image' => '', 'updated_at' => now(),
            ];
            if ($vid) {
                DB::table('product_variations')->where('id', $vid)->update($data);
            } else {
                DB::table('product_variations')->insert($data + ['product_id' => $productId, 'created_at' => now()]);
            }
        }
    }

    /** After anything changes a variation: the parent's stock, availability and option lists follow its enabled variations. */
    public static function sync(int $productId): void
    {
        $p = DB::table('products')->find($productId);
        if (! $p || $p->type !== 'variable') {
            return;
        }
        $vars = DB::table('product_variations')->where('product_id', $productId)->where('enabled', 1)->orderBy('position')->orderBy('id')->get();
        $total = 0;
        $c = $s = $l = [];
        foreach ($vars as $v) {
            $total += max(0, (int) $v->stock_qty);
            foreach ([[$v->colour, &$c], [$v->size, &$s], [$v->length, &$l]] as [$val, &$list]) {
                if ($val !== '' && ! in_array($val, $list, true)) {
                    $list[] = $val;
                }
            }
        }
        DB::table('products')->where('id', $productId)->update([
            'stock_qty' => $total,
            'in_stock' => $p->manage_stock ? $total > 0 : $vars->count() > 0,
            'colours' => json_encode($c, JSON_UNESCAPED_UNICODE), 'sizes' => json_encode($s, JSON_UNESCAPED_UNICODE), 'lengths' => json_encode($l, JSON_UNESCAPED_UNICODE),
            'barcode' => '', 'updated_at' => now(),
        ]);
    }

    /** Units a variation can sell now: 0 when off or the product is hidden; its stock when counted; else 999. */
    public static function variationLeft(object $p, object $v): int
    {
        if (! $v->enabled || $p->status !== 'active') {
            return 0;
        }

        return $p->manage_stock ? max(0, (int) $v->stock_qty) : 999;
    }

    public static function available(object $p): bool
    {
        if ($p->status !== 'active') {
            return false;
        }
        if ($p->type === 'variable') {
            return (bool) $p->in_stock;
        }

        return $p->manage_stock ? $p->stock_qty > 0 : (bool) $p->in_stock;
    }

    /* ---------------------------------------------------------------- categories */

    /** All categories in tree order, each with depth and whether it and every ancestor are on. */
    public static function categoryTree(): array
    {
        $all = DB::table('categories')->orderBy('sort')->orderBy('id')->get()->keyBy('id');
        $children = [];
        foreach ($all as $c) {
            $children[$c->parent_id ?? 0][] = $c->id;
        }
        $out = [];
        $walk = function ($parent, $depth, $on) use (&$walk, &$out, $children, $all) {
            foreach ($children[$parent] ?? [] as $id) {
                $c = $all[$id];
                $c->depth = $depth;
                $c->live = $on && (bool) $c->enabled;
                $out[$id] = $c;
                if ($depth < 20) {
                    $walk($id, $depth + 1, $c->live);
                }
            }
        };
        $walk(0, 0, true);
        foreach ($all as $id => $c) { // orphans (parent missing)
            if (! isset($out[$id])) {
                $c->depth = 0;
                $c->live = (bool) $c->enabled;
                $out[$id] = $c;
            }
        }

        return $out;
    }

    /** "Parent › Child" label for a category id. */
    public static function categoryLabel(?int $id, ?array $tree = null): string
    {
        $tree ??= self::categoryTree();
        $parts = [];
        $guard = 0;
        while ($id && isset($tree[$id]) && $guard++ < 20) {
            array_unshift($parts, $tree[$id]->name);
            $id = $tree[$id]->parent_id;
        }

        return implode(' › ', $parts);
    }

    /** Effective shipping weight in grams (own weight if kept, else category's inherited, else own, else 0 = fallback). */
    public static function weightGrams(object $p, ?array $tree = null): int
    {
        if ($p->own_weight && $p->weight_grams > 0) {
            return (int) $p->weight_grams;
        }
        $tree ??= self::categoryTree();
        $id = $p->category_id;
        $guard = 0;
        while ($id && isset($tree[$id]) && $guard++ < 20) {
            if ($tree[$id]->weight_grams > 0) {
                return (int) $tree[$id]->weight_grams;
            }
            $id = $tree[$id]->parent_id;
        }

        return (int) $p->weight_grams;
    }
}
