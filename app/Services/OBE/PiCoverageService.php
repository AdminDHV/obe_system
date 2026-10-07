<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\CurriculumGroup;
use App\Models\MappingScaleWeight;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Độ phủ đo lường của một PI: "PI1.1 (1/3)" — PI này được đo qua 3 học phần trong lộ trình của
 * học viên, bạn đã học xong 1.
 *
 * VÌ SAO CẦN: mức đạt PI được tính CỘNG DỒN trên những học phần đã có điểm, nên ngay sau học kỳ 1
 * học viên đã thấy số. Nhưng 85% khi mới học 1/3 học phần và 85% khi đã học đủ 3/3 là hai chuyện
 * khác nhau; không có mẫu số thì người đọc (học viên, CVHT, khoa) dễ kết luận sớm.
 *
 * MẪU SỐ TÍNH THEO LỘ TRÌNH RIÊNG TỪNG HỌC VIÊN, không theo cả CTĐT:
 *   - Mọi học phần thuộc nhóm bắt buộc (và học phần chưa xếp nhóm — coi như bắt buộc).
 *   - Cộng thêm học phần thuộc các hướng chuyên ngành mà CHÍNH học viên đó đã chọn.
 * Nếu đếm cả CTĐT thì học viên hướng Y tế bị đếm luôn học phần hướng Marketing mà họ không bao giờ
 * học ⇒ mẫu số vĩnh viễn không bao giờ đủ.
 *
 * Nhóm kiểu "mở 5 môn chọn 4" (required_credits < tổng tín chỉ của nhóm): mẫu số đếm theo học phần
 * học viên ĐÃ ĐĂNG KÝ trong nhóm, vì hệ thống không thể đoán bạn ấy sẽ bỏ môn nào.
 *
 * Trường CHƯA khai nhóm nào ⇒ trả về `declared = false` và giao diện không hiện độ phủ; thà không
 * hiện còn hơn hiện một mẫu số sai.
 */
class PiCoverageService
{
    /**
     * program_id → cấu trúc đo lường (PI nào đo qua học phần nào).
     *
     * @var array<int, array{
     *   declared:bool,
     *   pi_ids:array<int,int>,
     *   pi_codes:array<int,string>,
     *   course_pis:array<int, array<int,int>>,
     *   mandatory_courses:array<int,int>,
     *   groups:array<int, array{id:int, kind:string, required_credits:float|null,
     *                            course_ids:array<int,int>, total_credits:float, partial:bool}>
     * }>
     */
    private array $cache = [];

    /**
     * Độ phủ của một học viên.
     *
     * @return array{declared:bool, pending_choice:bool, chosen_groups:array<int,string>,
     *               pis:array<string, array{done:int, total:int}>}
     */
    public function forStudent(Student $student): array
    {
        if (! $student->program_id) {
            return $this->empty();
        }

        return $this->forStudents((int) $student->program_id, collect([$student]))[$student->id]
            ?? $this->empty();
    }

    /**
     * Độ phủ của nhiều học viên cùng một chương trình — bảng tổng lớp gọi 1 lần cho cả lớp thay vì
     * mỗi học viên một lượt truy vấn.
     *
     * @param  Collection<int,Student>  $students
     * @return array<int, array{declared:bool, pending_choice:bool, chosen_groups:array<int,string>,
     *                          pis:array<string, array{done:int, total:int}>}>  student_id → độ phủ
     */
    public function forStudents(int $programId, Collection $students): array
    {
        $struct = $this->structureFor($programId);

        if (! $struct['declared'] || $students->isEmpty()) {
            return $students->mapWithKeys(fn (Student $s) => [$s->id => $this->empty()])->all();
        }

        $studentIds = $students->pluck('id')->all();
        $chosen = $this->chosenGroups($studentIds);
        $enrolled = $this->enrolledCourses($studentIds, $struct);
        $measured = $this->measuredCourses($studentIds, $struct);

        $hasElective = collect($struct['groups'])
            ->contains(fn (array $g) => $g['kind'] === CurriculumGroup::KIND_ELECTIVE);

        $out = [];
        foreach ($students as $student) {
            $mine = $chosen[$student->id] ?? [];

            $planned = $struct['mandatory_courses'];
            foreach ($mine as $groupId => $groupName) {
                $group = $struct['groups'][$groupId] ?? null;
                if ($group === null) {
                    continue;
                }
                // Nhóm "chọn N trong M": chỉ đếm môn học viên đã đăng ký, vì không đoán được
                // bạn ấy sẽ bỏ môn nào trong nhóm.
                $ids = $group['partial']
                    ? array_intersect($group['course_ids'], $enrolled[$student->id] ?? [])
                    : $group['course_ids'];
                $planned = array_merge($planned, $ids);
            }
            $planned = array_flip(array_unique($planned));

            $pis = [];
            foreach ($struct['pi_ids'] as $piId) {
                $total = 0;
                $done = 0;
                foreach ($struct['course_pis'] as $courseId => $piIds) {
                    if (! isset($planned[$courseId]) || ! in_array($piId, $piIds, true)) {
                        continue;
                    }
                    $total++;
                    if (isset($measured[$student->id][$courseId][$piId])) {
                        $done++;
                    }
                }
                if ($total > 0) {
                    $pis[$struct['pi_codes'][$piId]] = ['done' => $done, 'total' => $total];
                }
            }

            $out[$student->id] = [
                'declared' => true,
                'pending_choice' => $hasElective && $mine === [],
                'chosen_groups' => array_values($mine),
                'pis' => $pis,
            ];
        }

        return $out;
    }

    /**
     * Độ phủ ở cấp CHƯƠNG TRÌNH: mỗi PI được đo qua bao nhiêu học phần của CTĐT, và học phần đang
     * xem là học phần thứ mấy. Dùng cho bảng của giảng viên — ở đó câu hỏi là "học phần tôi dạy
     * đóng góp bao nhiêu vào PI này", chứ không phải lộ trình của một học viên cụ thể.
     *
     * @return array<string, int>  pi_code → số học phần của CTĐT có đo PI đó
     */
    public function forProgram(int $programId): array
    {
        $struct = $this->structureFor($programId);
        if (! $struct['declared']) {
            return [];
        }

        // Chỉ đếm học phần THUỘC CTĐT này. Ma trận CLO-PI có thể còn học phần của chương trình
        // khác dùng chung PI, đếm cả vào thì mẫu số phồng lên vô lý.
        $inProgram = array_flip(array_merge(
            $struct['mandatory_courses'],
            ...array_map(fn (array $g) => $g['course_ids'], array_values($struct['groups'])),
        ));

        $counts = [];
        foreach ($struct['course_pis'] as $courseId => $piIds) {
            if (! isset($inProgram[$courseId])) {
                continue;
            }
            foreach ($piIds as $piId) {
                $counts[$struct['pi_codes'][$piId]] = ($counts[$struct['pi_codes'][$piId]] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** Học phần nào của CTĐT đo PI nào (dùng lại bởi kiểm tra sức khỏe dữ liệu). */
    public function structureFor(int $programId): array
    {
        if (isset($this->cache[$programId])) {
            return $this->cache[$programId];
        }

        $groups = CurriculumGroup::where('program_id', $programId)->get();

        if ($groups->isEmpty()) {
            return $this->cache[$programId] = [
                'declared' => false, 'pi_ids' => [], 'pi_codes' => [],
                'course_pis' => [], 'mandatory_courses' => [], 'groups' => [],
            ];
        }

        $plos = Plo::where('program_id', $programId)->pluck('id');
        $pis = Pi::whereIn('plo_id', $plos)->orderBy('code')->get(['id', 'code']);

        // Học phần ↔ PI: chỉ tính mức I/R/M/A có trọng số > 0 và học phần có tín chỉ > 0, đúng
        // bộ lọc mà engine đo lường dùng (WeightedAttainmentMethod::structureFor).
        $schoolId = DB::table('programs')->where('id', $programId)->value('school_id');
        $intensity = MappingScaleWeight::forSchool($schoolId ? (int) $schoolId : null);

        $rows = DB::table('clo_pi_mappings as m')
            ->join('clos as cl', 'cl.id', '=', 'm.clo_id')
            ->join('course_syllabus as cs', 'cs.id', '=', 'cl.syllabus_id')
            ->join('courses as c', 'c.id', '=', 'cs.course_id')
            ->whereIn('m.pi_id', $pis->pluck('id'))
            ->where('c.credits', '>', 0)
            ->distinct()
            ->get(['cs.course_id', 'm.pi_id', 'm.mapping_level']);

        $coursePis = [];
        foreach ($rows as $row) {
            if ((float) ($intensity[$row->mapping_level] ?? 0) <= 0) {
                continue;
            }
            $courseId = (int) $row->course_id;
            $piId = (int) $row->pi_id;
            if (! in_array($piId, $coursePis[$courseId] ?? [], true)) {
                $coursePis[$courseId][] = $piId;
            }
        }

        $pivot = DB::table('program_courses as pc')
            ->join('courses as c', 'c.id', '=', 'pc.course_id')
            ->where('pc.program_id', $programId)
            ->get(['pc.course_id', 'pc.curriculum_group_id', 'c.credits']);

        $byGroup = [];
        $mandatory = [];
        foreach ($pivot as $row) {
            $groupId = $row->curriculum_group_id ? (int) $row->curriculum_group_id : null;
            // Học phần chưa xếp nhóm ⇒ coi như bắt buộc. Bỏ qua thì lộ trình bị hụt âm thầm,
            // nguy hiểm hơn là đếm dư.
            if ($groupId === null) {
                $mandatory[] = (int) $row->course_id;

                continue;
            }
            $byGroup[$groupId]['course_ids'][] = (int) $row->course_id;
            $byGroup[$groupId]['credits'] = ($byGroup[$groupId]['credits'] ?? 0.0) + (float) $row->credits;
        }

        $groupInfo = [];
        foreach ($groups as $group) {
            $ids = $byGroup[$group->id]['course_ids'] ?? [];
            $credits = (float) ($byGroup[$group->id]['credits'] ?? 0.0);
            $required = $group->required_credits === null ? null : (float) $group->required_credits;

            if ($group->kind === CurriculumGroup::KIND_MANDATORY) {
                $mandatory = array_merge($mandatory, $ids);
            }

            $groupInfo[$group->id] = [
                'id' => (int) $group->id,
                'kind' => (string) $group->kind,
                'required_credits' => $required,
                'course_ids' => $ids,
                'total_credits' => $credits,
                // Nhóm mở nhiều tín chỉ hơn mức yêu cầu ⇒ học viên chỉ học một phần.
                'partial' => $required !== null && $credits > 0 && $required < $credits - 0.001,
            ];
        }

        return $this->cache[$programId] = [
            'declared' => true,
            'pi_ids' => $pis->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'pi_codes' => $pis->pluck('code', 'id')->all(),
            'course_pis' => $coursePis,
            'mandatory_courses' => array_values(array_unique($mandatory)),
            'groups' => $groupInfo,
        ];
    }

    /**
     * @param  array<int,int>  $studentIds
     * @return array<int, array<int,string>>  student_id → (group_id → tên nhóm)
     */
    private function chosenGroups(array $studentIds): array
    {
        $rows = DB::table('curriculum_group_student as cgs')
            ->join('curriculum_groups as g', 'g.id', '=', 'cgs.curriculum_group_id')
            ->whereIn('cgs.student_id', $studentIds)
            ->get(['cgs.student_id', 'g.id', 'g.name']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->student_id][(int) $row->id] = (string) $row->name;
        }

        return $out;
    }

    /**
     * Học phần học viên đã đăng ký (có tên trong lớp), dùng cho nhóm "chọn N trong M".
     *
     * @param  array<int,int>  $studentIds
     * @return array<int, array<int,int>>  student_id → course_ids
     */
    private function enrolledCourses(array $studentIds, array $struct): array
    {
        $needed = collect($struct['groups'])->filter(fn (array $g) => $g['partial']);
        if ($needed->isEmpty()) {
            return [];
        }

        $rows = DB::table('course_class_student as ccs')
            ->join('course_classes as cc', 'cc.id', '=', 'ccs.class_id')
            ->join('course_syllabus as cs', 'cs.id', '=', 'cc.syllabus_id')
            ->whereIn('ccs.student_id', $studentIds)
            ->distinct()
            ->get(['ccs.student_id', 'cs.course_id']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->student_id][] = (int) $row->course_id;
        }

        return $out;
    }

    /**
     * Học phần nào đã ĐO ĐƯỢC PI nào cho học viên nào — căn cứ trên kết quả CLO đã có, đúng quy
     * tắc mà bảng chi tiết PI của học viên dùng, để hai chỗ không bao giờ lệch nhau.
     *
     * @param  array<int,int>  $studentIds
     * @return array<int, array<int, array<int, true>>>  student_id → course_id → pi_id → true
     */
    private function measuredCourses(array $studentIds, array $struct): array
    {
        $rows = DB::table('student_clo_results as r')
            ->join('clo_pi_mappings as m', 'm.clo_id', '=', 'r.clo_id')
            ->join('clos as cl', 'cl.id', '=', 'r.clo_id')
            ->join('course_syllabus as cs', 'cs.id', '=', 'cl.syllabus_id')
            ->whereIn('r.student_id', $studentIds)
            ->whereIn('m.pi_id', $struct['pi_ids'])
            ->distinct()
            ->get(['r.student_id', 'cs.course_id', 'm.pi_id']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->student_id][(int) $row->course_id][(int) $row->pi_id] = true;
        }

        return $out;
    }

    /** @return array{declared:bool, pending_choice:bool, chosen_groups:array<int,string>, pis:array<string,array{done:int,total:int}>} */
    private function empty(): array
    {
        return ['declared' => false, 'pending_choice' => false, 'chosen_groups' => [], 'pis' => []];
    }
}
