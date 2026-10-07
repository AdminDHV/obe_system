<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Exports\CourseReportDocx;
use App\Models\Course;
use App\Models\School;
use App\Models\User;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\CourseReportService;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BÁO CÁO HỌC PHẦN — khách chốt "đo theo học phần chứ không phải theo lớp học phần":
 * hai lớp cùng môn, cùng học kỳ phải ra MỘT báo cáo, phần chi tiết mới tách cột theo lớp.
 *
 * Số kỳ vọng tính tay (ngưỡng đạt CLO 5,0 vì ngành chưa khai bậc; điểm câu quy về thang 10 rồi lấy
 * trung bình trong nhóm câu cùng hình thức của cùng một bài):
 *
 *   Lớp M01-A      GK1 Tự luận câu 1 (4đ) + câu 2 (3đ) đo CLO1 · GK1 Trắc nghiệm câu 3 (3đ) đo CLO2
 *                  CK  Tự luận câu 1 (5đ) đo CLO1      · CK Vấn đáp câu 2 (5đ) đo CLO2
 *     SV01  GK1 Tự luận TB(3/4, 1.5/3) × 10 = 6.25 đạt · CK Tự luận 4/5 × 10 = 8 đạt
 *           GK1 Trắc nghiệm 2/3 × 10 = 6.67 đạt        · CK Vấn đáp 5/5 × 10 = 10 đạt
 *     SV02  GK1 Tự luận TB(4/4, 3/3) × 10 = 10 đạt     · CK Tự luận 2.5/5 × 10 = 5 đạt
 *           GK1 Trắc nghiệm bỏ trống, không tính       · CK Vấn đáp 4/5 × 10 = 8 đạt
 *
 *   Lớp M01-B      cùng đề, hai sinh viên khác
 *     SV03  mọi câu đạt        SV04  cả hai câu tự luận đều 1/… ⇒ dưới 5, không đạt
 */
class CourseReportTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        (new MappingScaleWeightSeeder())->run();

        $this->school = School::create(['code' => 'BCHP', 'name' => 'Trường Đại học Thử', 'is_active' => true]);

        $kq = app(StructureBuilderService::class)->build([
            'Nganh_Khoa' => collect([[
                'ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính – Ngân hàng', 'bac' => 'Đại học',
                'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026, 'ten_khoa_quan_ly' => 'Khoa Tài chính',
            ]]),
            'MonHoc' => collect([[
                'ma_mon' => 'M01', 'ten_mon' => 'Môn thử', 'tin_chi' => 3,
                'so_tiet' => 45, 'bo_mon' => 'Bộ môn Tài chính',
            ]]),
            'PLO_PI' => collect([
                ['ma_plo' => 'PLO1', 'mo_ta_plo' => 'PLO1', 'ma_pi' => 'PI1.1', 'mo_ta_pi' => 'PI1.1', 'trong_so' => 1],
                ['ma_plo' => 'PLO2', 'mo_ta_plo' => 'PLO2'],
            ]),
        ], $this->school->id);

        $this->assertSame([], $kq['errors']);
    }

    /**
     * @param array<int,array<string,mixed>> $diemGk
     * @param array<int,array<string,mixed>> $diemCk
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function phieu(string $maLop, array $diemGk, array $diemCk): array
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
                12 => ['A' => 'CLO1', 'B' => 'Hiểu nguyên tắc cơ bản', 'C' => 'M'],
                13 => ['A' => 'CLO2', 'B' => 'Tuân thủ đạo đức nghề nghiệp', 'D' => 'R'],
                15 => ['A' => 'Đánh giá', 'B' => 'Loại', 'C' => 'Trọng số (%)'],
                16 => ['A' => 'Giữa kỳ 1', 'B' => 'Quá trình', 'C' => 40],
                17 => ['A' => 'Cuối kỳ', 'B' => 'Cuối kỳ', 'C' => 60],
            ],
            'Giữa kỳ 1' => array_replace([
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận', 'E' => 'Tự luận', 'F' => 'Trắc nghiệm'],
                3 => ['A' => 'Điểm tối đa', 'D' => 4, 'E' => 3, 'F' => 3],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1', 'E' => 'CLO1', 'F' => 'CLO2'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1', 'E' => 'Câu 2', 'F' => 'Câu 3'],
            ], $diemGk),
            'Cuối kỳ' => array_replace([
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận', 'E' => 'Vấn đáp'],
                3 => ['A' => 'Điểm tối đa', 'D' => 5, 'E' => 5],
                4 => ['A' => 'CLO cần đo', 'D' => 'CLO1', 'E' => 'CLO2'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1', 'E' => 'Câu 2'],
            ], $diemCk),
        ];
    }

    private function nhapHaiLop(): void
    {
        $import = fn (array $sheets) => app(ClassSheetService::class)
            ->import((new ClassSheetReader())->parse($sheets), $this->school->id);

        $a = $import($this->phieu('M01-A', [
            8 => ['A' => 1, 'B' => 'SV01', 'C' => 'Nguyễn Văn An', 'D' => 3, 'E' => 1.5, 'F' => 2],
            9 => ['A' => 2, 'B' => 'SV02', 'C' => 'Trần Thị Bình', 'D' => 4, 'E' => 3, 'F' => null],
        ], [
            8 => ['A' => 1, 'B' => 'SV01', 'C' => 'Nguyễn Văn An', 'D' => 4, 'E' => 5],
            9 => ['A' => 2, 'B' => 'SV02', 'C' => 'Trần Thị Bình', 'D' => 2.5, 'E' => 4],
        ]));
        $this->assertSame([], $a['errors']);

        $b = $import($this->phieu('M01-B', [
            8 => ['A' => 1, 'B' => 'SV03', 'C' => 'Lê Văn Cường', 'D' => 4, 'E' => 3, 'F' => 3],
            9 => ['A' => 2, 'B' => 'SV04', 'C' => 'Phạm Thị Dung', 'D' => 1, 'E' => 1, 'F' => 3],
        ], [
            8 => ['A' => 1, 'B' => 'SV03', 'C' => 'Lê Văn Cường', 'D' => 5, 'E' => 5],
            9 => ['A' => 2, 'B' => 'SV04', 'C' => 'Phạm Thị Dung', 'D' => 1, 'E' => 5],
        ]));
        $this->assertSame([], $b['errors']);
    }

    private function baoCao(): array
    {
        $this->nhapHaiLop();
        $course = Course::where('school_id', $this->school->id)->where('code', 'M01')->firstOrFail();

        return app(CourseReportService::class)->build($course, '2026-2027', 1);
    }

    public function test_bao_cao_gop_moi_lop_cua_hoc_phan_trong_mot_hoc_ky(): void
    {
        $bc = $this->baoCao();

        $this->assertSame(['M01-A', 'M01-B'], $bc['lop']);
        $this->assertSame('M01', $bc['hoc_phan']['ma']);
        $this->assertSame(45, $bc['hoc_phan']['so_tiet']);
        $this->assertSame('Bộ môn Tài chính', $bc['hoc_phan']['bo_mon']);
        $this->assertSame(['Trần Thị Diện'], $bc['giang_vien']);

        $this->assertSame(4, $bc['thong_ke']['dang_ky']);
        $this->assertSame(4, $bc['thong_ke']['du_thi_cuoi_ky']);
        // Điểm môn: SV01 0.4×6.5 + 0.6×9 = 8.0 · SV02 0.4×7 + 0.6×6.5 = 6.7 · SV03 10 · SV04 0.4×5 + 0.6×6 = 5.6
        // Ngưỡng qua môn 5,5 (ngành chưa khai bậc giữ ngưỡng cũ) ⇒ cả bốn qua.
        $this->assertSame(4, $bc['thong_ke']['qua_mon']);
    }

    public function test_tung_clo_tach_dong_theo_phuong_phap_va_lop(): void
    {
        $bc = $this->baoCao();
        $clo1 = collect($bc['clos'])->firstWhere('code', 'CLO1');

        $nhan = array_column($clo1['dong'], 'phuong_phap');
        $this->assertSame([
            'Tự luận, Giữa kỳ 1, Câu 1 – Câu 2',
            'Tự luận, Cuối kỳ, Câu 1',
        ], $nhan);

        // Giữa kỳ, tự luận: lớp A cả hai đạt; lớp B SV03 đạt, SV04 TB(1/4, 1/3) × 10 = 2.9 không đạt.
        $this->assertSame(
            [['lop' => 'M01-A', 'dat' => 2, 'khong_dat' => 0], ['lop' => 'M01-B', 'dat' => 1, 'khong_dat' => 1]],
            $clo1['dong'][0]['lop'],
        );

        // Cuối kỳ, tự luận: chỉ SV04 (1/5 × 10 = 2) không đạt.
        $this->assertSame(
            [['lop' => 'M01-A', 'dat' => 2, 'khong_dat' => 0], ['lop' => 'M01-B', 'dat' => 1, 'khong_dat' => 1]],
            $clo1['dong'][1]['lop'],
        );

        // TỔNG CỘNG là số sinh viên đạt CLO của cả học phần, không phải tổng các dòng trên.
        // SV04: CLO1 = 0.4 × 2.9167 + 0.6 × 2 = 2.3667 ⇒ không đạt; ba người còn lại đạt.
        $this->assertSame(4, $clo1['tong']['do']);
        $this->assertSame(3, $clo1['tong']['dat']);
        $this->assertSame(1, $clo1['tong']['khong_dat']);
        $this->assertEqualsWithDelta(75.0, $clo1['tong']['ty_le_dat'], 0.001);
        $this->assertSame('Đạt', $clo1['tong']['ket_luan']);
    }

    public function test_cau_bo_trong_khong_bi_tinh_la_khong_dat(): void
    {
        $bc = $this->baoCao();
        $clo2 = collect($bc['clos'])->firstWhere('code', 'CLO2');
        $gk = collect($clo2['dong'])->firstWhere('phuong_phap', 'Trắc nghiệm, Giữa kỳ 1, Câu 3');

        // SV02 bỏ trống câu trắc nghiệm ⇒ lớp A chỉ thống kê 1 người, không phải 1 đạt 1 không đạt.
        $this->assertSame(['lop' => 'M01-A', 'dat' => 1, 'khong_dat' => 0], $gk['lop'][0]);
    }

    public function test_bang_phuong_phap_danh_dau_dung_dong_co_dung(): void
    {
        $bc = $this->baoCao();
        $co = collect($bc['phuong_phap'])->where('co_su_dung', true)->pluck('clos', 'ten')->all();

        $this->assertSame(['CLO1'], $co['Tự luận']);
        $this->assertSame(['CLO2'], $co['Trắc nghiệm']);
        // Vấn đáp không có trong bảng in sẵn của trường ⇒ thêm một dòng mang đúng tên trong phiếu.
        $this->assertSame(['CLO2'], $co['Vấn đáp']);
        $this->assertArrayNotHasKey('Tiểu luận', $co);
        $this->assertSame([], $bc['phuong_phap_chua_khai']);

        // Các dòng in sẵn vẫn còn đủ để giảng viên tự đánh dấu thêm.
        $this->assertContains('Hồ sơ học tập', array_column($bc['phuong_phap'], 'ten'));
    }

    public function test_bai_chuyen_can_khong_ghi_lap_ba_lan_cung_mot_chu(): void
    {
        // Bài chuyên cần thường có hình thức, tên bài và nhãn câu đều là "Chuyên cần".
        $phieu = $this->phieu('M01-C', [
            8 => ['A' => 1, 'B' => 'SV05', 'C' => 'Đỗ Văn Em', 'D' => 4, 'E' => 3, 'F' => 3],
        ], [
            8 => ['A' => 1, 'B' => 'SV05', 'C' => 'Đỗ Văn Em', 'D' => 5, 'E' => 5],
        ]);
        $phieu['KhaiBao'][18] = ['A' => 'Chuyên cần', 'B' => 'Quá trình', 'C' => 20];
        $phieu['KhaiBao'][16]['C'] = 30;
        $phieu['KhaiBao'][17]['C'] = 50;
        $phieu['Chuyên cần'] = [
            2 => ['A' => 'Hình thức', 'D' => 'Chuyên cần'],
            3 => ['A' => 'Điểm tối đa', 'D' => 10],
            4 => ['A' => 'CLO cần đo', 'D' => 'CLO1'],
            7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Chuyên cần'],
            8 => ['A' => 1, 'B' => 'SV05', 'C' => 'Đỗ Văn Em', 'D' => 9],
        ];

        $kq = app(ClassSheetService::class)->import((new ClassSheetReader())->parse($phieu), $this->school->id);
        $this->assertSame([], $kq['errors']);

        $course = Course::where('school_id', $this->school->id)->where('code', 'M01')->firstOrFail();
        $bc = app(CourseReportService::class)->build($course, '2026-2027', 1);
        $clo1 = collect($bc['clos'])->firstWhere('code', 'CLO1');

        $this->assertContains('Chuyên cần', array_column($clo1['dong'], 'phuong_phap'));
        $this->assertNotContains('Chuyên cần, Chuyên cần, Chuyên cần', array_column($clo1['dong'], 'phuong_phap'));
    }

    public function test_phieu_chua_khai_hinh_thuc_thi_noi_thang_chu_khong_dung_dong_khac(): void
    {
        $this->nhapHaiLop();
        // Phiếu nhập bằng định dạng cũ: câu hỏi không có dòng "Hình thức".
        \App\Models\RubricCriteria::query()->update(['question_type' => null]);

        $course = Course::where('school_id', $this->school->id)->where('code', 'M01')->firstOrFail();
        $bc = app(CourseReportService::class)->build($course, '2026-2027', 1);

        $this->assertSame([], collect($bc['phuong_phap'])->where('co_su_dung', true)->all(),
            'Không khai hình thức thì không dựng dòng phương pháp nào');
        $this->assertSame(['Giữa kỳ 1', 'Cuối kỳ'], $bc['phuong_phap_chua_khai']);

        // Nhãn dòng trong bảng CLO chỉ còn tên bài và phạm vi câu, không có chữ "Khác".
        $clo1 = collect($bc['clos'])->firstWhere('code', 'CLO1');
        $this->assertSame(['Giữa kỳ 1, Câu 1 – Câu 2', 'Cuối kỳ, Câu 1'], array_column($clo1['dong'], 'phuong_phap'));
    }

    public function test_man_hinh_va_duong_dan_tai_file_word_chay_duoc(): void
    {
        foreach (['super_admin', 'lecturer', 'viewer'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::factory()->create(['is_active' => true, 'school_id' => $this->school->id]);
        $admin->assignRole('super_admin');

        $this->nhapHaiLop();
        $course = Course::where('school_id', $this->school->id)->where('code', 'M01')->firstOrFail();
        $q = ['school_id' => $this->school->id, 'nam_hoc' => '2026-2027', 'hoc_ky' => 1];

        $this->actingAs($admin)
            ->get(route('manager.reports.course.index') . '?school_id=' . $this->school->id)
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('manager.reports.course.show', $course->uuid) . '?' . http_build_query($q))
            ->assertOk();

        $res = $this->actingAs($admin)
            ->get(route('manager.reports.course.word', $course->uuid) . '?' . http_build_query($q));

        $res->assertOk();
        $this->assertStringContainsString('wordprocessingml', (string) $res->headers->get('Content-Type'));
    }

    public function test_xuat_file_word_theo_mau_cua_truong(): void
    {
        $bc = $this->baoCao();
        $docx = new CourseReportDocx($bc, 'Trường Đại học Thử');

        $path = tempnam(sys_get_temp_dir(), 'bchp') . '.docx';
        $docx->save($path);

        $this->assertFileExists($path);
        $this->assertStringEndsWith('.docx', $docx->fileName());
        $this->assertStringContainsString('M01', $docx->fileName());

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        foreach ([
            'BÁO CÁO HỌC PHẦN',
            'TRƯỜNG ĐẠI HỌC THỬ',
            '1. THÔNG TIN CHUNG',
            'Bộ môn phụ trách: Bộ môn Tài chính',
            'Tổng số sinh viên đăng ký học phần: 4',
            'TỔNG CỘNG',
            'GIẢNG VIÊN PHỤ TRÁCH',
        ] as $doan) {
            $this->assertStringContainsString($doan, $xml, "File Word thiếu: {$doan}");
        }
    }
}
