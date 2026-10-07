<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\User;
use App\Services\OBE\StructureBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructureBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function schoolId(): int
    {
        return School::firstOrCreate(['code' => 'SCH'], ['name' => 'Trường Test'])->id;
    }

    /** @return array<string,\Illuminate\Support\Collection> */
    private function pa3Sheets(): array
    {
        return [
            'Nganh' => collect([['ma_nganh' => 'CNTT', 'ten_nganh' => 'Công nghệ thông tin', 'khoa' => 'Khoa CNTT']]),
            'Khoa' => collect([['ma_nganh' => 'CNTT', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]]),
            'Mon' => collect([['ma_mon' => 'IT101', 'ten_mon' => 'Nhập môn lập trình', 'so_tin_chi' => 3, 'ma_nganh' => 'CNTT']]),
            'CLO' => collect([
                ['ma_mon' => 'IT101', 'ma_clo' => 'CLO1', 'mo_ta' => 'A'],
                ['ma_mon' => 'IT101', 'ma_clo' => 'CLO2', 'mo_ta' => 'B'],
            ]),
            'BaiDanhGia' => collect([
                ['ma_mon' => 'IT101', 'ten_bai_danh_gia' => 'Giữa kỳ', 'loai' => 'process', 'trong_so' => 40, 'clo' => 'CLO1'],
                ['ma_mon' => 'IT101', 'ten_bai_danh_gia' => 'Cuối kỳ', 'loai' => 'final', 'trong_so' => 60, 'clo' => 'CLO1,CLO2'],
            ]),
            'PLO' => collect([['ma_nganh' => 'CNTT', 'ma_plo' => 'PLO1', 'mo_ta' => 'P']]),
            'PI' => collect([['ma_nganh' => 'CNTT', 'ma_plo' => 'PLO1', 'ma_pi' => 'PI1.1', 'mo_ta' => 'I']]),
            'CLO_PI' => collect([
                ['ma_nganh' => 'CNTT', 'ma_mon' => 'IT101', 'ma_clo' => 'CLO1', 'ma_pi' => 'PI1.1', 'muc_do' => 'M'],
                ['ma_nganh' => 'CNTT', 'ma_mon' => 'IT101', 'ma_clo' => 'CLO2', 'ma_pi' => 'PI1.1', 'muc_do' => 'A'],
            ]),
            'Lop' => collect([['ma_lop' => 'IT101-2026A', 'ma_mon' => 'IT101', 'ten_khoa' => 'Khóa 2026', 'ma_gv' => 'GV001', 'nam_hoc' => '2026-2027', 'hoc_ky' => 1]]),
        ];
    }

    public function test_builds_full_framework_from_sheets(): void
    {
        User::factory()->create(['staff_code' => 'GV001']);

        $result = app(StructureBuilderService::class)->build($this->pa3Sheets(), $this->schoolId());

        $this->assertEmpty($result['errors'], 'Không được có lỗi: ' . json_encode($result['errors'], JSON_UNESCAPED_UNICODE));

        $this->assertDatabaseHas('programs', ['code' => 'CNTT']);
        $this->assertDatabaseHas('cohorts', ['name' => 'Khóa 2026', 'start_year' => 2026]);
        $this->assertDatabaseHas('courses', ['code' => 'IT101']);
        $this->assertSame(2, Clo::count());
        $this->assertSame(2, Assessment::count());
        $this->assertDatabaseHas('plos', ['code' => 'PLO1']);
        $this->assertDatabaseHas('pis', ['code' => 'PI1.1', 'is_dummy' => false]);
        $this->assertSame(2, CloPiMapping::count());
        $this->assertDatabaseHas('course_classes', ['class_code' => 'IT101-2026A', 'semester' => 1]);
    }

    public function test_two_schools_can_share_same_codes_without_collision(): void
    {
        User::factory()->create(['staff_code' => 'GV001']);
        $svc = app(StructureBuilderService::class);
        $a = School::create(['code' => 'A', 'name' => 'Trường A'])->id;
        $b = School::create(['code' => 'B', 'name' => 'Trường B'])->id;

        $ra = $svc->build($this->pa3Sheets(), $a);
        $rb = $svc->build($this->pa3Sheets(), $b); // CÙNG mã, trường khác

        $this->assertEmpty($ra['errors']);
        $this->assertEmpty($rb['errors'], 'Trường B trùng mã không được lỗi: ' . json_encode($rb['errors'], JSON_UNESCAPED_UNICODE));
        // Mỗi trường có bản ghi riêng dù trùng mã.
        $this->assertSame(2, Program::where('code', 'CNTT')->count());
        $this->assertSame(2, Course::where('code', 'IT101')->count());
        $this->assertSame(2, CourseClass::where('class_code', 'IT101-2026A')->count());
        // PI "PI1.1" của trường A map đúng PI của trường A (không vớ nhầm trường B).
        $piA = Pi::whereHas('plo.program', fn ($q) => $q->where('school_id', $a))->where('code', 'PI1.1')->first();
        $this->assertSame(2, CloPiMapping::where('pi_id', $piA->id)->count());
    }

    public function test_is_idempotent_on_rerun(): void
    {
        User::factory()->create(['staff_code' => 'GV001']);
        $svc = app(StructureBuilderService::class);
        $sid = $this->schoolId();

        $svc->build($this->pa3Sheets(), $sid);
        $svc->build($this->pa3Sheets(), $sid); // chạy lại

        $this->assertSame(1, Program::count());
        $this->assertSame(1, Cohort::count());
        $this->assertSame(1, Course::count());
        $this->assertSame(2, Clo::count());
        $this->assertSame(2, Assessment::count());
        $this->assertSame(1, Plo::count());
        $this->assertSame(1, Pi::count());
        $this->assertSame(2, CloPiMapping::count());
        $this->assertSame(1, CourseClass::count());
    }

    public function test_class_row_without_lecturer_reports_error(): void
    {
        $sheets = $this->pa3Sheets();
        $sheets['Lop'] = collect([['ma_lop' => 'X-01', 'ma_mon' => 'IT101', 'ten_khoa' => 'Khóa 2026', 'nam_hoc' => '2026-2027', 'hoc_ky' => 1]]);

        $result = app(StructureBuilderService::class)->build($sheets, $this->schoolId());

        $this->assertSame(0, CourseClass::count());
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('giảng viên', implode("\n", $result['errors']));
    }

    public function test_bai_danh_gia_clo_sheet_creates_mappings(): void
    {
        User::factory()->create(['staff_code' => 'GV001']);
        $sheets = $this->pa3Sheets();
        // "Giữa kỳ" ở sheet BaiDanhGia chỉ map CLO1 → thêm map CLO2 qua sheet phụ, có trọng số riêng.
        $sheets['BaiDanhGia_CLO'] = collect([
            ['ma_mon' => 'IT101', 'ten_bai_danh_gia' => 'Giữa kỳ', 'ma_clo' => 'CLO2', 'trong_so' => 50],
        ]);

        $result = app(StructureBuilderService::class)->build($sheets, $this->schoolId());
        $this->assertEmpty($result['errors'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));

        $mid = Assessment::where('name', 'Giữa kỳ')->value('id');
        $clo2 = Clo::where('code', 'CLO2')->value('id');
        $this->assertDatabaseHas('assessment_clo_mappings', [
            'assessment_id' => $mid, 'clo_id' => $clo2, 'weight_in_assessment' => 50,
        ]);
    }

    public function test_bai_danh_gia_clo_sheet_is_idempotent(): void
    {
        User::factory()->create(['staff_code' => 'GV001']);
        $sheets = $this->pa3Sheets();
        $sheets['BaiDanhGia_CLO'] = collect([
            ['ma_mon' => 'IT101', 'ten_bai_danh_gia' => 'Giữa kỳ', 'ma_clo' => 'CLO2'],
        ]);
        $svc = app(StructureBuilderService::class);
        $sid = $this->schoolId();

        $svc->build($sheets, $sid);
        $svc->build($sheets, $sid); // chạy lại

        $mid = Assessment::where('name', 'Giữa kỳ')->value('id');
        $clo2 = Clo::where('code', 'CLO2')->value('id');
        $this->assertSame(1, \App\Models\AssessmentCloMapping::where('assessment_id', $mid)->where('clo_id', $clo2)->count());
    }

    public function test_bai_danh_gia_clo_unknown_clo_reports_error(): void
    {
        User::factory()->create(['staff_code' => 'GV001']);
        $sheets = $this->pa3Sheets();
        $sheets['BaiDanhGia_CLO'] = collect([
            ['ma_mon' => 'IT101', 'ten_bai_danh_gia' => 'Giữa kỳ', 'ma_clo' => 'CLO9'],
        ]);

        $result = app(StructureBuilderService::class)->build($sheets, $this->schoolId());
        $this->assertStringContainsString('CLO9', implode("\n", $result['errors']));
    }

    /**
     * Khách, buổi họp lần 1: "CLO → PI ảo tự sinh — không có vụ này". Dựng khung không còn tự đẻ PI
     * cho PLO nào; PLO không chia PI được nối thẳng từ CLO ở phiếu lớp học phần (ClassSheetImportTest).
     */
    public function test_khung_khong_tu_sinh_pi_ao(): void
    {
        $sheets = [
            'Nganh' => collect([['ma_nganh' => 'KT', 'ten_nganh' => 'Kế toán', 'khoa' => 'Khoa KT']]),
            'Khoa' => collect([['ma_nganh' => 'KT', 'ten_khoa' => 'Khóa 2025', 'nam_bat_dau' => 2025]]),
            'PLO' => collect([['ma_nganh' => 'KT', 'ma_plo' => 'PLO1', 'mo_ta' => 'P']]),
        ];

        app(StructureBuilderService::class)->build($sheets, $this->schoolId());

        $this->assertDatabaseMissing('pis', ['is_dummy' => true]);
    }
}
