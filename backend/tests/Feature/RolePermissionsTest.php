<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Phase 1 acceptance: a role below a route's minimum gets 403 on EVERY admin endpoint, and nobody signed out gets in.
 * Walks the real route table, so a new route is covered the moment it is added – and the test fails if an
 * admin route is added without a role check.
 */
class RolePermissionsTest extends TestCase
{
    /** Admin routes that do not need sign-in. */
    private const PUBLIC = ['api/admin/me', 'api/admin/login', 'api/admin/logout', 'api/admin/forgot', 'api/admin/reset', 'api/admin/i18n.js'];

    /** Signed-in routes open to every role (own password, reporting untranslated admin text). */
    private const ANY_SIGNED_IN = ['api/admin/password', 'api/admin/i18n/missing'];

    private const LADDER = ['staff' => 1, 'manager' => 2, 'owner' => 3];

    private function adminRoutes(): array
    {
        return array_values(array_filter(RouteFacade::getRoutes()->getRoutes(), fn (Route $r) => str_starts_with($r->uri(), 'api/admin/')));
    }

    /** @return array{0: string, 1: array<string>} [kind, roles] where kind is ladder|any|public|signedin */
    private function requirement(Route $r): array
    {
        if (in_array($r->uri(), self::PUBLIC, true)) {
            return ['public', []];
        }
        foreach ($r->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'role:')) {
                $roles = explode(',', substr($m, 5));
                $ladder = count($roles) === 1 && isset(self::LADDER[$roles[0]]);

                return [$ladder ? 'ladder' : 'any', $roles];
            }
        }

        return ['signedin', []];
    }

    public function test_every_admin_route_declares_its_access(): void
    {
        foreach ($this->adminRoutes() as $r) {
            [$kind] = $this->requirement($r);
            $mw = $r->gatherMiddleware();
            if ($kind !== 'public') {
                $this->assertContains('admin', $mw, $r->uri().' must require sign-in');
            }
            if ($kind === 'signedin') {
                $this->assertContains($r->uri(), self::ANY_SIGNED_IN, $r->uri().' has no role check. Add role:… middleware or list it as open to every signed-in role.');
            }
        }
    }

    public function test_signed_out_requests_get_401(): void
    {
        foreach ($this->adminRoutes() as $r) {
            [$kind] = $this->requirement($r);
            if ($kind === 'public') {
                continue;
            }
            foreach (array_diff($r->methods(), ['HEAD']) as $method) {
                $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $r->uri());
                $this->json($method, $uri, [])->assertStatus(401);
            }
        }
    }

    public function test_roles_below_the_minimum_get_403_and_allowed_roles_get_past_the_check(): void
    {
        $roles = ['staff', 'manager', 'owner', 'order_manager', 'order_assistant'];
        $admins = [];
        foreach ($roles as $role) {
            $admins[$role] = $this->makeAdmin($role);
        }
        $checked = 0;
        foreach ($this->adminRoutes() as $r) {
            [$kind, $need] = $this->requirement($r);
            if ($kind === 'public') {
                continue;
            }
            foreach ($roles as $role) {
                $allowed = match ($kind) {
                    'signedin' => true,
                    'ladder' => (self::LADDER[$role] ?? 0) >= self::LADDER[$need[0]],
                    'any' => (bool) array_filter($need, fn ($n) => $n === $role
                        || ($n === 'managers' && in_array($role, ['owner', 'manager'], true))
                        || (str_ends_with($n, '+') && (self::LADDER[$role] ?? 0) >= (self::LADDER[substr($n, 0, -1)] ?? 99))),
                };
                foreach (array_diff($r->methods(), ['HEAD']) as $method) {
                    $uri = '/'.preg_replace('/\{[^}]+\}/', '999999', $r->uri());
                    $res = $this->signOut()->actingAsAdmin($admins[$role])->json($method, $uri, []);
                    if ($allowed) {
                        $this->assertNotContains($res->status(), [401, 403], "$role should pass the role check on $method $uri (got {$res->status()})");
                    } else {
                        $res->assertStatus(403);
                        $this->assertSame('forbidden', $res->json('error.code'), "$method $uri as $role");
                    }
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(20, $checked);
    }
}
