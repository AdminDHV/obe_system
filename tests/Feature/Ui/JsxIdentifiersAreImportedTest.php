<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use PHPUnit\Framework\TestCase;

/**
 * Mọi thành phần React dùng trong .jsx phải được import hoặc khai ngay trong file.
 *
 * `vite build` KHÔNG bắt được lỗi này: thiếu import thì gói vẫn dựng xong, tới lúc người dùng mở
 * đúng nhánh render mới nổ. Vừa dính thật — thêm khối "bảng trống" dùng icon `ClipboardList` mà
 * quên import, build vẫn xanh, và màn hình chỉ sập đúng vào lúc danh sách không có dòng nào, tức
 * là đúng lúc trường mới nhận hệ thống mở lên xem.
 *
 * Dự án không có ESLint. Đây là lưới rẻ tiền cho đúng một lớp lỗi đó, chạy chung với bộ test sẵn
 * có nên không phải nhớ chạy thêm gì.
 */
class JsxIdentifiersAreImportedTest extends TestCase
{
    /** Tên có sẵn của môi trường / React — không cần import. */
    private const CO_SAN = ['React', 'Fragment', 'Head', 'Suspense', 'StrictMode'];

    public function test_moi_thanh_phan_deu_co_nguon(): void
    {
        $goc = dirname(__DIR__, 3) . '/resources/js';
        $thieu = [];

        foreach ($this->timJsx($goc) as $duong) {
            $ma = $this->boChuThich(file_get_contents($duong));
            $co = array_merge(self::CO_SAN, $this->tenDaCo($ma));

            foreach ($this->tenDangDung($ma) as $ten) {
                if (! in_array($ten, $co, true)) {
                    $thieu[] = str_replace($goc . '/', '', $duong) . ' → <' . $ten . '>';
                }
            }
        }

        $this->assertSame([], $thieu, "Thành phần dùng mà không import (build vẫn xanh, chạy mới nổ):\n" . implode("\n", $thieu));
    }

    /**
     * Bỏ chú thích trước khi soi.
     *
     * Chú thích trong dự án hay viết ví dụ dùng thành phần (`<Input />`, `<Select>`) — soi nguyên
     * văn thì báo nhầm hàng loạt, mà báo nhầm nhiều lần thì người ta bỏ luôn không đọc kết quả.
     * `://` giữ nguyên để không cắt mất đường dẫn trong chuỗi.
     */
    private function boChuThich(string $ma): string
    {
        $ma = preg_replace('#/\*.*?\*/#s', '', $ma) ?? $ma;

        return preg_replace('#(?<!:)//[^\n]*#', '', $ma) ?? $ma;
    }

    /** @return list<string> */
    private function timJsx(string $goc): array
    {
        $ra = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($goc));

        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'jsx') {
                $ra[] = $f->getPathname();
            }
        }

        sort($ra);

        return $ra;
    }

    /**
     * Tên đã có trong file: import các kiểu, và khai tại chỗ (function/const/class).
     *
     * @return list<string>
     */
    private function tenDaCo(string $ma): array
    {
        $ten = [];

        // import A from '...' | import A, { B, C } from '...' | import { B as C } from '...'
        preg_match_all("/^import\s+(.+?)\s+from\s+['\"]/ms", $ma, $m);
        foreach ($m[1] as $menh) {
            // { A, B as C } → lấy tên SAU "as" nếu có, vì đó mới là tên dùng trong file
            preg_match_all('/(?:^|[{,\s])([A-Za-z_$][\w$]*)(?:\s+as\s+([A-Za-z_$][\w$]*))?/', $menh, $mm, PREG_SET_ORDER);
            foreach ($mm as $x) {
                $ten[] = $x[2] ?? $x[1];
            }
        }

        // Lấy từ destructuring: `{ icon: Icon }` (đổi tên) và `setup({ el, App, props })` (tham số).
        // Không có hai cái này thì mọi thành phần nhận qua prop đều bị báo nhầm là thiếu import.
        preg_match_all('/[A-Za-z_$][\w$]*\s*:\s*([A-Z][\w$]*)/', $ma, $mDoi);
        $ten = array_merge($ten, $mDoi[1]);

        $mauThamSo = [
            '/[\w$]+\s*\(\s*\{([^}]*)\}/',      // ham({ A, B })
            '/\(\s*\{([^}]*)\}\s*\)\s*=>/',   // ({ A }) => …  (hay gặp trong .map)
        ];
        foreach ($mauThamSo as $mau) {
            preg_match_all($mau, $ma, $mTs);
            foreach ($mTs[1] as $trong) {
                preg_match_all('/\b([A-Z][\w$]*)\b/', $trong, $x);
                $ten = array_merge($ten, $x[1]);
            }
        }

        // khai tại chỗ
        preg_match_all('/\b(?:function|class)\s+([A-Z][\w$]*)/', $ma, $m2);
        $ten = array_merge($ten, $m2[1]);
        preg_match_all('/\b(?:const|let|var)\s+([A-Z][\w$]*)\s*=/', $ma, $m3);
        $ten = array_merge($ten, $m3[1]);

        return array_values(array_unique($ten));
    }

    /**
     * Tên đang dùng như một thành phần: thẻ JSX `<Ten`, và `icon={Ten}` (dự án truyền icon kiểu này).
     *
     * @return list<string>
     */
    private function tenDangDung(string $ma): array
    {
        preg_match_all('/<([A-Z][\w$]*)/', $ma, $m);
        preg_match_all('/\bicon=\{([A-Z][\w$]*)\}/', $ma, $m2);

        return array_values(array_unique(array_merge($m[1], $m2[1])));
    }
}
