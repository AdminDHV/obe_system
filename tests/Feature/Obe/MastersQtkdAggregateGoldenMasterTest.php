<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Imports\AggregateGradeImport;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Course;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\FileBatch;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\OBE\ObeAttainmentService;
use App\Services\OBE\ObeRecalculationService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GOLDEN-MASTER — data THẬT chương trình Thạc sĩ QTKD (khóa 2024, "điểm tổng → PI/PLO").
 *
 * Map mô hình Thạc sĩ (Môn→PLO, I/R/M/A) vào engine: 1 CLO/môn + 1 PI/PLO (trọng số 1).
 * Dùng THAM SỐ CẤU TRÚC THẬT (mã/tín chỉ/mức từ ma trận QTKD — docx `(25.08) 5.1. CTĐT 2023`):
 *   PCO501 Triết học            4TC   PLO1=I  PLO2=I
 *   BUS510 QT marketing số      3TC   PLO1=I  PLO2=R
 *   BUS511 Lãnh đạo đích thực   3TC   PLO1=R  PLO2=M
 * Mức I/R/M/A = 1/2/3/3 (MappingScaleWeightSeeder).
 *
 * Số kỳ vọng tính TAY theo WeightedAttainmentFormula (điểm tổng = điểm mỗi CLO):
 *   PI_ratio = Σ(TC × mức × tổng) / Σ(TC × mức × 10);  PLO = PI (1 PI/PLO).
 * HV1 (tổng PCO501=8, BUS510=9, BUS511=7):
 *   PI1 = (4·1·8 + 3·1·9 + 3·2·7)/(40+30+60) = 101/130 = 0.776923…
 *   PI2 = (4·1·8 + 3·2·9 + 3·3·7)/(40+60+90) = 149/190 = 0.784210…
 * HV2 (tổng 3/2/4) → PI1 = 42/130 = 0.323077…  (< 0.5 = KHÔNG đạt)
 * ⇒ mức đạt PLO1 = 1/2 = 50% (ngưỡng tỉ lệ 0.5).
 */
class MastersQtkdAggregateGoldenMasterTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,array{tc:int,plo1:string,plo2:string}> mã môn => tín chỉ + mức PLO1/PLO2 (thật) */
    private const COURSES = [
        'PCO501' => ['tc' => 4, 'plo1' => 'I', 'plo2' => 'I'],
        'BUS510' => ['tc' => 3, 'plo1' => 'I', 'plo2' => 'R'],
        'BUS511' => ['tc' => 3, 'plo1' => 'R', 'plo2' => 'M'],
    ];

    /** Điểm tổng thật/đại diện của 2 học viên. */
    private const GRADES = [
        'HV1' => ['PCO501' => 8.0, 'BUS510' => 9.0, 'BUS511' => 7.0],
        'HV2' => ['PCO501' => 3.0, 'BUS510' => 2.0, 'BUS511' => 4.0],
    ];

    private function seedFramework(): Program
    {
        (new MappingScaleWeightSeeder())->run(); // I/R/M/A = 1/2/3/3

        $school = School::create(['code' => 'SDHT', 'name' => 'SĐH Test']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'SDH', 'name' => 'Sau đại học']);
        $program = Program::create(['school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'QTKD', 'name' => 'QTKD Thạc sĩ']);

        $plo1 = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'PLO1']);
        $plo2 = Plo::create(['program_id' => $program->id, 'code' => 'PLO2', 'description' => 'PLO2']);
        $pi1 = Pi::create(['plo_id' => $plo1->id, 'code' => 'PI1', 'description' => 'PI1', 'is_dummy' => false, 'weight' => 1.0]);
        $pi2 = Pi::create(['plo_id' => $plo2->id, 'code' => 'PI2', 'description' => 'PI2', 'is_dummy' => false, 'weight' => 1.0]);

        foreach (self::COURSES as $code => $c) {
            $course = Course::create(['school_id' => $school->id, 'code' => $code, 'name' => $code, 'credits' => $c['tc']]);
            $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
            $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'CLO']);
            CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi1->id, 'mapping_level' => $c['plo1']]);
            CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi2->id, 'mapping_level' => $c['plo2']]);
        }

        return $program;
    }

    private function batch(): FileBatch
    {
        return FileBatch::create(['user_id' => User::factory()->create()->id, 'file_path' => 'x',
            'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
    }

    public function test_masters_total_grades_roll_up_to_pi_plo_ratios(): void
    {
        $program = $this->seedFramework();

        // Nạp điểm TỔNG qua importer THẬT (khóa 2024 → cờ 'total' → CLO→PI→PLO).
        $rows = [];
        foreach (self::GRADES as $mssv => $byCourse) {
            foreach ($byCourse as $code => $diem) {
                $rows[] = ['ma_nganh' => 'QTKD', 'nam_khoa' => 2024, 'ma_mon' => $code, 'mssv' => $mssv, 'diem' => $diem];
            }
        }
        (new AggregateGradeImport($this->batch(), $program->school_id))->collection(collect($rows));

        // Đo cả phạm vi (đúng như command nghiệm thu).
        app(ObeRecalculationService::class)->recalculateScope($program->school_id, $program->id, null);

        $hv1 = Student::where('student_code', 'HV1')->firstOrFail();
        $hv2 = Student::where('student_code', 'HV2')->firstOrFail();

        // HV1: PI1=101/130, PI2=149/190; PLO = PI tương ứng.
        $this->assertEqualsWithDelta(101 / 130, $this->pi($hv1, 'PI1'), 0.0005);
        $this->assertEqualsWithDelta(149 / 190, $this->pi($hv1, 'PI2'), 0.0005);
        $this->assertEqualsWithDelta(101 / 130, $this->plo($hv1, 'PLO1'), 0.0005);
        $this->assertEqualsWithDelta(149 / 190, $this->plo($hv1, 'PLO2'), 0.0005);

        // HV2 (yếu): PLO1 = 42/130 ≈ 0.323 (< 0.5).
        $this->assertEqualsWithDelta(42 / 130, $this->plo($hv2, 'PLO1'), 0.0005);

        // Mức đạt cấp chương trình: 1/2 HV đạt PLO1 (ngưỡng tỉ lệ 0.5) = 50%.
        $att = app(ObeAttainmentService::class)->ploAttainment($program->id, null);
        $this->assertTrue($att['is_ratio']);
        $this->assertEqualsWithDelta(0.5, (float) $att['threshold'], 0.0001);
        $plo1 = collect($att['plos'])->firstWhere('plo_code', 'PLO1');
        $this->assertSame(2, $plo1['students_measured']);
        $this->assertSame(1, $plo1['passed']);
        $this->assertEqualsWithDelta(50.0, (float) $plo1['attainment_percent'], 0.01);
    }

    private function pi(Student $s, string $code): float
    {
        return (float) $s->piResults()->whereHas('pi', fn ($q) => $q->where('code', $code))->firstOrFail()->achieved_score;
    }

    private function plo(Student $s, string $code): float
    {
        return (float) $s->ploResults()->whereHas('plo', fn ($q) => $q->where('code', $code))->firstOrFail()->achieved_score;
    }
}
