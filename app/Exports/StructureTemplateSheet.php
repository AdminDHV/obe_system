<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** 1 sheet của template/khung cấu trúc (tiêu đề + headings + dòng dữ liệu). */
class StructureTemplateSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        private string $sheetTitle,
        private array $headings,
        private array $rows,
    ) {}

    public function title(): string { return $this->sheetTitle; }
    public function headings(): array { return $this->headings; }
    public function array(): array { return $this->rows; }
}
