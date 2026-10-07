<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\CourseClass;
use App\Services\Export\PscGradeSheetService;
use App\Support\LecturerScope;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * BẢNG ĐIỂM CHO PHẦN MỀM QUẢN LÝ ĐÀO TẠO (PSC).
 *
 * Giảng viên tải file bảng điểm xuất từ phần mềm đào tạo lên, hệ thống điền điểm vào đúng các cột
 * của file đó rồi trả lại — không sinh file mới, vì file gốc còn có lớp hành chính, tình trạng,
 * nợ phí và mã cột riêng của phần mềm đào tạo.
 */
class PscGradeSheetController extends Controller
{
    private const DIR = 'psc-sheets';

    public function form(Request $request, CourseClass $courseClass): Response
    {
        $this->guard($request, $courseClass);

        return Inertia::render('Manager/Classes/PscSheet', $this->props($courseClass));
    }

    public function inspect(Request $request, CourseClass $courseClass, PscGradeSheetService $service): Response|RedirectResponse
    {
        $this->guard($request, $courseClass);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:10240']);

        $this->donDep();

        $token = Str::uuid()->toString() . '.xlsx';
        $request->file('file')->storeAs(self::DIR, $token);
        $path = Storage::path(self::DIR . '/' . $token);

        try {
            $inspection = $service->inspect($path);
        } catch (\RuntimeException $e) {
            Storage::delete(self::DIR . '/' . $token);

            return back()->with('error', $e->getMessage());
        }

        return Inertia::render('Manager/Classes/PscSheet', $this->props($courseClass) + [
            'inspection' => $inspection,
            'mapping' => $service->suggestMapping($inspection['columns'], $courseClass),
            'token' => $token,
            'fileName' => $request->file('file')->getClientOriginalName(),
        ]);
    }

    public function fill(Request $request, CourseClass $courseClass, PscGradeSheetService $service): BinaryFileResponse|RedirectResponse
    {
        $this->guard($request, $courseClass);
        $data = $request->validate([
            'token' => 'required|string',
            'file_name' => 'nullable|string',
            'mapping' => 'required|array',
        ]);

        // Token là tên file do hệ thống tự đặt; chặn mọi thứ không phải uuid.xlsx để không đọc lạc
        // sang file khác trong storage.
        abort_unless(preg_match('/^[0-9a-f-]{36}\.xlsx$/', $data['token']) === 1, 404);
        $source = self::DIR . '/' . $data['token'];
        abort_unless(Storage::exists($source), 404, 'File đã hết hạn, hãy tải lên lại.');

        $out = Storage::path(self::DIR . '/filled-' . $data['token']);
        $service->fill(Storage::path($source), $courseClass, $data['mapping'], $out);

        // File nguồn để lại: người dùng hay đổi lại cách ghép cột rồi tải về lần nữa. `donDep()`
        // sẽ xóa nó ở lần tải lên sau.
        $name = $data['file_name'] ?? ('BangDiem_' . $courseClass->class_code . '.xlsx');

        return response()->download($out, $name)->deleteFileAfterSend();
    }

    /** Xóa file tải lên quá một ngày — chúng chỉ là bản nháp để điền điểm, không phải dữ liệu. */
    private function donDep(): void
    {
        foreach (Storage::files(self::DIR) as $file) {
            if (Storage::lastModified($file) < now()->subDay()->timestamp) {
                Storage::delete($file);
            }
        }
    }

    /** @return array<string,mixed> */
    private function props(CourseClass $courseClass): array
    {
        $courseClass->loadMissing('syllabus.course');

        return [
            'courseClass' => [
                'uuid' => $courseClass->uuid,
                'class_code' => $courseClass->class_code,
                'course' => $courseClass->syllabus?->course?->code . ' — ' . $courseClass->syllabus?->course?->name,
                'academic_year' => $courseClass->academic_year,
                'semester' => $courseClass->semester,
            ],
            'assessments' => Assessment::where('syllabus_id', $courseClass->syllabus_id)
                ->orderBy('id')
                ->get(['id', 'name', 'assessment_category', 'weight_percent'])
                ->map(fn (Assessment $a) => [
                    'id' => (string) $a->id,
                    'name' => $a->name,
                    'category' => $a->assessment_category === 'final' ? 'Cuối kỳ' : 'Quá trình',
                    'weight' => (float) $a->weight_percent,
                ]),
        ];
    }

    private function guard(Request $request, CourseClass $courseClass): void
    {
        abort_if((int) $courseClass->school_id !== (int) WorkingSchool::id($request), 404);
        LecturerScope::assertOwns($courseClass, $request);
    }
}
