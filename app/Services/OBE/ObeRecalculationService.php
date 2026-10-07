<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\CourseClass;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentRubricScore;
use App\Services\OBE\Strategies\CohortMeasurementResolver;
use App\Services\OBE\Strategies\StandardMeasurementStrategy;
use Illuminate\Support\Collection;

/**
 * Recomputes OBE scores, delegating to the cohort's strategy via CohortMeasurementResolver.
 *
 * Measurement runs in two phases (see CohortMeasurementStrategy):
 *   phase 1 — CLO per (student, class);  phase 2 — PI/PLO once per student.
 *
 * Shared by the real-time listener and the obe:recalculate command.
 */
class ObeRecalculationService
{
    public function __construct(
        private CohortMeasurementResolver $resolver,
        private StandardMeasurementStrategy $standard,
    ) {}

    /** @var array<int,?string> program_id → bậc đào tạo (tra một lần, dùng lại cho mọi sinh viên cùng ngành) */
    private array $levelCache = [];

    /** Bậc đào tạo của ngành sinh viên đang học — quyết định ngưỡng đạt theo quy chế. */
    private function levelOf(Student $student): ?string
    {
        if (! $student->program_id) {
            return null;
        }

        return $this->levelCache[(int) $student->program_id] ??= \App\Models\Program::whereKey($student->program_id)->value('level');
    }

    /**
     * Full measurement of one (student, class): CLO then PI/PLO aggregation, for the
     * real-time path where a single class just received grades.
     */
    public function recalculateStudentInClass(Student $student, CourseClass $courseClass): void
    {
        // Tham số đo (ngưỡng, mức I/R/M/A) có thể khai riêng theo trường ⇒ đặt ngữ cảnh ở đây,
        // là ĐIỂM VÀO, thay vì truyền school_id xuống hàng chục chỗ gọi ObeConfig::.
        ObeConfig::forProgram($student->school_id, $this->levelOf($student), function () use ($student, $courseClass) {
            $courseClass->loadMissing('cohort');
            $strategy = $this->resolver->for($courseClass);

            $strategy->measureClo($student, $courseClass);
            $strategy->aggregateStudent($student);
        });
    }

    /**
     * Recompute every (student, class) pair with grades in a school/program/cohort scope.
     * Returns the number of pairs processed; $progress() is called after each pair.
     * $classId limits the run to one class (per-class fan-out).
     * $cloOnly runs phase 1 only — phase 2 is then run once per student by the caller.
     */
    public function recalculateScope(
        ?int $schoolId,
        ?int $programId,
        ?int $cohortId,
        ?callable $progress = null,
        ?int $classId = null,
        bool $cloOnly = false,
    ): int {
        $pairs = $this->buildPairs($schoolId, $programId, $cohortId, $classId);

        $students = [];   // id → Student (loaded once, reused across that student's classes)
        $classes = [];    // id → CourseClass
        $strategies = []; // class_id → strategy
        $toAggregate = []; // id → Student needing PI/PLO aggregation

        $count = 0;
        foreach ($pairs as $pair) {
            $student = $students[$pair->student_id] ??= Student::find($pair->student_id);
            $courseClass = $classes[$pair->class_id] ??= CourseClass::with('cohort')->find($pair->class_id);

            if ($student && $courseClass) {
                // Ngữ cảnh cấu hình lấy theo TRƯỜNG CỦA SINH VIÊN, không theo tham số $schoolId.
                //
                // Trước đây cả vòng lặp bọc trong `forSchool($schoolId, ...)`, nên gọi
                // `obe:recalculate --program=17` (không kèm --school) thì $schoolId = null và
                // TOÀN BỘ hệ số riêng của trường bị bỏ qua — chạy bằng mặc định 0.4/0.6 mà không
                // báo gì. Trường khai 40/50 sẽ ra số sai lặng lẽ, đúng loại lỗi khó phát hiện
                // nhất vì kết quả vẫn "trông hợp lý". Phạm vi trải nhiều trường thì bản cũ còn
                // sai chắc chắn, vì một ngữ cảnh không thể đúng cho mọi trường.
                //
                // forSchool() chỉ hoán đổi một biến static và ObeConfig cache theo từng trường,
                // nên gọi mỗi cặp không tốn thêm truy vấn.
                $needsAggregation = ObeConfig::forProgram(
                    $student->school_id,
                    $this->levelOf($student),
                    function () use ($student, $courseClass, $pair, &$strategies) {
                        $strategy = $strategies[$pair->class_id] ??= $this->resolver->for($courseClass);
                        $strategy->measureClo($student, $courseClass);

                        return $strategy->needsAggregation();
                    },
                );

                if ($needsAggregation) {
                    $toAggregate[$student->id] = $student;
                }

                $count++;
            }

            if ($progress) {
                $progress();
            }
        }

        if (! $cloOnly) {
            foreach ($toAggregate as $student) {
                ObeConfig::forProgram($student->school_id, $this->levelOf($student), fn () => $this->standard->aggregateStudent($student));
            }
        }

        return $count;
    }

    /**
     * Phase 2 on its own: aggregate PI/PLO for a known set of students. Only strategy 2/3
     * has this phase, so the standard strategy is used directly.
     */
    public function aggregateStudents(array $studentIds): int
    {
        $count = 0;

        foreach (Student::whereIn('id', $studentIds)->cursor() as $student) {
            ObeConfig::forProgram($student->school_id, $this->levelOf($student), fn () => $this->standard->aggregateStudent($student));
            $count++;
        }

        return $count;
    }

    /**
     * course_class_ids in scope, used to fan out one small job per class.
     */
    public function classIdsInScope(?int $schoolId, ?int $programId, ?int $cohortId): array
    {
        return $this->buildPairs($schoolId, $programId, $cohortId)
            ->pluck('class_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Students in scope that need PI/PLO aggregation (excludes strategy-1-only students).
     * Used to run phase 2 once every CLO job has finished.
     *
     * @return array<int,int>
     */
    public function studentIdsNeedingAggregation(?int $schoolId, ?int $programId, ?int $cohortId): array
    {
        $pairs = $this->buildPairs($schoolId, $programId, $cohortId);

        $classes = CourseClass::with('cohort')
            ->whereIn('id', $pairs->pluck('class_id')->unique())
            ->get()
            ->keyBy('id');

        $aggregating = [];
        foreach ($pairs as $pair) {
            $courseClass = $classes->get($pair->class_id);
            if ($courseClass && $this->resolver->for($courseClass)->needsAggregation()) {
                $aggregating[(int) $pair->student_id] = true;
            }
        }

        return array_keys($aggregating);
    }

    /**
     * Number of (student, class) pairs in scope, for recording a MeasurementRun up front.
     */
    public function countScopePairs(?int $schoolId, ?int $programId, ?int $cohortId): int
    {
        return $this->buildPairs($schoolId, $programId, $cohortId)->count();
    }

    /**
     * (student, class) pairs to measure = those with assessment grades OR question scores.
     * Question-scored courses may have no student_grades rows at all, so both sources are
     * merged and made unique per pair.
     */
    private function buildPairs(?int $schoolId, ?int $programId, ?int $cohortId, ?int $classId = null): Collection
    {
        $studentIds = Student::query()
            ->when($schoolId, fn ($q) => $q->where('school_id', $schoolId))
            ->when($cohortId, fn ($q) => $q->where('cohort_id', $cohortId))
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->pluck('id');

        $gradePairs = StudentGrade::whereIn('student_id', $studentIds)
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->select('student_id', 'class_id')
            ->distinct()
            ->get();

        $rubricPairs = StudentRubricScore::whereIn('student_id', $studentIds)
            ->whereNotNull('class_id')
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->select('student_id', 'class_id')
            ->distinct()
            ->get();

        return $gradePairs->concat($rubricPairs)
            ->unique(fn ($p) => $p->student_id . '-' . $p->class_id);
    }
}
