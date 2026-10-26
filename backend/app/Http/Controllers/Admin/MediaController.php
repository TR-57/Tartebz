<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Media;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Media library: browse, upload, edit details (staff); replace and delete (managers). */
class MediaController extends Controller
{
    public function index(Request $r)
    {
        $per = min(120, max(12, (int) $r->query('per', 48)));
        $page = max(1, (int) $r->query('page', 1));
        $filter = $r->query('filter', 'all');
        $q = DB::table('media');
        if ($term = $this->str($r, 'q', 100)) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('original_name', 'like', $like)
                ->orWhere('path', 'like', $like)->orWhere('alt_en', 'like', $like)->orWhere('alt_ar', 'like', $like));
        }
        if ($filter === 'images') {
            $q->where('kind', 'image');
        } elseif ($filter === 'documents') {
            $q->where('kind', 'document');
        } elseif ($kind = $r->query('kind')) {
            $q->where('kind', $kind === 'document' ? 'document' : 'image');
        }
        $counts = [
            'all' => DB::table('media')->count(),
            'images' => DB::table('media')->where('kind', 'image')->count(),
            'documents' => DB::table('media')->where('kind', 'document')->count(),
        ];
        $hay = Media::usedHaystack();
        $unusedIds = DB::table('media')->get(['id', 'path'])->filter(fn ($m) => ! str_contains($hay, $m->path))->pluck('id')->all();
        $counts['unused'] = count($unusedIds);
        if ($filter === 'unused') {
            $q->whereIn('id', $unusedIds ?: [0]);
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * $per)->limit($per)->get();

        return $this->ok([
            'media' => $rows->map(fn ($m) => Media::present($m) + ['used' => ! in_array($m->id, $unusedIds, true)])->values(),
            'total' => $total, 'page' => $page, 'per' => $per, 'counts' => $counts,
        ]);
    }

    public function show(int $id)
    {
        $m = $this->find($id);

        return $this->ok(['media' => Media::present($m), 'usage' => Media::usage($m->path)]);
    }

    /** POST /media  files[] – one or many. Each failure is reported per file. */
    public function store(Request $r)
    {
        $files = $r->file('files') ?? ($r->file('file') ? [$r->file('file')] : []);
        $files = is_array($files) ? $files : [$files];
        if (! $files) {
            throw ApiError::validation(['files' => 'Choose a file to upload.']);
        }
        $done = [];
        $errors = [];
        foreach ($files as $f) {
            try {
                $done[] = Media::present($this->ingest($f, 'library', $r));
            } catch (ApiError $e) {
                $errors[] = ['name' => $f->getClientOriginalName(), 'message' => $e->fields['file'] ?? $e->getMessage()];
            }
        }
        if ($done) {
            Audit::log($r, 'media_uploaded', 'media', $done[0]['id'], ['files' => array_column($done, 'path')]);
        }

        return $this->ok(['media' => $done, 'errors' => $errors], $done ? 200 : 422);
    }

    /** POST /uploads?kind=product|content|slide|logo|brand – editor uploads (managers). Pictures only. */
    public function upload(Request $r)
    {
        $kind = in_array($r->query('kind'), ['product', 'content', 'slide', 'logo', 'brand'], true) ? $r->query('kind') : 'content';
        $f = $r->file('file');
        if (! $f) {
            throw ApiError::validation(['file' => 'Choose a photo to upload (JPG, PNG or WebP, up to 8 MB).']);
        }
        $m = $this->ingest($f, $kind, $r, false);
        Audit::log($r, 'upload', 'media', $m->id, ['kind' => $kind, 'path' => $m->path]);

        return $this->ok(['url' => Media::url($m->path, (int) $m->version), 'media' => Media::present($m)]);
    }

    public function update(Request $r, int $id)
    {
        $m = $this->find($id);
        $f = [];
        $upd = [];
        foreach (['title' => 190, 'alt_en' => 300, 'alt_ar' => 300] as $col => $max) {
            $key = ['title' => 'title', 'alt_en' => 'altEn', 'alt_ar' => 'altAr'][$col];
            if ($r->has($key)) {
                $v = trim((string) $r->input($key));
                if (mb_strlen($v) > $max) {
                    $f[$key] = "Keep this under {$max} characters.";
                }
                $upd[$col] = $v;
            }
        }
        if ($f) {
            throw ApiError::validation($f);
        }
        if ($upd) {
            DB::table('media')->where('id', $id)->update($upd + ['updated_at' => now()]);
            Audit::log($r, 'media_updated', 'media', $id, array_keys($upd));
        }

        return $this->ok(['media' => Media::present(DB::table('media')->find($id))]);
    }

    public function replace(Request $r, int $id)
    {
        $m = $this->find($id);
        $f = $r->file('file');
        if (! $f || ! $f->isValid()) {
            throw ApiError::validation(['file' => 'Choose the new file.']);
        }
        $res = Media::replace($m, (string) file_get_contents($f->getRealPath()), $f->getClientOriginalName());
        Audit::log($r, 'media_replaced', 'media', $id, ['path' => $m->path, 'linksUpdated' => $res['linksUpdated']]);

        return $this->ok(['media' => Media::present($res['media']), 'linksUpdated' => $res['linksUpdated']]);
    }

    public function destroy(Request $r, int $id)
    {
        $m = $this->find($id);
        $usage = Media::usage($m->path);
        if ($usage && ! $this->bool($r, 'force')) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'in_use', 'message' => 'This file is used in '.count($usage).' places. Remove it there first, or delete it anyway.',
            ], 'usage' => $usage], 409);
        }
        Media::delete($m);
        Audit::log($r, 'media_deleted', 'media', $id, ['path' => $m->path, 'forced' => (bool) $usage]);

        return $this->ok();
    }

    /** {ids:[…]} or {unused:true}. Files in use are always skipped. */
    public function bulkDelete(Request $r)
    {
        $hay = Media::usedHaystack();
        $q = DB::table('media');
        if ($this->bool($r, 'unused')) {
            $rows = $q->get();
        } else {
            $ids = array_slice(array_map('intval', (array) $r->input('ids', [])), 0, 5000);
            if (! $ids) {
                throw ApiError::validation(['ids' => 'Select at least one file.']);
            }
            $rows = $q->whereIn('id', $ids)->get();
        }
        $deleted = $kept = 0;
        $paths = [];
        foreach ($rows as $m) {
            if (str_contains($hay, $m->path)) {
                $kept++;

                continue;
            }
            Media::delete($m);
            $paths[] = $m->path;
            $deleted++;
        }
        Audit::log($r, 'media_deleted_bulk', 'media', '', ['deleted' => $deleted, 'kept' => $kept, 'paths' => array_slice($paths, 0, 200)]);

        return $this->ok(['deleted' => $deleted, 'kept' => $kept]);
    }

    private function find(int $id): object
    {
        return DB::table('media')->find($id) ?? throw ApiError::notFound('That file is not in the media library any more.');
    }

    private function ingest(UploadedFile $f, string $use, Request $r, bool $allowPdf = true): object
    {
        if (! $f->isValid()) {
            throw ApiError::validation(['file' => $f->getError() === UPLOAD_ERR_INI_SIZE || $f->getError() === UPLOAD_ERR_FORM_SIZE ? 'That file is over 8 MB.' : 'The upload did not finish. Try again.']);
        }

        return Media::store((string) file_get_contents($f->getRealPath()), $f->getClientOriginalName(), $use, $this->admin($r)->id, null, $allowPdf);
    }
}
