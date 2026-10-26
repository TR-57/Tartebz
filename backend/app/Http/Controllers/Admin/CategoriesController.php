<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Catalog;
use App\Support\Thumbs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Categories: list (staff), add, edit, reorder and delete (managers). */
class CategoriesController extends Controller
{
    public const ICONS = ['', 'star', 'drop', 'bolt', 'mix', 'gift', 'heart', 'leaf', 'fire'];

    public function index()
    {
        $tree = Catalog::categoryTree();
        $counts = DB::table('products')->selectRaw('category_id, COUNT(*) n, SUM(status = "active") live')->groupBy('category_id')->get()->keyBy('category_id');

        return $this->ok([
            'categories' => array_values(array_map(fn ($c) => $this->present($c, $tree, $counts[$c->id] ?? null), $tree)),
            'icons' => self::ICONS,
        ]);
    }

    public function store(Request $r)
    {
        $row = $this->clean($r, null);
        $row['slug'] = $this->slug($r->input('slug') ?: $row['name']);
        $row['sort'] = (int) DB::table('categories')->where('parent_id', $row['parent_id'])->max('sort') + 1;
        $id = DB::table('categories')->insertGetId($row + ['created_at' => now(), 'updated_at' => now()]);
        Audit::log($r, 'category_created', 'category', $id, ['name' => $row['name']]);

        return $this->ok(['id' => $id], 201);
    }

    public function update(Request $r, int $id)
    {
        $c = $this->find($id);
        $row = $this->clean($r, $c);
        if ($r->has('slug') && $this->str($r, 'slug', 60) !== $c->slug) {
            $row['slug'] = $this->slug($this->str($r, 'slug', 60) ?: $row['name'], $id);
        }
        DB::table('categories')->where('id', $id)->update($row + ['updated_at' => now()]);
        Audit::log($r, 'category_updated', 'category', $id, ['name' => $row['name']]);

        return $this->ok();
    }

    /** {ids:[…]} in the new order (same parent). */
    public function order(Request $r)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $r->input('ids', []))));
        foreach ($ids as $i => $id) {
            DB::table('categories')->where('id', $id)->update(['sort' => $i + 1]);
        }
        Audit::log($r, 'categories_reordered', 'category', '', ['ids' => $ids]);

        return $this->ok();
    }

    public function destroy(Request $r, int $id)
    {
        $c = $this->find($id);
        $n = DB::table('products')->where('category_id', $id)->count();
        if ($n) {
            throw new ApiError('in_use', "{$n} products are in this category. Move them to another category first.", 409);
        }
        if (DB::table('categories')->where('parent_id', $id)->exists()) {
            throw new ApiError('in_use', 'This category has categories inside it. Move or delete those first.', 409);
        }
        DB::table('categories')->where('id', $id)->delete();
        Audit::log($r, 'category_deleted', 'category', $id, ['name' => $c->name]);

        return $this->ok();
    }

    /* ---------------------------------------------------------------- */

    private function find(int $id): object
    {
        return DB::table('categories')->find($id) ?? throw ApiError::notFound('That category does not exist any more.');
    }

    private function clean(Request $r, ?object $c): array
    {
        $old = $c ? (array) $c : [];
        $get = fn ($k, $max, $def = '') => $r->has($k) ? $this->str($r, $k, $max) : ($old[$k] ?? $def);
        $row = [
            'name' => $get('name', 120), 'name_ar' => $get('name_ar', 120),
            'blurb' => $get('blurb', 500), 'blurb_ar' => $get('blurb_ar', 500),
            'image' => $get('image', 255), 'icon' => $get('icon', 40),
            'seo_title' => $get('seo_title', 200), 'seo_title_ar' => $get('seo_title_ar', 200),
            'seo_desc' => $get('seo_desc', 400), 'seo_desc_ar' => $get('seo_desc_ar', 400),
            'enabled' => $r->has('enabled') ? $this->bool($r, 'enabled') : (bool) ($old['enabled'] ?? true),
            'in_menu' => $r->has('in_menu') ? $this->bool($r, 'in_menu') : (bool) ($old['in_menu'] ?? true),
            'on_home' => $r->has('on_home') ? $this->bool($r, 'on_home') : (bool) ($old['on_home'] ?? false),
            'parent_id' => $r->has('parent_id') ? ((int) $r->input('parent_id') ?: null) : ($old['parent_id'] ?? null),
        ];
        $w = $r->has('weight_grams') ? $r->input('weight_grams') : ($old['weight_grams'] ?? null);
        $row['weight_grams'] = $w === null || $w === '' ? null : (int) $w;

        $f = [];
        if (mb_strlen($row['name']) < 2) {
            $f['name'] = 'Give the category a name.';
        }
        if ($row['name_ar'] === '') {
            $f['name_ar'] = 'Give the category its Arabic name.';
        }
        if (! in_array($row['icon'], self::ICONS, true)) {
            $row['icon'] = '';
        }
        if ($row['weight_grams'] !== null && ($row['weight_grams'] < 0 || $row['weight_grams'] > 100000)) {
            $f['weight_grams'] = 'Enter the weight in grams (0 to 100000), or leave it empty.';
        }
        if ($row['parent_id']) {
            // No loops: the parent may not be this category or one inside it.
            $guard = 0;
            $p = $row['parent_id'];
            while ($p && $guard++ < 25) {
                if ($c && $p === (int) $c->id) {
                    $f['parent_id'] = 'A category cannot sit inside itself.';
                    break;
                }
                $p = DB::table('categories')->where('id', $p)->value('parent_id');
            }
            if (! DB::table('categories')->where('id', $row['parent_id'])->exists()) {
                $f['parent_id'] = 'That parent category does not exist any more.';
            }
        }
        if ($f) {
            throw ApiError::validation($f);
        }

        return $row;
    }

    private function slug(string $from, ?int $except = null): string
    {
        $base = Str::slug($from) ?: 'category';
        $slug = $base;
        $i = 2;
        while (DB::table('categories')->where('slug', $slug)->when($except, fn ($q) => $q->where('id', '<>', $except))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return mb_substr($slug, 0, 60);
    }

    private function present(object $c, array $tree, ?object $count): array
    {
        return [
            'id' => $c->id, 'slug' => $c->slug, 'parentId' => $c->parent_id, 'depth' => $c->depth, 'live' => $c->live,
            'name' => $c->name, 'nameAr' => $c->name_ar, 'blurb' => (string) $c->blurb, 'blurbAr' => (string) $c->blurb_ar,
            'image' => $c->image, 'thumb' => $c->image ? Thumbs::url($c->image, 200) : null, 'icon' => $c->icon,
            'enabled' => (bool) $c->enabled, 'inMenu' => (bool) $c->in_menu, 'onHome' => (bool) $c->on_home,
            'weightGrams' => $c->weight_grams, 'sort' => (int) $c->sort,
            'seoTitle' => $c->seo_title, 'seoTitleAr' => $c->seo_title_ar, 'seoDesc' => $c->seo_desc, 'seoDescAr' => $c->seo_desc_ar,
            'products' => (int) ($count->n ?? 0), 'liveProducts' => (int) ($count->live ?? 0),
            'label' => Catalog::categoryLabel($c->id, $tree),
        ];
    }
}
