<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Owner only: the latest 300 actions taken in the admin. */
class ActivityController extends Controller
{
    public function index(Request $r)
    {
        $q = DB::table('audit_log')->orderByDesc('id')->limit(300);
        if ($a = $this->str($r, 'action', 60)) {
            $q->where('action', $a);
        }
        $rows = $q->get()->map(fn ($x) => [
            'id' => $x->id, 'at' => $x->created_at, 'admin' => $x->admin_name, 'adminId' => $x->admin_id,
            'action' => $x->action, 'entity' => $x->entity, 'entityId' => $x->entity_id,
            'details' => $x->details ? json_decode($x->details, true) : null, 'ip' => $x->ip,
        ]);

        return $this->ok(['rows' => $rows]);
    }
}
