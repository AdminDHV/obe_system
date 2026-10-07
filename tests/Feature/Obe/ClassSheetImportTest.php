<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Assessment;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\CourseClass;
use App\Models\Pi;
use App\Models\RubricCriteria;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentRubricScore;
use App\Models\User;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHIẾU LỚP HỌC PHẦN — thiết kế theo đúng buổi họp lần 1 với khách:
 *   "một lớp học phần mình nhập một phiếu là đủ… khai báo một lần rồi cho cục điểm chấm vô là chạy".
 *
 * Số kỳ vọng tính TAY theo đúng công thức engine (điểm từng BÀI trước, rồi gộp quá trình 0.4 /
 * cuối kỳ 0.6), không lấy từ chính code đang test:
 *
 *   SV01  CLO1: GK1 = TB(3/4 × 10, 1.5/3 × 10) = TB(7.5, 5) = 6.25   CK = 4/5 × 10 = 8
 *               → khối GK 40, CK 60 (trọng số bài của phiếu): 0.4 × 6.25 + 0.6 × 8 = 7.3
 *         CLO2: GK1 = 2/3 × 10 = 6.666667                   CK = 5/5 × 10 = 10
 *               → 0.4 × 6.666667 + 0.6 × 10 = 8.666667
 *         PLO2 (CLO2 nối THẲNG vào PLO2, PLO2 không có PI) = 8.666667 / 10 = 0.866667
 *         Điểm bài: GK1 = 3 + 1.5 + 2 = 6.5 · CK = 4 + 5 = 9
 *
 *   Nhiều câu cùng đo một CLO trong một bài: TRUNG BÌNH điểm quy đổi từng câu (WCML công thức (2)),
 *   không phải Σ đạt / Σ tối đa (= 6.428571).
 */
class ClassSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        (new MappingScaleWeightSeeder())->run();

        $this->school = School::create(['code' => 'TCNH-T', 'name' => 'Trường thử phiếu', 'is_active' => true]);

        // File khai báo chương trình: PLO1 chia PI, PLO2 KHÔNG chia PI (đề cương nối CLO→PLO thẳng).
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

    /** @return array<string, array<int, array<string, mixed>>> */
    private function phieu(string $maLop = 'M01-A'): array
    {
        return [
            'KhaiBao' => [
                1 => ['A' => 'PHIẾU KHAI BÁO LỚP HỌC PHẦN'],
                2 => ['A' => 'Mã môn học', 'B' => 'M01'],
                3 => ['A' => 'Tên môn học', 'B' => 'Môn thử'],
                4 => ['A' => 'Mã ngành', 'B' => 'TCNH'],
                5 => ['A' => 'Khóa', 'B' => 'Khóa 2026'],
                6 => ['A' => 'Mã lớp học phần', 'B' => $maLop],
                7 => ['A' => 'Giảng viên giảng dạy', 'B' => 'Trần Thị Diện'],
                8 => ['A' => 'Năm học', 'B' => '2026-2027'],
                9 => ['A' => 'Học kỳ', 'B' => 1],
                11 => ['A' => 'Chuẩn đầu ra', 'B' => 'Nội dung', 'C' => 'PI1.1', 'D' => 'PLO2'],
                12 => ['A' => 'CLO1', 'B' => 'Phát triển vấn đề nghiên cứu', 'C' => 'M'],
                13 => ['A' => 'CLO2', 'B' => 'Tuân thủ đạo đức nghiên cứu', 'D' => 'r'],
                15 => ['A' => 'Đánh giá', 'B' => 'Loại', 'C' => 'Trọng số (%)'],
                16 => ['A' => 'Giữa kỳ 1', 'B' => 'Quá trình', 'C' => 40],
                17 => ['A' => 'Cuối kỳ', 'B' => 'Cuối kỳ', 'C' => '60%'],
            ],
            'Giữa kỳ 1' => [
                1 => ['A' => 'Bài đánh giá', 'B' => 'Giữa kỳ 1'],
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận', 'E' => 'Tự luận', 'F' => 'Trắc nghiệm'],
                3 => ['A' => 'Điểm tối đa', 'D' => 4, 'E' => '3', 'F' => 3],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1', 'E' => 'clo1', 'F' => 'CLO2'],
                5 => ['A' => 'PLO/PI cần đo', 'F' => 'PLO2'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1', 'E' => 'Câu 2', 'F' => 'Câu 3-15'],
                8 => ['A' => 1, 'B' => 'SV01', 'C' => 'Nguyễn Văn An', 'D' => 3, 'E' => '1,5', 'F' => 2],
                9 => ['A' => 2, 'B' => 'SV02', 'C' => 'Trần Thị Bình', 'D' => 4, 'E' => 3, 'F' => null],
                10 => ['A' => null, 'B' => null, 'C' => null],
            ],
            'Cuối kỳ' => [
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận', 'E' => 'Vấn đáp'],
                3 => ['A' => 'Điểm tối đa', 'D' => 5, 'E' => 5],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1', 'E' => 'CLO2'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1', 'E' => 'Câu 2'],
                8 => ['A' => 1, 'B' => 'SV01', 'C' => 'Nguyễn Văn An', 'D' => 4, 'E' => 5],
                9 => ['A' => 2, 'B' => 'SV02', 'C' => 'Trần Thị Bình', 'D' => 2.5, 'E' => 4],
            ],
        ];
    }

    /** @param array<string, array<int, array<string, mixed>>> $sheets */
    private function nhap(array $sheets): array
    {
        return app(ClassSheetService::class)->import((new ClassSheetReader())->parse($sheets), $this->school->id);
    }

    private function clo(string $mssv, string $code): float
    {
        return (float) Student::where('student_code', $mssv)->firstOrFail()
            ->cloResults()->whereHas('clo', fn ($q) => $q->where('code', $code))->firstOrFail()->achieved_score;
    }

    private function plo(string $mssv, string $code): float
    {
        return (float) Student::where('student_code', $mssv)->firstOrFail()
            ->ploResults()->whereHas('plo', fn ($q) => $q->where('code', $code))->firstOrFail()->achieved_score;
    }

    public function test_mot_phieu_du_de_do_va_ra_dung_so_tinh_tay(): void
    {
        $kq = $this->nhap($this->phieu());
        $this->assertSame([], $kq['errors']);

        // Lớp, khóa, giảng viên được tạo từ chính phiếu — không ai phải tạo tay trước.
        $class = CourseClass::where('class_code', 'M01-A')->firstOrFail();
        $gv = User::findOrFail($class->lecturer_id);
        $this->assertSame('Trần Thị Diện', $gv->full_name);
        $this->assertFalse((bool) $gv->is_active, 'Giảng viên tạo từ phiếu không được đăng nhập');
        $this->assertSame('question', Cohort::findOrFail($class->cohort_id)->grade_granularity);
        $this->assertSame(2, Student::where('school_id', $this->school->id)->count());

        // Trọng số dạng "60%" đọc đúng; loại bài suy từ cột Loại.
        $ck = Assessment::where('syllabus_id', $class->syllabus_id)->where('name', 'Cuối kỳ')->firstOrFail();
        $this->assertEqualsWithDelta(60, (float) $ck->weight_percent, 0.001);
        $this->assertSame('final', $ck->assessment_category);

        // Mỗi câu một tiêu chí, gắn đúng lớp.
        $this->assertSame(5, RubricCriteria::where('class_id', $class->id)->count());

        // CLO2 nối thẳng PLO2 (không có PI) ⇒ một PI nội bộ duy nhất cho PLO2, không tạo cho PLO1.
        $this->assertSame(1, Pi::where('is_dummy', true)->count());
        $this->assertSame('R', CloPiMapping::whereHas('pi', fn ($q) => $q->where('is_dummy', true))->value('mapping_level'));

        // Đo lường — số tính tay ở đầu file.
        $this->assertEqualsWithDelta(7.3, $this->clo('SV01', 'CLO1'), 0.0005);
        $this->assertEqualsWithDelta(8.666667, $this->clo('SV01', 'CLO2'), 0.0005);
        $this->assertEqualsWithDelta(0.866667, $this->plo('SV01', 'PLO2'), 0.0005);

        // SV02 bỏ trống câu 3 giữa kỳ ⇒ CLO2 chỉ còn cuối kỳ (4/5 × 10 = 8), không bị kéo xuống 0.
        $this->assertEqualsWithDelta(8.0, $this->clo('SV02', 'CLO2'), 0.0005);

        // Điểm số — điểm bài = tổng điểm các câu, cùng một phiếu.
        $sv01 = Student::where('student_code', 'SV01')->firstOrFail();
        $gk = Assessment::where('syllabus_id', $class->syllabus_id)->where('name', 'Giữa kỳ 1')->firstOrFail();
        $this->assertEqualsWithDelta(6.5, (float) StudentGrade::where('student_id', $sv01->id)->where('assessment_id', $gk->id)->value('score'), 0.0001);
        $this->assertEqualsWithDelta(9.0, (float) StudentGrade::where('student_id', $sv01->id)->where('assessment_id', $ck->id)->value('score'), 0.0001);
    }

    public function test_nhap_lai_thay_han_diem_cua_lop_khong_nhan_doi(): void
    {
        $this->nhap($this->phieu());

        $sua = $this->phieu();
        $sua['Giữa kỳ 1'][8]['E'] = null; // xóa điểm câu 2 của SV01
        $kq = $this->nhap($sua);
        $this->assertSame([], $kq['errors']);

        $class = CourseClass::where('class_code', 'M01-A')->firstOrFail();
        $this->assertSame(5, RubricCriteria::where('class_id', $class->id)->count());
        // lần 1: 5 + 4 điểm câu; lần 2 bỏ một ô ⇒ 8, không phải 17.
        $this->assertSame(8, StudentRubricScore::where('class_id', $class->id)->count());
        $this->assertSame(1, CourseClass::count());

        // SV01 GK1 giờ chỉ còn câu 1 (3/4) → CLO1 = 0.4 × 7.5 + 0.6 × 8 = 7.8
        $this->assertEqualsWithDelta(7.8, $this->clo('SV01', 'CLO1'), 0.0005);
    }

    public function test_hai_lop_cung_mon_ra_de_khac_nhau_khong_de_nhau(): void
    {
        $this->nhap($this->phieu('M01-A'));

        $lopB = $this->phieu('M01-B');
        $lopB['Giữa kỳ 1'][3] = ['A' => 'Điểm tối đa', 'D' => 10];
        $lopB['Giữa kỳ 1'][4] = ['A' => 'CLO cần đo', 'D' => 'CLO2'];
        $lopB['Giữa kỳ 1'][2] = ['A' => 'Hình thức', 'D' => 'Tiểu luận'];
        $lopB['Giữa kỳ 1'][5] = ['A' => 'PLO/PI cần đo'];
        $lopB['Giữa kỳ 1'][7] = ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1'];
        $lopB['Giữa kỳ 1'][8] = ['A' => 1, 'B' => 'SV03', 'C' => 'Lê Văn Cường', 'D' => 9];
        unset($lopB['Giữa kỳ 1'][9]);
        $lopB['Cuối kỳ'][8] = ['A' => 1, 'B' => 'SV03', 'C' => 'Lê Văn Cường', 'D' => 5, 'E' => 5];
        unset($lopB['Cuối kỳ'][9]);

        $this->assertSame([], $this->nhap($lopB)['errors']);

        // Câu 1 của lớp A vẫn đo CLO1 tối đa 4; lớp B có câu 1 riêng.
        $a = CourseClass::where('class_code', 'M01-A')->firstOrFail();
        $this->assertSame(5, RubricCriteria::where('class_id', $a->id)->count());
        $this->assertEqualsWithDelta(4, (float) RubricCriteria::where('class_id', $a->id)->where('question_label', 'Câu 1')
            ->whereHas('rubric.assessment', fn ($q) => $q->where('name', 'Giữa kỳ 1'))->value('max_score'), 0.001);
        $this->assertEqualsWithDelta(7.3, $this->clo('SV01', 'CLO1'), 0.0005);
    }

    /**
     * Ma trận CLO và trọng số bài dùng chung cho mọi lớp cùng môn, cùng khóa. Phiếu lớp B sửa ma trận thì
     * số của lớp A (nhập trước) PHẢI được tính lại — không thì màn hình vẫn hiện số cũ, trông hợp lý,
     * mà đã sai. Người nhập cũng phải được báo là thay đổi lan sang lớp khác.
     */
    public function test_phieu_lop_sau_doi_ma_tran_thi_lop_truoc_duoc_tinh_lai_va_co_canh_bao(): void
    {
        $this->assertSame([], $this->nhap($this->phieu('M01-A'))['errors']);
        $this->assertEqualsWithDelta(0.866667, $this->plo('SV01', 'PLO2'), 0.0005);

        $lopB = $this->phieu('M01-B');
        $lopB['KhaiBao'][13] = ['A' => 'CLO2', 'B' => 'Tuân thủ đạo đức nghiên cứu', 'C' => 'M']; // CLO2: PLO2 → PI1.1
        $lopB['Giữa kỳ 1'][5] = ['A' => 'PLO/PI cần đo'];
        foreach (['Giữa kỳ 1', 'Cuối kỳ'] as $sheet) {
            $lopB[$sheet][8]['B'] = 'SV03';
            $lopB[$sheet][9]['B'] = 'SV04';
        }

        $kq = $this->nhap($lopB);
        $this->assertSame([], $kq['errors']);
        $this->assertNotEmpty($kq['warnings'], 'Phải báo người nhập là ma trận dùng chung đã đổi');
        $this->assertStringContainsString('M01-A', implode("\n", $kq['warnings']));

        // SV01 (lớp A) đã được tính lại theo ma trận mới: không còn đo PLO2, PI1.1 giờ gồm cả CLO2.
        $sv01 = Student::where('student_code', 'SV01')->firstOrFail();
        $this->assertFalse($sv01->ploResults()->whereHas('plo', fn ($q) => $q->where('code', 'PLO2'))->exists(), 'Kết quả PLO2 cũ của lớp A phải bị gỡ');
        // PI1.1 của SV01 tính từ MỌI câu của CLO1 và CLO2 (WCML (7)–(8)), không lấy TB điểm CLO:
        //   GK1 = TB(7.5, 5, 6.666667) = 6.388889 · CK = TB(8, 10) = 9 ⇒ 0.4 × 6.388889 + 0.6 × 9 = 7.955556 ⇒ 0.795556
        $pi = (float) $sv01->piResults()->whereHas('pi', fn ($q) => $q->where('code', 'PI1.1'))->firstOrFail()->achieved_score;
        $this->assertEqualsWithDelta(0.795556, $pi, 0.0005);
    }

    public function test_nhap_phieu_khong_doi_gi_cua_mon_thi_khong_canh_bao(): void
    {
        $this->nhap($this->phieu('M01-A'));

        $lopB = $this->phieu('M01-B');
        foreach (['Giữa kỳ 1', 'Cuối kỳ'] as $sheet) {
            $lopB[$sheet][8]['B'] = 'SV03';
            $lopB[$sheet][9]['B'] = 'SV04';
        }

        $kq = $this->nhap($lopB);
        $this->assertSame([], $kq['errors']);
        $this->assertSame([], $kq['warnings'], 'Cùng ma trận, cùng trọng số thì không có gì để cảnh báo');
    }

    /**
     * Ô ghi "VT" là VẮNG THI, không phải 0 điểm: câu đó không có bằng chứng nên để ngoài phép đo,
     * phiếu vẫn nhập được và chỉ cảnh báo. Báo cáo học phần đếm riêng số sinh viên không dự thi.
     *
     * SV01 vắng câu 1 giữa kỳ ⇒ CLO1 chỉ còn câu 2 (1.5/3 × 10 = 5) ở giữa kỳ và 4/5 × 10 = 8 ở cuối kỳ
     * ⇒ CLO1 = (40 × 5 + 60 × 8) / 100 = 6.8. Điểm bài giữa kỳ = 1.5 + 2 = 3.5.
     */
    public function test_o_ghi_vt_la_vang_thi_khong_phai_khong_diem(): void
    {
        $p = $this->phieu();
        $p['Giữa kỳ 1'][8]['D'] = 'VT';

        $kq = $this->nhap($p);

        $this->assertSame([], $kq['errors']);
        $this->assertStringContainsString('MSSV SV01 vừa có điểm vừa ghi VT', implode("\n", $kq['warnings']));
        $this->assertEqualsWithDelta(6.8, $this->clo('SV01', 'CLO1'), 0.0005);

        $sv01 = Student::where('student_code', 'SV01')->firstOrFail();
        $gk = Assessment::where('name', 'Giữa kỳ 1')->firstOrFail();
        $this->assertEqualsWithDelta(3.5, (float) StudentGrade::where('student_id', $sv01->id)
            ->where('assessment_id', $gk->id)->value('score'), 0.0001, 'Điểm bài chỉ cộng các câu có điểm');
        $this->assertSame(4, StudentRubricScore::where('student_id', $sv01->id)->count(), 'Câu vắng thi không tạo dòng điểm (5 câu − 1)');
    }

    /**
     * Mọi thứ làm KHÔNG ĐO ĐƯỢC phải bị chặn, báo đúng ô, và KHÔNG ghi gì.
     * Khách dặn: "còn sót tiêu chí nào để nó không đo được không? nhớ check lại".
     */
    public function test_moi_loi_deu_bi_chan_va_khong_ghi_gi(): void
    {
        $cases = [
            'tổng trọng số' => [fn (&$p) => $p['KhaiBao'][17]['C'] = 50, 'phải đúng 100%'],
            'tổng điểm tối đa' => [fn (&$p) => $p['Cuối kỳ'][3]['E'] = 4, 'phải đúng 10'],
            'điểm vượt tối đa' => [fn (&$p) => $p['Giữa kỳ 1'][8]['D'] = 4.5, 'vượt điểm tối đa'],
            'điểm không phải số' => [fn (&$p) => $p['Giữa kỳ 1'][8]['D'] = 'chưa chấm', 'không phải số'],
            'câu thiếu CLO' => [fn (&$p) => $p['Giữa kỳ 1'][4]['D'] = null, 'chưa ghi CLO'],
            'CLO không có trong khối' => [fn (&$p) => $p['Cuối kỳ'][4]['E'] = 'CLO9', "CLO 'CLO9'"],
            'CLO chưa nối PLO/PI' => [fn (&$p) => $p['KhaiBao'][13]['D'] = null, 'chưa nối vào PLO/PI'],
            'mức sai' => [fn (&$p) => $p['KhaiBao'][12]['C'] = 'X', 'chỉ ghi I, R, M hoặc A'],
            'thiếu sheet bài' => [function (&$p) { unset($p['Cuối kỳ']); }, "Thiếu sheet cho bài 'Cuối kỳ'"],
            'mã PLO/PI lạ' => [fn (&$p) => $p['KhaiBao'][11]['D'] = 'PLO9', "mã 'PLO9'"],
            'PLO đã chia PI' => [fn (&$p) => $p['KhaiBao'][11]['C'] = 'PLO1', 'ghi mã PI cụ thể'],
            'PLO/PI ghi tay lệch ma trận' => [fn (&$p) => $p['Giữa kỳ 1'][5]['F'] = 'PI1.1', 'không nối CLO2'],
            'môn chưa khai' => [fn (&$p) => $p['KhaiBao'][2]['B'] = 'M99', "chưa có môn 'M99'"],
            'MSSV lặp' => [fn (&$p) => $p['Cuối kỳ'][9]['B'] = 'SV01', 'lặp lại'],
        ];

        foreach ($cases as $ten => [$sua, $can]) {
            $p = $this->phieu();
            $sua($p);
            $kq = $this->nhap($p);

            $this->assertNotEmpty($kq['errors'], "Ca '{$ten}' phải bị chặn");
            $this->assertStringContainsString($can, implode("\n", $kq['errors']), "Ca '{$ten}' báo sai nội dung:\n" . implode("\n", $kq['errors']));
            $this->assertSame(0, CourseClass::count(), "Ca '{$ten}' đã ghi dở dữ liệu");
            $this->assertSame(0, StudentRubricScore::count());
        }
    }
}
