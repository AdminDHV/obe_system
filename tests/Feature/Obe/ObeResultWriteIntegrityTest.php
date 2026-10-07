<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Jobs\Calculations\RecalculateObeJob;
use App\Models\Assessment;
use App\Models\AssessmentCloMapping;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\MeasurementRun;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Models\User;
use App\Services\OBE\ObeRecalculationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KHOÁ hai tính chất của tầng ghi kết quả đo — cả hai đều từng bị vi phạm khi đo song song:
 *
 *  1. MỖI (SV, CLO/PI/PLO) CHỈ MỘT DÒNG. Engine từng ghi bằng `updateOrCreate` (SELECT rồi INSERT,
 *     không nguyên tử) trong khi nhiều worker cùng đo một sinh viên ⇒ chèn được 2 dòng. Dòng trùng
 *     làm phồng `students_measured` nên % mức đạt và avg_score sai. Nay: UNIQUE ở DB + `upsert`.
 *
 *  2. ĐO CHIA HAI THÌ. CLO thuộc 1 (SV, lớp) nhưng PI/PLO gộp qua MỌI môn, nên thì 2 phải chạy
 *     một lần cho mỗi SV SAU KHI CLO của mọi lớp đã xong — không phải mỗi lớp một lần.
 */
class ObeResultWriteIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;
    private Program $program;
    private Cohort $cohort;
    private User $lecturer;
    private School $school;
    private Plo $plo;
    private Pi $pi;
    /** @var array<int,CourseClass> */
    private array $classes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTwoCourseProgram();
    }

    public function test_do_lai_nhieu_lan_khong_sinh_dong_trung(): void
    {
        $service = app(ObeRecalculationService::class);

        // Đo đi đo lại đúng như worker chạy lại job (retry / bấm "Tính lại" nhiều lần).
        foreach (range(1, 3) as $ignored) {
            $service->recalculateScope($this->school->id, $this->program->id, null);
        }

        $this->assertSame(1, StudentPiResult::where('student_id', $this->student->id)
            ->where('pi_id', $this->pi->id)->count(), 'PI phải đúng 1 dòng');
        $this->assertSame(1, StudentPloResult::where('student_id', $this->student->id)
            ->where('plo_id', $this->plo->id)->count(), 'PLO phải đúng 1 dòng');

        // CLO: 2 môn × 1 CLO = 2 dòng, mỗi (SV, lớp, CLO) đúng 1.
        $this->assertSame(2, StudentCloResult::where('student_id', $this->student->id)->count());
    }

    public function test_db_chan_cung_dong_trung_khong_phu_thuoc_code(): void
    {
        app(ObeRecalculationService::class)->recalculateScope($this->school->id, $this->program->id, null);

        $row = StudentPiResult::where('student_id', $this->student->id)->firstOrFail();

        // Chèn thẳng bản sao ở tầng DB: phải bị UNIQUE chặn. Đây là lớp bảo vệ cuối cùng —
        // nếu ai đó lỡ quay lại updateOrCreate thì DB vẫn không cho ghi trùng.
        $this->expectException(QueryException::class);

        DB::table('student_pi_results')->insert([
            'student_id' => $row->student_id,
            'pi_id' => $row->pi_id,
            'achieved_score' => 0.1234,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_thi_1_chi_do_clo_thi_2_moi_gop_pi_plo(): void
    {
        $service = app(ObeRecalculationService::class);

        // Thì 1 chạy riêng từng lớp (đúng như job fan-out theo lớp trong batch).
        foreach ($this->classes as $class) {
            $service->recalculateScope(
                $this->school->id, $this->program->id, null, null, $class->id, cloOnly: true,
            );
        }

        $this->assertSame(2, StudentCloResult::where('student_id', $this->student->id)->count(),
            'thì 1 phải ghi CLO của cả 2 lớp');
        $this->assertSame(0, StudentPiResult::where('student_id', $this->student->id)->count(),
            'thì 1 KHÔNG được đụng PI');
        $this->assertSame(0, StudentPloResult::where('student_id', $this->student->id)->count(),
            'thì 1 KHÔNG được đụng PLO');

        // Danh sách SV cần gộp = đúng SV này (khóa 2026 → Phương án 3, có PI/PLO).
        $ids = $service->studentIdsNeedingAggregation($this->school->id, $this->program->id, null);
        $this->assertSame([$this->student->id], $ids);

        // Thì 2 — gộp một lần, và ra ĐÚNG con số của phép đo trọn vẹn.
        $service->aggregateStudents($ids);

        // PI = (2·2·6 + 3·2·9) / (2·2·10 + 3·2·10) = 78/100 = 0.78
        $piScore = (float) StudentPiResult::where('student_id', $this->student->id)->value('achieved_score');
        $this->assertEqualsWithDelta(0.78, $piScore, 0.0005);

        // 1 PI duy nhất → PLO = PI.
        $ploScore = (float) StudentPloResult::where('student_id', $this->student->id)->value('achieved_score');
        $this->assertEqualsWithDelta(0.78, $ploScore, 0.0005);
    }

    public function test_job_tinh_lai_chay_tron_hai_thi_va_chot_run_dung_mot_lan(): void
    {
        // Phạm vi 2 lớp ⇒ đi đường batch: thì 1 (2 job CLO) → thì 2 (job gộp PI/PLO) → chốt run.
        RecalculateObeJob::dispatchSync($this->school->id, $this->program->id, null, null, 'test');

        $piScore = (float) StudentPiResult::where('student_id', $this->student->id)->value('achieved_score');
        $this->assertEqualsWithDelta(0.78, $piScore, 0.0005, 'batch phải chạy tới thì 2');

        $ploScore = (float) StudentPloResult::where('student_id', $this->student->id)->value('achieved_score');
        $this->assertEqualsWithDelta(0.78, $ploScore, 0.0005);

        // Run được chốt SAU khi gộp xong, và đúng 1 dòng cho 1 lần bấm nút
        // (trước đây job con theo lớp cũng ghi run ⇒ mỗi lớp 1 dòng).
        $runs = MeasurementRun::all();
        $this->assertCount(1, $runs);
        $this->assertSame('completed', $runs->first()->status);
    }

    /** Chương trình 1 PLO / 1 PI, đo qua 2 môn (2 TC và 3 TC), cùng mức R(=2). */
    private function seedTwoCourseProgram(): void
    {
        $this->school = School::create(['code' => 'SCH-INT', 'name' => 'Integrity']);
        $dept = Department::create(['code' => 'IT', 'name' => 'CNTT', 'school_id' => $this->school->id]);
        $this->program = Program::create([
            'department_id' => $dept->id, 'code' => 'P-INT', 'name' => 'KTPM', 'school_id' => $this->school->id,
        ]);
        $this->cohort = Cohort::create(['program_id' => $this->program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $this->lecturer = User::factory()->create();

        $this->plo = Plo::create(['program_id' => $this->program->id, 'code' => 'PLO1', 'description' => 'PLO1']);
        $this->pi = Pi::create([
            'plo_id' => $this->plo->id, 'code' => 'PI1', 'description' => 'PI1', 'is_dummy' => false,
        ]);

        $this->student = Student::create([
            'student_code' => 'SV-INT', 'full_name' => 'Nguyen Van A', 'school_id' => $this->school->id,
            'cohort_id' => $this->cohort->id, 'program_id' => $this->program->id, 'status' => 'studying',
        ]);

        $this->makeCourseWithGrade('C-1', 2, 6.0);
        $this->makeCourseWithGrade('C-2', 3, 9.0);
    }

    /** 1 môn: CLO → PI mức R, 1 bài process + 1 bài final cùng điểm ⇒ điểm-PI-môn = điểm đó. */
    private function makeCourseWithGrade(string $code, int $credits, float $score): void
    {
        $course = Course::create([
            'code' => $code, 'name' => $code, 'credits' => $credits, 'school_id' => $this->school->id,
        ]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $class = CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $this->cohort->id, 'lecturer_id' => $this->lecturer->id,
            'class_code' => $code . '-CLS', 'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
            'school_id' => $this->school->id,
        ]);
        $this->classes[] = $class;

        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO-' . $code, 'description' => 'x']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $this->pi->id, 'mapping_level' => 'R']);

        foreach (['process' => 40, 'final' => 60] as $category => $weight) {
            $assessment = Assessment::create([
                'syllabus_id' => $syllabus->id, 'name' => $category . '-' . $code,
                'assessment_category' => $category, 'weight_percent' => $weight, 'is_rubric_based' => false,
            ]);
            AssessmentCloMapping::create([
                'assessment_id' => $assessment->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100,
            ]);
            StudentGrade::create([
                'student_id' => $this->student->id, 'class_id' => $class->id,
                'assessment_id' => $assessment->id, 'score' => $score,
            ]);
        }
    }
}
