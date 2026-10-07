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

/**
 * Nút "Xem" ở danh sách Khoa phải mở đúng khoa được bấm.
 *
 * Bản cũ: route `/manager/department` KHÔNG nhận tham số, còn hàm show() thì hiển thị khoa CỦA
 * CHÍNH người đang đăng nhập (tàn dư của portal tự phục vụ giảng viên đã gỡ). Danh sách vẫn
 * truyền uuid vào, nên bấm khoa nào cũng ra 404 "Bạn chưa được phân công khoa/bộ môn" — người vận
 * hành đâu có thuộc khoa nào.
 */
class DepartmentShowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(School $school): User
    {
        Role::findOrCreate('super_admin', 'web');
        $u = User::factory()->create(['school_id' => $school->id]);
        $u->assignRole('super_admin');

        return $u;
    }

    public function test_mo_dung_khoa_duoc_bam_du_nguoi_dung_khong_thuoc_khoa_nao(): void
    {
        $school = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $khoa = Department::create(['school_id' => $school->id, 'code' => 'CNTT', 'name' => 'Công nghệ thông tin']);
        Program::create([
            'school_id' => $school->id, 'department_id' => $khoa->id, 'code' => 'P1', 'name' => 'Ngành 1',
        ]);

        $admin = $this->admin($school);
        $this->assertNull($admin->department_id, 'Người vận hành cố ý KHÔNG thuộc khoa nào');

        $this->actingAs($admin)
            ->get(route('manager.departments.show', $khoa->uuid) . '?school_id=' . $school->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Manager/Department/Show')
                ->where('department.code', 'CNTT')
                ->has('programs', 1));
    }

    /** Khoa của trường khác thì không mở được — đổi uuid trên URL cũng vậy. */
    public function test_khong_mo_duoc_khoa_cua_truong_khac(): void
    {
        $a = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $b = School::create(['code' => 'S2', 'name' => 'Trường 2']);
        $khoaB = Department::create(['school_id' => $b->id, 'code' => 'CNTT', 'name' => 'CNTT của B']);

        $this->actingAs($this->admin($a))
            ->get(route('manager.departments.show', $khoaB->uuid) . '?school_id=' . $a->id)
            ->assertNotFound();
    }
}
