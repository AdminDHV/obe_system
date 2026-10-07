<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Assessment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\MeasurementVerifier;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * NGHIỆM THU theo ví dụ tính tay mục 5 của "Tài liệu hướng dẫn xây dựng phần mềm đo lường chuẩn đầu ra theo
 * mô hình WCML" (v1.0) khách gửi — nhập ĐÚNG như trường làm: file khai báo chương trình (ma trận CTĐT) + phiếu
 * lớp học phần. Bảng 14 của tài liệu là số phải ra.
 *
 * Thiết kế (Bảng 5): PLO1 = PI1.1 (0,6) + PI1.2 (0,4); τ = 3.
 *   HP1 3 TC mức I · CC 10% (không gắn CLO) · GK1 40%: T1→CLO1 /4, T2→CLO2 /6 · CK 50%: T3→CLO1 /5, T4→CLO2 /5
 *   HP2 3 TC mức M · CC 10%→CLO2 /10 · GK1 20%: T1→CLO1 /10 · GK2 20%: T2→CLO1 /10 · CK 50%: T3→CLO1 /6, T4→CLO2 /4
 *   HP3 4 TC mức A · không chấm CLO ở CC · GK 40%: T1→CLO1 /10 · CK 50%: T2→CLO1, T3→CLO2
 *     (phiếu bắt tổng điểm tối đa một bài = 10 nên CK của HP3 để mỗi câu /5; điểm quy đổi giữ nguyên 8,5 và 7,5)
 * CLO1 → PI1.1, CLO2 → PI1.2 ở mọi môn.
 *
 * Bảng 14: p(HP1) 8,000 / 7,444 · p(HP2) 8,000 / 7,750 · p(HP3) 8,278 / 7,500
 *          PI1.1 = 81,39% · PI1.2 = 75,87% · PLO1 = 79,18% · SV02 toàn 5,5 ⇒ PLO1 = 55,00%
 */
class WcmlExampleTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        (new MappingScaleWeightSeeder())->run();
        $this->school = School::create(['code' => 'WCML', 'name' => 'Trường nghiệm thu WCML', 'is_active' => true]);

        $kq = app(StructureBuilderService::class)->build(array_map(fn ($rows) => new Collection($rows), [
            'Nganh_Khoa' => [['ma_nganh' => 'THS', 'ten_nganh' => 'Thạc sĩ thử', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]],
            'PLO_PI' => [
                ['ma_plo' => 'PLO1', 'mo_ta_plo' => 'PLO1', 'ma_pi' => 'PI1.1', 'mo_ta_pi' => 'PI1.1', 'trong_so_pi' => 0.6],
                ['ma_plo' => 'PLO1', 'ma_pi' => 'PI1.2', 'mo_ta_pi' => 'PI1.2', 'trong_so_pi' => 0.4],
            ],
            'MonHoc' => [
                ['ma_mon' => 'HP1', 'ten_mon' => 'Học phần 1', 'tin_chi' => 3],
                ['ma_mon' => 'HP2', 'ten_mon' => 'Học phần 2', 'tin_chi' => 3],
                ['ma_mon' => 'HP3', 'ten_mon' => 'Học phần 3', 'tin_chi' => 4],
            ],
            'MaTran_CTDT' => [['Mã môn', 'PLO1'], ['HP1', 'I'], ['HP2', 'M'], ['HP3', 'A']],
        ]), $this->school->id);
        $this->assertSame([], $kq['errors']);
    }

    /**
     * @param array<string, array{0:string, 1:float|int, 2:array<int,array{0:string,1:float|int,2:?string}>}> $bai
     *        tên bài → [loại, trọng số %, các câu [nhãn, điểm tối đa, CLO]]
     * @param array<string, array<string, array<int,float>>> $diem MSSV → tên bài → điểm từng câu
     */
    private function phieu(string $mon, array $bai, array $diem): array
    {
        $info = [
            1 => ['A' => 'PHIẾU LỚP HỌC PHẦN'],
            2 => ['A' => 'Mã môn học', 'B' => $mon],
            3 => ['A' => 'Mã ngành', 'B' => 'THS'],
            4 => ['A' => 'Khóa', 'B' => 'Khóa 2026'],
            5 => ['A' => 'Mã lớp học phần', 'B' => "{$mon}-01"],
            6 => ['A' => 'Giảng viên giảng dạy', 'B' => 'TS. Giảng Viên'],
            7 => ['A' => 'Học kỳ', 'B' => 1],
            9 => ['A' => 'Chuẩn đầu ra', 'B' => 'Nội dung', 'C' => 'PI1.1', 'D' => 'PI1.2'],
            10 => ['A' => 'CLO1', 'B' => 'CLO1', 'C' => 'M'],
            11 => ['A' => 'CLO2', 'B' => 'CLO2', 'D' => 'M'],
            13 => ['A' => 'Đánh giá', 'B' => 'Loại', 'C' => 'Trọng số (%)'],
        ];
        $cols = ['D', 'E', 'F'];
        $sheets = [];
        $r = 14;
        foreach ($bai as $ten => [$loai, $trongSo, $cau]) {
            $info[$r++] = ['A' => $ten, 'B' => $loai, 'C' => $trongSo];
            $s = [
                1 => ['A' => 'Bài đánh giá', 'B' => $ten],
                2 => ['A' => 'Hình thức'],
                3 => ['A' => 'Điểm tối đa'],
                4 => ['A' => 'CLO cần đo'],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên'],
            ];
            foreach ($cau as $i => [$nhan, $max, $clo]) {
                $s[2][$cols[$i]] = str_starts_with($ten, 'Chuyên cần') ? 'Chuyên cần' : 'Tự luận';
                $s[3][$cols[$i]] = $max;
                if ($clo !== null) {
                    $s[4][$cols[$i]] = $clo;
                }
                $s[7][$cols[$i]] = $nhan;
            }
            $row = 8;
            foreach ($diem as $mssv => $theoBai) {
                $line = ['A' => $row - 7, 'B' => $mssv, 'C' => "Học viên {$mssv}"];
                foreach ($theoBai[$ten] as $i => $v) {
                    $line[$cols[$i]] = $v;
                }
                $s[$row++] = $line;
            }
            $sheets[$ten] = $s;
        }

        return ['KhaiBao' => $info] + $sheets;
    }

    private function nhap(array $sheets): array
    {
        $kq = app(ClassSheetService::class)->import((new ClassSheetReader())->parse($sheets), $this->school->id);
        $this->assertSame([], $kq['errors'], implode("\n", $kq['errors']));

        return $kq;
    }

    private function pi(string $mssv, string $code): float
    {
        return (float) Student::where('student_code', $mssv)->firstOrFail()
            ->piResults()->whereHas('pi', fn ($q) => $q->where('code', $code))->firstOrFail()->achieved_score;
    }

    private function plo(string $mssv): float
    {
        return (float) Student::where('student_code', $mssv)->firstOrFail()
            ->ploResults()->whereHas('plo', fn ($q) => $q->where('code', 'PLO1'))->firstOrFail()->achieved_score;
    }

    private function clo(string $mssv, string $mon, string $code): float
    {
        return (float) Student::where('student_code', $mssv)->firstOrFail()->cloResults()
            ->whereHas('clo', fn ($q) => $q->where('code', $code)->whereHas('syllabus.course', fn ($c) => $c->where('code', $mon)))
            ->firstOrFail()->achieved_score;
    }

    public function test_vi_du_muc_5_tai_lieu_wcml_ra_dung_bang_14(): void
    {
        // SV02 đạt đúng 5,5 / 10 ở mọi câu ⇒ điểm = 0,55 × điểm tối đa.
        $hp1 = $this->nhap($this->phieu('HP1', [
            'Chuyên cần' => ['Quá trình', 10, [['Chuyên cần', 10, null]]],
            'Giữa kỳ 1' => ['Quá trình', 40, [['T1', 4, 'CLO1'], ['T2', 6, 'CLO2']]],
            'Cuối kỳ' => ['Cuối kỳ', 50, [['T3', 5, 'CLO1'], ['T4', 5, 'CLO2']]],
        ], [
            'SV01' => ['Chuyên cần' => [9], 'Giữa kỳ 1' => [3.2, 4.8], 'Cuối kỳ' => [4.0, 3.5]],
            'SV02' => ['Chuyên cần' => [5.5], 'Giữa kỳ 1' => [2.2, 3.3], 'Cuối kỳ' => [2.75, 2.75]],
        ]));
        $this->nhap($this->phieu('HP2', [
            'Chuyên cần' => ['Quá trình', 10, [['Chuyên cần', 10, 'CLO2']]],
            'Giữa kỳ 1' => ['Quá trình', 20, [['T1', 10, 'CLO1']]],
            'Giữa kỳ 2' => ['Quá trình', 20, [['T2', 10, 'CLO1']]],
            'Cuối kỳ' => ['Cuối kỳ', 50, [['T3', 6, 'CLO1'], ['T4', 4, 'CLO2']]],
        ], [
            'SV01' => ['Chuyên cần' => [9.0], 'Giữa kỳ 1' => [7.5], 'Giữa kỳ 2' => [8.5], 'Cuối kỳ' => [4.8, 3.0]],
            'SV02' => ['Chuyên cần' => [5.5], 'Giữa kỳ 1' => [5.5], 'Giữa kỳ 2' => [5.5], 'Cuối kỳ' => [3.3, 2.2]],
        ]));
        $hp3 = $this->nhap($this->phieu('HP3', [
            'Chuyên cần' => ['Quá trình', 10, [['Chuyên cần', 10, null]]],
            'Giữa kỳ 1' => ['Quá trình', 40, [['T1', 10, 'CLO1']]],
            'Cuối kỳ' => ['Cuối kỳ', 50, [['T2', 5, 'CLO1'], ['T3', 5, 'CLO2']]],
        ], [
            'SV01' => ['Chuyên cần' => [10], 'Giữa kỳ 1' => [8.0], 'Cuối kỳ' => [4.25, 3.75]],
            'SV02' => ['Chuyên cần' => [5.5], 'Giữa kỳ 1' => [5.5], 'Cuối kỳ' => [2.75, 2.75]],
        ]));

        // Chuyên cần không gắn CLO: nhập được, chỉ cảnh báo; điểm vẫn vào điểm bài.
        $this->assertStringContainsString("câu 'Chuyên cần' không ghi CLO", implode("\n", $hp1['warnings']));
        $this->assertStringContainsString("câu 'Chuyên cần' không ghi CLO", implode("\n", $hp3['warnings']));
        $cc = Assessment::where('name', 'Chuyên cần')->whereHas('syllabus.course', fn ($q) => $q->where('code', 'HP1'))->firstOrFail();
        $this->assertEqualsWithDelta(9.0, (float) StudentGrade::where('assessment_id', $cc->id)
            ->where('student_id', Student::where('student_code', 'SV01')->value('id'))->value('score'), 0.0001);

        // Bảng 7 — điểm CLO (mỗi CLO nối đúng một PI nên trùng điểm PI trong môn).
        $this->assertEqualsWithDelta(8.0, $this->clo('SV01', 'HP1', 'CLO1'), 0.0005);
        $this->assertEqualsWithDelta(67 / 9, $this->clo('SV01', 'HP1', 'CLO2'), 0.0005);   // 7,444
        $this->assertEqualsWithDelta(8.0, $this->clo('SV01', 'HP2', 'CLO1'), 0.0005);      // GK = TB(7,5; 8,5)
        $this->assertEqualsWithDelta(7.75, $this->clo('SV01', 'HP2', 'CLO2'), 0.0005);     // {CC, CK} = 1/6, 5/6
        $this->assertEqualsWithDelta(74.5 / 9, $this->clo('SV01', 'HP3', 'CLO1'), 0.0005); // 8,278
        $this->assertEqualsWithDelta(7.5, $this->clo('SV01', 'HP3', 'CLO2'), 0.0005);      // một khối

        // Bảng 14.
        $this->assertEqualsWithDelta(0.8139, $this->pi('SV01', 'PI1.1'), 0.00005);
        $this->assertEqualsWithDelta(0.7587, $this->pi('SV01', 'PI1.2'), 0.00005);
        $this->assertEqualsWithDelta(0.7918, $this->plo('SV01'), 0.00005);
        $this->assertEqualsWithDelta(0.55, $this->pi('SV02', 'PI1.1'), 0.00005);
        $this->assertEqualsWithDelta(0.55, $this->pi('SV02', 'PI1.2'), 0.00005);
        $this->assertEqualsWithDelta(0.55, $this->plo('SV02'), 0.00005, 'Bất biến sàn: toàn 5,5 phải ra đúng 55,00%');

        $kiem = app(MeasurementVerifier::class)->verifySchool($this->school->id);
        $this->assertSame([], $kiem['mismatches'], implode("\n", $kiem['mismatches']));
        $this->assertGreaterThan(0, $kiem['pi_checked']);
    }
}
