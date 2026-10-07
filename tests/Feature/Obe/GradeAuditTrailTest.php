<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Imports\LegacyGradeImport;
use App\Models\AuditLog;
use App\Models\Clo;
use App\Models\Course;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\FileBatch;
use App\Models\Program;
use App\Models\School;
use App\Models\StudentGrade;
use App\Models\User;
use App\Support\AuditActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Ghi vết "AI SỬA ĐIỂM": StudentGrade nay Auditable; import chạy trong queue job (không
 * có phiên đăng nhập) được quy cho người upload nhờ AuditActor::runAs — trước đây các thay
 * đổi này KHÔNG để lại vết audit nào.
 */
class GradeAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function seedProgramCourseClo(): int
    {
        $school = School::create(['code' => 'SCH', 'name' => 'Trường Test']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'IT', 'name' => 'CNTT']);
        Program::create(['school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'CNTT', 'name' => 'KTPM']);
        $course = Course::create(['school_id' => $school->id, 'code' => 'IT101', 'name' => 'Nhập môn', 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'A']);

        return $school->id;
    }

    private function importGrade(int $schoolId, int $uploaderId, float $score): void
    {
        $batch = FileBatch::create(['user_id' => $uploaderId, 'file_path' => 'x', 'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
        // Mô phỏng đúng cách job bọc: quy thay đổi cho người upload.
        AuditActor::runAs($uploaderId, fn () => (new LegacyGradeImport($batch, $schoolId))->collection(collect([
            ['ma_nganh' => 'CNTT', 'nam_khoa' => 2022, 'ma_mon' => 'IT101', 'mssv' => 'SV900', 'diem' => $score],
        ])));
    }

    public function test_import_attributes_grade_change_to_uploader(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);
        $schoolId = $this->seedProgramCourseClo();
        $uploader = User::factory()->create();

        $this->importGrade($schoolId, $uploader->id, 7.0);

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => 'StudentGrade', 'action' => 'created', 'user_id' => $uploader->id,
        ]);
    }

    public function test_reimport_same_score_adds_no_new_audit(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);
        $schoolId = $this->seedProgramCourseClo();
        $uploader = User::factory()->create();

        $this->importGrade($schoolId, $uploader->id, 7.0);
        $countAfterFirst = AuditLog::where('model_type', 'StudentGrade')->count();
        $this->importGrade($schoolId, $uploader->id, 7.0); // cùng điểm → không đổi

        $this->assertSame($countAfterFirst, AuditLog::where('model_type', 'StudentGrade')->count());
    }

    public function test_changing_score_records_old_and_new(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);
        $schoolId = $this->seedProgramCourseClo();
        $uploader = User::factory()->create();

        $this->importGrade($schoolId, $uploader->id, 7.0);
        $this->importGrade($schoolId, $uploader->id, 9.0); // đổi điểm

        $updated = AuditLog::where('model_type', 'StudentGrade')->where('action', 'updated')->latest('id')->first();
        $this->assertNotNull($updated);
        $this->assertArrayHasKey('score', $updated->new_values);
        $this->assertEquals(9.0, (float) $updated->new_values['score']);
        $this->assertEquals(7.0, (float) $updated->old_values['score']);
    }

    public function test_no_actor_no_audit(): void
    {
        // seedProgramCourseClo tạo Program/Course/Clo (đều Auditable) nhưng KHÔNG có Auth
        // và KHÔNG có AuditActor → phải KHÔNG ghi audit nào (giữ seeder/migrate:fresh sạch).
        $this->seedProgramCourseClo();

        $this->assertDatabaseCount('audit_logs', 0);
    }
}
