<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\School;
use App\Models\StudentCloResult;
use App\Models\StudentPloResult;
use App\Models\StudentRubricScore;
use App\Services\Demo\DemoDataService;
use App\Services\Demo\DemoSchools;
use App\Services\OBE\MeasurementVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bộ kiểm đối chiếu độc lập phải làm được HAI việc — thiếu một là vô dụng:
 *  1. Dữ liệu đo đúng thì báo khớp hết (không báo nhầm).
 *  2. Số sai thì BẮT ĐƯỢC: engine lưu sai, hoặc sửa điểm mà quên tính lại.
 *
 * Dữ liệu là bộ mẫu dựng qua đúng hai file (file khai báo + phiếu lớp), nên việc này cũng kiểm toàn
 * bộ đường nhập → đo của mọi sinh viên, không chỉ vài ca tính tay.
 */
class MeasurementVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_moi_sinh_vien_trong_bo_mau_deu_khop_phep_tinh_lai_doc_lap(): void
    {
        app(DemoDataService::class)->seed();

        $tong = ['clo' => 0, 'pi' => 0, 'plo' => 0];
        foreach (array_column(DemoSchools::all(), 'code') as $code) {
            $kq = app(MeasurementVerifier::class)->verifySchool(School::where('code', $code)->firstOrFail()->id);

            $this->assertSame([], $kq['mismatches'], "{$code} lệch:\n" . implode("\n", array_slice($kq['mismatches'], 0, 10)));
            $this->assertSame([], $kq['skipped'], "{$code}: bộ mẫu chấm theo câu hết, không được có phần bỏ qua");
            $tong['clo'] += $kq['clo_checked'];
            $tong['pi'] += $kq['pi_checked'];
            $tong['plo'] += $kq['plo_checked'];
        }

        // Kiểm thật nhiều chứ không phải "0 lệch vì 0 kiểm".
        $this->assertGreaterThan(500, $tong['clo']);
        $this->assertGreaterThan(300, $tong['pi']);
        $this->assertGreaterThan(150, $tong['plo']);
    }

    public function test_bat_duoc_so_luu_sai_va_sua_diem_ma_quen_tinh_lai(): void
    {
        app(DemoDataService::class)->seed();
        $school = School::where('code', DemoSchools::all()[0]['code'])->firstOrFail();
        $verifier = app(MeasurementVerifier::class);

        // Kết quả PLO đã lưu bị lệch 0,05.
        $plo = StudentPloResult::whereHas('student', fn ($q) => $q->where('school_id', $school->id))->firstOrFail();
        $plo->update(['achieved_score' => (float) $plo->achieved_score + 0.05]);
        $this->assertStringContainsString($plo->plo->code, implode("\n", $verifier->verifySchool($school->id)['mismatches']));
        $plo->update(['achieved_score' => (float) $plo->achieved_score - 0.05]);
        $this->assertSame([], $verifier->verifySchool($school->id)['mismatches']);

        // Sửa một điểm câu mà KHÔNG tính lại — CLO đã lưu giờ là số cũ.
        $diem = StudentRubricScore::whereHas('student', fn ($q) => $q->where('school_id', $school->id))
            ->where('score', '>', 0.5)->firstOrFail();
        $diem->update(['score' => (float) $diem->score - 0.5]);
        $lech = $verifier->verifySchool($school->id)['mismatches'];
        $this->assertNotEmpty($lech, 'Sửa điểm mà quên tính lại phải bị bắt');
        $this->assertStringContainsString($diem->student->student_code, implode("\n", $lech));

        // Kết quả CLO bị xóa mất.
        $diem->update(['score' => (float) $diem->score + 0.5]);
        StudentCloResult::whereHas('student', fn ($q) => $q->where('school_id', $school->id))->firstOrFail()->delete();
        $this->assertStringContainsString('CHƯA có kết quả lưu', implode("\n", $verifier->verifySchool($school->id)['mismatches']));
    }
}
