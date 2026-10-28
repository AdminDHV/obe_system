<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Exports\ClassSheetTemplate;
use App\Exports\CourseFrameTemplate;
use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\FileBatch;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\User;
use App\Services\Import\ClassSheetReader;
use App\Services\Import\CourseFrameReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\CourseFrameService;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * KHUNG HỌC PHẦN THEO ĐỢT — trường chốt 03/10/2026: kỹ thuật dựng khung một lần cho cả khóa, giáo
 * viên chỉ còn một việc là nộp điểm.
 *
 * Số kỳ vọng như ClassSheetImportTest (cùng phiếu, cùng khung 40/60):
 *   SV01  CLO1 = 0,4 × TB(3/4, 1,5/3)×10 + 0,6 × 4/5×10 = 7,3
 */
class CourseFrameImportTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        (new MappingScaleWeightSeeder())->run();

        $this->school = School::create(['code' => 'KHUNG-T', 'name' => 'Trường thử khung', 'is_active' => true]);

        $kq = app(StructureBuilderService::class)->build([
            'Nganh' => collect([['ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính – Ngân hàng', 'khoa' => 'Khoa TCNH']]),
            'Khoa' => collect([['ma_nganh' => 'TCNH', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]]),
            'Mon' => collect([
                ['ma_mon' => 'M01', 'ten_mon' => 'Môn thử', 'so_tin_chi' => 3, 'ma_nganh' => 'TCNH'],
                ['ma_mon' => 'M02', 'ten_mon' => 'Môn chưa dựng', 'so_tin_chi' => 2, 'ma_nganh' => 'TCNH'],
            ]),
            'PLO' => collect([
                ['ma_nganh' => 'TCNH', 'ma_plo' => 'PLO1', 'mo_ta' => 'PLO1'],
                ['ma_nganh' => 'TCNH', 'ma_plo' => 'PLO2', 'mo_ta' => 'PLO2'],
            ]),
            'PI' => collect([['ma_nganh' => 'TCNH', 'ma_plo' => 'PLO1', 'ma_pi' => 'PI1.1', 'mo_ta' => 'PI1.1', 'trong_so' => 1]]),
        ], $this->school->id);
        $this->assertSame([], $kq['errors']);
    }

    /** File khung: Mã ngành / Khóa / Mã môn để trống thì lấy theo dòng trên. */
    private function khung(): array
    {
        return [
            'CLO' => [
                1 => ['A' => 'Mã ngành', 'B' => 'Khóa', 'C' => 'Mã môn', 'D' => 'Tên môn', 'E' => 'Mã CLO', 'F' => 'Nội dung CLO', 'G' => 'PI1.1', 'H' => 'PLO2'],
                2 => ['A' => 'TCNH', 'B' => 'Khóa 2026', 'C' => 'M01', 'D' => 'Môn thử', 'E' => 'CLO1', 'F' => 'Phát triển vấn đề nghiên cứu', 'G' => 'M'],
                3 => ['E' => 'CLO2', 'F' => 'Tuân thủ đạo đức nghiên cứu', 'H' => 'r'],
                4 => ['C' => 'M02', 'D' => 'Môn chưa dựng'],
            ],
            'DanhGia' => [
                1 => ['A' => 'Mã ngành', 'B' => 'Khóa', 'C' => 'Mã môn', 'D' => 'Tên môn', 'E' => 'Tên bài', 'F' => 'Loại', 'G' => 'Trọng số (%)'],
                2 => ['A' => 'TCNH', 'B' => 'Khóa 2026', 'C' => 'M01', 'E' => 'Giữa kỳ 1', 'F' => 'Quá trình', 'G' => 40],
                3 => ['E' => 'Cuối kỳ', 'F' => 'Cuối kỳ', 'G' => 60],
                // Dòng bài mẫu của môn chưa khai CLO: bỏ qua, không bắt xóa.
                4 => ['C' => 'M02', 'E' => 'Chuyên cần', 'F' => 'Quá trình', 'G' => 10],
                5 => ['E' => 'Cuối kỳ', 'F' => 'Cuối kỳ', 'G' => 90],
            ],
            'LopHocPhan' => [
                1 => ['A' => 'Mã ngành', 'B' => 'Khóa', 'C' => 'Mã môn', 'D' => 'Tên môn', 'E' => 'Mã lớp học phần', 'F' => 'Giảng viên', 'G' => 'Năm học', 'H' => 'Học kỳ'],
                2 => ['A' => 'TCNH', 'B' => 'Khóa 2026', 'C' => 'M01', 'E' => 'M01-A', 'F' => 'Trần Thị Diện', 'G' => '2026-2027', 'H' => 1],
            ],
        ];
    }

    /** Phiếu giáo viên — phần KhaiBao cố ý lệch khung (trọng số 50/50, CLO2 không nối gì). */
    private function phieu(string $maLop = 'M01-A'): array
    {
        return [
            'KhaiBao' => [
                2 => ['A' => 'Mã môn học', 'B' => 'M01'],
                4 => ['A' => 'Mã ngành', 'B' => 'TCNH'],
                5 => ['A' => 'Khóa', 'B' => 'Khóa 2026'],
                6 => ['A' => 'Mã lớp học phần', 'B' => $maLop],
                7 => ['A' => 'Giảng viên giảng dạy', 'B' => 'Trần Thị Diện'],
                8 => ['A' => 'Năm học', 'B' => '2026-2027'],
                9 => ['A' => 'Học kỳ', 'B' => 1],
                11 => ['A' => 'Chuẩn đầu ra', 'B' => 'Nội dung', 'C' => 'PI1.1', 'D' => 'PLO2'],
                12 => ['A' => 'CLO1', 'B' => 'Phát triển vấn đề nghiên cứu', 'C' => 'M'],
                13 => ['A' => 'CLO2', 'B' => 'Tuân thủ đạo đức nghiên cứu'],
                15 => ['A' => 'Đánh giá', 'B' => 'Loại', 'C' => 'Trọng số (%)'],
                16 => ['A' => 'Giữa kỳ 1', 'B' => 'Quá trình', 'C' => 50],
                17 => ['A' => 'Cuối kỳ', 'B' => 'Cuối kỳ', 'C' => 50],
            ],
            'Giữa kỳ 1' => [
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận', 'E' => 'Tự luận', 'F' => 'Trắc nghiệm'],
                3 => ['A' => 'Điểm tối đa', 'D' => 4, 'E' => 3, 'F' => 3],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1', 'E' => 'CLO1', 'F' => 'CLO2'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1', 'E' => 'Câu 2', 'F' => 'Câu 3'],
                8 => ['A' => 1, 'B' => 'SV01', 'C' => 'Nguyễn Văn An', 'D' => 3, 'E' => 1.5, 'F' => 2],
            ],
            'Cuối kỳ' => [
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận', 'E' => 'Vấn đáp'],
                3 => ['A' => 'Điểm tối đa', 'D' => 5, 'E' => 5],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1', 'E' => 'CLO2'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1', 'E' => 'Câu 2'],
                8 => ['A' => 1, 'B' => 'SV01', 'C' => 'Nguyễn Văn An', 'D' => 4, 'E' => 5],
            ],
        ];
    }

    private function dungKhung(?array $sheets = null): array
    {
        return app(CourseFrameService::class)->import((new CourseFrameReader())->parse($sheets ?? $this->khung()), $this->school->id);
    }

    private function nopPhieu(array $sheets): array
    {
        return app(ClassSheetService::class)->import((new ClassSheetReader())->parse($sheets), $this->school->id);
    }

    private function syllabusM01(): CourseSyllabus
    {
        return CourseSyllabus::where('course_id', Course::where('code', 'M01')->value('id'))->firstOrFail();
    }

    public function test_ky_thuat_dung_khung_mot_lan_cho_ca_dot(): void
    {
        $kq = $this->dungKhung();

        $this->assertSame([], $kq['errors']);
        $this->assertSame(['mon' => 1, 'clo' => 2, 'anh_xa' => 2, 'bai_danh_gia' => 2, 'lop' => 1], $kq['stats']);
        $this->assertStringContainsString('1 môn chỉ có bài đánh giá, chưa có CLO nên chưa dựng khung lần này: M02', implode("\n", $kq['warnings']));

        $syllabus = $this->syllabusM01();
        $this->assertNotNull($syllabus->frame_locked_at);
        $this->assertSame(['CLO1', 'CLO2'], Clo::where('syllabus_id', $syllabus->id)->orderBy('code')->pluck('code')->all());
        $this->assertSame(2, CloPiMapping::whereIn('clo_id', Clo::where('syllabus_id', $syllabus->id)->pluck('id'))->count());
        $this->assertEqualsWithDelta(60.0, (float) Assessment::where('syllabus_id', $syllabus->id)->where('name', 'Cuối kỳ')->value('weight_percent'), 0.001);

        $lop = CourseClass::with('lecturer')->where('class_code', 'M01-A')->firstOrFail();
        $this->assertSame($syllabus->id, $lop->syllabus_id);
        $this->assertSame('Trần Thị Diện', $lop->lecturer->full_name);
        $this->assertSame(1, (int) $lop->semester);

        // Môn chỉ có dòng bài mẫu thì chưa dựng gì.
        $this->assertSame(0, CourseSyllabus::where('course_id', Course::where('code', 'M02')->value('id'))->whereNotNull('frame_locked_at')->count());
    }

    public function test_giao_vien_chi_nop_diem_khai_bao_trong_phieu_khong_doi_duoc_khung(): void
    {
        $this->assertSame([], $this->dungKhung()['errors']);

        $kq = $this->nopPhieu($this->phieu());

        // CLO2 không nối gì, trọng số 50/50 — với phiếu thường là lỗi; môn đã dựng khung thì bỏ qua phần đó.
        $this->assertSame([], $kq['errors']);
        $this->assertStringContainsString('đã dựng khung', implode("\n", $kq['warnings']));

        $syllabus = $this->syllabusM01();
        $this->assertEqualsWithDelta(40.0, (float) Assessment::where('syllabus_id', $syllabus->id)->where('name', 'Giữa kỳ 1')->value('weight_percent'), 0.001);
        $this->assertSame(2, CloPiMapping::whereIn('clo_id', Clo::where('syllabus_id', $syllabus->id)->pluck('id'))->count());

        $clo1 = (float) Student::where('student_code', 'SV01')->firstOrFail()
            ->cloResults()->whereHas('clo', fn ($q) => $q->where('code', 'CLO1'))->value('achieved_score');
        $this->assertEqualsWithDelta(7.3, $clo1, 0.0005, 'Đo theo trọng số 40/60 của khung, không theo 50/50 của phiếu');
    }

    public function test_lop_khong_co_trong_khung_bi_chan(): void
    {
        $this->assertSame([], $this->dungKhung()['errors']);

        $kq = $this->nopPhieu($this->phieu('M01-Z'));

        $this->assertStringContainsString("lớp 'M01-Z' không có trong khung học phần của Khóa 2026", implode("\n", $kq['errors']));
        $this->assertSame(0, StudentGrade::count());
    }

    public function test_cau_hoi_do_clo_ngoai_khung_bi_chan(): void
    {
        $this->assertSame([], $this->dungKhung()['errors']);

        $p = $this->phieu();
        $p['Cuối kỳ'][4]['E'] = 'CLO3';

        $this->assertStringContainsString("CLO 'CLO3'", implode("\n", $this->nopPhieu($p)['errors']));
    }

    public function test_khung_loi_bao_dung_cho_va_khong_ghi_gi(): void
    {
        $k = $this->khung();
        $k['DanhGia'][3]['G'] = 50;          // 40 + 50 ≠ 100
        $k['CLO'][2]['G'] = 'X';             // mức sai
        $k['LopHocPhan'][3] = ['E' => 'M01-B']; // thiếu giảng viên

        $kq = $this->dungKhung($k);
        $loi = implode("\n", $kq['errors']);

        $this->assertStringContainsString('Môn M01 (Khóa 2026): tổng trọng số các bài đánh giá là 90% — phải đúng 100%.', $loi);
        $this->assertStringContainsString("CLO · ô G2: mức 'X' không hợp lệ", $loi);
        $this->assertStringContainsString("LopHocPhan · dòng 3: lớp 'M01-B' — thiếu giảng viên", $loi);
        $this->assertSame(0, Clo::count());
        $this->assertSame(0, CourseClass::count());
    }

    public function test_file_chi_co_lop_them_lop_cho_mon_da_dung_khung(): void
    {
        $this->assertSame([], $this->dungKhung()['errors']);

        $kq = $this->dungKhung(['LopHocPhan' => [
            1 => ['A' => 'Mã ngành', 'B' => 'Khóa', 'C' => 'Mã môn', 'E' => 'Mã lớp học phần', 'F' => 'Giảng viên', 'G' => 'Năm học', 'H' => 'Học kỳ'],
            2 => ['A' => 'TCNH', 'B' => 'Khóa 2026', 'C' => 'M01', 'E' => 'M01-B', 'F' => 'Lê Văn Phúc', 'G' => '2026-2027', 'H' => 2],
            3 => ['C' => 'M02', 'E' => 'M02-A', 'F' => 'Lê Văn Phúc'],
        ]]);

        $this->assertSame(
            ["LopHocPhan · dòng 3: Môn M02 (Khóa 2026) chưa dựng khung — khai CLO và bài đánh giá của môn (sheet CLO, DanhGia) trước hoặc trong cùng file."],
            $kq['errors'],
        );

        unset($kq);
        $kq = $this->dungKhung(['LopHocPhan' => [
            1 => ['A' => 'Mã ngành', 'B' => 'Khóa', 'C' => 'Mã môn', 'E' => 'Mã lớp học phần', 'F' => 'Giảng viên'],
            2 => ['A' => 'TCNH', 'B' => 'Khóa 2026', 'C' => 'M01', 'E' => 'M01-B', 'F' => 'Lê Văn Phúc'],
        ]]);
        $this->assertSame([], $kq['errors']);
        $this->assertSame($this->syllabusM01()->id, CourseClass::where('class_code', 'M01-B')->value('syllabus_id'));
    }

    public function test_khong_bo_bai_da_co_diem_khoi_khung(): void
    {
        $this->assertSame([], $this->dungKhung()['errors']);
        $this->assertSame([], $this->nopPhieu($this->phieu())['errors']);

        $k = $this->khung();
        $k['DanhGia'][2]['E'] = 'Tiểu luận'; // đổi tên bài giữa kỳ — bài cũ đã có điểm

        $this->assertStringContainsString("bài 'Giữa kỳ 1' đã có điểm nên không bỏ khỏi khung được", implode("\n", $this->dungKhung($k)['errors']));
    }

    /** File mẫu khung điền sẵn từ CSDL phải đọc lại được và nhập lại không đổi gì. */
    public function test_file_mau_khung_dien_san_doc_lai_duoc(): void
    {
        $this->assertSame([], $this->dungKhung()['errors']);

        $program = Program::where('code', 'TCNH')->firstOrFail();
        $cohort = Cohort::where('program_id', $program->id)->firstOrFail();
        $path = $this->luu((new CourseFrameTemplate())->build($this->school->id, $program->id, $cohort->id));

        $frame = (new CourseFrameReader())->read($path);
        $this->assertSame([], $frame['errors']);
        $this->assertSame(['PI1.1', 'PLO2'], array_values($frame['targets']));

        $kq = app(CourseFrameService::class)->import($frame, $this->school->id);
        $this->assertSame([], $kq['errors']);
        $this->assertSame(2, Clo::count());
        $this->assertSame(1, CourseClass::count());
        @unlink($path);
    }

    /** Phiếu của một lớp: thông tin lớp và khai báo của khung đã điền sẵn. */
    public function test_phieu_cua_lop_dien_san_thong_tin_lop(): void
    {
        $this->assertSame([], $this->dungKhung()['errors']);
        $lop = CourseClass::where('class_code', 'M01-A')->firstOrFail();

        $path = $this->luu((new ClassSheetTemplate())->forClass($lop));
        $phieu = (new ClassSheetReader())->read($path);

        $this->assertSame('M01-A', $phieu['info']['ma_lop']);
        $this->assertSame('Trần Thị Diện', $phieu['info']['giang_vien']);
        $this->assertSame('2026-2027', $phieu['info']['nam_hoc']);
        $this->assertSame(['Giữa kỳ 1', 'Cuối kỳ'], array_column($phieu['assessments'], 'name'));
        @unlink($path);
    }

    public function test_giao_vien_chi_tai_va_nop_duoc_phieu_lop_minh(): void
    {
        $this->assertSame([], $this->dungKhung()['errors']);
        foreach (['super_admin', 'lecturer', 'viewer'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $gv = User::factory()->create(['is_active' => true, 'school_id' => $this->school->id]);
        $gv->assignRole('lecturer');
        $khac = User::factory()->create(['is_active' => true, 'school_id' => $this->school->id]);
        $khac->assignRole('lecturer');

        $lop = CourseClass::where('class_code', 'M01-A')->firstOrFail();
        $lop->update(['lecturer_id' => $gv->id]);

        $this->actingAs($gv)->get(route('manager.classes.sheet.template', $lop->uuid))->assertOk();
        $this->actingAs($khac)->get(route('manager.classes.sheet.template', $lop->uuid))->assertNotFound();

        $path = $this->luu($this->sach($this->phieu()));
        $this->actingAs($khac)->post(route('manager.classes.sheet.import', $lop->uuid), [
            'file' => new UploadedFile($path, 'phieu.xlsx', null, null, true),
        ])->assertNotFound();

        $this->actingAs($gv)->post(route('manager.classes.sheet.import', $lop->uuid), [
            'file' => new UploadedFile($path, 'phieu.xlsx', null, null, true),
        ])->assertRedirect();

        $lo = FileBatch::where('class_id', $lop->id)->where('type', 'class_sheet')->sole();
        $this->assertSame('completed', $lo->status, implode("\n", $lo->error_log ?? []));
        $this->assertSame(2, StudentGrade::where('class_id', $lop->id)->count());
        @unlink($path);
    }

    /** @param array<string, array<int, array<string, mixed>>> $sheets */
    private function sach(array $sheets): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $ws = $book->createSheet();
            $ws->setTitle($title);
            foreach ($rows as $r => $cols) {
                foreach ($cols as $c => $v) {
                    $ws->setCellValue($c . $r, $v);
                }
            }
        }

        return $book;
    }

    private function luu(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'khung') . '.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return $path;
    }
}
