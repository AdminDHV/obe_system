<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bôi đen MÃ TRA CỨU đã lỡ lọt vào audit_logs.
 *
 * Bản đầu của tính năng tra cứu để cơ chế audit chép nguyên giá trị `lookup_code` vào nhật ký,
 * biến bảng nhật ký thành danh bạ mã của cả trường. Từ nay Student::$auditExclude chặn việc đó,
 * nhưng các dòng CŨ vẫn còn mã nên phải dọn — chặn nguồn thôi là chưa đủ.
 *
 * KHÔNG xoá dòng: vết "ai sửa gì lúc nào" vẫn phải giữ, chỉ thay giá trị bí mật.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['old_values', 'new_values'] as $cot) {
            DB::table('audit_logs')
                ->where('model_type', 'Student')
                ->whereNotNull($cot)
                ->where($cot, 'like', '%lookup_code%')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($cot) {
                    foreach ($rows as $row) {
                        $data = json_decode((string) $row->{$cot}, true);
                        if (! is_array($data) || ! array_key_exists('lookup_code', $data)) {
                            continue;
                        }
                        $data['lookup_code'] = $data['lookup_code'] === null ? null : '(đã ẩn)';

                        DB::table('audit_logs')->where('id', $row->id)
                            ->update([$cot => json_encode($data, JSON_UNESCAPED_UNICODE)]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Không khôi phục được — và cũng không nên: mã đã bị bôi đen là điều mong muốn.
    }
};
