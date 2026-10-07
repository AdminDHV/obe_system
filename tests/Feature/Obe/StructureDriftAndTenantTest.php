<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\CohortPiWeight;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPloResult;
use App\Models\User;
use App\Services\OBE\StructureBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hai rủi ro khi dùng hệ thống cho NHIỀU KHÓA và NHIỀU TRƯỜNG.
 *
 * 1. PLO/PI gắn vào NGÀNH chứ không gắn vào KHÓA, và nạp khung là `updateOrCreate` đè lên chính
 *    những dòng khóa cũ đang dùng ⇒ khai lại trọng số PI cho khóa mới sẽ đổi kết quả khóa cũ ở
 *    lần "Tính lại" kế tiếp. Chưa sửa được về mặt mô hình dữ liệu (cần phiên bản khung theo khóa),
 *    nên tối thiểu phải CẢNH BÁO.
 * 2. Tra giảng viên theo `staff_code` từng KHÔNG lọc trường ⇒ khung của trường mình trỏ vào mã GV
 *    của trường khác thì lớp gắn nhầm giảng viên trường bạn. Nay fail-closed.
 *    📌 `users.staff_code` đang UNIQUE TOÀN HỆ THỐNG (không phải theo trường như programs/courses/
 *    students/course_classes) — hai trường KHÔNG thể cùng dùng mã "GV01". Đây là hạn chế thật khi
 *    chạy nhiều trường, chưa sửa vì đổi ràng buộc unique cần quyết định riêng.
 */
class StructureDriftAndTenantTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        School::query()->forceDelete();
        $this->school = School::create(['code' => 'S1', 'name' => 'Trường 1', 'is_active' => true]);
    }

    public function test_canh_bao_khi_khung_moi_doi_co_so_do_cua_khoa_da_co_ket_qua(): void
    {
        $builder = app(StructureBuilderService::class);

        // Lần 1: dựng khung, PI1.1 trọng số 0.4
        $first = $builder->build($this->sheets(piWeight: 0.4), $this->school->id);
        $this->assertSame([], $first['warnings'], 'Chưa đo lần nào thì sửa khung là bình thường');

        // Khóa 2023 đã đo xong: có kết quả PLO
        $this->giveExistingResults();

        // Lần 2: khóa mới khai trọng số khác → ĐÈ lên cùng dòng PI mà khóa 2023 đang dùng
        $second = app(StructureBuilderService::class)->build($this->sheets(piWeight: 0.9), $this->school->id);

        $this->assertNotEmpty($second['warnings'], 'Phải cảnh báo khi cơ sở đo bị đổi');
        $warning = $second['warnings'][0];
        $this->assertStringContainsString('K2023', $warning, 'Phải nêu đích danh khóa bị ảnh hưởng');
        $this->assertStringContainsString('trọng số PI1.1: 0.4000 → 0.9', $warning);
        $this->assertStringContainsString('Tính lại', $warning);
    }

    public function test_doi_muc_I_R_M_A_cung_bi_canh_bao(): void
    {
        app(StructureBuilderService::class)->build($this->sheets(level: 'R'), $this->school->id);
        $this->giveExistingResults();

        $res = app(StructureBuilderService::class)->build($this->sheets(level: 'M'), $this->school->id);

        $this->assertNotEmpty($res['warnings']);
        $this->assertStringContainsString('C1/K2023 · CLO1 → PI1.1: R → M', $res['warnings'][0]);
    }

    public function test_nap_lai_khung_y_het_thi_khong_canh_bao_bay(): void
    {
        app(StructureBuilderService::class)->build($this->sheets(), $this->school->id);
        $this->giveExistingResults();

        $res = app(StructureBuilderService::class)->build($this->sheets(), $this->school->id);

        $this->assertSame([], $res['warnings'], 'Nạp lại đúng khung cũ thì không được kêu');
    }

    public function test_khong_muon_giang_vien_cua_truong_khac(): void
    {
        $khac = School::create(['code' => 'S2', 'name' => 'Trường 2', 'is_active' => true]);
        $deptKhac = Department::create(['code' => 'DK', 'name' => 'Khoa', 'school_id' => $khac->id]);

        // `users.staff_code` UNIQUE TOÀN HỆ THỐNG nên hai trường không thể trùng mã GV; rủi ro
        // thật là khung của trường mình trỏ vào mã GV đang thuộc trường KHÁC.
        $gvTruongKhac = User::factory()->create([
            'staff_code' => 'GV01', 'school_id' => $khac->id, 'department_id' => $deptKhac->id, 'is_active' => true,
        ]);

        $res = app(StructureBuilderService::class)->build($this->sheets(), $this->school->id);

        // Fail-closed: thà báo lỗi còn hơn gắn lớp cho giảng viên trường bạn.
        $this->assertNull(CourseClass::where('school_id', $this->school->id)->first());
        $this->assertNotEmpty($res['errors']);
        $this->assertStringContainsString("không tìm thấy giảng viên có mã 'GV01'", implode(' ', $res['errors']));
        $this->assertSame($khac->id, $gvTruongKhac->fresh()->school_id, 'Không được đụng vào GV trường khác');
    }

    public function test_van_nhan_giang_vien_chua_duoc_gan_truong(): void
    {
        // Dữ liệu cũ còn nhiều tài khoản GV mồ côi (school_id NULL) — không được chặn cứng.
        $orphan = User::factory()->create(['staff_code' => 'GV01', 'school_id' => null, 'is_active' => true]);

        app(StructureBuilderService::class)->build($this->sheets(), $this->school->id);

        $this->assertSame($orphan->id, CourseClass::where('school_id', $this->school->id)->value('lecturer_id'));
    }

    public function test_khoa_moi_KHONG_de_len_CLO_va_ma_tran_cua_khoa_cu(): void
    {
        $this->makeLecturer();

        // Khóa 2023 dựng khung, mức R
        app(StructureBuilderService::class)->build($this->sheets(level: 'R'), $this->school->id);
        $syl2023 = CourseClass::where('class_code', 'L1')->value('syllabus_id');
        $clo2023 = Clo::where('syllabus_id', $syl2023)->where('code', 'CLO1')->firstOrFail();

        // Khóa 2025 dựng khung CÙNG môn nhưng mức M
        app(StructureBuilderService::class)->build(
            $this->sheets(level: 'M', cohort: 'K2025', year: 2025, classCode: 'L2'),
            $this->school->id,
        );

        $syl2025 = CourseClass::where('class_code', 'L2')->value('syllabus_id');
        $this->assertNotSame($syl2023, $syl2025, 'Mỗi khóa phải có phiên bản đề cương riêng');
        $this->assertSame('K2025', CourseSyllabus::find($syl2025)->version);

        // Mức đo của khóa 2023 phải NGUYÊN VẸN
        $mucCu = CloPiMapping::where('clo_id', $clo2023->id)->value('mapping_level');
        $this->assertSame('R', $mucCu, 'Khung khóa 2025 không được đổi mức đo của khóa 2023');

        $clo2025 = Clo::where('syllabus_id', $syl2025)->where('code', 'CLO1')->firstOrFail();
        $this->assertSame('M', CloPiMapping::where('clo_id', $clo2025->id)->value('mapping_level'));
        $this->assertNotSame($clo2023->id, $clo2025->id, 'CLO của hai khóa là hai dòng khác nhau');
    }

    public function test_nap_lai_cung_khoa_khong_de_ra_phien_ban_moi(): void
    {
        $this->makeLecturer();
        app(StructureBuilderService::class)->build($this->sheets(), $this->school->id);
        $syl = CourseClass::where('class_code', 'L1')->value('syllabus_id');

        app(StructureBuilderService::class)->build($this->sheets(), $this->school->id);

        $this->assertSame($syl, CourseClass::where('class_code', 'L1')->value('syllabus_id'));
        $this->assertSame(1, CourseSyllabus::count(), 'Nạp lại cùng khóa không được sinh phiên bản mới');
    }

    public function test_nhan_lai_de_cuong_1_0_cua_truong_dang_chay_thay_vi_bo_roi(): void
    {
        // Mô phỏng trường ĐÃ chạy trước khi có đánh phiên bản: đề cương '1.0' + lớp trỏ vào đó.
        $lecturer = $this->makeLecturer();
        app(StructureBuilderService::class)->build($this->sheets(), $this->school->id);

        $syl = CourseSyllabus::firstOrFail();
        $syl->update(['version' => '1.0']);   // kéo về trạng thái dữ liệu cũ

        // Nạp lại khung của CHÍNH khóa đó ⇒ phải dùng lại '1.0', không bỏ rơi kết quả đo cũ.
        app(StructureBuilderService::class)->build($this->sheets(), $this->school->id);

        $this->assertSame(1, CourseSyllabus::count(), 'Không được đẻ phiên bản thứ hai cho cùng khóa');
        $this->assertSame('1.0', CourseSyllabus::firstOrFail()->version);
        $this->assertSame($syl->id, CourseClass::where('class_code', 'L1')->value('syllabus_id'));
    }

    public function test_khoa_khac_co_muc_do_khac_KHONG_sinh_canh_bao_ma(): void
    {
        $this->makeLecturer();

        // Khóa 2023 mức R, đã đo xong.
        app(StructureBuilderService::class)->build($this->sheets(level: 'R'), $this->school->id);
        $this->giveExistingResults();

        // Khóa 2025 khai mức M — hợp lệ, vì đã đóng băng theo khóa nên KHÔNG đụng khóa 2023.
        app(StructureBuilderService::class)->build(
            $this->sheets(level: 'M', cohort: 'K2025', year: 2025, classCode: 'L2'),
            $this->school->id,
        );

        // Nạp lại đúng khung khóa 2023 ⇒ không được kêu chỉ vì khóa 2025 để mức khác.
        $res = app(StructureBuilderService::class)->build($this->sheets(level: 'R'), $this->school->id);

        $this->assertSame([], $res['warnings'], 'Mức đo của khóa KHÁC không phải là trôi cơ sở đo');
    }

    public function test_trong_so_PI_dong_bang_theo_khoa_khoa_moi_khong_doi_so_khoa_cu(): void
    {
        $this->makeLecturer();

        // Khóa 2023 khai trọng số PI1.1 = 0.4 rồi đo xong.
        app(StructureBuilderService::class)->build($this->sheets(piWeight: 0.4), $this->school->id);
        $cohort2023 = Cohort::where('name', 'K2023')->firstOrFail();
        $pi = Pi::where('code', 'PI1.1')->firstOrFail();

        $this->assertSame(
            '0.4000',
            CohortPiWeight::where('cohort_id', $cohort2023->id)->where('pi_id', $pi->id)->value('weight'),
            'Nạp khung phải đóng băng trọng số cho khóa đang khai',
        );

        // Khóa 2025 khai trọng số KHÁC — đây chính là kịch bản từng làm PLO khóa cũ nhảy số.
        app(StructureBuilderService::class)->build(
            $this->sheets(piWeight: 0.9, cohort: 'K2025', year: 2025, classCode: 'L2'),
            $this->school->id,
        );

        // Trọng số đóng băng của khóa 2023 KHÔNG được đổi…
        $this->assertSame(
            '0.4000',
            CohortPiWeight::where('cohort_id', $cohort2023->id)->where('pi_id', $pi->id)->value('weight'),
        );
        // …dù mặc định của ngành đã bị khóa mới ghi đè (đúng như thiết kế).
        $this->assertSame('0.9000', $pi->fresh()->weight);
        $this->assertSame(
            '0.9000',
            CohortPiWeight::where('cohort_id', Cohort::where('name', 'K2025')->value('id'))
                ->where('pi_id', $pi->id)->value('weight'),
        );
    }

    private function makeLecturer(): User
    {
        return User::factory()->create([
            'staff_code' => 'GV01', 'school_id' => $this->school->id, 'is_active' => true,
        ]);
    }

    /** Khóa 2023 đã đo xong: 1 học viên có kết quả PLO. */
    private function giveExistingResults(): void
    {
        $program = Program::where('school_id', $this->school->id)->firstOrFail();
        $cohort = Cohort::where('school_id', $this->school->id)->firstOrFail();
        $student = Student::create([
            'student_code' => 'SV1', 'full_name' => 'A', 'school_id' => $this->school->id,
            'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying',
        ]);
        StudentPloResult::create([
            'student_id' => $student->id,
            'plo_id' => Plo::where('program_id', $program->id)->value('id'),
            'achieved_score' => 0.8,
            'level' => 3,
        ]);
    }

    /** Khung tối thiểu 1 ngành · 1 khóa · 1 môn · 1 CLO · 1 PLO · 1 PI · 1 lớp. */
    private function sheets(float $piWeight = 0.4, string $level = 'R', string $cohort = 'K2023', int $year = 2023, string $classCode = 'L1'): array
    {
        Department::firstOrCreate(
            ['code' => 'D', 'school_id' => $this->school->id],
            ['name' => 'Khoa'],
        );

        return [
            'Nganh' => collect([['ma_nganh' => 'P', 'ten_nganh' => 'Ngành', 'khoa' => 'Khoa']]),
            'Khoa' => collect([['ma_nganh' => 'P', 'ten_khoa' => $cohort, 'nam_bat_dau' => $year, 'do_min_diem' => 'question']]),
            'Mon' => collect([['ma_mon' => 'C1', 'ten_mon' => 'Môn', 'so_tin_chi' => 3, 'ma_nganh' => 'P']]),
            'CLO' => collect([['ma_mon' => 'C1', 'ma_clo' => 'CLO1', 'mo_ta' => 'x']]),
            'BaiDanhGia' => collect([['ma_mon' => 'C1', 'ten_bai_danh_gia' => 'Cuối kỳ', 'loai' => 'final', 'trong_so' => 100]]),
            'BaiDanhGia_CLO' => collect(),
            'PLO' => collect([['ma_nganh' => 'P', 'ma_plo' => 'PLO1', 'mo_ta' => 'x']]),
            'PI' => collect([['ma_nganh' => 'P', 'ma_plo' => 'PLO1', 'ma_pi' => 'PI1.1', 'mo_ta' => 'x', 'trong_so' => $piWeight]]),
            'CLO_PI' => collect([['ma_nganh' => 'P', 'ma_mon' => 'C1', 'ma_clo' => 'CLO1', 'ma_pi' => 'PI1.1', 'muc_do' => $level]]),
            'Lop' => collect([['ma_lop' => $classCode, 'ma_mon' => 'C1', 'ten_khoa' => $cohort, 'ma_gv' => 'GV01', 'nam_hoc' => $year . '-' . ($year + 1), 'hoc_ky' => 1]]),
        ];
    }
}
