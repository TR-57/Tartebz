<?php

namespace Tests;

use App\Support\AuthSessions;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::flushMemo();
        // Every state-changing API call must come from our own origin.
        $this->withHeaders(['Origin' => 'http://localhost', 'Accept' => 'application/json']);
        $this->withCredentials(); // JSON test requests send cookies only with this
        // Uploaded files and resized copies go to a throw-away folder, never the real uploads/.
        $this->filesDir = sys_get_temp_dir().'/tz-test-'.getmypid().'-'.bin2hex(random_bytes(3));
        config(['store.upload_dir' => $this->filesDir.'/uploads', 'store.thumb_dir' => $this->filesDir.'/img']);
    }

    protected string $filesDir = '';

    protected function tearDown(): void
    {
        if ($this->filesDir && is_dir($this->filesDir)) {
            exec('rm -rf '.escapeshellarg($this->filesDir));
        }
        parent::tearDown();
    }

    protected function makeAdmin(string $role = 'owner', array $over = []): object
    {
        $id = DB::table('admins')->insertGetId($over + [
            'name' => ucfirst($role).' Person', 'email' => $role.uniqid().'@example.test', 'role' => $role,
            'active' => 1, 'password_hash' => Hash::make('Correct-Horse-1'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('admins')->find($id);
    }

    /** Signs the admin in for the following requests (raw cookie, like the browser sends it). */
    protected function actingAsAdmin(object $admin): static
    {
        $token = AuthSessions::start(AuthSessions::ADMIN, $admin->id, Request::create('/'));
        $this->withUnencryptedCookie(AuthSessions::cookieName(AuthSessions::ADMIN), $token);

        return $this;
    }

    protected function signOut(): static
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this;
    }
}
