<?php

namespace App\Support;

/**
 * Resized copies for the storefront and admin: /img/<width>/<uploads|assets>/<path>.
 * Made from the ORIGINAL on first request (WebP, quality 86, never wider than the original, transparency kept),
 * written next to nothing else, and served straight from disk afterwards (Hostinger: .htaccess rule).
 * The original file is never changed.
 */
class Thumbs
{
    public const QUALITY = 86;

    /** /uploads/a/b.jpg?v=3 -> /img/400/uploads/a/b.jpg?v=3 ; anything else is returned as it is. */
    public static function url(string $url, int $width): string
    {
        $q = '';
        if (($i = strpos($url, '?')) !== false) {
            $q = substr($url, $i);
            $url = substr($url, 0, $i);
        }
        if (! preg_match('#^/(uploads|assets)/.+\.(jpe?g|png|webp)$#i', $url)) {
            return $url.$q;
        }

        return '/img/'.self::snap($width).$url.$q;
    }

    public static function snap(int $w): int
    {
        foreach (config('store.thumb_widths') as $allowed) {
            if ($w <= $allowed) {
                return $allowed;
            }
        }

        return max(config('store.thumb_widths'));
    }

    /** Absolute path of the original behind a thumbnail path like "uploads/a/b.jpg" or "assets/img/x.png". */
    public static function source(string $rel): ?string
    {
        if (str_contains($rel, '..') || ! preg_match('#^(uploads|assets)/(.+)$#', $rel, $m)) {
            return null;
        }
        $file = $m[1] === 'uploads' ? Media::abs($m[2]) : config('store.root').'/assets/'.$m[2];

        return is_file($file) ? $file : null;
    }

    public static function target(int $w, string $rel): string
    {
        return rtrim(config('store.thumb_dir'), '/').'/'.$w.'/'.$rel.'.webp';
    }

    /** Makes (once) and returns the thumbnail's absolute path, or null if it cannot be made. */
    public static function make(int $w, string $rel): ?string
    {
        $src = self::source($rel);
        if (! $src || ! in_array($w, config('store.thumb_widths'), true)) {
            return null;
        }
        $dst = self::target($w, $rel);
        if (is_file($dst) && filemtime($dst) >= filemtime($src)) {
            return $dst;
        }
        if (! is_dir(dirname($dst)) && ! @mkdir(dirname($dst), 0775, true) && ! is_dir(dirname($dst))) {
            return null;
        }
        // Parallel requests for the same copy wait for the first one.
        $lock = fopen($dst.'.lock', 'c');
        if (! $lock) {
            return null;
        }
        try {
            flock($lock, LOCK_EX);
            if (is_file($dst) && filemtime($dst) >= filemtime($src)) {
                return $dst;
            }
            $bytes = file_get_contents($src);
            $img = @imagecreatefromstring($bytes);
            if (! $img) {
                return null;
            }
            $img = self::orient($img, $bytes);
            $ow = imagesx($img);
            $oh = imagesy($img);
            $tw = min($w, $ow); // never upscale
            $th = max(1, (int) round($oh * $tw / $ow));
            $out = imagecreatetruecolor($tw, $th);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
            imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $ow, $oh);
            $tmp = $dst.'.tmp';
            if (! imagewebp($out, $tmp, self::QUALITY)) {
                return null;
            }
            rename($tmp, $dst);
            @chmod($dst, 0664);
            imagedestroy($img);
            imagedestroy($out);

            return $dst;
        } catch (\Throwable) {
            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($dst.'.lock');
        }
    }

    /** Applies the camera's orientation flag to the copy (the original keeps its bytes and its flag). */
    private static function orient(\GdImage $img, string $bytes): \GdImage
    {
        if (! str_starts_with($bytes, "\xFF\xD8") || ! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $o = (int) ($exif['Orientation'] ?? 1);

        return match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }

    /** Link-preview picture for WhatsApp / Instagram / Facebook: 1200×630 JPEG (the format every app shows). */
    public static function ogUrl(string $url): string
    {
        $url = explode('?', $url)[0];

        return preg_match('#^/(uploads|assets)/.+\.(jpe?g|png|webp)$#i', $url) ? '/img/og'.$url : $url;
    }

    public static function ogTarget(string $rel): string
    {
        return rtrim(config('store.thumb_dir'), '/').'/og/'.$rel.'.jpg';
    }

    public static function makeOg(string $rel): ?string
    {
        $src = self::source($rel);
        if (! $src) {
            return null;
        }
        $dst = self::ogTarget($rel);
        if (is_file($dst) && filemtime($dst) >= filemtime($src)) {
            return $dst;
        }
        if (! is_dir(dirname($dst)) && ! @mkdir(dirname($dst), 0775, true) && ! is_dir(dirname($dst))) {
            return null;
        }
        try {
            $bytes = file_get_contents($src);
            $img = @imagecreatefromstring($bytes);
            if (! $img) {
                return null;
            }
            $img = self::orient($img, $bytes);
            [$W, $H] = [1200, 630];
            $out = imagecreatetruecolor($W, $H);
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // transparent PNGs on white
            $ow = imagesx($img);
            $oh = imagesy($img);
            // Fit the whole picture (never crop the product), centred on white.
            $k = min($W / $ow, $H / $oh);
            $tw = (int) round($ow * $k);
            $th = (int) round($oh * $k);
            imagecopyresampled($out, $img, intdiv($W - $tw, 2), intdiv($H - $th, 2), 0, 0, $tw, $th, $ow, $oh);
            $tmp = $dst.'.tmp'.getmypid();
            imagejpeg($out, $tmp, 84);
            rename($tmp, $dst);
            @chmod($dst, 0664);

            return $dst;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Deletes every copy of one original (after it was replaced or deleted). */
    public static function forget(string $uploadPath): void
    {
        foreach (config('store.thumb_widths') as $w) {
            @unlink(self::target($w, 'uploads/'.ltrim($uploadPath, '/')));
        }
        @unlink(self::ogTarget('uploads/'.ltrim($uploadPath, '/')));
    }
}
