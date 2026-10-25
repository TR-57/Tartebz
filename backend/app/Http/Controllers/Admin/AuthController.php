<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminAuth;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\AuthSessions;
use App\Support\ClientIp;
use App\Support\EmailLayout;
use App\Support\Outbox;
use App\Support\Throttle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Admin sign-in, sign-out, forgotten password, reset and change of own password. */
class AuthController extends Controller
{
    private const WINDOW = 900; // 15 minutes

    // A real bcrypt hash of a random string: verifying against it costs the same as a real account.
    private const DUMMY_HASH = '$2y$12$TR4KszbUfHwcIM1nqybZt.xZf.l.hjnj7t7aiUWdnMDegF5hwABdS';

    public function me(Request $r)
    {
        $a = AdminAuth::resolve($r);

        return $this->ok(['admin' => $a ? ['id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'role' => $a->role] : null]);
    }

    public function login(Request $r)
    {
        $email = strtolower($this->str($r, 'email', 190));
        $password = (string) $r->input('password', '');
        $ip = ClientIp::of($r);

        $busy = new ApiError('too_many', 'Too many attempts. Please wait a few minutes and try again.', 429);
        if (Throttle::tooMany("adm-login-ip:$ip", 20, self::WINDOW)
            || Throttle::tooMany("adm-login-em-ip:$email|$ip", 6, self::WINDOW)
            || Throttle::tooMany("adm-login-em:$email", 40, self::WINDOW)) {
            throw $busy;
        }
        Throttle::hit("adm-login-ip:$ip", self::WINDOW);
        Throttle::hit("adm-login-em:$email", self::WINDOW);

        $admin = $email !== '' ? DB::table('admins')->where('email', $email)->first() : null;
        $valid = Hash::check($password, $admin->password_hash ?? self::DUMMY_HASH);
        if (! $admin || ! $valid || ! $admin->active || $password === '') {
            Throttle::hit("adm-login-em-ip:$email|$ip", self::WINDOW);
            throw new ApiError('bad_credentials', 'Wrong email or password.', 401);
        }
        Throttle::clear("adm-login-em-ip:$email|$ip", self::WINDOW);
        if (Hash::needsRehash($admin->password_hash)) {
            DB::table('admins')->where('id', $admin->id)->update(['password_hash' => Hash::make($password)]);
        }
        DB::table('admins')->where('id', $admin->id)->update(['last_login_at' => now()]);
        $token = AuthSessions::start(AuthSessions::ADMIN, $admin->id, $r);
        Audit::log($r, 'login', 'admin', $admin->id, [], $admin);

        return $this->ok(['admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => $admin->role]])
            ->withCookie(AuthSessions::cookie(AuthSessions::ADMIN, $token));
    }

    public function logout(Request $r)
    {
        AuthSessions::end(AuthSessions::ADMIN, $r);

        return $this->ok()->withCookie(AuthSessions::forgetCookie(AuthSessions::ADMIN));
    }

    public function forgot(Request $r)
    {
        $email = strtolower($this->str($r, 'email', 190));
        $ip = ClientIp::of($r);
        if (! Throttle::attempt("adm-forgot-ip:$ip", 10, 3600) || ! Throttle::attempt("adm-forgot-em:$email", 3, 3600)) {
            throw new ApiError('too_many', 'Too many attempts. Please wait a few minutes and try again.', 429);
        }
        $answer = 'If that email belongs to a staff account, a link to set a new password is on its way. It works for one hour.';
        $subject = 'Set a new password – '.config('store.name').' admin';
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->ok(['message' => $answer]);
        }
        $admin = DB::table('admins')->where('email', $email)->first();
        if (! $admin || ! $admin->active) {
            Outbox::skipped($email, $subject, $admin ? 'Admin password reset: the staff account is disabled.' : 'Admin password reset: no staff account with this email.');

            return $this->ok(['message' => $answer]);
        }
        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($admin, $token) {
            DB::table('admin_tokens')->where('admin_id', $admin->id)->where('kind', 'reset')->whereNull('used_at')->update(['used_at' => now()]);
            DB::table('admin_tokens')->insert([
                'admin_id' => $admin->id, 'kind' => 'reset', 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHour(), 'created_at' => now(),
            ]);
        });
        $url = rtrim(config('store.admin_url'), '/').'/?reset='.$token;
        $body = '<h1 style="font-size:22px;margin:0 0 12px;">Set a new password</h1>'
            .'<p>Hello '.e($admin->name).',</p><p>Someone (hopefully you) asked to reset the password of your '.e(config('store.name')).' admin account. The link works for one hour and only once.</p>'
            .EmailLayout::button('Choose a new password', $url)
            .'<p style="color:#5f636b;font-size:13px;">If you did not ask for this, ignore this email – your password stays the same.</p>';
        Outbox::queue($admin->email, $subject, EmailLayout::wrap('en', 'Set a new password for the admin', $body), ['name' => $admin->name, 'kind' => 'staff']);
        Audit::log($r, 'password_reset_requested', 'admin', $admin->id, [], $admin);

        return $this->ok(['message' => $answer]);
    }

    public function reset(Request $r)
    {
        $ip = ClientIp::of($r);
        if (! Throttle::attempt("adm-reset-ip:$ip", 20, 3600)) {
            throw new ApiError('too_many', 'Too many attempts. Please wait a few minutes and try again.', 429);
        }
        $token = (string) $r->input('token', '');
        $password = (string) $r->input('password', '');
        if (mb_strlen($password) < 10 || mb_strlen($password) > 200) {
            throw ApiError::validation(['password' => 'Use at least 10 characters.']);
        }
        $expired = new ApiError('bad_token', 'This link has expired or was already used. Ask for a new one.', 400);
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw $expired;
        }
        $hash = hash('sha256', $token);
        $row = DB::table('admin_tokens')->where('token_hash', $hash)->where('kind', 'reset')->first();
        // Claim atomically: one link can only ever be used once.
        $claimed = $row ? DB::table('admin_tokens')->where('id', $row->id)->whereNull('used_at')->where('expires_at', '>', now())->update(['used_at' => now()]) : 0;
        if (! $claimed) {
            throw $expired;
        }
        $admin = DB::table('admins')->where('id', $row->admin_id)->where('active', 1)->first();
        if (! $admin) {
            throw $expired;
        }
        DB::table('admins')->where('id', $admin->id)->update(['password_hash' => Hash::make($password), 'updated_at' => now()]);
        AuthSessions::endAll(AuthSessions::ADMIN, $admin->id);
        $t = AuthSessions::start(AuthSessions::ADMIN, $admin->id, $r);
        Audit::log($r, 'password_reset', 'admin', $admin->id, [], $admin);

        return $this->ok(['admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => $admin->role]])
            ->withCookie(AuthSessions::cookie(AuthSessions::ADMIN, $t));
    }

    public function password(Request $r)
    {
        $me = $this->admin($r);
        if (! Throttle::attempt("adm-pw:{$me->id}", 8, self::WINDOW)) {
            throw new ApiError('too_many', 'Too many attempts. Please wait a few minutes and try again.', 429);
        }
        $hash = DB::table('admins')->where('id', $me->id)->value('password_hash');
        if (! Hash::check((string) $r->input('current', ''), $hash)) {
            throw ApiError::validation(['current' => 'That is not your current password.']);
        }
        $new = (string) $r->input('password', '');
        if (mb_strlen($new) < 10 || mb_strlen($new) > 200) {
            throw ApiError::validation(['password' => 'Use at least 10 characters.']);
        }
        DB::table('admins')->where('id', $me->id)->update(['password_hash' => Hash::make($new), 'updated_at' => now()]);
        AuthSessions::endAll(AuthSessions::ADMIN, $me->id);
        $t = AuthSessions::start(AuthSessions::ADMIN, $me->id, $r);
        Audit::log($r, 'password_changed', 'admin', $me->id);

        return $this->ok(['message' => 'Password changed. Other devices have been signed out.'])
            ->withCookie(AuthSessions::cookie(AuthSessions::ADMIN, $t));
    }
}
