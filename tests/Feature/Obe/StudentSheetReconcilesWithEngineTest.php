<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentPiResult;
use App\Models\User;
use App\Services\OBE\ObeRecalculationService;
use App\Services\OBE\StudentPiBreakdownService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HỢP ĐỒNG: phiếu kết quả của học viên phải GIẢI TRÌNH ĐƯỢC con số của chính nó.
 *
 * Cộng tay các dòng "Môn đóng góp" in trên phiếu theo đúng công thức
 * Σ(tín chỉ × mức × điểm) / Σ(tín chỉ × mức × 10) phải ra ĐÚNG ô "Mức đạt" ở đầu dòng — con số
 * mà engine đã ghi vào `student_pi_results`.
 *
 * Trước đây phiếu tự dựng lại khung đo từ `student_clo_results` thay vì đọc khung của engine, nên
 * hai bên lệch nhau. Rõ nhất là học viên HỌC LẠI: `keyBy('clo_id')` gộp mọi lớp và giữ đúng một
 * dòng tuỳ thứ tự DB trả về, có khi là lần rớt, trong khi engine chấm theo lần học gần nhất.
 */
class StudentSheetReconcilesWithEngineTest extends TestCase
{
    use RefreshDatabase;

    private function syllabusWithClo(School $school, string $code, int $credits): array
    {
        $course = Course::create([
            'school_id' => $school->id, 'code' => $code, 'name' => 'Môn ' . $code, 'credits' => $credits,
        ]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'CLO 1']);

        foreach ([['Giữa kỳ', 'process', 40], ['Cuối kỳ', 'final', 60]] as [$name, $cat, $w]) {
            Assessment::create([
                'syllabus_id' => $syllabus->id, 'name' => $name,
                'assessment_category' => $cat, 'weight_percent' => $w, 'is_rubric_based' => false,
            ])->cloMappings()->create(['clo_id' => $clo->id, 'weight_in_assessment' => 100]);
        }

        return [$syllabus, $clo];
    }

    private function classFor(School $school, Cohort $cohort, CourseSyllabus $s, string $code, string $year): CourseClass
    {
        return CourseClass::create([
            'school_id' => $school->id, 'syllabus_id' => $s->id, 'cohort_id' => $cohort->id,
            'lecturer_id' => User::factory()->create()->id, 'class_code' => $code,
            'academic_year' => $year, 'semester' => 1,
        ]);
    }

    private function grade(Student $student, CourseClass $class, float $process, float $final): void
    {
        foreach (Assessment::where('syllabus_id', $class->syllabus_id)->get() as $a) {
            StudentGrade::create([
                'student_id' => $student->id, 'class_id' => $class->id,
                'assessment_id' => $a->id,
                'score' => $a->assessment_category === 'final' ? $final : $process,
            ]);
        }
    }

    public function test_sheet_rows_add_up_to_the_stored_pi_even_with_a_retake(): void
    {
        (new MappingScaleWeightSeeder())->run(); // I/R/M/A = 1/2/3/3

        $school = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'D1', 'name' => 'Khoa 1']);
        $program = Program::create([
            'school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'P1', 'name' => 'Ngành 1',
        ]);
        $cohort = Cohort::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'name' => '2026', 'start_year' => 2026,
        ]);

        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'CĐR 1']);
        $pi = Pi::create([
            'plo_id' => $plo->id, 'code' => 'PI1.1', 'description' => 'Chỉ số 1.1',
            'is_dummy' => false, 'weight' => 1.0,
        ]);

        [$sylA, $cloA] = $this->syllabusWithClo($school, 'C1', 3);
        [$sylB, $cloB] = $this->syllabusWithClo($school, 'C2', 4);
        CloPiMapping::create(['clo_id' => $cloA->id, 'pi_id' => $pi->id, 'mapping_level' => 'M']); // trọng số 3
        CloPiMapping::create(['clo_id' => $cloB->id, 'pi_id' => $pi->id, 'mapping_level' => 'R']); // trọng số 2

        // Môn C3 KHÔNG tín chỉ: engine loại khỏi cả tử lẫn mẫu, phiếu cũng không được liệt kê.
        [$sylC, $cloC] = $this->syllabusWithClo($school, 'C3', 0);
        CloPiMapping::create(['clo_id' => $cloC->id, 'pi_id' => $pi->id, 'mapping_level' => 'M']);

        $student = Student::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'cohort_id' => $cohort->id,
            'student_code' => 'SV01', 'full_name' => 'Nguyễn Văn A', 'status' => 'studying',
        ]);

        $classA = $this->classFor($school, $cohort, $sylA, 'C1-2026', '2026-2027');
        // C2 học hai lần: rớt 3.0 rồi học lại 9.0 — engine phải lấy LẦN SAU.
        $classB1 = $this->classFor($school, $cohort, $sylB, 'C2-2026', '2026-2027');
        $classB2 = $this->classFor($school, $cohort, $sylB, 'C2-2027', '2027-2028');
        $classC = $this->classFor($school, $cohort, $sylC, 'C3-2026', '2026-2027');

        foreach ([$classA, $classB1, $classB2, $classC] as $c) {
            $c->students()->attach($student->id);
        }

        $this->grade($student, $classA, 7.0, 8.0);   // 7×0.4 + 8×0.6 = 7.6
        $this->grade($student, $classB1, 3.0, 3.0);  // lần rớt
        $this->grade($student, $classB2, 9.0, 9.0);  // lần học lại
        $this->grade($student, $classC, 5.0, 5.0);

        app(ObeRecalculationService::class)->recalculateScope($school->id, $program->id, null);

        $sheet = app(StudentPiBreakdownService::class)->forStudent($student->fresh());
        $row = collect($sheet['pis'])->firstWhere('pi_code', 'PI1.1');

        $this->assertNotNull($row, 'Phiếu phải có dòng PI1.1');

        // Học phần không tín chỉ KHÔNG được xuất hiện — engine đã loại nó.
        $codes = array_column($row['courses'], 'course_code');
        $this->assertEqualsCanonicalizing(['C1', 'C2'], $codes);

        // Lần học GẦN NHẤT thắng: 9.0, không phải 3.0 và cũng không phải trung bình hai lần.
        $c2 = collect($row['courses'])->firstWhere('course_code', 'C2');
        $this->assertEqualsWithDelta(9.0, $c2['course_score'], 0.005);

        // Cộng tay đúng các dòng in trên phiếu → phải ra đúng ô "Mức đạt".
        $num = $den = 0.0;
        foreach ($row['courses'] as $course) {
            $w = (float) $course['weight']; // tín chỉ × mức
            $num += $w * (float) $course['course_score'];
            $den += $w * 10;
        }

        $engine = (float) StudentPiResult::where('student_id', $student->id)
            ->where('pi_id', $pi->id)->value('achieved_score');

        $this->assertGreaterThan(0.0, $den);
        $this->assertEqualsWithDelta($engine, $num / $den, 0.0005,
            'Cộng các dòng trên phiếu phải ra đúng con số engine đã ghi');
        $this->assertEqualsWithDelta($engine, (float) $row['achieved_score'], 0.0005);
    }
}
