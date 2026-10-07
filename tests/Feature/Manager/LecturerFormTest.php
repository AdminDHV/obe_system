<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Department;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Form "Thêm giảng viên" từng là ngõ cụt im lặng: backend bắt buộc `position` + `phone` nhưng form
 * KHÔNG đánh dấu 2 ô đó bắt buộc, mà cũng KHÔNG hiển thị lỗi 422 → bấm Lưu thì dialog đứng im.
 * Kèm theo, ô "Trạng thái" gửi lên nhưng thiếu trong rule → bị bỏ im lặng.
 */
class LecturerFormTest extends TestCase
{
    use RefreshDatabase;

    private function setUpSchool(): array
    {
        $school = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'D1', 'name' => 'Khoa 1']);

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('lecturer', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return [$school, $dept, $admin];
    }

    public function test_lecturer_can_be_created_without_position_and_phone(): void
    {
        [$school, $dept, $admin] = $this->setUpSchool();

        $this->actingAs($admin)
            ->post(route('manager.lecturers.store') . '?school_id=' . $school->id, [
                'department_id' => $dept->id,
                'staff_code' => 'GV001',
                'full_name' => 'Nguyễn Văn A',
                'email' => 'gv001@dhv.edu.vn',
                'password' => 'MatKhauManh123',
                'employment_status' => 'part_time',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $user = User::where('staff_code', 'GV001')->firstOrFail();
        $this->assertNull($user->position);
        $this->assertNull($user->phone);
        // Ô "Trạng thái" phải được LƯU (trước đây thiếu trong rule → rơi mất, luôn về mặc định).
        $this->assertSame('part_time', $user->employment_status);
        $this->assertTrue($user->hasRole('lecturer'));
    }

    public function test_validation_errors_are_returned_so_the_form_can_show_them(): void
    {
        [$school, $dept, $admin] = $this->setUpSchool();

        // Mã GV nay unique THEO TRƯỜNG ⇒ muốn trùng thì tài khoản cũ phải ở CÙNG trường.
        User::factory()->create([
            'staff_code' => 'GV001', 'email' => 'trung@dhv.edu.vn', 'school_id' => $school->id,
        ]);

        $this->actingAs($admin)
            ->post(route('manager.lecturers.store') . '?school_id=' . $school->id, [
                'department_id' => $dept->id,
                'staff_code' => 'GV001',                 // trùng
                'full_name' => 'Nguyễn Văn B',
                'email' => 'trung@dhv.edu.vn',           // trùng
                'password' => 'ngan',                    // < 8 ký tự
            ])
            ->assertSessionHasErrors(['staff_code', 'email', 'password']);
    }
}
