<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Assessment;
use App\Models\Clo;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\User;
use App\Services\OBE\CourseGradeFormula;
use App\Services\OBE\StudentProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Trọng số BỎ TRỐNG: trường khai khung trước rồi để giảng viên điền sau.
 *
 * Quy tắc tính nằm ở hai nơi vì lý do hiệu năng — `CourseGradeFormula::resolveWeights()` bằng PHP
 * cho trang chi tiết học viên, và bản SQL trong `StudentProgressService::courseGrades()` cho cả
 * ngành. Test này khoá hợp đồng để hai bên không trôi ra khỏi nhau: cùng dữ liệu phải ra CÙNG một
 * con số. Không có nó thì một hôm nào đó trang học viên và báo cáo khóa hiện hai điểm khác nhau
 * cho cùng một môn, và không ai biết bên nào đúng.
 */
class BlankWeightTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Student, 1:CourseClass} */
    private function dungMon(array $baiDanhGia, array $diem): array
    {
        $school = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'D1', 'name' => 'Khoa 1']);
        $program = Program::create([
            'school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'P1', 'name' => 'Ngành 1',
        ]);
        $cohort = Cohort::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'name' => '2026', 'start_year' => 2026,
        ]);

        $course = Course::create(['school_id' => $school->id, 'code' => 'C1', 'name' => 'Môn 1', 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'CLO 1']);

        $class = CourseClass::create([
            'school_id' => $school->id, 'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id,
            'lecturer_id' => User::factory()->create()->id, 'class_code' => 'C1-2026',
            'academic_year' => '2026-2027', 'semester' => 1,
        ]);

        $student = Student::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'cohort_id' => $cohort->id,
            'student_code' => 'SV01', 'full_name' => 'Nguyễn Văn A', 'status' => 'studying',
        ]);
        $class->students()->attach($student->id);

        foreach ($baiDanhGia as $i => [$ten, $loai, $trongSo]) {
            $a = Assessment::create([
                'syllabus_id' => $syllabus->id, 'name' => $ten,
                'assessment_category' => $loai, 'weight_percent' => $trongSo, 'is_rubric_based' => false,
            ]);
            if ($diem[$i] !== null) {
                StudentGrade::create([
                    'student_id' => $student->id, 'class_id' => $class->id,
                    'assessment_id' => $a->id, 'score' => $diem[$i],
                ]);
            }
        }

        return [$student, $class];
    }

    public static function truongHop(): array
    {
        return [
            'chưa khai trọng số nào' => [
                [['GK1', 'process', 0], ['GK2', 'process', 0], ['Cuối kỳ', 'final', 0]],
                [6.0, 9.0, 8.0],
                (6.0 + 9.0 + 8.0) / 3,
            ],
            'khai một phần' => [
                [['GK1', 'process', 0], ['GK2', 'process', 0], ['Cuối kỳ', 'final', 50]],
                [6.0, 8.0, 9.0],
                0.25 * 6.0 + 0.25 * 8.0 + 0.5 * 9.0,
            ],
            'khai đủ 100%' => [
                [['Giữa kỳ', 'process', 40], ['Cuối kỳ', 'final', 60], ['Thừa', 'process', 0]],
                [6.0, 9.0, 1.0],
                0.4 * 6.0 + 0.6 * 9.0,
            ],
        ];
    }

    #[DataProvider('truongHop')]
    public function test_hai_duong_tinh_ra_cung_mot_so(array $bai, array $diem, float $mongDoi): void
    {
        [$student, $class] = $this->dungMon($bai, $diem);

        // Đường SQL — dùng cho báo cáo khóa.
        $quaSql = app(StudentProgressService::class)->forStudent($student->fresh());

        // Đường PHP — dùng cho trang chi tiết học viên.
        $quaPhp = CourseGradeFormula::fromAssessments(
            $class->syllabus->assessments->map(fn ($a) => [
                'weight' => (float) $a->weight_percent,
                'category' => (string) $a->assessment_category,
                'score' => ($g = StudentGrade::where('student_id', $student->id)
                    ->where('assessment_id', $a->id)->first())?->score !== null
                        ? (float) $g->score : null,
            ])->all(),
        );

        $this->assertEqualsWithDelta($mongDoi, $quaPhp, 0.005, 'Đường PHP sai');

        // Môn đạt ⇒ tín chỉ được cộng, chứng tỏ đường SQL cũng ra điểm chứ không bỏ trống.
        $this->assertSame(1, $quaSql['courses_graded'], 'Đường SQL phải chấm được môn này');
        $this->assertEqualsWithDelta(
            $mongDoi >= 5.5 ? 3.0 : 0.0,
            (float) $quaSql['credits_earned'],
            0.001,
            'Đường SQL ra điểm khác đường PHP',
        );
    }
}
