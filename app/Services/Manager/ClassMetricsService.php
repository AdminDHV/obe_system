<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\CourseClass;
use App\Services\OBE\ObeConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes per-class performance metrics (weighted averages, grade distribution,
 * CLO achievement). Extracted from ClassController@show to keep the controller thin
 * per the project's "no fat controllers" rule.
 */
class ClassMetricsService
{
    /**
     * Build per-student performance rows: weighted average across assessments,
     * pass flag, per-assessment grades, and CLO scores.
     *
     * @param  Collection  $students     Students enrolled in the class.
     * @param  Collection  $grades       StudentGrade rows grouped by student_id.
     * @param  Collection  $cloResults   CLO result rows grouped by student_id.
     */
    public function studentPerformance(CourseClass $courseClass, Collection $students, Collection $grades, Collection $cloResults): Collection
    {
        $assessments = $courseClass->syllabus->assessments;

        return $students->map(function ($student) use ($grades, $cloResults, $assessments) {
            $studentGrades = $grades->get($student->id, collect());

            $totalWeight = 0;
            $weightedSum = 0;
            foreach ($assessments as $assessment) {
                $grade = $studentGrades->firstWhere('assessment_id', $assessment->id);
                if ($grade?->score !== null) {
                    $weight = (float) $assessment->weight_percent;
                    $totalWeight += $weight;
                    $weightedSum += (float) $grade->score * $weight;
                }
            }
            $avgScore = $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : null;

            $assessmentGrades = $assessments->map(function ($assessment) use ($studentGrades) {
                $grade = $studentGrades->firstWhere('assessment_id', $assessment->id);
                return [
                    'assessment_id' => $assessment->id,
                    'assessment_name' => $assessment->name,
                    'score' => $grade?->score !== null ? round((float) $grade->score, 2) : null,
                ];
            });

            $studentClo = $cloResults->get($student->id, collect());
            $cloScores = $studentClo->map(fn($r) => [
                'code' => $r->clo_code,
                'description' => $r->clo_description,
                'score' => round((float) $r->achieved_score, 2),
            ]);

            return [
                'id' => $student->id,
                'uuid' => $student->uuid,
                'student_code' => $student->student_code,
                'full_name' => $student->full_name,
                'avg_score' => $avgScore,
                'weighted_avg_score' => $avgScore,
                // Ngưỡng đạt môn lấy từ `obe.course_pass_threshold`, KHÔNG hardcode 4.0: màn hình
                // này từng ghi "Đạt" ở 4.0 trong khi bảng mức đạt PI ngay bên dưới (ngưỡng 5) và
                // bảng tín chỉ tích lũy (5.5) lại tính là trượt.
                'passed' => $avgScore !== null && $avgScore >= ObeConfig::coursePassThreshold(),
                'assessment_grades' => $assessmentGrades,
                'clo_scores' => $cloScores,
            ];
        });
    }

    /**
     * Grade distribution bucketed into 5 ranges (0-2, 2-4, 4-6, 6-8, 8-10).
     *
     * @return array<int, array{range: string, count: int}>
     */
    public function gradeDistribution(int $classId): array
    {
        $distCounts = array_fill(0, 5, 0);
        $rawDistribution = DB::table('student_grades')
            ->where('class_id', $classId)
            ->whereNotNull('score')
            ->select(
                DB::raw("CASE
                    WHEN score < 2 THEN 0
                    WHEN score < 4 THEN 1
                    WHEN score < 6 THEN 2
                    WHEN score < 8 THEN 3
                    ELSE 4
                END as bucket"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('bucket')
            ->get();

        foreach ($rawDistribution as $row) {
            $distCounts[(int) $row->bucket] = (int) $row->count;
        }

        return [
            ['range' => '0-2', 'count' => $distCounts[0]],
            ['range' => '2-4', 'count' => $distCounts[1]],
            ['range' => '4-6', 'count' => $distCounts[2]],
            ['range' => '6-8', 'count' => $distCounts[3]],
            ['range' => '8-10', 'count' => $distCounts[4]],
        ];
    }

    /** Average achieved score per CLO across the class. */
    public function cloAchievement(int $classId): Collection
    {
        return DB::table('student_clo_results')
            ->where('class_id', $classId)
            ->join('clos', 'student_clo_results.clo_id', '=', 'clos.id')
            ->select('clos.code', 'clos.description', DB::raw('AVG(achieved_score) as avg_score'))
            ->groupBy('clos.code', 'clos.description')
            ->orderBy('clos.code')
            ->get()
            ->map(fn($r) => [
                'code' => $r->code,
                'description' => $r->description,
                'avg_score' => round((float) $r->avg_score, 2),
            ]);
    }
}
