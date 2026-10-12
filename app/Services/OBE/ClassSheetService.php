<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Events\GradeUpdatedEvent;
use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseOutcomeLevel;
use App\Models\CourseSyllabus;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\Rubric;
use App\Models\RubricCriteria;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentRubricScore;
use App\Models\User;
use App\Services\Import\ClassSheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * PHIẾU LỚP HỌC PHẦN — một file cho một lớp, đủ để đo.
 *
 * Khách chốt ở buổi họp: "một lớp học phần mình nhập một phiếu là đủ cho nguyên học phần đó…
 * khai báo một lần rồi cho cục điểm chấm vô là nó chạy". Trước đây cùng việc đó cần: sheet CLO,
 * BaiDanhGia, CLO_PI, Lop trong file khung, file danh sách sinh viên, tài khoản giảng viên tạo tay,
 * file điểm theo câu hỏi, và thêm một file điểm thành phần.
 *
 * Nguyên tắc:
 *  - KIỂM HẾT RỒI MỚI GHI. Có một lỗi là không ghi gì, và báo đủ mọi lỗi kèm sheet + ô. Ghi dở một
 *    nửa phiếu thì số đo sai mà nhìn vẫn giống đúng.
 *  - Phiếu là nguồn đúng DUY NHẤT của lớp đó: nhập lại thì điểm của lớp được thay hẳn bằng phiếu mới
 *    (ô để trống = không có điểm), không cộng dồn.
 *  - Kiểm đúng những thứ làm KHÔNG ĐO ĐƯỢC (khách dặn "còn sót tiêu chí nào không đo được thì check"):
 *    câu không có CLO, CLO không nối PLO/PI, tổng điểm tối đa ≠ 10, tổng trọng số ≠ 100%.
 *
 * Một phiếu cho HAI phép tính, như khách tách bạch: điểm câu hỏi → đo chuẩn đầu ra (rubric), tổng
 * điểm các câu của một bài → điểm bài đó (điểm số môn). Công thức đo không đổi.
 */
final class ClassSheetService
{
    private const EPS = 0.001;

    public function __construct(
        private SyllabusVersionResolver $syllabi,
        private CloMatrixService $matrix,
    ) {}

    /**
     * @param array<string,mixed> $sheet kết quả của ClassSheetReader
     * @param array{bo_sung?: bool, lop_id?: int|null} $opt
     *   bo_sung: PHIẾU BỔ SUNG sau thi lại — chỉ cập nhật đúng những học viên có trong phiếu, điểm các
     *            bạn khác giữ nguyên. Trường thi lại cho 2 bạn vắng thi thì không phải nộp lại cả lớp.
     *   lop_id:  giảng viên nộp cho một lớp cụ thể — phiếu phải đúng là phiếu của lớp đó.
     * @return array{errors: array<int,string>, stats: array<string,int>, warnings: array<int,string>}
     */
    public function import(array $sheet, int $schoolId, array $opt = []): array
    {
        [$errors, $ctx] = $this->validate($sheet, $schoolId, $opt);

        if ($errors !== []) {
            return ['errors' => $errors, 'stats' => [], 'warnings' => []];
        }

        [$stats, $pairs, $warnings] = DB::transaction(fn () => $this->apply($sheet, $ctx, $schoolId));
        $warnings = array_merge($ctx['sheetWarnings'] ?? [], $warnings);

        // Tính lại SAU khi ghi xong hẳn — listener chạy nền, đọc giữa transaction thì thấy dữ liệu cũ.
        foreach ($pairs as [$student, $class]) {
            GradeUpdatedEvent::dispatch($student, $class);
        }

        return ['errors' => [], 'stats' => $stats, 'warnings' => $warnings];
    }

    // ── KIỂM ────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $sheet
     * @param array{bo_sung?: bool, lop_id?: int|null} $opt
     * @return array{0: array<int,string>, 1: array<string,mixed>}
     */
    public function validate(array $sheet, int $schoolId, array $opt = []): array
    {
        $e = [];
        $info = ClassSheetReader::INFO_SHEET;
        $boSung = (bool) ($opt['bo_sung'] ?? false);
        $lopId = isset($opt['lop_id']) ? (int) $opt['lop_id'] : null;

        if (! ($sheet['has_info_sheet'] ?? false)) {
            return [["Không thấy sheet '{$info}' — phiếu phải có sheet khai báo thông tin lớp học phần."], []];
        }

        $i = $sheet['info'];
        $at = fn (string $k) => $info . ' · ô ' . ($i['_cell_' . $k] ?? '?');

        // Thông tin lớp học phần
        foreach (['ma_mon' => 'Mã môn học', 'nganh' => 'Mã ngành', 'khoa' => 'Khóa', 'ma_lop' => 'Mã lớp học phần', 'giang_vien' => 'Giảng viên giảng dạy'] as $k => $label) {
            if (($i[$k] ?? null) === null) {
                $e[] = "{$info}: thiếu '{$label}'.";
            }
        }

        $program = ($i['nganh'] ?? null)
            ? Program::where('school_id', $schoolId)->where('code', $i['nganh'])->first()
            : null;
        if (($i['nganh'] ?? null) && ! $program) {
            $e[] = "{$at('nganh')}: chưa có ngành '{$i['nganh']}' — khai ở file khai báo chương trình trước.";
        }

        $course = ($i['ma_mon'] ?? null)
            ? Course::where('school_id', $schoolId)->where('code', $i['ma_mon'])->first()
            : null;
        if (($i['ma_mon'] ?? null) && ! $course) {
            $e[] = "{$at('ma_mon')}: chưa có môn '{$i['ma_mon']}' — khai ở sheet MonHoc của file khai báo chương trình trước.";
        }

        $cohort = null;
        if ($program && ($i['khoa'] ?? null)) {
            $cohort = $this->resolveCohort($program, (string) $i['khoa']);
            if ($cohort === null) {
                $e[] = "{$at('khoa')}: không hiểu khóa '{$i['khoa']}' — ghi tên khóa đã khai (vd 'Khóa 2026') hoặc năm bắt đầu (vd 2026).";
            }
        }

        $existing = null;
        if (($i['ma_lop'] ?? null) && $course) {
            $existing = CourseClass::with('syllabus')
                ->where('school_id', $schoolId)->where('class_code', $i['ma_lop'])->first();
            if ($existing && (int) $existing->syllabus?->course_id !== (int) $course->id) {
                $e[] = "{$at('ma_lop')}: mã lớp '{$i['ma_lop']}' đã là lớp của môn khác — mỗi lớp học phần một mã riêng.";
            }
        }

        // Giảng viên nộp từ trang lớp của mình: phiếu phải đúng là phiếu của lớp đó.
        if ($lopId !== null && ($i['ma_lop'] ?? null) !== null) {
            $lop = CourseClass::find($lopId);
            if ($lop && mb_strtoupper(trim((string) $i['ma_lop'])) !== mb_strtoupper($lop->class_code)) {
                $e[] = "{$at('ma_lop')}: phiếu ghi lớp '{$i['ma_lop']}' nhưng đang nộp cho lớp {$lop->class_code} — tải đúng phiếu của lớp này.";
            }
        }

        if (($i['hoc_ky'] ?? null) !== null && ClassSheetReader::number($i['hoc_ky']) === null) {
            $e[] = "{$at('hoc_ky')}: học kỳ phải là số.";
        }

        // Khai báo của môn lấy TỪ HỆ THỐNG, không từ phiếu, ở hai trường hợp:
        //  - phiếu bổ sung: lớp đã có, chỉ thêm điểm thi lại của vài bạn;
        //  - môn đã dựng KHUNG THEO ĐỢT: kỹ thuật của trường đã khai CLO, ma trận, bài đánh giá và lớp —
        //    giáo viên chỉ mang câu hỏi và điểm, sửa phần khai báo trong phiếu cũng không đổi được khung.
        $syllabus = null;
        if ($boSung) {
            if ($existing === null) {
                if (($i['ma_lop'] ?? null) !== null) {
                    $e[] = "{$at('ma_lop')}: phiếu bổ sung chỉ dùng cho lớp đã nộp phiếu — lớp '{$i['ma_lop']}' chưa có trong hệ thống.";
                }
            } else {
                $syllabus = $existing->syllabus;
            }
        } elseif ($course && $cohort?->exists) {
            $found = $this->syllabi->find($course, $cohort);
            if ($found?->frame_locked_at !== null) {
                $syllabus = $found;
                if ($existing === null && ($i['ma_lop'] ?? null) !== null) {
                    $e[] = "{$at('ma_lop')}: lớp '{$i['ma_lop']}' không có trong khung học phần của {$cohort->name} — lớp do kỹ thuật của trường khai ở file khung theo đợt.";
                }
            }
        }
        $theoHeThong = $syllabus !== null;

        $sheetWarnings = [];
        if ($theoHeThong) {
            [$clos, $assessments] = $this->declarationsOf($syllabus);
            $targets = [];
            if ($this->phieuLechKhaiBao($sheet, $clos, $assessments)) {
                $sheetWarnings[] = $boSung
                    ? 'Phiếu bổ sung dùng CLO và bài đánh giá đã có của lớp — phần khai ở sheet KhaiBao được bỏ qua.'
                    : "Môn {$course->code} của {$cohort->name} đã dựng khung — hệ thống dùng CLO, ma trận và bài đánh giá trong khung, phần khai ở sheet KhaiBao của phiếu được bỏ qua.";
            }
        } else {
            [$targets, $clos, $assessments] = $this->validateDeclarations(
                $program, $course, $cohort,
                $sheet['targets'] ?? [], $sheet['clos'] ?? [], $sheet['assessments'] ?? [],
                [
                    'dau' => $info,
                    'clo' => $info,
                    'danh_gia' => $info,
                    'mon' => $at('ma_mon'),
                    'khoi_clo' => "khối 'Chuẩn đầu ra'",
                    'khoi_danh_gia' => "khối 'Đánh giá'",
                ],
                $e, $sheetWarnings,
            );
        }

        // Sheet từng bài
        $titles = array_keys($sheet['sheets'] ?? []);
        $matched = [];
        foreach ($assessments as $key => $a) {
            $title = $this->sheetFor($a['name'], $titles);
            if ($title === null) {
                // Phiếu bổ sung chỉ cần sheet của bài thi lại.
                if (! $boSung) {
                    $e[] = "Thiếu sheet cho bài '{$a['name']}' — mỗi bài đánh giá một sheet, tên sheet trùng tên bài.";
                }
                continue;
            }
            $matched[$title] = true;
            $assessments[$key]['sheet'] = $title;
            $assessments[$key]['parsed'] = $this->validateAssessmentSheet($title, $sheet['sheets'][$title], $clos, $targets, $e, $sheetWarnings, $a['category']);
        }
        $this->checkBlankFinal($assessments, $e);
        if ($boSung && $existing !== null && $matched === []) {
            $e[] = 'Phiếu bổ sung không có sheet nào trùng tên bài đánh giá của lớp — cần ít nhất sheet của bài thi lại (vd "Cuối kỳ").';
        }
        foreach ($sheet['sheets'] ?? [] as $title => $s) {
            if (! isset($matched[$title]) && ($s['has_student_table'] ?? false)) {
                $e[] = $theoHeThong
                    ? "Sheet '{$title}' có bảng điểm nhưng môn không có bài đánh giá nào tên như vậy — sửa tên sheet cho trùng tên bài: " . implode(', ', array_column($assessments, 'name')) . '.'
                    : "Sheet '{$title}' có bảng điểm nhưng không có trong khối 'Đánh giá' của {$info} — thêm bài đó vào khối Đánh giá, hoặc sửa tên sheet cho trùng.";
            }
        }

        $ctx = compact('program', 'course', 'cohort', 'targets', 'clos', 'assessments', 'sheetWarnings', 'syllabus', 'existing', 'boSung', 'theoHeThong');

        return [array_values(array_unique($e)), $ctx];
    }

    /**
     * Trường dặn: vắng thi thì ghi VT, không để trống. Học viên có điểm ở bài khác của phiếu (tức là
     * người của lớp) mà bỏ trống cả bài cuối kỳ trong khi lớp đã thi là bị sót — để trống thì học viên
     * đó vẫn bị tính vào cỡ mẫu bằng riêng điểm quá trình. Dòng trống ở MỌI bài là học viên khác lớp
     * (phiếu điền sẵn liệt kê cả khóa), không báo.
     *
     * @param array<string,array<string,mixed>> $assessments
     * @param array<int,string> $e
     */
    private function checkBlankFinal(array $assessments, array &$e): void
    {
        $coMat = [];
        foreach ($assessments as $a) {
            foreach ($a['parsed']['students'] ?? [] as $st) {
                if ($st['score_values'] !== [] || $st['absent']) {
                    $coMat[(string) $st['mssv']] = true;
                }
            }
        }

        foreach ($assessments as $a) {
            if ($a['category'] !== 'final' || ! isset($a['parsed'])) {
                continue;
            }
            $students = $a['parsed']['students'];
            if (array_filter($students, fn ($st) => $st['score_values'] !== []) === []) {
                continue; // chưa thi cuối kỳ — phiếu nộp giữa kỳ
            }
            foreach ($students as $st) {
                if ($st['score_values'] === [] && ! $st['absent'] && isset($coMat[(string) $st['mssv']])) {
                    $e[] = "{$a['sheet']} · dòng {$st['row']}: MSSV {$st['mssv']} để trống cả bài cuối kỳ — vắng thi thì ghi VT.";
                }
            }
        }
    }

    /**
     * Kiểm PHẦN KHAI BÁO của một môn — CLO, ma trận CLO → PLO/PI, bài đánh giá.
     *
     * Phiếu lớp và file khung theo đợt dùng chung bộ kiểm này: hai đường nhập mà kiểm theo hai bộ luật
     * thì khung nhận một thứ mà phiếu chặn (hoặc ngược lại) đúng lúc không ai để ý.
     *
     * @param array<string,string> $headers cột → mã PLO/PI
     * @param array<int,array<string,mixed>> $cloRows
     * @param array<int,array<string,mixed>> $assessmentRows
     * @param array{dau: string, clo: string, danh_gia: string, mon: string, khoi_clo: string, khoi_danh_gia: string} $noi
     *        nhãn chỗ báo lỗi: đầu dòng, sheet chứa CLO, sheet chứa bài đánh giá, chỗ ghi mã môn, tên khối
     * @param array<int,string> $e
     * @param array<int,string> $warn
     * @return array{0: array<string,array<string,mixed>>, 1: array<string,array<string,mixed>>, 2: array<string,array<string,mixed>>}
     */
    public function validateDeclarations(
        ?Program $program,
        ?Course $course,
        ?Cohort $cohort,
        array $headers,
        array $cloRows,
        array $assessmentRows,
        array $noi,
        array &$e,
        array &$warn,
    ): array {
        // Chuẩn đầu ra và ma trận CLO → PLO/PI
        $targets = $program ? $this->resolveTargets($headers, $program, $e, $noi['clo']) : [];

        $clos = [];
        if ($cloRows === []) {
            $e[] = "{$noi['dau']}: chưa khai chuẩn đầu ra (CLO) nào trong {$noi['khoi_clo']}.";
        }
        foreach ($cloRows as $row) {
            $where = "{$noi['clo']} · ô {$row['cell']}";
            $code = $row['code'];
            if ($code === null) {
                $e[] = "{$where}: dòng chuẩn đầu ra thiếu mã CLO.";
                continue;
            }
            $key = mb_strtoupper($code);
            if (isset($clos[$key])) {
                $e[] = "{$where}: CLO '{$code}' khai trùng.";
                continue;
            }
            if ($row['map'] === []) {
                $e[] = "{$where}: CLO '{$code}' chưa nối vào PLO/PI nào — không nối thì CLO này không đo lên chương trình được.";
            }
            foreach ($row['map'] as $target => $m) {
                if (! in_array($m['level'], CloMatrixService::LEVELS, true)) {
                    $e[] = "{$noi['clo']} · ô {$m['cell']}: mức '{$m['level']}' không hợp lệ — chỉ ghi I, R, M hoặc A.";
                }
            }
            $clos[$key] = $row + ['key' => $key];
        }

        // Ma trận CTĐT của khóa (sheet MaTran_CTDT, file khai báo chương trình). Khóa đã khai ma trận thì
        // chỉ được nối CLO vào PLO/PI mà CTĐT ghi môn này đóng góp — đo PLO mà chương trình không
        // cam kết cho môn này thì trọng số không có chỗ dựa. Chiều ngược lại (CTĐT có mà chưa có CLO
        // nào đo) chỉ cảnh báo: số của các PLO/PI đó sẽ thiếu phần của môn này.
        if ($program && $course && $cohort?->exists) {
            $ctdt = CourseOutcomeLevel::forCohort((int) $cohort->id);
            if ($ctdt !== []) {
                $allowed = $ctdt[$course->id] ?? [];
                if ($allowed === []) {
                    $e[] = "{$noi['mon']}: ma trận CTĐT của {$cohort->name} (sheet MaTran_CTDT trong file khai báo chương trình) chưa có môn {$course->code} — thêm dòng của môn vào ma trận rồi nhập lại phiếu.";
                } else {
                    $measured = [];
                    foreach ($clos as $row) {
                        foreach ($row['map'] as $code => $m) {
                            $t = $targets[$code] ?? null;
                            if ($t === null) {
                                continue; // mã cột sai đã báo ở trên
                            }
                            $ids = $t['type'] === 'pi'
                                ? [(int) $t['pi']->id]
                                : Pi::where('plo_id', $t['plo']->id)->where('is_dummy', true)->pluck('id')->map(fn ($v) => (int) $v)->all();
                            if (array_intersect($ids, array_keys($allowed)) === []) {
                                $e[] = "{$noi['clo']} · ô {$m['cell']}: CLO '{$row['code']}' nối vào {$code} nhưng ma trận CTĐT của {$cohort->name} không ghi môn {$course->code} đóng góp {$code} — sửa phiếu theo CTĐT, hoặc sửa ma trận ở file khai báo chương trình.";
                            }
                            foreach ($ids as $id) {
                                $measured[$id] = true;
                            }
                        }
                    }
                    $missing = array_diff_key($allowed, $measured);
                    if ($missing !== []) {
                        $codes = Pi::whereIn('id', array_keys($missing))->with('plo:id,code')->orderBy('id')->get()
                            ->map(fn (Pi $pi) => CloMatrixService::targetCode($pi))->unique()->implode(', ');
                        $warn[] = "Ma trận CTĐT ghi môn {$course->code} đóng góp {$codes} nhưng phiếu chưa có CLO nào đo — các PLO/PI này sẽ thiếu phần đóng góp của môn.";
                    }
                }
            }
        }

        // Bài đánh giá
        $assessments = [];
        $weightSum = 0.0;
        $weightsOk = true;
        if ($assessmentRows === []) {
            $e[] = "{$noi['dau']}: chưa khai bài đánh giá nào trong {$noi['khoi_danh_gia']}.";
        }
        foreach ($assessmentRows as $a) {
            $key = ClassSheetReader::slug($a['name']);
            if (isset($assessments[$key])) {
                $e[] = "{$noi['danh_gia']} · ô {$a['cell']}: bài đánh giá '{$a['name']}' khai trùng.";
                continue;
            }
            if ($a['weight_percent'] === null) {
                $e[] = "{$noi['danh_gia']} · ô {$a['weight_cell']}: bài '{$a['name']}' thiếu trọng số (hoặc không phải số).";
                $weightsOk = false;
            } elseif ($a['weight_percent'] < 0) {
                $e[] = "{$noi['danh_gia']} · ô {$a['weight_cell']}: trọng số không được âm.";
                $weightsOk = false;
            } else {
                $weightSum += $a['weight_percent'];
            }
            $assessments[$key] = $a + ['category' => $this->category($a['name'], $a['type'])];
        }
        if ($assessments !== [] && $weightsOk && abs($weightSum - 100) > 0.01) {
            $e[] = "{$noi['dau']}: tổng trọng số các bài đánh giá là " . $this->fmt($weightSum) . "% — phải đúng 100%.";
        }

        return [$targets, $clos, $assessments];
    }

    /**
     * Khai báo ĐÃ CÓ của đề cương, dựng lại đúng cấu trúc bước kiểm đọc từ phiếu.
     *
     * @return array{0: array<string,array<string,mixed>>, 1: array<string,array<string,mixed>>}
     */
    private function declarationsOf(CourseSyllabus $syllabus): array
    {
        $models = Clo::where('syllabus_id', $syllabus->id)->orderBy('id')->get();
        $maps = CloPiMapping::whereIn('clo_id', $models->pluck('id'))->with('pi.plo')->get()->groupBy('clo_id');

        $clos = [];
        foreach ($models as $clo) {
            $key = mb_strtoupper($clo->code);
            $map = [];
            foreach ($maps[$clo->id] ?? [] as $m) {
                if ($m->pi) {
                    $map[CloMatrixService::targetCode($m->pi)] = ['level' => $m->mapping_level, 'cell' => null];
                }
            }
            $clos[$key] = ['code' => $clo->code, 'description' => $clo->description, 'map' => $map, 'cell' => null, 'key' => $key];
        }

        $assessments = [];
        foreach (Assessment::where('syllabus_id', $syllabus->id)->orderBy('id')->get() as $a) {
            $assessments[ClassSheetReader::slug($a->name)] = [
                'name' => $a->name,
                'type' => null,
                'weight_percent' => (float) $a->weight_percent,
                'cell' => null,
                'weight_cell' => null,
                'category' => $a->assessment_category,
            ];
        }

        return [$clos, $assessments];
    }

    /**
     * Phiếu có khai CLO / bài đánh giá KHÁC với khai báo đã có không. Phiếu điền sẵn tải về chép
     * nguyên khai báo của hệ thống nên thường trùng — chỉ báo khi giáo viên đã sửa tay.
     *
     * @param array<string,mixed> $sheet
     * @param array<string,array<string,mixed>> $clos
     * @param array<string,array<string,mixed>> $assessments
     */
    private function phieuLechKhaiBao(array $sheet, array $clos, array $assessments): bool
    {
        $chuan = function (array $rows): array {
            $out = [];
            foreach ($rows as $r) {
                if (($r['code'] ?? null) === null) {
                    continue;
                }
                $map = [];
                foreach ($r['map'] as $code => $m) {
                    $map[mb_strtoupper((string) $code)] = mb_strtoupper((string) $m['level']);
                }
                ksort($map);
                $out[mb_strtoupper((string) $r['code'])] = $map;
            }
            ksort($out);

            return $out;
        };
        $bai = function (array $rows): array {
            $out = [];
            foreach ($rows as $a) {
                $out[ClassSheetReader::slug($a['name'])] = round((float) ($a['weight_percent'] ?? -1), 2);
            }
            ksort($out);

            return $out;
        };

        $phieuClo = $chuan($sheet['clos'] ?? []);
        $phieuBai = $bai($sheet['assessments'] ?? []);
        if ($phieuClo === [] && $phieuBai === []) {
            return false;
        }

        return $phieuClo !== $chuan(array_values($clos)) || $phieuBai !== $bai(array_values($assessments));
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,array<string,mixed>> $clos
     * @param array<string,array<string,mixed>> $targets
     * @param array<int,string> $e
     * @return array<string,mixed>
     */
    private function validateAssessmentSheet(string $title, array $s, array $clos, array $targets, array &$e, array &$warn = [], string $category = 'process'): array
    {
        if (! ($s['has_student_table'] ?? false)) {
            $e[] = "{$title}: không thấy dòng tiêu đề bảng điểm (cần cột 'MSSV').";
            return ['questions' => [], 'students' => []];
        }
        if (($s['questions'] ?? []) === []) {
            $e[] = "{$title}: chưa có câu hỏi nào — mỗi câu một cột bên phải cột Họ và tên.";
            return ['questions' => [], 'students' => []];
        }

        $questions = [];
        $maxSum = 0.0;
        $maxOk = true;
        $labels = [];
        foreach ($s['questions'] as $q) {
            $where = "{$title} · cột {$q['col']}";
            $label = mb_strtolower($q['label']);
            if (isset($labels[$label])) {
                $e[] = "{$where}: câu '{$q['label']}' trùng tên với cột {$labels[$label]} — mỗi câu một tên riêng.";
            }
            $labels[$label] = $q['col'];

            $max = ClassSheetReader::number($q['max']);
            if ($max === null || $max <= 0) {
                $e[] = ($q['max_cell'] ? "{$title} · ô {$q['max_cell']}" : $where) . ": câu '{$q['label']}' thiếu điểm tối đa (phải là số lớn hơn 0).";
                $maxOk = false;
            } else {
                $maxSum += $max;
            }

            $clo = $q['clo'] ? ($clos[mb_strtoupper($q['clo'])] ?? null) : null;
            if ($q['clo'] === null) {
                // Chuyên cần không phải lúc nào cũng gắn CLO trong đề cương: vẫn là điểm của môn, chỉ không đo
                // chuẩn đầu ra (WCML: khối vắng mặt thì chuẩn hóa trên các khối còn lại). Câu thi thì bắt buộc.
                if (str_contains(ClassSheetReader::slug($title . ' ' . ($q['type'] ?? '')), 'chuyen_can')) {
                    $warn[] = "{$title} · ô {$q['clo_cell']}: câu '{$q['label']}' không ghi CLO — điểm vẫn tính vào điểm môn nhưng không đo chuẩn đầu ra.";
                } else {
                    $e[] = "{$title} · ô {$q['clo_cell']}: câu '{$q['label']}' chưa ghi CLO cần đo — không có CLO thì câu này không đo được.";
                }
            } elseif ($clo === null) {
                $e[] = "{$title} · ô {$q['clo_cell']}: CLO '{$q['clo']}' của câu '{$q['label']}' không có trong khối 'Chuẩn đầu ra' ở sheet KhaiBao.";
            }

            // Dòng PLO/PI cần đo: hệ thống tự suy từ ma trận; trường có ghi tay thì phải khớp.
            if ($clo && $q['target']) {
                $mapped = array_map('mb_strtoupper', array_keys($clo['map']));
                foreach (preg_split('/[,;\s]+/', $q['target'], -1, PREG_SPLIT_NO_EMPTY) as $t) {
                    if (! in_array(mb_strtoupper($t), $mapped, true)) {
                        $e[] = "{$where}: câu '{$q['label']}' ghi đo '{$t}', nhưng ma trận ở KhaiBao không nối {$clo['code']} vào '{$t}' — sửa cho khớp, hoặc để trống dòng PLO/PI cần đo.";
                    }
                }
            }

            $questions[$q['col']] = $q + ['max_value' => $max, 'clo_key' => $clo['key'] ?? null];
        }
        if ($maxOk && abs($maxSum - 10) > self::EPS) {
            $e[] = "{$title}: tổng điểm tối đa các câu là " . $this->fmt($maxSum) . ' — phải đúng 10.';
        }

        $students = [];
        $seen = [];
        foreach ($s['students'] as $st) {
            $where = "{$title} · dòng {$st['row']}";
            if ($st['mssv'] === null) {
                $e[] = "{$where}: thiếu MSSV.";
                continue;
            }
            if (isset($seen[$st['mssv']])) {
                $e[] = "{$where}: MSSV {$st['mssv']} lặp lại (đã có ở dòng {$seen[$st['mssv']]}).";
                continue;
            }
            $seen[$st['mssv']] = $st['row'];

            $scores = [];
            $vt = false;
            foreach ($st['scores'] as $col => $raw) {
                if ($raw === null || ! isset($questions[$col])) {
                    continue;
                }
                $cell = "{$title} · ô {$col}{$st['row']}";
                if (self::isVangThi($raw)) {
                    // Vắng thi: KHÔNG phải 0 điểm. Câu đó không có bằng chứng nên để ngoài phép đo.
                    $vt = true;
                    continue;
                }
                $v = ClassSheetReader::number($raw);
                if ($v === null) {
                    $e[] = "{$cell}: điểm '{$raw}' không phải số (vắng thi thì ghi VT).";
                    continue;
                }
                $max = $questions[$col]['max_value'];
                if ($v < 0 || ($max !== null && $v > $max + self::EPS)) {
                    $e[] = "{$cell}: điểm {$this->fmt($v)} vượt điểm tối đa " . $this->fmt((float) $max) . " của câu '{$questions[$col]['label']}'.";
                    continue;
                }
                $scores[$col] = $v;
            }

            $absent = $vt && $scores === [];
            if ($vt && ! $absent) {
                $warn[] = "{$where}: MSSV {$st['mssv']} vừa có điểm vừa ghi VT — các câu ghi VT không tính, các câu có điểm vẫn tính.";
            }
            $students[] = $st + ['score_values' => $scores, 'absent' => $absent];
        }

        $vangThi = array_column(array_filter($students, fn ($st) => $st['absent']), 'mssv');
        if ($vangThi !== []) {
            $warn[] = $category === 'final'
                ? "{$title}: " . count($vangThi) . ' học viên vắng thi (' . implode(', ', $vangThi) . ') — chưa tính vào cỡ mẫu của môn cho tới khi có điểm thi lại (nộp phiếu bổ sung).'
                : "{$title}: " . count($vangThi) . ' học viên vắng bài này (' . implode(', ', $vangThi) . ') — bài này không tính cho các bạn đó.';
        }

        return ['questions' => $questions, 'students' => $students];
    }

    /**
     * Mã ở tiêu đề ma trận là PI của ngành, hoặc PLO.
     * Trường nối thẳng CLO→PLO (đề cương thường ghi vậy) thì PLO đó được đo trực tiếp — chỉ khi PLO
     * không có PI nào. PLO đã chia PI thì phải nối vào PI cụ thể, không thì không biết chia về PI nào.
     *
     * @param array<string,string> $headers cột → mã
     * @param array<int,string> $e
     * @return array<string,array{type:string,pi:?Pi,plo:?Plo,code:string}>
     */
    private function resolveTargets(array $headers, Program $program, array &$e, string $sheetLabel = ClassSheetReader::INFO_SHEET): array
    {
        $out = [];

        foreach ($headers as $col => $code) {
            $t = $this->matrix->resolve($program, $code);
            if ($t['error'] !== null) {
                $e[] = "{$sheetLabel} · ô {$col}: {$t['error']}";
                continue;
            }
            $out[$code] = $t + ['code' => $code];
        }

        return $out;
    }

    // ── GHI ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $sheet
     * @param array<string,mixed> $ctx
     * @return array{0: array<string,int>, 1: array<int,array{0:Student,1:CourseClass}>}
     */
    private function apply(array $sheet, array $ctx, int $schoolId): array
    {
        /** @var Program $program */
        $program = $ctx['program'];
        /** @var Course $course */
        $course = $ctx['course'];
        $info = $sheet['info'];
        $boSung = $ctx['boSung'];
        $stats = ['clo' => 0, 'anh_xa' => 0, 'bai_danh_gia' => 0, 'cau_hoi' => 0, 'sinh_vien' => 0, 'diem' => 0, 'vang_thi' => 0];

        $cohort = $ctx['cohort']->exists ? $ctx['cohort'] : tap($ctx['cohort'])->save();

        $before = null;
        if ($ctx['theoHeThong']) {
            // Khung theo đợt / phiếu bổ sung: khai báo đã có sẵn, phiếu không được đụng tới.
            $syllabus = $ctx['syllabus'];
            [$cloModels, $assessmentModels] = $this->modelsOf($syllabus);
        } else {
            $program->courses()->syncWithoutDetaching([$course->id]); // không ghi đè cờ bắt buộc/tự chọn đã khai
            $syllabus = $this->syllabi->forCourse($course, $cohort);

            // CLO, ma trận và trọng số bài treo vào ĐỀ CƯƠNG — dùng chung cho mọi lớp cùng môn, cùng khóa.
            // Chụp lại trước khi ghi: phiếu lớp này đổi chúng thì số của các lớp đã nhập trước cũng đổi,
            // và các lớp đó phải được tính lại (không thì số cũ vẫn hiện, trông hợp lý, mà sai).
            $before = $this->syllabusFingerprint($syllabus->id);
            [$cloModels, $assessmentModels] = $this->applyDeclarations($syllabus, $ctx['targets'], $ctx['clos'], $ctx['assessments'], $stats);
        }

        // Lớp đã có trong khung (hoặc phiếu bổ sung) thì giữ nguyên thông tin lớp — khung là nguồn đúng.
        $class = $ctx['theoHeThong'] && $ctx['existing'] !== null
            ? $ctx['existing']
            : CourseClass::updateOrCreate(
                ['school_id' => $schoolId, 'class_code' => $info['ma_lop']],
                [
                    'syllabus_id' => $syllabus->id,
                    'cohort_id' => $cohort->id,
                    'lecturer_id' => $this->lecturer((string) $info['giang_vien'], $schoolId)->id,
                    'academic_year' => $info['nam_hoc'] ?? ($cohort->start_year . '-' . ($cohort->start_year + 1)),
                    'semester' => (int) (ClassSheetReader::number($info['hoc_ky'] ?? null) ?? 1),
                ],
            );

        if ($cohort->grade_granularity !== 'question') {
            $cohort->update(['grade_granularity' => 'question']);
        }

        // Sinh viên có điểm TRƯỚC khi thay — họ cũng phải được tính lại nếu phiếu mới bỏ họ ra.
        // Phiếu bổ sung không thay ai ngoài những bạn có trong phiếu, nên không cần.
        $pairs = [];
        if (! $boSung) {
            foreach (Student::whereIn('id', $this->studentsWithScores($class->id))->get() as $st) {
                $pairs[$st->id . '-' . $class->id] = [$st, $class];
            }
        }

        $studentCache = [];
        foreach ($ctx['assessments'] as $key => $a) {
            if (! isset($a['parsed'])) {
                continue; // phiếu bổ sung không có sheet của bài này
            }
            $assessment = $assessmentModels[$key];
            $stats['bai_danh_gia']++;

            $rubric = Rubric::firstOrCreate(['assessment_id' => $assessment->id], ['name' => 'Câu hỏi ' . $assessment->name]);

            $criteria = [];
            foreach ($a['parsed']['questions'] as $col => $q) {
                if ($q['clo_key'] === null) {
                    continue; // chuyên cần không gắn CLO: chỉ vào điểm bài, không có tiêu chí đo
                }
                $clo = $cloModels[$q['clo_key']];
                $criteria[$col] = $boSung
                    ? $this->criteriaBoSung($rubric, $class, $q, $clo)
                    : RubricCriteria::updateOrCreate(
                        ['rubric_id' => $rubric->id, 'class_id' => $class->id, 'question_label' => $q['label']],
                        [
                            'clo_id' => $clo->id,
                            'question_type' => $q['type'],
                            'max_score' => $q['max_value'],
                            'description' => trim($q['label'] . ($q['type'] ? " · {$q['type']}" : '') . " · đo {$clo->code}"),
                        ],
                    );
                $stats['cau_hoi']++;
            }

            $allCriteria = RubricCriteria::where('rubric_id', $rubric->id)->pluck('id');
            if ($boSung) {
                // Chỉ thay điểm của đúng những bạn có trong phiếu bổ sung; điểm các bạn khác giữ nguyên.
                $ids = Student::where('school_id', $schoolId)
                    ->whereIn('student_code', array_map('strval', array_column($a['parsed']['students'], 'mssv')))
                    ->pluck('id');
                StudentRubricScore::where('class_id', $class->id)->whereIn('rubric_criteria_id', $allCriteria)->whereIn('student_id', $ids)->delete();
                StudentGrade::where('class_id', $class->id)->where('assessment_id', $assessment->id)->whereIn('student_id', $ids)->delete();
            } else {
                // Câu hỏi của RIÊNG lớp này; câu cũ không còn trên phiếu thì bỏ.
                $keepIds = array_map(fn ($c) => $c->id, $criteria);
                RubricCriteria::where('rubric_id', $rubric->id)->where('class_id', $class->id)->whereNotIn('id', $keepIds)->delete();

                // Thay hẳn điểm của lớp cho bài này — gồm cả điểm nhập theo cách cũ (tiêu chí dùng chung).
                StudentRubricScore::where('class_id', $class->id)->whereIn('rubric_criteria_id', $allCriteria)->delete();
                StudentGrade::where('class_id', $class->id)->where('assessment_id', $assessment->id)->delete();
            }

            foreach ($a['parsed']['students'] as $st) {
                if ($boSung && $st['score_values'] === [] && ! $st['absent']) {
                    continue; // dòng trống trong phiếu bổ sung: bạn này không thi lại, không đụng tới
                }
                $student = $studentCache[$st['mssv']] ??= $this->student($st, $schoolId, $program, $cohort, $stats);
                $class->students()->syncWithoutDetaching([$student->id]);
                $pairs[$student->id . '-' . $class->id] = [$student, $class];

                if ($st['score_values'] === []) {
                    if ($st['absent']) {
                        // Vắng thi: lưu lại để phân biệt với "chưa có điểm" và để loại khỏi cỡ mẫu.
                        StudentGrade::create([
                            'student_id' => $student->id,
                            'class_id' => $class->id,
                            'assessment_id' => $assessment->id,
                            'score' => null,
                            'is_absent' => true,
                            'is_legacy_data' => false,
                        ]);
                        $stats['vang_thi']++;
                    }
                    continue;
                }
                foreach ($st['score_values'] as $col => $v) {
                    if (! isset($criteria[$col])) {
                        continue;
                    }
                    StudentRubricScore::create([
                        'student_id' => $student->id,
                        'class_id' => $class->id,
                        'rubric_criteria_id' => $criteria[$col]->id,
                        'score' => $v,
                    ]);
                    $stats['diem']++;
                }

                // Điểm bài = tổng điểm đạt các câu (điểm tối đa cộng lại đúng 10).
                StudentGrade::create([
                    'student_id' => $student->id,
                    'class_id' => $class->id,
                    'assessment_id' => $assessment->id,
                    'score' => round(array_sum($st['score_values']), 4),
                    'is_legacy_data' => false,
                ]);
            }
        }

        $stats['sinh_vien_trong_phieu'] = count($studentCache);

        $warnings = [];
        if ($before !== null) {
            $warnings = $this->recalcSiblings($syllabus, $class, $course, $cohort, $before, $pairs);
        }

        return [$stats, array_values($pairs), $warnings];
    }

    /**
     * Ghi PHẦN KHAI BÁO của một môn vào đề cương: CLO, ma trận CLO → PLO/PI, bài đánh giá.
     * Phiếu lớp và file khung theo đợt cùng ghi qua đây.
     *
     * @param array<string,array<string,mixed>> $targets
     * @param array<string,array<string,mixed>> $clos
     * @param array<string,array<string,mixed>> $assessments
     * @param array<string,int> $stats
     * @return array{0: array<string,Clo>, 1: array<string,Assessment>}
     */
    public function applyDeclarations(CourseSyllabus $syllabus, array $targets, array $clos, array $assessments, array &$stats): array
    {
        // PLO nối thẳng ⇒ lưu bằng một dòng `pis` MÃ TRÙNG MÃ PLO, nên mọi bảng, phiếu và file xuất in ra
        // "PLO2". Khách chốt không có khái niệm PI ảo: đây chỉ là cách lưu. PLO đã chia PI thì không tới
        // được đây (bước kiểm chặn trước).
        $piFor = [];
        foreach ($targets as $code => $t) {
            $piFor[$code] = $this->matrix->piFor($t);
        }

        // CLO + ma trận: phần khai báo là nguồn đúng của ánh xạ từng CLO nó khai.
        $cloModels = [];
        foreach ($clos as $key => $row) {
            $clo = Clo::updateOrCreate(
                ['syllabus_id' => $syllabus->id, 'code' => $row['code']],
                ['description' => $row['description'] ?? $row['code']],
            );
            $cloModels[$key] = $clo;
            $stats['clo'] = ($stats['clo'] ?? 0) + 1;

            $levels = [];
            foreach ($row['map'] as $code => $m) {
                if (isset($piFor[$code])) {
                    $levels[$piFor[$code]->id] = $m['level'];
                }
            }
            $stats['anh_xa'] = ($stats['anh_xa'] ?? 0) + $this->matrix->sync($clo, $levels);
        }

        $assessmentModels = [];
        foreach ($assessments as $key => $a) {
            $assessmentModels[$key] = Assessment::updateOrCreate(
                ['syllabus_id' => $syllabus->id, 'name' => $a['name']],
                ['assessment_category' => $a['category'], 'weight_percent' => $a['weight_percent'], 'is_rubric_based' => true],
            );
        }

        return [$cloModels, $assessmentModels];
    }

    /**
     * CLO và bài đánh giá ĐÃ CÓ của đề cương, đánh khóa giống hệt bước kiểm (mã CLO viết hoa, tên bài
     * chuẩn hóa).
     *
     * @return array{0: array<string,Clo>, 1: array<string,Assessment>}
     */
    private function modelsOf(CourseSyllabus $syllabus): array
    {
        $clos = [];
        foreach (Clo::where('syllabus_id', $syllabus->id)->get() as $clo) {
            $clos[mb_strtoupper($clo->code)] = $clo;
        }
        $assessments = [];
        foreach (Assessment::where('syllabus_id', $syllabus->id)->get() as $a) {
            $assessments[ClassSheetReader::slug($a->name)] = $a;
        }

        return [$clos, $assessments];
    }

    /**
     * Câu hỏi của phiếu bổ sung. Đề thi lại thường khác đề chính: câu cùng tên mà khác điểm tối đa hoặc
     * khác CLO thì KHÔNG được ghi đè câu cũ — 38 bạn thi đề chính vẫn đang chấm theo câu cũ. Câu khác
     * thì lưu thành câu riêng "Câu 1 (bổ sung)", "Câu 1 (bổ sung 2)"…; câu giống hệt thì dùng lại.
     *
     * @param array<string,mixed> $q
     */
    private function criteriaBoSung(Rubric $rubric, CourseClass $class, array $q, Clo $clo): RubricCriteria
    {
        for ($n = 1; ; $n++) {
            $label = match (true) {
                $n === 1 => $q['label'],
                $n === 2 => "{$q['label']} (bổ sung)",
                default => "{$q['label']} (bổ sung " . ($n - 1) . ')',
            };
            $found = RubricCriteria::where('rubric_id', $rubric->id)->where('class_id', $class->id)
                ->where('question_label', $label)->first();

            if ($found === null) {
                return RubricCriteria::create([
                    'rubric_id' => $rubric->id,
                    'class_id' => $class->id,
                    'question_label' => $label,
                    'clo_id' => $clo->id,
                    'question_type' => $q['type'],
                    'max_score' => $q['max_value'],
                    'description' => trim($label . ($q['type'] ? " · {$q['type']}" : '') . " · đo {$clo->code}"),
                ]);
            }
            if ((int) $found->clo_id === (int) $clo->id && abs((float) $found->max_score - (float) $q['max_value']) < self::EPS) {
                return $found;
            }
        }
    }

    /**
     * Khai báo của môn vừa đổi ⇒ các lớp khác cùng môn, cùng khóa (dùng chung đề cương) phải tính lại.
     *
     * @param array{map: array<int,string>, assessments: array<int,string>} $before
     * @param array<string,array{0:Student,1:CourseClass}> $pairs
     * @return array<int,string>
     */
    public function recalcSiblings(CourseSyllabus $syllabus, ?CourseClass $class, Course $course, Cohort $cohort, array $before, array &$pairs, string $nguon = 'Phiếu này', string $sua = 'sửa lại phiếu và tải lên lại'): array
    {
        if ($before === $this->syllabusFingerprint($syllabus->id)) {
            return [];
        }

        $others = CourseClass::where('syllabus_id', $syllabus->id)
            ->when($class !== null, fn ($q) => $q->where('id', '!=', $class->id))
            ->orderBy('class_code')->get();
        if ($others->isEmpty()) {
            return [];
        }

        foreach ($others as $other) {
            foreach (Student::whereIn('id', $this->studentsWithScores($other->id))->get() as $st) {
                $pairs[$st->id . '-' . $other->id] = [$st, $other];
            }
        }

        return ["{$nguon} làm ĐỔI CLO, ma trận chuẩn đầu ra hoặc trọng số bài của môn {$course->code} ({$cohort->name}). "
            . 'Các lớp cùng môn, cùng khóa dùng chung phần khai báo này nên đã được tính lại theo khai báo mới: '
            . $others->pluck('class_code')->implode(', ') . ". Nếu thay đổi là nhầm, {$sua}."];
    }

    // ── Phụ trợ ─────────────────────────────────────────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<int,int> */
    private function studentsWithScores(int $classId): \Illuminate\Support\Collection
    {
        return StudentRubricScore::where('class_id', $classId)->distinct()->pluck('student_id')
            ->merge(StudentGrade::where('class_id', $classId)->distinct()->pluck('student_id'))
            ->unique()->values();
    }

    /**
     * Mọi thứ của đề cương ẢNH HƯỞNG TỚI SỐ ĐO hoặc điểm số: CLO nối PI nào ở mức nào, bài nào
     * loại gì trọng số bao nhiêu. Mô tả chữ không tính.
     *
     * @return array{map: array<int,string>, assessments: array<int,string>}
     */
    public function syllabusFingerprint(int $syllabusId): array
    {
        $clos = Clo::where('syllabus_id', $syllabusId)->pluck('code', 'id');
        $map = CloPiMapping::whereIn('clo_id', $clos->keys())->with('pi:id,code')->get()
            ->map(fn ($m) => ($clos[$m->clo_id] ?? '?') . '>' . ($m->pi?->code ?? '?') . ':' . $m->mapping_level)
            ->sort()->values()->all();
        $assessments = Assessment::where('syllabus_id', $syllabusId)->get()
            ->map(fn ($a) => $a->name . '|' . $a->assessment_category . '|' . rtrim(rtrim(number_format((float) $a->weight_percent, 2, '.', ''), '0'), '.'))
            ->sort()->values()->all();

        return ['map' => $map, 'assessments' => $assessments];
    }

    /** Khóa: tên đã khai, hoặc năm bắt đầu. Chưa có thì trả bản chưa lưu (tạo lúc ghi). */
    public function resolveCohort(Program $program, string $raw): ?Cohort
    {
        $byName = Cohort::where('program_id', $program->id)->where('name', $raw)->first();
        if ($byName) {
            return $byName;
        }
        if (! preg_match('/(?:19|20)\d{2}/', $raw, $m)) {
            return null;
        }
        $year = (int) $m[0];

        return Cohort::where('program_id', $program->id)->where('start_year', $year)->orderBy('id')->first()
            ?? new Cohort(['program_id' => $program->id, 'name' => 'Khóa ' . $year, 'start_year' => $year, 'school_id' => $program->school_id]);
    }

    /** Ô điểm ghi vắng thi: VT · V · "vắng" · "vắng thi" · "không thi". */
    public static function isVangThi(mixed $raw): bool
    {
        $slug = ClassSheetReader::slug((string) $raw);

        return in_array($slug, ['vt', 'v', 'vang', 'vang_thi', 'khong_thi', 'kt'], true);
    }

    private function category(string $name, ?string $type): string
    {
        $t = ClassSheetReader::slug($type ?? '');
        if ($t !== '') {
            return (str_contains($t, 'cuoi') || $t === 'ck' || $t === 'final') ? 'final' : 'process';
        }
        $n = ClassSheetReader::slug($name);

        return (str_contains($n, 'cuoi_ky') || $n === 'ck' || str_starts_with($n, 'ck_') || str_contains($n, 'final')) ? 'final' : 'process';
    }

    /** @param array<int,string> $titles */
    private function sheetFor(string $name, array $titles): ?string
    {
        $want = ClassSheetReader::slug($name);
        // Excel giới hạn tên sheet 31 ký tự — so thêm bản cắt ngắn.
        $wantShort = ClassSheetReader::slug(mb_substr($name, 0, 31));
        foreach ($titles as $t) {
            $s = ClassSheetReader::slug($t);
            if ($s === $want || $s === $wantShort) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Giảng viên theo tên hoặc mã trong trường. Chưa có thì tạo tài khoản KHÔNG đăng nhập được —
     * lớp bắt buộc gắn giảng viên, nhưng khách chỉ ghi tên, không ai phải đi tạo tài khoản trước.
     */
    public function lecturer(string $name, int $schoolId): User
    {
        $found = User::where('school_id', $schoolId)
            ->where(fn ($q) => $q->where('staff_code', $name)->orWhereRaw('LOWER(full_name) = ?', [mb_strtolower($name)]))
            ->first();
        if ($found) {
            return $found;
        }

        $code = 'GV-' . $schoolId . '-' . substr(sha1(mb_strtolower($name)), 0, 8);
        $user = User::firstOrCreate(
            ['staff_code' => $code],
            [
                'full_name' => $name,
                'email' => strtolower($code) . '@phieu.local',
                'password' => bcrypt(Str::random(40)),
                'school_id' => $schoolId,
                'is_active' => false,
            ],
        );
        if (Role::where('name', 'lecturer')->exists() && ! $user->hasRole('lecturer')) {
            $user->assignRole('lecturer');
        }

        return $user;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int> $stats
     */
    private function student(array $row, int $schoolId, Program $program, Cohort $cohort, array &$stats): Student
    {
        $student = Student::firstOrCreate(
            ['school_id' => $schoolId, 'student_code' => (string) $row['mssv']],
            [
                'full_name' => $row['name'] ?? (string) $row['mssv'],
                'cohort_id' => $cohort->id,
                'program_id' => $program->id,
                'status' => 'studying',
            ],
        );
        if ($student->wasRecentlyCreated) {
            $stats['sinh_vien']++;
        }

        return $student;
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, ',', ''), '0'), ',');
    }
}
