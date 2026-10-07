<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\OBE\StudentLookupService;
use App\Services\OBE\StudentPiBreakdownService;
use App\Services\OBE\StudentProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function show(): Response
    {
        return Inertia::render('Public/Lookup', ['result' => null]);
    }

    public function lookup(
        Request $request,
        StudentLookupService $lookup,
        StudentPiBreakdownService $breakdown,
        StudentProgressService $progress,
    ): Response|RedirectResponse {
        $data = $request->validate([
            'student_code' => ['required', 'string', 'max:50'],
            'lookup_code' => ['required', 'string', 'max:20'],
        ]);

        $student = $lookup->find($data['student_code'], $data['lookup_code'], $request->ip());

        if (! $student) {
            // Thông báo CHUNG cho cả "sai MSSV" lẫn "sai mã" — không xác nhận MSSV có tồn tại.
            return back()->withErrors([
                'lookup_code' => 'Mã số hoặc mã tra cứu không đúng. Vui lòng đối chiếu lại phiếu kết quả của bạn.',
            ]);
        }

        $student->loadMissing(['program', 'cohort']);
        $pi = $breakdown->forStudent($student);

        return Inertia::render('Public/Lookup', [
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
