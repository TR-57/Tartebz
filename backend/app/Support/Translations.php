<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Built-in wording (I18nCatalog) merged with admin edits (translations table), and the missing-text reports
 * the Arabic storefront and the admin send in.
 */
class Translations
{
    public const MAX_REPORTED = 5000;

    /** Every entry of a scope with its effective English/Arabic and flags. Keyed by hash. */
    public static function entries(string $scope): array
    {
        $built = I18nCatalog::entries($scope);
        $rows = DB::table('translations')->where('scope', $scope)->get()->keyBy('hash');
        $out = [];
        foreach ($built as $hash => $b) {
            $out[$hash] = self::entry($hash, $b, $rows[$hash] ?? null);
        }
        foreach ($rows as $hash => $row) {
            if (! isset($out[$hash])) {
                $out[$hash] = self::entry($hash, null, $row);
            }
        }

        return $out;
    }

    private static function entry(string $hash, ?array $b, ?object $row): array
    {
        $enBuilt = $b['en'] ?? ($row->source ?? '');
        $arBuilt = $b['ar'] ?? '';
        $en = ($row && $row->en !== null) ? $row->en : $enBuilt;
        $ar = ($row && $row->ar !== null) ? $row->ar : $arBuilt;
        $keep = $row ? (bool) $row->keep : false;

        return [
            'hash' => $hash,
            'source' => $b['source'] ?? $row->source,
            'key' => $b['key'] ?? null,
            'en' => $en,
            'enBuilt' => $enBuilt,
            'ar' => $ar,
            'arBuilt' => $arBuilt,
            'where' => $b['where'] ?? '',
            'builtIn' => $b !== null,
            'keep' => $keep,
            'reported' => $row ? (bool) $row->reported : false,
            'seen' => $row ? (int) $row->seen : 0,
            'page' => $row->page ?? '',
            'changed' => $row !== null && ($row->ar !== null || $row->en !== null),
            'missing' => ! $keep && trim(strip_tags((string) $ar)) === '',
            'updatedAt' => $row->updated_at ?? null,
        ];
    }

    /**
     * Only the edits, for the runtime dictionaries:
     *   { defaultLang, keys: {key: {ar?, en?}}, phrases: {english: {ar?, en?}}, keep: [english…] }
     */
    public static function overrides(string $scope): array
    {
        return Cache::remember("i18n:overrides:$scope", 120, function () use ($scope) {
            $built = I18nCatalog::entries($scope);
            $out = ['defaultLang' => Settings::get('i18n')['defaultLang'] ?? 'ar', 'keys' => (object) [], 'phrases' => (object) [], 'keep' => []];
            $keys = [];
            $phrases = [];
            foreach (DB::table('translations')->where('scope', $scope)->get() as $row) {
                $o = array_filter(['ar' => $row->ar, 'en' => $row->en], fn ($v) => $v !== null && $v !== '');
                $b = $built[$row->hash] ?? null;
                if ($row->keep) {
                    $out['keep'][] = $b['en'] ?? $row->source;
                }
                if (! $o) {
                    continue;
                }
                if ($b && $b['key']) {
                    $keys[$b['key']] = $o;
                } else {
                    $phrases[$b['en'] ?? $row->source] = $o;
                }
            }
            $out['keys'] = (object) $keys;
            $out['phrases'] = (object) $phrases;

            return $out;
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget('i18n:overrides:site');
        Cache::forget('i18n:overrides:admin');
    }

    /**
     * Records texts a page showed without Arabic. Body: {"page": "/shop", "strings": ["…", …]} or a plain JSON list.
     * Only unknown texts or texts still without Arabic are stored; a repeat raises `seen`.
     */
    public static function report(string $scope, Request $r, string $defaultPage = ''): int
    {
        $body = (string) $r->getContent();
        if ($body === '' || strlen($body) > 32768) {
            return 0;
        }
        $data = json_decode($body, true);
        if (! is_array($data)) {
            return 0;
        }
        $strings = array_is_list($data) ? $data : ($data['strings'] ?? []);
        $page = mb_substr(is_string($data['page'] ?? null) ? $data['page'] : $defaultPage, 0, 255);
        if (! is_array($strings)) {
            return 0;
        }
        $built = I18nCatalog::entries($scope);
        $n = 0;
        foreach (array_slice($strings, 0, 60) as $s) {
            if (! is_string($s)) {
                continue;
            }
            $s = trim(preg_replace('/\s+/u', ' ', $s));
            if (mb_strlen($s) > 500 || preg_match_all('/\p{L}/u', $s) < 3 || preg_match('#https?://|www\.|\S+@\S+\.\S+#i', $s) || preg_match('/\p{Arabic}/u', $s)) {
                continue;
            }
            $hash = I18nCatalog::hash($s);
            if (isset($built[$hash]) && trim($built[$hash]['ar']) !== '') {
                continue;
            }
            $row = DB::table('translations')->where('scope', $scope)->where('hash', $hash)->first();
            if ($row) {
                if (($row->ar ?? '') === '' && ! $row->keep) {
                    DB::table('translations')->where('id', $row->id)->update(['seen' => DB::raw('seen + 1'), 'reported' => true, 'page' => $page ?: $row->page, 'updated_at' => now()]);
                    $n++;
                }

                continue;
            }
            if (DB::table('translations')->where('reported', 1)->count() >= self::MAX_REPORTED) {
                break;
            }
            DB::table('translations')->insertOrIgnore([
                'scope' => $scope, 'hash' => $hash, 'source' => $s, 'reported' => true, 'seen' => 1, 'page' => $page,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $n++;
        }

        return $n;
    }
}
