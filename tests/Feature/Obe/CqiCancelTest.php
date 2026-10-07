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
use App\Models\User;
use App\Services\OBE\CqiService;
use App\Services\OBE\ObeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Huỷ việc CQI: thêm trạng thái 'cancelled' (giữ dòng, có lịch sử) — huỷ việc lỡ tạo nhầm.
 */
class CqiCancelTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Program, 1: Cohort, 2: Plo, 3: School} — 1 PLO chưa đạt (60% < 70). */
    private function seedMissedPlo(): array
    {
        ObeConfig::flush();
        $school = School::create(['code' => 'SCH-CX', 'name' => 'CQI Cancel']);
        $dept = Department::create(['code' => 'IT', 'name' => 'CNTT', 'school_id' => $school->id]);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P1', 'name' => 'KTPM', 'school_id' => $school->id]);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'chưa đạt']);

        foreach (range(1, 10) as $i) {
            $student = Student::create([
                'student_code' => "SV$i", 'full_name' => "SV $i", 'school_id' => $school->id,
                'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying',
            ]);
            StudentPloResult::create(['student_id' => $student->id, 'plo_id' => $plo->id, 'achieved_score' => $i <= 6 ? 0.60 : 0.40]);
        }

        return [$program, $cohort, $plo, $school];
    }

    public function test_cancelled_action_does_not_block_reseed(): void
    {
        [$program, $cohort] = $this->seedMissedPlo();
        $cqi = app(CqiService::class);

        $this->assertSame(1, $cqi->seedFromAttainment($program->id, $cohort->id));
        CqiAction::first()->update(['status' => 'cancelled']);

        // PLO đã huỷ việc → seed lại TẠO việc mới (idempotency chỉ chặn open/in_progress).
        $this->assertSame(1, $cqi->seedFromAttainment($program->id, $cohort->id));
        $this->assertSame(2, CqiAction::count());
        $this->assertSame(1, CqiAction::where('status', 'open')->count());
        $this->assertSame(1, CqiAction::where('status', 'cancelled')->count());
    }

    public function test_list_puts_cancelled_last(): void
    {
        [$program, $cohort, $plo, $school] = $this->seedMissedPlo();
        // 1 việc đã huỷ + 1 việc mở (cùng ngành/khóa).
        CqiAction::create(['school_id' => $school->id, 'program_id' => $program->id, 'cohort_id' => $cohort->id, 'plo_id' => $plo->id, 'status' => 'cancelled']);
        CqiAction::create(['school_id' => $school->id, 'program_id' => $program->id, 'cohort_id' => $cohort->id, 'plo_id' => $plo->id, 'status' => 'open']);

        $list = app(CqiService::class)->listWithCurrent($program->id, $cohort->id);

        $this->assertSame('cancelled', end($list)['status'], 'Việc đã huỷ phải nằm cuối');
        $this->assertNotSame('cancelled', $list[0]['status']);
    }

    private function admin(): User
    {
        foreach (['super_admin', 'lecturer', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('super_admin');
        return $admin;
    }

    public function test_index_returns_plo_state_summary(): void
    {
        $admin = $this->admin();
        [$program, $cohort, $ploLow] = $this->seedMissedPlo(); // PLO1: đo, 60% < 70 → chưa đạt
        $students = Student::where('program_id', $program->id)->get();

        // PLO2: đo, 80% ≥ 70 → đạt.
        $ploMet = Plo::create(['program_id' => $program->id, 'code' => 'PLO2', 'description' => 'đạt']);
        foreach ($students->values() as $idx => $st) {
            StudentPloResult::create(['student_id' => $st->id, 'plo_id' => $ploMet->id, 'achieved_score' => $idx < 8 ? 0.60 : 0.40]);
        }
        // PLO3: KHÔNG có kết quả → chưa đo.
        Plo::create(['program_id' => $program->id, 'code' => 'PLO3', 'description' => 'chưa đo']);

        $this->actingAs($admin)
            ->getJson(route('manager.reports.cqi.index', ['program_id' => $program->id, 'cohort_id' => $cohort->id]))
            ->assertOk()
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.measured', 2)
            ->assertJsonPath('summary.met', 1)
            ->assertJsonPath('summary.unmet', 1)
            ->assertJsonPath('summary.unmeasured', 1)
            ->assertJsonPath('missed_count', 1);
    }

    public function test_update_endpoint_accepts_cancelled_status(): void
    {
        $admin = $this->admin();

        [$program, $cohort, $plo, $school] = $this->seedMissedPlo();
        $action = CqiAction::create(['school_id' => $school->id, 'program_id' => $program->id, 'cohort_id' => $cohort->id, 'plo_id' => $plo->id, 'status' => 'open']);

        $this->actingAs($admin)
            ->patch(route('manager.reports.cqi.update', $action->uuid), ['status' => 'cancelled'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $action->fresh()->status);
    }
}
