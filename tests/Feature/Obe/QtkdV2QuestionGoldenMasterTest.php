<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Imports\BulkGradeImport;
use App\Imports\QuestionGradeImport;
use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\FileBatch;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\User;
use App\Services\OBE\ObeRecalculationService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GOLDEN-MASTER — data THẬT khách bộ v2 (QTKD khóa 2024, đo THEO CÂU HỎI).
 *
 * Số liệu lấy nguyên từ file khách, học viên 2412HV9001, môn PCO501 "Triết học" (4 TC):
 *
 *  Điểm câu hỏi (QTKD_Ma trận điểm theo câu hỏi.xlsx)      Điểm thành phần (QTKD_Diem_thống kê.xlsx)
 *    GK1 · CLO1 · 2.75/3.5      CK · CLO1 · 8/10             Chuyên cần 9 · GK1 7.8 · GK2 7.9 · CK 7.9
 *    GK1 · CLO2 · 2.5/3.5       GK2 · CLO3 · 4.25/5          "Điểm tổng kết (gốc)" của khách = 8.0
 *    GK1 · CLO4 · 2.5/3         GK2 · CLO5 · 3.75/5
 *
 * Ma trận: PCO501 CLO1 → PI1.1 mức I. Mức I/R/M/A = 1/2/3/3.
 *
 * KHÁCH CHỐT: "Điểm số thì tính theo công thức (10% chuyên cần + 40% giữa kỳ + 50% cuối kỳ),
 * nhưng ĐO LƯỜNG CHUẨN ĐẦU RA LÀ CÂU CHUYỆN KHÁC." Test này khoá cả hai và chứng minh chúng ĐỘC LẬP:
 *
 *   ĐO LƯỜNG (từ câu hỏi):  CLO1 = (40 × 2.75/3.5×10 + 50 × 8/10×10) / 90   = 7.936508…
 *                           (tính từng bài, rồi gộp khối giữa kỳ 40 · cuối kỳ 50 theo đề cương)
 *                           PI1.1 = (4 × 1 × 7.936508) / (4 × 1 × 10)        = 0.793651…
 *                           PLO1  = PI1.1 (chỉ PI1.1 có dữ liệu)              = 0.793651…
 *
 *   ĐIỂM SỐ (từ trọng số):  (9×10 + 7.8×20 + 7.9×20 + 7.9×50) / 100           = 7.99  (khách: 8.0)
 *
 *   Nếu đo NHẦM theo điểm bài (0.4×TB quá trình + 0.6×cuối kỳ) thì CLO1 = 7.88 — test chặn đúng ca này.
 */
class QtkdV2QuestionGoldenMasterTest extends TestCase
{
    use RefreshDatabase;

    private const MSSV = '2412HV9001';
    private const CLASS_CODE = 'PCO501-2024';

    /** Điểm thành phần thật (BulkGradeImport) — tên bài phải khớp sheet BaiDanhGia. */
    private const COMPONENT_GRADES = [
        ['Chuyên cần', 9.0],
        ['GK1', 7.8],
        ['GK2', 7.9],
        ['Cuối kỳ', 7.9],
    ];

    /** Điểm câu hỏi thật (QuestionGradeImport): [bài, CLO, đạt, tối đa]. */
    private const QUESTIONS = [
        ['GK1', 'CLO1', 2.75, 3.5],
        ['GK1', 'CLO2', 2.5, 3.5],
        ['GK1', 'CLO4', 2.5, 3.0],
        ['GK2', 'CLO3', 4.25, 5.0],
        ['GK2', 'CLO5', 3.75, 5.0],
        ['CK', 'CLO1', 8.0, 10.0],
    ];

    private function seedFramework(): Program
    {
        (new MappingScaleWeightSeeder())->run(); // I/R/M/A = 1/2/3/3

        $school = School::create(['code' => 'SDHV2', 'name' => 'SĐH Test v2']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'SDH', 'name' => 'Sau đại học']);
        $program = Program::create([
            'school_id' => $school->id, 'department_id' => $dept->id,
            'code' => 'QTKD', 'name' => 'QTKD Thạc sĩ',
        ]);

        $plo1 = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'PLO1']);
        $pi = Pi::create(['plo_id' => $plo1->id, 'code' => 'PI1.1', 'description' => 'PI1.1', 'is_dummy' => false, 'weight' => 0.4]);
        // PI1.2 / PI1.3 tồn tại nhưng KHÔNG môn nào trong test đo → không được kéo PLO1 xuống.
        Pi::create(['plo_id' => $plo1->id, 'code' => 'PI1.2', 'description' => 'PI1.2', 'is_dummy' => false, 'weight' => 0.3]);
        Pi::create(['plo_id' => $plo1->id, 'code' => 'PI1.3', 'description' => 'PI1.3', 'is_dummy' => false, 'weight' => 0.3]);

        $course = Course::create(['school_id' => $school->id, 'code' => 'PCO501', 'name' => 'Triết học', 'credits' => 4]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);

        $clos = [];
        foreach (['CLO1', 'CLO2', 'CLO3', 'CLO4', 'CLO5'] as $code) {
            $clos[$code] = Clo::create(['syllabus_id' => $syllabus->id, 'code' => $code, 'description' => $code]);
        }
        CloPiMapping::create(['clo_id' => $clos['CLO1']->id, 'pi_id' => $pi->id, 'mapping_level' => 'I']);

        // Bài đánh giá + TRỌNG SỐ ĐIỂM SỐ (môn có 2 bài giữa kỳ ⇒ mỗi bài 40/2 = 20%).
        foreach ([['Chuyên cần', 'process', 10], ['GK1', 'process', 20], ['GK2', 'process', 20], ['Cuối kỳ', 'final', 50]] as [$name, $cat, $w]) {
            Assessment::create([
                'syllabus_id' => $syllabus->id, 'name' => $name,
                'assessment_category' => $cat, 'weight_percent' => $w, 'is_rubric_based' => false,
            ]);
        }

        // Khóa 2024 (vốn là legacy theo năm) + độ mịn 'question' ⇒ vẫn đo lên PI/PLO.
        $cohort = Cohort::create([
            'school_id' => $school->id, 'program_id' => $program->id,
            'name' => '2024', 'start_year' => 2024, 'grade_granularity' => 'question',
        ]);

        CourseClass::create([
            'school_id' => $school->id, 'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id,
            'lecturer_id' => User::factory()->create()->id, 'class_code' => self::CLASS_CODE,
            'academic_year' => '2024-2025', 'semester' => 1,
        ]);

        Student::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'cohort_id' => $cohort->id,
            'student_code' => self::MSSV, 'full_name' => 'Học viên mẫu A', 'status' => 'studying',
        ]);

        return $program;
    }

    private function batch(string $type): FileBatch
    {
        return FileBatch::create([
            'user_id' => User::factory()->create()->id, 'file_path' => 'x',
            'type' => $type, 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0,
        ]);
    }

    public function test_question_scores_drive_clo_pi_plo_while_weights_drive_course_grade(): void
    {
        $program = $this->seedFramework();
        $schoolId = (int) $program->school_id;

        // 1) ĐIỂM SỐ — điểm thành phần qua importer THẬT.
        $gradeRows = array_map(fn ($g) => [
            'ma_lop' => self::CLASS_CODE, 'mssv' => self::MSSV,
            'ten_bai_danh_gia' => $g[0], 'diem' => $g[1],
        ], self::COMPONENT_GRADES);
        (new BulkGradeImport($this->batch('grade_bulk'), $schoolId))->collection(collect($gradeRows));

        // 2) ĐO LƯỜNG — điểm câu hỏi qua importer THẬT.
        $questionRows = array_map(fn ($q) => [
            'ma_lop' => self::CLASS_CODE, 'mssv' => self::MSSV,
            'bai_kiem_tra' => $q[0] === 'CK' ? 'Cuối kỳ' : $q[0],
            'ma_clo' => $q[1], 'diem_dat' => $q[2], 'diem_toi_da' => $q[3],
        ], self::QUESTIONS);
        (new QuestionGradeImport($this->batch('grade_question'), $schoolId))->collection(collect($questionRows));

        app(ObeRecalculationService::class)->recalculateScope($schoolId, $program->id, null);

        $student = Student::where('student_code', self::MSSV)->firstOrFail();

        // ── ĐO LƯỜNG: CLO đến từ CÂU HỎI, không phải điểm bài ────────────────
        // Điểm từng BÀI trước, rồi mới gộp theo loại — KHÔNG dồn chung mọi câu:
        //   GK1 = 2.75/3.5 × 10 = 7.857142…   ·   CK = 8/10 × 10 = 8.0
        //   CLO1 = (40 × 7.857142 + 50 × 8.0) / 90 = 7.936508… — trọng số khối lấy từ đề cương (WCML (4)):
        //   chuyên cần không đo CLO1 nên chỉ còn khối giữa kỳ 40 và cuối kỳ 50, chuẩn hóa 4/9 : 5/9.
        // Dồn chung sẽ ra (2.75+8)/(3.5+10) × 10 = 7.9630 — câu 10 điểm ở cuối kỳ nuốt mất câu
        // 3.5 điểm giữa kỳ. Đối chiếu file Trường (42 HV × 24 PI) chốt cách tách bài là đúng.
        $clo1 = ((2.75 / 3.5 * 10) * 40 + (8 / 10 * 10) * 50) / 90; // = 7.936508… (khối GK 20+20, CK 50 của đề cương)
        $this->assertEqualsWithDelta($clo1, $this->clo($student, 'CLO1'), 0.0005);
        $this->assertEqualsWithDelta(7.142857, $this->clo($student, 'CLO2'), 0.0005); // 2.5/3.5 × 10
        $this->assertEqualsWithDelta(8.5, $this->clo($student, 'CLO3'), 0.0005);      // 4.25/5 × 10
        $this->assertEqualsWithDelta(8.333333, $this->clo($student, 'CLO4'), 0.0005); // 2.5/3  × 10
        $this->assertEqualsWithDelta(7.5, $this->clo($student, 'CLO5'), 0.0005);      // 3.75/5 × 10

        // Chốt chặn: KHÔNG được ra 7.88 (= 0.4 × TB(7.8, 7.9) + 0.6 × 7.9) — đó là đo theo điểm bài.
        $this->assertNotEqualsWithDelta(7.88, $this->clo($student, 'CLO1'), 0.005);

        $this->assertEqualsWithDelta($clo1 / 10, $this->pi($student, 'PI1.1'), 0.0005);
        $this->assertEqualsWithDelta($clo1 / 10, $this->plo($student, 'PLO1'), 0.0005);

        // ── ĐIỂM SỐ: trọng số bài (10/20/20/50) → điểm tổng kết môn ───────────
        $this->assertEqualsWithDelta(7.99, $this->courseGrade($student), 0.0005);
    }

    /** Không có điểm câu hỏi → giữ nguyên cách cũ (0.4 quá trình + 0.6 cuối kỳ) — chống hồi quy. */
    public function test_without_question_scores_clo_falls_back_to_assessment_rule(): void
    {
        $program = $this->seedFramework();
        $schoolId = (int) $program->school_id;

        // Bài đánh giá phải map CLO thủ công (không có rubric để suy ra).
        $syllabusId = CourseClass::where('class_code', self::CLASS_CODE)->firstOrFail()->syllabus_id;
        $clo1 = Clo::where('syllabus_id', $syllabusId)->where('code', 'CLO1')->firstOrFail();
        foreach (['GK1', 'GK2', 'Cuối kỳ'] as $name) {
            $a = Assessment::where('syllabus_id', $syllabusId)->where('name', $name)->firstOrFail();
            $a->cloMappings()->create(['clo_id' => $clo1->id, 'weight_in_assessment' => 100]);
        }

        $gradeRows = array_map(fn ($g) => [
            'ma_lop' => self::CLASS_CODE, 'mssv' => self::MSSV,
            'ten_bai_danh_gia' => $g[0], 'diem' => $g[1],
        ], self::COMPONENT_GRADES);
        (new BulkGradeImport($this->batch('grade_bulk'), $schoolId))->collection(collect($gradeRows));

        app(ObeRecalculationService::class)->recalculateScope($schoolId, $program->id, null);

        $student = Student::where('student_code', self::MSSV)->firstOrFail();

        // 0.4 × TB(7.8, 7.9) + 0.6 × 7.9 = 3.14 + 4.74 = 7.88
        $this->assertEqualsWithDelta(7.88, $this->clo($student, 'CLO1'), 0.0005);
    }

    private function clo(Student $s, string $code): float
    {
        return (float) $s->cloResults()->whereHas('clo', fn ($q) => $q->where('code', $code))->firstOrFail()->achieved_score;
    }

    private function pi(Student $s, string $code): float
    {
        return (float) $s->piResults()->whereHas('pi', fn ($q) => $q->where('code', $code))->firstOrFail()->achieved_score;
    }

    private function plo(Student $s, string $code): float
    {
        return (float) $s->ploResults()->whereHas('plo', fn ($q) => $q->where('code', $code))->firstOrFail()->achieved_score;
    }

    /** Điểm tổng kết môn = Σ(điểm bài × trọng số) / Σ trọng số — đúng công thức trường quy định. */
    private function courseGrade(Student $s): float
    {
        $row = StudentGrade::query()
            ->join('assessments as a', 'student_grades.assessment_id', '=', 'a.id')
            ->where('student_grades.student_id', $s->id)
            ->selectRaw('sum(student_grades.score * a.weight_percent) as num, sum(a.weight_percent) as den')
            ->first();

        return (float) $row->num / (float) $row->den;
    }
}
