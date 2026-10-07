<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\AuditLog;
use App\Models\Student;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Tra cứu kết quả bằng MSSV + MÃ TRA CỨU (không cần tài khoản).
 *
 * Nguyên tắc an toàn, cố ý:
 *   - KHÔNG có danh sách, KHÔNG có tìm kiếm — chỉ trả đúng hồ sơ của người nhập đúng cặp mã.
 *   - Sai MSSV hay sai mã đều báo GIỐNG NHAU, để không lộ "MSSV này có tồn tại".
 *   - So mã bằng `hash_equals` (không so sánh sớm-thoát) và luôn chạy hết một lần so, kể cả khi
 *     không tìm thấy học viên, để thời gian phản hồi không tiết lộ thông tin.
 *   - Mọi lượt tra đều được ghi log để phát hiện dò quét.
 */
class StudentLookupService
{
    /** Bảng chữ tránh ký tự dễ nhầm (0/O, 1/I/L) — mã còn phải đọc được từ giấy. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** Sinh mã cho học viên chưa có. Idempotent — gọi lại không đổi mã đã cấp. */
    public function ensureCode(Student $student): string
    {
        if ($student->lookup_code) {
            return $student->lookup_code;
        }

        do {
            $code = $this->randomCode();
        } while (Student::where('lookup_code', $code)->exists());

        $student->forceFill(['lookup_code' => $code])->save();

        return $code;
    }

    /**
     * Cấp LẠI mã: mã cũ mất hiệu lực ngay, học viên phải nhận mã mới mới tra được.
     *
     * Đây là thao tác THU HỒI quyền xem của một người nên phải để lại vết ai làm, lúc nào —
     * nhưng vết KHÔNG chứa mã (xem $auditExclude ở Student).
     */
    public function regenerate(Student $student): string
    {
        $student->forceFill(['lookup_code' => null])->save();
        $code = $this->ensureCode($student);

        if ($actor = Auth::id()) {
            AuditLog::create([
                'user_id' => $actor,
                'action' => 'lookup_code_regenerated',
                'model_type' => 'Student',
                'model_id' => $student->getKey(),
                'old_values' => null,
                'new_values' => ['lookup_code' => '(đã ẩn)'],
            ]);
        }

        Log::channel('obe')->info('Cấp lại mã tra cứu', [
            'student_code' => $student->student_code,
            'boi' => Auth::id(),
        ]);

        return $code;
    }

    /**
     * Tìm học viên theo MSSV + mã. Trả null cho MỌI trường hợp thất bại.
     */
    public function find(string $studentCode, string $code, ?string $ip = null): ?Student
    {
        $studentCode = trim($studentCode);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        $student = Student::where('student_code', $studentCode)->first();

        // Luôn so một lần, kể cả khi không có học viên — tránh lộ qua thời gian phản hồi.
        $expected = $student?->lookup_code ?? str_repeat('-', 8);
        $ok = $student !== null
            && $student->lookup_code !== null
            && hash_equals(strtoupper($expected), $code);

        Log::channel('obe')->info('Tra cứu kết quả học viên', [
            'student_code' => $studentCode,
            'ket_qua' => $ok ? 'thành công' : 'thất bại',
            'ip' => $ip,
        ]);

        return $ok ? $student : null;
    }

    /** Định dạng cho dễ đọc trên giấy: XXXX-XXXX. */
    public function format(string $code): string
    {
        return strlen($code) === 8 ? substr($code, 0, 4) . '-' . substr($code, 4) : $code;
    }

    private function randomCode(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 8; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
