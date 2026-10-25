<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\AuthSessions;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Owner only: staff accounts and their roles. */
class StaffController extends Controller
{
    public function index()
    {
        $rows = DB::table('admins')->orderByRaw("FIELD(role,'owner','manager','staff','order_manager','order_assistant')")->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'active', 'order_emails', 'last_login_at', 'created_at']);

        return $this->ok(['staff' => $rows->map(fn ($a) => [
            'id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'role' => $a->role,
            'active' => (bool) $a->active, 'orderEmails' => (bool) $a->order_emails,
            'lastLoginAt' => $a->last_login_at, 'createdAt' => $a->created_at,
        ]), 'roles' => Roles::LABELS]);
    }

    public function store(Request $r)
    {
        return $this->save($r, null);
    }

    public function update(Request $r, int $id)
    {
        if (! DB::table('admins')->where('id', $id)->exists()) {
            throw ApiError::notFound('No such staff account.');
        }

        return $this->save($r, $id);
    }

    private function save(Request $r, ?int $id)
    {
        $me = $this->admin($r);
        $name = $this->str($r, 'name', 120);
        $email = strtolower($this->str($r, 'email', 190));
        $role = $this->str($r, 'role', 20);
        $password = (string) $r->input('password', '');
        $active = $this->bool($r, 'active', true);
        $orderEmails = $this->bool($r, 'orderEmails', true);

        $f = [];
        if (mb_strlen($name) < 2) {
            $f['name'] = 'Enter their name.';
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $f['email'] = 'Enter a valid email address.';
        } elseif (DB::table('admins')->where('email', $email)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            $f['email'] = 'Another staff account already uses this email.';
        }
        if (! in_array($role, Roles::ALL, true)) {
            $f['role'] = 'Choose a role.';
        }
        if ($id === null || $password !== '') {
            if (mb_strlen($password) < 10 || mb_strlen($password) > 200) {
                $f['password'] = 'Use at least 10 characters.';
            }
        }
        if ($id === $me->id && ($role !== 'owner' || ! $active)) {
            $f['role'] = 'You cannot remove your own owner access.';
        }
        if ($f) {
            throw ApiError::validation($f);
        }

        $before = $id ? DB::table('admins')->where('id', $id)->first() : null;
        $row = ['name' => $name, 'email' => $email, 'role' => $role, 'active' => $active, 'order_emails' => $orderEmails, 'updated_at' => now()];
        if ($password !== '') {
            $row['password_hash'] = Hash::make($password);
        }
        if ($id) {
            DB::table('admins')->where('id', $id)->update($row);
        } else {
            $row['created_at'] = now();
            $id = (int) DB::table('admins')->insertGetId($row);
        }
        // Disabling an account or giving it a new password ends all of its sessions.
        if ($before && (($before->active && ! $active) || $password !== '')) {
            AuthSessions::endAll(AuthSessions::ADMIN, $id);
        }
        Audit::log($r, 'staff_saved', 'admin', $id, ['role' => $role, 'active' => $active, 'new' => $before === null]);

        return $this->ok(['id' => $id]);
    }
}
