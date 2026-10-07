<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Soi sức khỏe dữ liệu trước/sau khi deploy — MẶC ĐỊNH CHỈ BÁO CÁO, không sửa gì.
 *
 * Dùng để trả lời "dữ liệu cũ có cần dọn không", đặc biệt trên production, thay vì đoán.
 * Mỗi mục nói rõ: đây là thứ **migration tự dọn**, hay thứ **cần người quyết**, hay thứ
 * `--fix` xử lý được an toàn.
 */
class DataHealthCommand extends Command
{
    protected $signature = 'obe:data-health {--fix : Vá những mục ĐƯỢC ĐÁNH DẤU an toàn (chỉ bù ghi danh)}';

    protected $description = 'Kiểm tra dữ liệu cần dọn (dòng trùng, rò chéo trường, thiếu ghi danh…)';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $issues = 0;

        $issues += $this->duplicateResults();
        $issues += $this->crossSchoolMappings();
        $issues += $this->softDeletedFramework();
        $issues += $this->missingEnrolments($fix);
        $issues += $this->duplicateStaffCodes();
        $issues += $this->missingRequiredCredits();
        $issues += $this->orphanSchool();
        $issues += $this->curriculumGroups();

        $this->newLine();
        if ($issues === 0) {
            $this->info('✓ Không phát hiện vấn đề nào cần dọn.');
        } else {
            $this->warn("Tổng cộng {$issues} mục cần xem. Mục nào ghi [CẦN NGƯỜI QUYẾT] thì ĐỪNG tự xoá.");
        }

        return self::SUCCESS;
    }

    /** Dòng kết quả trùng — migration 2026_08_03_000001 tự dọn khi chạy. */
    private function duplicateResults(): int
    {
        $this->line('');
        $this->line('<comment>[1] Dòng kết quả đo bị trùng</comment>');

        $found = 0;
        foreach ([
            'student_clo_results' => ['student_id', 'class_id', 'clo_id'],
            'student_pi_results' => ['student_id', 'pi_id'],
            'student_plo_results' => ['student_id', 'plo_id'],
        ] as $table => $cols) {
            if (! $this->tableExists($table)) {
                continue;
            }
            $group = implode(', ', $cols);
            $n = DB::selectOne("SELECT count(*) AS n FROM (
                SELECT {$group} FROM {$table} GROUP BY {$group} HAVING count(*) > 1
            ) x")->n;

            if ($n > 0) {
                $found++;
                $this->warn("    {$table}: {$n} khoá bị trùng — migration 2026_08_03_000001 sẽ tự dọn (giữ dòng mới nhất).");
                $this->line('      ⇒ Sau khi deploy nên bấm "Tính lại" để ghi lại số cho sạch.');
            } else {
                $this->line("    {$table}: sạch");
            }
        }

        return $found;
    }

    /** Ánh xạ CLO→PI vắt qua hai trường — dấu vết của lỗ rò ObeController đã vá. */
    private function crossSchoolMappings(): int
    {
        $this->line('');
        $this->line('<comment>[2] Ánh xạ CLO→PI vắt qua hai trường</comment>');

        $rows = DB::select("
            SELECT m.id, c.code AS clo, cs.code AS course_school, p.code AS pi, ps.code AS pi_school
            FROM clo_pi_mappings m
            JOIN clos c ON c.id = m.clo_id
            JOIN course_syllabus syl ON syl.id = c.syllabus_id
            JOIN courses co ON co.id = syl.course_id
            JOIN schools cs ON cs.id = co.school_id
            JOIN pis p ON p.id = m.pi_id
            JOIN plos pl ON pl.id = p.plo_id
            JOIN programs pr ON pr.id = pl.program_id
            JOIN schools ps ON ps.id = pr.school_id
            WHERE co.school_id <> pr.school_id
        ");

        if ($rows === []) {
            $this->line('    sạch');

            return 0;
        }

        $this->error('    [CẦN NGƯỜI QUYẾT] ' . count($rows) . ' ánh xạ nối CLO của trường này với PI của trường khác.');
        $this->line('      Đây là dấu vết của lỗ rò khung CTĐT (đã vá). Chúng KHÔNG còn hiện trên giao diện,');
        $this->line('      nên phải xoá bằng SQL sau khi xác nhận với từng trường:');
        foreach (array_slice($rows, 0, 10) as $r) {
            $this->line("        id={$r->id}  {$r->clo}({$r->course_school}) → {$r->pi}({$r->pi_school})");
        }

        return 1;
    }

    /** PLO/PI/CLO bị xoá mềm — có thể do thao tác nhầm từ trường khác trước khi vá. */
    private function softDeletedFramework(): int
    {
        $this->line('');
        $this->line('<comment>[3] PLO/PI/CLO đã bị xoá mềm</comment>');

        $found = 0;
        foreach (['plos', 'pis', 'clos'] as $table) {
            $n = DB::selectOne("SELECT count(*) AS n FROM {$table} WHERE deleted_at IS NOT NULL")->n;
            if ($n > 0) {
                $found++;
                $this->warn("    [CẦN NGƯỜI QUYẾT] {$table}: {$n} dòng đã xoá mềm.");
                $this->line('      Nếu là xoá nhầm (kể cả do lỗ rò cũ) thì khôi phục bằng cách set deleted_at = NULL.');
            } else {
                $this->line("    {$table}: sạch");
            }
        }

        return $found;
    }

    /** Lớp có điểm nhưng chưa ghi danh — do BulkGradeImport/GradeImport cũ không ghi pivot. */
    private function missingEnrolments(bool $fix): int
    {
        $this->line('');
        $this->line('<comment>[4] Sinh viên có điểm nhưng chưa ghi danh vào lớp</comment>');

        $missing = DB::select('
            SELECT DISTINCT g.student_id, g.class_id
            FROM student_grades g
            LEFT JOIN course_class_student ccs
              ON ccs.student_id = g.student_id AND ccs.class_id = g.class_id
            WHERE ccs.student_id IS NULL
        ');

        if ($missing === []) {
            $this->line('    sạch');

            return 0;
        }

        $this->warn('    ' . count($missing) . ' cặp (SV, lớp) thiếu ghi danh — màn hình Lớp sẽ liệt kê RỖNG dù đã có điểm.');

        if (! $fix) {
            $this->line('      ⇒ AN TOÀN để vá tự động: chạy lại lệnh này với <info>--fix</info>.');

            return 1;
        }

        $rows = array_map(fn ($m) => [
            'student_id' => $m->student_id,
            'class_id' => $m->class_id,
        ], $missing);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('course_class_student')->insertOrIgnore($chunk);
        }
        $this->info('      ✓ Đã bù ' . count($rows) . ' dòng ghi danh.');

        return 0;
    }

    /** Mã GV trùng trong cùng một trường — sẽ CHẶN migration 2026_08_04_000001. */
    private function duplicateStaffCodes(): int
    {
        $this->line('');
        $this->line('<comment>[5] Mã giảng viên trùng trong cùng một trường</comment>');

        $rows = DB::select('
            SELECT school_id, staff_code, count(*) AS n
            FROM users WHERE school_id IS NOT NULL
            GROUP BY school_id, staff_code HAVING count(*) > 1
        ');

        if ($rows === []) {
            $this->line('    sạch — migration đổi unique sang (trường, mã GV) sẽ chạy được');

            return 0;
        }

        $this->error('    [CẦN NGƯỜI QUYẾT] ' . count($rows) . ' mã bị trùng — migration 2026_08_04_000001 sẽ DỪNG cho tới khi dọn:');
        foreach ($rows as $r) {
            $this->line("        school_id={$r->school_id} · {$r->staff_code} ({$r->n} tài khoản)");
        }

        return 1;
    }

    /** Ngành chưa khai số tín chỉ tốt nghiệp ⇒ bộ lọc "đủ tích lũy" không lọc ai. */
    private function missingRequiredCredits(): int
    {
        $this->line('');
        $this->line('<comment>[6] Ngành chưa khai số tín chỉ tốt nghiệp</comment>');

        // Chỉ soi trường ĐANG HOẠT ĐỘNG — nhắc về trường đã tắt là báo động giả, làm người
        // vận hành quen bỏ qua cảnh báo.
        $rows = DB::select('
            SELECT p.code, s.code AS school
            FROM programs p JOIN schools s ON s.id = p.school_id
            WHERE p.required_credits IS NULL AND p.deleted_at IS NULL
              AND s.is_active = true
        ');

        if ($rows === []) {
            $this->line('    sạch');

            return 0;
        }

        $this->warn('    ' . count($rows) . ' ngành chưa khai ⇒ bộ lọc "đủ tích lũy" KHÔNG lọc ai (giữ hành vi cũ, không sai số):');
        foreach (array_slice($rows, 0, 10) as $r) {
            $this->line("        {$r->school} / {$r->code}");
        }

        return 1;
    }

    /**
     * Bản ghi không thuộc trường nào (`school_id` NULL).
     *
     * Sau khi phân quyền theo trường + theo giảng viên, những dòng này trở nên VÔ HÌNH với mọi
     * người: không lọt bộ lọc trường nào cả. Người dùng chỉ thấy "mất dữ liệu" chứ không thấy
     * lý do — nên phải gọi tên ra.
     */
    private function orphanSchool(): int
    {
        $this->line('');
        $this->line('<comment>[7] Bản ghi không thuộc trường nào (vô hình với mọi người dùng)</comment>');

        $bang = ['course_classes', 'students', 'programs', 'courses', 'cohorts', 'departments'];
        $found = 0;

        foreach ($bang as $table) {
            if (! $this->tableExists($table)) {
                continue;
            }

            $n = DB::selectOne("SELECT count(*) AS n FROM {$table}
                WHERE school_id IS NULL AND deleted_at IS NULL")->n;

            if ($n > 0) {
                $found++;
                $this->warn("    {$table}: {$n} dòng mồ côi — không hiện ở bất kỳ trường nào.");
            } else {
                $this->line("    {$table}: sạch");
            }
        }

        if ($found > 0) {
            $this->line('      ⇒ [CẦN NGƯỜI QUYẾT] gán đúng trường, hoặc xoá nếu là dữ liệu rác cũ.');
        }

        return $found;
    }

    /**
     * Cấu trúc CTĐT khai dở dang.
     *
     * Ba lỗi khiến độ phủ "PI1.1 (1/3)" ra mẫu số sai mà không ai biết:
     *   - khối yêu cầu 15 tín chỉ nhưng học phần xếp vào chỉ có 12 ⇒ CTĐT còn thiếu môn;
     *   - học phần của ngành chưa xếp khối nào ⇒ bị tính cho MỌI học viên như thể bắt buộc;
     *   - ngành có hướng chuyên ngành nhưng học viên chưa được gán hướng ⇒ mẫu số hụt phần
     *     chuyên ngành. Riêng cái cuối là bình thường với đại học năm 1-2, nên chỉ nhắc.
     */
    private function curriculumGroups(): int
    {
        $this->line('');
        $this->line('<comment>[8] Cấu trúc chương trình đào tạo (khối học phần)</comment>');

        if (! $this->tableExists('curriculum_groups')) {
            $this->line('    bỏ qua (chưa có bảng)');

            return 0;
        }

        $found = 0;

        $thieu = DB::select("
            SELECT g.code, g.name, p.code AS program, g.required_credits,
                   COALESCE(SUM(c.credits), 0) AS credits
            FROM curriculum_groups g
            JOIN programs p ON p.id = g.program_id
            JOIN schools s ON s.id = g.school_id AND s.is_active = true
            LEFT JOIN program_courses pc ON pc.curriculum_group_id = g.id
            LEFT JOIN courses c ON c.id = pc.course_id AND c.deleted_at IS NULL
            WHERE g.required_credits IS NOT NULL AND p.deleted_at IS NULL
            GROUP BY g.id, g.code, g.name, p.code, g.required_credits
            HAVING COALESCE(SUM(c.credits), 0) < g.required_credits
        ");

        foreach ($thieu as $r) {
            $found++;
            $this->warn("    {$r->program} / {$r->code} \"{$r->name}\": yêu cầu {$r->required_credits} TC"
                . " nhưng học phần đã xếp chỉ có {$r->credits} TC.");
        }

        $chuaXep = DB::select("
            SELECT p.code AS program, count(*) AS n
            FROM program_courses pc
            JOIN programs p ON p.id = pc.program_id AND p.deleted_at IS NULL
            JOIN schools s ON s.id = p.school_id AND s.is_active = true
            WHERE pc.curriculum_group_id IS NULL
              AND EXISTS (SELECT 1 FROM curriculum_groups g WHERE g.program_id = p.id)
            GROUP BY p.code
        ");

        foreach ($chuaXep as $r) {
            $found++;
            $this->warn("    {$r->program}: {$r->n} học phần chưa xếp khối — đang bị tính cho MỌI học viên.");
        }

        $chuaChon = DB::select("
            SELECT p.code AS program, count(*) AS n
            FROM students st
            JOIN programs p ON p.id = st.program_id AND p.deleted_at IS NULL
            JOIN schools s ON s.id = p.school_id AND s.is_active = true
            WHERE st.deleted_at IS NULL
              AND EXISTS (SELECT 1 FROM curriculum_groups g
                          WHERE g.program_id = p.id AND g.kind = 'elective')
              AND NOT EXISTS (SELECT 1 FROM curriculum_group_student cgs
                              JOIN curriculum_groups g2 ON g2.id = cgs.curriculum_group_id
                              WHERE cgs.student_id = st.id AND g2.kind = 'elective')
            GROUP BY p.code
        ");

        foreach ($chuaChon as $r) {
            $this->line("    {$r->program}: {$r->n} học viên chưa chọn hướng chuyên ngành"
                . ' (bình thường nếu chưa tới kỳ chọn hướng) — mẫu số độ phủ mới tính phần bắt buộc.');
        }

        if ($found === 0) {
            $this->line('    sạch');
        } else {
            $this->line('      ⇒ [CẦN NGƯỜI QUYẾT] mở màn hình "Cấu trúc CTĐT" để xếp lại.');
        }

        return $found;
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }
}
