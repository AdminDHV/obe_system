<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Clo;
use App\Models\School;
use App\Models\Student;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\MeasurementVerifier;
use App\Services\OBE\StructureBuilderService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * MA TRẬN CTĐT (môn × PLO/PI) quyết định mức I/R/M/A dùng làm trọng số; ma trận CLO của phiếu chỉ cho
 * biết CLO nào đo PLO/PI nào. Khách góp ý + chốt: file khai báo chương trình không nhập CLO, nhập ma trận
 * của CTĐT; phiếu nối CLO ra ngoài ma trận thì chặn.
 *
 * Số tính TAY (I/R/M/A = 1/2/3/3), SV01 có một câu tối đa 10 ở cuối kỳ mỗi môn:
 *   M01 (3 TC): CLO1 = 8 — phiếu ghi CLO1→PLO1 mức R, CTĐT ghi M01→PLO1 mức M
 *   M02 (2 TC): CLO1 = 5 — phiếu ghi CLO1→PLO1 mức A, CTĐT ghi M02→PLO1 mức I
 *   PLO1 theo CTĐT = (3×3×8 + 2×1×5) / (3×3×10 + 2×1×10) = 82/110 = 0.745455
 *   (nếu còn lấy mức CLO: (3×2×8 + 2×3×5) / (60 + 60) = 0.65 — test phải KHÔNG ra số này)
 */
class CtdtMatrixMeasurementTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        (new MappingScaleWeightSeeder())->run();
        $this->school = School::create(['code' => 'CTDT', 'name' => 'Trường thử ma trận CTĐT', 'is_active' => true]);

        $kq = app(StructureBuilderService::class)->build(array_map(fn ($rows) => new Collection($rows), [
            'Nganh_Khoa' => [['ma_nganh' => 'TCNH', 'ten_nganh' => 'Tài chính', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]],
            'PLO_PI' => [
                ['ma_plo' => 'PLO1', 'mo_ta_plo' => 'Kiến thức'],
                ['ma_plo' => 'PLO2', 'mo_ta_plo' => 'Kỹ năng', 'ma_pi' => 'PI2.1', 'trong_so_pi' => 0.5],
                ['ma_plo' => 'PLO2', 'ma_pi' => 'PI2.2', 'trong_so_pi' => 0.5],
            ],
            'MonHoc' => [
                ['ma_mon' => 'M01', 'ten_mon' => 'Môn một', 'tin_chi' => 3],
                ['ma_mon' => 'M02', 'ten_mon' => 'Môn hai', 'tin_chi' => 2],
                ['ma_mon' => 'M03', 'ten_mon' => 'Môn ngoài CTĐT', 'tin_chi' => 2],
            ],
            'MaTran_CTDT' => [
                ['Mã môn', 'PLO1', 'PLO2'],
                ['M01', 'M', null],
                ['M02', 'I', 'R'],
            ],
        ]), $this->school->id);
        $this->assertSame([], $kq['errors']);
    }

    /**
     * Phiếu tối giản: một bài cuối kỳ 100%, một câu tối đa 10 đo CLO đầu tiên.
     *
     * @param array<string, array<string,string>> $clos mã CLO → (mã PLO/PI → mức)
     */
    private function phieu(string $mon, array $clos, float $diem): array
    {
        $codes = collect($clos)->flatMap(fn ($m) => array_keys($m))->unique()->values()->all();
        $cols = ['C', 'D', 'E', 'F'];
        $info = [
            1 => ['A' => 'PHIẾU LỚP HỌC PHẦN'],
            2 => ['A' => 'Mã môn học', 'B' => $mon],
            3 => ['A' => 'Mã ngành', 'B' => 'TCNH'],
            4 => ['A' => 'Khóa', 'B' => 'Khóa 2026'],
            5 => ['A' => 'Mã lớp học phần', 'B' => "{$mon}-A"],
            6 => ['A' => 'Giảng viên giảng dạy', 'B' => 'Trần Thị Diện'],
            7 => ['A' => 'Học kỳ', 'B' => 1],
        ];
        $header = ['A' => 'Chuẩn đầu ra', 'B' => 'Nội dung'];
        foreach ($codes as $i => $c) {
            $header[$cols[$i]] = $c;
        }
        $info[9] = $header;
        $r = 10;
        foreach ($clos as $code => $map) {
            $row = ['A' => $code, 'B' => "Nội dung {$code}"];
            foreach ($map as $target => $level) {
                $row[$cols[array_search($target, $codes, true)]] = $level;
            }
            $info[$r++] = $row;
        }
        $info[$r + 1] = ['A' => 'Đánh giá', 'B' => 'Loại', 'C' => 'Trọng số (%)'];
        $info[$r + 2] = ['A' => 'Cuối kỳ', 'B' => 'Cuối kỳ', 'C' => 100];

        return [
            'KhaiBao' => $info,
            'Cuối kỳ' => [
                2 => ['A' => 'Hình thức', 'D' => 'Tự luận'],
                3 => ['A' => 'Điểm tối đa', 'D' => 10],
                4 => ['A' => 'CLO cần đo', 'D' => array_key_first($clos)],
                7 => ['A' => 'STT', 'B' => 'MSSV', 'C' => 'Họ và tên', 'D' => 'Câu 1'],
                8 => ['A' => 1, 'B' => 'SV01', 'C' => 'Nguyễn Văn An', 'D' => $diem],
            ],
        ];
    }

    private function nhap(array $sheets): array
    {
        return app(ClassSheetService::class)->import((new ClassSheetReader())->parse($sheets), $this->school->id);
    }

    private function plo(string $code): float
    {
        return (float) Student::where('student_code', 'SV01')->firstOrFail()
            ->ploResults()->whereHas('plo', fn ($q) => $q->where('code', $code))->firstOrFail()->achieved_score;
    }

    public function test_muc_lay_tu_ma_tran_ctdt_khong_lay_muc_clo_cua_phieu(): void
    {
        $a = $this->nhap($this->phieu('M01', ['CLO1' => ['PLO1' => 'R']], 8));
        $this->assertSame([], $a['errors']);
        $b = $this->nhap($this->phieu('M02', ['CLO1' => ['PLO1' => 'A']], 5));
        $this->assertSame([], $b['errors']);

        $this->assertEqualsWithDelta(82 / 110, $this->plo('PLO1'), 0.0001, 'Trọng số phải là tín chỉ × mức CTĐT (M, I), không phải mức CLO (R, A)');

        $kiem = app(MeasurementVerifier::class)->verifySchool($this->school->id);
        $this->assertSame([], $kiem['mismatches'], 'Bộ kiểm đối chiếu dùng đúng quy tắc mức CTĐT');
        $this->assertGreaterThan(0, $kiem['plo_checked']);
    }

    public function test_phieu_noi_clo_ra_ngoai_ma_tran_ctdt_bi_chan(): void
    {
        $kq = $this->nhap($this->phieu('M01', ['CLO1' => ['PLO1' => 'M'], 'CLO2' => ['PI2.1' => 'R']], 8));

        $this->assertStringContainsString('ma trận CTĐT của Khóa 2026 không ghi môn M01 đóng góp PI2.1', implode("\n", $kq['errors']));
        $this->assertSame(0, Clo::count(), 'Phiếu bị chặn thì không ghi gì');

        $ngoai = $this->nhap($this->phieu('M03', ['CLO1' => ['PLO1' => 'M']], 8));
        $this->assertStringContainsString('chưa có môn M03', implode("\n", $ngoai['errors']));
    }

    public function test_ctdt_co_ma_phieu_chua_do_thi_chi_canh_bao(): void
    {
        $kq = $this->nhap($this->phieu('M02', ['CLO1' => ['PLO1' => 'I']], 5));

        $this->assertSame([], $kq['errors']);
        $this->assertStringContainsString('đóng góp PI2.1, PI2.2 nhưng phiếu chưa có CLO nào đo', implode("\n", $kq['warnings']));
    }
}
