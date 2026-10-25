<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    public function test_sign_in_sets_a_hashed_session_and_me_returns_the_admin(): void
    {
        $a = $this->makeAdmin('manager', ['email' => 'm@example.test']);
        $res = $this->postJson('/api/admin/login', ['email' => 'M@Example.test', 'password' => 'Correct-Horse-1']);
        $res->assertOk()->assertJsonPath('admin.role', 'manager');
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'tz_adm');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain(), 'admin cookie must be host-only');
        // Only the SHA-256 of the token is stored.
        $this->assertTrue(DB::table('sessions')->where('id', hash('sha256', $cookie->getValue()))->exists());
        $this->assertFalse(DB::table('sessions')->where('id', $cookie->getValue())->exists());
        $this->withUnencryptedCookie('tz_adm', $cookie->getValue())->getJson('/api/admin/me')->assertJsonPath('admin.id', $a->id);
        $this->assertTrue(DB::table('audit_log')->where('action', 'login')->exists());
    }

    public function test_wrong_password_and_unknown_email_look_the_same(): void
    {
        $this->makeAdmin('owner', ['email' => 'o@example.test']);
        $a = $this->postJson('/api/admin/login', ['email' => 'o@example.test', 'password' => 'nope']);
        $b = $this->postJson('/api/admin/login', ['email' => 'nobody@example.test', 'password' => 'nope']);
        $a->assertStatus(401)->assertJsonPath('error.code', 'bad_credentials');
        $b->assertStatus(401)->assertJsonPath('error.message', $a->json('error.message'));
    }

    public function test_six_wrong_passwords_lock_that_email_from_that_ip_but_not_from_another(): void
    {
        $this->makeAdmin('owner', ['email' => 'o@example.test']);
        for ($i = 0; $i < 6; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->postJson('/api/admin/login', ['email' => 'o@example.test', 'password' => 'wrong'])->assertStatus(401);
        }
        // Even the right password is refused now, from that IP…
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->postJson('/api/admin/login', ['email' => 'o@example.test', 'password' => 'Correct-Horse-1'])
            ->assertStatus(429)->assertJsonPath('error.code', 'too_many');
        // …but a stranger cannot lock the owner out everywhere.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->postJson('/api/admin/login', ['email' => 'o@example.test', 'password' => 'Correct-Horse-1'])->assertOk();
    }

    public function test_disabled_admin_cannot_sign_in(): void
    {
        $this->makeAdmin('owner', ['email' => 'x@example.test', 'active' => 0]);
        $this->postJson('/api/admin/login', ['email' => 'x@example.test', 'password' => 'Correct-Horse-1'])->assertStatus(401);
    }

    public function test_requests_from_another_origin_are_refused(): void
    {
        $this->makeAdmin('owner', ['email' => 'o@example.test']);
        $this->withHeaders(['Origin' => 'https://evil.example'])->postJson('/api/admin/login', ['email' => 'o@example.test', 'password' => 'Correct-Horse-1'])
            ->assertStatus(403)->assertJsonPath('error.code', 'bad_origin');
        // No Origin and no Referer: refused too.
        $this->withHeaders(['Origin' => ''])->postJson('/api/admin/login', [])->assertStatus(403);
        // Same origin via Referer only: allowed through the guard.
        $this->withHeaders(['Origin' => '', 'Referer' => 'http://localhost/admin/'])->postJson('/api/admin/login', ['email' => 'o@example.test', 'password' => 'x'])->assertStatus(401);
    }

    public function test_forgot_password_always_answers_the_same_and_reset_works_once(): void
    {
        $a = $this->makeAdmin('staff', ['email' => 's@example.test']);
        $known = $this->postJson('/api/admin/forgot', ['email' => 's@example.test'])->assertOk()->json('message');
        $unknown = $this->postJson('/api/admin/forgot', ['email' => 'ghost@example.test'])->assertOk()->json('message');
        $this->assertSame($known, $unknown);
        $this->assertTrue(DB::table('mail_outbox')->where('to_email', 'ghost@example.test')->where('status', 'skipped')->exists());

        $mail = DB::table('mail_outbox')->where('to_email', 's@example.test')->first();
        $this->assertNotNull($mail);
        preg_match('/\?reset=([a-f0-9]{64})/', $mail->html, $m);
        $token = $m[1];
        $this->assertFalse(DB::table('admin_tokens')->where('token_hash', $token)->exists(), 'raw token must not be stored');

        $old = $this->signOut()->actingAsAdmin($a);
        $this->signOut();
        $this->postJson('/api/admin/reset', ['token' => $token, 'password' => 'short'])->assertStatus(422)->assertJsonPath('error.fields.password', 'Use at least 10 characters.');
        $this->postJson('/api/admin/reset', ['token' => $token, 'password' => 'A-new-password-9'])->assertOk();
        // Every earlier session ended; only the new one remains.
        $this->assertSame(1, DB::table('sessions')->where('subject_type', 'admin')->where('subject_id', $a->id)->count());
        $this->postJson('/api/admin/reset', ['token' => $token, 'password' => 'Another-pass-99'])->assertStatus(400)->assertJsonPath('error.code', 'bad_token');
        $this->postJson('/api/admin/login', ['email' => 's@example.test', 'password' => 'A-new-password-9'])->assertOk();
    }

    public function test_change_password_needs_the_current_one_and_signs_out_elsewhere(): void
    {
        $a = $this->makeAdmin('order_assistant');
        $this->actingAsAdmin($a);
        $this->postJson('/api/admin/password', ['current' => 'bad', 'password' => 'A-new-password-9'])
            ->assertStatus(422)->assertJsonPath('error.fields.current', 'That is not your current password.');
        $this->postJson('/api/admin/password', ['current' => 'Correct-Horse-1', 'password' => 'A-new-password-9'])->assertOk();
        $this->assertSame(1, DB::table('sessions')->where('subject_id', $a->id)->count());
    }
}
