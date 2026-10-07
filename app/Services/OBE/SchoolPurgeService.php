<?php

declare(strict_types=1);

namespace App\Services\OBE;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * XÓA HẲN toàn bộ dữ liệu của một hoặc nhiều TRƯỜNG — dùng để dọn trường thử nghiệm / dữ liệu cũ.
 *
 * Xóa trên production không hoàn tác được, nên có ba lớp an toàn:
 *  1. Chạy trong MỘT transaction, xóa CON trước CHA bằng query builder (xóa cứng, bỏ qua soft delete —
 *     để lại dòng "đã xóa mềm" thì dữ liệu học viên vẫn nằm trong DB).
 *  2. Chụp số liệu của mọi trường KHÔNG bị xóa trước và sau; lệch dù một dòng là hoàn tác cả lần xóa.
 *     Chặn đúng loại tai nạn dây chuyền khóa ngoại (vd xóa tài khoản giảng viên → xóa luôn lớp của họ
 *     ở trường khác, vì `course_classes.lecturer_id` cascade).
 *  3. `dryRun`: làm đủ các bước rồi hoàn tác, trả về số dòng SẼ xóa để người vận hành đọc trước.
 *
 * Tài khoản chỉ bị xóa khi chắc chắn thuộc trường bị xóa: không phải super_admin, và không còn dạy lớp,
 * tải file hay tạo báo cáo ở trường nào khác. Tài khoản còn dính trường khác thì giữ lại.
 */
final class SchoolPurgeService
{
    /** @var array<string,int> */
    private array $counts = [];

    /**
     * @param array<int,int> $schoolIds
     * @return array<string,int> bảng → số dòng đã (hoặc sẽ) xóa
     */
    public function purge(array $schoolIds, bool $dryRun = false): array
    {
        $sids = array_values(array_unique(array_map('intval', $schoolIds)));
        if ($sids === []) {
            return [];
        }

        $this->counts = [];
        $files = [];

        DB::beginTransaction();
        try {
            $before = $this->otherSchoolsFingerprint($sids);
            $files = $this->run($sids);
            $after = $this->otherSchoolsFingerprint($sids);

            if ($before !== $after) {
                throw new RuntimeException('Dừng và hoàn tác: dữ liệu của trường KHÔNG bị xóa đã thay đổi — '
                    . json_encode(['truoc' => $before, 'sau' => $after], JSON_UNESCAPED_UNICODE));
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if (! $dryRun) {
            foreach ($files as $path) {
                if ($path && Storage::exists($path)) {
                    Storage::delete($path);
                }
            }
        }

        ksort($this->counts);

        return $this->counts;
    }

    /** @param array<int,int> $sids @return array<int,string> file cần xóa khỏi storage sau khi commit */
    private function run(array $sids): array
    {
        $ids = fn (string $table, string $col, $values) => $this->pluck($table, $col, $values);

        $programIds = $ids('programs', 'school_id', $sids);
        $courseIds = $ids('courses', 'school_id', $sids);
        $classIds = $ids('course_classes', 'school_id', $sids);
        $studentIds = $ids('students', 'school_id', $sids);
        $cohortIds = $ids('cohorts', 'school_id', $sids)->merge($ids('cohorts', 'program_id', $programIds))->unique()->values();
        $syllabusIds = $ids('course_syllabus', 'course_id', $courseIds);
        $cloIds = $ids('clos', 'syllabus_id', $syllabusIds);
        $assessmentIds = $ids('assessments', 'syllabus_id', $syllabusIds);
        $rubricIds = $ids('rubrics', 'assessment_id', $assessmentIds);
        $criteriaIds = $ids('rubric_criteria', 'rubric_id', $rubricIds)->merge($ids('rubric_criteria', 'class_id', $classIds))->unique()->values();
        $ploIds = $ids('plos', 'program_id', $programIds);
        $piIds = $ids('pis', 'plo_id', $ploIds);
        $snapshotIds = $ids('measurement_snapshots', 'school_id', $sids);
        $instrumentIds = $ids('survey_instruments', 'school_id', $sids);
        $groupIds = $ids('curriculum_groups', 'school_id', $sids);
        $departmentIds = $ids('departments', 'school_id', $sids);

        $userIds = $this->usersOnlyIn($sids, $classIds);

        $files = DB::table('report_snapshots')->whereIn('school_id', $sids)->pluck('file_path')
            ->merge(DB::table('file_batches')->whereIn('school_id', $sids)->pluck('file_path'))
            ->filter()->values()->all();

        // Kết quả và điểm
        $this->del('student_rubric_scores', ['rubric_criteria_id' => $criteriaIds, 'class_id' => $classIds, 'student_id' => $studentIds]);
        $this->del('student_grades', ['class_id' => $classIds, 'student_id' => $studentIds, 'assessment_id' => $assessmentIds]);
        $this->del('student_clo_results', ['class_id' => $classIds, 'student_id' => $studentIds, 'clo_id' => $cloIds]);
        $this->del('student_pi_results', ['pi_id' => $piIds, 'student_id' => $studentIds]);
        $this->del('student_plo_results', ['plo_id' => $ploIds, 'student_id' => $studentIds]);
        $this->del('course_class_student', ['class_id' => $classIds, 'student_id' => $studentIds]);

        // Snapshot, khảo sát, nhóm học phần, CQI, mục tiêu
        $this->del('measurement_snapshot_outcomes', ['snapshot_id' => $snapshotIds]);
        $this->del('measurement_snapshot_students', ['snapshot_id' => $snapshotIds, 'student_id' => $studentIds]);
        $this->del('measurement_snapshots', ['id' => $snapshotIds]);
        $this->del('survey_plo_results', ['survey_instrument_id' => $instrumentIds, 'plo_id' => $ploIds]);
        $this->del('survey_instruments', ['id' => $instrumentIds]);
        $this->del('curriculum_group_student', ['curriculum_group_id' => $groupIds, 'student_id' => $studentIds]);
        $this->del('curriculum_groups', ['id' => $groupIds]);
        $this->del('cqi_actions', ['school_id' => $sids, 'program_id' => $programIds, 'plo_id' => $ploIds]);
        $this->del('obe_targets', ['school_id' => $sids, 'plo_id' => $ploIds, 'pi_id' => $piIds]);
        $this->del('cohort_pi_weights', ['cohort_id' => $cohortIds, 'pi_id' => $piIds]);

        // Khung
        $this->del('rubric_criteria', ['id' => $criteriaIds]);
        $this->del('rubrics', ['id' => $rubricIds]);
        $this->del('assessment_clo_mappings', ['assessment_id' => $assessmentIds, 'clo_id' => $cloIds]);
        $this->del('clo_pi_mappings', ['clo_id' => $cloIds, 'pi_id' => $piIds]);
        $this->del('framework_plo_mappings', ['plo_id' => $ploIds]);
        $this->del('assessments', ['id' => $assessmentIds]);
        $this->del('clos', ['id' => $cloIds]);
        $this->del('course_prerequisites', ['course_id' => $courseIds, 'required_course_id' => $courseIds]);
        $this->del('course_classes', ['id' => $classIds]);
        $this->del('course_syllabus', ['id' => $syllabusIds]);
        $this->del('program_courses', ['program_id' => $programIds, 'course_id' => $courseIds]);
        $this->del('courses', ['id' => $courseIds]);
        $this->del('pis', ['id' => $piIds]);
        $this->del('plos', ['id' => $ploIds]);
        $this->del('students', ['id' => $studentIds]);
        $this->del('cohorts', ['id' => $cohortIds]);
        $this->del('programs', ['id' => $programIds]);

        // Vận hành
        $this->del('file_batches', ['school_id' => $sids]);
        $this->del('report_snapshots', ['school_id' => $sids]);
        $this->del('measurement_runs', ['school_id' => $sids]);
        $this->del('system_settings', ['school_id' => $sids]);
        $this->del('mapping_scale_weights', ['school_id' => $sids]);

        // Tài khoản giữ lại mà đang trỏ vào khoa sắp xóa: gỡ khoa trước, kẻo khóa ngoại cascade xóa luôn họ.
        if ($departmentIds->isNotEmpty() && Schema::hasColumn('users', 'department_id')) {
            DB::table('users')->whereIn('department_id', $departmentIds)->whereNotIn('id', $userIds)->update(['department_id' => null]);
        }
        if (Schema::hasColumn('users', 'school_id')) {
            DB::table('users')->whereIn('school_id', $sids)->whereNotIn('id', $userIds)->update(['school_id' => null]);
        }

        $this->del('model_has_roles', ['model_id' => $userIds], fn ($q) => $q->where('model_type', \App\Models\User::class));
        $this->del('users', ['id' => $userIds]);

        if (Schema::hasColumn('departments', 'parent_id')) {
            DB::table('departments')->whereIn('id', $departmentIds)->update(['parent_id' => null]);
        }
        $this->del('departments', ['id' => $departmentIds]);
        $this->del('schools', ['id' => $sids]);

        return $files;
    }

    /**
     * Tài khoản thuộc RIÊNG các trường bị xóa: gắn trường đó, hoặc chỉ dạy lớp ở trường đó — và không
     * dạy lớp / tải file / tạo báo cáo ở trường nào khác. super_admin không bao giờ bị xóa.
     *
     * @param array<int,int> $sids
     * @return Collection<int,int>
     */
    private function usersOnlyIn(array $sids, Collection $classIds): Collection
    {
        $candidates = DB::table('users')->whereIn('school_id', $sids)->pluck('id')
            ->merge(DB::table('course_classes')->whereIn('id', $classIds)->pluck('lecturer_id'))
            ->filter()->unique()->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $superAdmins = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'super_admin')
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->pluck('model_has_roles.model_id');

        $elsewhere = DB::table('course_classes')->whereNotIn('school_id', $sids)->whereIn('lecturer_id', $candidates)->pluck('lecturer_id')
            ->merge(DB::table('file_batches')->where(fn ($q) => $q->whereNotIn('school_id', $sids)->orWhereNull('school_id'))->whereIn('user_id', $candidates)->pluck('user_id'))
            ->merge(DB::table('report_snapshots')->where(fn ($q) => $q->whereNotIn('school_id', $sids)->orWhereNull('school_id'))->whereIn('user_id', $candidates)->pluck('user_id'));

        return $candidates->diff($superAdmins)->diff($elsewhere)->values();
    }

    /**
     * Số liệu then chốt của mọi trường KHÔNG bị xóa. Phải y hệt trước và sau khi xóa.
     *
     * @param array<int,int> $sids
     * @return array<string,int>
     */
    private function otherSchoolsFingerprint(array $sids): array
    {
        $otherClasses = DB::table('course_classes')->whereNotIn('school_id', $sids)->select('id');
        $otherStudents = DB::table('students')->whereNotIn('school_id', $sids)->select('id');

        return [
            'schools' => DB::table('schools')->whereNotIn('id', $sids)->count(),
            'programs' => DB::table('programs')->whereNotIn('school_id', $sids)->count(),
            'courses' => DB::table('courses')->whereNotIn('school_id', $sids)->count(),
            'course_classes' => DB::table('course_classes')->whereNotIn('school_id', $sids)->count(),
            'students' => DB::table('students')->whereNotIn('school_id', $sids)->count(),
            'student_grades' => DB::table('student_grades')->whereIn('class_id', $otherClasses)->count(),
            'student_rubric_scores' => DB::table('student_rubric_scores')->whereIn('class_id', $otherClasses)->count(),
            'student_clo_results' => DB::table('student_clo_results')->whereIn('class_id', $otherClasses)->count(),
            'student_pi_results' => DB::table('student_pi_results')->whereIn('student_id', $otherStudents)->count(),
            'student_plo_results' => DB::table('student_plo_results')->whereIn('student_id', $otherStudents)->count(),
            'rubric_criteria_of_other_classes' => DB::table('rubric_criteria')->whereIn('class_id', $otherClasses)->count(),
        ];
    }

    /** @return Collection<int,int> */
    private function pluck(string $table, string $col, $values): Collection
    {
        $values = collect($values)->filter()->values();
        if ($values->isEmpty() || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
            return collect();
        }
        $key = Schema::hasColumn($table, 'id') ? 'id' : $col;

        return DB::table($table)->whereIn($col, $values)->pluck($key)->unique()->values();
    }

    /** @param array<string,mixed> $where cột → danh sách id (dòng khớp BẤT KỲ cột nào sẽ bị xóa) */
    private function del(string $table, array $where, ?\Closure $extra = null): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $clauses = [];
        foreach ($where as $col => $values) {
            $values = collect($values)->filter(fn ($v) => $v !== null)->values()->all();
            if ($values !== [] && Schema::hasColumn($table, $col)) {
                $clauses[$col] = $values;
            }
        }
        if ($clauses === []) {
            return;
        }

        $q = DB::table($table)->where(function ($w) use ($clauses) {
            foreach ($clauses as $col => $values) {
                foreach (array_chunk($values, 1000) as $chunk) {
                    $w->orWhereIn($col, $chunk);
                }
            }
        });
        if ($extra) {
            $extra($q);
        }

        $n = $q->delete();
        if ($n > 0) {
            $this->counts[$table] = ($this->counts[$table] ?? 0) + $n;
        }
    }
}
