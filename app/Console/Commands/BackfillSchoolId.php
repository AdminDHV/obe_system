<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gán `school_id` cho các bản ghi còn NULL (do tạo tay qua CRUD trước khi tenant hoá).
 * Suy từ quan hệ; nếu chỉ có 1 trường active thì gán tất cả cho trường đó.
 * Idempotent + `--dry-run` để xem trước. CHẠY TRƯỚC khi bật scope theo trường.
 */
class BackfillSchoolId extends Command
{
    protected $signature = 'obe:backfill-school {--dry-run : Chỉ đếm, không ghi}';
    protected $description = 'Gán school_id cho bản ghi còn NULL (suy từ quan hệ; 1 trường → gán hết)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $filled = 0;

        // 1. Program ← Khoa
        $filled += $this->fill(Program::whereNull('school_id')->with('department'),
            fn ($p) => $p->department?->school_id, $dry);
        // 2. Cohort ← Ngành
        $filled += $this->fill(Cohort::whereNull('school_id')->with('program'),
            fn ($c) => $c->program?->school_id, $dry);
        // 3. Student ← Ngành (fallback Khóa)
        $filled += $this->fill(Student::whereNull('school_id')->with('program', 'cohort'),
            fn ($s) => $s->program?->school_id ?? $s->cohort?->school_id, $dry);
        // 4. Lớp ← Khóa (fallback đề cương→môn)
        $filled += $this->fill(CourseClass::whereNull('school_id')->with('cohort', 'syllabus.course'),
            fn ($cc) => $cc->cohort?->school_id ?? $cc->syllabus?->course?->school_id, $dry);
        // 5. Môn ← các ngành gắn (chỉ khi mọi ngành cùng 1 trường)
        $filled += $this->fill(Course::whereNull('school_id')->with('programs:id,school_id'),
            function ($course) {
                $ids = $course->programs->pluck('school_id')->filter()->unique();
                return $ids->count() === 1 ? (int) $ids->first() : null;
            }, $dry);

        // 6. User (non-super_admin) ← Khoa. super_admin để NULL = operator toàn cục (không kẹp trường).
        $filled += $this->fill(
            User::whereNull('school_id')
                ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'super_admin'))
                ->with('department'),
            fn ($u) => $u->department?->school_id,
            $dry
        );

        // 7. Chỉ 1 trường active → gán hết NULL còn lại (không còn nhập nhằng).
        $active = School::where('is_active', true)->get();
        if ($active->count() === 1) {
            $only = (int) $active->first()->id;
            foreach ([Department::class, Program::class, Course::class, Cohort::class, CourseClass::class, Student::class] as $model) {
                $filled += $this->fill($model::whereNull('school_id'), fn () => $only, $dry);
            }
            // User non-super_admin còn NULL → gán trường duy nhất; super_admin vẫn NULL.
            $filled += $this->fill(
                User::whereNull('school_id')->whereDoesntHave('roles', fn ($q) => $q->where('name', 'super_admin')),
                fn () => $only,
                $dry
            );
        }

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Đã gán school_id cho {$filled} bản ghi.");

        // Báo bản ghi còn mồ côi (NULL) sau khi suy — cần gán tay.
        $orphans = [
            'departments' => Department::whereNull('school_id')->count(),
            'programs' => Program::whereNull('school_id')->count(),
            'courses' => Course::whereNull('school_id')->count(),
            'cohorts' => Cohort::whereNull('school_id')->count(),
            'course_classes' => CourseClass::whereNull('school_id')->count(),
            'students' => Student::whereNull('school_id')->count(),
            'users (non-admin)' => User::whereNull('school_id')
                ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'super_admin'))->count(),
        ];
        $totalOrphan = array_sum($orphans);
        if ($totalOrphan > 0) {
            $this->warn("Còn {$totalOrphan} bản ghi CHƯA gán trường (nhập nhằng): "
                . collect($orphans)->filter()->map(fn ($v, $k) => "{$k}={$v}")->implode(', '));
        } else {
            $this->info('Không còn bản ghi mồ côi trường.');
        }

        return self::SUCCESS;
    }

    /** Duyệt & gán theo resolver (bỏ qua khi resolver trả null). Trả số bản ghi gán. */
    private function fill(Builder $query, Closure $resolver, bool $dry): int
    {
        $n = 0;
        $query->chunkById(500, function ($rows) use ($resolver, $dry, &$n) {
            foreach ($rows as $row) {
                $sid = $resolver($row);
                if ($sid) {
                    $n++;
                    if (! $dry) {
                        $row->forceFill(['school_id' => $sid])->saveQuietly();
                    }
                }
            }
        });

        return $n;
    }
}
