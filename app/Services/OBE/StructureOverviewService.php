<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Assessment;
use App\Models\AssessmentCloMapping;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseOutcomeLevel;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\RubricCriteria;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * "Trường này đang có gì trong khung?" — tóm tắt cho MỘT trường, đọc thuần.
 *
 * Bước 1 của Trung tâm đo lường trước đây render y hệt nhau dù khung đã dựng hay chưa: tải file
 * lên xong chỉ có thêm một dòng lịch sử, còn màn hình vẫn mời "dựng khung" và vẫn chìa nút nạp
 * dữ liệu mẫu. Người vận hành không có cách nào biết mình đã khai tới đâu ngay tại chỗ vừa khai.
 *
 * Khác `ObeReadinessService` ở PHẠM VI, không phải ở việc: cái kia soi MỘT (ngành, khóa) và bắt
 * người dùng chọn trước — hợp với Bước 3, lúc sắp đo. Cái này soi CẢ TRƯỜNG, không cần chọn gì,
 * để trả lời câu "khai xong chưa". Hai bên cố ý không gọi lẫn nhau: gọi 1 lần cho mỗi cặp
 * (ngành, khóa) là hàng chục truy vấn cho một cái thẻ tóm tắt.
 *
 * Chỗ thiếu (`gaps`) chỉ liệt kê thứ THẬT SỰ chặn đường đo, kèm số lượng và đường dẫn đi sửa —
 * đây đúng là mấy nguyên nhân làm Bước 3 báo "chưa đo được PLO nào" mà không nói vì sao.
 */
class StructureOverviewService
{
    /** @return array<string,mixed> */
    public function forSchool(int $schoolId): array
    {
        $programIds = Program::where('school_id', $schoolId)->pluck('id');
        $courseIds = Course::where('school_id', $schoolId)->pluck('id');

        // Chỉ xét bản đề cương ĐANG DÙNG: môn có v1 cũ (đã tắt) thiếu CLO không phải là lỗi.
        $syllabi = CourseSyllabus::whereIn('course_id', $courseIds)
            ->where('is_active', true)
            ->get(['id', 'course_id']);
        $syllabusIds = $syllabi->pluck('id');

        $clos = Clo::whereIn('syllabus_id', $syllabusIds)->get(['id', 'syllabus_id']);
        $cloIds = $clos->pluck('id');

        $ploIds = Plo::whereIn('program_id', $programIds)->pluck('id');
        $pis = Pi::whereIn('plo_id', $ploIds)->get(['id', 'plo_id', 'is_dummy']);

        $mappings = CloPiMapping::whereIn('clo_id', $cloIds)->get(['clo_id', 'pi_id']);
        $assessments = Assessment::whereIn('syllabus_id', $syllabusIds)->get(['id', 'syllabus_id']);
        $classes = CourseClass::where('school_id', $schoolId)->get(['id', 'syllabus_id', 'cohort_id']);

        $counts = [
            'departments' => Department::where('school_id', $schoolId)->count(),
            'programs' => $programIds->count(),
            'cohorts' => Cohort::where('school_id', $schoolId)->count(),
            'courses' => $courseIds->count(),
            'clos' => $clos->count(),
            'plos' => $ploIds->count(),
            // PI nội bộ đại diện PLO nối thẳng (is_dummy) không phải PI trường khai — không đếm.
            'pis' => $pis->where('is_dummy', false)->count(),
            'mappings' => $mappings->count(),
            'assessments' => $assessments->count(),
            'classes' => $classes->count(),
            'students' => Student::where('school_id', $schoolId)->count(),
        ];

        return [
            'built' => $counts['programs'] > 0 || $counts['courses'] > 0,
            'counts' => $counts,
            'gaps' => $this->gaps($programIds, $ploIds, $pis, $syllabi, $clos, $mappings, $assessments, $classes),
        ];
    }

    /**
     * @param  Collection<int,mixed>  $programIds
     * @param  Collection<int,mixed>  $ploIds
     * @return array<int, array<string,mixed>>
     */
    private function gaps(
        Collection $programIds,
        Collection $ploIds,
        Collection $pis,
        Collection $syllabi,
        Collection $clos,
        Collection $mappings,
        Collection $assessments,
        Collection $classes,
    ): array {
        $syllabusIds = $syllabi->pluck('id');
        // Theo quy trình 2 file, CLO và bài đánh giá khai ở PHIẾU LỚP. Môn chưa có lớp nào thì thiếu CLO
        // là đương nhiên — đã có dòng "Môn chưa có phiếu" nói việc đó; báo đỏ thêm là báo trùng, dễ
        // làm trường tưởng mình khai sai. Chỉ soi CLO/bài đánh giá ở môn ĐÃ có lớp.
        $syllabiInUse = $syllabusIds->intersect($classes->pluck('syllabus_id')->unique());

        $programsWithPlo = Plo::whereIn('id', $ploIds)->pluck('program_id')->unique();
        $mappedPiIds = $mappings->pluck('pi_id')->unique();
        // PLO "được đo" = có ít nhất một CLO nối vào nó, qua PI thật hoặc nối thẳng (PI nội bộ).
        $plosMeasured = $pis->whereIn('id', $mappedPiIds)->pluck('plo_id')->unique();
        $realPiIds = $pis->where('is_dummy', false)->pluck('id');
        $cloIds = $clos->pluck('id');
        $closMeasured = RubricCriteria::whereIn('clo_id', $cloIds)->pluck('clo_id')
            ->merge(AssessmentCloMapping::whereIn('clo_id', $cloIds)->pluck('clo_id'))
            ->unique();
        $coursesWithClass = $syllabi->whereIn('id', $classes->pluck('syllabus_id')->unique())->pluck('course_id')->unique();
        $syllabiWithClo = $clos->pluck('syllabus_id')->unique();
        $syllabiWithAssessment = $assessments->pluck('syllabus_id')->unique();
        $closWithPi = $mappings->pluck('clo_id')->unique();
        $classesWithStudent = Enrollment::whereIn('class_id', $classes->pluck('id'))
            ->distinct()->pluck('class_id');

        // Ma trận CTĐT (File 1): ô "môn X đóng góp PLO/PI Y" của một khóa mà lớp của môn đó đã nhập phiếu
        // nhưng không CLO nào đo Y. Môn chưa có lớp thì chưa tính — đã có dòng "Môn chưa có phiếu".
        $syllabusCourse = $syllabi->pluck('course_id', 'id');
        $cloSyllabus = $clos->pluck('syllabus_id', 'id');
        $taught = [];
        $measuredCtdt = [];
        foreach ($classes as $c) {
            $courseId = $syllabusCourse[$c->syllabus_id] ?? null;
            if ($courseId !== null) {
                $taught[$c->cohort_id . '|' . $courseId] = true;
            }
        }
        foreach ($mappings as $m) {
            $sid = $cloSyllabus[$m->clo_id] ?? null;
            foreach ($sid === null ? [] : $classes->where('syllabus_id', $sid) as $c) {
                $measuredCtdt[$c->cohort_id . '|' . $syllabusCourse[$sid] . '|' . $m->pi_id] = true;
            }
        }
        $ctdtWithoutClo = CourseOutcomeLevel::whereIn('pi_id', $pis->pluck('id'))->get(['cohort_id', 'course_id', 'pi_id'])
            ->filter(fn ($r) => isset($taught[$r->cohort_id . '|' . $r->course_id])
                && ! isset($measuredCtdt[$r->cohort_id . '|' . $r->course_id . '|' . $r->pi_id]))
            ->count();

        $gaps = [
            $this->gap('programs_without_plo', 'Ngành chưa khai PLO',
                $programIds->diff($programsWithPlo)->count(),
                'Không có PLO thì không đo được chuẩn đầu ra nào của ngành đó.', '/manager/obe'),

            // KHÔNG còn báo "PLO chưa có PI": khách chốt PLO không chia PI thì CLO nối thẳng vào PLO.
            // Cái thật sự làm PLO trống là không có CLO nào nối tới nó.
            $this->gap('plos_without_clo', 'PLO chưa có CLO nào đo',
                $ploIds->diff($plosMeasured)->count(),
                'Không CLO nào nối tới PLO này (thẳng hoặc qua PI) — PLO sẽ luôn trống. Nối ở ma trận chuẩn đầu ra của phiếu lớp học phần.', '/manager/reports?step=2'),

            $this->gap('pis_without_clo', 'PI chưa có CLO nào nối',
                $realPiIds->diff($mappedPiIds)->count(),
                'PI đã khai nhưng chưa môn nào đo — mức đạt PLO chỉ tính trên các PI còn lại.', '/manager/reports?step=2', 'warn'),

            $this->gap('ctdt_without_clo', 'Ô ma trận CTĐT chưa có CLO đo', $ctdtWithoutClo,
                'Ma trận CTĐT ghi môn đóng góp PLO/PI này nhưng phiếu lớp chưa có CLO nào đo — PLO/PI đó thiếu phần của môn.', '/manager/reports?step=2', 'warn'),

            $this->gap('syllabi_without_clo', 'Môn chưa có CLO',
                $syllabiInUse->diff($syllabiWithClo)->count(),
                'Điểm của môn không quy về chuẩn đầu ra nào được.', '/manager/obe'),

            $this->gap('clos_without_pi', 'CLO chưa nối vào PLO/PI',
                $clos->pluck('id')->diff($closWithPi)->count(),
                'CLO có điểm nhưng không chảy lên PI/PLO — đây là lý do hay gặp nhất khiến bảng mức đạt trống.', '/manager/obe'),

            $this->gap('syllabi_without_assessment', 'Môn chưa có bài đánh giá',
                $syllabiInUse->diff($syllabiWithAssessment)->count(),
                'Không có bài đánh giá thì không có chỗ nhập điểm.', '/manager/assessments'),

            $this->gap('clos_without_measure', 'CLO chưa có câu hỏi nào đo',
                // Chỉ ở môn ĐÃ có phiếu: CLO của dữ liệu cũ mà môn chưa có lớp là bình thường.
                $clos->whereIn('syllabus_id', $syllabiInUse)->pluck('id')->diff($closMeasured)->count(),
                'Không câu hỏi hay bài đánh giá nào đo CLO này — kết quả CLO sẽ trống.', '/manager/reports?step=2', 'warn'),

            $this->gap('courses_without_class', 'Môn chưa có phiếu lớp học phần',
                $syllabi->pluck('course_id')->unique()->diff($coursesWithClass)->count(),
                'Môn đã khai nhưng chưa có lớp nào nhập phiếu — chưa có số đo cho môn này.', '/manager/reports?step=2', 'warn'),

            $this->gap('classes_without_student', 'Lớp chưa có sinh viên',
                $classes->pluck('id')->diff($classesWithStudent)->count(),
                'Lớp trống vẫn dựng được, nhưng sẽ không đóng góp số liệu nào.', '/manager/classes', 'warn'),
        ];

        return array_values(array_filter($gaps));
    }

    /** @return array<string,mixed>|null */
    private function gap(string $key, string $label, int $count, string $why, string $link, string $severity = 'error'): ?array
    {
        if ($count <= 0) {
            return null;
        }

        return compact('key', 'label', 'count', 'why', 'link', 'severity');
    }
}
