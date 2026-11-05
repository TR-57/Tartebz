<?php

namespace App\Console\Commands;

use App\Support\Media;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Adds the shop's packaging photos (backend/database/seed/photos) to the media library and puts them first on their
 * products; the photos the product already had stay after them. Each photo is added once only – removing or reordering
 * it later in Admin › Products is kept. The files are copied as they are (never compressed); the website shows
 * resized copies. deploy.sh runs this on every deploy.
 */
class ProductPhotos extends Command
{
    protected $signature = 'store:product-photos';

    protected $description = 'Add the packaging photos from the repository to their products (once each)';

    /** product slug => photos, first is the main one */
    public const PHOTOS = [
        'tartebz-fruits' => ['tartebz-fruits-box.png'],
        'tartebz-fruits-berry' => ['tartebz-fruits-box.png'],
        'tartebz-fruits-pineapple' => ['tartebz-fruits-box.png'],
        'tartebz-fruits-mango' => ['tartebz-fruits-box.png'],
        'electric-sour-strips' => ['electric-sour-strips-box.png'],
        'sweet-sour-strips' => ['sweet-sour-strips-box.png'],
        'marshmallow-red-berry' => ['marshmallow-red-berry-box.png', 'marshmallow-red-berry-open.png'],
    ];

    public function handle(): int
    {
        $done = (array) (Settings::raw('photos_added') ?? []);
        $added = 0;
        foreach (self::PHOTOS as $slug => $files) {
            $p = DB::table('products')->where('slug', $slug)->first();
            if (! $p) {
                $this->warn("No product {$slug} – skipped.");

                continue;
            }
            $new = [];
            foreach ($files as $file) {
                $key = $slug.'/'.$file; // one photo can belong to several products
                if (in_array($key, $done, true)) {
                    continue;
                }
                $rel = 'products/tartebz/'.$file;
                $m = DB::table('media')->where('path', $rel)->first()
                    ?? Media::store((string) file_get_contents(database_path('seed/photos/'.$file)), $file, 'product', null, $rel);
                $new[] = Media::url($m->path);
                $done[] = $key;
                $added++;
            }
            if ($new) {
                $images = json_decode((string) $p->images, true) ?: [];
                $images = array_values(array_unique(array_merge($new, array_diff($images, $new))));
                DB::table('products')->where('id', $p->id)->update(['images' => json_encode($images, JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
                $this->line("{$slug}: ".count($new).' photo(s) added first.');
            }
        }
        Settings::set('photos_added', array_values(array_unique($done)));
        $this->info($added ? "Added {$added} photo(s)." : 'Nothing new to add.');

        return self::SUCCESS;
    }
}
