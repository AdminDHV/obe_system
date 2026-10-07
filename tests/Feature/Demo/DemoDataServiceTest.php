<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Services\Demo\DemoDataService;
use App\Services\Demo\DemoSchools;
use App\Services\OBE\ObeAttainmentService;
use App\Services\OBE\ObeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dữ liệu mẫu: nạp 1 nút ra trọn quy trình đo THẬT trên NHIỀU trường, xóa sạch được.
 *
 * Bộ mẫu này đem đi trình bày cho trường, nên test ở đây canh cả HÌNH THỨC chứ không chỉ
 * chạy-được: tên trường phải là tên hư cấu có gắn nhãn, và số đo phải nói lên điều gì đó.
 */
class DemoDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_builds_every_demo_school(): void
    {
        $dau = app(DemoDataService::class)->seed();

        $ma = array_column(DemoSchools::all(), 'code');
        $this->assertContains($dau->code, $ma);
        $this->assertGreaterThanOrEqual(3, count($ma), 'Bộ mẫu phải có nhiều trường thì mới cho thấy hệ thống chạy đa-trường');

        foreach ($ma as $code) {
            $school = School::where('code', $code)->first();
            $this->assertNotNull($school, "Thiếu trường mẫu {$code}");
            $this->assertTrue((bool) $school->is_active, 'Trường mẫu phải active để hiện ở ô chọn trường');
            $this->assertGreaterThan(0, Program::where('school_id', $school->id)->count());
            $this->assertGreaterThan(0, Student::where('school_id', $school->id)->count());
        }

        $this->assertGreaterThan(0, StudentGrade::count());
        $this->assertGreaterThan(0, StudentPloResult::count());
        $this->assertGreaterThan(0, StudentPiResult::count());
    }

    /**
     * Bộ mẫu đầu tiên để khóa 2023/2024 — mà khóa ≤2024 chạy phương án CHỈ ĐO CLO, không lên
     * PI/PLO. Nạp xong bảng mức đạt trống trơn ở CẢ 8 PLO, trong khi khung và sinh viên đều có
     * đủ nên nhìn qua tưởng chạy đúng. Test này canh đúng chỗ đó.
     */
    public function test_every_plo_actually_has_measured_students(): void
    {
        app(DemoDataService::class)->seed();

        foreach (School::whereIn('code', array_column(DemoSchools::all(), 'code'))->get() as $school) {
            foreach (Program::where('school_id', $school->id)->get() as $program) {
                $kq = ObeConfig::forSchool(
                    $school->id,
                    fn () => app(ObeAttainmentService::class)->ploAttainment($program->id, null),
                );

                $this->assertNotEmpty($kq['plos'], "{$school->code}/{$program->code} không có PLO nào");

                foreach ($kq['plos'] as $r) {
                    $this->assertGreaterThan(
                        0,
                        $r['students_measured'],
                        "{$school->code}/{$program->code}/{$r['plo_code']}: đo được 0 SV — bảng mức đạt sẽ trống",
                    );
                    $this->assertNotNull($r['attainment_percent']);
                }
            }
        }
    }

    /**
     * Demo mà PLO nào cũng xanh 100% thì không cho người xem thấy công dụng chính của việc đo
     * chuẩn đầu ra là PHÁT HIỆN chỗ chưa đạt. Điểm được cố ý rải để có cả hai vế.
     */
    public function test_attainment_shows_both_passing_and_failing_plos(): void
    {
        app(DemoDataService::class)->seed();

        $dat = 0;
        $chuaDat = 0;

        foreach (School::whereIn('code', array_column(DemoSchools::all(), 'code'))->get() as $school) {
            foreach (Program::where('school_id', $school->id)->get() as $program) {
                $kq = ObeConfig::forSchool(
                    $school->id,
                    fn () => app(ObeAttainmentService::class)->ploAttainment($program->id, null),
                );

                foreach ($kq['plos'] as $r) {
                    $r['met_target'] ? $dat++ : $chuaDat++;
                }
            }
        }

        $this->assertGreaterThan(0, $dat, 'Không có PLO nào đạt — bộ mẫu nhìn như hệ thống hỏng');
        $this->assertGreaterThan(0, $chuaDat, 'Không có PLO nào chưa đạt — bộ mẫu không cho thấy hệ thống biết phát hiện vấn đề');
    }

    /**
     * Dữ liệu kiểm thử từng đội lốt trường thật trên production (đặt tên theo NGÀNH, nhìn hệt
     * một trường), khách mở danh sách Trường lên là thấy sai ngay. Tên mẫu phải tự tố cáo mình.
     */
    public function test_demo_school_names_are_clearly_labelled(): void
    {
        foreach (DemoSchools::all() as $cfg) {
            $this->assertStringStartsWith('[DEMO]', $cfg['name'], "Tên trường mẫu {$cfg['code']} phải mang nhãn [DEMO]");
            $this->assertStringStartsWith(DemoSchools::PREFIX, $cfg['code']);
        }
    }

    public function test_clear_removes_everything(): void
    {
        $svc = app(DemoDataService::class);
        $svc->seed();

        $this->assertTrue($svc->clear());

        foreach (array_column(DemoSchools::all(), 'code') as $code) {
            $this->assertSame(0, School::where('code', $code)->count());
        }

        $this->assertSame(0, Student::count());
        $this->assertSame(0, Program::count());
        $this->assertSame(0, StudentPloResult::count());
        $this->assertSame(0, StudentGrade::count());
    }

    /** Bản mẫu đời cũ chỉ có 1 trường mã "DEMO" — nâng cấp rồi vẫn phải dọn được nó. */
    public function test_clear_also_removes_legacy_single_school_demo(): void
    {
        School::create(['code' => DemoSchools::LEGACY_CODE, 'name' => 'Trường DEMO cũ', 'is_active' => true]);

        $this->assertTrue(app(DemoDataService::class)->clear());
        $this->assertSame(0, School::where('code', DemoSchools::LEGACY_CODE)->count());
    }

    public function test_seed_is_idempotent(): void
    {
        $svc = app(DemoDataService::class);
        $svc->seed();
        $truoc = Student::count();

        $svc->seed(); // nạp lại không nhân đôi

        foreach (array_column(DemoSchools::all(), 'code') as $code) {
            $this->assertSame(1, School::where('code', $code)->count());
        }

        $this->assertSame($truoc, Student::count());
    }

    public function test_clear_noop_when_no_demo(): void
    {
        $this->assertFalse(app(DemoDataService::class)->clear());
    }
}
