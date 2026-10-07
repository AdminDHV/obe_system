<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Department;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cách ly CỨNG per-user: super_admin = operator toàn cục (đổi trường tự do);
 * non-super_admin bị KẸP vào users.school_id — không forge ?school_id/cookie/payload sang trường khác.
 */
class PerUserSchoolIsolationTest extends TestCase
{
    use RefreshDatabase;

    private School $a;
    private School $b;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'lecturer', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        School::query()->forceDelete(); // bỏ trường DEFAULT của migration
        $this->a = School::create(['code' => 'AAA', 'name' => 'A School', 'is_active' => true]);
        $this->b = School::create(['code' => 'BBB', 'name' => 'B School', 'is_active' => true]);
    }

    private function lecturer(?int $schoolId): User
    {
        $u = User::factory()->create(['is_active' => true, 'school_id' => $schoolId]);
        $u->assignRole('lecturer');

        return $u;
    }

    private function admin(): User
    {
        $u = User::factory()->create(['is_active' => true, 'school_id' => null]); // global operator
        $u->assignRole('super_admin');

        return $u;
    }

    public function test_lecturer_is_clamped_ignoring_query_and_cookie(): void
    {
        $lect = $this->lecturer($this->a->id);

        // Forge cả ?school_id=B lẫn cookie=B → vẫn kẹp về trường A của giảng viên.
        $this->actingAs($lect)
            ->withUnencryptedCookie('obe_working_school', (string) $this->b->id)
            ->get(route('manager.dashboard.index', ['school_id' => $this->b->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('workingSchoolId', $this->a->id)
                ->has('workingSchools', 1) // switcher chỉ thấy trường của mình
                ->where('workingSchools.0.id', $this->a->id));
    }

    public function test_lecturer_index_shows_only_own_school_even_when_forging_school_id(): void
    {
        // Giảng viên giờ chỉ vào được trang lớp học phần, nên tính chất "không forge được
        // school_id" phải kiểm ở đó. Cố ý cho CÙNG một giảng viên dạy lớp ở cả hai trường —
        // nếu chỉ kẹp theo lecturer_id mà quên kẹp theo trường thì lớp B sẽ lọt.
        $lect = $this->lecturer($this->a->id);
        $this->taoLop($this->a->id, 'LOP-A', $lect);
        $this->taoLop($this->b->id, 'LOP-B', $lect);

        $this->actingAs($lect)->get(route('manager.classes.index', ['school_id' => $this->b->id]))
            ->assertInertia(fn (Assert $p) => $p->has('classes.data', 1)
                ->where('classes.data.0.class_code', 'LOP-A'));
    }

    public function test_lecturer_without_school_sees_nothing(): void
    {
        $lect = $this->lecturer(null);
        $this->taoLop($this->a->id, 'LOP-A', $lect);

        // Chưa gán trường ⇒ không thuộc trường nào ⇒ không thấy gì, kể cả lớp mình dạy.
        $this->actingAs($lect)->get(route('manager.classes.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('workingSchoolId', null)
                ->has('workingSchools', 0)
                ->has('classes.data', 0));
    }

    public function test_super_admin_stays_global_operator(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->b->id)
            ->get(route('manager.dashboard.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('workingSchoolId', $this->b->id) // đổi trường tự do
                ->has('workingSchools', 2));
    }

    public function test_import_write_dong_dau_theo_truong_dang_lam_viec_khong_theo_payload(): void
    {
        Bus::fake();
        Storage::fake();
        // Nhập điểm/danh sách giờ CHỈ super_admin làm được (xem LecturerScopingTest), nên tính
        // chất "không forge được trường qua payload" phải kiểm bằng đúng vai trò đó.
        $admin = $this->admin();
        $file = UploadedFile::fake()->create('roster.csv', 1, 'text/csv');

        $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->b->id)
            ->post(route('manager.reports.student-roster'), [
                'school_id' => $this->a->id,   // cố forge sang trường A
                'file' => $file,
            ])->assertRedirect();

        $this->assertDatabaseHas('file_batches', ['school_id' => $this->b->id, 'type' => 'student_roster']);
        $this->assertDatabaseMissing('file_batches', ['school_id' => $this->a->id]);
    }

    public function test_giang_vien_KHONG_import_duoc_du_da_gan_truong(): void
    {
        Bus::fake();
        Storage::fake();
        $lect = $this->lecturer($this->a->id);
        $file = UploadedFile::fake()->create('roster.csv', 1, 'text/csv');

        // Đảm bảo hơn cả "kẹp đúng trường": giảng viên không nhập được danh sách toàn trường.
        $this->actingAs($lect)->post(route('manager.reports.student-roster'), ['file' => $file])
            ->assertForbidden();
        $this->assertDatabaseCount('file_batches', 0);
    }

    private function taoLop(int $schoolId, string $ma, \App\Models\User $gv): \App\Models\CourseClass
    {
        $course = \App\Models\Course::create([
            'code' => 'C' . $ma, 'name' => 'Môn ' . $ma, 'credits' => 3, 'school_id' => $schoolId,
        ]);
        $syllabus = \App\Models\CourseSyllabus::create([
            'course_id' => $course->id, 'version' => '1.0', 'effective_year' => 2023,
        ]);
        $dept = \App\Models\Department::create([
            'code' => 'D' . $ma, 'name' => 'Khoa ' . $ma, 'school_id' => $schoolId,
        ]);
        $program = \App\Models\Program::create([
            'department_id' => $dept->id, 'code' => 'P' . $ma, 'name' => 'Ngành ' . $ma,
            'school_id' => $schoolId,
        ]);
        $cohort = \App\Models\Cohort::create([
            'program_id' => $program->id, 'name' => 'K' . $ma, 'start_year' => 2023,
            'school_id' => $schoolId,
        ]);

        return \App\Models\CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id, 'lecturer_id' => $gv->id,
            'class_code' => $ma, 'academic_year' => '2023-2024', 'semester' => 1,
            'school_id' => $schoolId,
        ]);
    }

    public function test_lecturer_without_school_cannot_import(): void
    {
        Bus::fake();
        Storage::fake();
        $lect = $this->lecturer(null);
        $file = UploadedFile::fake()->create('roster.csv', 1, 'text/csv');

        $this->actingAs($lect)->post(route('manager.reports.student-roster'), ['file' => $file])
            ->assertForbidden();
        $this->assertDatabaseCount('file_batches', 0);
    }
}
