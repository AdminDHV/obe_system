<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Assessment;
use App\Models\AssessmentCloMapping;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\CohortPiWeight;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseOutcomeLevel;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\MappingScaleWeight;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds / updates the curriculum structure from a multi-sheet workbook.
 * Idempotent: re-running the same file updates by code instead of duplicating.
 *
 * Sheets are processed in dependency order:
 *   Program → Cohort → Course(+syllabus+program link) → CLO → Assessment(+CLO map)
 *   → PLO → PI → CLO→PI → Class.
 */
class StructureBuilderService
{
    /** @var array<string,string> errors keyed by "sheet · row" */
    private array $errors = [];

    /** @var array<int,string> cảnh báo: khung mới làm ĐỔI cơ sở đo của khóa đã có kết quả */
    private array $warnings = [];

    /** @var array<string,int> */
    private array $stats = [
        'nganh' => 0, 'khoa' => 0, 'mon' => 0, 'clo' => 0, 'bai_danh_gia' => 0,
        'bai_danh_gia_clo' => 0, 'plo' => 0, 'pi' => 0, 'clo_pi' => 0, 'ma_tran_ctdt' => 0, 'lop' => 0, 'sinh_vien' => 0,
    ];

    /** Tenant being built — every lookup by code is scoped to this school. */
    private int $schoolId = 0;

    /**
     * Khóa mà file khung này đang khai (sheet `Khoa`). Dùng để ĐÓNG BĂNG đề cương theo khóa:
     * mỗi khóa một `course_syllabus.version` riêng, nên khai khung khóa mới không đè CLO và ma
     * trận CLO→PI mà khóa cũ đang dùng. File khai nhiều khóa ⇒ null ⇒ giữ hành vi cũ.
     */
    private ?Cohort $cohortContext = null;

    public function __construct(
        private SyllabusVersionResolver $syllabi = new SyllabusVersionResolver(),
        private CloMatrixService $matrix = new CloMatrixService(),
    ) {}

    /**
     * @param array<string,Collection> $sheets sheet-name => rows (heading-row collection)
     * @return array{stats: array<string,int>, errors: array<int,string>, warnings: array<int,string>}
     */
    public function build(array $sheets, int $schoolId): array
    {
        $this->schoolId = $schoolId;

        // PLO/PI gắn vào NGÀNH chứ không gắn vào KHÓA, và nạp khung là `updateOrCreate` đè lên
        // CHÍNH những dòng khóa cũ đang dùng. Nên chụp lại cơ sở đo trước/sau để cảnh báo nếu
        // khung mới làm đổi số của khóa đã đo xong.
        $before = $this->measurementSnapshot();

        $this->cohortContext = null;
        $this->syllabi->reset();

        $sheets = $this->fromProgramFile($sheets);

        $this->buildPrograms($sheets['Nganh'] ?? collect());
        $this->buildCohorts($sheets['Khoa'] ?? collect());
        $this->cohortContext = $this->resolveCohortContext($sheets['Khoa'] ?? collect());
        $this->buildCourses($sheets['Mon'] ?? collect());
        $this->buildClos($sheets['CLO'] ?? collect());
        $this->buildAssessments($sheets['BaiDanhGia'] ?? collect());
        $this->buildAssessmentCloMappings($sheets['BaiDanhGia_CLO'] ?? collect());
        $this->buildPlos($sheets['PLO'] ?? collect());
        $this->buildPis($sheets['PI'] ?? collect());
        $this->buildCloPi($sheets['CLO_PI'] ?? collect());
        $this->buildCourseMatrix($sheets['MaTran_CTDT'] ?? collect(), $sheets['Khoa'] ?? collect());
        $this->buildClasses($sheets['Lop'] ?? collect());
        $this->buildSettings($sheets['ThamSo'] ?? collect());
        $this->buildStudents($sheets['SinhVien'] ?? collect());

        $this->detectMeasurementDrift($before, $this->measurementSnapshot());

        return [
            'stats' => $this->stats,
            'errors' => array_values($this->errors),
            'warnings' => $this->warnings,
        ];
    }

    // Helpers

    private function val($row, string ...$keys): ?string
    {
        foreach ($keys as $k) {
            $v = $row[$k] ?? null;
            if ($v !== null && trim((string) $v) !== '') {
                return trim((string) $v);
            }
        }
        return null;
    }

    /**
     * Chụp mọi thứ ẢNH HƯỞNG TỚI CON SỐ ĐO của trường này: trọng số PI, mức I/R/M/A của từng
     * ánh xạ CLO→PI, và tập PI của mỗi PLO (thêm/bớt PI làm đổi trung bình PLO).
     *
     * @return array{pi_weight: array<string,string>, level: array<string,string>, plo_pis: array<string,int>}
     */
    private function measurementSnapshot(): array
    {
        $programIds = Program::where('school_id', $this->schoolId)->pluck('id');
        if ($programIds->isEmpty()) {
            return ['pi_weight' => [], 'level' => [], 'plo_pis' => []];
        }

        $plos = Plo::whereIn('program_id', $programIds)->get(['id', 'code']);
        $pis = Pi::whereIn('plo_id', $plos->pluck('id'))->get(['id', 'plo_id', 'code', 'weight']);
        $ploCode = $plos->pluck('code', 'id');

        $piWeight = [];
        $ploPis = [];
        foreach ($pis as $pi) {
            $piWeight[$pi->code] = $pi->weight === null ? 'bằng nhau' : (string) $pi->weight;
            $ploPis[$ploCode[$pi->plo_id] ?? '?'] = ($ploPis[$ploCode[$pi->plo_id] ?? '?'] ?? 0) + 1;
        }

        $level = [];
        $mappings = CloPiMapping::whereIn('pi_id', $pis->pluck('id'))->get(['clo_id', 'pi_id', 'mapping_level']);
        // Khoá phải kèm MÔN + PHIÊN BẢN đề cương: sau khi đóng băng theo khóa, mỗi khóa có một
        // CLO mang mã "CLO1" riêng — chỉ lấy mã CLO thì hai khóa đè khoá nhau và sinh cảnh báo ma.
        $clos = Clo::whereIn('id', $mappings->pluck('clo_id')->unique())
            ->with('syllabus.course:id,code')
            ->get(['id', 'code', 'syllabus_id']);
        $cloLabel = $clos->mapWithKeys(fn (Clo $c) => [$c->id => sprintf(
            '%s/%s · %s',
            $c->syllabus?->course?->code ?? '?',
            $c->syllabus?->version ?? '?',
            $c->code,
        )]);
        $piCode = $pis->pluck('code', 'id');
        foreach ($mappings as $m) {
            $key = ($cloLabel[$m->clo_id] ?? '?') . ' → ' . ($piCode[$m->pi_id] ?? '?');
            $level[$key] = (string) $m->mapping_level;
        }

        // Ma trận CTĐT cũng quyết định trọng số (tín chỉ × mức) ⇒ đổi nó là đổi số đo.
        $ctdt = CourseOutcomeLevel::whereIn('pi_id', $pis->pluck('id'))
            ->with(['cohort:id,name', 'course:id,code'])
            ->get(['cohort_id', 'course_id', 'pi_id', 'level']);
        foreach ($ctdt as $row) {
            $key = sprintf('CTĐT %s · %s → %s', $row->cohort?->name ?? '?', $row->course?->code ?? '?', $piCode[$row->pi_id] ?? '?');
            $level[$key] = (string) $row->level;
        }

        return ['pi_weight' => $piWeight, 'level' => $level, 'plo_pis' => $ploPis];
    }

    /**
     * So hai ảnh chụp; chỉ cảnh báo khi trường ĐÃ CÓ kết quả đo — nếu chưa đo lần nào thì việc
     * sửa khung là bình thường, không có gì để mất.
     */
    private function detectMeasurementDrift(array $before, array $after): void
    {
        $changes = [];

        foreach ($after['pi_weight'] as $code => $now) {
            $was = $before['pi_weight'][$code] ?? null;
            if ($was !== null && $was !== $now) {
                $changes[] = "trọng số {$code}: {$was} → {$now}";
            }
        }
        foreach ($after['level'] as $key => $now) {
            $was = $before['level'][$key] ?? null;
            if ($was !== null && $was !== $now) {
                $changes[] = "mức đo {$key}: {$was} → {$now}";
            } elseif ($was === null && $before['level'] !== []) {
                $changes[] = "thêm ánh xạ {$key} (mức {$now})";
            }
        }
        foreach ($after['plo_pis'] as $plo => $count) {
            $was = $before['plo_pis'][$plo] ?? null;
            if ($was !== null && $was !== $count) {
                $changes[] = "{$plo} đổi từ {$was} sang {$count} PI";
            }
        }

        if ($changes === []) {
            return;
        }

        $measured = DB::table('cohorts as c')
            ->join('students as s', 's.cohort_id', '=', 'c.id')
            ->join('student_plo_results as r', 'r.student_id', '=', 's.id')
            ->where('c.school_id', $this->schoolId)
            ->distinct()
            ->pluck('c.name');

        if ($measured->isEmpty()) {
            return;
        }

        $this->warnings[] = 'CẢNH BÁO — khung vừa nạp làm ĐỔI cơ sở đo của khóa đã có kết quả: '
            . $measured->implode(', ')
            . '. Bấm "Tính lại" sẽ ra số khác với báo cáo đã gửi. Thay đổi: '
            . implode(' · ', array_slice($changes, 0, 12))
            . (count($changes) > 12 ? ' … (+' . (count($changes) - 12) . ' mục)' : '');
    }

    /**
     * Ghi MỌI lỗi, kể cả nhiều lỗi trên cùng một dòng. Trước đây khóa theo "sheet:dòng" nên lỗi sau đè lỗi
     * trước — dòng có hai ô sai chỉ báo một, người nhập sửa xong tải lên lại mới thấy lỗi thứ hai. Chỉ
     * gộp các lỗi trùng hệt nhau.
     */
    private function err(string $sheet, int $rowNum, string $msg): void
    {
        $line = "Sheet {$sheet} · dòng {$rowNum}: {$msg}";
        $this->errors[$sheet . ':' . $rowNum . ':' . md5($msg)] = $line;
    }

    /**
     * "Đại học" · "ĐH" · "Cử nhân" ⇒ đại học; "Thạc sĩ" · "ThS" · "Sau đại học" ⇒ thạc sĩ.
     * Không nhận ra thì trả null: ngành giữ nguyên bậc cũ, ngưỡng vẫn là mặc định chung.
     */
    private function programLevel(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $slug = \Illuminate\Support\Str::slug($raw, '_');

        return match (true) {
            in_array($slug, ['dai_hoc', 'dh', 'cu_nhan', 'undergraduate'], true) => Program::LEVEL_DAI_HOC,
            in_array($slug, ['thac_si', 'ths', 'sau_dai_hoc', 'master'], true) => Program::LEVEL_THAC_SI,
            default => null,
        };
    }

    private function programByCode(string $code): ?Program
    {
        return Program::where('school_id', $this->schoolId)->where('code', $code)->first();
    }

    private function courseByCode(string $code): ?Course
    {
        return Course::where('school_id', $this->schoolId)->where('code', $code)->first();
    }

    /**
     * Đề cương dùng cho lần nạp này — quy tắc chọn phiên bản theo khóa nằm ở
     * `SyllabusVersionResolver`, dùng chung với phiếu lớp học phần.
     */
    private function activeSyllabus(Course $course): CourseSyllabus
    {
        return $this->syllabi->forCourse($course, $this->cohortContext);
    }

    /** Khóa của lần nạp — chỉ khi file khai ĐÚNG MỘT khóa; nhiều khóa thì không đoán. */
    private function resolveCohortContext(Collection $rows): ?Cohort
    {
        $names = $rows->map(fn ($row) => $this->val($row, 'ten_khoa', 'ten_khoa_hoc', 'name'))
            ->filter()->unique()->values();

        if ($names->count() !== 1) {
            return null;
        }

        return Cohort::where('school_id', $this->schoolId)->where('name', $names->first())->first();
    }

    // 1. Programs

    private function buildPrograms(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $code = $this->val($row, 'ma_nganh', 'code');
            $name = $this->val($row, 'ten_nganh', 'name');
            if (!$code || !$name) {
                $this->err('Nganh', $rowNum, 'thiếu Mã ngành hoặc Tên ngành.');
                continue;
            }
            $deptName = $this->val($row, 'khoa', 'ten_khoa', 'department') ?? 'Chưa phân khoa';
            $dept = Department::firstOrCreate(
                ['school_id' => $this->schoolId, 'name' => $deptName],
                ['code' => mb_substr(preg_replace('/\s+/', '', $deptName), 0, 20)],
            );
            $attrs = ['name' => $name, 'department_id' => $dept->id];
            $level = $this->programLevel($this->val($row, 'bac', 'bac_dao_tao', 'trinh_do'));
            if ($level !== null) {
                $attrs['level'] = $level;
            }
            Program::updateOrCreate(['school_id' => $this->schoolId, 'code' => $code], $attrs);
            $this->stats['nganh']++;
        }
    }

    // 2. Cohorts

    private function buildCohorts(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $progCode = $this->val($row, 'ma_nganh', 'program_code');
            $name = $this->val($row, 'ten_khoa', 'ten_khoa_hoc', 'name');
            $year = $this->val($row, 'nam_bat_dau', 'start_year', 'nam');
            if (!$progCode || !$name || !$year) {
                $this->err('Khoa', $rowNum, 'thiếu Mã ngành / Tên khóa / Năm bắt đầu.');
                continue;
            }
            $program = $this->programByCode($progCode);
            if (!$program) {
                $this->err('Khoa', $rowNum, "không tìm thấy ngành '{$progCode}' (khai báo ở sheet Nganh trước).");
                continue;
            }
            $attrs = ['start_year' => (int) $year, 'school_id' => $this->schoolId];

            // Optional grade granularity: 'total' (course mark only) or 'question' (rubric).
            // Left blank ⇒ inferred from the cohort year, and the stored value is not overwritten.
            $granularity = strtolower(trim((string) ($this->val($row, 'do_min_diem', 'grade_granularity') ?? '')));
            if (in_array($granularity, ['total', 'question'], true)) {
                $attrs['grade_granularity'] = $granularity;
            }

            Cohort::updateOrCreate(['program_id' => $program->id, 'name' => $name], $attrs);
            $this->stats['khoa']++;
        }
    }

    // 3. Courses (+ syllabus + program link)

    private function buildCourses(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $code = $this->val($row, 'ma_mon', 'code');
            $name = $this->val($row, 'ten_mon', 'name');
            if (!$code || !$name) {
                $this->err('Mon', $rowNum, 'thiếu Mã môn hoặc Tên môn.');
                continue;
            }
            $credits = (int) ($this->val($row, 'so_tin_chi', 'credits') ?? 3);
            $attrs = ['name' => $name, 'credits' => $credits];
            if (($periods = $this->val($row, 'so_tiet', 'periods')) !== null && is_numeric($periods)) {
                $attrs['periods'] = (int) $periods;
            }
            if (($unit = $this->val($row, 'bo_mon', 'managing_unit')) !== null) {
                $attrs['managing_unit'] = $unit;
            }
            $course = Course::updateOrCreate(
                ['school_id' => $this->schoolId, 'code' => $code],
                $attrs,
            );
            $this->activeSyllabus($course);

            // Attach the course to the program (program_courses) when a program code is given.
            $progCode = $this->val($row, 'ma_nganh', 'program_code');
            if ($progCode && ($program = $this->programByCode($progCode))) {
                $program->courses()->syncWithoutDetaching([$course->id => ['is_mandatory' => true]]);
            }
            $this->stats['mon']++;
        }
    }

    // 4. CLOs

    private function buildClos(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $courseCode = $this->val($row, 'ma_mon', 'course_code');
            $cloCode = $this->val($row, 'ma_clo', 'code');
            if (!$courseCode || !$cloCode) {
                $this->err('CLO', $rowNum, 'thiếu Mã môn hoặc Mã CLO.');
                continue;
            }
            $course = $this->courseByCode($courseCode);
            if (!$course) {
                $this->err('CLO', $rowNum, "không tìm thấy môn '{$courseCode}'.");
                continue;
            }
            $syllabus = $this->activeSyllabus($course);
            Clo::updateOrCreate(
                ['syllabus_id' => $syllabus->id, 'code' => $cloCode],
                ['description' => $this->val($row, 'mo_ta', 'description') ?? $cloCode],
            );
            $this->stats['clo']++;
        }
    }

    // 5. Assessments (+ CLO map)

    private function buildAssessments(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $courseCode = $this->val($row, 'ma_mon', 'course_code');
            $name = $this->val($row, 'ten_bai_danh_gia', 'ten_bai', 'name');
            if (!$courseCode || !$name) {
                $this->err('BaiDanhGia', $rowNum, 'thiếu Mã môn hoặc Tên bài đánh giá.');
                continue;
            }
            $course = $this->courseByCode($courseCode);
            if (!$course) {
                $this->err('BaiDanhGia', $rowNum, "không tìm thấy môn '{$courseCode}'.");
                continue;
            }
            $syllabus = $this->activeSyllabus($course);

            $loai = strtolower($this->val($row, 'loai', 'assessment_category', 'category') ?? 'process');
            $category = $loai === 'final' || str_contains($loai, 'cuối') ? 'final' : 'process';
            $weight = (float) ($this->val($row, 'trong_so', 'weight_percent', 'weight') ?? 0);

            $assessment = Assessment::updateOrCreate(
                ['syllabus_id' => $syllabus->id, 'name' => $name],
                ['assessment_category' => $category, 'weight_percent' => $weight, 'is_rubric_based' => false],
            );
            $this->stats['bai_danh_gia']++;

            // Map to CLOs (comma-separated list of CLO codes).
            $cloCodes = $this->val($row, 'clo', 'ma_clo', 'clos');
            if ($cloCodes) {
                foreach (preg_split('/[,;]+/', $cloCodes) as $cc) {
                    $cc = trim($cc);
                    if ($cc === '') continue;
                    $clo = Clo::where('syllabus_id', $syllabus->id)->where('code', $cc)->first();
                    if (!$clo) {
                        $this->err('BaiDanhGia', $rowNum, "CLO '{$cc}' chưa khai báo ở môn '{$courseCode}'.");
                        continue;
                    }
                    AssessmentCloMapping::updateOrCreate(
                        ['assessment_id' => $assessment->id, 'clo_id' => $clo->id],
                        ['weight_in_assessment' => 100],
                    );
                }
            }
        }
    }

    // 5b. Assessment → CLO mappings, one pair per row, with an optional per-pair weight.

    private function buildAssessmentCloMappings(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $courseCode = $this->val($row, 'ma_mon', 'course_code');
            $name = $this->val($row, 'ten_bai_danh_gia', 'ten_bai', 'name');
            $cloCode = $this->val($row, 'ma_clo', 'clo_code', 'code');
            if (!$courseCode || !$name || !$cloCode) {
                $this->err('BaiDanhGia_CLO', $rowNum, 'thiếu Mã môn / Tên bài đánh giá / Mã CLO.');
                continue;
            }
            $course = $this->courseByCode($courseCode);
            if (!$course) {
                $this->err('BaiDanhGia_CLO', $rowNum, "không tìm thấy môn '{$courseCode}'.");
                continue;
            }
            $syllabus = $this->activeSyllabus($course);
            $assessment = Assessment::where('syllabus_id', $syllabus->id)->where('name', $name)->first();
            if (!$assessment) {
                $this->err('BaiDanhGia_CLO', $rowNum, "bài đánh giá '{$name}' chưa khai báo ở môn '{$courseCode}' (sheet BaiDanhGia) — tên phải trùng khớp.");
                continue;
            }
            $clo = Clo::where('syllabus_id', $syllabus->id)->where('code', $cloCode)->first();
            if (!$clo) {
                $this->err('BaiDanhGia_CLO', $rowNum, "CLO '{$cloCode}' chưa khai báo ở môn '{$courseCode}'.");
                continue;
            }
            $rawWeight = $this->val($row, 'trong_so', 'weight_in_assessment', 'weight');
            $weight = ($rawWeight === null || $rawWeight === '') ? 100 : (float) $rawWeight;
            AssessmentCloMapping::updateOrCreate(
                ['assessment_id' => $assessment->id, 'clo_id' => $clo->id],
                ['weight_in_assessment' => $weight],
            );
            $this->stats['bai_danh_gia_clo']++;
        }
    }

    // 6. PLOs

    private function buildPlos(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $progCode = $this->val($row, 'ma_nganh', 'program_code');
            $ploCode = $this->val($row, 'ma_plo', 'code');
            if (!$progCode || !$ploCode) {
                $this->err('PLO', $rowNum, 'thiếu Mã ngành hoặc Mã PLO.');
                continue;
            }
            $program = $this->programByCode($progCode);
            if (!$program) {
                $this->err('PLO', $rowNum, "không tìm thấy ngành '{$progCode}'.");
                continue;
            }
            Plo::updateOrCreate(
                ['program_id' => $program->id, 'code' => $ploCode],
                ['description' => $this->val($row, 'mo_ta', 'description') ?? $ploCode],
            );
            $this->stats['plo']++;
        }
    }

    // 7. PIs

    private function buildPis(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $progCode = $this->val($row, 'ma_nganh', 'program_code');
            $ploCode = $this->val($row, 'ma_plo', 'plo_code');
            $piCode = $this->val($row, 'ma_pi', 'code');
            if (!$progCode || !$ploCode || !$piCode) {
                $this->err('PI', $rowNum, 'thiếu Mã ngành / Mã PLO / Mã PI.');
                continue;
            }
            $program = $this->programByCode($progCode);
            $plo = $program ? Plo::where('program_id', $program->id)->where('code', $ploCode)->first() : null;
            if (!$plo) {
                $this->err('PI', $rowNum, "không tìm thấy PLO '{$ploCode}' của ngành '{$progCode}'.");
                continue;
            }
            $rawWeight = $this->val($row, 'trong_so', 'weight');
            $weight = ($rawWeight === null || $rawWeight === '') ? null : (float) $rawWeight;

            // PI (mã, mô tả, thuộc PLO nào) là danh mục của NGÀNH — dùng chung là đúng.
            // Nhưng TRỌNG SỐ là tham số ĐO: ghi vào bảng riêng theo khóa để khai khung khóa mới
            // không đè lên trọng số mà khóa cũ đã dùng khi xuất báo cáo.
            // `pis.weight` vẫn giữ vai trò MẶC ĐỊNH CỦA NGÀNH (khóa nào chưa khai riêng thì dùng nó),
            // nên vẫn ghi như cũ — đổi đi sẽ làm các khóa chưa có dòng đè bị thay đổi hành vi.
            $pi = Pi::updateOrCreate(
                ['plo_id' => $plo->id, 'code' => $piCode],
                ['description' => $this->val($row, 'mo_ta', 'description') ?? $piCode, 'is_dummy' => false, 'weight' => $weight],
            );

            // Và ĐÓNG BĂNG cho đúng khóa đang khai: từ đây khóa khác khai lại không đụng tới nó.
            if ($this->cohortContext && $weight !== null) {
                CohortPiWeight::updateOrCreate(
                    ['cohort_id' => $this->cohortContext->id, 'pi_id' => $pi->id],
                    ['weight' => $weight],
                );
            }

            $this->stats['pi']++;
        }
    }

    // 8. CLO → PI

    private function buildCloPi(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $progCode = $this->val($row, 'ma_nganh', 'program_code');
            $courseCode = $this->val($row, 'ma_mon', 'course_code');
            $cloCode = $this->val($row, 'ma_clo', 'clo_code');
            $piCode = $this->val($row, 'ma_pi', 'pi_code');
            $level = strtoupper($this->val($row, 'muc_do', 'mapping_level', 'level') ?? 'M');
            if (!in_array($level, ['I', 'R', 'M', 'A'], true)) $level = 'M';
            if (!$progCode || !$courseCode || !$cloCode || !$piCode) {
                $this->err('CLO_PI', $rowNum, 'thiếu Mã ngành / Mã môn / Mã CLO / Mã PI.');
                continue;
            }
            $program = $this->programByCode($progCode);
            $course = $this->courseByCode($courseCode);
            $clo = $course ? Clo::where('syllabus_id', $this->activeSyllabus($course)->id)->where('code', $cloCode)->first() : null;
            // The PI must belong to this program — PI codes can repeat across programs.
            $pi = $program
                ? Pi::where('code', $piCode)->whereHas('plo', fn ($q) => $q->where('program_id', $program->id))->first()
                : null;
            if (!$clo || !$pi) {
                $this->err('CLO_PI', $rowNum, "không tìm thấy CLO '{$cloCode}' (môn {$courseCode}) hoặc PI '{$piCode}' (ngành {$progCode}).");
                continue;
            }
            CloPiMapping::updateOrCreate(
                ['clo_id' => $clo->id, 'pi_id' => $pi->id],
                ['mapping_level' => $level],
            );
            $this->stats['clo_pi']++;
        }
    }

    // 9. Classes

    /**
     * Tham số đo của TRƯỜNG, khai ngay trong file khung.
     *
     * Trước đây khung khai được toàn bộ cấu trúc nhưng hệ số/ngưỡng thì phải vào màn hình Trường
     * gõ tay. Quên bước đó thì hệ thống LẶNG LẼ chạy bằng mặc định — số vẫn hiện, vẫn trông hợp
     * lý, chỉ là sai. Đúng lỗi đó đã kéo độ khớp với file của một trường từ 714/714 xuống 475/714.
     * Gộp vào cùng file khung để "phát file mẫu → trường điền → nhập điểm → đo" là đủ, không còn
     * bước gõ tay nào bị bỏ quên.
     *
     * Ô để TRỐNG ⇒ xoá dòng riêng của trường, quay về mặc định dùng chung. Nhờ vậy tải lại file
     * đã sửa vừa thêm vừa bỏ được, không phải vào tận nơi xoá tay.
     */
    private function buildSettings(Collection $rows): void
    {
        // Danh sách TRẮNG — khớp đúng màn hình Trường → Cấu hình OBE. Không mở cửa cho mọi khoá
        // cấu hình hệ thống chỉ vì nó nằm trong một file Excel ai đó tải lên.
        $choPhep = [
            'obe.process_weight', 'obe.final_weight',
            'obe.clo_pass_threshold', 'obe.plo_attainment_ratio_pass', 'obe.plo_pass_threshold',
            'obe.target_attainment_percent', 'obe.course_pass_threshold',
            'obe.measurement_min_completion_percent',
        ];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $key = trim((string) ($this->val($row, 'tham_so', 'ma_tham_so', 'key') ?? ''));

            if ($key === '' || str_starts_with(mb_strtolower($key), 'tham s')) {
                continue; // dòng trống hoặc dòng tiêu đề lặp lại
            }

            $raw = $this->val($row, 'gia_tri', 'value');
            $value = $raw === null ? '' : trim((string) $raw);

            // Mức I/R/M/A đi cùng bảng riêng chứ không nằm trong system_settings.
            if (str_starts_with($key, 'muc.')) {
                $level = strtoupper(substr($key, 4));

                if (! in_array($level, ['I', 'R', 'M', 'A'], true)) {
                    $this->err('ThamSo', $rowNum, "mức '{$level}' không hợp lệ (chỉ I, R, M, A).");
                    continue;
                }
                if ($value === '') {
                    MappingScaleWeight::where('school_id', $this->schoolId)->where('level', $level)->delete();
                    continue;
                }
                if (! is_numeric($value) || (float) $value < 0) {
                    $this->err('ThamSo', $rowNum, "trọng số mức {$level} phải là số không âm.");
                    continue;
                }

                MappingScaleWeight::updateOrCreate(
                    ['school_id' => $this->schoolId, 'level' => $level],
                    ['weight_percent' => (float) $value],
                );
                $this->stats['tham_so'] = ($this->stats['tham_so'] ?? 0) + 1;
                continue;
            }

            if (! in_array($key, $choPhep, true)) {
                $this->err('ThamSo', $rowNum, "tham số '{$key}' không được phép khai ở đây.");
                continue;
            }
            if ($value === '') {
                SystemSetting::where('school_id', $this->schoolId)->where('key', $key)->delete();
                continue;
            }
            if (! is_numeric($value)) {
                $this->err('ThamSo', $rowNum, "tham số '{$key}' phải là số.");
                continue;
            }

            SystemSetting::updateOrCreate(
                ['school_id' => $this->schoolId, 'key' => $key],
                ['value' => $value],
            );
            $this->stats['tham_so'] = ($this->stats['tham_so'] ?? 0) + 1;
        }

        ObeConfig::flush();
    }

    private function buildClasses(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $classCode = $this->val($row, 'ma_lop', 'class_code');
            $courseCode = $this->val($row, 'ma_mon', 'course_code');
            $cohortName = $this->val($row, 'ten_khoa', 'ten_khoa_hoc', 'cohort');
            $staff = $this->val($row, 'ma_gv', 'staff_code', 'lecturer');
            if (!$classCode || !$courseCode || !$cohortName) {
                $this->err('Lop', $rowNum, 'thiếu Mã lớp / Mã môn / Tên khóa.');
                continue;
            }
            $course = $this->courseByCode($courseCode);
            if (!$course) {
                $this->err('Lop', $rowNum, "không tìm thấy môn '{$courseCode}'.");
                continue;
            }
            $cohort = Cohort::where('school_id', $this->schoolId)->where('name', $cohortName)->first();
            if (!$cohort) {
                $this->err('Lop', $rowNum, "không tìm thấy khóa '{$cohortName}'.");
                continue;
            }
            // Tra GV PHẢI kẹp theo trường: hai trường trùng mã GV thì trước đây lớp gắn nhầm
            // giảng viên của trường kia. Chấp nhận cả GV chưa được gán trường (school_id NULL) —
            // dữ liệu cũ còn nhiều tài khoản mồ côi — nhưng KHÔNG lấy GV của trường khác.
            $lecturer = $staff
                ? User::where('staff_code', $staff)
                    ->where(fn ($q) => $q->where('school_id', $this->schoolId)->orWhereNull('school_id'))
                    ->orderByRaw('CASE WHEN school_id = ? THEN 0 ELSE 1 END', [$this->schoolId])
                    ->first()
                : null;
            if (!$lecturer) {
                $this->err('Lop', $rowNum, $staff
                    ? "không tìm thấy giảng viên có mã '{$staff}'."
                    : 'thiếu Mã GV (lớp bắt buộc có giảng viên).');
                continue;
            }
            CourseClass::updateOrCreate(
                ['school_id' => $this->schoolId, 'class_code' => $classCode],
                [
                    'syllabus_id' => $this->activeSyllabus($course)->id,
                    'cohort_id' => $cohort->id,
                    'lecturer_id' => $lecturer->id,
                    'academic_year' => $this->val($row, 'nam_hoc', 'academic_year') ?? ($cohort->start_year . '-' . ($cohort->start_year + 1)),
                    'semester' => (int) ($this->val($row, 'hoc_ky', 'semester') ?? 1),
                ],
            );
            $this->stats['lop']++;
        }
    }

    /**
     * FILE KHAI BÁO CHƯƠNG TRÌNH — 4 sheet thay cho 11 sheet cũ.
     *
     * Khách chốt ở buổi họp: file khai báo chương trình chỉ khai thứ của CẢ chương trình; CLO, bài đánh giá, ma
     * trận và lớp khai ở phiếu từng lớp học phần ("lật hết ra nhập cho… môn một lần thì không hợp
     * lý"). Ở đây chỉ CHUYỂN DẠNG sang các sheet cũ rồi để các hàm dựng sẵn có làm — không viết lại
     * logic dựng, nên file 11 sheet cũ vẫn nhập được y như trước.
     *
     *   Nganh_Khoa : Mã ngành · Tên ngành · Khoa quản lý · Tên khóa · Năm bắt đầu  (mỗi khóa một dòng)
     *   PLO_PI     : Mã ngành · Mã PLO · Mô tả PLO · Mã PI · Mô tả PI · Trọng số PI
     *   MonHoc     : Mã ngành · Mã môn · Tên môn · Tín chỉ
     *   SinhVien   : MSSV · Họ và tên · Mã ngành · Năm khóa
     *
     * Mã ngành được bỏ trống khi file chỉ khai MỘT ngành.
     *
     * @param array<string,Collection> $sheets
     * @return array<string,Collection>
     */
    private function fromProgramFile(array $sheets): array
    {
        if (! isset($sheets['Nganh_Khoa']) && ! isset($sheets['PLO_PI']) && ! isset($sheets['MonHoc'])) {
            return $sheets;
        }

        $programs = [];
        $cohorts = [];
        foreach ($sheets['Nganh_Khoa'] ?? collect() as $row) {
            $code = $this->val($row, 'ma_nganh');
            if (! $code) {
                continue;
            }
            $programs[$code] ??= [
                'ma_nganh' => $code,
                'ten_nganh' => $this->val($row, 'ten_nganh'),
                'khoa' => $this->val($row, 'khoa_quan_ly', 'khoa_phu_trach', 'don_vi'),
                'bac' => $this->val($row, 'bac', 'bac_dao_tao', 'trinh_do'),
            ];
            $year = $this->val($row, 'nam_bat_dau', 'nam_khoa');
            $name = $this->val($row, 'ten_khoa') ?? ($year ? "Khóa {$year}" : null);
            if ($name || $year) {
                $cohorts[] = ['ma_nganh' => $code, 'ten_khoa' => $name, 'nam_bat_dau' => $year];
            }
        }
        $onlyProgram = count($programs) === 1 ? array_key_first($programs) : null;
        $programOf = fn ($row) => $this->val($row, 'ma_nganh') ?? $onlyProgram;

        if (isset($sheets['Nganh_Khoa'])) {
            $sheets['Nganh'] = collect(array_values($programs));
            $sheets['Khoa'] = collect($cohorts);
        }

        if (isset($sheets['MonHoc'])) {
            $sheets['Mon'] = $sheets['MonHoc']->map(fn ($row) => [
                'ma_mon' => $this->val($row, 'ma_mon', 'ma_hoc_phan'),
                'ten_mon' => $this->val($row, 'ten_mon', 'ten_hoc_phan'),
                'so_tin_chi' => $this->val($row, 'tin_chi', 'so_tin_chi'),
                'so_tiet' => $this->val($row, 'so_tiet', 'tong_so_tiet', 'thoi_luong'),
                'bo_mon' => $this->val($row, 'bo_mon', 'bo_mon_phu_trach', 'don_vi_phu_trach'),
                'ma_nganh' => $programOf($row),
            ])->values();
        }

        if (isset($sheets['PLO_PI'])) {
            [$sheets['PLO'], $sheets['PI']] = $this->splitPloPi($sheets['PLO_PI'], $programOf);
        }

        if (isset($sheets['SinhVien'])) {
            $sheets['SinhVien'] = $sheets['SinhVien']->map(fn ($row) => [
                'mssv' => $this->val($row, 'mssv', 'ma_so_sinh_vien', 'mshv'),
                'ho_ten' => $this->val($row, 'ho_va_ten', 'ho_ten'),
                'ma_nganh' => $programOf($row),
                'nam_khoa' => $this->val($row, 'nam_khoa', 'nam_bat_dau'),
                'ten_khoa' => $this->val($row, 'ten_khoa'),
            ])->values();
        }

        return $sheets;
    }

    /**
     * Tách sheet PLO_PI (mỗi PI một dòng, PLO lặp lại) thành sheet PLO và PI.
     *
     * Khách chốt: tổng trọng số các PI của MỘT PLO phải bằng 1. Sai thì KHÔNG dựng PI của PLO đó —
     * dựng với trọng số sai thì số đo vẫn ra, vẫn trông hợp lý, chỉ là sai.
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function splitPloPi(Collection $rows, \Closure $programOf): array
    {
        $plos = [];
        $pis = [];
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $prog = $programOf($row);
            $plo = $this->val($row, 'ma_plo');
            if (! $prog || ! $plo) {
                $this->err('PLO_PI', $rowNum, 'thiếu Mã PLO' . ($prog ? '' : ' hoặc Mã ngành (file khai nhiều ngành)') . '.');
                continue;
            }
            $key = $prog . '|' . $plo;
            $plos[$key] ??= ['ma_nganh' => $prog, 'ma_plo' => $plo, 'mo_ta' => null];
            $plos[$key]['mo_ta'] ??= $this->val($row, 'mo_ta_plo', 'noi_dung_plo');

            $pi = $this->val($row, 'ma_pi');
            if ($pi) {
                $pis[$key][] = [
                    'row' => $rowNum,
                    'ma_nganh' => $prog, 'ma_plo' => $plo, 'ma_pi' => $pi,
                    'mo_ta' => $this->val($row, 'mo_ta_pi', 'noi_dung_pi'),
                    'trong_so' => $this->val($row, 'trong_so_pi', 'trong_so'),
                ];
            }
        }

        $okPis = [];
        foreach ($pis as $key => $group) {
            $plo = $plos[$key]['ma_plo'];
            $missing = array_filter($group, fn ($p) => $p['trong_so'] === null || ! is_numeric(str_replace(',', '.', $p['trong_so'])));
            if ($missing !== []) {
                foreach ($missing as $p) {
                    $this->err('PLO_PI', $p['row'], "PI '{$p['ma_pi']}' thiếu trọng số (hoặc không phải số) — các PI của {$plo} phải có trọng số cộng lại đúng 1.");
                }
                continue;
            }
            $sum = array_sum(array_map(fn ($p) => (float) str_replace(',', '.', $p['trong_so']), $group));
            if (abs($sum - 1) > 0.001) {
                $this->err('PLO_PI', $group[0]['row'], "tổng trọng số các PI của {$plo} là " . rtrim(rtrim(number_format($sum, 3, ',', ''), '0'), ',') . ' — phải đúng 1. Chưa dựng PI của PLO này.');
                continue;
            }
            foreach ($group as $p) {
                unset($p['row']);
                $p['trong_so'] = str_replace(',', '.', $p['trong_so']);
                $okPis[] = $p;
            }
        }

        foreach ($plos as $k => $p) {
            $plos[$k]['mo_ta'] ??= $p['ma_plo'];
        }

        return [collect(array_values($plos)), collect($okPis)];
    }

    /**
     * Sheet `MaTran_CTDT` — "Ma trận chuẩn đầu ra các học phần trong chương trình đào tạo" của CTĐT:
     * mỗi môn một dòng, mỗi PLO/PI một cột, ô ghi I/R/M/A.
     *
     *   (Mã ngành) · Mã môn · (Tên môn, Tín chỉ, STT — chỉ để dễ đọc) · <mã PLO/PI> · <mã PLO/PI> …
     *
     * Khách góp ý: file này KHÔNG nhập CLO (CLO nhập ở phiếu từng môn), mà nhập ma trận môn × PLO/PI.
     *  - Áp cho các khóa khai ở sheet Nganh_Khoa của CHÍNH file này — CTĐT ban hành theo khóa, khóa sau
     *    đổi ma trận không làm đổi kết quả khóa trước.
     *  - Cột PLO đã chia PI ⇒ mức áp cho mọi PI của PLO đó (bảng CTĐT ghi ở cấp PLO).
     *  - Dòng của một môn là TOÀN BỘ đóng góp của môn: nhập lại mà bỏ trống ô nào thì gỡ ô đó.
     *
     * Dòng 1 là tiêu đề GỐC (không qua chuẩn hóa khóa), vì mã cột có dấu chấm ("PI2.1").
     */
    private function buildCourseMatrix(Collection $rows, Collection $cohortRows): void
    {
        $rows = $rows->values();
        if ($rows->isEmpty()) {
            return;
        }

        $sheet = 'MaTran_CTDT';
        $header = array_values((array) $rows->first());
        $colNganh = null;
        $colMon = null;
        $targets = [];
        foreach ($header as $i => $h) {
            $raw = trim((string) $h);
            $slug = \Illuminate\Support\Str::slug($raw, '_');
            if ($slug === 'ma_nganh') {
                $colNganh = $i;
            } elseif (in_array($slug, ['ma_mon', 'ma_hoc_phan', 'ma_mon_hoc', 'ma_hp'], true)) {
                $colMon = $i;
            } elseif ($raw === '' || $slug === 'stt' || str_starts_with($slug, 'ten_') || str_contains($slug, 'tin_chi')) {
                continue;
            } else {
                $targets[$i] = $raw;
            }
        }

        if ($colMon === null) {
            $this->err($sheet, 1, 'dòng tiêu đề phải có cột "Mã môn".');

            return;
        }
        if ($targets === []) {
            $this->err($sheet, 1, 'dòng tiêu đề chưa có cột PLO/PI nào (ghi mã PLO hoặc PI, vd PLO1, PI2.1).');

            return;
        }

        $cell = fn (array $r, ?int $i) => $i === null ? null : (($v = trim((string) ($r[$i] ?? ''))) === '' ? null : $v);
        $programs = Program::where('school_id', $this->schoolId)->get();
        $resolved = [];
        $trung = [];
        $cohorts = [];

        foreach ($rows->slice(1) as $i => $r) {
            $r = array_values((array) $r);
            $rowNum = $i + 1;
            $maMon = $cell($r, $colMon);
            $hasLevel = collect($targets)->keys()->contains(fn ($k) => $cell($r, $k) !== null);

            if ($maMon === null && ! $hasLevel) {
                continue; // dòng trống, hoặc dòng tiêu đề nhóm ("I. KIẾN THỨC CHUNG") không có mã môn
            }
            if ($maMon === null) {
                $this->err($sheet, $rowNum, 'có ghi mức nhưng thiếu Mã môn.');
                continue;
            }

            $maNganh = $cell($r, $colNganh);
            $program = $maNganh ? $programs->firstWhere('code', $maNganh) : ($programs->count() === 1 ? $programs->first() : null);
            if (! $program) {
                $this->err($sheet, $rowNum, $maNganh
                    ? "không tìm thấy ngành '{$maNganh}'."
                    : 'trường có nhiều ngành — thêm cột "Mã ngành" để biết dòng này thuộc ngành nào.');
                continue;
            }

            $course = $this->courseByCode($maMon);
            if (! $course) {
                $this->err($sheet, $rowNum, "không tìm thấy môn '{$maMon}' (khai ở sheet MonHoc).");
                continue;
            }

            if (! isset($cohorts[$program->id])) {
                $names = $cohortRows->filter(fn ($row) => ($this->val($row, 'ma_nganh') ?? $program->code) === $program->code)
                    ->map(fn ($row) => $this->val($row, 'ten_khoa'))->filter()->unique()->values();
                $cohorts[$program->id] = Cohort::where('program_id', $program->id)->whereIn('name', $names)->get();
            }
            if ($cohorts[$program->id]->isEmpty()) {
                $this->err($sheet, $rowNum, "chưa khai khóa của ngành {$program->code} ở sheet Nganh_Khoa — ma trận CTĐT áp cho các khóa khai trong cùng file.");
                continue;
            }

            // Hai cột cùng trỏ vào một PI (cột PLO2 và cột PI2.1 của chính PLO2) là lỗi khai báo ⇒ soi ngay ở
            // dòng tiêu đề, không cần đợi cột đó có dữ liệu.
            if (! isset($trung[$program->id])) {
                $trung[$program->id] = [];
                $chu = [];
                $plos = Plo::where('program_id', $program->id)->with('pis')->get();
                foreach ($targets as $k => $code) {
                    $plo = $plos->firstWhere('code', $code);
                    $pis = $plo
                        ? ($plo->pis->where('is_dummy', false)->isNotEmpty() ? $plo->pis->where('is_dummy', false) : $plo->pis->where('is_dummy', true))
                        : $plos->flatMap->pis->filter(fn (Pi $p) => ! $p->is_dummy && $p->code === $code);
                    $keys = $plo && $pis->isEmpty() ? ['plo:' . $plo->id] : $pis->map(fn (Pi $p) => 'pi:' . $p->id)->all();
                    foreach ($keys as $key) {
                        if (isset($chu[$key])) {
                            [$cotTruoc, $kTruoc, $ten] = $chu[$key];
                            $this->err($sheet, 1, "cột {$cotTruoc} và cột {$code} cùng trỏ vào {$ten}"
                                . ' — ghi mức ở cột PLO hoặc ở từng cột PI của PLO đó, không ghi cả hai.');
                            $trung[$program->id][$k] = true;
                            $trung[$program->id][$kTruoc] = true;
                            continue;
                        }
                        $chu[$key] = [$code, $k, str_starts_with($key, 'plo:') ? $code : ($pis->firstWhere('id', (int) substr($key, 3))?->code ?? $code)];
                    }
                }
            }

            // Cột chưa khai ở PLO_PI chỉ báo lỗi khi THẬT SỰ ghi mức: bảng CTĐT hay chừa sẵn cột tới PLO10 dù
            // ngành mới khai vài PLO, chừa cột trống mà báo lỗi thì trường phải đi xóa cột mới nhập được.
            $resolve = function (int $k, string $code) use (&$resolved, $program, $sheet) {
                if (isset($resolved[$program->id][$k])) {
                    return $resolved[$program->id][$k];
                }
                $t = $this->matrix->resolveCourseColumn($program, $code);
                if ($t['error'] !== null) {
                    $this->err($sheet, 1, "cột {$code}: {$t['error']}");
                }

                return $resolved[$program->id][$k] = $t;
            };

            $byPi = [];
            $rowOk = true;
            foreach ($targets as $k => $code) {
                $v = $cell($r, $k);
                if ($v === null) {
                    continue;
                }
                $level = CloMatrixService::normalizeLevel($v);
                if (! in_array($level, CloMatrixService::LEVELS, true)) {
                    $this->err($sheet, $rowNum, "cột {$code}: mức '{$v}' không hợp lệ — chỉ ghi I, R, M hoặc A.");
                    $rowOk = false;
                    continue;
                }
                if (isset($trung[$program->id][$k])) {
                    $rowOk = false;
                    continue;
                }
                $target = $resolve($k, $code);
                if ($target['error'] !== null) {
                    $rowOk = false;
                    continue;
                }
                foreach ($target['pis'] as $pi) {
                    $byPi[(int) $pi->id] = $level;
                }
            }

            if (! $rowOk) {
                continue;
            }
            if ($byPi === []) {
                $this->err($sheet, $rowNum, "môn {$maMon} chưa ghi mức ở cột PLO/PI nào — môn không đóng góp PLO nào thì bỏ dòng này.");
                continue;
            }

            foreach ($cohorts[$program->id] as $cohort) {
                foreach ($byPi as $piId => $level) {
                    CourseOutcomeLevel::updateOrCreate(
                        ['cohort_id' => $cohort->id, 'course_id' => $course->id, 'pi_id' => $piId],
                        ['level' => $level],
                    );
                }
                CourseOutcomeLevel::where('cohort_id', $cohort->id)->where('course_id', $course->id)
                    ->whereNotIn('pi_id', array_keys($byPi))->delete();
            }
            $this->stats['ma_tran_ctdt'] += count($byPi);
        }
    }

    /**
     * Sheet SinhVien của file khai báo chương trình — thay cho file danh sách sinh viên riêng.
     * MSSV trùng thì cập nhật, không nhân đôi. Phiếu lớp học phần vẫn tự thêm sinh viên chưa có.
     */
    private function buildStudents(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $mssv = $this->val($row, 'mssv');
            $name = $this->val($row, 'ho_ten');
            $prog = $this->val($row, 'ma_nganh');
            if (! $mssv && ! $name) {
                continue;
            }
            if (! $mssv || ! $name || ! $prog) {
                $this->err('SinhVien', $rowNum, 'thiếu MSSV / Họ và tên / Mã ngành.');
                continue;
            }
            $program = $this->programByCode($prog);
            if (! $program) {
                $this->err('SinhVien', $rowNum, "không tìm thấy ngành '{$prog}'.");
                continue;
            }

            $cohortName = $this->val($row, 'ten_khoa');
            $year = $this->val($row, 'nam_khoa');
            $cohort = $cohortName
                ? Cohort::where('program_id', $program->id)->where('name', $cohortName)->first()
                : ($year ? Cohort::where('program_id', $program->id)->where('start_year', (int) $year)->orderBy('id')->first() : null);
            if (! $cohort && $year) {
                $cohort = Cohort::firstOrCreate(
                    ['program_id' => $program->id, 'name' => "Khóa {$year}"],
                    ['start_year' => (int) $year, 'school_id' => $this->schoolId],
                );
            }
            if (! $cohort) {
                $this->err('SinhVien', $rowNum, 'thiếu Năm khóa (để xếp sinh viên vào khóa).');
                continue;
            }

            \App\Models\Student::updateOrCreate(
                ['school_id' => $this->schoolId, 'student_code' => $mssv],
                ['full_name' => $name, 'program_id' => $program->id, 'cohort_id' => $cohort->id, 'status' => 'studying'],
            );
            $this->stats['sinh_vien']++;
        }
    }
}
