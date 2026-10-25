<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\HtmlSanitizer;
use App\Support\I18nCatalog;
use App\Support\Settings;
use App\Support\Translations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin > Translations: every built-in text of the website and the admin, with the Arabic, edits made here,
 * texts the Arabic site reported as missing, and the default language for new visitors.
 */
class TranslationsController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $r)
    {
        $scope = $r->query('scope') === 'admin' ? 'admin' : 'site';
        $filter = in_array($r->query('filter'), ['missing', 'all', 'changed', 'keep'], true) ? $r->query('filter') : 'missing';
        $q = mb_strtolower($this->str($r, 'q', 120));
        $page = max(1, (int) $r->query('page', 1));

        $entries = Translations::entries($scope);
        $counts = ['missing' => 0, 'all' => count($entries), 'changed' => 0, 'keep' => 0];
        foreach ($entries as $e) {
            $counts['missing'] += $e['missing'] ? 1 : 0;
            $counts['changed'] += $e['changed'] ? 1 : 0;
            $counts['keep'] += $e['keep'] ? 1 : 0;
        }
        $list = array_filter($entries, function ($e) use ($filter, $q) {
            if ($filter === 'missing' && ! $e['missing']) {
                return false;
            }
            if ($filter === 'changed' && ! $e['changed']) {
                return false;
            }
            if ($filter === 'keep' && ! $e['keep']) {
                return false;
            }
            if ($q !== '') {
                $hay = mb_strtolower($e['source'].' '.$e['en'].' '.$e['ar'].' '.($e['enBuilt'] ?? ''));

                return str_contains($hay, $q);
            }

            return true;
        });
        usort($list, function ($a, $b) {
            if ($a['missing'] !== $b['missing']) {
                return $a['missing'] ? -1 : 1;
            }
            if ($a['missing'] && $a['seen'] !== $b['seen']) {
                return $b['seen'] <=> $a['seen'];
            }

            return strcasecmp($a['en'], $b['en']);
        });
        $total = count($list);
        $rows = array_slice($list, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        // Counts for the other tab, for the badge.
        $other = $scope === 'site' ? 'admin' : 'site';
        $otherMissing = count(array_filter(Translations::entries($other), fn ($e) => $e['missing']));

        return $this->ok([
            'scope' => $scope, 'filter' => $filter, 'page' => $page, 'perPage' => self::PER_PAGE, 'total' => $total,
            'counts' => $counts, 'missing' => [$scope => $counts['missing'], $other => $otherMissing],
            'rows' => array_values($rows),
            'defaultLang' => Settings::get('i18n')['defaultLang'] ?? 'ar',
        ]);
    }

    /** Saves one entry: Arabic, a new English wording, or "no translation needed". Saves on blur in the screen. */
    public function save(Request $r)
    {
        $scope = $r->input('scope') === 'admin' ? 'admin' : 'site';
        $source = (string) $r->input('source', '');
        if ($source === '' || mb_strlen($source) > 2000) {
            throw ApiError::validation(['source' => 'Pick a text to translate.']);
        }
        $hash = I18nCatalog::hash($source);
        $built = I18nCatalog::entries($scope)[$hash] ?? null;
        $row = DB::table('translations')->where('scope', $scope)->where('hash', $hash)->first();
        if (! $built && ! $row) {
            throw ApiError::notFound('This text is not in the list any more. Reload the page.');
        }
        $upd = [];
        if ($r->has('ar')) {
            $ar = HtmlSanitizer::inline(mb_substr(trim((string) $r->input('ar', '')), 0, 4000));
            $upd['ar'] = ($ar === '' || ($built && $ar === $built['ar'])) ? null : $ar;
        }
        if ($r->has('en')) {
            $en = HtmlSanitizer::inline(mb_substr(trim((string) $r->input('en', '')), 0, 4000));
            $upd['en'] = ($en === '' || ($built && $en === $built['en'])) ? null : $en;
        }
        if ($r->has('keep')) {
            $upd['keep'] = $this->bool($r, 'keep');
        }
        $me = $this->admin($r);
        if ($row) {
            DB::table('translations')->where('id', $row->id)->update($upd + ['updated_by' => $me->id, 'updated_at' => now()]);
        } else {
            DB::table('translations')->insert($upd + [
                'scope' => $scope, 'hash' => $hash, 'source' => $source, 'updated_by' => $me->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Translations::forgetCache();
        Audit::log($r, 'translation.save', $scope, substr($hash, 0, 12), ['source' => mb_substr($source, 0, 80)] + array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 80) : $v, $upd));

        return $this->ok(['row' => Translations::entries($scope)[$hash] ?? null]);
    }

    /** "Back to original" drops the edits. "Remove from the list" (remove=1) also forgets a reported text. */
    public function reset(Request $r)
    {
        $scope = $r->input('scope') === 'admin' ? 'admin' : 'site';
        $source = (string) $r->input('source', '');
        $hash = I18nCatalog::hash($source);
        $row = DB::table('translations')->where('scope', $scope)->where('hash', $hash)->first();
        if (! $row) {
            return $this->ok();
        }
        $built = isset(I18nCatalog::entries($scope)[$hash]);
        if ($this->bool($r, 'remove') || ! $row->reported || $built) {
            DB::table('translations')->where('id', $row->id)->delete();
        } else {
            DB::table('translations')->where('id', $row->id)->update(['ar' => null, 'en' => null, 'keep' => false, 'updated_at' => now()]);
        }
        Translations::forgetCache();
        Audit::log($r, 'translation.reset', $scope, substr($hash, 0, 12), ['source' => mb_substr($source, 0, 80), 'remove' => $this->bool($r, 'remove')]);

        return $this->ok();
    }

    public function settings(Request $r)
    {
        if ($r->isMethod('get')) {
            return $this->ok(['defaultLang' => Settings::get('i18n')['defaultLang']]);
        }
        $lang = $r->input('defaultLang');
        if (! in_array($lang, ['en', 'ar'], true)) {
            throw ApiError::validation(['defaultLang' => 'Choose English or Arabic.']);
        }
        Settings::set('i18n', ['defaultLang' => $lang] + (Settings::raw('i18n') ?? []));
        Translations::forgetCache();
        Audit::log($r, 'translation.settings', 'i18n', '', ['defaultLang' => $lang]);

        return $this->ok(['defaultLang' => $lang]);
    }

    /** Signed-in staff report admin text that has no Arabic yet. */
    public function reportAdmin(Request $r)
    {
        Translations::report('admin', $r, 'admin');

        return response()->noContent();
    }

    /** The owner's overrides for the admin translator, as a script. */
    public function adminScript()
    {
        $js = 'window.ADMIN_I18N_OVERRIDES='.json_encode(Translations::overrides('admin'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).';';

        return response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
