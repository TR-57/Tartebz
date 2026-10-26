<?php

namespace App\Support;

/**
 * Removes GPS location from a JPEG without touching a single pixel: the GPS block inside the EXIF segment is
 * overwritten with zeros in place, so every other tag (orientation, camera, date) stays exactly where it was.
 */
class ExifScrub
{
    public static function hasGps(string $jpeg): bool
    {
        return self::gpsSpan($jpeg) !== null;
    }

    public static function stripGps(string $jpeg): string
    {
        $span = self::gpsSpan($jpeg);
        if ($span === null) {
            return $jpeg;
        }
        [$tiff, $little, $ifdOffset, $pointerEntry] = $span;
        $u16 = fn (int $o) => unpack($little ? 'v' : 'n', substr($jpeg, $o, 2))[1];
        $u32 = fn (int $o) => unpack($little ? 'V' : 'N', substr($jpeg, $o, 4))[1];
        $sizes = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 7 => 1, 9 => 4, 10 => 8];

        $gpsIfd = $tiff + $ifdOffset;
        $n = $u16($gpsIfd);
        if ($n > 200 || $gpsIfd + 2 + $n * 12 > strlen($jpeg)) {
            return $jpeg;
        }
        for ($i = 0; $i < $n; $i++) {
            $e = $gpsIfd + 2 + $i * 12;
            $type = $u16($e + 2);
            $count = $u32($e + 4);
            $len = ($sizes[$type] ?? 1) * $count;
            if ($len > 4) {
                $off = $tiff + $u32($e + 8);
                if ($off + $len <= strlen($jpeg)) {
                    $jpeg = substr_replace($jpeg, str_repeat("\0", $len), $off, $len);
                }
            }
        }
        // Empty the GPS directory (no entries) and point the tag at nothing useful.
        $jpeg = substr_replace($jpeg, str_repeat("\0", 2 + $n * 12), $gpsIfd, 2 + $n * 12);
        // Turn the GPS pointer tag in IFD0 into a harmless private tag.
        $jpeg = substr_replace($jpeg, $little ? "\xff\xff" : "\xff\xff", $pointerEntry, 2);

        return $jpeg;
    }

    /** [tiffStart, littleEndian, gpsIfdOffset, offsetOfTheGpsTagEntry] or null. */
    private static function gpsSpan(string $j): ?array
    {
        if (substr($j, 0, 2) !== "\xFF\xD8") {
            return null;
        }
        $pos = 2;
        $len = strlen($j);
        while ($pos + 4 < $len && $j[$pos] === "\xFF") {
            $marker = ord($j[$pos + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $segLen = unpack('n', substr($j, $pos + 2, 2))[1];
            if ($marker === 0xE1 && substr($j, $pos + 4, 6) === "Exif\0\0") {
                $tiff = $pos + 10;
                $order = substr($j, $tiff, 2);
                if ($order !== 'II' && $order !== 'MM') {
                    return null;
                }
                $little = $order === 'II';
                $u16 = fn (int $o) => unpack($little ? 'v' : 'n', substr($j, $o, 2))[1];
                $u32 = fn (int $o) => unpack($little ? 'V' : 'N', substr($j, $o, 4))[1];
                $ifd0 = $tiff + $u32($tiff + 4);
                if ($ifd0 + 2 > $len) {
                    return null;
                }
                $n = $u16($ifd0);
                for ($i = 0; $i < min($n, 500); $i++) {
                    $e = $ifd0 + 2 + $i * 12;
                    if ($e + 12 > $len) {
                        break;
                    }
                    if ($u16($e) === 0x8825) {
                        return [$tiff, $little, $u32($e + 8), $e];
                    }
                }

                return null;
            }
            $pos += 2 + $segLen;
        }

        return null;
    }
}
