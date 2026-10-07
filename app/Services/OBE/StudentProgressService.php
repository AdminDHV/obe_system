<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Program;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Credit accumulation progress of students — "how far along is this student?", as opposed to
 * "how well does this student attain the outcomes?" (ObeAttainmentService).
 *
 * Rules:
 *   - Course mark = Σ(score × weight) / Σ weight.
 *   - Failed courses are still measured but earn no credits (≥ `obe.course_pass_threshold`).
 *   - A student counts in cohort statistics at ≥ `obe.measurement_min_completion_percent`;
 *     a program with no `required_credits` filters nobody out.
 */
class StudentProgressService
{
    /**
     * Progress of every student in a program (optionally narrowed to one cohort).
     *
     * @return array<int, array{
     *   student_id:int, student_code:string, full_name:string,
     *   courses_graded:int, courses_passed:int,
     *   credits_attempted:float, credits_earned:float,
     *   required_credits:int|null, completion_percent:float|null, is_eligible:bool
     * }>  keyed by student_id
     */
    public function forProgram(int $programId, ?int $cohortId = null): array
    {
        $program = Program::find($programId);
        $required = $program?->required_credits;
        // Ngưỡng qua môn theo bậc đào tạo của ngành (đại học 4,0 · thạc sĩ 5,5) nếu trường chưa khai riêng.
        [$passMark, $minPercent] = ObeConfig::forProgram($program?->school_id, $program?->level, fn () => [
            ObeConfig::coursePassThreshold(),
            ObeConfig::measurementMinCompletionPercent(),
        ]);

        $students = Student::where('program_id', $programId)
            ->when($cohortId, fn ($q) => $q->where('cohort_id', $cohortId))
            ->orderBy('student_code')
            ->get(['id', 'student_code', 'full_name']);

        $grades = $this->courseGrades($programId, $cohortId);

        $out = [];
        foreach ($students as $student) {
            $out[$student->id] = $this->summarise(
                $student, $grades[$student->id] ?? [], $required, $passMark, $minPercent,
            );
        }

        return $out;
    }

    /**
     * Progress of a single student — same numbers as forProgram(), without scanning the
     * whole program to serve one row.
     *
     * @return array{
     *   student_id:int, student_code:string, full_name:string,
     *   courses_graded:int, courses_passed:int,
     *   credits_attempted:float, credits_earned:float,
     *   required_credits:int|null, completion_percent:float|null, is_eligible:bool
     * }
     */
    public function forStudent(Student $student): array
    {
        $program = $student->program_id ? Program::find($student->program_id) : null;

        return ObeConfig::forProgram($program?->school_id, $program?->level, fn () => $this->summarise(
            $student,
            $this->courseGrades($student->program_id, null, $student->id)[$student->id] ?? [],
            $program?->required_credits,
            ObeConfig::coursePassThreshold(),
            ObeConfig::measurementMinCompletionPercent(),
        ));
    }

    /**
     * Single place where course rows become a progress row, so the per-student sheet and the
     * cohort report can never disagree.
     *
     * @param  array<int, array{credits:float, score:float|null}>  $rows
     */
    private function summarise(
        Student $student,
        array $rows,
        ?int $required,
        float $passMark,
        float $minPercent,
    ): array {
        $attempted = 0.0;
        $earned = 0.0;
        $passed = 0;

        foreach ($rows as $row) {
            $attempted += $row['credits'];
            if ($row['score'] !== null && $row['score'] >= $passMark) {
                $earned += $row['credits'];
                $passed++;
            }
        }

        $percent = $required > 0 ? round($earned / $required * 100, 1) : null;

        return [
            'student_id' => $student->id,
            'student_code' => $student->student_code,
            'full_name' => $student->full_name,
            'courses_graded' => count($rows),
            'courses_passed' => $passed,
            'credits_attempted' => $attempted,
            'credits_earned' => $earned,
            'required_credits' => $required,
            'completion_percent' => $percent,
            // No required_credits declared ⇒ no basis to exclude anyone ⇒ eligible.
            'is_eligible' => $percent === null || $percent >= $minPercent,
            'pass_mark' => $passMark,
            'min_completion_percent' => $minPercent,
        ];
    }

    /**
     * IDs of the students counted in the cohort's attainment statistics.
     *
     * @return array<int,int>
     */
    public function eligibleStudentIds(int $programId, ?int $cohortId = null): array
    {
        return array_values(array_map(
            fn ($r) => $r['student_id'],
            array_filter($this->forProgram($programId, $cohortId), fn ($r) => $r['is_eligible']),
        ));
    }

    /**
     * Summary shown at the top of reports and screens.
     *
     * @return array{required_credits:int|null, min_completion_percent:float, pass_mark:float,
     *               students_total:int, students_eligible:int, students_excluded:int,
     *               excluded:array<int, array<string,mixed>>}
     */
    public function summary(int $programId, ?int $cohortId = null): array
    {
        $rows = $this->forProgram($programId, $cohortId);
        $excluded = array_values(array_filter($rows, fn ($r) => ! $r['is_eligible']));

        return [
            'required_credits' => Program::find($programId)?->required_credits,
            'min_completion_percent' => ObeConfig::measurementMinCompletionPercent(),
            'pass_mark' => ObeConfig::coursePassThreshold(),
            'students_total' => count($rows),
            'students_eligible' => count($rows) - count($excluded),
            'students_excluded' => count($excluded),
            'excluded' => $excluded,
        ];
    }

    /**
     * Per-course marks of each student, using the same formula as the AUN-QA course-mark
     * sheet so the two can never diverge. Retakes count ONCE — the latest attempt.
     *
     * @return array<int, array<int, array{credits:float, score:float|null}>>  student_id → courses
     */
    private function courseGrades(?int $programId, ?int $cohortId, ?int $studentId = null): array
    {
        // Điểm tổng kết môn theo quy tắc CỤM của Trường (xem CourseGradeFormula): trung bình các
        // bài trong cụm trước, rồi mới nhân trọng số CẢ CỤM. Làm 2 tầng trong SQL thay vì kéo từng
        // dòng điểm về PHP — hàm này chạy cho cả một ngành/khóa.
        //
        // Trọng số cụm lấy từ bảng `assessments` (không phụ thuộc sinh viên), nên sinh viên thiếu
        // một bài giữa kỳ vẫn thấy cụm giữa kỳ đủ 40%, không bị đẩy sang cuối kỳ.
        //
        // Bài BỎ TRỐNG trọng số được bù bằng phần trăm còn lại chia đều — cùng quy tắc với
        // `CourseGradeFormula::resolveWeights()`, xem giải thích đầy đủ ở đó. Làm bằng SQL vì hàm
        // này chạy cho cả một ngành, không kéo từng dòng điểm về PHP được.
        // Chỉ dùng CASE/SUM thuần: test chạy SQLite còn production chạy PostgreSQL, nên tránh
        // `FILTER`/`GREATEST` vốn chỉ có ở một bên.
        $tongTheoDeCuong = DB::table('assessments')
            ->whereNull('deleted_at')
            ->groupBy('syllabus_id')
            ->selectRaw('syllabus_id,
                sum(case when weight_percent > 0 then weight_percent else 0 end) as da_khai,
                sum(case when weight_percent > 0 then 0 else 1 end) as so_trong');

        $trongSoThuc = DB::table('assessments as a')
            ->joinSub($tongTheoDeCuong, 't', 't.syllabus_id', '=', 'a.syllabus_id')
            ->whereNull('a.deleted_at')
            ->selectRaw('a.id, a.syllabus_id, a.assessment_category,
                case
                    when a.weight_percent > 0 then a.weight_percent
                    when t.so_trong > 0 and t.da_khai < 100
                        then (100 - t.da_khai) / t.so_trong
                    else 0
                end as w');

        $groupWeights = DB::query()
            ->fromSub($trongSoThuc, 'e')
            ->groupBy('e.syllabus_id', 'e.assessment_category', 'e.w')
            ->selectRaw('e.syllabus_id, e.assessment_category, e.w as weight_percent,
                sum(e.w) as group_weight');

        $perGroup = DB::table('student_grades as g')
            ->join('students as s', 'g.student_id', '=', 's.id')
            ->joinSub($trongSoThuc, 'a', 'a.id', '=', 'g.assessment_id')
            ->join('course_classes as cc', 'g.class_id', '=', 'cc.id')
            ->join('course_syllabus as cs', 'cc.syllabus_id', '=', 'cs.id')
            ->join('courses as c', 'cs.course_id', '=', 'c.id')
            ->when($programId, fn ($q) => $q->where('s.program_id', $programId))
            ->when($cohortId, fn ($q) => $q->where('s.cohort_id', $cohortId))
            ->when($studentId, fn ($q) => $q->where('s.id', $studentId))
            ->whereNotNull('g.score')
            ->groupBy('s.id', 'cc.id', 'cs.id', 'cs.course_id', 'c.credits',
                'cc.academic_year', 'cc.semester', 'a.assessment_category', 'a.w')
            ->selectRaw('s.id as student_id, cc.id as class_id, cs.id as syllabus_id,
                cs.course_id, c.credits, cc.academic_year, cc.semester,
                a.assessment_category, a.w as weight_percent, avg(g.score) as group_avg');

        $rows = DB::query()
            ->fromSub($perGroup, 'p')
            ->joinSub($groupWeights, 'w', fn ($join) => $join
                ->on('w.syllabus_id', '=', 'p.syllabus_id')
                ->on('w.assessment_category', '=', 'p.assessment_category')
                ->on('w.weight_percent', '=', 'p.weight_percent'))
            ->groupBy('p.student_id', 'p.class_id', 'p.course_id', 'p.credits',
                'p.academic_year', 'p.semester')
            ->selectRaw('p.student_id, p.class_id, p.course_id, p.credits,
                p.academic_year, p.semester,
                sum(w.group_weight * p.group_avg) as num, sum(w.group_weight) as den')
            ->get();

        // Học lại ⇒ một MÔN có nhiều lớp. Chỉ lấy LẦN HỌC GẦN NHẤT, cùng quy tắc với CoursePiScorer:
        // nếu đếm cả hai lần thì tín chỉ đã học (và có thể cả tích lũy) bị nhân đôi.
        $latest = [];
        foreach ($rows as $row) {
            $key = $row->student_id . '|' . $row->course_id;
            $rank = [(string) $row->academic_year, (int) $row->semester, (int) $row->class_id];

            if (! isset($latest[$key]) || $rank > $latest[$key]['rank']) {
                $den = (float) $row->den;
                $latest[$key] = [
                    'rank' => $rank,
                    'student_id' => (int) $row->student_id,
                    'credits' => (float) $row->credits,
                    'score' => $den > 0 ? round((float) $row->num / $den, 2) : null,
                ];
            }
        }

        $out = [];
        foreach ($latest as $row) {
            $out[$row['student_id']][] = [
                'credits' => $row['credits'],
                'score' => $row['score'],
            ];
        }

        return $out;
    }
}
