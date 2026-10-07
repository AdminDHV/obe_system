<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Models\SurveyInstrument;
use App\Models\SurveyPloResult;
use App\Services\OBE\Methods\ObeMethodResolver;

/**
 * Attainment reporting in the AUN-QA/ABET sense: not the average score but the share of
 * students above the pass threshold, graded into levels 1/2/3.
 *
 * attainment% = (#students with achieved_score ≥ pass_threshold) / (#students measured) × 100
 */
class ObeAttainmentService
{
    public function __construct(
        private ObeMethodResolver $methods,
        private StudentProgressService $progress,
    ) {}

    /**
     * Attainment of every PLO in a program (optionally narrowed to one cohort).
     *
     * @return array{threshold: float, target: float, is_ratio: bool, has_indirect: bool, direct_weight: float, indirect_weight: float, plos: array<int, array<string, mixed>>}
     *   Each PLO holds: plo_code, plo_description, students_measured, passed, avg_score,
     *   attainment_percent (official figure = blended when survey data exists, else direct),
     *   direct_percent, indirect_percent, blended_percent, has_indirect, target_percent, gap, level, met_target.
     */
    /**
     * `gap` = mục tiêu − % đạt (dương là còn thiếu). Báo cáo ghi theo cách màn hình hiện: % đạt so với mục tiêu,
     * "+30" là vượt 30 điểm %, "-20" là thiếu 20 — cùng một số mà hai nơi ghi ngược dấu thì người đọc hiểu ngược.
     */
    public static function versusTarget(?float $gap): string
    {
        if ($gap === null) {
            return '—';
        }
        $v = round(-$gap, 1);
        $txt = rtrim(rtrim(number_format(abs($v), 1, '.', ''), '0'), '.');

        return $v > 0 ? "+{$txt}" : ($v < 0 ? "-{$txt}" : '0');
    }

    public function ploAttainment(int $programId, ?int $cohortId = null): array
    {
        $program = Program::find($programId);

        return ObeConfig::forProgram($program?->school_id, $program?->level, fn () => $this->ploAttainmentInner($programId, $cohortId, $program));
    }

    private function ploAttainmentInner(int $programId, ?int $cohortId, ?Program $program): array
    {
        // Threshold and output scale come from the school's measurement method.
        $method = $program ? $this->methods->for($program) : null;
        $threshold = $method ? $method->ploPassThreshold() : ObeConfig::ploPassThreshold();
        $isRatio = $method ? $method->outputsRatio() : false;
        $target = ObeConfig::targetAttainmentPercent();
        $schoolId = $program?->school_id;
        $levelCuts = ObeConfig::attainmentLevelThresholds();

        // Direct/indirect blend weights per school (default 0.8/0.2).
        $school = $program?->school;
        $dw = $school ? (float) $school->direct_weight : 0.8;
        $iw = $school ? (float) $school->indirect_weight : 0.2;

        // Indirect (survey) attainment per PLO in %, empty when no survey data exists.
        $indirect = $this->indirectByPlo($programId, $cohortId);
        $hasIndirectAny = $indirect !== [];

        // Only students with enough accumulated credits count: a half-way student still has a
        // full set of PI/PLO results, but those are not representative of the cohort.
        $eligibility = $this->progress->summary($programId, $cohortId);
        $studentIds = collect($this->progress->eligibleStudentIds($programId, $cohortId));

        $plos = Plo::where('program_id', $programId)->orderBy('code')->get();

        $rows = [];
        foreach ($plos as $plo) {
            $results = StudentPloResult::where('plo_id', $plo->id)
                ->whereIn('student_id', $studentIds)
                ->get();

            $measured = $results->count();
            $passed = $results->filter(fn ($r) => (float) $r->achieved_score >= $threshold)->count();
            $directPercent = $measured > 0 ? round($passed / $measured * 100, 1) : null;
            $avg = $measured > 0 ? round((float) $results->avg('achieved_score'), 4) : null;

            // Survey figure for this PLO, blended with the direct one when available.
            $indirectPercent = $indirect[$plo->id] ?? null;
            $hasIndirect = $indirectPercent !== null;
            $blended = ($hasIndirect && $directPercent !== null)
                ? round($dw * $directPercent + $iw * $indirectPercent, 1)
                : null;
            // Official figure: blended when survey data exists, otherwise the direct one.
            $percent = $blended ?? $directPercent;

            // Per-PLO target (falls back to the global one) plus the gap.
            $ploTarget = ObeConfig::targetForPlo($plo->id, $schoolId);
            $gap = $percent === null ? null : round($ploTarget - $percent, 1);

            $rows[] = [
                'plo_id' => $plo->id,
                'plo_code' => $plo->code,
                'plo_description' => $plo->description,
                'students_measured' => $measured,
                'passed' => $passed,
                'avg_score' => $avg,
                'attainment_percent' => $percent,
                'direct_percent' => $directPercent,
                'indirect_percent' => $indirectPercent,
                'blended_percent' => $blended,
                'has_indirect' => $hasIndirect,
                'target_percent' => $ploTarget,
                'gap' => $gap,
                'level' => $percent === null ? 0 : $this->levelFor($percent, $levelCuts),
                'level_label' => $percent === null ? null : $this->levelLabel($this->levelFor($percent, $levelCuts), $levelCuts),
                'met_target' => $percent !== null && $percent >= $ploTarget,
            ];
        }

        return [
            'threshold' => $threshold,
            'target' => $target,
            'is_ratio' => $isRatio,
            'has_indirect' => $hasIndirectAny,
            'direct_weight' => $dw,
            'indirect_weight' => $iw,
            'eligibility' => $eligibility,
            'plos' => $rows,
        ];
    }

    /**
     * Attainment per PI. Direct measurement only — PIs have no survey data or per-PI target.
     *
     * @return array{threshold: float, target: float, is_ratio: bool, pis: array<int, array<string, mixed>>}
     *   Each PI: pi_code, plo_code, pi_description, students_measured, passed, avg_score,
     *   attainment_percent, target_percent, level, met_target.
     */
    public function piAttainment(int $programId, ?int $cohortId = null): array
    {
        $program = Program::find($programId);

        return ObeConfig::forProgram($program?->school_id, $program?->level, fn () => $this->piAttainmentInner($programId, $cohortId, $program));
    }

    private function piAttainmentInner(int $programId, ?int $cohortId, ?Program $program): array
    {
        $method = $program ? $this->methods->for($program) : null;
        $threshold = $method ? $method->ploPassThreshold() : ObeConfig::ploPassThreshold();
        $isRatio = $method ? $method->outputsRatio() : false;
        $target = ObeConfig::targetAttainmentPercent();
        $levelCuts = ObeConfig::attainmentLevelThresholds();

        $eligibility = $this->progress->summary($programId, $cohortId);
        $studentIds = collect($this->progress->eligibleStudentIds($programId, $cohortId));

        $pis = Pi::whereHas('plo', fn ($q) => $q->where('program_id', $programId))
            ->with('plo:id,code')
            ->orderBy('code')
            ->get();

        $rows = [];
        foreach ($pis as $pi) {
            $results = StudentPiResult::where('pi_id', $pi->id)
                ->whereIn('student_id', $studentIds)
                ->get();

            $measured = $results->count();
            $passed = $results->filter(fn ($r) => (float) $r->achieved_score >= $threshold)->count();
            $percent = $measured > 0 ? round($passed / $measured * 100, 1) : null;
            $avg = $measured > 0 ? round((float) $results->avg('achieved_score'), 4) : null;

            $rows[] = [
                'pi_id' => $pi->id,
                'pi_code' => $pi->code,
                'plo_code' => $pi->plo?->code,
                'pi_description' => $pi->description,
                'students_measured' => $measured,
                'passed' => $passed,
                'avg_score' => $avg,
                'attainment_percent' => $percent,
                'target_percent' => $target,
                'level' => $percent === null ? 0 : $this->levelFor($percent, $levelCuts),
                'level_label' => $percent === null ? null : $this->levelLabel($this->levelFor($percent, $levelCuts), $levelCuts),
                'met_target' => $percent !== null && $percent >= $target,
            ];
        }

        return [
            'threshold' => $threshold,
            'target' => $target,
            'is_ratio' => $isRatio,
            'eligibility' => $eligibility,
            'pis' => $rows,
        ];
    }

    /**
     * Indirect (survey) attainment per PLO in %, combining the program's survey instruments
     * weighted by respondent count.
     *
     * @return array<int, float> [plo_id => percent]
     */
    private function indirectByPlo(int $programId, ?int $cohortId): array
    {
        $instrumentIds = SurveyInstrument::where('program_id', $programId)
            // A specific cohort accepts its own instruments plus the shared ones (cohort_id null).
            ->when($cohortId, fn ($q) => $q->where(fn ($w) => $w->where('cohort_id', $cohortId)->orWhereNull('cohort_id')))
            ->pluck('id');

        if ($instrumentIds->isEmpty()) {
            return [];
        }

        $out = [];
        SurveyPloResult::whereIn('survey_instrument_id', $instrumentIds)
            ->get()
            ->groupBy('plo_id')
            ->each(function ($group, $ploId) use (&$out) {
                $totalResp = (int) $group->sum('respondents');
                if ($totalResp > 0) {
                    $weighted = $group->sum(fn ($r) => (float) $r->attainment_ratio * (int) $r->respondents) / $totalResp;
                } else {
                    $weighted = (float) $group->avg('attainment_ratio');
                }
                $out[(int) $ploId] = round($weighted * 100, 1);
            });

        return $out;
    }

    /** Attainment level (3/2/1) from descending % cut-offs (e.g. 80/70/60). */
    private function levelFor(float $percent, array $cuts): int
    {
        $level = count($cuts);
        foreach ($cuts as $cut) {
            if ($percent >= $cut) {
                return $level;
            }
            $level--;
        }

        return 0;
    }

    /**
     * Diễn giải con số "Mức" thành chữ, ngay tại chỗ tính ra nó.
     *
     * Bảng mức đạt trước đây in mỗi con số trần ("3" trong một ô xám) mà không đâu chú thích, nên
     * người đọc báo cáo không có cách nào biết nó nghĩa là gì. Ghép nhãn ở server chứ không ở giao
     * diện vì NGƯỠNG khai theo từng trường (`obe.attainment_level_thresholds`) — để giao diện tự
     * đoán thì mỗi trường đổi ngưỡng là nhãn nói sai, mà nói sai còn tệ hơn không nói.
     */
    private function levelLabel(int $level, array $cuts): string
    {
        $so = count($cuts);

        if ($level <= 0) {
            return 'Mức 0 (< ' . $this->goncSo($cuts[$so - 1] ?? 0) . '%)';
        }

        return 'Mức ' . $level . ' (≥ ' . $this->goncSo($cuts[$so - $level] ?? 0) . '%)';
    }

    /** Bỏ đuôi .0 cho ngưỡng nguyên — "≥ 80%" chứ không phải "≥ 80.0%". */
    private function goncSo(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
