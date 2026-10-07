<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CohortStrategy;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Course;
use App\Models\CourseSyllabus;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ObeController extends Controller
{
    private function authorizeManager(): void
    {
        $user = Auth::user();
        $isManager = $user->hasRole('super_admin') || ($user->hasRole('lecturer') && str_contains($user->position ?? '', 'Trưởng'));
        if (!$isManager) {
            abort(403, 'Bạn không có quyền thực hiện thao tác này.');
        }
    }

    /**
     * Trường của request cho các thao tác GHI. Không có trường ⇒ 403 (fail-closed),
     * đúng quy ước tenant: chỉ tin WorkingSchool::id() server-side, không tin payload.
     */
    private function requireSchool(Request $request): int
    {
        $schoolId = WorkingSchool::id($request);
        abort_if($schoolId === null, 403, 'Tài khoản chưa được gán trường.');

        return $schoolId;
    }

    /**
     * Chặn thao tác lên bản ghi của trường khác. PLO/PI/CLO/mapping KHÔNG có cột school_id
     * nên trường được suy qua quan hệ (PLO→ngành, PI→PLO→ngành, CLO→đề cương→môn).
     */
    private function assertOwned(int $schoolId, ?int $ownerSchoolId): void
    {
        abort_if($ownerSchoolId === null || (int) $ownerSchoolId !== $schoolId, 404);
    }

    /** @return Collection<int,int> */
    private function programIds(int $schoolId): Collection
    {
        return Program::where('school_id', $schoolId)->pluck('id');
    }

    /** @return Collection<int,int> */
    private function courseIds(int $schoolId): Collection
    {
        return Course::where('school_id', $schoolId)->pluck('id');
    }

    /** @return Collection<int,int> */
    private function syllabusIds(int $schoolId): Collection
    {
        return CourseSyllabus::whereIn('course_id', $this->courseIds($schoolId))->pluck('id');
    }

    /** @return Collection<int,int> */
    private function ploIds(int $schoolId): Collection
    {
        return Plo::whereIn('program_id', $this->programIds($schoolId))->pluck('id');
    }
    /**
     * Display the OBE Configuration reference or management page.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $isManagerRoute = str_contains($request->route()->getName(), 'manager.obe');
        $isManager = $user->hasRole('super_admin') || ($user->hasRole('lecturer') && str_contains($user->position ?? '', 'Trưởng'));

        // Người xem (cố vấn học tập) được VÀO trang khung chuẩn đầu ra nhưng ở chế độ chỉ đọc —
        // đây là bảng họ cần để hiểu con số PI/PLO đang nhìn. Mọi thao tác ghi vẫn đi qua
        // authorizeManager() nên vẫn 403.
        $isViewer = ! $isManager && $user->hasRole('viewer');

        if ($isManagerRoute && ! $isManager && ! $isViewer) {
            abort(403, 'Bạn không có quyền truy cập trang quản lý này.');
        }

        $readOnly = ! $isManagerRoute || $isViewer;

        // Khung CTĐT phải lọc theo TRƯỜNG ĐANG LÀM VIỆC. Trước đây trang này truy vấn toàn hệ
        // thống nên trưởng bộ môn trường A đọc/sửa/xoá được PLO-PI-CLO của trường B.
        // Chưa gán trường ⇒ thấy rỗng (giống các trang CRUD khác), không phải thấy tất cả.
        $schoolId = WorkingSchool::id($request);
        $programIds = $schoolId === null ? collect() : $this->programIds($schoolId);
        $syllabusIds = $schoolId === null ? collect() : $this->syllabusIds($schoolId);

        $plos = Plo::whereIn('program_id', $programIds)->with(['program'])->withCount('pis')->get();
        $pis = Pi::whereIn('plo_id', $plos->pluck('id'))->with(['plo'])->get();
        $clos = Clo::whereIn('syllabus_id', $syllabusIds)->with(['syllabus.course'])->get();

        return Inertia::render('Obe/Index', [
            'readOnly' => $readOnly,
            'plos' => $plos->map(function ($plo) {
                return [
                    'id' => $plo->id,
                    'uuid' => $plo->uuid,
                    'code' => $plo->code,
                    'description' => $plo->description,
                    'program_id' => $plo->program_id,
                    'program_name' => $plo->program?->name,
                    'pis_count' => $plo->pis_count,
                ];
            }),
            'pis' => $pis->map(function ($pi) {
                return [
                    'id' => $pi->id,
                    'uuid' => $pi->uuid,
                    'code' => $pi->code,
                    'description' => $pi->description,
                    'plo_id' => $pi->plo_id,
                    'plo_code' => $pi->plo?->code,
                    'is_dummy' => (bool)$pi->is_dummy,
                ];
            }),
            'clos' => $clos->map(function ($clo) {
                return [
                    'id' => $clo->id,
                    'uuid' => $clo->uuid,
                    'code' => $clo->code,
                    'description' => $clo->description,
                    'syllabus_id' => $clo->syllabus_id,
                    'syllabus_version' => $clo->syllabus?->version,
                    'course_name' => $clo->syllabus?->course?->name,
                    'course_code' => $clo->syllabus?->course?->code,
                ];
            }),
            // Chỉ mapping nằm TRỌN trong trường (cả CLO lẫn PI). Mapping chéo trường (nếu có do
            // dữ liệu cũ) cố tình không hiện ở đây để không lộ mã PI của trường khác — dọn bằng
            // sửa dữ liệu, không dọn qua UI.
            'mappings' => CloPiMapping::whereIn('clo_id', $clos->pluck('id'))
                ->whereIn('pi_id', $pis->pluck('id'))
                ->with(['clo.syllabus.course', 'pi.plo'])->get()->map(function ($map) {
                return [
                    'id' => $map->id,
                    'uuid' => $map->uuid,
                    'clo_id' => $map->clo_id,
                    'clo_code' => $map->clo?->code,
                    'course_name' => $map->clo?->syllabus?->course?->name,
                    'pi_id' => $map->pi_id,
                    'pi_code' => $map->pi?->code,
                    'plo_code' => $map->pi?->plo?->code,
                    'mapping_level' => $map->mapping_level,
                ];
            }),
            'programs' => Program::whereIn('id', $programIds)->get(['id', 'name', 'code']),
            'courses' => Course::whereIn('id', $schoolId === null ? collect() : $this->courseIds($schoolId))
                ->get(['id', 'name', 'code']),
            'syllabi' => CourseSyllabus::whereIn('id', $syllabusIds)->with('course')->get()->map(function ($syl) {
                return [
                    'id' => $syl->id,
                    'version' => $syl->version,
                    'course_name' => $syl->course?->name,
                    'course_code' => $syl->course?->code,
                ];
            }),
        ]);
    }

    /* PLO Management */
    public function storePlo(Request $request): RedirectResponse
    {
        $this->authorizeManager();
        $schoolId = $this->requireSchool($request);
        $validated = $request->validate([
            'program_id' => ['required', Rule::exists('programs', 'id')->where('school_id', $schoolId)],
            'code' => 'required|string|max:20',
            'description' => 'required|string',
        ]);

        $plo = Plo::create($validated);

        // Không tự sinh "PI ảo" nữa — khách: "không có vụ này". PLO không chia PI thì trường nối CLO
        // thẳng vào PLO ở phiếu lớp học phần; lúc đó hệ thống mới tạo một PI nội bộ đại diện PLO.

        return redirect()->back()->with('success', 'Tạo PLO thành công.');
    }

    public function updatePlo(Request $request, Plo $plo): RedirectResponse
    {
        $this->authorizeManager();
        $schoolId = $this->requireSchool($request);
        $this->assertOwned($schoolId, $plo->loadMissing('program')->program?->school_id);

        $validated = $request->validate([
            'program_id' => ['required', Rule::exists('programs', 'id')->where('school_id', $schoolId)],
            'code' => 'required|string|max:20',
            'description' => 'required|string',
        ]);

        $plo->update($validated);

        return redirect()->back()->with('success', 'Cập nhật PLO thành công.');
    }

    public function destroyPlo(Request $request, Plo $plo): RedirectResponse
    {
        $this->authorizeManager();
        $this->assertOwned($this->requireSchool($request), $plo->loadMissing('program')->program?->school_id);

        $plo->delete();
        return redirect()->back()->with('success', 'Xóa PLO thành công.');
    }

    /* PI Management */
    public function storePi(Request $request): RedirectResponse
    {
        $this->authorizeManager();
        $schoolId = $this->requireSchool($request);
        $validated = $request->validate([
            'plo_id' => ['required', Rule::exists('plos', 'id')->whereIn('program_id', $this->programIds($schoolId)->all())],
            'code' => 'required|string|max:20',
            'description' => 'required|string',
            'weight' => 'nullable|numeric|min:0', // trọng số PI trong PLO (WeightedAttainment); trống = bằng nhau
        ]);

        Pi::create($validated);

        return redirect()->back()->with('success', 'Tạo PI thành công.');
    }

    public function updatePi(Request $request, Pi $pi): RedirectResponse
    {
        $this->authorizeManager();
        $schoolId = $this->requireSchool($request);
        $this->assertOwned($schoolId, $pi->loadMissing('plo.program')->plo?->program?->school_id);

        $validated = $request->validate([
            'plo_id' => ['required', Rule::exists('plos', 'id')->whereIn('program_id', $this->programIds($schoolId)->all())],
            'code' => 'required|string|max:20',
            'description' => 'required|string',
            'weight' => 'nullable|numeric|min:0', // trọng số PI trong PLO (WeightedAttainment); trống = bằng nhau
        ]);

        $pi->update($validated);

        return redirect()->back()->with('success', 'Cập nhật PI thành công.');
    }

    public function destroyPi(Request $request, Pi $pi): RedirectResponse
    {
        $this->authorizeManager();
        $this->assertOwned($this->requireSchool($request), $pi->loadMissing('plo.program')->plo?->program?->school_id);

        $pi->delete();
        return redirect()->back()->with('success', 'Xóa PI thành công.');
    }

    /* CLO Management */
    public function storeClo(Request $request): RedirectResponse
    {
        $this->authorizeManager();
        $schoolId = $this->requireSchool($request);
        $validated = $request->validate([
            'syllabus_id' => ['required', Rule::exists('course_syllabus', 'id')->whereIn('course_id', $this->courseIds($schoolId)->all())],
            'code' => 'required|string|max:20',
            'description' => 'required|string',
        ]);

        Clo::create($validated);

        return redirect()->back()->with('success', 'Tạo CLO thành công.');
    }

    public function updateClo(Request $request, Clo $clo): RedirectResponse
    {
        $this->authorizeManager();
        $schoolId = $this->requireSchool($request);
        $this->assertOwned($schoolId, $clo->loadMissing('syllabus.course')->syllabus?->course?->school_id);

        $validated = $request->validate([
            'syllabus_id' => ['required', Rule::exists('course_syllabus', 'id')->whereIn('course_id', $this->courseIds($schoolId)->all())],
            'code' => 'required|string|max:20',
            'description' => 'required|string',
        ]);

        $clo->update($validated);

        return redirect()->back()->with('success', 'Cập nhật CLO thành công.');
    }

    public function destroyClo(Request $request, Clo $clo): RedirectResponse
    {
        $this->authorizeManager();
        $this->assertOwned($this->requireSchool($request), $clo->loadMissing('syllabus.course')->syllabus?->course?->school_id);

        $clo->delete();
        return redirect()->back()->with('success', 'Xóa CLO thành công.');
    }

    /* CLO-PI Mapping Management */
    public function storeMapping(Request $request): RedirectResponse
    {
        $this->authorizeManager();
        $validated = $request->validate($this->mappingRules($this->requireSchool($request)));

        CloPiMapping::create($validated);

        return redirect()->back()->with('success', 'Tạo mapping CLO-PI thành công.');
    }

    public function updateMapping(Request $request, CloPiMapping $mapping): RedirectResponse
    {
        $this->authorizeManager();
        $schoolId = $this->requireSchool($request);
        $this->assertOwned($schoolId, $mapping->loadMissing('clo.syllabus.course')->clo?->syllabus?->course?->school_id);

        $validated = $request->validate($this->mappingRules($schoolId));

        $mapping->update($validated);

        return redirect()->back()->with('success', 'Cập nhật mapping CLO-PI thành công.');
    }

    public function destroyMapping(Request $request, CloPiMapping $mapping): RedirectResponse
    {
        $this->authorizeManager();
        $this->assertOwned(
            $this->requireSchool($request),
            $mapping->loadMissing('clo.syllabus.course')->clo?->syllabus?->course?->school_id,
        );

        $mapping->delete();
        return redirect()->back()->with('success', 'Xóa mapping CLO-PI thành công.');
    }

    /** Cả CLO lẫn PI của mapping đều phải thuộc trường đang làm việc. */
    private function mappingRules(int $schoolId): array
    {
        return [
            'clo_id' => ['required', Rule::exists('clos', 'id')->whereIn('syllabus_id', $this->syllabusIds($schoolId)->all())],
            'pi_id' => ['required', Rule::exists('pis', 'id')->whereIn('plo_id', $this->ploIds($schoolId)->all())],
            'mapping_level' => 'required|string|in:I,R,M,A',
        ];
    }
}
