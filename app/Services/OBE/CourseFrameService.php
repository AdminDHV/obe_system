<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Events\GradeUpdatedEvent;
use App\Models\Assessment;
use App\Models\Clo;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Program;
use App\Models\RubricCriteria;
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Services\Import\ClassSheetReader;
use App\Services\Import\CourseFrameReader;
use Illuminate\Support\Facades\DB;

/**
 * KHUNG HỌC PHẦN THEO ĐỢT — kỹ thuật của trường dựng một lần cho cả một khóa, một năm học.
 *
 * Trường chốt sau buổi trình bày 03/10/2026: giáo viên thấy quy trình nhiều bước, nên tách vai. Kỹ thuật
 * khai trước CLO, ma trận CLO → PLO/PI, bài đánh giá của từng môn và danh sách lớp kèm giảng viên;
 * giáo viên chỉ còn một việc là tải phiếu lớp mình, điền câu hỏi với điểm, rồi nộp.
 *
 * Đề cương dựng xong được đánh dấu `frame_locked_at`: từ đó phiếu giáo viên nộp lên dùng khai báo
 * của khung, không ghi đè được (xem `ClassSheetService::validate`).
 *
 * Kiểm bằng ĐÚNG bộ kiểm và ghi bằng ĐÚNG bộ ghi khai báo của phiếu lớp — hai đường nhập không được
 * lệch luật nhau. Như phiếu: có một lỗi là không ghi gì, báo đủ mọi lỗi kèm sheet và ô.
 *
 * Một đợt tách được nhiều file (vd mỗi khoa một file). File chỉ có sheet LopHocPhan dùng để thêm lớp
 * cho môn đã dựng khung từ file trước.
 */
final class CourseFrameService
{
    public function __construct(
        private ClassSheetService $sheets,
        private SyllabusVersionResolver $syllabi,
    ) {}

    /**
     * @param array<string,mixed> $frame kết quả của CourseFrameReader
     * @return array{errors: array<int,string>, warnings: array<int,string>, stats: array<string,int>}
     */
    public function import(array $frame, int $schoolId): array
    {
        $this->syllabi->reset();
        [$errors, $warnings, $groups] = $this->validate($frame, $schoolId);

        if ($errors !== []) {
            return ['errors' => $errors, 'warnings' => [], 'stats' => []];
        }

        [$stats, $pairs, $more] = DB::transaction(fn () => $this->apply($groups, $schoolId));

        // Tính lại SAU khi ghi xong hẳn — như phiếu lớp.
        foreach ($pairs as [$student, $class]) {
            GradeUpdatedEvent::dispatch($student, $class);
        }

        return ['errors' => [], 'warnings' => array_merge($warnings, $more), 'stats' => $stats];
    }

    // ── KIỂM ────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $frame
     * @return array{0: array<int,string>, 1: array<int,string>, 2: array<int,array<string,mixed>>}
     */
    public function validate(array $frame, int $schoolId): array
    {
        $e = $frame['errors'] ?? [];
        $warn = [];
        $sheetClo = $frame['sheets']['clo'] ?? null;
        $sheetDg = $frame['sheets']['danh_gia'] ?? null;
        $sheetLop = $frame['sheets']['lop'] ?? null;

        if ($sheetClo === null && $sheetDg === null && $sheetLop === null) {
            return [[
                'Không thấy sheet CLO, DanhGia hay LopHocPhan — tải file mẫu khung học phần ở Bước 1 rồi điền theo mẫu.',
            ], [], []];
        }
        if (($sheetClo === null) !== ($sheetDg === null)) {
            $e[] = 'File khung có sheet ' . ($sheetClo ?? $sheetDg) . ' mà thiếu sheet ' . ($sheetClo === null ? CourseFrameReader::CLO_SHEET : CourseFrameReader::ASSESSMENT_SHEET)
                . ' — CLO và bài đánh giá của một môn phải khai cùng nhau.';
        }

        // Gom theo (ngành, khóa, môn).
        $groups = [];
        $add = function (string $kind, array $row, string $sheet) use (&$groups, &$e): void {
            foreach (['nganh' => 'Mã ngành', 'khoa' => 'Khóa', 'mon' => 'Mã môn'] as $k => $label) {
                if (($row[$k] ?? null) === null) {
                    $e[] = "{$sheet} · dòng {$row['row']}: thiếu {$label}.";
                    return;
                }
            }
            $key = mb_strtoupper($row['nganh']) . '|' . mb_strtoupper($row['khoa']) . '|' . mb_strtoupper($row['mon']);
            $groups[$key] ??= ['nganh' => $row['nganh'], 'khoa' => $row['khoa'], 'mon' => $row['mon'], 'row' => $row['row'], 'sheet' => $sheet, 'clos' => [], 'assessments' => [], 'classes' => []];
            $groups[$key][$kind][] = $row;
        };
        foreach ($frame['clos'] ?? [] as $row) {
            $add('clos', $row, (string) $sheetClo);
        }
        foreach ($frame['assessments'] ?? [] as $row) {
            $add('assessments', $row, (string) $sheetDg);
        }
        foreach ($frame['classes'] ?? [] as $row) {
            $add('classes', $row, (string) $sheetLop);
        }

        if ($groups === [] && $e === []) {
            $e[] = 'File khung chưa có môn nào — điền ít nhất một dòng ở sheet CLO và DanhGia.';
        }

        $seenClass = [];
        $skipped = [];
        $out = [];
        foreach ($groups as $g) {
            $where = "Môn {$g['mon']} ({$g['khoa']})";

            // Môn chỉ có bài đánh giá (file mẫu điền sẵn chuyên cần · giữa kỳ · cuối kỳ cho mọi môn) mà
            // chưa có CLO và chưa có lớp: chưa dựng trong đợt này, bỏ qua thay vì bắt xóa từng dòng.
            if ($g['clos'] === [] && $g['classes'] === []) {
                $skipped[] = $g['mon'];
                continue;
            }

            $program = Program::where('school_id', $schoolId)->where('code', $g['nganh'])->first();
            if (! $program) {
                $e[] = "{$g['sheet']} · dòng {$g['row']}: chưa có ngành '{$g['nganh']}' — khai ở file khai báo chương trình trước.";
                continue;
            }
            $cohort = $this->sheets->resolveCohort($program, (string) $g['khoa']);
            if ($cohort === null || ! $cohort->exists) {
                $e[] = "{$g['sheet']} · dòng {$g['row']}: ngành {$program->code} chưa có khóa '{$g['khoa']}' — khai khóa ở file khai báo chương trình trước.";
                continue;
            }
            $course = Course::where('school_id', $schoolId)->where('code', $g['mon'])->first();
            if (! $course) {
                $e[] = "{$g['sheet']} · dòng {$g['row']}: chưa có môn '{$g['mon']}' — khai ở sheet MonHoc của file khai báo chương trình trước.";
                continue;
            }

            $declares = $g['clos'] !== [] || $g['assessments'] !== [];
            $syllabus = $this->syllabi->find($course, $cohort);
            $targets = [];
            $clos = [];
            $assessments = [];

            if ($declares) {
                // Chỉ đưa vào bộ kiểm những cột PLO/PI môn này thật sự dùng — file của một khoa có nhiều
                // ngành, cột của ngành khác không phải lỗi của môn này.
                $used = [];
                foreach ($g['clos'] as $row) {
                    foreach (array_keys($row['map']) as $code) {
                        $used[$code] = true;
                    }
                }
                $headers = array_filter($frame['targets'] ?? [], fn ($code) => isset($used[$code]));

                [$targets, $clos, $assessments] = $this->sheets->validateDeclarations(
                    $program, $course, $cohort, $headers, $g['clos'], $g['assessments'],
                    [
                        'dau' => $where,
                        'clo' => (string) $sheetClo,
                        'danh_gia' => (string) $sheetDg,
                        'mon' => $where,
                        'khoi_clo' => 'sheet ' . ($sheetClo ?? CourseFrameReader::CLO_SHEET),
                        'khoi_danh_gia' => 'sheet ' . ($sheetDg ?? CourseFrameReader::ASSESSMENT_SHEET),
                    ],
                    $e, $warn,
                );

                if ($syllabus) {
                    $this->checkRemovals($syllabus, $clos, $assessments, $where, $e);
                }
            } elseif ($syllabus?->frame_locked_at === null) {
                $e[] = "{$g['sheet']} · dòng {$g['row']}: {$where} chưa dựng khung — khai CLO và bài đánh giá của môn (sheet CLO, DanhGia) trước hoặc trong cùng file.";
                continue;
            }

            if ($g['classes'] === []) {
                $warn[] = "{$where}: chưa có lớp học phần nào — giáo viên chưa nộp được phiếu cho môn này. Thêm lớp ở sheet LopHocPhan khi có.";
            }

            $classes = [];
            foreach ($g['classes'] as $row) {
                $code = $row['ma_lop'];
                $cell = "{$sheetLop} · ô {$row['cell']}";
                $k = mb_strtoupper($code);
                if (isset($seenClass[$k])) {
                    $e[] = "{$cell}: mã lớp '{$code}' khai trùng (đã có ở dòng {$seenClass[$k]}).";
                    continue;
                }
                $seenClass[$k] = $row['row'];

                $existing = CourseClass::with('syllabus:id,course_id')->where('school_id', $schoolId)->where('class_code', $code)->first();
                if ($existing && (int) $existing->syllabus?->course_id !== (int) $course->id) {
                    $e[] = "{$cell}: mã lớp '{$code}' đã là lớp của môn khác — mỗi lớp học phần một mã riêng.";
                    continue;
                }
                if ($existing && (int) $existing->cohort_id !== (int) $cohort->id) {
                    $e[] = "{$cell}: mã lớp '{$code}' đã là lớp của khóa khác — mỗi lớp học phần một mã riêng.";
                    continue;
                }
                $gv = $this->sheets->lecturerProblem($row['ma_gv'] ?? null, $row['giang_vien'], $schoolId);
                if ($gv !== null) {
                    $e[] = "{$sheetLop} · dòng {$row['row']}: lớp '{$code}' — {$gv}";
                }
                if ($row['hoc_ky'] !== null && ClassSheetReader::number($row['hoc_ky']) === null) {
                    $e[] = "{$sheetLop} · dòng {$row['row']}: học kỳ của lớp '{$code}' phải là số.";
                }
                $classes[] = $row;
            }

            $out[] = compact('program', 'cohort', 'course', 'declares', 'targets', 'clos', 'assessments', 'classes');
        }

        if ($skipped !== []) {
            $warn[] = count($skipped) . ' môn chỉ có bài đánh giá, chưa có CLO nên chưa dựng khung lần này: ' . implode(', ', $skipped) . '.';
        }

        return [array_values(array_unique($e)), $warn, $out];
    }

    /**
     * Khung là nguồn đúng: CLO hay bài đánh giá đã có mà không còn trong khung thì gỡ ra — trừ khi đã
     * có câu hỏi hay điểm treo vào, vì gỡ nó là mất điểm của giáo viên mà không ai biết.
     *
     * @param array<string,array<string,mixed>> $clos
     * @param array<string,array<string,mixed>> $assessments
     * @param array<int,string> $e
     */
    private function checkRemovals(CourseSyllabus $syllabus, array $clos, array $assessments, string $where, array &$e): void
    {
        foreach (Clo::where('syllabus_id', $syllabus->id)->get() as $clo) {
            if (isset($clos[mb_strtoupper($clo->code)])) {
                continue;
            }
            if (RubricCriteria::where('clo_id', $clo->id)->exists() || StudentCloResult::where('clo_id', $clo->id)->exists()) {
                $e[] = "{$where}: CLO {$clo->code} đã có câu hỏi hoặc kết quả đo nên không bỏ khỏi khung được — giữ dòng của CLO này.";
            }
        }
        foreach (Assessment::where('syllabus_id', $syllabus->id)->get() as $a) {
            if (! isset($assessments[ClassSheetReader::slug($a->name)]) && StudentGrade::where('assessment_id', $a->id)->exists()) {
                $e[] = "{$where}: bài '{$a->name}' đã có điểm nên không bỏ khỏi khung được — giữ dòng của bài này.";
            }
        }
    }

    // ── GHI ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $groups
     * @return array{0: array<string,int>, 1: array<int,array{0:\App\Models\Student,1:CourseClass}>, 2: array<int,string>}
     */
    private function apply(array $groups, int $schoolId): array
    {
        $stats = ['mon' => 0, 'clo' => 0, 'anh_xa' => 0, 'bai_danh_gia' => 0, 'lop' => 0];
        $pairs = [];
        $warnings = [];

        foreach ($groups as $g) {
            /** @var Program $program */
            $program = $g['program'];
            /** @var Cohort $cohort */
            $cohort = $g['cohort'];
            /** @var Course $course */
            $course = $g['course'];

            $program->courses()->syncWithoutDetaching([$course->id]); // không ghi đè cờ bắt buộc/tự chọn đã khai
            $syllabus = $this->syllabi->forCourse($course, $cohort);

            $before = null;
            if ($g['declares']) {
                $before = $this->sheets->syllabusFingerprint($syllabus->id);
                $this->sheets->applyDeclarations($syllabus, $g['targets'], $g['clos'], $g['assessments'], $stats);
                $stats['bai_danh_gia'] += count($g['assessments']);

                // Gỡ phần không còn trong khung (bước kiểm đã chặn những thứ có điểm treo vào).
                $keepClo = array_map(fn ($c) => $c['code'], $g['clos']);
                Clo::where('syllabus_id', $syllabus->id)->get()
                    ->reject(fn (Clo $c) => in_array(mb_strtoupper($c->code), array_map('mb_strtoupper', $keepClo), true))
                    ->each->delete();
                Assessment::where('syllabus_id', $syllabus->id)->get()
                    ->reject(fn (Assessment $a) => isset($g['assessments'][ClassSheetReader::slug($a->name)]))
                    ->each->delete();

                $syllabus->update(['frame_locked_at' => now()]);
                $stats['mon']++;
            }

            foreach ($g['classes'] as $row) {
                CourseClass::updateOrCreate(
                    ['school_id' => $schoolId, 'class_code' => $row['ma_lop']],
                    [
                        'syllabus_id' => $syllabus->id,
                        'cohort_id' => $cohort->id,
                        'lecturer_id' => $this->sheets->lecturer($row['ma_gv'] ?? null, $row['giang_vien'], $schoolId)->id,
                        'academic_year' => $row['nam_hoc'] ?? ($cohort->start_year . '-' . ($cohort->start_year + 1)),
                        'semester' => (int) (ClassSheetReader::number($row['hoc_ky']) ?? 1),
                    ],
                );
                $stats['lop']++;
            }

            if ($before !== null) {
                array_push($warnings, ...$this->sheets->recalcSiblings(
                    $syllabus, null, $course, $cohort, $before, $pairs,
                    'File khung', 'sửa lại file khung và tải lên lại',
                ));
            }
        }

        return [$stats, array_values($pairs), $warnings];
    }
}
