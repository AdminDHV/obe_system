<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Exports\ClassSheetTemplate;
use App\Exports\StructureTemplateExport;
use App\Imports\StructureImport;
use App\Models\Clo;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseOutcomeLevel;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Services\OBE\CloMatrixService;
use App\Services\OBE\StructureBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * FILE KHAI BÁO CHƯƠNG TRÌNH — ngành và khóa, PLO/PI, môn, ma trận CTĐT (môn × PLO/PI), sinh viên.
 */
class ProgramFileImportTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['code' => 'PF', 'name' => 'Trường file 1', 'is_active' => true]);
    }

    /** @param array<string, array<int, array<string, mixed>>> $sheets */
    private function build(array $sheets): array
    {
        return app(StructureBuilderService::class)->build(
            array_map(fn ($rows) => new Collection($rows), $sheets),
            $this->school->id,
        );
    }

    public function test_bon_sheet_dung_du_chuong_trinh_va_sinh_vien(): void
    {
        $kq = $this->build([
            'Nganh_Khoa' => [
                ['ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính', 'khoa_quan_ly' => 'Khoa TCNH', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026],
                ['ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính', 'khoa_quan_ly' => 'Khoa TCNH', 'ten_khoa' => null, 'nam_bat_dau' => 2027],
            ],
            // Mã ngành bỏ trống được vì file chỉ khai một ngành.
            'PLO_PI' => [
                ['ma_plo' => 'PLO1', 'mo_ta_plo' => 'Kiến thức', 'ma_pi' => null],
                ['ma_plo' => 'PLO2', 'mo_ta_plo' => 'Kỹ năng', 'ma_pi' => 'PI2.1', 'mo_ta_pi' => 'a', 'trong_so_pi' => '0,4'],
                ['ma_plo' => 'PLO2', 'ma_pi' => 'PI2.2', 'mo_ta_pi' => 'b', 'trong_so_pi' => 0.6],
            ],
            'MonHoc' => [
                ['ma_mon' => '81002', 'ten_mon' => 'Phương pháp nghiên cứu', 'tin_chi' => 3],
            ],
            'SinhVien' => [
                ['mssv' => 'SV01', 'ho_va_ten' => 'Nguyễn Văn An', 'nam_khoa' => 2026],
            ],
        ]);

        $this->assertSame([], $kq['errors']);

        $program = Program::where('school_id', $this->school->id)->where('code', 'TCNH')->firstOrFail();
        $this->assertSame('Khoa TCNH', $program->department->name);
        $this->assertSame(['Khóa 2026', 'Khóa 2027'], Cohort::where('program_id', $program->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame(2, Plo::where('program_id', $program->id)->count());
        $this->assertEqualsWithDelta(0.4, (float) Pi::where('code', 'PI2.1')->value('weight'), 0.0001);
        $this->assertSame(0, Pi::where('is_dummy', true)->count(), 'PLO1 không chia PI thì KHÔNG tự sinh PI ảo');
        $this->assertTrue($program->courses()->where('code', '81002')->exists());
        $this->assertSame('Khóa 2026', Student::where('student_code', 'SV01')->firstOrFail()->cohort->name);
    }

    public function test_tong_trong_so_pi_khac_1_thi_bao_loi_va_khong_dung_pi(): void
    {
        $kq = $this->build([
            'Nganh_Khoa' => [['ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính', 'nam_bat_dau' => 2026]],
            'PLO_PI' => [
                ['ma_plo' => 'PLO2', 'ma_pi' => 'PI2.1', 'trong_so_pi' => 0.5],
                ['ma_plo' => 'PLO2', 'ma_pi' => 'PI2.2', 'trong_so_pi' => 0.6],
                ['ma_plo' => 'PLO3', 'ma_pi' => 'PI3.1', 'trong_so_pi' => null],
            ],
        ]);

        $loi = implode("\n", $kq['errors']);
        $this->assertStringContainsString('tổng trọng số các PI của PLO2 là 1,1', $loi);
        $this->assertStringContainsString("PI 'PI3.1' thiếu trọng số", $loi);
        $this->assertSame(0, Pi::count(), 'Trọng số sai thì không được dựng PI — dựng rồi số đo vẫn ra, chỉ là sai');
        $this->assertSame(2, Plo::count());
    }

    /** @return array<string, array<int, array<string, mixed>>> khung 2 môn, PLO1 không chia PI, PLO2 chia 2 PI */
    private function khungCoCtdt(array $maTran, string $khoa = 'Khóa 2026', int $nam = 2026): array
    {
        return [
            'Nganh_Khoa' => [['ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính', 'ten_khoa' => $khoa, 'nam_bat_dau' => $nam]],
            'PLO_PI' => [
                ['ma_plo' => 'PLO1', 'mo_ta_plo' => 'Kiến thức'],
                ['ma_plo' => 'PLO2', 'mo_ta_plo' => 'Kỹ năng', 'ma_pi' => 'PI2.1', 'trong_so_pi' => 0.5],
                ['ma_plo' => 'PLO2', 'ma_pi' => 'PI2.2', 'trong_so_pi' => 0.5],
            ],
            'MonHoc' => [
                ['ma_mon' => '81002', 'ten_mon' => 'PPNC', 'tin_chi' => 3],
                ['ma_mon' => '81003', 'ten_mon' => 'Tiền tệ', 'tin_chi' => 3],
            ],
            // Sheet MaTran_CTDT đọc dòng GỐC: dòng 1 là tiêu đề, mã cột giữ nguyên dấu chấm.
            'MaTran_CTDT' => $maTran,
        ];
    }

    /** @return array<string,string> mã cột (PLO nối thẳng hiện mã PLO) → mức */
    private function mucCtdt(string $khoa, string $mon): array
    {
        return CourseOutcomeLevel::whereHas('cohort', fn ($q) => $q->where('name', $khoa))
            ->whereHas('course', fn ($q) => $q->where('code', $mon))
            ->with('pi.plo')->get()
            ->mapWithKeys(fn (CourseOutcomeLevel $r) => [CloMatrixService::targetCode($r->pi) => $r->level])
            ->sortKeys()->all();
    }

    /** Chép đúng bảng mục 13 của CTĐT: có STT, tên môn, tín chỉ, dòng tiêu đề nhóm; cột PLO. */
    public function test_ma_tran_ctdt_khai_mon_voi_plo_khong_khai_clo(): void
    {
        $kq = $this->build($this->khungCoCtdt([
            ['STT', 'Mã môn', 'Tên học phần', 'Tín chỉ', 'PLO1', 'PLO2'],
            ['I. KIẾN THỨC CHUNG', null, null, null, null, null],   // dòng tiêu đề nhóm: bỏ qua
            [1, '81002', 'PPNC', 3, 'R', null],
            [2, '81003', 'Tiền tệ', 3, 'm', 'A'],                   // chữ thường vẫn nhận
        ]));

        $this->assertSame([], $kq['errors']);
        $this->assertSame(0, Clo::count(), 'File khai báo chương trình không tạo CLO nào — CLO nhập ở phiếu từng môn');
        $this->assertSame(['PLO1' => 'R'], $this->mucCtdt('Khóa 2026', '81002'), 'PLO1 không chia PI ⇒ nối thẳng, hiện mã PLO1');
        $this->assertSame(['PI2.1' => 'A', 'PI2.2' => 'A', 'PLO1' => 'M'], $this->mucCtdt('Khóa 2026', '81003'),
            'Cột PLO2 đã chia PI ⇒ mức áp cho mọi PI của PLO2');
        $this->assertSame(1, Pi::where('is_dummy', true)->count());

        // Nhập lại, ghi riêng từng PI, bỏ trống PLO1 của 81003 ⇒ ô bỏ trống được gỡ, không để sót.
        $kq = $this->build($this->khungCoCtdt([
            ['Mã môn', 'PLO1', 'PI2.1', 'PI2.2'],
            ['81002', 'R', null, null],
            ['81003', null, 'M', null],
        ]));
        $this->assertSame([], $kq['errors']);
        $this->assertSame(['PI2.1' => 'M'], $this->mucCtdt('Khóa 2026', '81003'));
        $this->assertSame(2, CourseOutcomeLevel::count(), 'Nhập lại không nhân đôi');
    }

    /**
     * Đúng bảng khách gửi: header trộn cột PLO và cột PI (PLO2 đã chia PI nên ghi PI2.1/PI2.2), chừa sẵn
     * cột tới PLO10 dù ngành mới khai vài PLO. Cột chừa sẵn còn trống thì không được báo lỗi.
     */
    public function test_ma_tran_ctdt_tron_cot_plo_va_pi_va_cot_chua_san_de_trong(): void
    {
        $tieuDe = ['Mã môn', 'Tên môn (ghi cho dễ đọc)', 'PLO1', 'PI2.1', 'PI2.2', 'PLO3', 'PLO4', 'PLO5', 'PLO6', 'PLO7', 'PLO8', 'PLO9', 'PLO10'];
        $kq = $this->build($this->khungCoCtdt([
            $tieuDe,
            ['81002', 'Phương pháp nghiên cứu', 'R', null, 'R'],
            ['81003', 'Phân tích chính sách tiền tệ', 'M', 'M', null],
        ]));

        $this->assertSame([], $kq['errors'], 'Cột PLO3…PLO10 chưa khai nhưng để trống thì không phải lỗi');
        $this->assertSame(['PI2.2' => 'R', 'PLO1' => 'R'], $this->mucCtdt('Khóa 2026', '81002'));
        $this->assertSame(['PI2.1' => 'M', 'PLO1' => 'M'], $this->mucCtdt('Khóa 2026', '81003'));

        // Ghi mức vào cột chưa khai thì mới báo lỗi, và báo đúng cột đó.
        $loi = $this->build($this->khungCoCtdt([$tieuDe, ['81002', 'PPNC', 'R', null, null, 'M']]))['errors'];
        $this->assertStringContainsString("cột PLO3: không có PLO/PI mã 'PLO3'", implode("\n", $loi));
    }

    /** CTĐT ban hành theo khóa: file khóa sau đổi ma trận không làm đổi ma trận khóa trước. */
    public function test_ma_tran_ctdt_ap_cho_khoa_khai_trong_file(): void
    {
        $this->assertSame([], $this->build($this->khungCoCtdt([['Mã môn', 'PLO1'], ['81002', 'R']]))['errors']);
        $this->assertSame([], $this->build($this->khungCoCtdt([['Mã môn', 'PLO1'], ['81002', 'M']], 'Khóa 2027', 2027))['errors']);

        $this->assertSame(['PLO1' => 'R'], $this->mucCtdt('Khóa 2026', '81002'));
        $this->assertSame(['PLO1' => 'M'], $this->mucCtdt('Khóa 2027', '81002'));
    }

    public function test_ma_tran_ctdt_bao_loi_dung_cho(): void
    {
        $kq = $this->build($this->khungCoCtdt([
            ['Mã môn', 'PLO1', 'PLO2', 'PI2.1', 'PLO9'],
            ['81002', 'X', null, null, null],   // mức sai
            ['M99', 'R', null, null, null],      // môn chưa khai
            ['81003', null, null, null, null],   // không ghi mức nào
            ['81002', null, null, 'R', null],    // cột PI2.1 trùng với cột PLO2
            ['81003', null, null, null, 'R'],    // cột PLO9 chưa khai ở PLO_PI
            [null, 'R', null, null, null],       // có mức mà thiếu mã môn
        ]));
        $loi = implode("\n", $kq['errors']);

        $this->assertStringContainsString("mức 'X' không hợp lệ", $loi);
        $this->assertStringContainsString("không tìm thấy môn 'M99'", $loi);
        $this->assertStringContainsString('môn 81003 chưa ghi mức ở cột PLO/PI nào', $loi);
        $this->assertStringContainsString('cột PLO2 và cột PI2.1 cùng trỏ vào PI2.1', $loi);
        $this->assertStringContainsString("cột PLO9: không có PLO/PI mã 'PLO9'", $loi);
        $this->assertStringContainsString('có ghi mức nhưng thiếu Mã môn', $loi);
        $this->assertSame(0, CourseOutcomeLevel::count(), 'Dòng lỗi thì không ghi gì');

        $thieuCot = $this->build($this->khungCoCtdt([['Tên môn', 'PLO1'], ['PPNC', 'R']]));
        $this->assertStringContainsString('phải có cột "Mã môn"', implode("\n", $thieuCot['errors']));

        // File không khai khóa ⇒ không biết ma trận áp cho khóa nào.
        $khongKhoa = $this->build([
            'MonHoc' => [['ma_mon' => '81002', 'ten_mon' => 'PPNC', 'tin_chi' => 3]],
            'MaTran_CTDT' => [['Mã môn', 'PLO1'], ['81002', 'R']],
        ]);
        $this->assertStringContainsString('chưa khai khóa của ngành TCNH ở sheet Nganh_Khoa', implode("\n", $khongKhoa['errors']));
    }

    /** Khóa đã khai ma trận CTĐT ⇒ phiếu tải về chỉ có cột PLO/PI mà CTĐT ghi môn đó đóng góp. */
    public function test_phieu_lop_tai_ve_chi_co_cot_theo_ma_tran_ctdt(): void
    {
        $kq = $this->build($this->khungCoCtdt([
            ['Mã môn', 'PLO1', 'PLO2'],
            ['81002', 'R', null],
            ['81003', null, 'M'],
        ]));
        $this->assertSame([], $kq['errors']);

        $program = Program::where('code', 'TCNH')->firstOrFail();
        $cohortId = Cohort::where('program_id', $program->id)->firstOrFail()->id;
        $cot = function (string $mon) use ($program, $cohortId): array {
            $ws = (new ClassSheetTemplate())->build($this->school->id, $program->id, $cohortId, Course::where('code', $mon)->firstOrFail()->id)
                ->getSheetByName('KhaiBao');
            for ($r = 1; $r <= 40; $r++) {
                if ($ws->getCell("A{$r}")->getValue() === 'Chuẩn đầu ra') {
                    return array_values(array_filter(array_map(fn ($c) => $ws->getCell("{$c}{$r}")->getValue(), ['C', 'D', 'E', 'F'])));
                }
            }
            $this->fail('Không thấy khối Chuẩn đầu ra');
        };

        $this->assertSame(['PLO1'], $cot('81002'));
        $this->assertSame(['PI2.1', 'PI2.2'], $cot('81003'));
    }

    /** File mẫu tải về phải tự nhập được — mẫu mà nhập lỗi thì người điền theo mẫu cũng lỗi. */
    public function test_file_mau_nhap_duoc_khong_loi(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'khung') . '.xlsx';
        (new Xlsx((new StructureTemplateExport())->build()))->save($path);

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $import = new StructureImport($reader->listWorksheetNames($path));
        Excel::import($import, $path);
        $kq = app(StructureBuilderService::class)->build($import->data, $this->school->id);
        @unlink($path);

        $this->assertSame([], $kq['errors']);
        $this->assertSame(3, Course::where('school_id', $this->school->id)->count());
        $this->assertSame(0, Clo::count(), 'File mẫu không còn khai CLO');
        $this->assertSame(6, CourseOutcomeLevel::count(), 'Sheet MaTran_CTDT ví dụ trong file mẫu phải nhập được hết');
        $this->assertSame(2, Student::where('school_id', $this->school->id)->count());
        $this->assertEqualsWithDelta(1.0, (float) Pi::whereHas('plo', fn ($q) => $q->where('code', 'PLO2'))->sum('weight'), 0.0001);
    }

    /**
     * Xóa dòng ví dụ trong Excel vẫn để lại ô rỗng tới tận dòng cuối từng đụng tới. Kiểm thử trên
     * production: 200 dòng rỗng như vậy ra 393 lỗi "thiếu Mã PLO" dù khung dựng đúng.
     */
    public function test_dong_trong_sau_khi_xoa_vi_du_khong_bao_loi(): void
    {
        $book = (new StructureTemplateExport())->build();
        foreach (['PLO_PI' => 6, 'MonHoc' => 8, 'SinhVien' => 4] as $sheet => $cols) {
            $ws = $book->getSheetByName($sheet);
            for ($r = 2; $r <= 120; $r++) {
                for ($c = 1; $c <= $cols; $c++) {
                    $ws->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r, $r > 60 ? ' ' : null);
                }
            }
        }
        $book->getSheetByName('PLO_PI')->fromArray(['8340201', 'PLO1', 'Kiến thức'], null, 'A2');
        $book->getSheetByName('PLO_PI')->fromArray(['8340201', null, null, 'PI9.1', 'Thiếu mã PLO', 1], null, 'A7');
        $book->getSheetByName('MonHoc')->fromArray(['8340201', '81002', 'Phương pháp nghiên cứu', 3], null, 'A2');
        $book->removeSheetByIndex($book->getIndex($book->getSheetByName('MaTran_CTDT')));

        $path = tempnam(sys_get_temp_dir(), 'khung') . '.xlsx';
        (new Xlsx($book))->save($path);
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $import = new StructureImport($reader->listWorksheetNames($path));
        Excel::import($import, $path);
        $kq = app(StructureBuilderService::class)->build($import->data, $this->school->id);
        @unlink($path);

        $this->assertSame(['Sheet PLO_PI · dòng 7: thiếu Mã PLO.'], $kq['errors'], 'Chỉ dòng có dữ liệu mới được báo, và báo đúng số dòng');
        $this->assertSame(1, Course::where('school_id', $this->school->id)->count());
        $this->assertSame(0, Student::where('school_id', $this->school->id)->count());
    }
}
