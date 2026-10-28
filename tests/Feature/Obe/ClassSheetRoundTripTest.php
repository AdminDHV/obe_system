<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Exports\ClassSheetTemplate;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Đi đúng đường của trường: tải phiếu ĐIỀN SẴN → mở Excel điền → lưu → tải lên → đo.
 *
 * Test bằng file .xlsx thật (không phải mảng dựng tay) để bắt lệch giữa bố cục file mẫu và bộ đọc:
 * hai bên do hai class khác nhau viết, lệch một dòng là trường điền đúng mẫu vẫn bị báo lỗi.
 */
class ClassSheetRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_phieu_tai_ve_dien_diem_roi_nhap_lai_duoc(): void
    {
        (new MappingScaleWeightSeeder())->run();
        $school = School::create(['code' => 'RT', 'name' => 'Trường vòng', 'is_active' => true]);

        app(StructureBuilderService::class)->build(array_map('collect', [
            'Nganh_Khoa' => [['ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]],
            'PLO_PI' => [
                ['ma_plo' => 'PLO1', 'mo_ta_plo' => 'Kiến thức'],
                ['ma_plo' => 'PLO2', 'ma_pi' => 'PI2.1', 'trong_so_pi' => 1],
            ],
            'MonHoc' => [['ma_mon' => '81002', 'ten_mon' => 'Phương pháp nghiên cứu', 'tin_chi' => 3]],
            'SinhVien' => [
                ['mssv' => '0123', 'ho_va_ten' => 'Nguyễn Văn An', 'nam_khoa' => 2026],
                ['mssv' => '0124', 'ho_va_ten' => 'Trần Thị Bình', 'nam_khoa' => 2026],
            ],
        ]), $school->id);

        $program = Program::where('code', 'TCNH')->firstOrFail();
        $cohort = Cohort::where('program_id', $program->id)->firstOrFail();
        $course = Course::where('code', '81002')->firstOrFail();

        $book = (new ClassSheetTemplate())->build($school->id, $program->id, $cohort->id, $course->id);

        // Phiếu điền sẵn đúng thông tin môn, cột PLO/PI của ngành và danh sách sinh viên.
        $info = $book->getSheetByName('KhaiBao');
        $this->assertSame('81002', (string) $info->getCell('B3')->getValue());
        $this->assertSame('Khóa 2026', (string) $info->getCell('B6')->getValue());
        $gk = $book->getSheetByName('Giữa kỳ 1');
        $this->assertNotNull($gk, 'Môn chưa có bài đánh giá thì phiếu gợi ý sẵn bộ bài mặc định');
        $this->assertSame('0123', (string) $gk->getCell('B8')->getValue(), 'MSSV phải giữ số 0 ở đầu');

        // ── Trường điền phiếu ──
        $info->setCellValue('B7', '81002-A');
        $info->setCellValue('B8', 'Trần Thị Diện');
        $info->setCellValue('B11', 1);
        // Khối chuẩn đầu ra: dòng 13 là tiêu đề (A13 "Chuẩn đầu ra", C13 PLO1, D13 PI2.1).
        $this->assertSame('Chuẩn đầu ra', $info->getCell('A13')->getValue());
        $this->assertSame(['PLO1', 'PI2.1'], [$info->getCell('C13')->getValue(), $info->getCell('D13')->getValue()]);
        $info->fromArray([['CLO1', 'Phát triển vấn đề', 'R', null], ['CLO2', 'Đạo đức nghiên cứu', null, 'M']], null, 'A14', true);

        foreach ($book->getSheetNames() as $title) {
            if ($title === 'KhaiBao') {
                continue;
            }
            $ws = $book->getSheetByName($title);
            $ws->fromArray([['Tự luận', 'Tự luận'], [6, 4], ['CLO1', 'CLO2']], null, 'D2', true);
            $ws->setCellValue('D7', 'Câu 1');
            $ws->setCellValue('E7', 'Câu 2');
            $ws->setCellValue('F7', null);
            $ws->fromArray([[5, 3], [6, 4]], null, 'D8', true);
        }

        $path = tempnam(sys_get_temp_dir(), 'phieu') . '.xlsx';
        (new Xlsx($book))->save($path);

        // ── Tải lên ──
        $sheet = (new ClassSheetReader())->read($path);
        @unlink($path);
        $kq = app(ClassSheetService::class)->import($sheet, $school->id);

        $this->assertSame([], $kq['errors'], implode("\n", $kq['errors']));
        $this->assertSame(2, $kq['stats']['sinh_vien_trong_phieu']);

        // Mọi bài đều CLO1 = 5/6×10 và CLO2 = 3/4×10 cho SV 0123 ⇒ CLO1 = 8.3333, CLO2 = 7.5.
        $sv = Student::where('student_code', '0123')->firstOrFail();
        $clo1 = (float) $sv->cloResults()->whereHas('clo', fn ($q) => $q->where('code', 'CLO1'))->firstOrFail()->achieved_score;
        $this->assertEqualsWithDelta(8.3333, $clo1, 0.0005);
        $plo1 = (float) $sv->ploResults()->whereHas('plo', fn ($q) => $q->where('code', 'PLO1'))->firstOrFail()->achieved_score;
        $this->assertEqualsWithDelta(0.8333, $plo1, 0.0005, 'PLO1 không chia PI, đo thẳng từ CLO1');

        // ── Chuyên cần: khách khai "Chuyên cần 10%" như một bài đánh giá có điểm ──
        // Bản trống: hình thức của sheet Chuyên cần là "Chuyên cần" và danh sách chọn có mục này.
        $trong = (new ClassSheetTemplate())->build()->getSheetByName('Chuyên cần');
        $this->assertSame('Chuyên cần', $trong->getCell('D2')->getValue());
        $this->assertStringContainsString('Chuyên cần', $trong->getCell('D2')->getDataValidation()->getFormula1());

        // Môn đã có bài đánh giá: tải lại phiếu thì sheet Chuyên cần điền sẵn một cột Chuyên cần, tối đa 10.
        $lai = (new ClassSheetTemplate())->build($school->id, $program->id, $cohort->id, $course->id)->getSheetByName('Chuyên cần');
        $this->assertSame(['Chuyên cần', 'Chuyên cần', 10], [
            $lai->getCell('D7')->getValue(), $lai->getCell('D2')->getValue(), $lai->getCell('D3')->getValue(),
        ]);
        $this->assertNull($lai->getCell('D4')->getValue(), 'CLO của chuyên cần theo đề cương, giảng viên tự ghi');
        $this->assertSame('Giữa kỳ 1', $book->getSheetByName('Giữa kỳ 1')->getCell('B1')->getValue());
    }
}
