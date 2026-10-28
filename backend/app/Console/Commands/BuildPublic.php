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

        // Pages
        foreach (glob($src.'/*.html') as $file) {
            $html = (string) file_get_contents($file);
            $html = str_replace('__SITE__', $site, $html);
            $html = preg_replace('#(["\'])(/assets/(?:js|css)/[^"\'?]+\.(?:js|css))\1#', '$1$2?v='.$ver.'$1', $html);
            $this->put($dest.'/'.basename($file), $html);
        }

        // Orders board: its service worker and app manifest live at the top (a service worker only covers pages below it).
        foreach (['staff-sw.js', 'staff.webmanifest'] as $f) {
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
            if ($ext === 'js') {
                $code = (string) file_get_contents($f->getPathname());
                // Files loaded from inside a script get the same version.
                $code = preg_replace('#(["\'])(/assets/js/[^"\'?]+\.js)\1#', '$1$2?v='.$ver.'$1', $code);
                $min = (new Minify\JS($code))->minify();
                $saved += strlen($code) - strlen($min);
                $this->put($out, $min);
            } elseif ($ext === 'css') {
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
}
