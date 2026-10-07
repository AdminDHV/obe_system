<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;

/**
 * Chọn ĐÚNG phiên bản đề cương của một môn cho một khóa.
 *
 * CLO, bài đánh giá và ma trận CLO→PI đều treo vào đề cương, nên chọn đúng phiên bản là thứ quyết
 * định khai khóa mới có đè lên khóa cũ hay không. Trước đây quy tắc này nằm riêng trong file khung;
 * nay phiếu lớp học phần cũng khai CLO và ma trận, nên tách ra một chỗ — hai đường nhập mà chọn
 * phiên bản theo hai cách thì khóa cũ sẽ bị đè đúng lúc không ai để ý.
 *
 * Thứ tự tìm (cố ý KHÔNG phá dữ liệu cũ):
 *   1. đã có phiên bản mang tên khóa này        → dùng lại
 *   2. khóa này đã có lớp trỏ vào phiên bản nào → dùng lại (trường đã chạy từ trước)
 *   3. có phiên bản '1.0' mà CHƯA lớp nào dùng  → nhận làm phiên bản của khóa này
 *   4. còn lại                                  → TẠO phiên bản mới mang tên khóa
 *
 * Không biết khóa (file khai nhiều khóa) ⇒ dùng '1.0' như hành vi cũ.
 */
final class SyllabusVersionResolver
{
    /** @var array<string,CourseSyllabus> */
    private array $cache = [];

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Như `forCourse()` nhưng KHÔNG tạo phiên bản mới — chưa có thì null. Dùng để hỏi "môn này của
     * khóa này đã có đề cương (đã dựng khung) chưa" mà không để lại một đề cương rỗng.
     */
    public function find(Course $course, ?Cohort $cohort): ?CourseSyllabus
    {
        $key = $course->id . '|' . ($cohort?->id ?? 0);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if (! $cohort?->exists) {
            return CourseSyllabus::where('course_id', $course->id)->where('version', '1.0')->first();
        }

        $own = CourseSyllabus::where('course_id', $course->id)->where('version', $cohort->name)->first();
        if ($own) {
            return $own;
        }

        $usedByCohort = CourseClass::where('cohort_id', $cohort->id)
            ->whereIn('syllabus_id', CourseSyllabus::where('course_id', $course->id)->select('id'))
            ->value('syllabus_id');
        if ($usedByCohort) {
            return CourseSyllabus::find($usedByCohort);
        }

        $legacy = CourseSyllabus::where('course_id', $course->id)->where('version', '1.0')->first();

        return $legacy && ! CourseClass::where('syllabus_id', $legacy->id)->exists() ? $legacy : null;
    }

    public function forCourse(Course $course, ?Cohort $cohort): CourseSyllabus
    {
        $key = $course->id . '|' . ($cohort?->id ?? 0);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if (! $cohort) {
            return $this->cache[$key] = CourseSyllabus::firstOrCreate(
                ['course_id' => $course->id, 'version' => '1.0'],
                ['is_active' => true],
            );
        }

        $own = CourseSyllabus::where('course_id', $course->id)
            ->where('version', $cohort->name)
            ->first();
        if ($own) {
            return $this->cache[$key] = $own;
        }

        $usedByCohort = CourseClass::where('cohort_id', $cohort->id)
            ->whereIn('syllabus_id', CourseSyllabus::where('course_id', $course->id)->select('id'))
            ->value('syllabus_id');
        if ($usedByCohort) {
            return $this->cache[$key] = CourseSyllabus::find($usedByCohort);
        }

        $legacy = CourseSyllabus::where('course_id', $course->id)->where('version', '1.0')->first();
        if ($legacy && ! CourseClass::where('syllabus_id', $legacy->id)->exists()) {
            return $this->cache[$key] = $legacy;
        }

        return $this->cache[$key] = CourseSyllabus::create([
            'course_id' => $course->id,
            'version' => $cohort->name,
            'is_active' => true,
        ]);
    }
}
