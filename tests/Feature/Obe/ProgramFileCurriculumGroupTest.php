<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Exports\StructureTemplateExport;
use App\Imports\StructureImport;
use App\Models\Course;
use App\Models\CurriculumGroup;
use App\Models\Program;
use App\Models\School;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\PiCoverageService;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * HỌC PHẦN BẮT BUỘC / TỰ CHỌN trong file khai báo chương trình (thầy Long hỏi 12/10/2026).
 *
 * Hai cột "Loại học phần" và "Khối / Hướng" của sheet MonHoc ghi vào ĐÚNG các khối của màn hình
 * Danh mục → Cấu trúc CTĐT (`curriculum_groups` + `program_courses.curriculum_group_id`) — không có
 * danh sách thứ hai để lệch nhau.
 */
class ProgramFileCurriculumGroupTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['code' => 'CTDT', 'name' => 'Trường thử khối', 'is_active' => true]);
    }

    /** @param array<int, array<string, mixed>> $monHoc */
    private function nhap(array $monHoc, array $them = []): array
    {
        $sheets = [
            'Nganh_Khoa' => [['ma_nganh' => 'QTKD', 'ten_nganh' => 'Quản trị kinh doanh', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]],
            'MonHoc' => $monHoc,
        ] + $them;

        return app(StructureBuilderService::class)->build(
            array_map(fn ($rows) => new Collection($rows), $sheets),
            $this->school->id,
        );
    }

    private function program(): Program
    {
        return Program::where('school_id', $this->school->id)->where('code', 'QTKD')->firstOrFail();
    }

    /** @return array<string, array{khoi: ?string, loai: ?string, bat_buoc: bool}> mã môn → khối */
    private function xep(): array
    {
        return DB::table('program_courses as pc')
            ->join('courses as c', 'c.id', '=', 'pc.course_id')
            ->leftJoin('curriculum_groups as g', 'g.id', '=', 'pc.curriculum_group_id')
            ->where('pc.program_id', $this->program()->id)
            ->orderBy('c.code')
            ->get(['c.code', 'g.name', 'g.kind', 'pc.is_mandatory'])
            ->mapWithKeys(fn ($r) => [$r->code => ['khoi' => $r->name, 'loai' => $r->kind, 'bat_buoc' => (bool) $r->is_mandatory]])
            ->all();
    }

    public function test_khai_bat_buoc_va_tu_chon_tao_khoi_va_xep_mon(): void
    {
        $kq = $this->nhap([
            ['ma_mon' => 'BUS501', 'ten_mon' => 'Quản trị học', 'tin_chi' => 3, 'loai_hoc_phan' => 'Bắt buộc'],
            ['ma_mon' => 'BUS502', 'ten_mon' => 'Kinh tế học', 'tin_chi' => 3, 'loai_hoc_phan' => 'bắt buộc'],
            ['ma_mon' => 'BUS638', 'ten_mon' => 'Quản trị bệnh viện', 'tin_chi' => 3, 'loai_hoc_phan' => 'Tự chọn', 'khoi_huong' => 'Hướng Y tế'],
            ['ma_mon' => 'BUS639', 'ten_mon' => 'Kinh tế y tế', 'tin_chi' => 3, 'loai_hoc_phan' => 'TC', 'khoi_huong' => 'hướng y tế'],
            ['ma_mon' => 'BUS643', 'ten_mon' => 'Marketing số', 'tin_chi' => 3, 'loai_hoc_phan' => 'Tự chọn', 'khoi_huong' => 'Hướng Marketing số'],
            ['ma_mon' => 'BUS700', 'ten_mon' => 'Môn chưa xếp', 'tin_chi' => 2],
        ]);

        $this->assertSame([], $kq['errors']);
        $this->assertSame(5, $kq['stats']['xep_khoi']);
        $this->assertSame(3, $kq['stats']['khoi_moi']);

        $this->assertSame([
            'BUS501' => ['khoi' => 'Khối bắt buộc', 'loai' => 'mandatory', 'bat_buoc' => true],
            'BUS502' => ['khoi' => 'Khối bắt buộc', 'loai' => 'mandatory', 'bat_buoc' => true],
            // "hướng y tế" viết thường vẫn vào chung khối với "Hướng Y tế" — không đẻ khối thứ hai.
            'BUS638' => ['khoi' => 'Hướng Y tế', 'loai' => 'elective', 'bat_buoc' => false],
            'BUS639' => ['khoi' => 'Hướng Y tế', 'loai' => 'elective', 'bat_buoc' => false],
            'BUS643' => ['khoi' => 'Hướng Marketing số', 'loai' => 'elective', 'bat_buoc' => false],
            // Không ghi gì thì chưa xếp khối — độ phủ coi như bắt buộc, như trước giờ.
            'BUS700' => ['khoi' => null, 'loai' => null, 'bat_buoc' => true],
        ], $this->xep());

        $this->assertSame(
            ['BAT_BUOC', 'HUONG_MARKETING_SO', 'HUONG_Y_TE'],
            CurriculumGroup::where('program_id', $this->program()->id)->orderBy('code')->pluck('code')->all(),
        );

        // Độ phủ đo lường đọc đúng cấu trúc vừa khai: bắt buộc (gồm môn chưa xếp) + từng hướng riêng.
        $info = app(PiCoverageService::class)->structureFor($this->program()->id);
        $id = fn (string $code) => (int) Course::where('code', $code)->value('id');
        $this->assertTrue($info['declared']);
        $bb = $info['mandatory_courses'];
        sort($bb);
        $ky = [$id('BUS501'), $id('BUS502'), $id('BUS700')];
        sort($ky);
        $this->assertSame($ky, $bb);
        $huong = collect($info['groups'])->where('kind', 'elective')->map(fn ($g) => count($g['course_ids']))->sort()->values()->all();
        $this->assertSame([1, 2], $huong, 'Hướng Marketing số 1 môn, Hướng Y tế 2 môn');
    }

    public function test_khop_khoi_da_khai_tren_man_hinh_theo_ma_hoac_ten(): void
    {
        $this->nhap([['ma_mon' => 'BUS501', 'ten_mon' => 'Quản trị học', 'tin_chi' => 3]]);
        $program = $this->program();
        CurriculumGroup::create(['school_id' => $this->school->id, 'program_id' => $program->id, 'code' => 'BB', 'name' => 'Khối kiến thức chung', 'kind' => 'mandatory', 'required_credits' => 45]);
        CurriculumGroup::create(['school_id' => $this->school->id, 'program_id' => $program->id, 'code' => 'YT', 'name' => 'Hướng Quản trị bệnh viện & Y tế', 'kind' => 'elective', 'required_credits' => 15]);

        $kq = $this->nhap([
            // Bắt buộc bỏ trống khối ⇒ vào khối bắt buộc DUY NHẤT đã có, không tạo "Khối bắt buộc" mới.
            ['ma_mon' => 'BUS501', 'ten_mon' => 'Quản trị học', 'tin_chi' => 3, 'loai_hoc_phan' => 'Bắt buộc'],
            ['ma_mon' => 'BUS638', 'ten_mon' => 'Quản trị bệnh viện', 'tin_chi' => 3, 'khoi_huong' => 'yt'],
            ['ma_mon' => 'BUS639', 'ten_mon' => 'Kinh tế y tế', 'tin_chi' => 3, 'loai_hoc_phan' => 'Tự chọn', 'khoi_huong' => 'HƯỚNG QUẢN TRỊ BỆNH VIỆN & Y TẾ'],
        ]);

        $this->assertSame([], $kq['errors']);
        $this->assertSame(0, $kq['stats']['khoi_moi']);
        $xep = $this->xep();
        $this->assertSame('Khối kiến thức chung', $xep['BUS501']['khoi']);
        $this->assertSame('Hướng Quản trị bệnh viện & Y tế', $xep['BUS638']['khoi']);
        $this->assertSame('Hướng Quản trị bệnh viện & Y tế', $xep['BUS639']['khoi']);
        $this->assertSame(2, CurriculumGroup::count());
        $this->assertEqualsWithDelta(45.0, (float) CurriculumGroup::where('code', 'BB')->value('required_credits'), 0.001, 'Nhập file không đụng tín chỉ yêu cầu của khối');
    }

    public function test_file_khong_co_hai_cot_thi_giu_nguyen_khoi_da_xep(): void
    {
        $this->nhap([['ma_mon' => 'BUS638', 'ten_mon' => 'Quản trị bệnh viện', 'tin_chi' => 3, 'loai_hoc_phan' => 'Tự chọn', 'khoi_huong' => 'Hướng Y tế']]);

        // File 1 kiểu cũ (không có hai cột) nhập lại: không được xóa khối, không được lật cờ.
        $kq = $this->nhap([['ma_mon' => 'BUS638', 'ten_mon' => 'Quản trị bệnh viện (sửa tên)', 'tin_chi' => 3]]);

        $this->assertSame([], $kq['errors']);
        $this->assertSame(['khoi' => 'Hướng Y tế', 'loai' => 'elective', 'bat_buoc' => false], $this->xep()['BUS638']);
        $this->assertSame('Quản trị bệnh viện (sửa tên)', Course::where('code', 'BUS638')->value('name'));
    }

    public function test_chuyen_mon_sang_khoi_khac(): void
    {
        $this->nhap([['ma_mon' => 'BUS638', 'ten_mon' => 'Quản trị bệnh viện', 'tin_chi' => 3, 'loai_hoc_phan' => 'Tự chọn', 'khoi_huong' => 'Hướng Y tế']]);
        $kq = $this->nhap([['ma_mon' => 'BUS638', 'ten_mon' => 'Quản trị bệnh viện', 'tin_chi' => 3, 'loai_hoc_phan' => 'Bắt buộc']]);

        $this->assertSame([], $kq['errors']);
        $this->assertSame(['khoi' => 'Khối bắt buộc', 'loai' => 'mandatory', 'bat_buoc' => true], $this->xep()['BUS638']);
    }

    public function test_cac_loi_bao_dung_dong_va_mon_van_duoc_tao(): void
    {
        $kq = $this->nhap([
            ['ma_mon' => 'M1', 'ten_mon' => 'Tự chọn thiếu khối', 'tin_chi' => 3, 'loai_hoc_phan' => 'Tự chọn'],
            ['ma_mon' => 'M2', 'ten_mon' => 'Loại lạ', 'tin_chi' => 3, 'loai_hoc_phan' => 'Nửa bắt buộc'],
            ['ma_mon' => 'M3', 'ten_mon' => 'Khối mới không ghi loại', 'tin_chi' => 3, 'khoi_huong' => 'Hướng chưa có'],
            ['ma_mon' => 'M4', 'ten_mon' => 'Mở khối tự chọn', 'tin_chi' => 3, 'loai_hoc_phan' => 'Tự chọn', 'khoi_huong' => 'Hướng A'],
            ['ma_mon' => 'M5', 'ten_mon' => 'Khối lệch loại', 'tin_chi' => 3, 'loai_hoc_phan' => 'Bắt buộc', 'khoi_huong' => 'Hướng A'],
        ]);

        $this->assertSame([
            'Sheet Mon · dòng 2: môn M1 là học phần tự chọn nhưng chưa ghi Khối / Hướng — ghi tên hướng chuyên ngành của môn.',
            "Sheet Mon · dòng 3: Loại học phần 'Nửa bắt buộc' không hiểu — ghi \"Bắt buộc\" hoặc \"Tự chọn\".",
            "Sheet Mon · dòng 4: ngành QTKD chưa có khối 'Hướng chưa có' — ghi thêm Loại học phần (Bắt buộc / Tự chọn) để hệ thống tạo khối này.",
            "Sheet Mon · dòng 6: khối 'Hướng A' là khối tự chọn nhưng dòng này ghi Bắt buộc — sửa cho khớp.",
        ], $kq['errors']);

        // Như mọi lỗi của sheet này: môn vẫn được tạo, chỉ chưa xếp khối.
        $xep = $this->xep();
        $this->assertSame(['M1', 'M2', 'M3', 'M4', 'M5'], array_keys($xep));
        $this->assertNull($xep['M1']['khoi']);
        $this->assertNull($xep['M5']['khoi']);
        $this->assertSame('Hướng A', $xep['M4']['khoi']);
        $this->assertSame(['Hướng A'], CurriculumGroup::pluck('name')->all(), 'Dòng lỗi không được đẻ khối');
    }

    public function test_nhieu_khoi_bat_buoc_thi_phai_ghi_ro_khoi(): void
    {
        $this->nhap([['ma_mon' => 'M1', 'ten_mon' => 'Môn 1', 'tin_chi' => 3]]);
        $program = $this->program();
        foreach (['DC' => 'Đại cương', 'CS' => 'Cơ sở ngành'] as $code => $name) {
            CurriculumGroup::create(['school_id' => $this->school->id, 'program_id' => $program->id, 'code' => $code, 'name' => $name, 'kind' => 'mandatory']);
        }

        $kq = $this->nhap([
            ['ma_mon' => 'M1', 'ten_mon' => 'Môn 1', 'tin_chi' => 3, 'loai_hoc_phan' => 'Bắt buộc'],
            ['ma_mon' => 'M2', 'ten_mon' => 'Môn 2', 'tin_chi' => 3, 'loai_hoc_phan' => 'Bắt buộc', 'khoi_huong' => 'Cơ sở ngành'],
        ]);

        $this->assertSame(['Sheet Mon · dòng 2: ngành QTKD có nhiều khối bắt buộc (Đại cương, Cơ sở ngành) — ghi rõ Khối / Hướng của môn M1.'], $kq['errors']);
        $this->assertSame('Cơ sở ngành', $this->xep()['M2']['khoi']);
    }

    /** Phiếu lớp nhập sau đó từng ghi đè cờ thành "bắt buộc" cho mọi môn — môn tự chọn bị lật. */
    public function test_nop_phieu_lop_khong_lat_mon_tu_chon_thanh_bat_buoc(): void
    {
        (new MappingScaleWeightSeeder())->run();
        $kq = $this->nhap(
            [['ma_mon' => 'BUS638', 'ten_mon' => 'Quản trị bệnh viện', 'tin_chi' => 3, 'loai_hoc_phan' => 'Tự chọn', 'khoi_huong' => 'Hướng Y tế']],
            ['PLO_PI' => [['ma_plo' => 'PLO1', 'mo_ta_plo' => 'PLO1']]],
        );
        $this->assertSame([], $kq['errors']);

        $phieu = app(ClassSheetService::class)->import((new ClassSheetReader())->parse([
            'KhaiBao' => [
                2 => ['A' => 'Mã môn học', 'B' => 'BUS638'],
                4 => ['A' => 'Mã ngành', 'B' => 'QTKD'],
                5 => ['A' => 'Khóa', 'B' => 'Khóa 2026'],
                6 => ['A' => 'Mã lớp học phần', 'B' => 'BUS638-01'],
                7 => ['A' => 'Giảng viên giảng dạy', 'B' => 'Lê Văn Phúc'],
                11 => ['A' => 'Chuẩn đầu ra', 'B' => 'Nội dung', 'C' => 'PLO1'],
                12 => ['A' => 'CLO1', 'B' => 'Hiểu quản trị bệnh viện', 'C' => 'R'],
                15 => ['A' => 'Đánh giá', 'B' => 'Loại', 'C' => 'Trọng số (%)'],
                16 => ['A' => 'Cuối kỳ', 'B' => 'Cuối kỳ', 'C' => 100],
            ],
            'Cuối kỳ' => [
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận'],
                3 => ['A' => 'Điểm tối đa', 'D' => 10],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1'],
                8 => ['A' => 1, 'B' => 'SV01', 'C' => 'Nguyễn Văn An', 'D' => 8],
            ],
        ]), $this->school->id);
        $this->assertSame([], $phieu['errors']);

        $this->assertSame(['khoi' => 'Hướng Y tế', 'loai' => 'elective', 'bat_buoc' => false], $this->xep()['BUS638']);
    }

    /** File mẫu tải về phải tự nhập được, và ví dụ hai cột mới phải ra đúng khối. */
    public function test_file_mau_co_hai_cot_va_doc_lai_dung(): void
    {
        $book = (new StructureTemplateExport())->build();
        $ws = $book->getSheetByName('MonHoc');
        $this->assertSame('Loại học phần', $ws->getCell('G1')->getValue());
        $this->assertSame('Khối / Hướng', $ws->getCell('H1')->getValue());
        $this->assertSame('"Bắt buộc,Tự chọn"', $ws->getCell('G2')->getDataValidation()->getFormula1());

        $path = tempnam(sys_get_temp_dir(), 'khung') . '.xlsx';
        (new Xlsx($book))->save($path);
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $import = new StructureImport($reader->listWorksheetNames($path));
        Excel::import($import, $path);
        $kq = app(StructureBuilderService::class)->build($import->data, $this->school->id);
        @unlink($path);

        $this->assertSame([], $kq['errors']);
        $program = Program::where('school_id', $this->school->id)->where('code', '8340201')->firstOrFail();
        $khoi = DB::table('program_courses as pc')
            ->join('courses as c', 'c.id', '=', 'pc.course_id')
            ->join('curriculum_groups as g', 'g.id', '=', 'pc.curriculum_group_id')
            ->where('pc.program_id', $program->id)
            ->orderBy('c.code')
            ->pluck('g.name', 'c.code')->all();
        $this->assertSame([
            '81001' => 'Khối bắt buộc',
            '81002' => 'Khối bắt buộc',
            '81003' => 'Hướng Tài chính doanh nghiệp',
        ], $khoi);
    }
}
