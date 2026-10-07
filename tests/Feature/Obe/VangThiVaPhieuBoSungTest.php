<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\RubricCriteria;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Models\StudentRubricScore;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\CourseReportService;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VẮNG THI và PHIẾU BỔ SUNG — buổi họp 03/10/2026 với trường.
 *
 *  - Vắng thi ghi VT, không để trống. Học viên vắng thi cuối kỳ không vào cỡ mẫu: lớp 40 bạn, 2 bạn VT
 *    thì đo trên 38.
 *  - Thi lại sau 4–5 tuần thì nộp phiếu bổ sung chỉ cho các bạn đó, không phải đo lại cả lớp.
 *
 * Số kỳ vọng tính TAY (khối giữa kỳ 40, cuối kỳ 60 theo trọng số bài của phiếu; nhiều câu cùng CLO
 * trong một bài thì trung bình điểm quy đổi từng câu):
 *
 *   SV01  CLO1 = 0,4 × TB(3/4, 1,5/3)×10 + 0,6 × 4/5×10 = 0,4 × 6,25 + 0,6 × 8 = 7,3
 *   SV03  giữa kỳ CLO1 = TB(2/4, 2/3)×10 = TB(5; 6,6667) = 5,8333
 *         thi lại đề chính: CLO1 cuối kỳ = 3/5×10 = 6   ⇒ CLO1 = 0,4 × 5,8333 + 0,6 × 6 = 5,9333
 *         thi lại đề khác (câu 1 tối đa 6): 6/6×10 = 10 ⇒ CLO1 = 0,4 × 5,8333 + 0,6 × 10 = 8,3333
 */
class VangThiVaPhieuBoSungTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        (new MappingScaleWeightSeeder())->run();

        $this->school = School::create(['code' => 'VT-T', 'name' => 'Trường thử vắng thi', 'is_active' => true]);

        $kq = app(StructureBuilderService::class)->build([
            'Nganh' => collect([['ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính – Ngân hàng', 'khoa' => 'Khoa TCNH']]),
            'Khoa' => collect([['ma_nganh' => 'TCNH', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]]),
            'Mon' => collect([['ma_mon' => 'M01', 'ten_mon' => 'Môn thử', 'so_tin_chi' => 3, 'ma_nganh' => 'TCNH']]),
            'PLO' => collect([
                ['ma_nganh' => 'TCNH', 'ma_plo' => 'PLO1', 'mo_ta' => 'PLO1'],
                ['ma_nganh' => 'TCNH', 'ma_plo' => 'PLO2', 'mo_ta' => 'PLO2'],
            ]),
            'PI' => collect([['ma_nganh' => 'TCNH', 'ma_plo' => 'PLO1', 'ma_pi' => 'PI1.1', 'mo_ta' => 'PI1.1', 'trong_so' => 1]]),
        ], $this->school->id);
        $this->assertSame([], $kq['errors']);
    }

    private function info(string $maLop = 'M01-A'): array
    {
        return [
            2 => ['A' => 'Mã môn học', 'B' => 'M01'],
            3 => ['A' => 'Tên môn học', 'B' => 'Môn thử'],
            4 => ['A' => 'Mã ngành', 'B' => 'TCNH'],
            5 => ['A' => 'Khóa', 'B' => 'Khóa 2026'],
            6 => ['A' => 'Mã lớp học phần', 'B' => $maLop],
            7 => ['A' => 'Giảng viên giảng dạy', 'B' => 'Trần Thị Diện'],
            8 => ['A' => 'Năm học', 'B' => '2026-2027'],
            9 => ['A' => 'Học kỳ', 'B' => 1],
        ];
    }

    /** Ba học viên; SV03 có điểm giữa kỳ, cuối kỳ ghi VT. */
    private function phieu(string $maLop = 'M01-A'): array
    {
        return [
            'KhaiBao' => $this->info($maLop) + [
                11 => ['A' => 'Chuẩn đầu ra', 'B' => 'Nội dung', 'C' => 'PI1.1', 'D' => 'PLO2'],
                12 => ['A' => 'CLO1', 'B' => 'Phát triển vấn đề nghiên cứu', 'C' => 'M'],
                13 => ['A' => 'CLO2', 'B' => 'Tuân thủ đạo đức nghiên cứu', 'D' => 'R'],
                15 => ['A' => 'Đánh giá', 'B' => 'Loại', 'C' => 'Trọng số (%)'],
                16 => ['A' => 'Giữa kỳ 1', 'B' => 'Quá trình', 'C' => 40],
                17 => ['A' => 'Cuối kỳ', 'B' => 'Cuối kỳ', 'C' => 60],
            ],
            'Giữa kỳ 1' => [
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận', 'E' => 'Tự luận', 'F' => 'Trắc nghiệm'],
                3 => ['A' => 'Điểm tối đa', 'D' => 4, 'E' => 3, 'F' => 3],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1', 'E' => 'CLO1', 'F' => 'CLO2'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1', 'E' => 'Câu 2', 'F' => 'Câu 3'],
                8 => ['A' => 1, 'B' => 'SV01', 'C' => 'Nguyễn Văn An', 'D' => 3, 'E' => 1.5, 'F' => 2],
                9 => ['A' => 2, 'B' => 'SV02', 'C' => 'Trần Thị Bình', 'D' => 4, 'E' => 3, 'F' => 3],
                10 => ['A' => 3, 'B' => 'SV03', 'C' => 'Lê Văn Cường', 'D' => 2, 'E' => 2, 'F' => 1],
            ],
            'Cuối kỳ' => [
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận', 'E' => 'Vấn đáp'],
                3 => ['A' => 'Điểm tối đa', 'D' => 5, 'E' => 5],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1', 'E' => 'CLO2'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1', 'E' => 'Câu 2'],
                8 => ['A' => 1, 'B' => 'SV01', 'C' => 'Nguyễn Văn An', 'D' => 4, 'E' => 5],
                9 => ['A' => 2, 'B' => 'SV02', 'C' => 'Trần Thị Bình', 'D' => 2.5, 'E' => 4],
                10 => ['A' => 3, 'B' => 'SV03', 'C' => 'Lê Văn Cường', 'D' => 'VT', 'E' => 'vt'],
            ],
        ];
    }

    /** Phiếu bổ sung: chỉ thông tin lớp và sheet bài thi lại. */
    private function phieuBoSung(array $cuoiKy): array
    {
        return ['KhaiBao' => $this->info(), 'Cuối kỳ' => $cuoiKy];
    }

    private function nhap(array $sheets, array $opt = []): array
    {
        return app(ClassSheetService::class)->import((new ClassSheetReader())->parse($sheets), $this->school->id, $opt);
    }

    private function sv(string $mssv): Student
    {
        return Student::where('student_code', $mssv)->firstOrFail();
    }

    private function clo(string $mssv, string $code): ?float
    {
        $v = $this->sv($mssv)->cloResults()->whereHas('clo', fn ($q) => $q->where('code', $code))->value('achieved_score');

        return $v === null ? null : (float) $v;
    }

    public function test_vang_thi_cuoi_ky_khong_vao_co_mau(): void
    {
        $kq = $this->nhap($this->phieu());

        $this->assertSame([], $kq['errors']);
        $this->assertStringContainsString('1 học viên vắng thi (SV03)', implode("\n", $kq['warnings']));
        $this->assertSame(1, $kq['stats']['vang_thi']);

        // VT được LƯU lại, khác với ô trống.
        $ck = Assessment::where('name', 'Cuối kỳ')->firstOrFail();
        $dong = StudentGrade::where('student_id', $this->sv('SV03')->id)->where('assessment_id', $ck->id)->firstOrFail();
        $this->assertTrue($dong->is_absent);
        $this->assertNull($dong->score);

        // SV03 đứng ngoài phép đo của môn — không tính CLO từ riêng điểm giữa kỳ.
        $this->assertSame(0, StudentCloResult::where('student_id', $this->sv('SV03')->id)->count());
        $this->assertSame(0, $this->sv('SV03')->ploResults()->count());
        $this->assertSame(0, $this->sv('SV03')->piResults()->count());

        // Các bạn khác không bị ảnh hưởng.
        $this->assertEqualsWithDelta(7.3, $this->clo('SV01', 'CLO1'), 0.0005);

        // Báo cáo học phần: 3 đăng ký, 2 dự thi, 1 vắng; mọi dòng của CLO1 đo trên 2 bạn.
        $bc = app(CourseReportService::class)->build(Course::where('code', 'M01')->firstOrFail(), '2026-2027', 1);
        $this->assertSame(3, $bc['thong_ke']['dang_ky']);
        $this->assertSame(2, $bc['thong_ke']['du_thi_cuoi_ky']);
        $this->assertSame(1, $bc['thong_ke']['vang_thi']);

        $clo1 = collect($bc['clos'])->firstWhere('code', 'CLO1');
        $this->assertSame(2, $clo1['tong']['do']);
        foreach ($clo1['dong'] as $dongPp) {
            $soHv = array_sum(array_map(fn ($l) => $l['dat'] + $l['khong_dat'], $dongPp['lop']));
            $this->assertSame(2, $soHv, "Dòng '{$dongPp['phuong_phap']}' phải đo trên 2 bạn, không tính bạn vắng thi");
        }
    }

    public function test_de_trong_ca_bai_cuoi_ky_thi_bi_chan(): void
    {
        $p = $this->phieu();
        $p['Cuối kỳ'][10] = ['A' => 3, 'B' => 'SV03', 'C' => 'Lê Văn Cường', 'D' => null, 'E' => null];
        // SV04 trống ở MỌI bài: học viên khác lớp (phiếu điền sẵn liệt kê cả khóa) — không phải lỗi.
        $p['Giữa kỳ 1'][11] = ['A' => 4, 'B' => 'SV04', 'C' => 'Phạm Thị Dung'];
        $p['Cuối kỳ'][11] = ['A' => 4, 'B' => 'SV04', 'C' => 'Phạm Thị Dung'];

        $kq = $this->nhap($p);

        $this->assertSame(['Cuối kỳ · dòng 10: MSSV SV03 để trống cả bài cuối kỳ — vắng thi thì ghi VT.'], $kq['errors']);
        $this->assertSame(0, StudentGrade::count(), 'Phiếu có lỗi thì không ghi gì');
    }

    public function test_phieu_nop_giua_ky_chua_co_diem_cuoi_ky_van_nhap_duoc(): void
    {
        $p = $this->phieu();
        foreach ([8, 9, 10] as $r) {
            $p['Cuối kỳ'][$r]['D'] = null;
            $p['Cuối kỳ'][$r]['E'] = null;
        }

        $this->assertSame([], $this->nhap($p)['errors']);
    }

    public function test_phieu_bo_sung_chi_cap_nhat_ban_thi_lai(): void
    {
        $this->assertSame([], $this->nhap($this->phieu())['errors']);
        $diemSv01 = StudentRubricScore::where('student_id', $this->sv('SV01')->id)->pluck('score', 'rubric_criteria_id')->all();

        $kq = $this->nhap($this->phieuBoSung([
            2 => ['A' => 'Hình thức', 'D' => 'Tự luận', 'E' => 'Vấn đáp'],
            3 => ['A' => 'Điểm tối đa', 'D' => 5, 'E' => 5],
            4 => ['A' => 'CLO cần đo', 'D' => 'CLO1', 'E' => 'CLO2'],
            7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1', 'E' => 'Câu 2'],
            8 => ['A' => 1, 'B' => 'SV03', 'C' => 'Lê Văn Cường', 'D' => 3, 'E' => 4],
        ]), ['bo_sung' => true]);

        $this->assertSame([], $kq['errors']);

        // SV03 hết vắng thi, có điểm cuối kỳ và vào lại cỡ mẫu.
        $ck = Assessment::where('name', 'Cuối kỳ')->firstOrFail();
        $dong = StudentGrade::where('student_id', $this->sv('SV03')->id)->where('assessment_id', $ck->id)->sole();
        $this->assertFalse($dong->is_absent);
        $this->assertEqualsWithDelta(7.0, (float) $dong->score, 0.0001);
        $this->assertEqualsWithDelta(5.933333, $this->clo('SV03', 'CLO1'), 0.0005);

        // Điểm của các bạn khác giữ nguyên từng ô — phiếu bổ sung không thay cả lớp.
        $this->assertSame($diemSv01, StudentRubricScore::where('student_id', $this->sv('SV01')->id)->pluck('score', 'rubric_criteria_id')->all());
        $this->assertSame(2, StudentGrade::where('student_id', $this->sv('SV02')->id)->whereNotNull('score')->count());
        $this->assertEqualsWithDelta(7.3, $this->clo('SV01', 'CLO1'), 0.0005);
    }

    public function test_de_thi_lai_khac_de_chinh_khong_de_len_cau_cu(): void
    {
        $this->assertSame([], $this->nhap($this->phieu())['errors']);

        $kq = $this->nhap($this->phieuBoSung([
            2 => ['A' => 'Hình thức', 'D' => 'Tự luận', 'E' => 'Tự luận'],
            3 => ['A' => 'Điểm tối đa', 'D' => 6, 'E' => 4],
            4 => ['A' => 'CLO cần đo', 'D' => 'CLO1', 'E' => 'CLO2'],
            7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1', 'E' => 'Câu 2'],
            8 => ['A' => 1, 'B' => 'SV03', 'C' => 'Lê Văn Cường', 'D' => 6, 'E' => 2],
        ]), ['bo_sung' => true]);
        $this->assertSame([], $kq['errors']);

        $class = CourseClass::where('class_code', 'M01-A')->firstOrFail();
        $cau = RubricCriteria::where('class_id', $class->id)->pluck('max_score', 'question_label')->map(fn ($v) => (float) $v)->all();

        // Câu của đề chính giữ nguyên điểm tối đa — 2 bạn thi đề chính vẫn chấm theo câu cũ.
        $this->assertEqualsWithDelta(5.0, $cau['Câu 1'], 0.0001);
        $this->assertEqualsWithDelta(6.0, $cau['Câu 1 (bổ sung)'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $cau['Câu 2 (bổ sung)'], 0.0001);

        $this->assertEqualsWithDelta(7.3, $this->clo('SV01', 'CLO1'), 0.0005);
        $this->assertEqualsWithDelta(8.333333, $this->clo('SV03', 'CLO1'), 0.0005);
    }

    public function test_phieu_bo_sung_cho_lop_chua_co_thi_bi_chan(): void
    {
        $kq = $this->nhap($this->phieuBoSung([
            7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1'],
        ]), ['bo_sung' => true]);

        $this->assertStringContainsString('phiếu bổ sung chỉ dùng cho lớp đã nộp phiếu', implode("\n", $kq['errors']));
    }

    public function test_nop_nham_phieu_lop_khac_thi_bi_chan(): void
    {
        $this->assertSame([], $this->nhap($this->phieu())['errors']);
        $lopA = CourseClass::where('class_code', 'M01-A')->firstOrFail();

        $kq = $this->nhap($this->phieu('M01-B'), ['lop_id' => $lopA->id]);

        $this->assertStringContainsString("phiếu ghi lớp 'M01-B' nhưng đang nộp cho lớp M01-A", implode("\n", $kq['errors']));
    }
}
