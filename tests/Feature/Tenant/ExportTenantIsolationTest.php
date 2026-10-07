<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\FileBatch;
use App\Models\Program;
use App\Models\ReportSnapshot;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * MỌI đường xuất file phải kẹp theo TRƯỜNG ĐANG LÀM VIỆC.
 *
 * File xuất ra được gửi thẳng cho trường hoặc dùng làm báo cáo kiểm định, nên rò một file sang
 * trường khác là hỏng việc thật — không phải lỗi hiển thị sửa sau cũng được. Trước đây
 * `reports.download` và `reports.batch-file` chỉ kiểm file có tồn tại, KHÔNG kiểm trường; hai
 * route đó lại nằm trong nhóm người xem được vào, nên đổi uuid trên URL là lấy được báo cáo và
 * file điểm gốc của trường khác.
 */
class ExportTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{School, Program, Cohort, Student, CourseClass} */
    private function dungTruong(string $ma): array
    {
        $school = School::create(['code' => $ma, 'name' => 'Trường ' . $ma]);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'D1', 'name' => 'Khoa 1']);
        // Mã ngành/môn TRÙNG NHAU giữa hai trường — đúng thiết kế đa-trường, và là bẫy dễ lọt nhất.
        $program = Program::create([
            'school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'P1', 'name' => 'Ngành 1',
        ]);
        $cohort = Cohort::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'name' => '2026', 'start_year' => 2026,
        ]);
        $course = Course::create(['school_id' => $school->id, 'code' => 'C1', 'name' => 'Môn 1', 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $class = CourseClass::create([
            'school_id' => $school->id, 'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id,
            'lecturer_id' => User::factory()->create()->id, 'class_code' => 'C1-2026',
            'academic_year' => '2026-2027', 'semester' => 1,
        ]);
        $student = Student::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'cohort_id' => $cohort->id,
            'student_code' => 'SV01', 'full_name' => 'Học viên ' . $ma, 'status' => 'studying',
            'lookup_code' => 'CODE' . $ma . '1',
        ]);

        return [$school, $program, $cohort, $student, $class];
    }

    private function adminCua(School $school): User
    {
        Role::findOrCreate('super_admin', 'web');
        $u = User::factory()->create(['school_id' => $school->id]);
        $u->assignRole('super_admin');

        return $u;
    }

    public function test_moi_duong_xuat_file_deu_chan_du_lieu_truong_khac(): void
    {
        Storage::fake('local');

        [$schoolA, $programA] = $this->dungTruong('AAA');
        [$schoolB, $programB, $cohortB, $studentB, $classB] = $this->dungTruong('BBB');

        // Báo cáo + file gốc thuộc trường B.
        $adminB = $this->adminCua($schoolB);
        Storage::put('exports/bao_cao_B.csv', 'BÍ MẬT CỦA TRƯỜNG B');
        $snapshotB = ReportSnapshot::create([
            'user_id' => $adminB->id, 'school_id' => $schoolB->id,
            'name' => 'Báo cáo B', 'type' => 'aun_qa', 'filters' => ['program_id' => $programB->id],
            'status' => 'generated', 'file_path' => 'exports/bao_cao_B.csv',
        ]);
        Storage::put('imports/diem_B.xlsx', 'ĐIỂM GỐC CỦA TRƯỜNG B');
        $batchB = FileBatch::create([
            'user_id' => $adminB->id, 'school_id' => $schoolB->id,
            'type' => 'grade_bulk', 'status' => 'completed',
            'file_name' => 'diem_B.xlsx', 'file_path' => 'imports/diem_B.xlsx',
        ]);

        // Người vận hành của trường A, đang làm việc ở trường A.
        $this->actingAs($this->adminCua($schoolA));
        $q = '?school_id=' . $schoolA->id;

        $chan = [
            'reports.download' => route('manager.reports.download', $snapshotB->uuid) . $q,
            'reports.batch-file' => route('manager.reports.batch-file', $batchB->uuid) . $q,
            'students.export-plo' => route('manager.students.export-plo', $studentB->uuid) . $q,
            'classes.export-pi' => route('manager.classes.export-pi', $classB->uuid) . $q,
        ];

        $lot = [];
        foreach ($chan as $ten => $url) {
            $status = $this->get($url)->getStatusCode();
            if ($status < 400) {
                $lot[] = $ten . ' → ' . $status;
            }
        }
        $this->assertSame([], $lot, 'Rò dữ liệu trường khác qua: ' . implode(', ', $lot));

        // Xuất HÀNG LOẠT theo ngành của trường khác cũng phải bị chặn ở tầng validate.
        foreach (['students.export-plo-bulk', 'students.export-class-summary'] as $ten) {
            $this->get(route('manager.' . $ten, ['program_id' => $programB->id]) . '&school_id=' . $schoolA->id)
                ->assertSessionHasErrors('program_id');
        }
    }

    /**
     * Xem / SỬA / XÓA giảng viên của trường khác.
     *
     * Ba hàm này chỉ kiểm "có phải giảng viên không", không kiểm trường. Đổi uuid trên URL là
     * chạm được người của trường khác — và `update` còn validate khoa theo trường của CHÍNH NGƯỜI
     * BỊ SỬA, tức càng đi sâu càng lệch khỏi phạm vi.
     */
    public function test_khong_cham_duoc_giang_vien_cua_truong_khac(): void
    {
        Role::findOrCreate('lecturer', 'web');

        [$schoolA] = $this->dungTruong('AAA');
        [$schoolB] = $this->dungTruong('BBB');

        $khoaB = \App\Models\Department::where('school_id', $schoolB->id)->firstOrFail();
        $gvB = User::factory()->create(['school_id' => $schoolB->id, 'department_id' => $khoaB->id]);
        $gvB->assignRole('lecturer');

        $this->actingAs($this->adminCua($schoolA));
        $q = '?school_id=' . $schoolA->id;

        $this->get(route('manager.lecturers.show', $gvB->uuid) . $q)->assertNotFound();
        $this->put(route('manager.lecturers.update', $gvB->uuid) . $q, ['full_name' => 'Đổi tên'])
            ->assertNotFound();
        $this->delete(route('manager.lecturers.destroy', $gvB->uuid) . $q)->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $gvB->id, 'full_name' => $gvB->full_name]);
    }

    /** Chặn nhầm cũng là lỗi: cùng trường thì phải tải được. */
    public function test_cung_truong_thi_van_tai_duoc(): void
    {
        Storage::fake('local');

        [$school, $program, $cohort] = $this->dungTruong('AAA');
        $admin = $this->adminCua($school);
        Storage::put('exports/bao_cao_A.csv', 'BÁO CÁO CỦA TRƯỜNG A');
        $snapshot = ReportSnapshot::create([
            'user_id' => $admin->id, 'school_id' => $school->id,
            'name' => 'Báo cáo A', 'type' => 'aun_qa', 'filters' => ['program_id' => $program->id],
            'status' => 'generated', 'file_path' => 'exports/bao_cao_A.csv',
        ]);

        $this->actingAs($admin)
            ->get(route('manager.reports.download', $snapshot->uuid) . '?school_id=' . $school->id)
            ->assertOk();
    }
}
