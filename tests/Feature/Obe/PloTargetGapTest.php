<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Cohort;
use App\Models\Department;
use App\Models\ObeTarget;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPloResult;
use App\Services\OBE\ObeAttainmentService;
use App\Services\OBE\ObeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature A1 — mục tiêu theo từng PLO + khoảng cách (gap).
 * - Không khai mục tiêu riêng → dùng global (70) → gap = 70 − %đạt, met=false khi dưới.
 * - Khai mục tiêu PLO = 55 → gap = 55 − %đạt, met=true khi vượt.
 */
class PloTargetGapTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_plo_target_overrides_global_and_computes_gap(): void
    {
        ObeConfig::flush();

        $school = School::create(['code' => 'SCH-T', 'name' => 'Target']); // weighted_attainment → ngưỡng 0.5, thang tỉ lệ
        $dept = Department::create(['code' => 'IT', 'name' => 'CNTT', 'school_id' => $school->id]);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P1', 'name' => 'KTPM', 'school_id' => $school->id]);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'PLO1']);

        // 10 SV: 6 đạt (≥0.5), 4 không → attainment = 60%.
        foreach (range(1, 10) as $i) {
            $student = Student::create([
                'student_code' => "SV$i", 'full_name' => "SV $i", 'school_id' => $school->id,
                'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying',
            ]);
            StudentPloResult::create([
                'student_id' => $student->id, 'plo_id' => $plo->id,
                'achieved_score' => $i <= 6 ? 0.60 : 0.40,
            ]);
        }

        $service = app(ObeAttainmentService::class);

        // (1) Chưa khai mục tiêu → global 70 → gap 10, chưa đạt.
        $row = $service->ploAttainment($program->id, $cohort->id)['plos'][0];
        $this->assertSame(60.0, $row['attainment_percent']);
        $this->assertSame(70.0, $row['target_percent']);
        $this->assertSame(10.0, $row['gap']);
        $this->assertFalse($row['met_target']);

        // (2) Khai mục tiêu PLO = 55 → gap −5, đạt.
        ObeTarget::create([
            'school_id' => $school->id, 'target_type' => 'plo', 'plo_id' => $plo->id, 'target_percent' => 55,
        ]);

        $row = $service->ploAttainment($program->id, $cohort->id)['plos'][0];
        $this->assertSame(55.0, $row['target_percent']);
        $this->assertSame(-5.0, $row['gap']);
        $this->assertTrue($row['met_target']);
    }

    /** Báo cáo tải về ghi "so với mục tiêu" cùng dấu với màn hình: thiếu là âm, vượt là dương. */
    public function test_bao_cao_ghi_so_voi_muc_tieu_cung_dau_voi_man_hinh(): void
    {
        $this->assertSame('-20', ObeAttainmentService::versusTarget(20.0));
        $this->assertSame('+30', ObeAttainmentService::versusTarget(-30.0));
        $this->assertSame('+5.5', ObeAttainmentService::versusTarget(-5.5));
        $this->assertSame('0', ObeAttainmentService::versusTarget(0.0));
        $this->assertSame('—', ObeAttainmentService::versusTarget(null));
    }
}
