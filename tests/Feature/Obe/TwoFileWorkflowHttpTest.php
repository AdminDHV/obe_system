<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Exports\ClassSheetTemplate;
use App\Exports\StructureTemplateExport;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\FileBatch;
use App\Models\Program;
use App\Models\School;
use App\Models\User;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Quy trình 2 file đi qua ĐÚNG các route mà màn hình gọi: tải 2 file mẫu, tải phiếu lên (nhiều file
 * một lần), job xử lý, lịch sử nhập hiện kết quả. Test dịch vụ đã khoá con số; test này khoá đường
 * đi — một route sai tên hay một prop thiếu là màn hình hỏng dù dịch vụ đúng.
 */
class TwoFileWorkflowHttpTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'lecturer', 'student', 'viewer'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new MappingScaleWeightSeeder())->run();

        $this->school = School::create(['code' => 'HTTP2', 'name' => 'Trường HTTP', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true, 'school_id' => $this->school->id]);
        $this->admin->assignRole('super_admin');
    }

    private function get2(string $route, array $query = [])
    {
        return $this->actingAs($this->admin)->get(route($route) . ($query ? '?' . http_build_query($query + ['school_id' => $this->school->id]) : '?school_id=' . $this->school->id));
    }

    public function test_tai_hai_file_mau(): void
    {
        foreach (['manager.reports.structure.template', 'manager.reports.class-sheets.template'] as $route) {
            $res = $this->get2($route);
            $res->assertOk();
            $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('Content-Type'), $route);
            $path = tempnam(sys_get_temp_dir(), 'mau') . '.xlsx';
            file_put_contents($path, $res->streamedContent());
            $this->assertNotEmpty(IOFactory::load($path)->getSheetNames(), "{$route} không ra file Excel mở được");
            @unlink($path);
        }
    }

    public function test_man_hinh_do_luong_co_danh_sach_mon_de_tai_phieu_dien_san(): void
    {
        app(StructureBuilderService::class)->build(array_map('collect', [
            'Nganh_Khoa' => [['ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính', 'nam_bat_dau' => 2026]],
            'MonHoc' => [['ma_mon' => '81002', 'ten_mon' => 'PPNC', 'tin_chi' => 3]],
        ]), $this->school->id);

        $program = Program::where('code', 'TCNH')->firstOrFail();

        $this->get2('manager.reports.index', ['step' => 2])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('courses.0.code', '81002')
                ->where('courses.0.program_ids', [$program->id]));
    }

    public function test_tai_len_nhieu_phieu_mot_lan_phieu_dung_vao_phieu_loi_bi_chan(): void
    {
        Storage::fake();

        app(StructureBuilderService::class)->build(array_map('collect', [
            'Nganh_Khoa' => [['ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]],
            'PLO_PI' => [['ma_plo' => 'PLO1', 'mo_ta_plo' => 'Kiến thức']],
            'MonHoc' => [['ma_mon' => '81002', 'ten_mon' => 'PPNC', 'tin_chi' => 3]],
            'SinhVien' => [['mssv' => 'SV01', 'ho_va_ten' => 'Nguyễn Văn An', 'nam_khoa' => 2026]],
        ]), $this->school->id);

        $program = Program::where('code', 'TCNH')->firstOrFail();
        $cohort = Cohort::where('program_id', $program->id)->firstOrFail();
        $course = Course::where('code', '81002')->firstOrFail();

        $dung = $this->phieu($program->id, $cohort->id, $course->id, 'LOP-A', 6);
        $loi = $this->phieu($program->id, $cohort->id, $course->id, 'LOP-B', 11); // điểm vượt tối đa

        $this->actingAs($this->admin)
            ->post(route('manager.reports.class-sheets'), [
                'school_id' => $this->school->id,
                'files' => [
                    new UploadedFile($dung, 'lop_a.xlsx', null, null, true),
                    new UploadedFile($loi, 'lop_b.xlsx', null, null, true),
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $a = FileBatch::where('file_name', 'lop_a.xlsx')->firstOrFail();
        $b = FileBatch::where('file_name', 'lop_b.xlsx')->firstOrFail();

        $this->assertSame('class_sheet', $a->type);
        $this->assertSame('completed', $a->status, json_encode($a->error_log, JSON_UNESCAPED_UNICODE));
        $this->assertSame('failed', $b->status);
        $this->assertStringContainsString('vượt điểm tối đa', implode("\n", $b->error_log ?? []));

        $this->assertTrue(CourseClass::where('class_code', 'LOP-A')->exists());
        $this->assertFalse(CourseClass::where('class_code', 'LOP-B')->exists(), 'Phiếu lỗi không được ghi gì');

        // Lịch sử nhập ở Bước 2 phải hiện cả phiếu.
        $this->get2('manager.reports.index', ['step' => 2])
            ->assertInertia(fn ($page) => $page->where('bulkBatches', fn ($ds) => collect($ds)->pluck('file_name')->contains('lop_a.xlsx')));
    }

    private function phieu(int $programId, int $cohortId, int $courseId, string $maLop, float $diemCau1): string
    {
        $book = (new ClassSheetTemplate())->build($this->school->id, $programId, $cohortId, $courseId);
        $info = $book->getSheetByName('KhaiBao');
        $info->setCellValue('B7', $maLop);
        $info->setCellValue('B8', 'GV thử');
        $info->fromArray([['CLO1', 'Mô tả', 'R']], null, 'A13', true);

        foreach (array_slice($book->getSheetNames(), 1) as $title) {
            $ws = $book->getSheetByName($title);
            $ws->fromArray([['Tự luận', 'Tự luận', null], [6, 4, null], ['CLO1', 'CLO1', null]], null, 'D2', true);
            $ws->setCellValue('F7', null);
            $ws->fromArray([[$diemCau1, 3]], null, 'D8', true);
        }

        $path = tempnam(sys_get_temp_dir(), 'phieu') . '.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}
