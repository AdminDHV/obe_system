<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\MeasurementSnapshot;
use App\Models\MeasurementSnapshotOutcome;
use App\Models\MeasurementSnapshotStudent;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Chốt sổ kết quả đo, và dựng lại bản đã chốt.
 *
 * Bản chốt lưu ĐÚNG những gì bảng tổng của lớp hiển thị, và `restore()` trả về ĐÚNG cấu trúc mà
 * `ClassOutcomeSummaryService::forGroup()` trả về — nhờ vậy màn hình và file xuất dùng chung một
 * đường vẽ, không thể lệch giữa "xem hiện tại" và "xem bản đã gửi".
 *
 * Mức đạt chung của lớp được TÍNH LẠI từ điểm từng sinh viên trong bản chốt (không lưu sẵn), để
 * bản chốt chỉ chứa dữ kiện thô — đổi cách tổng hợp về sau vẫn đọc lại được bản cũ.
 */
class MeasurementSnapshotService
{
    public function __construct(private ClassOutcomeSummaryService $summary) {}

    /** Chốt sổ hiện trạng của một phạm vi. */
    public function take(
        int $programId,
        ?int $cohortId = null,
        ?string $classGroup = null,
        bool $onTrackOnly = false,
        string $label = '',
        ?int $takenBy = null,
    ): MeasurementSnapshot {
        $data = $this->summary->forGroup($programId, $cohortId, $classGroup, $onTrackOnly);
        $schoolId = Student::where('program_id', $programId)->value('school_id');

        return DB::transaction(function () use ($data, $programId, $cohortId, $classGroup, $onTrackOnly, $label, $takenBy, $schoolId) {
            $snapshot = MeasurementSnapshot::create([
                'school_id' => $schoolId,
                'program_id' => $programId,
                'cohort_id' => $cohortId,
                'class_group' => $classGroup,
                'label' => $label !== '' ? $label : 'Chốt sổ ' . now()->format('d/m/Y H:i'),
                'on_track_only' => $onTrackOnly,
                'pass_threshold' => $data['threshold'],
                'target_percent' => $data['target_percent'],
                'students_total' => $data['students_total'],
                'students_counted' => $data['students_counted'],
                'taken_by' => $takenBy,
            ]);

            $students = [];
            $outcomes = [];
            foreach ($data['students'] as $row) {
                $students[] = [
                    'snapshot_id' => $snapshot->id,
                    'student_id' => $row['student_id'] ?? null,
                    'student_code' => $row['student_code'],
                    'full_name' => $row['full_name'],
                    'class_group' => $row['class_group'],
                    'track' => ($row['track'] ?? []) ? implode(', ', $row['track']) : null,
                    'track_pending' => (bool) ($row['track_pending'] ?? false),
                    'credits_earned' => $row['credits_earned'],
                    'required_credits' => $row['required_credits'],
                    'completion_percent' => $row['completion_percent'],
                    'on_track' => $row['on_track'],
                    'counted' => $row['counted'],
                ];

                foreach (['plo', 'pi'] as $type) {
                    foreach ($row[$type] as $code => $score) {
                        if ($score !== null) {
                            $phu = $type === 'pi' ? ($row['pi_coverage'][$code] ?? null) : null;
                            $outcomes[] = [
                                'snapshot_id' => $snapshot->id,
                                'student_code' => $row['student_code'],
                                'outcome_type' => $type,
                                'outcome_code' => $code,
                                'achieved_score' => $score,
                                'courses_done' => $phu['done'] ?? null,
                                'courses_planned' => $phu['total'] ?? null,
                            ];
                        }
                    }
                }
            }

            foreach (array_chunk($students, 500) as $chunk) {
                MeasurementSnapshotStudent::insert($chunk);
            }
            foreach (array_chunk($outcomes, 1000) as $chunk) {
                MeasurementSnapshotOutcome::insert($chunk);
            }

            return $snapshot;
        });
    }

    /**
     * Dựng lại bản đã chốt, CÙNG cấu trúc với ClassOutcomeSummaryService::forGroup().
     *
     * @return array<string,mixed>
     */
    public function restore(MeasurementSnapshot $snapshot): array
    {
        $rows = $snapshot->students()->orderBy('student_code')->get();
        $outcomes = $snapshot->outcomes()->get()->groupBy('student_code');

        $ploCodes = [];
        $piCodes = [];
        foreach ($snapshot->outcomes()->get(['outcome_type', 'outcome_code']) as $o) {
            $bucket = $o->outcome_type === 'plo' ? 'ploCodes' : 'piCodes';
            ${$bucket}[$o->outcome_code] = true;
        }
        $ploCodes = array_keys($ploCodes);
        $piCodes = array_keys($piCodes);
        sort($ploCodes);
        sort($piCodes);

        $students = [];
        $coverageDeclared = false;
        foreach ($rows as $row) {
            $byType = ['plo' => [], 'pi' => []];
            $phu = [];
            foreach ($outcomes->get($row->student_code, collect()) as $o) {
                $byType[$o->outcome_type][$o->outcome_code] = (float) $o->achieved_score;
                if ($o->outcome_type === 'pi' && $o->courses_planned !== null) {
                    $phu[$o->outcome_code] = [
                        'done' => (int) $o->courses_done,
                        'total' => (int) $o->courses_planned,
                    ];
                    $coverageDeclared = true;
                }
            }

            $students[] = [
                'student_code' => $row->student_code,
                'full_name' => $row->full_name,
                'class_group' => $row->class_group,
                'track' => $row->track === null || $row->track === '' ? [] : explode(', ', $row->track),
                'track_pending' => (bool) $row->track_pending,
                'pi_coverage' => $phu,
                'credits_earned' => (float) $row->credits_earned,
                'required_credits' => $row->required_credits,
                'completion_percent' => $row->completion_percent === null ? null : (float) $row->completion_percent,
                'on_track' => $row->on_track,
                'counted' => $row->counted,
                'plo' => $this->fill($ploCodes, $byType['plo']),
                'pi' => $this->fill($piCodes, $byType['pi']),
            ];
        }

        $counted = array_values(array_filter($students, fn ($s) => $s['counted']));

        return [
            'group_label' => $snapshot->class_group ?? 'Cả khóa',
            'grouped_by' => $snapshot->class_group !== null ? 'class_group' : 'cohort_all_groups',
            'on_track_only' => $snapshot->on_track_only,
            'is_ratio' => true,
            'threshold' => (float) $snapshot->pass_threshold,
            'target_percent' => (float) $snapshot->target_percent,
            'students_total' => $snapshot->students_total,
            'students_counted' => $snapshot->students_counted,
            'plo_codes' => $ploCodes,
            'pi_codes' => $piCodes,
            'coverage_declared' => $coverageDeclared,
            'track_pending_count' => count(array_filter($students, fn ($s) => $s['track_pending'])),
            'students' => $students,
            'plo_summary' => $this->summarise($ploCodes, $counted, 'plo', (float) $snapshot->pass_threshold, (float) $snapshot->target_percent),
            'pi_summary' => $this->summarise($piCodes, $counted, 'pi', (float) $snapshot->pass_threshold, (float) $snapshot->target_percent),
            'snapshot' => [
                'uuid' => $snapshot->uuid,
                'label' => $snapshot->label,
                'taken_at' => $snapshot->created_at?->format('d/m/Y H:i'),
            ],
        ];
    }

    /** @return array<string,float|null> */
    private function fill(array $codes, array $values): array
    {
        $out = [];
        foreach ($codes as $code) {
            $out[$code] = $values[$code] ?? null;
        }

        return $out;
    }

    /**
     * Cùng quy tắc với ClassOutcomeSummaryService: SV không có kết quả cho một chuẩn thì không
     * vào mẫu số của chuẩn đó.
     *
     * @return array<int,array<string,mixed>>
     */
    private function summarise(array $codes, array $rows, string $key, float $threshold, float $target): array
    {
        $out = [];
        foreach ($codes as $code) {
            $scores = [];
            foreach ($rows as $row) {
                if (($row[$key][$code] ?? null) !== null) {
                    $scores[] = (float) $row[$key][$code];
                }
            }

            $measured = count($scores);
            $passed = count(array_filter($scores, fn ($s) => $s >= $threshold));
            $percent = $measured > 0 ? round($passed / $measured * 100, 1) : null;

            $out[] = [
                'code' => $code,
                'students_measured' => $measured,
                'passed' => $passed,
                'attainment_percent' => $percent,
                'avg_score' => $measured > 0 ? round(array_sum($scores) / $measured, 4) : null,
                'met_target' => $percent !== null && $percent >= $target,
            ];
        }

        return $out;
    }
}
