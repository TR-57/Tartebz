<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffTest extends TestCase
{
    public function test_owner_adds_a_person_with_field_errors_keyed_by_field(): void
    {
        $owner = $this->makeAdmin('owner');
        $this->actingAsAdmin($owner);
        $this->postJson('/api/admin/staff', ['name' => 'A', 'email' => 'bad', 'role' => 'boss', 'password' => 'x'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['name', 'email', 'role', 'password']]]);
        $id = $this->postJson('/api/admin/staff', ['name' => 'Ali', 'email' => 'ali@example.test', 'role' => 'manager', 'password' => 'Long-enough-1'])->assertOk()->json('id');
        $this->assertSame('manager', DB::table('admins')->where('id', $id)->value('role'));
        $this->postJson('/api/admin/staff', ['name' => 'Ali 2', 'email' => 'ALI@example.test', 'role' => 'staff', 'password' => 'Long-enough-1'])
            ->assertStatus(422)->assertJsonPath('error.fields.email', 'Another staff account already uses this email.');
        $this->assertTrue(DB::table('audit_log')->where('action', 'staff_saved')->exists());
    }

    public function test_owner_cannot_remove_their_own_owner_access(): void
    {
        $owner = $this->makeAdmin('owner');
        $this->actingAsAdmin($owner);
        $this->putJson('/api/admin/staff/'.$owner->id, ['name' => 'Me', 'email' => $owner->email, 'role' => 'manager', 'active' => true])
            ->assertStatus(422)->assertJsonPath('error.fields.role', 'You cannot remove your own owner access.');
    }

    public function test_disabling_a_person_ends_their_sessions(): void
    {
        $owner = $this->makeAdmin('owner');
        $staff = $this->makeAdmin('staff');
        $this->actingAsAdmin($staff);
        $this->getJson('/api/admin/dashboard')->assertOk();
        $this->signOut()->actingAsAdmin($owner)
            ->putJson('/api/admin/staff/'.$staff->id, ['name' => $staff->name, 'email' => $staff->email, 'role' => 'staff', 'active' => false])->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('subject_id', $staff->id)->where('subject_type', 'admin')->count());
    }
}
