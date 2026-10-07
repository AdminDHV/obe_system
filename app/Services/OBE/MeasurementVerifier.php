<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\CohortPiWeight;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseOutcomeLevel;
use App\Models\CourseSyllabus;
use App\Models\MappingScaleWeight;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Rubric;
use App\Models\RubricCriteria;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Models\StudentRubricScore;
use Illuminate\Support\Collection;

/**
 * KIỂM ĐỐI CHIẾU số đo đã lưu với một phép tính lại ĐỘC LẬP theo đúng quy tắc trong
 * `docs/cach_tinh_CLO_PI_PLO.md`.
 *
 * Không gọi engine đo (CloCalculationService, CoursePiScorer, WeightedAttainmentMethod…) — tính lại
 * thẳng từ dữ liệu thô. Engine sai, hoặc số đã lưu cũ (quên tính lại sau khi sửa điểm / ma trận),
 * thì hai bên lệch nhau và lệnh báo đúng sinh viên, đúng chỉ số.
 *
 * Mỗi tầng kiểm RIÊNG, lấy đầu vào là số đã lưu của tầng ngay dưới — lệch ở tầng nào báo đúng tầng đó:
 *   CLO  ← điểm từng câu (student_rubric_scores): mỗi câu quy thang 10, TB trong bài, TB trong khối
 *          (chuyên cần · giữa kỳ · cuối kỳ), gộp các khối có đo theo trọng số khối của đề cương (tổng bài
 *          = 100%); đề cương cũ không đủ trọng số ⇒ mỗi bài Σđạt/Σtối đa×10, gộp quá trình/cuối kỳ theo hệ số trường
 *   PI   ← điểm từng câu: điểm PI trong môn tính như CLO trên tập câu của mọi CLO nối PI (lần học gần nhất);
 *          PI = Σ(TC×mức×điểm)/Σ(TC×mức×10),
 *          mức = ô của môn trong ma trận CTĐT của khóa (File 1); môn chưa có trong ma trận thì lấy mức
 *          nặng nhất trong các CLO của môn nối vào PI đó
 *   PLO  ← PI đã lưu: Σ(PI×trọng số)/Σ trọng số, trọng số trống ⇒ 1, trọng số riêng của khóa được ưu tiên
 *
 * Chỉ kiểm chỗ chắc chắn tính lại được theo quy tắc trên, phần còn lại ĐẾM là bỏ qua chứ không coi là
 * khớp: môn chấm theo điểm bài (không có điểm câu), trường dùng phương pháp đo cũ.
 */
final class MeasurementVerifier
{
    private const EPS = 0.0006; // kết quả lưu ở decimal(8,4)
    private const DEPTH = ['I' => 1, 'R' => 2, 'M' => 3, 'A' => 4];

    /**
     * @return array{school:string, students:int, clo_checked:int, pi_checked:int, plo_checked:int,
     *               skipped:array<string,int>, mismatches:array<int,string>}
     */
    public function verifySchool(int $schoolId): array
    {
        return ObeConfig::forSchool($schoolId, fn () => $this->run($schoolId));
    }

    private function run(int $schoolId): array
    {
        $school = School::withTrashed()->findOrFail($schoolId);
        $out = [
            'school' => $school->code,
            'students' => 0, 'clo_checked' => 0, 'pi_checked' => 0, 'plo_checked' => 0,
            'skipped' => [], 'mismatches' => [],
        ];

        if (($school->obe_method ?? 'weighted_attainment') !== 'weighted_attainment') {
            $out['skipped']['truong_dung_phuong_phap_cu'] = 1;

            return $out;
        }

        $processW = ObeConfig::processWeight();
        $finalW = ObeConfig::finalWeight();
        $intensity = collect(MappingScaleWeight::forSchool($schoolId))->all(); // ['I' => 1, 'R' => 2, 'M' => 3, 'A' => 3]

        $students = Student::where('school_id', $schoolId)->whereNotNull('program_id')->get();
        $structureCache = [];

        foreach ($students as $student) {
            $out['students']++;
            $code = $student->student_code;

            // ── Tầng CLO ─────────────────────────────────────────────────────────────────────
            $questionClasses = StudentRubricScore::where('student_id', $student->id)->distinct()->pluck('class_id')->filter();
            $storedClo = StudentCloResult::where('student_id', $student->id)->get(['class_id', 'clo_id', 'achieved_score']);

            $questionCtx = [];
            foreach (CourseClass::whereIn('id', $questionClasses)->get(['id', 'syllabus_id', 'class_code']) as $class) {
                $questionCtx[$class->id] = $this->loadQuestions($student->id, $class);
                $oracle = [];
                foreach (Clo::where('syllabus_id', $class->syllabus_id)->pluck('id') as $cloId) {
                    $v = $this->questionScore($questionCtx[$class->id], [(int) $cloId], $processW, $finalW);
                    if ($v !== null) {
                        $oracle[(int) $cloId] = $v;
                    }
                }
                $stored = $storedClo->where('class_id', $class->id)->keyBy('clo_id');

                foreach ($oracle as $cloId => $value) {
                    $row = $stored->get($cloId);
                    $label = "SV {$code} · lớp {$class->class_code} · " . (Clo::find($cloId)?->code ?? "CLO#{$cloId}");
                    if ($row === null) {
                        $out['mismatches'][] = "{$label}: tính lại được " . $this->f($value) . ' nhưng CHƯA có kết quả lưu (quên tính lại?)';
                    } elseif (abs((float) $row->achieved_score - $value) > self::EPS) {
                        $out['mismatches'][] = "{$label}: đã lưu " . $this->f((float) $row->achieved_score) . ', tính lại ' . $this->f($value);
                    }
                    $out['clo_checked']++;
                }
            }

            if (! $student->cohort_id) {
                $out['skipped']['sinh_vien_khong_co_khoa'] = ($out['skipped']['sinh_vien_khong_co_khoa'] ?? 0) + 1;
                continue;
            }

            // ── Tầng PI ──────────────────────────────────────────────────────────────────────
            $key = $student->program_id . '|' . $student->cohort_id;
            $structure = $structureCache[$key] ??= $this->structure((int) $student->program_id, (int) $student->cohort_id, $intensity);

            $latest = $this->latestClassBySyllabus($storedClo->pluck('class_id')->unique());
            $storedPi = StudentPiResult::where('student_id', $student->id)->pluck('achieved_score', 'pi_id');

            foreach ($structure['pis'] as $piId => $pi) {
                $num = 0.0;
                $den = 0.0;
                $verifiable = true;

                foreach ($pi['syllabi'] as $syllabusId => $s) {
                    $classId = $latest[$syllabusId] ?? null;
                    if ($classId === null) {
                        continue; // chưa học môn này
                    }
                    if (! $questionClasses->contains($classId)) {
                        $verifiable = false; // môn chấm theo điểm bài — không thuộc quy tắc kiểm ở đây
                        break;
                    }
                    $questionCtx[$classId] ??= $this->loadQuestions($student->id, CourseClass::findOrFail($classId));
                    $score = $this->questionScore($questionCtx[$classId], $s['clo_ids'], $processW, $finalW);
                    if ($score === null) {
                        continue;
                    }
                    $num += $s['credit'] * $s['weight'] * $score;
                    $den += $s['credit'] * $s['weight'] * 10;
                }

                if (! $verifiable) {
                    $out['skipped']['pi_co_mon_cham_theo_diem_bai'] = ($out['skipped']['pi_co_mon_cham_theo_diem_bai'] ?? 0) + 1;
                    continue;
                }

                $value = $den > 0 ? $num / $den : null;
                $stored = $storedPi->get($piId);
                $label = "SV {$code} · {$pi['code']}";

                if ($value === null && $stored !== null) {
                    $out['mismatches'][] = "{$label}: đã lưu " . $this->f((float) $stored) . ' nhưng tính lại KHÔNG có dữ liệu đo (kết quả cũ chưa gỡ?)';
                } elseif ($value !== null && $stored === null) {
                    $out['mismatches'][] = "{$label}: tính lại được " . $this->f($value) . ' nhưng CHƯA có kết quả lưu';
                } elseif ($value !== null && abs((float) $stored - $value) > self::EPS) {
                    $out['mismatches'][] = "{$label}: đã lưu " . $this->f((float) $stored) . ', tính lại ' . $this->f($value);
                }
                if ($value !== null || $stored !== null) {
                    $out['pi_checked']++;
                }
            }

            // ── Tầng PLO ─────────────────────────────────────────────────────────────────────
            $storedPlo = StudentPloResult::where('student_id', $student->id)->pluck('achieved_score', 'plo_id');
            foreach ($structure['plos'] as $ploId => $plo) {
                $num = 0.0;
                $den = 0.0;
                foreach ($plo['pi_ids'] as $piId) {
                    if (! $storedPi->has($piId)) {
                        continue;
                    }
                    $w = $structure['pis'][$piId]['weight'];
                    $w = ($w === null || $w <= 0) ? 1.0 : $w;
                    $num += (float) $storedPi[$piId] * $w;
                    $den += $w;
                }
                $value = $den > 0 ? $num / $den : null;
                $stored = $storedPlo->get($ploId);
                $label = "SV {$code} · {$plo['code']}";

                if ($value === null && $stored !== null) {
                    $out['mismatches'][] = "{$label}: đã lưu " . $this->f((float) $stored) . ' nhưng không PI nào có kết quả';
                } elseif ($value !== null && $stored === null) {
                    $out['mismatches'][] = "{$label}: tính lại được " . $this->f($value) . ' nhưng CHƯA có kết quả lưu';
                } elseif ($value !== null && abs((float) $stored - $value) > self::EPS) {
                    $out['mismatches'][] = "{$label}: đã lưu " . $this->f((float) $stored) . ', tính lại ' . $this->f($value);
                }
                if ($value !== null || $stored !== null) {
                    $out['plo_checked']++;
                }
            }
        }

        return $out;
    }

    /**
     * Điểm câu của một sinh viên trong một lớp + bài đánh giá của môn, nạp một lần cho mọi CLO và PI.
     *
     * @return array{scores: array<int,float>, criteria: \Illuminate\Support\Collection, block: array<int,string>,
     *               category: array<int,string>, blockWeight: ?array<string,float>}
     */
    private function loadQuestions(int $studentId, CourseClass $class): array
    {
        $scores = StudentRubricScore::where('student_id', $studentId)->where('class_id', $class->id)
            ->pluck('score', 'rubric_criteria_id')->map(fn ($v) => (float) $v)->all();
        $assessments = Assessment::where('syllabus_id', $class->syllabus_id)->get(['id', 'name', 'assessment_category', 'weight_percent']);
        $rubrics = Rubric::whereIn('assessment_id', $assessments->pluck('id'))->pluck('assessment_id', 'id');
        $criteria = RubricCriteria::whereIn('rubric_id', $rubrics->keys())->whereIn('id', array_keys($scores))
            ->get(['id', 'rubric_id', 'clo_id', 'max_score'])
            ->each(fn ($c) => $c->assessment_id = (int) $rubrics[$c->rubric_id]);

        // Khối: cuối kỳ theo loại bài; chuyên cần theo tên bài; còn lại là giữa kỳ.
        $block = [];
        $category = [];
        $weight = ['cc' => 0.0, 'gk' => 0.0, 'ck' => 0.0];
        $total = 0.0;
        $complete = $assessments->isNotEmpty();
        foreach ($assessments as $a) {
            $slug = \Illuminate\Support\Str::slug((string) $a->name, '_');
            $block[(int) $a->id] = $a->assessment_category === 'final' ? 'ck' : ((str_contains($slug, 'chuyen_can') || $slug === 'cc') ? 'cc' : 'gk');
            $category[(int) $a->id] = (string) $a->assessment_category;
            if ($a->weight_percent === null) {
                $complete = false;
                continue;
            }
            $weight[$block[(int) $a->id]] += (float) $a->weight_percent;
            $total += (float) $a->weight_percent;
        }

        return [
            'scores' => $scores,
            'criteria' => $criteria,
            'block' => $block,
            'category' => $category,
            'blockWeight' => ($complete && abs($total - 100) <= 0.01) ? $weight : null,
        ];
    }

    /**
     * Điểm thang 10 của một nhóm CLO (một CLO, hoặc các CLO của môn nối vào một PI) từ điểm câu.
     *
     * @param array<int,int> $cloIds
     */
    private function questionScore(array $ctx, array $cloIds, float $processW, float $finalW): ?float
    {
        $rows = $ctx['criteria']->filter(fn ($c) => in_array((int) $c->clo_id, $cloIds, true) && (float) $c->max_score > 0);
        if ($rows->isEmpty()) {
            return null;
        }

        if ($ctx['blockWeight'] !== null) {
            $blocks = [];
            foreach ($rows->groupBy('assessment_id') as $assessmentId => $inAssessment) {
                $blocks[$ctx['block'][$assessmentId]][] = $inAssessment->avg(fn ($c) => $ctx['scores'][$c->id] / (float) $c->max_score * 10);
            }
            $num = 0.0;
            $den = 0.0;
            $avgs = [];
            foreach ($blocks as $b => $values) {
                $avg = array_sum($values) / count($values);
                $avgs[] = $avg;
                $num += $ctx['blockWeight'][$b] * $avg;
                $den += $ctx['blockWeight'][$b];
            }

            return $den > 0 ? $num / $den : array_sum($avgs) / count($avgs);
        }

        $process = [];
        $final = [];
        foreach ($rows->groupBy('assessment_id') as $assessmentId => $inAssessment) {
            $value = $inAssessment->sum(fn ($c) => $ctx['scores'][$c->id]) / $inAssessment->sum(fn ($c) => (float) $c->max_score) * 10;
            $ctx['category'][$assessmentId] === 'final' ? $final[] = $value : $process[] = $value;
        }
        if ($final === []) {
            return array_sum($process) / count($process);
        }
        if ($process === []) {
            return array_sum($final) / count($final);
        }
        $p = array_sum($process) / count($process);
        $f = array_sum($final) / count($final);
        $t = $processW + $finalW;

        return $t > 0 ? ($p * $processW + $f * $finalW) / $t : ($p + $f) / 2;
    }

    /**
     * Khung đo của một ngành cho một khóa, dựng thẳng từ bảng khung.
     *
     * @return array{pis: array<int,array{code:string,weight:?float,syllabi:array<int,array{credit:float,weight:float,clo_ids:array<int,int>}>}>,
     *               plos: array<int,array{code:string,pi_ids:array<int,int>}>}
     */
    private function structure(int $programId, int $cohortId, array $intensity): array
    {
        $plos = Plo::where('program_id', $programId)->orderBy('id')->get(['id', 'code']);
        $pis = Pi::whereIn('plo_id', $plos->pluck('id'))->get(['id', 'plo_id', 'code', 'weight']);
        $cohortWeights = CohortPiWeight::where('cohort_id', $cohortId)->pluck('weight', 'pi_id');
        $mappings = CloPiMapping::whereIn('pi_id', $pis->pluck('id'))->get(['clo_id', 'pi_id', 'mapping_level']);
        $cloSyllabus = Clo::whereIn('id', $mappings->pluck('clo_id'))->pluck('syllabus_id', 'id');
        $syllabusCourse = CourseSyllabus::whereIn('id', $cloSyllabus->unique())->pluck('course_id', 'id');
        $credits = $syllabusCourse->map(fn ($courseId) => (float) (Course::find($courseId)?->credits ?? 0));
        $ctdt = CourseOutcomeLevel::forCohort($cohortId);

        $outPis = [];
        foreach ($pis as $pi) {
            $syllabi = [];
            foreach ($mappings->where('pi_id', $pi->id) as $m) {
                $sid = $cloSyllabus[$m->clo_id] ?? null;
                if ($sid === null) {
                    continue;
                }
                $w = (float) ($intensity[$m->mapping_level] ?? 0);
                $syllabi[$sid]['clo_ids'][] = (int) $m->clo_id;
                $cur = $syllabi[$sid]['weight'] ?? -1.0;
                $curDepth = self::DEPTH[$syllabi[$sid]['level'] ?? ''] ?? -1;
                if ($w > $cur || ($w === $cur && (self::DEPTH[$m->mapping_level] ?? 0) > $curDepth)) {
                    $syllabi[$sid]['weight'] = $w;
                    $syllabi[$sid]['level'] = $m->mapping_level;
                }
            }
            foreach ($syllabi as $sid => $s) {
                $courseId = (int) ($syllabusCourse[$sid] ?? 0);
                if (isset($ctdt[$courseId])) {
                    $lv = $ctdt[$courseId][$pi->id] ?? null;
                    $s['level'] = $syllabi[$sid]['level'] = (string) $lv;
                    $s['weight'] = $syllabi[$sid]['weight'] = $lv === null ? 0.0 : (float) ($intensity[$lv] ?? 0);
                }
                $syllabi[$sid]['credit'] = (float) ($credits[$sid] ?? 0);
                if ($syllabi[$sid]['credit'] <= 0 || $s['weight'] <= 0) {
                    unset($syllabi[$sid]);
                }
            }

            $weight = $cohortWeights[$pi->id] ?? $pi->weight;
            $outPis[(int) $pi->id] = [
                'code' => $pi->code,
                'weight' => $weight === null ? null : (float) $weight,
                'syllabi' => $syllabi,
            ];
        }

        $outPlos = [];
        foreach ($plos as $plo) {
            $outPlos[(int) $plo->id] = ['code' => $plo->code, 'pi_ids' => $pis->where('plo_id', $plo->id)->pluck('id')->map(fn ($v) => (int) $v)->all()];
        }

        return ['pis' => $outPis, 'plos' => $outPlos];
    }

    /** Lớp của LẦN HỌC GẦN NHẤT mỗi môn: năm học · học kỳ · id. @return array<int,int> syllabus_id → class_id */
    private function latestClassBySyllabus(Collection $classIds): array
    {
        $out = [];
        foreach (CourseClass::whereIn('id', $classIds)->orderBy('academic_year')->orderBy('semester')->orderBy('id')->get(['id', 'syllabus_id']) as $c) {
            $out[(int) $c->syllabus_id] = (int) $c->id;
        }

        return $out;
    }

    private function f(float $v): string
    {
        return number_format($v, 4, ',', '');
    }
}
