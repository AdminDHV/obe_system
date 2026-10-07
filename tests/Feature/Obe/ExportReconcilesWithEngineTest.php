<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Exports\AunQaReportExport;
use App\Models\Cohort;
use App\Models\Department;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Models\User;
use App\Services\OBE\ClassOutcomeSummaryService;
use App\Services\OBE\ObeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * HAI FILE GỬI RA NGOÀI phải TỰ GIẢI TRÌNH ĐƯỢC: bảng tổng lớp và báo cáo AUN-QA.
 *
 * Cộng tay các dòng học viên in trong chính file đó phải ra đúng dòng tổng hợp phía trên. Đây là
 * việc người nhận sẽ làm — trường mở file ra, đếm lại, và nếu lệch thì mất niềm tin vào cả hệ
 * thống. Khác với test hiển thị: ở đây kiểm NỘI DUNG FILE, vì file đã gửi đi rồi thì không sửa
 * lại được.
 *
 * Test cố ý dựng sẵn kết quả PLO/PI thay vì chạy engine: tầng báo cáo có đúng một nhiệm vụ là
 * tổng hợp trung thực những gì đã lưu, nên đó mới là hợp đồng cần khoá ở đây. Chuỗi tính từ điểm
 * lên PLO đã có test riêng (`StudentSheetReconcilesWithEngineTest`, các golden master).
 */
class ExportReconcilesWithEngineTest extends TestCase
{
    use RefreshDatabase;

    private Program $program;

    private Cohort $cohort;

    private Plo $plo1;

    private Plo $plo2;

    /** Điểm PLO của từng học viên — cố ý có người chưa đo (null) và người dưới ngưỡng. */
    private const DIEM = [
        'SV01' => [0.80, 0.90],
        'SV02' => [0.60, 0.40],
        'SV03' => [0.40, null],
        'SV04' => [null, 0.70],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        ObeConfig::flush();

        $school = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'D1', 'name' => 'Khoa 1']);

        // KHÔNG khai tín chỉ tốt nghiệp ⇒ mọi học viên đều được gộp thống kê, để test đo đúng
        // phép cộng chứ không lẫn với bộ lọc "đủ tiến độ" (đã có test riêng).
        $this->program = Program::create([
            'school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'P1', 'name' => 'Ngành 1',
        ]);
        $this->cohort = Cohort::create([
            'school_id' => $school->id, 'program_id' => $this->program->id,
            'name' => '2026', 'start_year' => 2026,
        ]);

        $this->plo1 = Plo::create(['program_id' => $this->program->id, 'code' => 'PLO1', 'description' => 'CĐR 1']);
        $this->plo2 = Plo::create(['program_id' => $this->program->id, 'code' => 'PLO2', 'description' => 'CĐR 2']);
        $pi1 = Pi::create(['plo_id' => $this->plo1->id, 'code' => 'PI1.1', 'description' => 'Chỉ số 1.1', 'is_dummy' => false, 'weight' => 1.0]);

        foreach (self::DIEM as $ma => [$d1, $d2]) {
            $sv = Student::create([
                'school_id' => $school->id, 'program_id' => $this->program->id, 'cohort_id' => $this->cohort->id,
                'student_code' => $ma, 'full_name' => 'Học viên ' . $ma, 'status' => 'studying',
            ]);
            foreach ([[$this->plo1, $d1], [$this->plo2, $d2]] as [$plo, $diem]) {
                if ($diem !== null) {
                    StudentPloResult::create([
                        'student_id' => $sv->id, 'plo_id' => $plo->id,
                        'achieved_score' => $diem, 'level' => 3,
                    ]);
                }
            }
            if ($d1 !== null) {
                StudentPiResult::create(['student_id' => $sv->id, 'pi_id' => $pi1->id, 'achieved_score' => $d1]);
            }
        }
    }

    /**
     * Bảng tổng lớp: dòng "% đạt" của mỗi PLO phải cộng ra được từ chính các dòng học viên bên
     * dưới nó trong cùng file.
     */
    public function test_bang_tong_lop_cong_lai_dung_tu_cac_dong_hoc_vien(): void
    {
        $data = app(ClassOutcomeSummaryService::class)
            ->forGroup($this->program->id, $this->cohort->id, null, false);

        $nguong = (float) $data['threshold'];
        $this->assertGreaterThan(0.0, $nguong);

        foreach ($data['plo_summary'] as $tong) {
            // Cộng tay từ đúng những dòng học viên được gộp.
            $diem = [];
            foreach ($data['students'] as $sv) {
                if (! $sv['counted']) {
                    continue;
                }
                $v = $sv['plo'][$tong['code']] ?? null;
                if ($v !== null) {
                    $diem[] = (float) $v;
                }
            }

            $dat = count(array_filter($diem, fn ($x) => $x >= $nguong));

            $this->assertSame(count($diem), $tong['students_measured'], "PLO {$tong['code']}: số SV đo");
            $this->assertSame($dat, $tong['passed'], "PLO {$tong['code']}: số đạt");
            $this->assertEqualsWithDelta(
                count($diem) > 0 ? round($dat / count($diem) * 100, 1) : null,
                $tong['attainment_percent'],
                0.05,
                "PLO {$tong['code']}: % đạt phải = số đạt / số SV đo",
            );
            if ($diem !== []) {
                $this->assertEqualsWithDelta(
                    array_sum($diem) / count($diem),
                    (float) $tong['avg_score'],
                    0.0005,
                    "PLO {$tong['code']}: điểm TB",
                );
            }
        }

        // Chốt chặn bằng số cụ thể, để một thay đổi âm thầm ở cách đếm cũng lộ ra.
        $plo1 = collect($data['plo_summary'])->firstWhere('code', 'PLO1');
        $this->assertSame(3, $plo1['students_measured']);   // SV04 chưa đo PLO1
        $this->assertSame(2, $plo1['passed']);              // 0.80 và 0.60 vượt ngưỡng 0.5
        $this->assertEqualsWithDelta(66.7, $plo1['attainment_percent'], 0.05);
    }

    /**
     * Báo cáo AUN-QA: sheet "Mức đạt PLO" phải cộng ra được từ sheet "Ma trận SV x PLO" trong
     * cùng file — đây đúng là việc trường sẽ làm khi kiểm tra lại.
     */
    public function test_bao_cao_aun_qa_cong_lai_dung_tu_ma_tran_trong_cung_file(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $this->actingAs(User::factory()->create());

        $sheets = collect((new AunQaReportExport($this->program->id, $this->cohort->id))->sheets())
            ->keyBy(fn ($s) => $s->title());

        $matran = $sheets->get('Ma trận SV x PLO');
        $tongHop = $sheets->get('Mức đạt PLO');
        $this->assertNotNull($matran, 'Thiếu sheet ma trận');
        $this->assertNotNull($tongHop, 'Thiếu sheet mức đạt');

        // Cột nào là PLO nào — đọc từ chính headings của file.
        $cot = [];
        foreach ($matran->headings() as $i => $ten) {
            if (str_starts_with((string) $ten, 'PLO')) {
                $cot[(string) $ten] = $i;
            }
        }
        $this->assertNotEmpty($cot);

        $nguong = ObeConfig::ploAttainmentRatioPass() * 100; // ma trận in ra dạng %


        foreach ($tongHop->array() as $dong) {
            [$ma, , $soDo, $soDat, $phanTram] = $dong;
            $this->assertArrayHasKey($ma, $cot, "Ma trận thiếu cột {$ma}");

            // Ô trong ma trận in dạng "80%" hoặc "N/A" (chưa đo) — đọc đúng như người mở file đọc.
            $diem = [];
            foreach ($matran->array() as $sv) {
                $o = trim((string) $sv[$cot[$ma]]);
                if ($o === '' || $o === 'N/A') {
                    continue;
                }
                $diem[] = (float) rtrim($o, '%');
            }

            $dat = count(array_filter($diem, fn ($x) => $x >= $nguong - 0.0001));

            $this->assertSame(count($diem), (int) $soDo, "PLO {$ma}: số SV đo phải khớp ma trận");
            $this->assertSame($dat, (int) $soDat, "PLO {$ma}: số đạt phải khớp ma trận");
            $this->assertEqualsWithDelta(
                round($dat / max(count($diem), 1) * 100, 1),
                (float) rtrim((string) $phanTram, '%'),
                0.05,
                "PLO {$ma}: % đạt phải = số đạt / số SV đo",
            );
        }
    }
}
