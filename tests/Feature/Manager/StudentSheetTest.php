<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Assessment;
use App\Models\AssessmentCloMapping;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\User;
use App\Services\OBE\ObeRecalculationService;
use App\Services\OBE\StudentProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BẢNG 1 của khách — phiếu kết quả từng sinh viên: PI + PLO + tín chỉ tích lũy.
 *
 * Trước đây phiếu `exportPlo` chỉ có PLO + PI, không có tín chỉ; còn màn hình chi tiết SV tự cộng
 * tín chỉ của MỌI môn có điểm (kể cả rớt) nên lệch với báo cáo khóa. Nay cả hai lấy chung
 * `StudentProgressService`.
 */
class StudentSheetTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Program $program;
    private Cohort $cohort;
    private User $admin;
    private Student $dat;      // đạt cả 2 môn
    private Student $rot;      // rớt 1 môn ⇒ tín chỉ tích lũy ít hơn tín chỉ đã học

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'lecturer', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        School::query()->forceDelete();
        $this->school = School::create(['code' => 'S1', 'name' => 'Trường 1', 'is_active' => true]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('super_admin');

        $dept = Department::create(['code' => 'D', 'name' => 'Khoa', 'school_id' => $this->school->id]);
        $this->program = Program::create([
            'department_id' => $dept->id, 'code' => 'P', 'name' => 'Ngành',
            'school_id' => $this->school->id, 'required_credits' => 10,
        ]);
        $this->cohort = Cohort::create([
            'program_id' => $this->program->id, 'name' => 'K2026', 'start_year' => 2026,
            'school_id' => $this->school->id,
        ]);

        $plo = Plo::create(['program_id' => $this->program->id, 'code' => 'PLO1', 'description' => 'x']);
        $pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1', 'description' => 'x', 'is_dummy' => false]);

        $this->dat = $this->makeStudent('SV-DAT', 'Sinh viên Đạt');
        $this->rot = $this->makeStudent('SV-ROT', 'Sinh viên Rớt');

        // Môn A 2TC: cả hai đều đạt 8.0 · Môn B 3TC: SV-DAT 8.0, SV-ROT 3.0 (rớt)
        $this->makeCourse('MA', 2, $pi, ['SV-DAT' => 8.0, 'SV-ROT' => 8.0]);
        $this->makeCourse('MB', 3, $pi, ['SV-DAT' => 8.0, 'SV-ROT' => 3.0]);

        app(ObeRecalculationService::class)->recalculateScope($this->school->id, $this->program->id, null);
    }

    public function test_tien_do_mot_sv_khop_tuyet_doi_voi_tien_do_ca_nganh(): void
    {
        $service = app(StudentProgressService::class);

        $caNganh = $service->forProgram($this->program->id);
        $rieng = $service->forStudent($this->rot);

        foreach (['courses_graded', 'courses_passed', 'credits_attempted', 'credits_earned',
            'required_credits', 'completion_percent', 'is_eligible'] as $key) {
            $this->assertSame(
                $caNganh[$this->rot->id][$key], $rieng[$key],
                "Khoá '{$key}' của forStudent phải khớp forProgram",
            );
        }

        // SV rớt môn B (3TC): học 5 TC nhưng chỉ tích lũy 2 TC = 20% của 10 TC ⇒ chưa đủ điều kiện.
        $this->assertSame(5.0, $rieng['credits_attempted']);
        $this->assertSame(2.0, $rieng['credits_earned']);
        $this->assertSame(20.0, $rieng['completion_percent']);
        $this->assertFalse($rieng['is_eligible']);
    }

    public function test_phieu_sv_co_them_khoi_tien_do_tich_luy(): void
    {
        $csv = $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->school->id)
            ->get(route('manager.students.export-plo', $this->rot))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('[I] TIẾN ĐỘ TÍCH LŨY', $csv);
        $this->assertStringContainsString('[II] CHUẨN ĐẦU RA CHƯƠNG TRÌNH (PLO)', $csv);
        $this->assertStringContainsString('[III] CHỈ SỐ THỰC HIỆN (PI)', $csv);

        $this->assertStringContainsString('"Tín chỉ tích lũy",2', $csv);
        $this->assertStringContainsString('"Tín chỉ đã học",5', $csv);
        $this->assertStringContainsString('"Tín chỉ yêu cầu tốt nghiệp",10', $csv);
        $this->assertStringContainsString('"% tích lũy",20%', $csv);
        $this->assertStringContainsString('CHƯA', $csv);
    }

    public function test_xuat_hang_loat_moi_sinh_vien_mot_phieu_trong_zip(): void
    {
        $zipBinary = $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->school->id)
            ->get(route('manager.students.export-plo-bulk', [
                'program_id' => $this->program->id,
                'cohort_id' => $this->cohort->id,
            ]))
            ->assertOk()
            ->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $zipBinary);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Phải mở được file zip');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);

        // 2 phiếu + 1 hướng dẫn phát. Hướng dẫn là bắt buộc: mỗi phiếu chứa MÃ TRA CỨU RIÊNG,
        // gửi nguyên gói vào nhóm lớp là phát mã của mọi người cho mọi người.
        $this->assertSame(['DOC-TRUOC-KHI-GUI.txt', 'PLO_PI_SV-DAT.csv', 'PLO_PI_SV-ROT.csv'], $names);
        $this->assertStringContainsString(
            'CHỈ GỬI TỪNG FILE CHO TỪNG NGƯỜI',
            $zip->getFromName('DOC-TRUOC-KHI-GUI.txt'),
        );

        // Nội dung từng phiếu phải là của đúng sinh viên đó, không phải bản sao.
        $this->assertStringContainsString('"Tín chỉ tích lũy",5', $zip->getFromName('PLO_PI_SV-DAT.csv'));
        $this->assertStringContainsString('"Tín chỉ tích lũy",2', $zip->getFromName('PLO_PI_SV-ROT.csv'));

        $zip->close();
        @unlink($path);
    }

    public function test_xuat_hang_loat_doi_pham_vi_thuoc_truong_dang_lam_viec(): void
    {
        $khac = School::create(['code' => 'S2', 'name' => 'Trường 2', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $khac->id)
            ->get(route('manager.students.export-plo-bulk', ['program_id' => $this->program->id]))
            ->assertSessionHasErrors('program_id');
    }

    public function test_khong_xem_hay_xuat_duoc_ho_so_sv_truong_khac(): void
    {
        $khac = School::create(['code' => 'S3', 'name' => 'Trường 3', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $khac->id)
            ->get(route('manager.students.show', $this->dat))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $khac->id)
            ->get(route('manager.students.export-plo', $this->dat))
            ->assertNotFound();
    }

    private function makeStudent(string $code, string $name): Student
    {
        return Student::create([
            'student_code' => $code, 'full_name' => $name, 'school_id' => $this->school->id,
            'cohort_id' => $this->cohort->id, 'program_id' => $this->program->id, 'status' => 'studying',
        ]);
    }

    /** @param array<string,float> $scores mã SV → điểm (dùng cho cả bài quá trình lẫn cuối kỳ) */
    private function makeCourse(string $code, int $credits, Pi $pi, array $scores): void
    {
        $course = Course::create([
            'code' => $code, 'name' => $code, 'credits' => $credits, 'school_id' => $this->school->id,
        ]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $class = CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $this->cohort->id,
            'lecturer_id' => $this->admin->id, 'class_code' => $code . '-L1',
            'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
            'school_id' => $this->school->id,
        ]);

        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO-' . $code, 'description' => 'x']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi->id, 'mapping_level' => 'R']);

        foreach (['process' => 40, 'final' => 60] as $category => $weight) {
            $assessment = Assessment::create([
                'syllabus_id' => $syllabus->id, 'name' => $category . '-' . $code,
                'assessment_category' => $category, 'weight_percent' => $weight, 'is_rubric_based' => false,
            ]);
            AssessmentCloMapping::create([
                'assessment_id' => $assessment->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100,
            ]);

            foreach ($scores as $studentCode => $score) {
                $student = Student::where('student_code', $studentCode)->firstOrFail();
                StudentGrade::create([
                    'student_id' => $student->id, 'class_id' => $class->id,
                    'assessment_id' => $assessment->id, 'score' => $score,
                ]);
            }
        }
    }
}
