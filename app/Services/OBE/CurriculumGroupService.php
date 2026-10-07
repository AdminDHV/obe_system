<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\CurriculumGroup;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Khai báo và bảo trì các khối học phần của chương trình đào tạo.
 *
 * Phần đáng chú ý là `inferTracks()`: các khóa đã học xong từ trước không có ai khai "bạn này theo
 * hướng nào", nhưng dữ liệu điểm đã nói ra điều đó — học viên chỉ có điểm ở đúng những học phần của
 * hướng mình chọn. Suy ngược lại rẻ hơn và ít sai hơn bắt trường nhập tay 42 dòng.
 *
 * Cố ý CHỈ gán khi kết quả rõ ràng: học viên có điểm ở hai hướng khác nhau (chuyển hướng giữa
 * chừng, hoặc ma trận khai sai) thì bỏ qua và báo ra, chứ không đoán bừa — gán sai một hướng là
 * mẫu số của cả người đó sai theo.
 */
class CurriculumGroupService
{
    /**
     * Gán hướng chuyên ngành cho học viên dựa trên học phần họ đã học.
     *
     * @param  bool  $apply  false ⇒ chỉ thử, không ghi
     * @return array{assigned:int, skipped_ambiguous:array<int,string>, skipped_no_data:array<int,string>,
     *               already:int, by_group:array<string,int>}
     */
    public function inferTracks(int $programId, bool $apply = true): array
    {
        $groups = CurriculumGroup::where('program_id', $programId)
            ->where('kind', CurriculumGroup::KIND_ELECTIVE)
            ->get();

        if ($groups->isEmpty()) {
            return ['assigned' => 0, 'skipped_ambiguous' => [], 'skipped_no_data' => [], 'already' => 0, 'by_group' => []];
        }

        // course_id → group_id, chỉ trong các nhóm tự chọn của chính ngành này.
        $courseGroup = DB::table('program_courses')
            ->where('program_id', $programId)
            ->whereIn('curriculum_group_id', $groups->pluck('id'))
            ->pluck('curriculum_group_id', 'course_id');

        $students = Student::where('program_id', $programId)->orderBy('student_code')->get();

        // Học phần mỗi học viên ĐÃ CÓ ĐIỂM (không dùng danh sách đăng ký: nhiều bộ nhập điểm của
        // trường không ghi vào course_class_student, còn điểm thì luôn có).
        $rows = DB::table('student_clo_results as r')
            ->join('course_classes as cc', 'cc.id', '=', 'r.class_id')
            ->join('course_syllabus as cs', 'cs.id', '=', 'cc.syllabus_id')
            ->whereIn('r.student_id', $students->pluck('id'))
            ->distinct()
            ->get(['r.student_id', 'cs.course_id']);

        $hoc = [];
        foreach ($rows as $row) {
            $groupId = $courseGroup[$row->course_id] ?? null;
            if ($groupId !== null) {
                $hoc[(int) $row->student_id][(int) $groupId] = ($hoc[(int) $row->student_id][(int) $groupId] ?? 0) + 1;
            }
        }

        $daChon = DB::table('curriculum_group_student')
            ->whereIn('student_id', $students->pluck('id'))
            ->whereIn('curriculum_group_id', $groups->pluck('id'))
            ->pluck('student_id')
            ->unique()
            ->flip();

        $tenNhom = $groups->pluck('name', 'id');
        $out = ['assigned' => 0, 'skipped_ambiguous' => [], 'skipped_no_data' => [], 'already' => 0, 'by_group' => []];
        $insert = [];

        foreach ($students as $student) {
            if (isset($daChon[$student->id])) {
                $out['already']++;

                continue;
            }

            $counts = $hoc[$student->id] ?? [];
            if ($counts === []) {
                $out['skipped_no_data'][] = $student->student_code;

                continue;
            }
            if (count($counts) > 1) {
                $out['skipped_ambiguous'][] = $student->student_code;

                continue;
            }

            $groupId = (int) array_key_first($counts);
            $insert[] = [
                'curriculum_group_id' => $groupId,
                'student_id' => $student->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $out['assigned']++;
            $ten = (string) $tenNhom[$groupId];
            $out['by_group'][$ten] = ($out['by_group'][$ten] ?? 0) + 1;
        }

        if ($apply && $insert !== []) {
            foreach (array_chunk($insert, 500) as $chunk) {
                DB::table('curriculum_group_student')->insertOrIgnore($chunk);
            }
            Log::channel('obe')->info('Suy hướng chuyên ngành từ học phần đã học', [
                'program_id' => $programId,
                'assigned' => $out['assigned'],
                'ambiguous' => count($out['skipped_ambiguous']),
            ]);
        }

        return $out;
    }

    /**
     * Sức khỏe của cấu trúc CTĐT: nhóm nào khai thiếu/thừa tín chỉ, học phần nào chưa xếp nhóm.
     *
     * @return array{groups:array<int,array<string,mixed>>, ungrouped:array<int,array<string,mixed>>}
     */
    public function audit(int $programId): array
    {
        $groups = CurriculumGroup::where('program_id', $programId)
            ->orderBy('sort_order')->orderBy('code')->get();

        $stats = DB::table('program_courses as pc')
            ->join('courses as c', 'c.id', '=', 'pc.course_id')
            ->where('pc.program_id', $programId)
            ->whereNotNull('pc.curriculum_group_id')
            ->groupBy('pc.curriculum_group_id')
            ->selectRaw('pc.curriculum_group_id as gid, count(*) as so_mon, sum(c.credits) as tin_chi')
            ->get()
            ->keyBy('gid');

        $studentCounts = DB::table('curriculum_group_student')
            ->whereIn('curriculum_group_id', $groups->pluck('id'))
            ->groupBy('curriculum_group_id')
            ->selectRaw('curriculum_group_id as gid, count(*) as n')
            ->pluck('n', 'gid');

        $rows = [];
        foreach ($groups as $group) {
            $credits = (float) ($stats[$group->id]->tin_chi ?? 0);
            $required = $group->required_credits === null ? null : (float) $group->required_credits;

            $rows[] = [
                'id' => $group->id,
                'uuid' => $group->uuid,
                'code' => $group->code,
                'name' => $group->name,
                'kind' => $group->kind,
                'required_credits' => $required,
                'sort_order' => $group->sort_order,
                'courses_count' => (int) ($stats[$group->id]->so_mon ?? 0),
                'credits_total' => $credits,
                'students_count' => (int) ($studentCounts[$group->id] ?? 0),
                // Khai 15 TC mà chỉ xếp được 12 TC học phần ⇒ CTĐT còn thiếu môn, hoặc số tín chỉ
                // yêu cầu khai sai. Không đoán hộ, chỉ nói ra.
                'shortfall' => $required !== null && $credits < $required - 0.001
                    ? round($required - $credits, 2)
                    : null,
            ];
        }

        $ungrouped = DB::table('program_courses as pc')
            ->join('courses as c', 'c.id', '=', 'pc.course_id')
            ->where('pc.program_id', $programId)
            ->whereNull('pc.curriculum_group_id')
            ->orderBy('c.code')
            ->get(['c.id', 'c.code', 'c.name', 'c.credits'])
            ->map(fn ($c) => [
                'id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name, 'credits' => (float) $c->credits,
            ])
            ->all();

        return ['groups' => $rows, 'ungrouped' => $ungrouped];
    }
}
