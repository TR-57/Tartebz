<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MatthiasMullie\Minify;

/**
 * Writes the storefront into the web folder (Hostinger: public_html), ready to serve:
 *  - pages: the site address filled in (link previews need full URLs) and ?v=<version> on every CSS/JS link,
 *    so browsers and Cloudflare can keep those files for a year and still get new ones after each deploy;
 *  - CSS and JS: minified (comments and spaces removed); pictures and fonts copied as they are;
 *  - files that no longer exist in the repo are removed from assets/ (uploads/ and img/ are never touched).
 */
class BuildPublic extends Command
{
    protected $signature = 'store:build-public {dest : The web folder, e.g. ~/domains/example.com/public_html} {--build= : Version label (default: git commit)}';

    protected $description = 'Build the storefront into the web folder (minified, versioned links)';

    public function handle(): int
    {
        $src = config('store.root');
        $dest = rtrim($this->argument('dest'), '/');
        if (! is_dir($dest)) {
            $this->error("No such folder: {$dest}");

            return self::FAILURE;
        }
        if (realpath($dest) === realpath($src)) {
            $this->error('The web folder must not be the repository itself.');

            return self::FAILURE;
        }
        $ver = $this->option('build') ?: trim((string) @shell_exec('git -C '.escapeshellarg($src).' rev-parse --short HEAD 2>/dev/null')) ?: (string) time();
        $site = rtrim(config('store.store_url'), '/');

        // Pages – with the Arabic words written in, so the first paint does not wait for the scripts
        $words = self::arabicWords($src);
        $logoHead = self::logoHead();
        foreach (glob($src.'/*.html') as $file) {
            $html = (string) file_get_contents($file);
            $html = str_replace('__SITE__', $site, $html);
            $html = preg_replace('#(["\'])(/assets/(?:js|css)/[^"\'?]+\.(?:js|css))\1#', '$1$2?v='.$ver.'$1', $html);
            $html = self::prerender($html, $words);
            $html = str_replace('<link rel="stylesheet"', $logoHead.'<link rel="stylesheet"', $html);
            $this->put($dest.'/'.basename($file), $html);
        }

        // At the top of the site: the orders board's service worker and app manifest (a service worker only covers pages below it),
        // and the site icon browsers ask for at /favicon.ico.
        foreach (['staff-sw.js', 'staff.webmanifest', 'favicon.ico'] as $f) {
            if (is_file($src.'/'.$f)) {
                $this->put($dest.'/'.$f, (string) file_get_contents($src.'/'.$f));
            }
        }

        // Assets
        $written = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src.'/assets', \FilesystemIterator::SKIP_DOTS));
        $saved = 0;
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($src) + 1);
            $out = $dest.'/'.$rel;
            $ext = strtolower($f->getExtension());
            $vendor = str_starts_with($rel, 'assets/vendor/');   // already built upstream: copied as it is
            if ($ext === 'js' && ! $vendor) {
                $code = (string) file_get_contents($f->getPathname());
                // Files loaded from inside a script get the same version.
                $code = preg_replace('#(["\'])(/assets/js/[^"\'?]+\.js)\1#', '$1$2?v='.$ver.'$1', $code);
                $min = (new Minify\JS($code))->minify();
                $saved += strlen($code) - strlen($min);
                $this->put($out, $min);
            } elseif ($ext === 'css' && ! $vendor) {
                $code = (string) file_get_contents($f->getPathname());
                $min = (new Minify\CSS($code))->minify();
                $saved += strlen($code) - strlen($min);
                $this->put($out, $min);
            } else {
                if (! is_dir(dirname($out))) {
                    mkdir(dirname($out), 0775, true);
                }
                copy($f->getPathname(), $out);
            }
            $written[$out] = true;
        }
        // Remove assets that were deleted from the repo.
        $removed = 0;
        if (is_dir($dest.'/assets')) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dest.'/assets', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                if ($f->isFile() && ! isset($written[$f->getPathname()])) {
                    unlink($f->getPathname());
                    $removed++;
                } elseif ($f->isDir() && ! (new \FilesystemIterator($f->getPathname()))->valid()) {
                    rmdir($f->getPathname());
                }
            }
        }
        $this->info(sprintf('Built version %s: %d pages, %d asset files (%s KB smaller), %d old files removed.',
            $ver, count(glob($src.'/*.html')), count($written), number_format($saved / 1024, 1), $removed));

        return self::SUCCESS;
    }

    private function put(string $path, string $content): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $tmp = $path.'.tmp'.getmypid();
        file_put_contents($tmp, $content);
        @chmod($tmp, 0664);
        rename($tmp, $path);
    }

    /** The built-in Arabic wording of the shop pages (i18n/catalog.json, made by tools/i18n-build.mjs). */
    private static function arabicWords(string $src): array
    {
        $cat = json_decode((string) @file_get_contents($src.'/i18n/catalog.json'), true);
        $out = [];
        foreach ((array) ($cat['site']['keys'] ?? []) as $k => $v) {
            $ar = (string) ($v['ar'] ?? '');
            if ($ar !== '' && ! str_contains($ar, '{')) {
                $out[$k] = $ar;
            }
        }

        return $out;
    }

    /**
     * Fills the empty [data-i18n] / [data-i18n-html] elements with their Arabic text. The page script sets the same words
     * again (or the English ones, or the owner's own wording from Admin › Translations).
     */
    public static function prerender(string $html, array $words): string
    {
        return (string) preg_replace_callback('#(<([a-z0-9]+)\b[^>]*?\sdata-i18n(-html)?="([a-z0-9_]+)"[^>]*>)(</\2>)#i', function ($m) use ($words) {
            if (! isset($words[$m[4]])) {
                return $m[0];
            }

            return $m[1].($m[3] ? $words[$m[4]] : htmlspecialchars($words[$m[4]], ENT_QUOTES | ENT_HTML5, 'UTF-8')).$m[5];
        }, $html);
    }

    /** The shop logo's addresses (Admin › Settings › Brand) for the header, and the browser starts fetching it at once. */
    private static function logoHead(): string
    {
        try {
            $path = (string) (\App\Support\Settings::get('brand')['logo'] ?? '');
            if ($path === '') {
                return '';
            }
            $url = \App\Support\Media::url($path, (int) \Illuminate\Support\Facades\DB::table('media')->where('path', $path)->value('version'));
            $l = ['s' => \App\Support\Thumbs::url($url, 200), 'm' => \App\Support\Thumbs::url($url, 400), 'l' => \App\Support\Thumbs::url($url, 800)];
        } catch (\Throwable) {
            return '';   // no database while building: the header waits for the shop data instead
        }
        $e = fn ($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        return '<link rel="preload" as="image" href="'.$e($l['s']).'" imagesrcset="'.$e($l['s']).' 200w, '.$e($l['m']).' 400w" imagesizes="(min-width: 700px) 220px, 120px">'."\n  "
            .'<script>window.TZ_LOGO='.json_encode($l, JSON_UNESCAPED_SLASHES).';</script>'."\n  ";
    }
}
