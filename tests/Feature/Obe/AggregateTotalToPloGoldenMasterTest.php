<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Imports\AggregateGradeImport;
use App\Imports\LegacyGradeImport;
use App\Models\Assessment;
use App\Models\Clo;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseSyllabus;
use App\Models\CloPiMapping;
use App\Models\CourseClass;
use App\Models\Department;
use App\Models\FileBatch;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Services\OBE\ObeRecalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * GOLDEN-MASTER cho chế độ "điểm tổng → PI/PLO" (Feature B).
 *
 * Chứng minh 2 chiều:
 *  (1) Khóa gắn cờ grade_granularity='total' — DÙ là năm ≤2024 (vốn đi luồng legacy CLO-only) —
 *      import điểm TỔNG vẫn cuốn lên PI/PLO cấp chương trình. Điểm tổng S=8 → mỗi CLO=8 →
 *      PI ratio = 8/10 = 0.8 → PLO = 0.8 (1 PI trọng số 1.0).
 *  (2) Control: cùng năm ≤2024 KHÔNG gắn cờ (LegacyGradeImport) → chỉ CLO, KHÔNG PI/PLO.
 */
class AggregateTotalToPloGoldenMasterTest extends TestCase
{
    use RefreshDatabase;

    /** Dựng Ngành + Môn(2 CLO) + PLO + PI(trọng số 1.0) + ma trận CLO→PI. Trả [schoolId, program]. */
    private function seedFramework(string $suffix): array
    {
        $school = School::create(['code' => 'SCH' . $suffix, 'name' => 'Trường ' . $suffix]);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'IT' . $suffix, 'name' => 'CNTT']);
        $program = Program::create(['school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'CNTT' . $suffix, 'name' => 'KTPM']);

        $course = Course::create(['school_id' => $school->id, 'code' => 'IT101', 'name' => 'Nhập môn', 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $clo1 = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'A']);
        $clo2 = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO2', 'description' => 'B']);

        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'P']);
        $pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1', 'description' => 'I', 'is_dummy' => false, 'weight' => 1.0]);
        CloPiMapping::create(['clo_id' => $clo1->id, 'pi_id' => $pi->id, 'mapping_level' => 'M']);
        CloPiMapping::create(['clo_id' => $clo2->id, 'pi_id' => $pi->id, 'mapping_level' => 'M']);

        return [$school->id, $program];
    }

    private function batch(): FileBatch
    {
        return FileBatch::create(['user_id' => \App\Models\User::factory()->create()->id, 'file_path' => 'x', 'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
    }

    public function test_aggregate_total_produces_pi_plo_even_for_pre_2024_cohort(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]); // đo thủ công bên dưới cho tất định
        [$schoolId] = $this->seedFramework('A');

        // Năm 2022 (mặc định = legacy) nhưng import qua chế độ điểm tổng.
        (new AggregateGradeImport($this->batch(), $schoolId))->collection(collect([
            ['ma_nganh' => 'CNTTA', 'nam_khoa' => 2022, 'ma_mon' => 'IT101', 'mssv' => 'SV1', 'diem' => 8],
        ]));

        // Khóa được gắn cờ 'total' + carrier map tới CẢ 2 CLO.
        $this->assertDatabaseHas('cohorts', ['name' => 'Khóa 2022', 'grade_granularity' => 'total']);
        $carrier = Assessment::where('name', 'Điểm tổng kết')->firstOrFail();
        $this->assertSame(2, \App\Models\AssessmentCloMapping::where('assessment_id', $carrier->id)->count());

        $student = Student::where('student_code', 'SV1')->firstOrFail();
        $class = CourseClass::where('class_code', 'IT101-2022-TONG')->firstOrFail();
        app(ObeRecalculationService::class)->recalculateStudentInClass($student, $class);

        // Mỗi CLO = điểm tổng 8.
        $this->assertSame(2, StudentCloResult::where('student_id', $student->id)->count());
        foreach (StudentCloResult::where('student_id', $student->id)->get() as $r) {
            $this->assertEqualsWithDelta(8.0, (float) $r->achieved_score, 0.001);
        }

        // PI = 8/10 = 0.8 ; PLO = 0.8 (1 PI trọng số 1.0) — KHÁC legacy (vốn bỏ PI/PLO).
        $this->assertSame(1, $student->piResults()->count());
        $this->assertSame(1, $student->ploResults()->count());
        $this->assertEqualsWithDelta(0.8, (float) $student->piResults()->first()->achieved_score, 0.0005);
        $this->assertEqualsWithDelta(0.8, (float) $student->ploResults()->first()->achieved_score, 0.0005);
    }

    public function test_control_legacy_year_without_flag_skips_pi_plo(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);
        [$schoolId] = $this->seedFramework('B');

        // CÙNG năm 2022 nhưng qua luồng legacy (không gắn cờ) → chỉ CLO.
        (new LegacyGradeImport($this->batch(), $schoolId))->collection(collect([
            ['ma_nganh' => 'CNTTB', 'nam_khoa' => 2022, 'ma_mon' => 'IT101', 'mssv' => 'SV2', 'diem' => 8],
        ]));

        $this->assertDatabaseHas('cohorts', ['name' => 'Khóa 2022', 'grade_granularity' => null]);

        $student = Student::where('student_code', 'SV2')->firstOrFail();
        $class = CourseClass::where('class_code', 'IT101-2022-LEGACY')->firstOrFail();
        app(ObeRecalculationService::class)->recalculateStudentInClass($student, $class);

        $this->assertSame(2, StudentCloResult::where('student_id', $student->id)->count());
        $this->assertSame(0, $student->piResults()->count());
        $this->assertSame(0, $student->ploResults()->count());
    }
}
