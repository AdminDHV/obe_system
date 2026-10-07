<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Assessment;
use App\Models\AssessmentCloMapping;
use App\Models\Clo;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Models\User;
use App\Services\OBE\CloCalculationService;
use App\Services\OBE\ObeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GOLDEN-MASTER cấp MÔN/CLO — bám ĐÚNG dữ liệu demo "HỌC PHẦN AAA" trong file khách
 * `docs/New Đo lường chuẩn đầu ra học phần.xlsx` (bảng "Điểm thi của SV quy đổi sang thang điểm 10").
 *
 * Chứng minh engine CLO ({@see CloCalculationService}) tái tạo CHÍNH XÁC từng điểm CLO của 10 SV
 * và tỷ lệ đạt CLO 0.9/0.9/1/1/1 ở ngưỡng đạt = 5 (không phải số bịa như test tổng hợp cũ).
 *
 * Cột (đã quy đổi thang 10) → CLO / category:
 *   BÀI TẬP 1 (process): cột0→CLO1, cột1→CLO1, cột2→CLO3
 *   THI GIỮA KỲ (process): cột3→CLO2, cột4→CLO4, cột5→CLO5
 *   CUỐI KỲ (final):      cột6→CLO2, cột7→CLO3, cột8→CLO4
 */
class ClientFile1CourseGoldenMasterTest extends TestCase
{
    use RefreshDatabase;

    /** cột → [clo_index (1..5), category] */
    private const COLUMNS = [
        [1, 'process'], [1, 'process'], [3, 'process'], // BÀI TẬP 1
        [2, 'process'], [4, 'process'], [5, 'process'], // THI GIỮA KỲ
        [2, 'final'],   [3, 'final'],   [4, 'final'],    // CUỐI KỲ
    ];

    /** SV → 9 điểm thang-10 đã quy đổi (đúng bảng file HP AAA). */
    private const INPUTS = [
        1001 => [7.5, 8.75, 7.5, 6.6667, 7.5, 6.6667, 7.5, 6.6667, 8.3333],
        1002 => [5.0, 7.5, 7.5, 10.0, 7.5, 6.6667, 8.75, 5.0, 6.6667],
        1003 => [6.25, 7.5, 7.5, 6.6667, 7.5, 10.0, 7.5, 5.0, 6.6667],
        1004 => [8.75, 8.75, 7.5, 8.3333, 8.75, 8.3333, 7.5, 6.6667, 6.6667],
        1005 => [3.75, 3.75, 7.5, 5.0, 6.25, 6.6667, 3.75, 5.0, 5.0],
        1006 => [7.5, 8.75, 7.5, 8.3333, 7.5, 8.3333, 7.5, 6.6667, 8.3333],
        1007 => [8.75, 8.75, 10.0, 6.6667, 8.75, 6.6667, 8.75, 8.3333, 8.3333],
        1008 => [8.25, 7.5, 7.5, 8.3333, 6.25, 6.6667, 5.0, 6.6667, 6.6667],
        1009 => [8.75, 8.75, 10.0, 8.3333, 7.5, 8.3333, 7.5, 8.3333, 10.0],
        1010 => [10.0, 7.5, 10.0, 10.0, 8.75, 8.3333, 8.75, 10.0, 6.6667],
    ];

    /** SV → [CLO1..CLO5] điểm mong đợi (đúng cột kết quả CLO trong file). */
    private const EXPECTED_CLO = [
        1001 => [8.125, 7.1667, 7.0, 8.0, 6.6667],
        1002 => [6.25, 9.25, 6.0, 7.0, 6.6667],
        1003 => [6.875, 7.1667, 6.0, 7.0, 10.0],
        1004 => [8.75, 7.8333, 7.0, 7.5, 8.3333],
        1005 => [3.75, 4.25, 6.0, 5.5, 6.6667],
        1006 => [8.125, 7.8333, 7.0, 8.0, 8.3333],
        1007 => [8.75, 7.9167, 9.0, 8.5, 6.6667],
        1008 => [7.875, 6.3333, 7.0, 6.5, 6.6667],
        1009 => [8.75, 7.8333, 9.0, 9.0, 8.3333],
        1010 => [8.75, 9.25, 10.0, 7.5, 8.3333],
    ];

    public function test_hp_aaa_reproduces_client_clo_scores_and_pass_rates(): void
    {
        $school = School::create(['code' => 'SCH-GM1', 'name' => 'GoldenMaster1']);
        $dept = Department::create(['code' => 'D1', 'name' => 'Khoa', 'school_id' => $school->id]);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'PG1', 'name' => 'CTĐT', 'school_id' => $school->id]);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $lecturer = User::factory()->create();

        $course = Course::create(['code' => 'AAA', 'name' => 'HỌC PHẦN AAA', 'credits' => 3, 'school_id' => $school->id]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $class = CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id, 'lecturer_id' => $lecturer->id,
            'class_code' => 'AAA-CLS', 'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
            'school_id' => $school->id,
        ]);

        // 5 CLO (index 1..5 → model)
        $clos = [];
        foreach (range(1, 5) as $i) {
            $clos[$i] = Clo::create(['syllabus_id' => $syllabus->id, 'code' => "CLO{$i}", 'description' => "CLO{$i}"]);
        }

        // 9 assessment theo cột, map đúng CLO + category
        $assessments = [];
        foreach (self::COLUMNS as $col => [$cloIdx, $category]) {
            $a = Assessment::create([
                'syllabus_id' => $syllabus->id, 'name' => "COL{$col}", 'assessment_category' => $category,
                'weight_percent' => $category === 'final' ? 60 : 40, 'is_rubric_based' => false,
            ]);
            AssessmentCloMapping::create(['assessment_id' => $a->id, 'clo_id' => $clos[$cloIdx]->id, 'weight_in_assessment' => 100]);
            $assessments[$col] = $a;
        }

        // 10 SV + điểm
        $students = [];
        foreach (self::INPUTS as $code => $scores) {
            $sv = Student::create([
                'student_code' => (string) $code, 'full_name' => "SV{$code}", 'school_id' => $school->id,
                'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying',
            ]);
            foreach ($scores as $col => $score) {
                StudentGrade::create([
                    'student_id' => $sv->id, 'class_id' => $class->id,
                    'assessment_id' => $assessments[$col]->id, 'score' => $score,
                ]);
            }
            $students[$code] = $sv;
        }

        // Chạy engine CLO cho từng SV
        $svc = app(CloCalculationService::class);
        foreach ($students as $sv) {
            $svc->calculateForStudent($sv, $class);
        }

        // 1) Từng điểm CLO khớp file
        foreach (self::EXPECTED_CLO as $code => $expected) {
            foreach ($expected as $i => $expScore) {
                $clo = $clos[$i + 1];
                $actual = StudentCloResult::where('student_id', $students[$code]->id)
                    ->where('clo_id', $clo->id)->value('achieved_score');
                $this->assertNotNull($actual, "Thiếu kết quả CLO" . ($i + 1) . " cho SV{$code}");
                $this->assertEqualsWithDelta(
                    $expScore, (float) $actual, 0.005,
                    "SV{$code} CLO" . ($i + 1) . " lệch file (mong {$expScore})"
                );
            }
        }

        // 2) Tỷ lệ đạt CLO (>= ngưỡng đạt) khớp file: 0.9/0.9/1/1/1
        $threshold = ObeConfig::cloPassThreshold();
        $this->assertEqualsWithDelta(5.0, $threshold, 0.0001, 'Ngưỡng đạt CLO phải = 5 (chuẩn khách)');

        $expectedPassRate = [1 => 0.9, 2 => 0.9, 3 => 1.0, 4 => 1.0, 5 => 1.0];
        foreach ($expectedPassRate as $i => $rate) {
            $passed = StudentCloResult::where('clo_id', $clos[$i]->id)
                ->where('achieved_score', '>=', $threshold)->count();
            $this->assertSame(
                $rate, round($passed / 10, 4),
                "Tỷ lệ đạt CLO{$i} lệch file (mong {$rate})"
            );
        }
    }
}
