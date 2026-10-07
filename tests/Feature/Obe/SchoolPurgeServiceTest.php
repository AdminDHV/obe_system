<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\CourseClass;
use App\Models\RubricCriteria;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPloResult;
use App\Models\StudentRubricScore;
use App\Models\User;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\SchoolPurgeService;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Xóa trường thử nghiệm trên production: không hoàn tác được, nên phải chứng minh ba điều —
 * xóa SẠCH trường đó, KHÔNG đụng một dòng nào của trường khác (kể cả số đo), và tài khoản còn
 * dính trường khác thì được giữ.
 *
 * Dựng dữ liệu bằng đúng đường nhập thật (file khai báo + phiếu lớp) để có đủ mọi bảng: rubric,
 * điểm câu, điểm bài, kết quả CLO/PI/PLO, PI nội bộ của PLO nối thẳng.
 */
class SchoolPurgeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'lecturer', 'viewer'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new MappingScaleWeightSeeder())->run();
    }

    private function truong(string $ma): School
    {
        $school = School::create(['code' => $ma, 'name' => "Trường {$ma}", 'is_active' => false]);

        app(StructureBuilderService::class)->build(array_map('collect', [
            'Nganh_Khoa' => [['ma_nganh' => 'N1', 'ten_nganh' => 'Ngành', 'khoa_quan_ly' => "Khoa {$ma}", 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]],
            'PLO_PI' => [
                ['ma_plo' => 'PLO1', 'mo_ta_plo' => 'x', 'ma_pi' => 'PI1.1', 'trong_so_pi' => 1],
                ['ma_plo' => 'PLO2', 'mo_ta_plo' => 'y'],
            ],
            'MonHoc' => [['ma_mon' => 'M01', 'ten_mon' => 'Môn', 'tin_chi' => 3]],
            'SinhVien' => [['mssv' => "{$ma}-SV1", 'ho_va_ten' => 'An', 'nam_khoa' => 2026]],
        ]), $school->id);

        $kq = app(ClassSheetService::class)->import((new ClassSheetReader())->parse([
            'KhaiBao' => [
                2 => ['A' => 'Mã môn học', 'B' => 'M01'],
                3 => ['A' => 'Mã ngành', 'B' => 'N1'],
                4 => ['A' => 'Khóa', 'B' => 'Khóa 2026'],
                5 => ['A' => 'Mã lớp học phần', 'B' => "{$ma}-L1"],
                6 => ['A' => 'Giảng viên giảng dạy', 'B' => "GV {$ma}"],
                8 => ['A' => 'Chuẩn đầu ra', 'B' => 'Nội dung', 'C' => 'PI1.1', 'D' => 'PLO2'],
                9 => ['A' => 'CLO1', 'B' => 'a', 'C' => 'M'],
                10 => ['A' => 'CLO2', 'B' => 'b', 'D' => 'R'],
                12 => ['A' => 'Đánh giá', 'B' => 'Loại', 'C' => 'Trọng số (%)'],
                13 => ['A' => 'Cuối kỳ', 'B' => 'Cuối kỳ', 'C' => 100],
            ],
            'Cuối kỳ' => [
                2 => ['A' => 'Điểm tối đa', 'D' => 5, 'E' => 5],
                3 => ['A' => 'CLO cần đo', 'D' => 'CLO1', 'E' => 'CLO2'],
                5 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1', 'E' => 'Câu 2'],
                6 => ['A' => 1, 'B' => "{$ma}-SV1", 'C' => 'An', 'D' => 4, 'E' => 3],
            ],
        ]), $school->id);
        $this->assertSame([], $kq['errors']);

        return $school;
    }

    /** Ảnh chụp toàn bộ dữ liệu của một trường, kể cả GIÁ TRỊ đo. */
    private function anhChup(School $s): array
    {
        $classIds = CourseClass::where('school_id', $s->id)->pluck('id');
        $studentIds = Student::where('school_id', $s->id)->pluck('id');

        return [
            'lop' => $classIds->count(),
            'sv' => $studentIds->count(),
            'cau_hoi' => RubricCriteria::whereIn('class_id', $classIds)->count(),
            'diem_cau' => StudentRubricScore::whereIn('class_id', $classIds)->orderBy('id')->pluck('score')->map(fn ($v) => (string) $v)->all(),
            'plo' => StudentPloResult::whereIn('student_id', $studentIds)->orderBy('plo_id')->pluck('achieved_score')->map(fn ($v) => (string) $v)->all(),
            'khoa' => DB::table('departments')->where('school_id', $s->id)->count(),
        ];
    }

    public function test_xoa_sach_truong_nay_khong_dung_truong_kia_giu_tai_khoan_con_day_noi_khac(): void
    {
        $a = $this->truong('A');
        $b = $this->truong('B');

        // Giảng viên của A mà cũng dạy một lớp ở B → phải GIỮ (xóa là mất luôn lớp ở B vì cascade).
        $gvA = User::where('full_name', 'GV A')->firstOrFail();
        $lopB = CourseClass::where('class_code', 'B-L1')->firstOrFail();
        $lopKhac = $lopB->replicate(['uuid']);
        $lopKhac->class_code = 'B-L2';
        $lopKhac->lecturer_id = $gvA->id;
        $lopKhac->save();

        // Tài khoản giả kiểu AGG-12 trên production: ghi trường B nhưng chỉ dạy lớp ở A → XÓA.
        $gia = User::factory()->create(['school_id' => $b->id, 'is_active' => true]);
        $lopA2 = CourseClass::where('class_code', 'A-L1')->firstOrFail()->replicate(['uuid']);
        $lopA2->class_code = 'A-L2';
        $lopA2->lecturer_id = $gia->id;
        $lopA2->save();

        // Quản trị hệ thống lỡ gắn trường A → không bao giờ bị xóa.
        $admin = User::factory()->create(['school_id' => $a->id, 'is_active' => true]);
        $admin->assignRole('super_admin');

        $truocB = $this->anhChup($b);
        $this->assertNotEmpty($truocB['plo'], 'Trường B phải có số đo để kiểm là không bị đụng');

        // ── Chạy thử: báo số dòng nhưng không xóa gì ──
        $thu = app(SchoolPurgeService::class)->purge([$a->id], dryRun: true);
        $this->assertGreaterThan(0, $thu['student_rubric_scores'] ?? 0);
        $this->assertTrue(School::whereKey($a->id)->exists(), 'Chạy thử không được xóa');
        $this->assertSame(2, CourseClass::where('school_id', $a->id)->count());

        // ── Xóa thật ──
        app(SchoolPurgeService::class)->purge([$a->id]);

        $this->assertFalse(School::whereKey($a->id)->exists());
        foreach (['programs', 'courses', 'course_classes', 'students', 'cohorts', 'departments', 'file_batches', 'measurement_runs'] as $t) {
            $this->assertSame(0, DB::table($t)->where('school_id', $a->id)->count(), "Còn sót {$t} của trường A");
        }
        $this->assertSame(0, DB::table('rubric_criteria')->whereNotIn('class_id', CourseClass::pluck('id'))->count(), 'Còn sót câu hỏi của lớp đã xóa');
        $this->assertSame(0, DB::table('student_rubric_scores')->whereNotIn('student_id', Student::pluck('id'))->count());

        $this->assertSame($truocB, $this->anhChup($b), 'Dữ liệu (kể cả số đo) của trường B phải y nguyên');
        $this->assertTrue(CourseClass::where('class_code', 'B-L2')->exists(), 'Lớp ở B của giảng viên dạy cả hai trường phải còn');

        $this->assertTrue(User::whereKey($gvA->id)->exists(), 'Giảng viên còn dạy ở B phải được giữ');
        $this->assertNull(User::find($gvA->id)->department_id, 'Được giữ thì gỡ khoa đã xóa, không để trỏ vào khoa không còn');
        $this->assertFalse(User::whereKey($gia->id)->exists(), 'Tài khoản chỉ dạy ở A phải bị xóa dù ghi trường B');
        $this->assertTrue(User::whereKey($admin->id)->exists(), 'Quản trị hệ thống không bao giờ bị xóa');
    }
}
