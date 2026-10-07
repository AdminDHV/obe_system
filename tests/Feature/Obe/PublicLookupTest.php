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
use App\Services\OBE\StudentLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * TRA CỨU CÔNG KHAI bằng MSSV + mã tra cứu.
 *
 * Đây là endpoint KHÔNG xác thực trả về dữ liệu cá nhân, nên phần quan trọng nhất của test không
 * phải "tra được" mà là "KHÔNG tra được của người khác" và "không dò quét được".
 *
 * Lý do phải có mã: mã học viên tuần tự và công khai (2312HV9001, 1003, 1004…), đếm lên là ra cả
 * khóa; dữ liệu lại không có ngày sinh/CCCD/điện thoại để ghép làm yếu tố thứ hai.
 */
class PublicLookupTest extends TestCase
{
    use RefreshDatabase;

    private Student $an;
    private Student $binh;
    private Plo $plo;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
        School::query()->forceDelete();

        $school = School::create(['code' => 'S', 'name' => 'Trường', 'is_active' => true]);
        $dept = Department::create(['code' => 'D', 'name' => 'Khoa', 'school_id' => $school->id]);
        $program = Program::create([
            'department_id' => $dept->id, 'code' => 'P', 'name' => 'Ngành',
            'school_id' => $school->id, 'required_credits' => 30,
        ]);
        $cohort = Cohort::create([
            'program_id' => $program->id, 'name' => 'K2023', 'start_year' => 2023,
            'school_id' => $school->id,
        ]);
        $this->plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'x']);

        $this->an = $this->makeStudent($school, $program, $cohort, '2312HV9001', 'An', 0.82);
        $this->binh = $this->makeStudent($school, $program, $cohort, '2312HV9002', 'Bình', 0.41);
    }

    public function test_tra_dung_ma_thi_xem_duoc_ket_qua_cua_minh(): void
    {
        $code = app(StudentLookupService::class)->ensureCode($this->an);

        $this->post(route('lookup.submit'), [
            'student_code' => '2312HV9001',
            'lookup_code' => $code,
        ])->assertInertia(fn (Assert $page) => $page
            ->component('Public/Lookup')
            ->where('result.student.full_name', 'An')
            ->where('result.plos.0.code', 'PLO1')
            ->where('result.plos.0.passed', true));
    }

    public function test_KHONG_tra_duoc_bang_moi_MSSV(): void
    {
        app(StudentLookupService::class)->ensureCode($this->an);

        // Đây chính là kịch bản dò quét: biết mã học viên (công khai) nhưng không có mã tra cứu.
        $this->post(route('lookup.submit'), [
            'student_code' => '2312HV9001',
            'lookup_code' => 'SAIMAAAA',
        ])->assertSessionHasErrors('lookup_code');
    }

    public function test_KHONG_dung_ma_cua_nguoi_nay_de_xem_nguoi_khac(): void
    {
        $maCuaAn = app(StudentLookupService::class)->ensureCode($this->an);
        app(StudentLookupService::class)->ensureCode($this->binh);

        $this->post(route('lookup.submit'), [
            'student_code' => '2312HV9002',   // MSSV của Bình
            'lookup_code' => $maCuaAn,        // mã của An
        ])->assertSessionHasErrors('lookup_code');
    }

    public function test_bao_loi_GIONG_NHAU_du_MSSV_co_that_hay_khong(): void
    {
        app(StudentLookupService::class)->ensureCode($this->an);

        $loi = fn (string $mssv) => $this->post(route('lookup.submit'), [
            'student_code' => $mssv, 'lookup_code' => 'SAIMAAAA',
        ])->assertSessionHasErrors('lookup_code')
            ->getSession()->get('errors')->get('lookup_code');

        // Không được để người dò suy ra "MSSV này có tồn tại" từ nội dung thông báo.
        $this->assertSame($loi('2312HV9001'), $loi('9999KHONGCO'));
    }

    public function test_hoc_vien_chua_duoc_cap_ma_thi_khong_tra_duoc(): void
    {
        // lookup_code NULL — không được coi "chuỗi rỗng khớp NULL".
        $this->assertNull($this->binh->lookup_code);

        $this->post(route('lookup.submit'), [
            'student_code' => '2312HV9002', 'lookup_code' => '',
        ])->assertSessionHasErrors();
    }

    public function test_ma_sinh_ra_la_duy_nhat_va_goi_lai_khong_doi(): void
    {
        $service = app(StudentLookupService::class);

        $lan1 = $service->ensureCode($this->an);
        $lan2 = $service->ensureCode($this->an->fresh());

        $this->assertSame($lan1, $lan2, 'Gọi lại không được cấp mã mới');
        $this->assertNotSame($lan1, $service->ensureCode($this->binh));
        $this->assertSame(8, strlen($lan1));
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/', $lan1);
    }

    public function test_ma_duoc_in_len_phieu_ket_qua(): void
    {
        $admin = \App\Models\User::factory()->create(['is_active' => true]);
        $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web'],
        ));

        $csv = $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->an->school_id)
            ->get(route('manager.students.export-plo', $this->an))
            ->assertOk()
            ->streamedContent();

        // Xuất phiếu là cấp mã luôn ⇒ mã luôn có trên tờ giấy học viên nhận.
        $code = $this->an->fresh()->lookup_code;
        $this->assertNotNull($code);
        $this->assertStringContainsString(app(StudentLookupService::class)->format($code), $csv);
        $this->assertStringContainsString('ket-qua-hoc-tap', $csv);
    }

    public function test_duong_dan_CU_tren_phieu_da_in_van_dan_dung_cho(): void
    {
        // 42 phiếu đã in mang /tra-cuu — đổi đường dẫn mà bỏ rơi chúng là làm hỏng giấy đã phát.
        $this->get('/tra-cuu')
            ->assertRedirect('/ket-qua-hoc-tap')
            ->assertStatus(301);
    }

    public function test_cap_lai_ma_lam_ma_cu_HET_hieu_luc(): void
    {
        $service = app(StudentLookupService::class);
        $maCu = $service->ensureCode($this->an);

        $admin = \App\Models\User::factory()->create(['is_active' => true]);
        $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web'],
        ));

        $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->an->school_id)
            ->put(route('manager.students.lookup-code', $this->an))
            ->assertRedirect();

        $maMoi = $this->an->fresh()->lookup_code;
        $this->assertNotSame($maCu, $maMoi, 'Phải là mã khác');

        // Mã cũ KHÔNG được dùng nữa — đây là mục đích của việc cấp lại.
        $this->post(route('lookup.submit'), [
            'student_code' => '2312HV9001', 'lookup_code' => $maCu,
        ])->assertSessionHasErrors('lookup_code');

        $this->post(route('lookup.submit'), [
            'student_code' => '2312HV9001', 'lookup_code' => $maMoi,
        ])->assertInertia(fn (Assert $page) => $page->where('result.student.full_name', 'An'));
    }

    public function test_khong_cap_lai_duoc_ma_cua_sinh_vien_truong_khac(): void
    {
        $khac = School::create(['code' => 'S2', 'name' => 'Trường 2', 'is_active' => true]);
        $admin = \App\Models\User::factory()->create(['is_active' => true]);
        $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web'],
        ));

        $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $khac->id)
            ->put(route('manager.students.lookup-code', $this->an))
            ->assertNotFound();
    }

    public function test_ma_tra_cuu_KHONG_bi_chep_vao_nhat_ky_kiem_toan(): void
    {
        $admin = \App\Models\User::factory()->create(['is_active' => true]);
        $this->actingAs($admin);

        $ma = app(StudentLookupService::class)->ensureCode($this->an);
        app(StudentLookupService::class)->regenerate($this->an);

        // Nhật ký mà chứa mã thì ai đọc được nhật ký sẽ tra được kết quả của cả trường.
        $moiGiaTri = \App\Models\AuditLog::where('model_type', 'Student')->pluck('new_values')
            ->concat(\App\Models\AuditLog::where('model_type', 'Student')->pluck('old_values'))
            ->map(fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE))
            ->implode(' ');

        $this->assertStringNotContainsString($ma, $moiGiaTri, 'Mã cũ lọt vào nhật ký');
        $this->assertStringNotContainsString(
            (string) $this->an->fresh()->lookup_code, $moiGiaTri, 'Mã mới lọt vào nhật ký',
        );

        // Nhưng vẫn phải biết AI đã thu hồi mã của ai.
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'lookup_code_regenerated',
            'model_id' => $this->an->id,
        ]);
    }

    public function test_lenh_cap_ma_hang_loat_ghi_ra_FILE_khong_in_ra_man_hinh(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->artisan('obe:lookup-codes --school=S')
            ->expectsOutputToContain('2 học viên')
            ->assertOk();

        $this->assertNotNull($this->an->fresh()->lookup_code);
        $this->assertNotNull($this->binh->fresh()->lookup_code);

        $files = \Illuminate\Support\Facades\Storage::disk('local')->files('lookup-codes');
        $this->assertCount(1, $files);

        $csv = \Illuminate\Support\Facades\Storage::disk('local')->get($files[0]);
        $this->assertStringContainsString('2312HV9001', $csv);
        $this->assertStringContainsString(
            app(StudentLookupService::class)->format($this->an->fresh()->lookup_code),
            $csv,
        );
    }

    public function test_lenh_bao_loi_ro_khi_thieu_hoac_sai_truong(): void
    {
        $this->artisan('obe:lookup-codes')->assertFailed();
        $this->artisan('obe:lookup-codes --school=KHONGCO')->assertFailed();
    }

    private function makeStudent(School $school, Program $program, Cohort $cohort, string $code, string $name, float $score): Student
    {
        $student = Student::create([
            'student_code' => $code, 'full_name' => $name, 'school_id' => $school->id,
            'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying',
        ]);
        StudentPloResult::create([
            'student_id' => $student->id, 'plo_id' => $this->plo->id,
            'achieved_score' => $score, 'level' => 3,
        ]);

        return $student;
    }
}
