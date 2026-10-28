<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\CourseClass;
use App\Models\School;
use App\Models\User;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gắn giảng viên cho lớp khi nhập phiếu / file khung.
 *
 * Lỗi cũ: một ô "Giảng viên" tra theo mã HOẶC họ tên rồi lấy người đầu tiên khớp — trường có hai
 * giảng viên trùng họ tên thì lớp gắn nhầm người, điểm cả lớp hiện dưới tên người khác.
 */
class LecturerMatchingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        (new MappingScaleWeightSeeder())->run();

        $this->school = School::create(['code' => 'GV-T', 'name' => 'Trường thử giảng viên', 'is_active' => true]);

        $kq = app(StructureBuilderService::class)->build([
            'Nganh' => collect([['ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính', 'khoa' => 'Khoa TCNH']]),
            'Khoa' => collect([['ma_nganh' => 'TCNH', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]]),
            'Mon' => collect([['ma_mon' => 'M01', 'ten_mon' => 'Môn thử', 'so_tin_chi' => 3, 'ma_nganh' => 'TCNH']]),
            'PLO' => collect([['ma_nganh' => 'TCNH', 'ma_plo' => 'PLO1', 'mo_ta' => 'PLO1']]),
            'PI' => collect([['ma_nganh' => 'TCNH', 'ma_plo' => 'PLO1', 'ma_pi' => 'PI1.1', 'mo_ta' => 'PI1.1', 'trong_so' => 1]]),
        ], $this->school->id);
        $this->assertSame([], $kq['errors']);
    }

    private function gv(string $code, string $name, ?int $schoolId = null): User
    {
        return User::create([
            'staff_code' => $code,
            'full_name' => $name,
            'email' => strtolower($code) . '-' . ($schoolId ?? $this->school->id) . '@test.local',
            'password' => bcrypt('x'),
            'school_id' => $schoolId ?? $this->school->id,
            'is_active' => true,
        ]);
    }

    /** @return array<string,mixed> */
    private function nhap(?string $ten, ?string $ma = null, string $lop = 'M01-A'): array
    {
        $info = [
            2 => ['A' => 'Mã môn học', 'B' => 'M01'],
            4 => ['A' => 'Mã ngành', 'B' => 'TCNH'],
            5 => ['A' => 'Khóa', 'B' => 'Khóa 2026'],
            6 => ['A' => 'Mã lớp học phần', 'B' => $lop],
            7 => ['A' => 'Giảng viên giảng dạy', 'B' => $ten],
            8 => ['A' => 'Mã giảng viên', 'B' => $ma],
            9 => ['A' => 'Học kỳ', 'B' => 1],
            11 => ['A' => 'Chuẩn đầu ra', 'B' => 'Nội dung', 'C' => 'PI1.1'],
            12 => ['A' => 'CLO1', 'B' => 'CLO một', 'C' => 'M'],
            15 => ['A' => 'Đánh giá', 'B' => 'Loại', 'C' => 'Trọng số (%)'],
            16 => ['A' => 'Cuối kỳ', 'B' => 'Cuối kỳ', 'C' => 100],
        ];
        $sheets = [
            'KhaiBao' => $info,
            'Cuối kỳ' => [
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận'],
                3 => ['A' => 'Điểm tối đa', 'D' => 10],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1'],
                8 => ['A' => 1, 'B' => 'SV01', 'C' => 'Nguyễn Văn An', 'D' => 7],
            ],
        ];

        return app(ClassSheetService::class)->import((new ClassSheetReader())->parse($sheets), $this->school->id);
    }

    private function lecturerOf(string $lop): User
    {
        return User::findOrFail(CourseClass::where('class_code', $lop)->firstOrFail()->lecturer_id);
    }

    public function test_trung_ten_ma_khong_ghi_ma_thi_bao_loi_khong_doan(): void
    {
        $this->gv('GV010', 'Nguyễn Văn Hùng');
        $this->gv('GV020', 'Nguyễn Văn Hùng');

        $kq = $this->nhap('Nguyễn Văn Hùng');

        $this->assertNotSame([], $kq['errors']);
        $loi = implode(' | ', $kq['errors']);
        $this->assertStringContainsString('2 giảng viên cùng tên', $loi);
        $this->assertStringContainsString('GV010', $loi);
        $this->assertStringContainsString('GV020', $loi);
        $this->assertSame(0, CourseClass::where('class_code', 'M01-A')->count(), 'Có lỗi thì không ghi gì');
    }

    public function test_trung_ten_co_ma_thi_gan_dung_nguoi(): void
    {
        $this->gv('GV010', 'Nguyễn Văn Hùng');
        $dung = $this->gv('GV020', 'Nguyễn Văn Hùng');

        $kq = $this->nhap('Nguyễn Văn Hùng', 'gv020');

        $this->assertSame([], $kq['errors']);
        $this->assertSame($dung->id, $this->lecturerOf('M01-A')->id);
    }

    public function test_o_ten_ghi_ma_van_khop_nhu_truoc(): void
    {
        $dung = $this->gv('GV020', 'Nguyễn Văn Hùng');
        $this->gv('GV010', 'Nguyễn Văn Hùng');

        $this->assertSame([], $this->nhap('GV020')['errors']);
        $this->assertSame($dung->id, $this->lecturerOf('M01-A')->id);
    }

    public function test_chi_mot_nguoi_ten_do_thi_khop_ca_khi_khac_dang_chu_va_khoang_trang(): void
    {
        $dung = $this->gv('GV030', 'Trần Thị Diện');

        // Excel hay mang chữ Việt dạng tổ hợp (NFD) và khoảng trắng thừa.
        $nfd = \Normalizer::normalize('  trần   thị diện ', \Normalizer::FORM_D);
        $this->assertSame([], $this->nhap($nfd)['errors']);
        $this->assertSame($dung->id, $this->lecturerOf('M01-A')->id);
    }

    public function test_nguoi_trung_ten_o_truong_khac_khong_tinh(): void
    {
        $khac = School::create(['code' => 'KHAC', 'name' => 'Trường khác', 'is_active' => true]);
        $this->gv('GV010', 'Nguyễn Văn Hùng', $khac->id);
        $dung = $this->gv('GV020', 'Nguyễn Văn Hùng');

        $this->assertSame([], $this->nhap('Nguyễn Văn Hùng')['errors']);
        $this->assertSame($dung->id, $this->lecturerOf('M01-A')->id);
    }

    public function test_ma_moi_thi_tao_tai_khoan_mang_dung_ma_va_khong_dang_nhap_duoc(): void
    {
        $this->assertSame([], $this->nhap('Lê Văn Phúc', 'GV777')['errors']);

        $gv = $this->lecturerOf('M01-A');
        $this->assertSame('GV777', $gv->staff_code);
        $this->assertSame('Lê Văn Phúc', $gv->full_name);
        $this->assertSame($this->school->id, (int) $gv->school_id);
        $this->assertFalse((bool) $gv->is_active);
    }

    public function test_bat_dau_ghi_ma_thi_nhan_lai_tai_khoan_tu_tao_cu_khong_de_them(): void
    {
        // Lần đầu chỉ ghi tên ⇒ tài khoản mang mã máy sinh.
        $this->assertSame([], $this->nhap('Lê Văn Phúc', null, 'M01-A')['errors']);
        $cu = $this->lecturerOf('M01-A');
        $this->assertStringStartsWith('GV-' . $this->school->id . '-', $cu->staff_code);

        // Lần sau trường ghi mã thật ⇒ vẫn là người đó, mang mã thật, cả hai lớp cùng một tài khoản.
        $this->assertSame([], $this->nhap('Lê Văn Phúc', 'GV888', 'M01-B')['errors']);
        $this->assertSame($cu->id, $this->lecturerOf('M01-B')->id);
        $this->assertSame('GV888', $cu->fresh()->staff_code);
        $this->assertSame(1, User::where('full_name', 'Lê Văn Phúc')->count());
    }

    public function test_thieu_ca_ten_lan_ma_thi_bao_loi(): void
    {
        $kq = $this->nhap(null, null);

        $this->assertStringContainsString('thiếu giảng viên', implode(' | ', $kq['errors']));
    }
}
