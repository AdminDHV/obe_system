<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Enums\CohortStrategy;
use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\CourseClass;
use App\Models\Plo;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentGrade;

/**
 * Read-only preflight check that a (program, cohort) has the structure needed before grades
 * are imported and measurement runs. Strategy is detected from cohort.start_year:
 *   - Strategy 1 (≤2024, legacy): CLO + one "carrier" assessment.
 *   - Strategy 2 (2025): CLO + assessment mapped to CLO + PLO, CLO linked straight to the PLO.
 *   - Strategy 3 (≥2026): same as 2 but CLOs link to the PLO's PIs.
 */
class ObeReadinessService
{
    /**
     * @return array<string,mixed>
     */
    public function check(int $programId, int $cohortId): array
    {
        $program = Program::select('id', 'code', 'name')->findOrFail($programId);
        $cohort = Cohort::select('id', 'name', 'start_year')->findOrFail($cohortId);

        $startYear = (int) $cohort->start_year;
        $strategyEnum = CohortStrategy::fromStartYear($startYear);
        $strategy = $strategyEnum->code();
        $strategyLabel = $strategyEnum->label();

        // Scope: the classes of this cohort and their syllabi.
        $classes = CourseClass::where('cohort_id', $cohortId)->get(['id', 'syllabus_id', 'class_code']);
        $classIds = $classes->pluck('id');
        $syllabusIds = $classes->pluck('syllabus_id')->filter()->unique()->values();

        $checks = [];

        // Shared check: the cohort has classes.
        $checks[] = $this->row(
            'classes',
            'Khóa đã có lớp học phần',
            $classes->isNotEmpty(),
            $classes->isNotEmpty()
                ? "{$classes->count()} lớp"
                : 'Khóa này chưa có lớp nào',
            'Tạo lớp tại /manager/classes (gán đúng khóa + syllabus + giảng viên).',
            '/manager/classes',
        );

        // Shared check: every class syllabus has CLOs.
        $clos = Clo::whereIn('syllabus_id', $syllabusIds)->get(['id', 'syllabus_id']);
        $cloIds = $clos->pluck('id');
        $syllabiWithClo = $clos->pluck('syllabus_id')->unique();
        $syllabiMissingClo = $syllabusIds->diff($syllabiWithClo);
        $checks[] = $this->row(
            'clos',
            'Mỗi môn (syllabus) đã có CLO',
            $syllabusIds->isNotEmpty() && $syllabiMissingClo->isEmpty(),
            $syllabusIds->isEmpty()
                ? 'Chưa có syllabus nào (do chưa có lớp)'
                : ($syllabiMissingClo->isEmpty()
                    ? "{$clos->count()} CLO trên {$syllabusIds->count()} môn"
                    : "{$syllabiMissingClo->count()}/{$syllabusIds->count()} môn chưa có CLO"),
            'Thêm CLO tại /manager/obe (tab CLO) cho từng syllabus.',
            '/manager/obe',
        );

        if ($strategy === 1) {
            // Strategy 1: each syllabus needs ≥1 assessment to carry the final course grade.
            $assessSyllabusIds = Assessment::whereIn('syllabus_id', $syllabusIds)
                ->pluck('syllabus_id')->unique();
            $syllabiMissingAssess = $syllabusIds->diff($assessSyllabusIds);
            $checks[] = $this->row(
                'legacy_carrier',
                'Mỗi môn có bài đánh giá để chứa điểm tổng kết',
                $syllabusIds->isNotEmpty() && $syllabiMissingAssess->isEmpty(),
                $syllabusIds->isEmpty()
                    ? 'Chưa có syllabus nào'
                    : ($syllabiMissingAssess->isEmpty()
                        ? 'Đủ bài đánh giá carrier'
                        : "{$syllabiMissingAssess->count()}/{$syllabusIds->count()} môn chưa có bài đánh giá"),
                'Khóa cũ chỉ cần 1 bài đánh giá (vd "Điểm tổng kết") mỗi môn để nhận điểm — tạo tại /manager/assessments.',
                '/manager/assessments',
            );
        } else {
            // Strategy 2/3: need PLO → PI plus the CLO→PI and assessment→CLO mappings.
            $plos = Plo::where('program_id', $programId)->with('pis:id,plo_id,is_dummy')->get(['id', 'code']);

            $checks[] = $this->row(
                'plos',
                'Ngành đã có PLO',
                $plos->isNotEmpty(),
                $plos->isNotEmpty() ? "{$plos->count()} PLO" : 'Ngành chưa có PLO nào',
                'Khai PLO ở sheet PLO_PI của file khai báo chương trình.',
                '/manager/obe',
            );

            $plosWithoutPi = $plos->filter(fn ($p) => $p->pis->isEmpty());
            $checks[] = $this->row(
                'pis',
                'Mỗi PLO có PI hoặc được CLO nối thẳng',
                $plos->isNotEmpty() && $plosWithoutPi->isEmpty(),
                $plos->isEmpty()
                    ? 'Chưa có PLO'
                    : ($plosWithoutPi->isEmpty()
                        ? 'Tất cả PLO đều có PI'
                        : "{$plosWithoutPi->count()}/{$plos->count()} PLO chưa có PI: " . $plosWithoutPi->pluck('code')->implode(', ')),
                'Chia PI cho PLO ở sheet PLO_PI của file khai báo chương trình, hoặc nối CLO thẳng vào PLO ở ma trận chuẩn đầu ra của phiếu lớp học phần.',
                '/manager/obe',
            );

            // assessment → CLO, via assessment_clo_mappings or rubric criteria.
            $assessments = Assessment::whereIn('syllabus_id', $syllabusIds)
                ->with(['cloMappings:id,assessment_id,clo_id', 'rubric.criteria:id,rubric_id,clo_id'])
                ->get();
            $mappedAssessments = $assessments->filter(fn ($a) => $a->mappedCloIds()->isNotEmpty());
            $checks[] = $this->row(
                'assessment_clo',
                'Bài đánh giá đã gắn với CLO',
                $mappedAssessments->isNotEmpty(),
                $assessments->isEmpty()
                    ? 'Chưa có bài đánh giá nào'
                    : "{$mappedAssessments->count()}/{$assessments->count()} bài đánh giá có gắn CLO",
                'Gắn CLO cho bài đánh giá tại /manager/assessments (hoặc dùng rubric có tiêu chí gắn CLO).',
                '/manager/assessments',
            );

            // CLO → PI mapping.
            $mappingCount = $cloIds->isEmpty() ? 0 : CloPiMapping::whereIn('clo_id', $cloIds)->count();
            $checks[] = $this->row(
                'clo_pi',
                'Đã có ánh xạ CLO → PI',
                $mappingCount > 0,
                $mappingCount > 0 ? "{$mappingCount} ánh xạ CLO→PI" : 'Chưa có ánh xạ CLO→PI nào',
                'Tạo ánh xạ tại /manager/obe (tab Mapping): nối CLO của môn với PI của PLO.',
                '/manager/obe',
            );
        }

        // Quick counters.
        $studentsInCohort = Student::where('cohort_id', $cohortId)->count();
        $studentsWithGrades = $classIds->isEmpty()
            ? 0
            : StudentGrade::whereIn('class_id', $classIds)->distinct('student_id')->count('student_id');

        $ready = collect($checks)->where('severity', 'error')->every(fn ($c) => $c['ok'] === true);

        return [
            'strategy' => $strategy,
            'strategy_label' => $strategyLabel,
            'start_year' => $startYear,
            'program' => ['code' => $program->code, 'name' => $program->name],
            'cohort' => ['name' => $cohort->name, 'start_year' => $startYear],
            'ready' => $ready,
            'checks' => $checks,
            'counts' => [
                'classes' => $classes->count(),
                'students_in_cohort' => $studentsInCohort,
                'students_with_grades' => $studentsWithGrades,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $key, string $label, bool $ok, string $detail, string $hint, ?string $link = null, string $severity = 'error'): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'detail' => $detail,
            'hint' => $ok ? null : $hint,
            'link' => $ok ? null : $link,
            'severity' => $severity,
        ];
    }
}
