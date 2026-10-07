<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Assessment;
use App\Models\Clo;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\RubricCriteria;
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Models\StudentRubricScore;
use App\Services\Import\ClassSheetReader;
use Illuminate\Support\Collection;

/**
 * BÁO CÁO HỌC PHẦN — số liệu cho mẫu báo cáo của trường (mẫu Word khách gửi 22/09/2026).
 *
 * Khách chốt: "mẫu này lấy dữ liệu của phần mềm, mà đo theo HỌC PHẦN chứ không phải theo lớp học phần".
 * Vì vậy phạm vi là (học phần, năm học, học kỳ): gộp mọi lớp của học phần trong học kỳ đó, còn bảng
 * chi tiết từng CLO vẫn tách cột theo lớp đúng như mẫu.
 *
 * Cách đọc các con số:
 *  - Mỗi dòng "phương pháp đánh giá" là một nhóm câu hỏi cùng hình thức trong cùng một bài (ví dụ
 *    "Trắc nghiệm, Giữa kỳ, Câu 1 – Câu 20"); ĐẠT nghĩa là điểm nhóm câu đó ≥ ngưỡng đạt CLO.
 *  - Dòng TỔNG CỘNG không phải tổng các dòng trên mà là số sinh viên ĐẠT CLO của cả học phần, đúng
 *    như mẫu của trường: CLO1 có 2 phương pháp × 2 lớp nhưng tổng cộng vẫn là 64 sinh viên.
 *  - Ngưỡng lấy theo bậc đào tạo của ngành (đại học 4,0 · thạc sĩ 5,5) nếu trường chưa khai riêng.
 */
final class CourseReportService
{
    /**
     * Bảng "Phương pháp giảng dạy và đánh giá" trong mẫu của trường là bảng in sẵn 9 dòng, giảng viên
     * chỉ đánh dấu X. Hệ thống đánh dấu giúp bằng cách đối chiếu hình thức câu hỏi ghi trong phiếu lớp
     * học phần với các dòng này; hình thức nào không khớp dòng nào thì thêm một dòng mang đúng tên đã
     * ghi trong phiếu, để không im lặng bỏ mất dữ liệu.
     */
    private const METHOD_TABLE = [
        ['ten' => 'Chuyên cần', 'slugs' => ['chuyen_can', 'diem_danh', 'cc']],
        ['ten' => 'Hồ sơ học tập', 'slugs' => ['ho_so_hoc_tap', 'portfolio']],
        ['ten' => 'Thảo luận tại lớp', 'slugs' => ['thao_luan', 'thao_luan_tai_lop']],
        ['ten' => 'Làm việc, báo cáo nhóm', 'slugs' => ['nhom', 'lam_viec_nhom', 'bai_tap_nhom', 'bao_cao_nhom', 'thuyet_trinh', 'thuyet_trinh_nhom']],
        ['ten' => 'Báo cáo cá nhân', 'slugs' => ['bao_cao_ca_nhan', 'ca_nhan']],
        ['ten' => 'Tiểu luận', 'slugs' => ['tieu_luan']],
        ['ten' => 'Thực hành', 'slugs' => ['thuc_hanh']],
        ['ten' => 'Tự luận', 'slugs' => ['tu_luan', 'tl']],
        ['ten' => 'Trắc nghiệm', 'slugs' => ['trac_nghiem', 'tn']],
    ];

    /**
     * @return array<string,mixed>|null null ⇒ học phần không có lớp nào trong phạm vi đã chọn
     */
    public function build(Course $course, ?string $academicYear = null, ?int $semester = null, ?array $onlyClassIds = null): ?array
    {
        $classes = $this->classesOf($course, $academicYear, $semester, $onlyClassIds);

        if ($classes->isEmpty()) {
            return null;
        }

        $program = $classes->pluck('cohort.program')->filter()->first();

        return ObeConfig::forProgram(
            $course->school_id,
            $program?->level,
            fn () => $this->compose($course, $classes, $program, $academicYear, $semester),
        );
    }

    /**
     * @param array<int,int>|null $onlyClassIds giới hạn theo quyền (giảng viên chỉ thấy lớp mình dạy);
     *                                          null nghĩa là không giới hạn
     * @return Collection<int,CourseClass>
     */
    public function classesOf(Course $course, ?string $academicYear = null, ?int $semester = null, ?array $onlyClassIds = null): Collection
    {
        $syllabusIds = CourseSyllabus::where('course_id', $course->id)->pluck('id');

        return CourseClass::whereIn('syllabus_id', $syllabusIds)
            ->when($onlyClassIds !== null, fn ($q) => $q->whereIn('id', $onlyClassIds))
            ->when($academicYear, fn ($q) => $q->where('academic_year', $academicYear))
            ->when($semester, fn ($q) => $q->where('semester', $semester))
            ->with(['lecturer:id,full_name', 'cohort.program.department'])
            ->orderBy('class_code')
            ->get();
    }

    /**
     * Năm học và học kỳ mà học phần này đã có lớp — để màn hình cho chọn.
     *
     * @return array<int,array{nam_hoc:?string,hoc_ky:?int,so_lop:int}>
     */
    public function availableTerms(Course $course, ?array $onlyClassIds = null): array
    {
        $syllabusIds = CourseSyllabus::where('course_id', $course->id)->pluck('id');

        return CourseClass::whereIn('syllabus_id', $syllabusIds)
            ->when($onlyClassIds !== null, fn ($q) => $q->whereIn('id', $onlyClassIds))
            ->selectRaw('academic_year, semester, count(*) as so_lop')
            ->groupBy('academic_year', 'semester')
            ->orderByDesc('academic_year')->orderByDesc('semester')
            ->get()
            ->map(fn ($r) => [
                'nam_hoc' => $r->academic_year === null ? null : (string) $r->academic_year,
                'hoc_ky' => $r->semester === null ? null : (int) $r->semester,
                'so_lop' => (int) $r->so_lop,
            ])
            ->all();
    }

    /**
     * @param Collection<int,CourseClass> $classes
     * @return array<string,mixed>
     */
    private function compose(Course $course, Collection $classes, ?Program $program, ?string $academicYear, ?int $semester): array
    {
        $classIds = $classes->pluck('id');
        $syllabusIds = $classes->pluck('syllabus_id')->unique();

        $clos = Clo::whereIn('syllabus_id', $syllabusIds)->orderBy('code')->get();
        $assessments = Assessment::whereIn('syllabus_id', $syllabusIds)->with('rubric:id,assessment_id')->orderBy('id')->get();

        /** @var array<int,Assessment> rubric_id → bài đánh giá */
        $assessmentByRubric = [];
        foreach ($assessments as $a) {
            if ($a->rubric) {
                $assessmentByRubric[(int) $a->rubric->id] = $a;
            }
        }

        $criteria = RubricCriteria::whereIn('rubric_id', array_keys($assessmentByRubric))->get();
        $scores = StudentRubricScore::whereIn('class_id', $classIds)->get(['student_id', 'class_id', 'rubric_criteria_id', 'score']);
        $grades = StudentGrade::whereIn('class_id', $classIds)->whereNotNull('score')->get(['student_id', 'class_id', 'assessment_id', 'score']);
        $cloResults = StudentCloResult::whereIn('class_id', $classIds)->get(['student_id', 'class_id', 'clo_id', 'achieved_score']);

        // Tra điểm bằng khóa chuỗi: bảng điểm câu của một học phần lớn có hàng chục nghìn dòng, dò
        // tuyến tính cho từng sinh viên × từng câu sẽ chạy rất lâu.
        $scoreIndex = [];
        foreach ($scores as $s) {
            $scoreIndex[$s->class_id . ':' . $s->student_id . ':' . $s->rubric_criteria_id] = (float) $s->score;
        }
        $gradeIndex = [];
        foreach ($grades as $g) {
            $gradeIndex[$g->class_id . ':' . $g->student_id . ':' . $g->assessment_id] = (float) $g->score;
        }

        // Sinh viên của học phần: ưu tiên danh sách ghi danh, thiếu thì lấy người đã có điểm.
        $studentsByClass = [];
        foreach (Enrollment::whereIn('class_id', $classIds)->get(['class_id', 'student_id']) as $row) {
            $studentsByClass[(int) $row->class_id][(int) $row->student_id] = true;
        }
        foreach ($scores as $row) {
            $studentsByClass[(int) $row->class_id][(int) $row->student_id] = true;
        }
        foreach ($grades as $row) {
            $studentsByClass[(int) $row->class_id][(int) $row->student_id] = true;
        }

        // Vắng thi cuối kỳ, chưa thi lại: đứng ngoài cỡ mẫu của học phần — kể cả ở các dòng quá trình,
        // để mọi dòng của một CLO đo trên cùng một nhóm học viên với dòng Tổng cộng.
        $vangThi = StudentGrade::vangThiCuoiKy([], $classIds->map(fn ($id) => (int) $id)->all());

        $cloPass = ObeConfig::cloPassThreshold();
        $coursePass = ObeConfig::coursePassThreshold();
        $target = ObeConfig::targetAttainmentPercent();

        // ── Thống kê chung ───────────────────────────────────────────────────────────────────
        $finalIds = $assessments->where('assessment_category', 'final')->pluck('id');
        $duThi = $grades->whereIn('assessment_id', $finalIds)->pluck('student_id')->unique()->count();
        $dangKy = collect($studentsByClass)->sum(fn ($ids) => count($ids));

        $marks = $this->courseMarks($classes, $assessments, $gradeIndex, $studentsByClass);
        $quaMon = count(array_filter($marks, fn ($m) => $m !== null && $m >= $coursePass));

        // ── Phương pháp giảng dạy và đánh giá ────────────────────────────────────────────────
        $phuongPhap = $this->methodTable($criteria, $clos, $assessmentByRubric);

        // ── Từng CLO ─────────────────────────────────────────────────────────────────────────
        $cloRows = [];
        foreach ($clos as $clo) {
            $rows = [];
            $groups = $criteria->where('clo_id', $clo->id)
                ->groupBy(fn (RubricCriteria $c) => $c->rubric_id . '|' . $this->typeOf($c));

            foreach ($groups as $group) {
                $assessment = $assessmentByRubric[(int) $group->first()->rubric_id] ?? null;
                $perClass = [];

                foreach ($classes as $class) {
                    // Tiêu chí có class_id là câu riêng của một lớp; để trống nghĩa là dùng chung.
                    $inClass = $group->filter(fn ($c) => $c->class_id === null || (int) $c->class_id === (int) $class->id);
                    if ($inClass->isEmpty()) {
                        continue;
                    }

                    $dat = 0;
                    $khongDat = 0;
                    foreach (array_keys($studentsByClass[(int) $class->id] ?? []) as $studentId) {
                        if (isset($vangThi[(int) $class->id][$studentId])) {
                            continue;
                        }
                        $value = $this->groupScore($inClass, $scoreIndex, $studentId, (int) $class->id);
                        if ($value === null) {
                            continue;
                        }
                        $value >= $cloPass ? $dat++ : $khongDat++;
                    }

                    if ($dat + $khongDat > 0) {
                        $perClass[] = ['lop' => $class->class_code, 'dat' => $dat, 'khong_dat' => $khongDat];
                    }
                }

                if ($perClass !== []) {
                    $rows[] = [
                        'phuong_phap' => $this->methodLabel($group, $assessment),
                        'lop' => $perClass,
                    ];
                }
            }

            $results = $cloResults->where('clo_id', $clo->id);
            $doDuoc = $results->count();
            $dat = $results->filter(fn ($r) => (float) $r->achieved_score >= $cloPass)->count();
            $tyLe = $doDuoc > 0 ? round($dat / $doDuoc * 100, 2) : null;

            $cloRows[] = [
                'code' => $clo->code,
                'description' => $clo->description,
                'muc_tieu' => $target,
                'dong' => $rows,
                'tong' => [
                    'do' => $doDuoc,
                    'dat' => $dat,
                    'khong_dat' => $doDuoc - $dat,
                    'ty_le_dat' => $tyLe,
                    'ty_le_khong_dat' => $tyLe === null ? null : round(100 - $tyLe, 2),
                    'ket_luan' => $tyLe !== null && $tyLe >= $target ? 'Đạt' : 'Chưa đạt',
                ],
            ];
        }

        return [
            'hoc_phan' => [
                'ma' => $course->code,
                'ten' => $course->name,
                'tin_chi' => (float) $course->credits,
                'so_tiet' => $course->periods,
                'bo_mon' => $course->managing_unit,
            ],
            'khoa' => $program?->department?->name,
            'nganh' => $program ? $program->code . ' - ' . $program->name : null,
            'bac' => $program?->level,
            'nam_hoc' => $academicYear ?? $classes->pluck('academic_year')->filter()->unique()->implode(', '),
            'hoc_ky' => $semester ?? ($classes->first()->semester === null ? null : (int) $classes->first()->semester),
            'lop' => $classes->pluck('class_code')->all(),
            'giang_vien' => $classes->pluck('lecturer.full_name')->filter()->unique()->values()->all(),
            'nguong' => ['clo' => $cloPass, 'mon' => $coursePass, 'muc_tieu' => $target],
            'thong_ke' => [
                'dang_ky' => $dangKy,
                'du_thi_cuoi_ky' => $duThi,
                'vang_thi' => max(0, $dangKy - $duThi),
                'qua_mon' => $quaMon,
                'ty_le_qua_mon' => $dangKy > 0 ? round($quaMon / $dangKy * 100, 2) : null,
            ],
            'phuong_phap' => $phuongPhap['rows'],
            'phuong_phap_chua_khai' => $phuongPhap['chua_khai'],
            'clos' => $cloRows,
        ];
    }

    /**
     * Bảng phương pháp: dòng nào có câu hỏi thì đánh dấu, kèm các CLO và tên bài đã dùng nó.
     *
     * @param Collection<int,RubricCriteria> $criteria
     * @param Collection<int,Clo> $clos
     * @param array<int,Assessment> $assessmentByRubric
     * @return array{rows:array<int,array{ten:string,co_su_dung:bool,clos:array<int,string>,bai:array<int,string>}>,chua_khai:array<int,string>}
     */
    private function methodTable(Collection $criteria, Collection $clos, array $assessmentByRubric): array
    {
        $used = [];
        $chuaKhai = [];
        foreach ($criteria as $c) {
            $ten = $this->typeOf($c);
            if ($ten === '') {
                // Phiếu nhập bằng định dạng cũ không có dòng Hình thức. Ghi lại để nói thẳng là
                // chưa khai, chứ không dựng một dòng "Khác" trông như một phương pháp có thật.
                if ($bai = $assessmentByRubric[(int) $c->rubric_id]?->name ?? null) {
                    $chuaKhai[$bai] = true;
                }

                continue;
            }
            $key = $this->methodKey($ten);
            $used[$key]['ten'] ??= $ten;
            if ($code = $clos->firstWhere('id', $c->clo_id)?->code) {
                $used[$key]['clos'][$code] = true;
            }
            if ($bai = $assessmentByRubric[(int) $c->rubric_id]?->name ?? null) {
                $used[$key]['bai'][$bai] = true;
            }
        }

        $rows = [];
        foreach (self::METHOD_TABLE as $row) {
            $hit = $used[$row['ten']] ?? null;
            unset($used[$row['ten']]);
            $rows[] = [
                'ten' => $row['ten'],
                'co_su_dung' => $hit !== null,
                'clos' => $this->sortedKeys($hit['clos'] ?? []),
                'bai' => array_values(array_keys($hit['bai'] ?? [])),
            ];
        }

        // Hình thức trong phiếu không khớp dòng in sẵn nào — giữ nguyên tên giảng viên đã ghi.
        foreach ($used as $hit) {
            $rows[] = [
                'ten' => $hit['ten'],
                'co_su_dung' => true,
                'clos' => $this->sortedKeys($hit['clos'] ?? []),
                'bai' => array_values(array_keys($hit['bai'] ?? [])),
            ];
        }

        return ['rows' => $rows, 'chua_khai' => array_values(array_keys($chuaKhai))];
    }

    /** Tên dòng trong bảng in sẵn ứng với một hình thức câu hỏi, hoặc chính tên đó nếu không khớp. */
    private function methodKey(string $ten): string
    {
        $slug = ClassSheetReader::slug($ten);

        foreach (self::METHOD_TABLE as $row) {
            foreach ($row['slugs'] as $s) {
                if ($slug === $s || str_contains($slug, $s)) {
                    return $row['ten'];
                }
            }
        }

        return $ten;
    }

    /** @param array<string,bool> $set @return array<int,string> */
    private function sortedKeys(array $set): array
    {
        $keys = array_keys($set);
        sort($keys);

        return $keys;
    }

    /** Hình thức câu hỏi ghi trong phiếu; phiếu nhập bằng định dạng cũ thì để trống. */
    private function typeOf(RubricCriteria $c): string
    {
        return trim((string) ($c->question_type ?? ''));
    }

    /**
     * Điểm nhóm câu của một sinh viên, thang 10 — trung bình các câu có điểm, đúng quy tắc
     * `QuestionOutcomeScorer`. Không câu nào có điểm ⇒ null, sinh viên đó không vào thống kê dòng này
     * (vắng thi ghi VT không bị tính thành 0).
     *
     * @param Collection<int,RubricCriteria> $group
     * @param array<string,float> $scoreIndex
     */
    private function groupScore(Collection $group, array $scoreIndex, int $studentId, int $classId): ?float
    {
        $values = [];
        foreach ($group as $c) {
            $max = (float) $c->max_score;
            $score = $scoreIndex[$classId . ':' . $studentId . ':' . $c->id] ?? null;
            if ($max > 0 && $score !== null) {
                $values[] = $score / $max * 10;
            }
        }

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * "Trắc nghiệm, Giữa kỳ, Câu 1 – Câu 20" — cách ghi phương pháp đánh giá trong mẫu của trường.
     *
     * @param Collection<int,RubricCriteria> $group
     */
    private function methodLabel(Collection $group, ?Assessment $assessment): string
    {
        $labels = $group->pluck('question_label')->filter()->unique()->values();
        $phamVi = match (true) {
            $labels->isEmpty() => null,
            $labels->count() === 1 => (string) $labels->first(),
            default => $labels->first() . ' – ' . $labels->last(),
        };

        // Bài chuyên cần thường có hình thức, tên bài và nhãn câu trùng nhau — ghi một lần là đủ.
        return collect([$this->typeOf($group->first()), $assessment?->name, $phamVi])
            ->filter()
            ->unique()
            ->implode(', ');
    }

    /**
     * Điểm tổng kết môn của từng sinh viên (theo lớp họ học), dùng đúng công thức cụm của trường.
     *
     * @param Collection<int,CourseClass> $classes
     * @param Collection<int,Assessment> $assessments
     * @param array<string,float> $gradeIndex
     * @param array<int,array<int,bool>> $studentsByClass
     * @return array<int,?float> student_id → điểm môn
     */
    private function courseMarks(Collection $classes, Collection $assessments, array $gradeIndex, array $studentsByClass): array
    {
        $out = [];

        foreach ($classes as $class) {
            $ofClass = $assessments->where('syllabus_id', $class->syllabus_id);

            foreach (array_keys($studentsByClass[(int) $class->id] ?? []) as $studentId) {
                $rows = $ofClass->map(fn (Assessment $a) => [
                    'weight' => (float) ($a->weight_percent ?? 0),
                    'category' => (string) $a->assessment_category,
                    'score' => $gradeIndex[$class->id . ':' . $studentId . ':' . $a->id] ?? null,
                ])->values()->all();

                $out[$studentId] = CourseGradeFormula::fromAssessments($rows);
            }
        }

        return $out;
    }
}
