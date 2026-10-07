<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Đọc workbook "Khung CTĐT" nhiều sheet thành mảng rows theo tên sheet,
 * rồi StructureBuilderService dựng/cập nhật cấu trúc theo thứ tự phụ thuộc.
 */
class StructureImport implements WithMultipleSheets
{
    /**
     * 4 sheet đầu là FILE KHAI BÁO CHƯƠNG TRÌNH mới (khách chốt ở buổi họp lần 1); các sheet sau là
     * định dạng cũ, vẫn nhận để file trường đang dùng không hỏng.
     */
    public const SHEETS = [
        'Nganh_Khoa', 'PLO_PI', 'MonHoc', 'MaTran_CTDT', 'SinhVien',
        'Nganh', 'Khoa', 'Mon', 'CLO', 'BaiDanhGia', 'BaiDanhGia_CLO', 'PLO', 'PI', 'CLO_PI', 'Lop', 'ThamSo',
    ];

    /** @var array<string,Collection> sheet-name => rows */
    public array $data = [];

    /** @param array<int,string> $availableSheets tên các sheet THỰC SỰ có trong file (rỗng = mặc định tất cả) */
    public function __construct(private array $availableSheets = []) {}

    public function sheets(): array
    {
        // Chỉ đăng ký sheet CÓ trong file (tránh "out of bounds" khi file thiếu sheet).
        $available = $this->availableSheets ?: self::SHEETS;

        $map = [];
        foreach (self::SHEETS as $name) {
            if (! in_array($name, $available, true)) {
                continue;
            }
            // MaTran_CTDT: tiêu đề cột là MÃ PLO/PI ("PI2.1"). Đọc kiểu có dòng tiêu đề thì thư viện đổi
            // tiêu đề thành khóa không dấu "pi21" — mất dấu chấm, không biết là PI nào. Đọc dòng gốc.
            if ($name === 'MaTran_CTDT') {
                $map[$name] = new class($this, $name) implements ToCollection {
                    public function __construct(private StructureImport $parent, private string $name) {}

                    public function collection(Collection $rows): void
                    {
                        $this->parent->data[$this->name] = $rows->map(
                            fn ($r) => $r instanceof Collection ? $r->values()->all() : array_values((array) $r),
                        );
                    }
                };
                continue;
            }

            $map[$name] = new class($this, $name) implements ToCollection, WithHeadingRow {
                public function __construct(private StructureImport $parent, private string $name) {}

                public function collection(Collection $rows): void
                {
                    // Dòng trắng hẳn (xóa dòng ví dụ trong Excel vẫn để lại ô rỗng) không phải dòng khai
                    // báo — bỏ đi, giữ nguyên khóa để lỗi vẫn báo đúng số dòng.
                    $this->parent->data[$this->name] = $rows->filter(
                        fn ($r) => collect($r)->contains(fn ($v) => $v !== null && trim((string) $v) !== ''),
                    );
                }
            };
        }

        return $map;
    }
}
