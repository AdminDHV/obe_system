<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Cohort;
use App\Models\Department;
use App\Models\MeasurementSnapshot;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Models\User;
use App\Services\OBE\MeasurementSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CHỐT SỔ — trả lời "mức đạt tính đến thời điểm X".
 *
 * Ba bảng kết quả chỉ giữ TRẠNG THÁI MỚI NHẤT (upsert ghi đè, xoá cứng dòng cũ, không Auditable),
 * nên báo cáo đã gửi cho trường sẽ biến mất sau lần "Tính lại" kế tiếp. Bản chốt phải sống độc lập
 * với dữ liệu hiện tại.
 */
class MeasurementSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Program $program;
    private Cohort $cohort;
    private School $school;
    private User $admin;
    private Student $sv1;
    private Student $sv2;
    private Plo $plo;
    private Pi $pi;

    protected function setUp(): void
    {
        parent::setUp();
        School::query()->forceDelete();

        $this->school = School::create(['code' => 'S', 'name' => 'Trường', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $dept = Department::create(['code' => 'D', 'name' => 'Khoa', 'school_id' => $this->school->id]);
        $this->program = Program::create([
            'department_id' => $dept->id, 'code' => 'P', 'name' => 'Ngành',
            'school_id' => $this->school->id, 'required_credits' => 10,
        ]);
        $this->cohort = Cohort::create([
            'program_id' => $this->program->id, 'name' => 'K2023', 'start_year' => 2023,
            'school_id' => $this->school->id,
        ]);
        $this->plo = Plo::create(['program_id' => $this->program->id, 'code' => 'PLO1', 'description' => 'x']);
        $this->pi = Pi::create(['plo_id' => $this->plo->id, 'code' => 'PI1.1', 'description' => 'x', 'is_dummy' => false]);

        $this->sv1 = $this->makeStudent('SV1', 'A', 0.80);
        $this->sv2 = $this->makeStudent('SV2', 'B', 0.40);
    }

    public function test_ban_chot_giu_nguyen_so_du_da_do_lai(): void
    {
        $snapshot = app(MeasurementSnapshotService::class)
            ->take($this->program->id, $this->cohort->id, label: 'Gửi trường 04/08');

        $this->assertSame(2, $snapshot->students_total);

        // Đo lại: điểm đổi hoàn toàn, kể cả xoá bớt kết quả.
        StudentPloResult::where('student_id', $this->sv1->id)->update(['achieved_score' => 0.10]);
        StudentPloResult::where('student_id', $this->sv2->id)->delete();
        StudentPiResult::where('student_id', $this->sv1->id)->update(['achieved_score' => 0.10]);

        $restored = app(MeasurementSnapshotService::class)->restore($snapshot->fresh());

        $sv1 = collect($restored['students'])->firstWhere('student_code', 'SV1');
        $sv2 = collect($restored['students'])->firstWhere('student_code', 'SV2');
        $this->assertSame(0.8, $sv1['plo']['PLO1'], 'Bản chốt phải giữ số của thời điểm chốt');
        $this->assertSame(0.4, $sv2['plo']['PLO1'], 'Kết quả bị xoá sau đó vẫn còn trong bản chốt');

        // Mức đạt chung tính lại TỪ bản chốt: SV1 0.8 đạt, SV2 0.4 không ⇒ 1/2 = 50%
        $this->assertSame(50.0, $restored['plo_summary'][0]['attainment_percent']);
    }

    public function test_chot_so_ton_tai_ke_ca_khi_sinh_vien_bi_xoa(): void
    {
        $snapshot = app(MeasurementSnapshotService::class)->take($this->program->id, $this->cohort->id);

        $this->sv2->forceDelete();

        $restored = app(MeasurementSnapshotService::class)->restore($snapshot->fresh());
        $this->assertCount(2, $restored['students'], 'Bản chốt lưu theo MÃ SV nên xoá SV không mất dòng');
        $this->assertSame('SV2', $restored['students'][1]['student_code']);
    }

    public function test_xuat_bang_tong_tu_dong_chot_so_va_xem_lai_duoc(): void
    {
        $this->admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web'],
        ));

        $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->school->id)
            ->get(route('manager.students.export-class-summary', [
                'program_id' => $this->program->id,
                'cohort_id' => $this->cohort->id,
                'snapshot_label' => 'Bản gửi trường',
            ]))
            ->assertOk()
            ->streamedContent();

        $snapshot = MeasurementSnapshot::firstOrFail();
        $this->assertSame('Bản gửi trường', $snapshot->label);

        // Số hiện tại đổi sau đó…
        StudentPloResult::where('student_id', $this->sv1->id)->update(['achieved_score' => 0.99]);

        // …nhưng xem lại bản đã chốt thì vẫn ra số cũ.
        $csv = $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->school->id)
            ->get(route('manager.students.export-class-summary', ['snapshot' => $snapshot->uuid]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Bản gửi trường', $csv);
        $this->assertStringContainsString('80.0%', $csv);
        $this->assertStringNotContainsString('99.0%', $csv);
    }

    public function test_khong_xem_duoc_ban_chot_cua_truong_khac(): void
    {
        $snapshot = app(MeasurementSnapshotService::class)->take($this->program->id, $this->cohort->id);
        $khac = School::create(['code' => 'S2', 'name' => 'Trường 2', 'is_active' => true]);
        $this->admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web'],
        ));

        $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $khac->id)
            ->get(route('manager.students.export-class-summary', ['snapshot' => $snapshot->uuid]))
            ->assertNotFound();
    }

    private function makeStudent(string $code, string $name, float $score): Student
    {
        $student = Student::create([
            'student_code' => $code, 'full_name' => $name, 'school_id' => $this->school->id,
            'cohort_id' => $this->cohort->id, 'program_id' => $this->program->id, 'status' => 'studying',
        ]);
        StudentPloResult::create([
            'student_id' => $student->id, 'plo_id' => $this->plo->id,
            'achieved_score' => $score, 'level' => 3,
        ]);
        StudentPiResult::create([
            'student_id' => $student->id, 'pi_id' => $this->pi->id, 'achieved_score' => $score,
        ]);

        return $student;
    }
}
