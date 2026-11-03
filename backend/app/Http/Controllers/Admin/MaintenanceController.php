<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Maintenance;
use Illuminate\Http\Request;

/** Managers and owners: maintenance mode on / off, and the message customers see. */
class MaintenanceController extends Controller
{
    public function index()
    {
        return $this->ok(['maintenance' => Maintenance::info(), 'preview' => '/api/admin/maintenance/preview']);
    }

    /** {on, message?, messageAr?} */
    public function save(Request $r)
    {
        $on = $r->boolean('on');
        $v = Maintenance::set($on, (string) $r->input('message', ''), (string) $r->input('messageAr', ''), $this->admin($r));
        Audit::log($r, $on ? 'maintenance_on' : 'maintenance_off', 'settings', 'maintenance', ['message' => $v['message']]);
        if (! Maintenance::sync()) {
            throw new ApiError('not_written', 'Saved, but the web folder could not be written, so visitors may still see the shop. Check the folder permissions (store:doctor).', 500);
        }

        return $this->ok(['maintenance' => $v]);
    }

    /** What customers see, with the message as saved. */
    public function preview()
    {
        return response(Maintenance::page(), 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
