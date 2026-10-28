<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\School;
use App\Services\OBE\StudentLookupService;
use App\Services\OBE\StudentPiBreakdownService;
use App\Services\OBE\StudentProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trang tra cứu CÔNG KHAI: học viên nhập MSSV + mã tra cứu (in trên phiếu khoa phát) để xem
 * kết quả của CHÍNH MÌNH. Không cần tài khoản, không mở thêm cửa đăng nhập.
 *
 * Đây là endpoint không xác thực trả về dữ liệu cá nhân, nên cố ý làm hẹp nhất có thể:
 * không danh sách, không tìm kiếm, một lần tra một người, sai thì báo chung chung.
 */
class PublicLookupController extends Controller
{
    public function show(Request $request): Response
    {
        $schools = $this->schools();
        $picked = $this->pick($schools, $request->query('truong'));

        return Inertia::render('Public/Lookup', [
            'result' => null,
            'schools' => $schools->map(fn (School $s) => $this->schoolProp($s))->values(),
            'school' => $picked?->code,
        ]);
    }

    /**
     * Trường hiện ở ô chọn: đang hoạt động, bỏ các trường dữ liệu mẫu [DEMO] — trừ khi hệ thống chỉ
     * có trường mẫu (máy dev, buổi tập huấn), không thì trang không tra được gì.
     *
     * @return Collection<int,School>
     */
    private function schools(): Collection
    {
        $active = School::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'short_name']);
        // Trường mẫu luôn mang tiền tố "[DEMO]" ở TÊN (DemoSchools) — lọc theo tên, mã thì trường thật cũng có thể bắt đầu bằng DEMO.
        $real = $active->reject(fn (School $s) => str_starts_with($s->name, '[DEMO]'));

        return $real->isNotEmpty() ? $real->values() : $active;
    }

    /** Chỉ một trường thì khỏi chọn; nhiều trường thì phải đúng mã một trường trong danh sách. */
    private function pick(Collection $schools, mixed $code): ?School
    {
        if ($schools->count() === 1) {
            return $schools->first();
        }
        $code = is_string($code) ? strtoupper(trim($code)) : '';

        return $code === '' ? null : $schools->first(fn (School $s) => strtoupper($s->code) === $code);
    }

    /** @return array{code:string,name:string,short_name:?string} */
    private function schoolProp(School $s): array
    {
        return ['code' => $s->code, 'name' => $s->name, 'short_name' => $s->short_name];
    }

    public function lookup(
        Request $request,
        StudentLookupService $lookup,
        StudentPiBreakdownService $breakdown,
        StudentProgressService $progress,
    ): Response|RedirectResponse {
        $data = $request->validate([
            'school' => ['nullable', 'string', 'max:50'],
            'student_code' => ['required', 'string', 'max:50'],
            'lookup_code' => ['required', 'string', 'max:20'],
        ]);

        // Mã số học viên chỉ duy nhất trong từng trường — hai trường trùng mã số thì phải biết tra ở đâu.
        $schools = $this->schools();
        $school = $this->pick($schools, $data['school'] ?? null);
        if (! $school) {
            return back()->withErrors(['school' => 'Chọn trường của bạn trước khi tra cứu.']);
        }

        $student = $lookup->find($data['student_code'], $data['lookup_code'], $request->ip(), $school->id);

        if (! $student) {
            // Thông báo CHUNG cho cả "sai MSSV" lẫn "sai mã" — không xác nhận MSSV có tồn tại.
            return back()->withErrors([
                'lookup_code' => 'Mã số hoặc mã tra cứu không đúng. Vui lòng đối chiếu lại phiếu kết quả của bạn.',
            ]);
        }

        $student->loadMissing(['program', 'cohort']);
        $pi = $breakdown->forStudent($student);

        return Inertia::render('Public/Lookup', [
            'schools' => $schools->map(fn (School $s) => $this->schoolProp($s))->values(),
            'school' => $school->code,
            'result' => [
                'student' => [
                    'student_code' => $student->student_code,
                    'full_name' => $student->full_name,
                    'program' => $student->program?->name,
                    'cohort' => $student->cohort?->name,
                ],
                'progress' => $progress->forStudent($student),
                'obe' => [
                    'is_ratio' => $pi['is_ratio'],
                    'threshold' => $pi['threshold'],
                    'coverage' => $pi['coverage'],
                ],
                'plos' => \App\Models\StudentPloResult::where('student_id', $student->id)
                    ->with('plo')
                    ->get()
                    ->map(fn ($r) => [
                        'code' => $r->plo?->code,
                        'name' => $r->plo?->name,
                        'score' => round((float) $r->achieved_score, 4),
                        'passed' => (float) $r->achieved_score >= $pi['threshold'],
                    ]),
                'pis' => $pi['pis'],
            ],
        ]);
    }
}
