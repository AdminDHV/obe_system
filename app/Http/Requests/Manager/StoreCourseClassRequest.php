<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager;

use App\Models\CourseSyllabus;
use App\Models\User;
use App\Support\WorkingSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseClassRequest extends FormRequest
{
    public function rules(): array
    {
        $sid = $this->attributes->get(WorkingSchool::ATTR);

        return [
            'syllabus_id' => ['required', 'exists:course_syllabus,id', $this->syllabusInSchoolRule($sid)],
            'cohort_id' => ['required', Rule::exists('cohorts', 'id')->where('school_id', $sid)],
            'lecturer_id' => ['required', 'exists:users,id', $this->lecturerRule($sid)],
            'class_code' => ['required', 'string', 'max:100', $this->classCodeRule($sid)],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', 'integer', 'min:1', 'max:3'],
            'max_capacity' => ['required', 'integer', 'min:0', 'max:1000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    /** Đề cương phải thuộc môn của TRƯỜNG đang làm việc. */
    protected function syllabusInSchoolRule(?int $sid): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($sid): void {
            $ok = CourseSyllabus::whereKey($value)
                ->whereHas('course', fn ($q) => $q->where('school_id', $sid))->exists();
            if (! $ok) {
                $fail('Đề cương không thuộc trường đang làm việc.');
            }
        };
    }

    /** Giảng viên phải có vai trò lecturer VÀ thuộc khoa của trường đang làm việc. */
    protected function lecturerRule(?int $sid): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($sid): void {
            $ok = User::role('lecturer')->whereKey($value)
                ->whereHas('department', fn ($q) => $q->where('school_id', $sid))->exists();
            if (! $ok) {
                $fail('Người được gán không phải giảng viên của trường này.');
            }
        };
    }

    protected function classCodeRule(?int $sid): \Illuminate\Validation\Rules\Unique
    {
        return Rule::unique('course_classes', 'class_code')->where('school_id', $sid)->whereNull('deleted_at');
    }
}
