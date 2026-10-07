<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Cohort;
use App\Models\CqiAction;
use App\Models\Department;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPloResult;
use App\Services\OBE\CqiService;
use App\Services\OBE\ObeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature A3 — tự tạo việc cải tiến (CQI) cho PLO chưa đạt; idempotent.
 */
class CqiSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_one_action_per_missed_plo_and_is_idempotent(): void
    {
        ObeConfig::flush();

        $school = School::create(['code' => 'SCH-C', 'name' => 'CQI']);
        $dept = Department::create(['code' => 'IT', 'name' => 'CNTT', 'school_id' => $school->id]);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P1', 'name' => 'KTPM', 'school_id' => $school->id]);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $ploLow = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'chưa đạt']);
        $ploHigh = Plo::create(['program_id' => $program->id, 'code' => 'PLO2', 'description' => 'đạt']);

        // 10 SV: PLO1 6 đạt (60% < 70 → chưa đạt) ; PLO2 9 đạt (90% ≥ 70 → đạt).
        foreach (range(1, 10) as $i) {
            $student = Student::create([
                'student_code' => "SV$i", 'full_name' => "SV $i", 'school_id' => $school->id,
                'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying',
            ]);
            StudentPloResult::create(['student_id' => $student->id, 'plo_id' => $ploLow->id, 'achieved_score' => $i <= 6 ? 0.60 : 0.40]);
            StudentPloResult::create(['student_id' => $student->id, 'plo_id' => $ploHigh->id, 'achieved_score' => $i <= 9 ? 0.60 : 0.40]);
        }

        $cqi = app(CqiService::class);

        $created = $cqi->seedFromAttainment($program->id, $cohort->id);
        $this->assertSame(1, $created);
        $this->assertSame(1, CqiAction::count());
        $this->assertSame($ploLow->id, CqiAction::first()->plo_id);
        $this->assertEqualsWithDelta(60.0, (float) CqiAction::first()->attainment_percent, 0.01);

        // Seed lại → không tạo trùng (PLO1 đã có việc đang mở).
        $createdAgain = $cqi->seedFromAttainment($program->id, $cohort->id);
        $this->assertSame(0, $createdAgain);
        $this->assertSame(1, CqiAction::count());
    }
}
