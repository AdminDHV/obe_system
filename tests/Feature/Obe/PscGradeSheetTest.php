<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\CourseClass;
use App\Models\School;
use App\Models\User;
use App\Services\Export\PscGradeSheetService;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BẢNG ĐIỂM CHO PHẦN MỀM QUẢN LÝ ĐÀO TẠO — giảng viên tải chính file của phần mềm đó lên, hệ thống
 * điền điểm vào đúng cột rồi trả lại. File mẫu có mấy dòng tiêu đề gộp ở trên, cột "Mã SV" không ở
 * dòng 1, và các cột thông tin (lớp hành chính, tình trạng, nợ phí) mà hệ thống này không giữ —
 * nên phải giữ nguyên chúng, chỉ chạm vào cột điểm.
 */
class PscGradeSheetTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        (new MappingScaleWeightSeeder())->run();

        $this->school = School::create(['code' => 'PSC', 'name' => 'Trường thử PSC', 'is_active' => true]);

        app(StructureBuilderService::class)->build([
            'Nganh_Khoa' => collect([['ma_nganh' => 'LUAT', 'ten_nganh' => 'Luật', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]]),
            'MonHoc' => collect([['ma_mon' => 'M01', 'ten_mon' => 'Tố tụng dân sự', 'tin_chi' => 3]]),
            'PLO_PI' => collect([['ma_plo' => 'PLO1', 'mo_ta_plo' => 'PLO1', 'ma_pi' => 'PI1.1', 'mo_ta_pi' => 'PI1.1', 'trong_so' => 1]]),
        ], $this->school->id);

        $kq = app(ClassSheetService::class)->import((new ClassSheetReader())->parse($this->phieu()), $this->school->id);

        $this->assertSame([], $kq['errors']);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function phieu(): array
    {
        return [
            'KhaiBao' => [
                2 => ['A' => 'Mã môn học', 'B' => 'M01'],
                3 => ['A' => 'Tên môn học', 'B' => 'Tố tụng dân sự'],
                4 => ['A' => 'Mã ngành', 'B' => 'LUAT'],
                5 => ['A' => 'Khóa', 'B' => 'Khóa 2026'],
                6 => ['A' => 'Mã lớp học phần', 'B' => 'LT07A'],
                7 => ['A' => 'Giảng viên giảng dạy', 'B' => 'Trần Thị Diện'],
                8 => ['A' => 'Năm học', 'B' => '2026-2027'],
                9 => ['A' => 'Học kỳ', 'B' => 1],
                11 => ['A' => 'Chuẩn đầu ra', 'B' => 'Nội dung', 'C' => 'PI1.1'],
                12 => ['A' => 'CLO1', 'B' => 'Hiểu nguyên tắc tố tụng', 'C' => 'M'],
                15 => ['A' => 'Đánh giá', 'B' => 'Loại', 'C' => 'Trọng số (%)'],
                16 => ['A' => 'Giữa kỳ', 'B' => 'Quá trình', 'C' => 40],
                17 => ['A' => 'Cuối kỳ', 'B' => 'Cuối kỳ', 'C' => 60],
            ],
            'Giữa kỳ' => [
                2 => ['A' => 'Hình thức', 'D' => 'Trắc nghiệm'],
                3 => ['A' => 'Điểm tối đa', 'D' => 10],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1'],
                8 => ['A' => 1, 'B' => '2305LT0235', 'C' => 'Nguyễn Ngọc Quế Anh', 'D' => 6.3],
                9 => ['A' => 2, 'B' => '2305LT0758', 'C' => 'Dương Ngọc Ánh', 'D' => 10],
            ],
            'Cuối kỳ' => [
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận'],
                3 => ['A' => 'Điểm tối đa', 'D' => 10],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1'],
                8 => ['A' => 1, 'B' => '2305LT0235', 'C' => 'Nguyễn Ngọc Quế Anh', 'D' => 9],
                9 => ['A' => 2, 'B' => '2305LT0758', 'C' => 'Dương Ngọc Ánh', 'D' => 10],
            ],
        ];
    }

    /** File dựng giống hệt file phần mềm đào tạo xuất ra: 3 dòng gộp, tiêu đề ở dòng 4. */
    private function fileMau(): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();

        $sheet->fromArray([
            ['Thông tin sinh viên', null, null, null, null, null, null, 'Điểm thành phần chi tiết', null],
            [null, null, null, null, null, null, null, 'Điểm QT (40%)', 'Thi (60%)'],
            [null, null, null, null, null, null, null, 'K1 (40%)', 'Thi LT (60%)'],
            ['Mã SV', 'Họ là tên lót', 'Tên', 'Ngày sinh', 'Lớp', 'Tình trạng', 'Nợ phí', 'K1#1', '_ASS101#1'],
            ['2305LT0235', 'Nguyễn Ngọc Quế', 'Anh', '04/12/2005', 'LT07A', 'Còn học', null, null, null],
            ['2305LT0758', 'Dương Ngọc', 'Ánh', '28/07/2005', 'LT07A', 'Còn học', null, null, null],
            ['9999ZZ0001', 'Người', 'Lạ', '01/01/2005', 'LT07A', 'Còn học', null, null, null],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'psc') . '.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return $path;
    }

    public function test_doc_dung_dong_tieu_de_va_cac_cot_diem(): void
    {
        $kq = app(PscGradeSheetService::class)->inspect($this->fileMau());

        $this->assertSame(4, $kq['header_row']);
        $this->assertSame('A', $kq['code_col']);
        $this->assertSame(3, $kq['students']);
        $this->assertSame(['K1#1', '_ASS101#1'], array_column($kq['columns'], 'label'));
        $this->assertSame('Điểm thành phần chi tiết · Điểm QT (40%) · K1 (40%)', $kq['columns'][0]['group']);
    }

    public function test_doan_san_cot_nao_lay_diem_bai_nao(): void
    {
        $service = app(PscGradeSheetService::class);
        $class = CourseClass::where('class_code', 'LT07A')->firstOrFail();

        $map = $service->suggestMapping($service->inspect($this->fileMau())['columns'], $class);

        $gk = \App\Models\Assessment::where('syllabus_id', $class->syllabus_id)->where('name', 'Giữa kỳ')->value('id');
        $ck = \App\Models\Assessment::where('syllabus_id', $class->syllabus_id)->where('name', 'Cuối kỳ')->value('id');

        $this->assertSame((string) $gk, $map['H']);
        $this->assertSame((string) $ck, $map['I']);
    }

    public function test_dien_diem_vao_dung_o_va_giu_nguyen_cot_khac(): void
    {
        $service = app(PscGradeSheetService::class);
        $class = CourseClass::where('class_code', 'LT07A')->firstOrFail();

        $source = $this->fileMau();
        $map = $service->suggestMapping($service->inspect($source)['columns'], $class);

        $out = tempnam(sys_get_temp_dir(), 'pscout') . '.xlsx';
        $kq = $service->fill($source, $class, $map, $out);

        $this->assertSame(2, $kq['filled']);
        $this->assertSame(['9999ZZ0001'], $kq['missing']);

        $sheet = IOFactory::load($out)->getActiveSheet();
        $this->assertEqualsWithDelta(6.3, (float) $sheet->getCell('H5')->getValue(), 0.001);
        $this->assertEqualsWithDelta(9.0, (float) $sheet->getCell('I5')->getValue(), 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $sheet->getCell('H6')->getValue(), 0.001);

        // Sinh viên không có trong hệ thống: ô điểm để nguyên, không ghi 0.
        $this->assertNull($sheet->getCell('H7')->getValue());

        // Các cột của phần mềm đào tạo giữ nguyên từng chữ.
        $this->assertSame('Nguyễn Ngọc Quế', $sheet->getCell('B5')->getValue());
        $this->assertSame('Còn học', $sheet->getCell('F5')->getValue());
        $this->assertSame('K1#1', $sheet->getCell('H4')->getValue());

        @unlink($source);
        @unlink($out);
    }

    /**
     * File thật của trường (Tố tụng dân sự, anh Long gửi 03/10/2026) ghi VT ở cột thi cho học viên vắng.
     * Hệ thống điền lại cũng phải ra VT — không để trống, không ghi 0.
     */
    public function test_hoc_vien_vang_thi_duoc_dien_vt(): void
    {
        $p = $this->phieu();
        $p['Giữa kỳ'][10] = ['A' => 3, 'B' => '2305LT0719', 'C' => 'Đinh Thị Trà My', 'D' => 0];
        $p['Cuối kỳ'][10] = ['A' => 3, 'B' => '2305LT0719', 'C' => 'Đinh Thị Trà My', 'D' => 'VT'];
        $kq = app(ClassSheetService::class)->import((new ClassSheetReader())->parse($p), $this->school->id);
        $this->assertSame([], $kq['errors']);

        $service = app(PscGradeSheetService::class);
        $class = CourseClass::where('class_code', 'LT07A')->firstOrFail();

        $book = IOFactory::load($this->fileMau());
        $book->getActiveSheet()->fromArray([['2305LT0719', 'Đinh Thị Trà', 'My', '10/06/2005', 'LT07A', 'Còn học', null, null, null]], null, 'A8');
        $source = tempnam(sys_get_temp_dir(), 'psc') . '.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($source);

        $map = $service->suggestMapping($service->inspect($source)['columns'], $class);
        $out = tempnam(sys_get_temp_dir(), 'pscout') . '.xlsx';
        $service->fill($source, $class, $map, $out);

        $sheet = IOFactory::load($out)->getActiveSheet();
        $this->assertEqualsWithDelta(0.0, (float) $sheet->getCell('H8')->getValue(), 0.001, 'Điểm quá trình 0 vẫn ghi 0');
        $this->assertSame('VT', $sheet->getCell('I8')->getValue(), 'Vắng thi ghi VT');
        $this->assertEqualsWithDelta(9.0, (float) $sheet->getCell('I5')->getValue(), 0.001);

        @unlink($source);
        @unlink($out);
    }

    public function test_tai_len_va_tai_ve_qua_dung_duong_dan_man_hinh_goi(): void
    {
        foreach (['super_admin', 'lecturer', 'viewer'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::factory()->create(['is_active' => true, 'school_id' => $this->school->id]);
        $admin->assignRole('super_admin');

        $class = CourseClass::where('class_code', 'LT07A')->firstOrFail();
        $qs = '?school_id=' . $this->school->id;

        $this->actingAs($admin)->get(route('manager.classes.psc.form', $class->uuid) . $qs)->assertOk();

        $res = $this->actingAs($admin)->post(route('manager.classes.psc.inspect', $class->uuid) . $qs, [
            'file' => new UploadedFile($this->fileMau(), 'bang-diem.xlsx', null, null, true),
        ]);
        $res->assertOk();

        $props = $res->viewData('page')['props'];
        $this->assertSame(4, $props['inspection']['header_row']);
        $this->assertNotEmpty($props['token']);

        $tai = $this->actingAs($admin)->get(
            route('manager.classes.psc.fill', $class->uuid) . '?' . http_build_query([
                'school_id' => $this->school->id,
                'token' => $props['token'],
                'file_name' => 'bang-diem.xlsx',
                'mapping' => $props['mapping'],
            ]),
        );

        $tai->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $tai->headers->get('Content-Type'));
    }

    public function test_file_khong_phai_bang_diem_thi_bao_loi_ro_rang(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['Họ tên', 'Điểm']], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'psc') . '.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Mã SV/');

        app(PscGradeSheetService::class)->inspect($path);
    }
}
