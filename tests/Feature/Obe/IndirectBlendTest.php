<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Cohort;
use App\Models\Department;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPloResult;
use App\Models\SurveyInstrument;
use App\Models\SurveyPloResult;
use App\Services\OBE\ObeAttainmentService;
use App\Services\OBE\ObeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature A2 — trộn đo trực tiếp + gián tiếp (khảo sát).
 * - PLO có khảo sát: blended = 0.8·trực tiếp + 0.2·gián tiếp.
 * - PLO không khảo sát: giữ NGUYÊN (chỉ trực tiếp).
 */
class IndirectBlendTest extends TestCase
{
    use RefreshDatabase;

    public function test_indirect_blends_only_for_plos_with_survey(): void
    {
        ObeConfig::flush();

        $school = School::create(['code' => 'SCH-B', 'name' => 'Blend']); // weighted, trọng số mặc định 0.8/0.2
        $dept = Department::create(['code' => 'IT', 'name' => 'CNTT', 'school_id' => $school->id]);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P1', 'name' => 'KTPM', 'school_id' => $school->id]);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $plo1 = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'có khảo sát']);
        $plo2 = Plo::create(['program_id' => $program->id, 'code' => 'PLO2', 'description' => 'không khảo sát']);

        // 10 SV, 6 đạt (≥0.5) cho CẢ HAI PLO → trực tiếp 60%.
        foreach (range(1, 10) as $i) {
            $student = Student::create([
                'student_code' => "SV$i", 'full_name' => "SV $i", 'school_id' => $school->id,
                'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying',
            ]);
            foreach ([$plo1, $plo2] as $plo) {
                StudentPloResult::create([
                    'student_id' => $student->id, 'plo_id' => $plo->id,
                    'achieved_score' => $i <= 6 ? 0.60 : 0.40,
                ]);
            }
        }

        // Khảo sát chỉ cho PLO1: tỉ lệ 0.80 → gián tiếp 80%.
        $instrument = SurveyInstrument::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'cohort_id' => null,
            'stakeholder' => 'employer', 'likert_max' => 5,
        ]);
        SurveyPloResult::create([
            'survey_instrument_id' => $instrument->id, 'plo_id' => $plo1->id,
            'respondents' => 10, 'mean_likert' => 4.2, 'attainment_ratio' => 0.80,
        ]);

        $att = app(ObeAttainmentService::class)->ploAttainment($program->id, $cohort->id);
        $this->assertTrue($att['has_indirect']);

        $rows = collect($att['plos'])->keyBy('plo_code');

        // PLO1: blended = 0.8·60 + 0.2·80 = 64.
        $r1 = $rows['PLO1'];
        $this->assertSame(60.0, $r1['direct_percent']);
        $this->assertSame(80.0, $r1['indirect_percent']);
        $this->assertSame(64.0, $r1['blended_percent']);
        $this->assertSame(64.0, $r1['attainment_percent']);
        $this->assertTrue($r1['has_indirect']);

        // PLO2: không khảo sát → giữ trực tiếp 60, không trộn.
        $r2 = $rows['PLO2'];
        $this->assertSame(60.0, $r2['direct_percent']);
        $this->assertNull($r2['indirect_percent']);
        $this->assertNull($r2['blended_percent']);
        $this->assertSame(60.0, $r2['attainment_percent']);
        $this->assertFalse($r2['has_indirect']);
    }
}
