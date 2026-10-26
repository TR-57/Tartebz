<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The media library.
 * Originals are kept exactly as uploaded (only GPS location is blanked, in place, without re-encoding).
 * Resized copies are made on demand by ImageController and never replace the original.
 */
class Media
{
    public const IMAGE_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    public const MAX_PIXELS = 40_000_000;

    public static function dir(): string
    {
        return rtrim(config('store.upload_dir'), '/');
    }

    public static function abs(string $path): string
    {
        return self::dir().'/'.ltrim($path, '/');
    }

    public static function url(string $path, int $version = 0): string
    {
        return config('store.upload_url').'/'.ltrim($path, '/').($version ? '?v='.$version : '');
    }

    /** /uploads/a/b.jpg?v=3 -> a/b.jpg (null when it is not one of ours). */
    public static function pathFromUrl(string $url): ?string
    {
        $url = trim($url);
        $site = config('store.store_url');
        if ($site && str_starts_with($url, $site.'/')) {
            $url = substr($url, strlen($site));
        }
        $prefix = config('store.upload_url').'/';
        if (! str_starts_with($url, $prefix)) {
            return null;
        }
        $p = explode('?', substr($url, strlen($prefix)))[0];

        return str_contains($p, '..') ? null : $p;
    }

    /** What the bytes really are: jpg | png | webp | gif | pdf | null. Never trust the file name. */
    public static function sniff(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'gif';
        }
        if (str_starts_with($bytes, '%PDF-')) {
            return 'pdf';
        }

        return null;
    }

    /**
     * Checks and stores a file. $use: product | content | slide | logo | brand | library.
     * $relPath forces where it goes (imports); otherwise <folder>/<yyyy>/<mm>/<random>.<ext>.
     * Returns the media row.
     */
    public static function store(string $bytes, string $originalName, string $use = 'library', ?int $adminId = null, ?string $relPath = null, bool $allowPdf = true): object
    {
        $max = config('store.upload_max_mb') * 1024 * 1024;
        if (strlen($bytes) === 0) {
            throw ApiError::validation(['file' => 'Choose a photo to upload (JPG, PNG or WebP, up to 8 MB).']);
        }
        if (strlen($bytes) > $max && $relPath === null) {
            throw ApiError::validation(['file' => 'That file is over 8 MB.']);
        }
        if (preg_match('/^\s*(<\?xml|<svg)/i', substr($bytes, 0, 200)) || str_ends_with(strtolower($originalName), '.svg')) {
            throw ApiError::validation(['file' => 'SVG files are not accepted. Save the picture as PNG or WebP and upload that.']);
        }
        $ext = self::sniff($bytes);
        if (! $ext || ($ext === 'pdf' && ! $allowPdf)) {
            throw ApiError::validation(['file' => $allowPdf
                ? 'That file type is not accepted. Upload a JPG, PNG, WebP or GIF picture, or a PDF.'
                : 'That is not a JPG, PNG or WebP photo.']);
        }
        $w = $h = null;
        if ($ext !== 'pdf') {
            $info = @getimagesizefromstring($bytes);
            if (! $info || ! $info[0] || ! $info[1]) {
                throw ApiError::validation(['file' => 'That picture could not be read. Save it again as JPG or PNG and upload that.']);
            }
            [$w, $h] = $info;
            if ($w * $h > self::MAX_PIXELS) {
                throw ApiError::validation(['file' => 'That picture is too big (over 40 megapixels). Make it smaller and upload it again.']);
            }
            if ($ext === 'jpg' && ExifScrub::hasGps($bytes)) {
                $bytes = ExifScrub::stripGps($bytes); // same pixels, location removed
            }
        }

        $folder = match ($use) {
            'product' => 'products', 'content', 'slide' => 'content', 'logo' => 'logos', 'brand' => 'brands', default => 'library',
        };
        if ($relPath === null) {
            $base = $ext === 'pdf'
                ? Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'document'
                : bin2hex(random_bytes(10));
            $relPath = $folder.'/'.date('Y/m').'/'.($ext === 'pdf' ? $base.'-'.bin2hex(random_bytes(3)) : $base).'.'.($ext === 'jpg' && preg_match('/\.jpeg$/i', $originalName) ? 'jpeg' : $ext);
        }
        $relPath = ltrim($relPath, '/');
        if (str_contains($relPath, '..')) {
            throw ApiError::validation(['file' => 'Bad file name.']);
        }
        $abs = self::abs($relPath);
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0775, true);
        }
        // Write to a temporary file, then rename over the target: nobody ever reads half a file.
        $tmp = $abs.'.tmp-'.bin2hex(random_bytes(4));
        file_put_contents($tmp, $bytes);
        @chmod($tmp, 0664);
        rename($tmp, $abs);

        $title = trim(preg_replace('/[-_]+/', ' ', pathinfo($originalName, PATHINFO_FILENAME)));
        $row = [
            'path' => $relPath,
            'url' => self::url($relPath),
            'original_name' => mb_substr($originalName, 0, 190),
            'title' => mb_substr($title, 0, 190),
            'mime' => $ext === 'pdf' ? 'application/pdf' : self::IMAGE_TYPES[$ext],
            'size' => strlen($bytes),
            'width' => $w, 'height' => $h,
            'kind' => $ext === 'pdf' ? 'document' : 'image',
            'folder' => $folder === 'library' ? 'library' : rtrim($folder, 's'),
            'uploaded_by' => $adminId,
            'updated_at' => now(),
        ];
        $existing = DB::table('media')->where('path', $relPath)->first();
        if ($existing) {
            DB::table('media')->where('id', $existing->id)->update($row);
            $id = $existing->id;
        } else {
            $id = DB::table('media')->insertGetId($row + ['created_at' => now()]);
        }

        return DB::table('media')->find($id);
    }

    public static function present(object $m): array
    {
        return [
            'id' => $m->id, 'path' => $m->path, 'url' => self::url($m->path, (int) $m->version),
            'title' => $m->title, 'originalName' => $m->original_name, 'altEn' => $m->alt_en, 'altAr' => $m->alt_ar,
            'mime' => $m->mime, 'size' => (int) $m->size, 'width' => $m->width ? (int) $m->width : null, 'height' => $m->height ? (int) $m->height : null,
            'kind' => $m->kind, 'folder' => $m->folder, 'version' => (int) $m->version,
            'thumb' => $m->kind === 'image' ? Thumbs::url(self::url($m->path, (int) $m->version), 400) : null,
            'uploadedBy' => $m->uploaded_by ? DB::table('admins')->where('id', $m->uploaded_by)->value('name') : null,
            'createdAt' => $m->created_at,
        ];
    }

    /* ------------------------------------------------------------------ usage */

    private const SETTING_LABELS = [
        'content' => ['Homepage', '#/homepage'], 'menus' => ['Menus', '#/menus'], 'brands' => ['Brands', '#/brands'],
        'seo' => ['SEO', '#/seo'], 'payment_logos' => ['Payments > Checkout logos', '#/payments?tab=logos'],
        'business' => ['Settings', '#/settings'], 'featured_products' => ['Featured products', '#/featured'],
        'industries' => ['Industries', '#/industries'], 'sizecharts' => ['Size charts', '#/sizecharts'],
        'popups' => ['Pop-ups', '#/popups'], 'email_templates' => ['Emails', '#/emails'],
    ];

    /** Where a file is used. Never stored – always worked out now, so it cannot go stale. */
    public static function usage(string $path): array
    {
        $like = '%'.addcslashes($path, '%_\\').'%';
        $out = [];
        foreach (DB::table('products')->where(fn ($q) => $q->where('images', 'like', $like)->orWhere('colour_images', 'like', $like)
            ->orWhere('description', 'like', $like)->orWhere('short_desc', 'like', $like)->orWhere('description_ar', 'like', $like))
            ->limit(100)->get(['id', 'name']) as $p) {
            $out[] = ['where' => 'product', 'label' => "Product #{$p->id} {$p->name}", 'link' => "#/products/{$p->id}"];
        }
        foreach (DB::table('settings')->where('v', 'like', $like)->pluck('k') as $k) {
            [$label, $link] = self::SETTING_LABELS[$k] ?? ['Setting: '.$k, '#/settings'];
            $out[] = ['where' => 'setting', 'label' => $label, 'link' => $link];
        }
        foreach (DB::table('categories')->where(fn ($q) => $q->where('image', 'like', $like)->orWhere('size_image', 'like', $like))->get(['id', 'name']) as $c) {
            $out[] = ['where' => 'category', 'label' => "Category {$c->name}", 'link' => '#/categories'];
        }
        if (Schema::hasTable('order_items') && ($n = DB::table('order_items')->where('image', 'like', $like)->distinct()->count('order_id'))) {
            $out[] = ['where' => 'orders', 'label' => "{$n} past orders", 'link' => '#/orders'];
        }
        foreach (self::otherTextColumns() as [$table, $col]) {
            if (DB::table($table)->where($col, 'like', $like)->exists()) {
                $out[] = ['where' => 'database', 'label' => "{$table}.{$col}", 'link' => null];
            }
        }
        foreach (self::staticFilesUsing($path) as $f) {
            $out[] = ['where' => 'file', 'label' => $f, 'link' => null];
        }

        return $out;
    }

    /** Text columns of every other table (email campaigns, enquiries, translations, coupons …). */
    private static function otherTextColumns(): array
    {
        static $cols = null;
        if ($cols !== null) {
            return $cols;
        }
        $skip = ['media', 'products', 'settings', 'categories', 'order_items', 'mail_outbox', 'audit_log', 'sessions', 'cache', 'cache_locks', 'photo_fetches', 'migrations', 'admin_tokens'];
        $rows = DB::select("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND DATA_TYPE IN ('text','mediumtext','longtext','json','varchar') AND (DATA_TYPE <> 'varchar' OR CHARACTER_MAXIMUM_LENGTH >= 150)");
        $cols = [];
        foreach ($rows as $r) {
            if (! in_array($r->t, $skip, true)) {
                $cols[] = [$r->t, $r->c];
            }
        }

        return $cols;
    }

    private static function staticFilesUsing(string $path): array
    {
        $root = config('store.root');
        $hits = [];
        $globs = array_merge(glob($root.'/*.html') ?: [], glob($root.'/assets/js/*.js') ?: [], glob($root.'/assets/css/*.css') ?: []);
        foreach ($globs as $f) {
            if (str_contains((string) @file_get_contents($f), $path)) {
                $hits[] = substr($f, strlen($root) + 1);
            }
        }

        return $hits;
    }

    /** One text blob of everything that can reference a file, for the "Unused" filter (one pass, not one query per file). */
    public static function usedHaystack(): string
    {
        $parts = [];
        foreach (DB::table('products')->get(['images', 'colour_images', 'description', 'description_ar', 'short_desc']) as $p) {
            $parts[] = implode(' ', array_map('strval', (array) $p));
        }
        $parts[] = implode(' ', DB::table('settings')->pluck('v')->all());
        foreach (DB::table('categories')->get(['image', 'size_image']) as $c) {
            $parts[] = $c->image.' '.$c->size_image;
        }
        if (Schema::hasTable('order_items')) {
            $parts[] = implode(' ', DB::table('order_items')->distinct()->pluck('image')->all());
        }
        foreach (self::otherTextColumns() as [$t, $c]) {
            $parts[] = implode(' ', DB::table($t)->whereNotNull($c)->pluck($c)->map(fn ($v) => (string) $v)->all());
        }
        $root = config('store.root');
        foreach (array_merge(glob($root.'/*.html') ?: [], glob($root.'/assets/js/*.js') ?: [], glob($root.'/assets/css/*.css') ?: []) as $f) {
            $parts[] = (string) @file_get_contents($f);
        }

        return implode("\n", $parts);
    }

    /* ------------------------------------------------------------------ replace / delete */

    /** Writes the new file over the same path and makes every stored link point at the new version. */
    public static function replace(object $m, string $bytes, string $originalName): array
    {
        $ext = self::sniff($bytes);
        $wasPdf = $m->kind === 'document';
        if (! $ext || ($ext === 'pdf') !== $wasPdf) {
            throw ApiError::validation(['file' => $wasPdf ? 'A PDF can only be replaced by another PDF.' : 'A picture can only be replaced by another picture.']);
        }
        if ($ext !== 'pdf') {
            $oldExt = strtolower(pathinfo($m->path, PATHINFO_EXTENSION));
            $oldExt = $oldExt === 'jpeg' ? 'jpg' : $oldExt;
            if ($oldExt !== $ext) {
                throw ApiError::validation(['file' => 'Upload the same kind of picture ('.strtoupper($oldExt).') so every link keeps working.']);
            }
        }
        $row = self::store($bytes, $originalName, $m->folder, $m->uploaded_by, $m->path);
        $version = time();
        DB::table('media')->where('id', $m->id)->update(['version' => $version, 'title' => $m->title, 'alt_en' => $m->alt_en, 'alt_ar' => $m->alt_ar]);
        Thumbs::forget($m->path);

        return ['media' => DB::table('media')->find($m->id), 'linksUpdated' => self::relink($m->path, $version)];
    }

    /** Rewrites /uploads/<path> and /uploads/<path>?v=<old> to …?v=<version> in products, settings and categories. */
    public static function relink(string $path, int $version): int
    {
        $base = self::url($path);
        $re = '#'.preg_quote($base, '#').'(\?v=\d+)?#';
        $to = $base.'?v='.$version;
        $n = 0;
        $like = '%'.addcslashes($path, '%_\\').'%';
        foreach (DB::table('products')->where(fn ($q) => $q->where('images', 'like', $like)->orWhere('colour_images', 'like', $like)->orWhere('description', 'like', $like))->get() as $p) {
            $upd = [];
            foreach (['images', 'colour_images', 'description', 'description_ar', 'short_desc', 'short_desc_ar'] as $col) {
                if ($p->$col !== null && str_contains($p->$col, $base)) {
                    $upd[$col] = preg_replace($re, $to, $p->$col);
                    // JSON columns store "/" escaped as "\/" sometimes – handle both.
                    $upd[$col] = preg_replace('#'.preg_quote(str_replace('/', '\/', $base), '#').'(\?v=\d+)?#', str_replace('/', '\/', $to), $upd[$col]);
                }
            }
            if ($upd) {
                DB::table('products')->where('id', $p->id)->update($upd);
                $n++;
            }
        }
        foreach (DB::table('settings')->where('v', 'like', $like)->get() as $s) {
            $v = preg_replace($re, $to, $s->v);
            $v = preg_replace('#'.preg_quote(str_replace('/', '\/', $base), '#').'(\?v=\d+)?#', str_replace('/', '\/', $to), $v);
            DB::table('settings')->where('k', $s->k)->update(['v' => $v]);
            $n++;
        }
        foreach (DB::table('categories')->where(fn ($q) => $q->where('image', 'like', $like)->orWhere('size_image', 'like', $like))->get() as $c) {
            DB::table('categories')->where('id', $c->id)->update(['image' => preg_replace($re, $to, $c->image), 'size_image' => preg_replace($re, $to, $c->size_image)]);
            $n++;
        }
        Settings::flushMemo();

        return $n;
    }

    public static function delete(object $m): void
    {
        @unlink(self::abs($m->path));
        Thumbs::forget($m->path);
        DB::table('media')->where('id', $m->id)->delete();
        DB::table('photo_fetches')->where('local', $m->path)->delete();
    }
}
