<?php

declare(strict_types=1);

namespace App\Exports;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Xuất "BÁO CÁO HỌC PHẦN" ra Word đúng mẫu của trường: cùng thứ tự mục, cùng bảng, cùng chỗ ký.
 *
 * Số liệu do `CourseReportService` dựng. Mục 4 (đánh giá chung và đề xuất cải tiến) là nhận xét của
 * giảng viên nên để trống sẵn dòng cho từng CLO — phần mềm không nghĩ thay người dạy.
 */
final class CourseReportDocx
{
    private const FONT = 'Times New Roman';

    private const BORDER = ['borderSize' => 6, 'borderColor' => '000000', 'cellMargin' => 60];

    /** @param array<string,mixed> $data kết quả `CourseReportService::build()` */
    public function __construct(private array $data, private string $schoolName = 'TRƯỜNG ĐẠI HỌC HÙNG VƯƠNG') {}

    public function save(string $path): string
    {
        $word = new PhpWord();
        $word->setDefaultFontName(self::FONT);
        $word->setDefaultFontSize(13);
        $word->addTitleStyle(1, ['bold' => true, 'size' => 14], ['spaceBefore' => 240, 'spaceAfter' => 120]);

        $section = $word->addSection(['marginTop' => 1000, 'marginBottom' => 1000, 'marginLeft' => 1200, 'marginRight' => 1000]);

        $this->quocHieu($section);
        $this->tieuDe($section);
        $this->thongTinChung($section);
        $this->phuongPhap($section);
        $this->danhGia($section);
        $this->danhGiaChung($section);
        $this->chuKy($section);

        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /** Tên file gợi ý: "10122_Kiem soat_HK2_2025-2026.docx". */
    public function fileName(): string
    {
        $hp = $this->data['hoc_phan'];
        $phan = array_filter([
            $hp['ma'],
            $this->khongDau((string) $hp['ten']),
            $this->data['hoc_ky'] ? 'HK' . $this->data['hoc_ky'] : null,
            $this->data['nam_hoc'] ?: null,
        ]);

        return implode('_', $phan) . '.docx';
    }

    private function quocHieu(Section $section): void
    {
        $table = $section->addTable(['cellMargin' => 0]);
        $table->addRow();

        $left = $table->addCell(4400);
        $left->addText('BỘ GIÁO DỤC VÀ ĐÀO TẠO', ['size' => 12], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $left->addText(mb_strtoupper($this->schoolName), ['size' => 12, 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);

        $right = $table->addCell(5200);
        $right->addText('CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM', ['size' => 12, 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $right->addText('Độc lập - Tự do - Hạnh phúc', ['size' => 13, 'bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $right->addText('__________________', ['size' => 11], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $right->addText($this->ngayThangNam(), ['size' => 12, 'italic' => true], ['alignment' => Jc::CENTER, 'spaceBefore' => 120]);
    }

    private function tieuDe(Section $section): void
    {
        $hp = $this->data['hoc_phan'];
        $lop = implode(', ', $this->data['lop']);

        $section->addTextBreak(1);
        $section->addText('BÁO CÁO HỌC PHẦN', ['bold' => true, 'size' => 16], ['alignment' => Jc::CENTER, 'spaceAfter' => 60]);
        $section->addText(
            '(Học kỳ ' . ($this->data['hoc_ky'] ?? '…') . ' năm học ' . ($this->data['nam_hoc'] ?: '…') . ')',
            ['italic' => true],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 120],
        );
        $section->addText('Học phần: ' . $hp['ten'], ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $section->addText(
            'Mã học phần: ' . $hp['ma'] . ($lop !== '' ? ' (' . $lop . ')' : ''),
            ['bold' => true],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 240],
        );
    }

    private function thongTinChung(Section $section): void
    {
        $hp = $this->data['hoc_phan'];
        $gv = implode(', ', $this->data['giang_vien']);

        $section->addText('1. THÔNG TIN CHUNG', ['bold' => true], ['spaceAfter' => 60]);
        $this->gach($section, 'Tên học phần: ' . $hp['ten']);
        $this->gach($section, 'Thời lượng (tổng số tiết): ' . ($hp['so_tiet'] ? $hp['so_tiet'] . ' tiết' : '…'));
        $this->gach($section, 'Số tín chỉ: ' . $this->so($hp['tin_chi']));
        $this->gach($section, 'Lớp: ' . (implode(', ', $this->data['lop']) ?: '…'));
        $this->gach($section, 'Giảng viên tham gia giảng dạy: ' . ($gv ?: '…'));
        $this->gach($section, 'Khoa: ' . ($this->data['khoa'] ?: '…'));
        $this->gach($section, 'Bộ môn phụ trách: ' . ($hp['bo_mon'] ?: '…'));
        $this->gach($section, 'Giảng viên thực hiện báo cáo học phần: ' . ($gv ?: '…'));
    }

    private function phuongPhap(Section $section): void
    {
        $section->addText('2. PHƯƠNG PHÁP GIẢNG DẠY VÀ ĐÁNH GIÁ KẾT QUẢ HỌC TẬP', ['bold' => true], ['spaceBefore' => 200, 'spaceAfter' => 80]);

        $table = $section->addTable(self::BORDER);
        $table->addRow(null, ['tblHeader' => true]);
        foreach ([['Phương pháp', 3200], ['Có sử dụng', 1400], ['CLO', 2200], ['Mô tả cách thức sử dụng phương pháp', 3000]] as [$ten, $w]) {
            $table->addCell($w, ['bgColor' => 'EFEFEF'])->addText($ten, ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
        }

        foreach ($this->data['phuong_phap'] as $row) {
            $table->addRow();
            $table->addCell(3200)->addText($row['ten'], ['size' => 12]);
            $table->addCell(1400)->addText($row['co_su_dung'] ? 'x' : '', ['size' => 12], ['alignment' => Jc::CENTER]);
            $table->addCell(2200)->addText(implode(', ', $row['clos']), ['size' => 12]);
            $table->addCell(3000)->addText(
                $row['bai'] === [] ? '' : 'Đánh giá qua ' . implode(', ', $row['bai']),
                ['size' => 12],
            );
        }

        // Phiếu nhập bằng định dạng cũ không có dòng Hình thức, nên không đánh dấu giúp được.
        $chuaKhai = $this->data['phuong_phap_chua_khai'] ?? [];
        if ($chuaKhai !== []) {
            $section->addText(
                'Các bài ' . implode(', ', $chuaKhai) . ' chưa khai hình thức câu hỏi trong phiếu lớp học phần, '
                . 'nên giảng viên tự đánh dấu các dòng trên.',
                ['italic' => true, 'size' => 11],
                ['spaceBefore' => 60],
            );
        }
    }

    private function danhGia(Section $section): void
    {
        $tk = $this->data['thong_ke'];

        $section->addText('3. ĐÁNH GIÁ', ['bold' => true], ['spaceBefore' => 200, 'spaceAfter' => 60]);
        $section->addText('a. Kết quả thống kê:', ['bold' => true, 'italic' => true], ['spaceAfter' => 40]);
        $this->gach($section, 'Tổng số sinh viên đăng ký học phần: ' . $tk['dang_ky']);
        $this->gach($section, 'Số sinh viên dự thi cuối kỳ: ' . $tk['du_thi_cuoi_ky']);
        $this->gach($section, 'Số sinh viên qua môn (ĐTB từ ' . $this->so($this->data['nguong']['mon']) . ' trở lên): ' . $tk['qua_mon']);

        $section->addText('b. Đánh giá theo chuẩn đầu ra:', ['bold' => true, 'italic' => true], ['spaceBefore' => 120, 'spaceAfter' => 60]);

        foreach ($this->data['clos'] as $clo) {
            $this->bangClo($section, $clo);
        }
    }

    /** @param array<string,mixed> $clo */
    private function bangClo(Section $section, array $clo): void
    {
        $section->addText(
            $clo['code'] . ': ' . $clo['description'],
            ['bold' => true, 'size' => 12],
            ['spaceBefore' => 160, 'spaceAfter' => 40],
        );
        $section->addText(
            'Mục tiêu: Yêu cầu trên ' . $this->so($clo['muc_tieu']) . '% sinh viên đạt.',
            ['italic' => true, 'size' => 12],
            ['spaceAfter' => 60],
        );

        $table = $section->addTable(self::BORDER);

        $table->addRow(null, ['tblHeader' => true]);
        $table->addCell(3600, ['vMerge' => 'restart', 'bgColor' => 'EFEFEF'])->addText('Phương pháp đánh giá', ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
        $table->addCell(2000, ['vMerge' => 'restart', 'bgColor' => 'EFEFEF'])->addText('Lớp', ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
        $table->addCell(2600, ['gridSpan' => 2, 'bgColor' => 'EFEFEF'])->addText('Số lượng sinh viên', ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
        $table->addCell(1600, ['vMerge' => 'restart', 'bgColor' => 'EFEFEF'])->addText('Ghi chú', ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);

        $table->addRow(null, ['tblHeader' => true]);
        $table->addCell(3600, ['vMerge' => 'continue']);
        $table->addCell(2000, ['vMerge' => 'continue']);
        $table->addCell(1300, ['bgColor' => 'EFEFEF'])->addText('Đạt', ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
        $table->addCell(1300, ['bgColor' => 'EFEFEF'])->addText('Không đạt', ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
        $table->addCell(1600, ['vMerge' => 'continue']);

        foreach ($clo['dong'] as $dong) {
            $dau = true;
            foreach ($dong['lop'] as $lop) {
                $table->addRow();
                // Mẫu của trường ghi tên phương pháp một lần rồi để trống ở các lớp tiếp theo.
                $table->addCell(3600)->addText($dau ? $dong['phuong_phap'] : '', ['size' => 12]);
                $table->addCell(2000)->addText((string) $lop['lop'], ['size' => 12], ['alignment' => Jc::CENTER]);
                $table->addCell(1300)->addText((string) $lop['dat'], ['size' => 12], ['alignment' => Jc::CENTER]);
                $table->addCell(1300)->addText((string) $lop['khong_dat'], ['size' => 12], ['alignment' => Jc::CENTER]);
                $table->addCell(1600);
                $dau = false;
            }
        }

        $tong = $clo['tong'];

        $table->addRow();
        $table->addCell(3600, ['vMerge' => 'restart'])->addText('TỔNG CỘNG', ['bold' => true, 'size' => 12]);
        $table->addCell(2000)->addText('Số lượng', ['size' => 12], ['alignment' => Jc::CENTER]);
        $table->addCell(1300)->addText((string) $tong['dat'], ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
        $table->addCell(1300)->addText((string) $tong['khong_dat'], ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
        $table->addCell(1600, ['vMerge' => 'restart'])->addText($tong['ket_luan'], ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);

        $table->addRow();
        $table->addCell(3600, ['vMerge' => 'continue']);
        $table->addCell(2000)->addText('Tỷ lệ', ['size' => 12], ['alignment' => Jc::CENTER]);
        $table->addCell(1300)->addText($this->phanTram($tong['ty_le_dat']), ['size' => 12], ['alignment' => Jc::CENTER]);
        $table->addCell(1300)->addText($this->phanTram($tong['ty_le_khong_dat']), ['size' => 12], ['alignment' => Jc::CENTER]);
        $table->addCell(1600, ['vMerge' => 'continue']);
    }

    private function danhGiaChung(Section $section): void
    {
        $section->addText('4. ĐÁNH GIÁ CHUNG VÀ ĐỀ XUẤT CẢI TIẾN', ['bold' => true], ['spaceBefore' => 220, 'spaceAfter' => 60]);
        $section->addText('a. Đánh giá chung:', ['bold' => true, 'italic' => true], ['spaceAfter' => 40]);

        foreach ($this->data['clos'] as $clo) {
            $this->gach($section, $clo['code'] . ': ');
        }

        $section->addText('b. Đề xuất cải tiến:', ['bold' => true, 'italic' => true], ['spaceBefore' => 120, 'spaceAfter' => 40]);
        $section->addText(
            'Các hành động chính sẽ được thực hiện trong học kỳ tiếp theo để cải thiện kết quả học tập của sinh viên như sau:',
            [],
            ['spaceAfter' => 40],
        );
        foreach (['Đối với phương pháp giảng dạy: ', 'Đối với phương pháp đánh giá: ', 'Đối với khoa/bộ môn: ', 'Đối với sinh viên: '] as $muc) {
            $this->gach($section, $muc);
        }
    }

    private function chuKy(Section $section): void
    {
        $section->addTextBreak(1);

        $table = $section->addTable(['cellMargin' => 0]);
        $table->addRow();

        foreach (['PHỤ TRÁCH BỘ MÔN', 'GIẢNG VIÊN PHỤ TRÁCH'] as $vai) {
            $cell = $table->addCell(4800);
            $cell->addText($vai, ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            $cell->addText('(ký và ghi rõ họ tên)', ['italic' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
            $cell->addTextBreak(4);
        }
    }

    private function gach(Section $section, string $text): void
    {
        $section->addText('- ' . $text, [], ['spaceAfter' => 20, 'indentation' => ['left' => 220]]);
    }

    private function phanTram(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 2, ',', '.') . '%';
    }

    private function so(float|int|null $v): string
    {
        if ($v === null) {
            return '…';
        }

        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }

    private function ngayThangNam(): string
    {
        $now = now();

        return sprintf('Thành phố Hồ Chí Minh, ngày %02d tháng %02d năm %d', $now->day, $now->month, $now->year);
    }

    private function khongDau(string $s): string
    {
        $s = \Illuminate\Support\Str::ascii($s);

        return trim(preg_replace('/[^A-Za-z0-9]+/', ' ', $s) ?? '');
    }
}
