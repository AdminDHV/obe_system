<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SchoolManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('super_admin', 'web');
        $u = User::factory()->create();
        $u->assignRole('super_admin');

        return $u;
    }

    public function test_super_admin_can_create_and_rename_school(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('manager.schools.store'), [
            'code' => 'DHV', 'name' => 'Trường ĐH Hùng Vương', 'is_active' => true,
        ])->assertRedirect();
        $this->assertDatabaseHas('schools', ['code' => 'DHV', 'name' => 'Trường ĐH Hùng Vương']);

        $school = School::where('code', 'DHV')->first();
        $this->actingAs($admin)->put(route('manager.schools.update', $school), [
            'code' => 'DHV', 'name' => 'ĐH Hùng Vương TP.HCM', 'is_active' => true,
        ])->assertRedirect();
        $this->assertDatabaseHas('schools', ['id' => $school->id, 'name' => 'ĐH Hùng Vương TP.HCM']);
    }

    public function test_two_schools_can_share_no_code_conflict_but_code_unique_per_school(): void
    {
        $admin = $this->admin();
        School::create(['code' => 'DHV', 'name' => 'A']);

        // Mã trường trùng → chặn (schools.code unique global).
        $this->actingAs($admin)->post(route('manager.schools.store'), ['code' => 'DHV', 'name' => 'B'])
            ->assertSessionHasErrors('code');
    }

    public function test_cannot_delete_school_with_data_but_can_delete_empty(): void
    {
        $admin = $this->admin();

        $withData = School::create(['code' => 'S1', 'name' => 'Có dữ liệu']);
        $dept = Department::create(['school_id' => $withData->id, 'code' => 'D1', 'name' => 'Khoa']);
        Program::create(['school_id' => $withData->id, 'department_id' => $dept->id, 'code' => 'P1', 'name' => 'Ngành']);

        $this->actingAs($admin)->delete(route('manager.schools.destroy', $withData))
            ->assertSessionHas('error');
        $this->assertNotSoftDeleted('schools', ['id' => $withData->id]);

        $empty = School::create(['code' => 'S2', 'name' => 'Rỗng']);
        $this->actingAs($admin)->delete(route('manager.schools.destroy', $empty))
            ->assertSessionHas('success');
        $this->assertSoftDeleted('schools', ['id' => $empty->id]);
    }

    public function test_non_super_admin_cannot_access(): void
    {
        Role::findOrCreate('lecturer', 'web');
        $lecturer = User::factory()->create();
        $lecturer->assignRole('lecturer');

        $this->actingAs($lecturer)->get(route('manager.schools.index'))->assertForbidden();
    }
}
