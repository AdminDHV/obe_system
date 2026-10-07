<?php

namespace App\Services\OBE;

use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CourseClass;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Models\StudentRubricScore;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CloCalculationService
{
    /**
     * Compute CLO scores for one student in one course class (docs/cach_tinh_CLO_PI_PLO.md).
     */
    public function calculateForStudent(Student $student, CourseClass $courseClass): array
    {
        $syllabusId = $courseClass->syllabus_id;

        // Vắng thi cuối kỳ, chưa có điểm thi lại ⇒ đứng ngoài cỡ mẫu của môn ở lớp này. Không tính CLO
        // từ riêng điểm quá trình: như thế học viên vẫn bị đếm vào mẫu với một nửa bằng chứng.
        if (isset(StudentGrade::vangThiCuoiKy([$student->id], [$courseClass->id])[$courseClass->id])) {
            StudentCloResult::where('student_id', $student->id)->where('class_id', $courseClass->id)->delete();

            return [];
        }

        $clos = Clo::where('syllabus_id', $syllabusId)->get();

        $grades = StudentGrade::where('student_id', $student->id)
            ->where('class_id', $courseClass->id)
            ->whereNotNull('score')
            ->get();

        $assessments = Assessment::where('syllabus_id', $syllabusId)
            ->with(['cloMappings', 'rubric.criteria'])
            ->get();

        // Question-level (rubric) scores take priority as the CLO measurement source.
        $rubricScores = $this->rubricScoresFor($student, $courseClass, $assessments);

        $rows = [];
        $staleCloIds = [];

        foreach ($clos as $clo) {
            $cloScore = $this->calculateSingleClo($clo, $grades, $assessments, $rubricScores);

            if ($cloScore !== null) {
                $rows[] = [
                    'student_id' => $student->id,
                    'class_id' => $courseClass->id,
                    'clo_id' => $clo->id,
                    'achieved_score' => $cloScore,
                    'uuid' => (string) Str::uuid(),
                ];
            } else {
                // No data left for this CLO (grades deleted) → clear the stale result.
                $staleCloIds[] = $clo->id;
            }
        }

        // Duplicates are prevented by the DB unique key (student, class, CLO), not by upsert.
        // `uuid` must be passed explicitly because upsert fires no model events.
        if ($rows !== []) {
            StudentCloResult::upsert($rows, ['student_id', 'class_id', 'clo_id'], ['achieved_score']);
        }

        if ($staleCloIds !== []) {
            StudentCloResult::where('student_id', $student->id)
                ->where('class_id', $courseClass->id)
                ->whereIn('clo_id', $staleCloIds)
                ->delete();
        }

        return $rows;
    }

    /**
     * Legacy data (cohort <= 2024): assign the final course grade to every CLO.
     */
    public function calculateLegacyForStudent(Student $student, CourseClass $courseClass): array
    {
        $syllabusId = $courseClass->syllabus_id;
        $clos = Clo::where('syllabus_id', $syllabusId)->get();

        $legacyGrade = StudentGrade::where('student_id', $student->id)
            ->where('class_id', $courseClass->id)
            ->where('is_legacy_data', true)
            ->first();

        if (!$legacyGrade || $legacyGrade->score === null) {
            return [];
        }

        $rows = $clos->map(fn (Clo $clo) => [
            'student_id' => $student->id,
            'class_id' => $courseClass->id,
            'clo_id' => $clo->id,
            'achieved_score' => $legacyGrade->score,
            'uuid' => (string) Str::uuid(),
        ])->all();

        if ($rows !== []) {
            StudentCloResult::upsert($rows, ['student_id', 'class_id', 'clo_id'], ['achieved_score']);
        }

        return $rows;
    }

    /**
     * Question scores of (student, class) for this syllabus' rubric criteria, keyed by
     * `rubric_criteria_id`. Empty means the course is not question-scored.
     *
     * @return Collection<int, StudentRubricScore>
     */
    private function rubricScoresFor(Student $student, CourseClass $courseClass, Collection $assessments): Collection
    {
        $criteriaIds = $assessments
            ->flatMap(fn (Assessment $a) => $a->rubric?->criteria->pluck('id') ?? collect())
            ->all();

        if (empty($criteriaIds)) {
            return collect();
        }

        return StudentRubricScore::where('student_id', $student->id)
            ->where('class_id', $courseClass->id)
            ->whereIn('rubric_criteria_id', $criteriaIds)
            ->get()
            ->keyBy('rubric_criteria_id');
    }

    /**
     * Question-scored measurement — công thức chung ở `QuestionOutcomeScorer` (dùng chung với PI trong môn):
     * mỗi câu quy thang 10, trung bình trong bài, trung bình trong khối, gộp các khối có đo theo trọng số khối
     * của đề cương (dữ liệu cũ không đủ trọng số thì giữ quy tắc quá trình / cuối kỳ cũ).
     *
     * @return float|null null ⇒ no scored question for this CLO ⇒ caller falls back.
     */
    private function cloScoreFromRubric(Clo $clo, Collection $assessments, Collection $rubricScores): ?float
    {
        if ($rubricScores->isEmpty()) {
            return null;
        }

        return QuestionOutcomeScorer::score(
            $assessments,
            $rubricScores->map(fn ($s) => (float) $s->score)->all(),
            [(int) $clo->id],
        );
    }

    /**
     * Score one CLO. Question scores win; otherwise fall back to the assessment rule:
     * chỉ quá trình → trung bình cộng; có cả hai → gộp theo hệ số quá trình/cuối kỳ.
     */
    private function calculateSingleClo(Clo $clo, Collection $grades, Collection $assessments, Collection $rubricScores): ?float
    {
        $fromRubric = $this->cloScoreFromRubric($clo, $assessments, $rubricScores);
        if ($fromRubric !== null) {
            return $fromRubric;
        }

        $process = [];
        $final = [];

        foreach ($assessments as $assessment) {
            if (! $assessment->mappedCloIds()->contains($clo->id)) {
                continue;
            }

            $grade = $grades->firstWhere('assessment_id', $assessment->id);
            if (! $grade || $grade->score === null) {
                continue;
            }

            // Scores are already stored on the 0-10 scale.
            if ($assessment->assessment_category === 'final') {
                $final[] = (float) $grade->score;
            } else {
                $process[] = (float) $grade->score;
            }
        }

        return ObeScoreFormula::weightedFromCategories($process, $final);
    }
}
