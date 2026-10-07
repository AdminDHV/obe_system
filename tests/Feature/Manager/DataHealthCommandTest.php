<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Assessment;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `obe:data-health` — công cụ trả lời "dữ liệu cũ có cần dọn không", chạy được trên production.
 *
 * Nguyên tắc: MẶC ĐỊNH CHỈ BÁO CÁO. Chỉ `--fix` mới được sửa, và chỉ sửa thứ an toàn tuyệt đối
 * (bù ghi danh). Xoá dữ liệu là việc của người, không phải của công cụ.
 */
class DataHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Student $student;
    private CourseClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        School::query()->forceDelete();

        $this->school = School::create(['code' => 'S', 'name' => 'Trường', 'is_active' => true]);
        $dept = Department::create(['code' => 'D', 'name' => 'Khoa', 'school_id' => $this->school->id]);
        $program = Program::create([
            'department_id' => $dept->id, 'code' => 'P', 'name' => 'Ngành',
            'school_id' => $this->school->id, 'required_credits' => 30,
        ]);
        $cohort = Cohort::create([
            'program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026,
            'school_id' => $this->school->id,
        ]);
        $course = Course::create(['code' => 'C1', 'name' => 'Môn', 'credits' => 3, 'school_id' => $this->school->id]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);

        $this->class = CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id,
            'lecturer_id' => User::factory()->create(['is_active' => true])->id,
            'class_code' => 'L1', 'academic_year' => '2026-2027', 'semester' => 1,
            'max_capacity' => 50, 'school_id' => $this->school->id,
        ]);
        $this->student = Student::create([
            'student_code' => 'SV1', 'full_name' => 'A', 'school_id' => $this->school->id,
            'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying',
        ]);

        // Có ĐIỂM nhưng KHÔNG ghi danh — đúng hiện trạng do BulkGradeImport/GradeImport cũ để lại.
        $assessment = Assessment::create([
            'syllabus_id' => $syllabus->id, 'name' => 'Cuối kỳ', 'assessment_category' => 'final',
            'weight_percent' => 100, 'is_rubric_based' => false,
        ]);
        StudentGrade::create([
            'student_id' => $this->student->id, 'class_id' => $this->class->id,
            'assessment_id' => $assessment->id, 'score' => 8,
        ]);
    }

    public function test_mac_dinh_chi_bao_cao_KHONG_sua_gi(): void
    {
        $this->artisan('obe:data-health')
            ->expectsOutputToContain('1 cặp (SV, lớp) thiếu ghi danh')
            ->assertOk();

        $this->assertDatabaseMissing('course_class_student', [
            'student_id' => $this->student->id, 'class_id' => $this->class->id,
        ]);
    }

    public function test_fix_bu_ghi_danh(): void
    {
        $this->artisan('obe:data-health --fix')->assertOk();

        $this->assertDatabaseHas('course_class_student', [
            'student_id' => $this->student->id, 'class_id' => $this->class->id,
        ]);

        // Chạy lại phải sạch và không nhân đôi.
        $this->artisan('obe:data-health')->assertOk();
        $this->assertSame(1, DB::table('course_class_student')->count());
    }

    public function test_bao_ma_giang_vien_trung_trong_cung_truong(): void
    {
        // Đây là cảnh TRƯỚC KHI DEPLOY: unique (trường, mã GV) chưa có nên dữ liệu trùng tồn tại
        // được. Phải gỡ ràng buộc mới trong test thì mới dựng lại được đúng tình huống đó —
        // sau migration thì chính ràng buộc này chặn, nên lỗi không thể phát sinh thêm.
        DB::statement('DROP INDEX users_school_staff_code_unique');

        DB::table('users')->where('id', User::first()->id)->update([
            'school_id' => $this->school->id, 'staff_code' => 'GV01',
        ]);
        $second = User::factory()->create(['is_active' => true, 'school_id' => $this->school->id]);
        DB::table('users')->where('id', $second->id)->update(['staff_code' => 'GV01']);

        $this->artisan('obe:data-health')
            ->expectsOutputToContain('CẦN NGƯỜI QUYẾT')
            ->assertOk();
    }

    public function test_du_lieu_sach_thi_khong_bao_gi(): void
    {
        DB::table('course_class_student')->insert([
            'student_id' => $this->student->id, 'class_id' => $this->class->id,
        ]);

        $this->artisan('obe:data-health')
            ->expectsOutputToContain('Không phát hiện vấn đề nào cần dọn')
            ->assertOk();
    }

    public function test_khong_bao_dong_gia_ve_truong_da_TAT(): void
    {
        $tat = School::create(['code' => 'CU', 'name' => 'Trường cũ', 'is_active' => false]);
        $dept = Department::create(['code' => 'DC', 'name' => 'Khoa', 'school_id' => $tat->id]);
        Program::create([
            'department_id' => $dept->id, 'code' => 'PC', 'name' => 'Ngành cũ',
            'school_id' => $tat->id, 'required_credits' => null,
        ]);

        // Nhắc về trường đã tắt là báo động giả — người vận hành sẽ quen bỏ qua cảnh báo.
        $this->artisan('obe:data-health')
            ->doesntExpectOutputToContain('CU / PC')
            ->assertOk();
    }
}
