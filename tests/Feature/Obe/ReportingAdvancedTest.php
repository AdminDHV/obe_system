<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Exports\AunQaReportExport;
use App\Models\Cohort;
use App\Models\Department;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Services\OBE\ObeAttainmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 44 — PI-level rollup (trước đây hoàn toàn ẩn) + Excel export nhiều sheet.
 * Số PI bám golden-master Sprint 39: PI1=0.6053, PI2=0.7778, PI3=0.7244.
 */
class ReportingAdvancedTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Program, 1: Cohort} */
    private function seedScenario(): array
    {
        $school = School::create(['code' => 'SCH-R', 'name' => 'Reporting']); // mặc định weighted → ngưỡng 0.5, is_ratio
        $dept = Department::create(['school_id' => $school->id, 'code' => 'D', 'name' => 'Khoa']);
        $program = Program::create(['school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'PGR', 'name' => 'CTĐT']);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $student = Student::create(['student_code' => 'SV1', 'full_name' => 'SV1', 'school_id' => $school->id, 'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying']);

        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'PLO1']);
        $pis = [
            'PI1' => 0.6053, 'PI2' => 0.7778, 'PI3' => 0.7244,
        ];
        foreach ($pis as $code => $score) {
            $pi = Pi::create(['plo_id' => $plo->id, 'code' => $code, 'description' => $code, 'is_dummy' => false, 'weight' => 0.33]);
            StudentPiResult::create(['student_id' => $student->id, 'pi_id' => $pi->id, 'achieved_score' => $score]);
        }
        StudentPloResult::create(['student_id' => $student->id, 'plo_id' => $plo->id, 'achieved_score' => 0.70466]);

        return [$program, $cohort];
    }

    public function test_pi_attainment_rolls_up_client_numbers(): void
    {
        [$program, $cohort] = $this->seedScenario();

        $res = app(ObeAttainmentService::class)->piAttainment($program->id, $cohort->id);

        $this->assertTrue($res['is_ratio']);
        $byCode = collect($res['pis'])->keyBy('pi_code');
        $this->assertEqualsWithDelta(0.6053, (float) $byCode['PI1']['avg_score'], 0.0005);
        $this->assertEqualsWithDelta(0.7778, (float) $byCode['PI2']['avg_score'], 0.0005);
        $this->assertEqualsWithDelta(0.7244, (float) $byCode['PI3']['avg_score'], 0.0005);
        // 1 SV, mọi PI ≥ 0.5 → 100% đạt.
        $this->assertSame(100.0, $byCode['PI1']['attainment_percent']);
        $this->assertSame('PLO1', $byCode['PI1']['plo_code']);
    }

    public function test_excel_export_has_all_sheets_with_data(): void
    {
        [$program, $cohort] = $this->seedScenario();

        $sheets = (new AunQaReportExport($program->id, $cohort->id))->sheets();

        $titles = array_map(fn ($s) => $s->title(), $sheets);
        $this->assertSame([
            'Tổng quan', 'Mức đạt PLO', 'Mức đạt PI',
            'Ma trận SV x PLO', 'Ma trận SV x PI', 'Kết quả CLO', 'Điểm tổng kết học phần',
            'Tiến độ tích lũy',
        ], $titles);

        // Sheet PI có 3 dòng (PI1/PI2/PI3).
        $piSheet = $sheets[2];
        $this->assertCount(3, $piSheet->array());
        $this->assertContains('Mã PI', $piSheet->headings());

        // Sheet PLO có PLO1 với % đạt 100 (1 SV ratio 0.70466 ≥ 0.5).
        $ploRows = $sheets[1]->array();
        $this->assertSame('PLO1', $ploRows[0][0]);
        $this->assertEquals(100.0, $ploRows[0][4]);

        // Ma trận SV×PLO có đúng 1 dòng sinh viên.
        $this->assertCount(1, $sheets[3]->array());
    }
}
