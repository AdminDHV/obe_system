<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Mô hình biết mình thuộc TRƯỜNG nào.
 *
 * Dùng cho `EnsureModelBelongsToWorkingSchool`: middleware không tự đoán đường truy ngược (mỗi
 * model một kiểu — có cái mang thẳng `school_id`, có cái phải đi qua đề cương → học phần), mà
 * hỏi thẳng chính model. Ai thêm model mới chỉ cần khai một hàm là được bảo vệ theo.
 *
 * Trả `null` khi không xác định được (bản ghi mồ côi) — middleware coi đó là KHÔNG thuộc trường
 * nào và chặn, vì thà chặn nhầm còn hơn cho sửa nhầm dữ liệu trường khác.
 */
interface BelongsToSchool
{
    public function schoolId(): ?int;
}
