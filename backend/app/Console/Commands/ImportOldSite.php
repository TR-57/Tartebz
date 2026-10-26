<?php

namespace App\Console\Commands;

use App\Support\Catalog;
use App\Support\Media;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings the old WordPress shop into the new store: its photos (byte for byte, same folder names under uploads/wp/),
 * its categories and its products (same product numbers), plus the logo and contact details.
 * Safe to run again: it updates what is there and never deletes anything.
 */
class ImportOldSite extends Command
{
    protected $signature = 'store:import-old-site {--file= : Seed file (default database/seed/old-site.json)} {--photos= : Folder with the photos (default media-seed/)}';

    protected $description = 'Import the old shop: photos, logo, categories and products';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('seed/old-site.json');
        $photos = rtrim($this->option('photos') ?: config('store.root').'/media-seed', '/');
        $seed = json_decode((string) @file_get_contents($file), true);
        if (! is_array($seed)) {
            $this->error("Could not read {$file}.");

            return self::FAILURE;
        }

        // 1. Photos – originals copied exactly as they are.
        $added = 0;
        foreach ($seed['media'] as $m) {
            $src = $photos.'/'.$m['path'];
            if (! is_file($src)) {
                $this->warn("Missing photo {$m['path']} – skipped.");

                continue;
            }
            $row = DB::table('media')->where('path', $m['path'])->first();
            if (! $row || ! is_file(Media::abs($m['path']))) {
                $row = Media::store((string) file_get_contents($src), basename($m['path']), $m['use'], null, $m['path']);
                $added++;
            }
            DB::table('media')->where('id', $row->id)->update([
                'title' => $m['title'], 'alt_en' => $m['alt_en'], 'alt_ar' => $m['alt_ar'],
            ]);
        }
        $this->info("Photos: {$added} added, ".count($seed['media']).' in the seed.');

        // 2. Settings – contact details only when they were never saved, so edits made in the admin are kept.
        if (Settings::raw('business') === null) {
            Settings::set('business', array_merge(Settings::get('business'), $seed['business']));
            $this->info('Business details saved.');
        }
        if (Settings::raw('brand') === null) {
            Settings::set('brand', array_merge(Settings::get('brand'), $seed['brand']));
            $this->info('Logo and social links saved.');
        }

        // 3. Categories (matched by slug).
        $catIds = [];
        foreach ($seed['categories'] as $c) {
            $existing = DB::table('categories')->where('slug', $c['slug'])->first();
            if ($existing) {
                $catIds[$c['slug']] = $existing->id;

                continue;
            }
            $catIds[$c['slug']] = DB::table('categories')->insertGetId($c + ['created_at' => now(), 'updated_at' => now()]);
        }
        $this->info('Categories: '.count($catIds).'.');

        // 4. Products – same numbers as the old shop; existing ones are left as they are.
        $made = 0;
        foreach ($seed['products'] as $p) {
            if (DB::table('products')->where('id', $p['id'])->exists()) {
                continue;
            }
            $in = [
                'type' => 'simple', 'name' => $p['name'], 'name_ar' => $p['name_ar'], 'brand' => 'Tartebz',
                'price' => $p['price'], 'regular_price' => $p['price'],
                'images' => array_map(fn ($path) => Media::url($path), $p['images']),
                'description' => $p['description'], 'description_ar' => $p['description_ar'],
                'flavor' => $p['flavor'], 'flavor_ar' => $p['flavor_ar'], 'sour_level' => $p['sour_level'],
                'weight_grams' => $p['weight_grams'], 'own_weight' => true,
                'manage_stock' => true, 'stock_qty' => $p['stock_qty'], 'in_stock' => $p['stock_qty'] > 0,
                'status' => $p['status'], 'category_id' => $catIds[$p['category']] ?? null,
                'sort_rank' => $p['sort_rank'], 'best_seller' => $p['best_seller'],
            ];
            $id = Catalog::save($in, null, true, $p['id']);
            DB::table('products')->where('id', $id)->update(['slug' => $p['slug'], 'permalink' => $p['old_url'] ?? '']);
            $made++;
            if ($p['status'] === 'hidden') {
                $this->line("  #{$p['id']} {$p['name']} is hidden".($p['images'] ? '' : ' (no photo yet)').'.');
            }
        }
        $this->info("Products: {$made} added.");

        return self::SUCCESS;
    }
}
