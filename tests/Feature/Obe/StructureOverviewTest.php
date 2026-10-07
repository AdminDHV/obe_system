<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Course;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\User;
use App\Services\Demo\DemoDataService;
use App\Services\Demo\DemoSchools;
use App\Services\OBE\StructureOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Thẻ "khung đang có gì" ở Bước 1 — Trung tâm đo lường.
 *
 * Trước đây Bước 1 render y hệt nhau dù khung đã dựng hay chưa, nên người vận hành không có cách
 * nào biết mình khai tới đâu ngay tại chỗ vừa khai. Test canh hai điều: con số phải đếm đúng
 * TRONG trường đang mở, và chỗ thiếu phải nêu ra đúng thứ chặn đường đo.
 */
class StructureOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function truong(string $ma): School
    {
        return School::create(['code' => $ma, 'name' => 'Trường ' . $ma, 'is_active' => true]);
    }

    public function test_truong_chua_khai_gi_thi_bao_chua_dung_khung(): void
    {
        $school = $this->truong('SO-EMPTY');

        $kq = app(StructureOverviewService::class)->forSchool($school->id);

        $this->assertFalse($kq['built']);
        $this->assertSame(0, $kq['counts']['programs']);
        $this->assertSame([], $kq['gaps'], 'Chưa khai gì thì đừng dội một tràng lỗi vào mặt người dùng');
    }

    public function test_dem_dung_va_khong_lan_sang_truong_khac(): void
    {
        app(DemoDataService::class)->seed();

        $svc = app(StructureOverviewService::class);
        $cau = DemoSchools::all();

        foreach ($cau as $cfg) {
            $school = School::where('code', $cfg['code'])->first();
            $kq = $svc->forSchool($school->id);

            $this->assertTrue($kq['built']);
            $this->assertSame(count($cfg['programs']), $kq['counts']['programs'], "Sai số ngành ở {$cfg['code']}");
            $this->assertSame(
                array_sum(array_map(fn ($ct) => count($ct['courses']), $cfg['programs'])),
                $kq['counts']['courses'],
                "Sai số môn ở {$cfg['code']}",
            );
            $this->assertGreaterThan(0, $kq['counts']['students']);
            $this->assertSame([], $kq['gaps'], "Trường mẫu {$cfg['code']} phải đủ điều kiện đo — nếu đây đỏ thì bộ mẫu hỏng");
        }
    }

    public function test_neu_ngành_chua_co_plo_thi_neu_ra_dung_cho(): void
    {
        $school = $this->truong('SO-GAP1');
        $dept = Department::create(['code' => 'D1', 'name' => 'Khoa', 'school_id' => $school->id]);
        Program::create(['code' => 'P1', 'name' => 'Ngành', 'department_id' => $dept->id, 'school_id' => $school->id]);

        $kq = app(StructureOverviewService::class)->forSchool($school->id);
        $keys = array_column($kq['gaps'], 'key');

        $this->assertTrue($kq['built']);
        $this->assertContains('programs_without_plo', $keys);
    }

    public function test_clo_khong_noi_pi_la_cho_thieu_hay_gap_nhat(): void
    {
        $school = $this->truong('SO-GAP2');
        $dept = Department::create(['code' => 'D2', 'name' => 'Khoa', 'school_id' => $school->id]);
        $prog = Program::create(['code' => 'P2', 'name' => 'Ngành', 'department_id' => $dept->id, 'school_id' => $school->id]);
        $plo = Plo::create(['program_id' => $prog->id, 'code' => 'PLO1', 'description' => 'x']);
        $pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1.1', 'description' => 'x']);

        $course = Course::create(['code' => 'C1', 'name' => 'Môn', 'credits' => 3, 'school_id' => $school->id]);
        $syl = CourseSyllabus::create(['course_id' => $course->id, 'version' => 1, 'is_active' => true]);
        $clo1 = Clo::create(['syllabus_id' => $syl->id, 'code' => 'CLO1', 'description' => 'x']);
        Clo::create(['syllabus_id' => $syl->id, 'code' => 'CLO2', 'description' => 'x']); // bỏ lửng
        CloPiMapping::create(['clo_id' => $clo1->id, 'pi_id' => $pi->id, 'mapping_level' => 'M']);

        $kq = app(StructureOverviewService::class)->forSchool($school->id);
        $gaps = collect($kq['gaps'])->keyBy('key');

        $this->assertSame(1, $gaps['clos_without_pi']['count'], 'Chỉ CLO2 bị bỏ lửng');
        $this->assertSame('error', $gaps['clos_without_pi']['severity']);
        $this->assertSame(2, $kq['counts']['clos']);
        $this->assertSame(1, $kq['counts']['mappings']);
    }

    public function test_ban_de_cuong_cu_da_tat_khong_bi_tinh_la_thieu(): void
    {
        $school = $this->truong('SO-OLD');
        $course = Course::create(['code' => 'C9', 'name' => 'Môn', 'credits' => 3, 'school_id' => $school->id]);
        CourseSyllabus::create(['course_id' => $course->id, 'version' => 1, 'is_active' => false]); // bản cũ, rỗng
        $moi = CourseSyllabus::create(['course_id' => $course->id, 'version' => 2, 'is_active' => true]);
        Clo::create(['syllabus_id' => $moi->id, 'code' => 'CLO1', 'description' => 'x']);

        $kq = app(StructureOverviewService::class)->forSchool($school->id);
        $keys = array_column($kq['gaps'], 'key');

        $this->assertNotContains('syllabi_without_clo', $keys, 'Bản đề cương đã tắt không phải là chỗ thiếu');
    }

    public function test_man_hinh_buoc_1_nhan_duoc_tom_tat(): void
    {
        foreach (['super_admin', 'lecturer', 'student'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $school = $this->truong('SO-UI');
        $admin = User::factory()->create(['is_active' => true, 'school_id' => $school->id]);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)
            ->get(route('manager.reports.index', ['step' => 1, 'school_id' => $school->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('structureOverview.counts')
                ->has('structureOverview.gaps')
                ->where('structureOverview.built', false)
                ->where('demoLoaded', false));
    }
}
