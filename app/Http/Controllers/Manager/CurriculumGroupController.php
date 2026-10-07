<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\CurriculumGroup;
use App\Models\Program;
use App\Models\Student;
use App\Services\OBE\CurriculumGroupService;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Khai cấu trúc CTĐT theo KHỐI học phần: khối bắt buộc, và các hướng chuyên ngành để học viên chọn.
 *
 * Đây là đầu vào để tính độ phủ "PI1.1 (1/3)" — không có nó thì hệ thống không biết học viên nào
 * học những học phần nào, và mẫu số buộc phải đếm cả CTĐT (sai với mọi học viên).
 */
class CurriculumGroupController extends Controller
{
    public function index(Request $request, CurriculumGroupService $service): Response
    {
        $sid = WorkingSchool::id($request);

        $programs = Program::where('school_id', $sid)->orderBy('name')
            ->get(['id', 'uuid', 'code', 'name', 'required_credits']);

        $program = $request->get('program')
            ? $programs->firstWhere('uuid', $request->get('program'))
            : $programs->first();

        if (! $program) {
            return Inertia::render('Manager/Curriculum/Index', [
                'programs' => $programs, 'program' => null,
                'groups' => [], 'ungrouped' => [], 'students' => [],
            ]);
        }

        $audit = $service->audit((int) $program->id);

        // Học phần đã xếp nhóm, gắn kèm nhóm để màn hình vẽ được từng khối.
        $courses = DB::table('program_courses as pc')
            ->join('courses as c', 'c.id', '=', 'pc.course_id')
            ->where('pc.program_id', $program->id)
            ->orderBy('c.code')
            ->get(['c.id', 'c.code', 'c.name', 'c.credits', 'pc.curriculum_group_id'])
            ->map(fn ($c) => [
                'id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name,
                'credits' => (float) $c->credits,
                'group_id' => $c->curriculum_group_id ? (int) $c->curriculum_group_id : null,
            ])
            ->all();

        $chosen = DB::table('curriculum_group_student as cgs')
            ->join('curriculum_groups as g', 'g.id', '=', 'cgs.curriculum_group_id')
            ->where('g.program_id', $program->id)
            ->pluck('cgs.curriculum_group_id', 'cgs.student_id');

        $students = Student::where('program_id', $program->id)
            ->orderBy('student_code')
            ->get(['id', 'uuid', 'student_code', 'full_name', 'class_group'])
            ->map(fn (Student $s) => [
                'id' => $s->id, 'uuid' => $s->uuid, 'student_code' => $s->student_code,
                'full_name' => $s->full_name, 'class_group' => $s->class_group,
                'group_id' => isset($chosen[$s->id]) ? (int) $chosen[$s->id] : null,
            ])
            ->all();

        return Inertia::render('Manager/Curriculum/Index', [
            'programs' => $programs,
            'program' => [
                'uuid' => $program->uuid, 'code' => $program->code, 'name' => $program->name,
                'required_credits' => $program->required_credits,
            ],
            'groups' => $audit['groups'],
            'courses' => $courses,
            'ungrouped' => $audit['ungrouped'],
            'students' => $students,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sid = WorkingSchool::id($request);
        $program = Program::where('school_id', $sid)->where('uuid', $request->get('program'))->firstOrFail();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50',
                Rule::unique('curriculum_groups', 'code')->where('program_id', $program->id)],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([CurriculumGroup::KIND_MANDATORY, CurriculumGroup::KIND_ELECTIVE])],
            'required_credits' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        CurriculumGroup::create($data + [
            'school_id' => $sid,
            'program_id' => $program->id,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return back()->with('success', 'Đã thêm khối học phần.');
    }

    public function update(Request $request, CurriculumGroup $curriculumGroup): RedirectResponse
    {
        $this->assertSameSchool($request, $curriculumGroup);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50',
                Rule::unique('curriculum_groups', 'code')
                    ->where('program_id', $curriculumGroup->program_id)
                    ->ignore($curriculumGroup->id)],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([CurriculumGroup::KIND_MANDATORY, CurriculumGroup::KIND_ELECTIVE])],
            'required_credits' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $curriculumGroup->update($data);

        return back()->with('success', 'Đã cập nhật khối học phần.');
    }

    public function destroy(Request $request, CurriculumGroup $curriculumGroup): RedirectResponse
    {
        $this->assertSameSchool($request, $curriculumGroup);

        // Học phần trong nhóm KHÔNG bị xoá theo — chỉ trở về trạng thái chưa xếp nhóm (khoá ngoại
        // nullOnDelete). Xoá nhầm một khối không được phép làm mất học phần của CTĐT.
        $curriculumGroup->delete();

        return back()->with('success', 'Đã xóa khối học phần. Các học phần trong khối trở về mục chưa xếp nhóm.');
    }

    /** Xếp một loạt học phần vào một khối (hoặc bỏ khỏi khối khi group = null). */
    public function assignCourses(Request $request): RedirectResponse
    {
        $sid = WorkingSchool::id($request);
        $program = Program::where('school_id', $sid)->where('uuid', $request->get('program'))->firstOrFail();

        $data = $request->validate([
            'course_ids' => ['required', 'array', 'min:1'],
            'course_ids.*' => ['integer'],
            'group' => ['nullable', 'string'],
        ]);

        $groupId = null;
        if (! empty($data['group'])) {
            $groupId = (int) CurriculumGroup::where('program_id', $program->id)
                ->where('uuid', $data['group'])->firstOrFail()->id;
        }

        DB::table('program_courses')
            ->where('program_id', $program->id)
            ->whereIn('course_id', $data['course_ids'])
            ->update(['curriculum_group_id' => $groupId]);

        return back()->with('success', 'Đã xếp ' . count($data['course_ids']) . ' học phần.');
    }

    /** Gán hướng chuyên ngành cho một loạt học viên. */
    public function assignStudents(Request $request): RedirectResponse
    {
        $sid = WorkingSchool::id($request);
        $program = Program::where('school_id', $sid)->where('uuid', $request->get('program'))->firstOrFail();

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer'],
            'group' => ['nullable', 'string'],
        ]);

        $students = Student::where('program_id', $program->id)
            ->whereIn('id', $data['student_ids'])->pluck('id');

        $electiveIds = CurriculumGroup::where('program_id', $program->id)
            ->where('kind', CurriculumGroup::KIND_ELECTIVE)->pluck('id');

        DB::transaction(function () use ($students, $electiveIds, $data, $program) {
            // Một học viên chỉ theo MỘT hướng: gỡ hướng cũ trước, nếu không đổi hướng sẽ thành
            // cộng dồn hai hướng và mẫu số phồng gấp đôi.
            DB::table('curriculum_group_student')
                ->whereIn('student_id', $students)
                ->whereIn('curriculum_group_id', $electiveIds)
                ->delete();

            if (empty($data['group'])) {
                return;
            }

            $group = CurriculumGroup::where('program_id', $program->id)
                ->where('uuid', $data['group'])->firstOrFail();

            DB::table('curriculum_group_student')->insertOrIgnore(
                $students->map(fn ($id) => [
                    'curriculum_group_id' => $group->id, 'student_id' => $id,
                    'created_at' => now(), 'updated_at' => now(),
                ])->all(),
            );
        });

        return back()->with('success', 'Đã cập nhật hướng cho ' . $students->count() . ' học viên.');
    }

    /** Suy hướng chuyên ngành từ học phần học viên đã học. */
    public function inferTracks(Request $request, CurriculumGroupService $service): RedirectResponse
    {
        $sid = WorkingSchool::id($request);
        $program = Program::where('school_id', $sid)->where('uuid', $request->get('program'))->firstOrFail();

        $result = $service->inferTracks((int) $program->id);

        $message = 'Đã gán hướng cho ' . $result['assigned'] . ' học viên';
        if ($result['by_group'] !== []) {
            $parts = [];
            foreach ($result['by_group'] as $name => $n) {
                $parts[] = $name . ': ' . $n;
            }
            $message .= ' (' . implode(' · ', $parts) . ')';
        }
        if ($result['already'] > 0) {
            $message .= '. ' . $result['already'] . ' học viên đã có hướng, giữ nguyên';
        }
        if ($result['skipped_ambiguous'] !== []) {
            $message .= '. CẦN XEM LẠI — có điểm ở nhiều hướng: '
                . implode(', ', array_slice($result['skipped_ambiguous'], 0, 10));
        }

        return back()->with('success', $message . '.');
    }

    private function assertSameSchool(Request $request, CurriculumGroup $group): void
    {
        abort_if((int) $group->school_id !== (int) WorkingSchool::id($request), 404);
    }
}
