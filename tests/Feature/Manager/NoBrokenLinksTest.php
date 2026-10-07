<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Không để nút bấm nào trỏ vào hư không.
 *
 * Nút "Xem" ở danh sách Khoa từng trỏ vào route KHÔNG nhận tham số, còn controller thì hiển thị
 * khoa của chính người đăng nhập — bấm khoa nào cũng ra 404. Loại lỗi này test chức năng không
 * bắt được (vì chẳng ai viết test cho một cái icon), và chỉ lộ ra khi người dùng bấm thử.
 *
 * Hai phép kiểm dưới đây quét TOÀN BỘ giao diện, nên trang thêm sau này cũng được canh.
 */
class NoBrokenLinksTest extends TestCase
{
    /** @return array<int, array{0:string, 1:string, 2:string}> */
    private static function loiGoiRoute(): array
    {
        $ket = [];
        foreach (glob(resource_path('js') . '/**/*.jsx', GLOB_BRACE) ?: [] as $_) {
            // glob không đệ quy sâu — dùng iterator bên dưới.
        }

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'jsx') {
                continue;
            }
            $noiDung = file_get_contents($file->getPathname());
            preg_match_all("/route\(\s*'([^']+)'\s*(,\s*([^)]{0,40}))?/", $noiDung, $m, PREG_SET_ORDER);
            foreach ($m as $x) {
                $ket[] = [$x[1], trim($x[3] ?? ''), str_replace(base_path() . '/', '', $file->getPathname())];
            }
        }

        return $ket;
    }

    public function test_moi_route_duoc_goi_tu_giao_dien_deu_ton_tai(): void
    {
        $coThat = collect(Route::getRoutes())->pluck('action.as')->filter()->all();

        $thieu = [];
        foreach (self::loiGoiRoute() as [$ten, , $file]) {
            if (! in_array($ten, $coThat, true)) {
                $thieu[] = "{$ten} ({$file})";
            }
        }

        $this->assertSame([], array_values(array_unique($thieu)),
            "Giao diện gọi route không tồn tại:\n" . implode("\n", array_unique($thieu)));
    }

    /**
     * Truyền một GIÁ TRỊ ĐƠN vào route không có tham số — đúng lỗi của nút "Xem" trang Khoa.
     * Truyền OBJECT thì hợp lệ (Ziggy biến thành query string), nên bỏ qua.
     */
    public function test_khong_truyen_tham_so_vao_route_khong_nhan_tham_so(): void
    {
        $soThamSo = [];
        foreach (Route::getRoutes() as $r) {
            if ($ten = $r->getName()) {
                $soThamSo[$ten] = count($r->parameterNames());
            }
        }

        $sai = [];
        foreach (self::loiGoiRoute() as [$ten, $doiSo, $file]) {
            if ($doiSo === '' || ! isset($soThamSo[$ten]) || $soThamSo[$ten] > 0) {
                continue;
            }
            if (str_starts_with($doiSo, '{')) {
                continue; // object ⇒ query string
            }
            $sai[] = "{$ten} ← {$doiSo} ({$file})";
        }

        $this->assertSame([], array_values(array_unique($sai)),
            "Route không có tham số nhưng giao diện vẫn truyền vào:\n" . implode("\n", array_unique($sai)));
    }
}
