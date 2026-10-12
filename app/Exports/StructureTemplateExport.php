<?php

declare(strict_types=1);

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * File mẫu KHAI BÁO CHƯƠNG TRÌNH — mỗi khóa khai một lần cho cả chương trình.
 *
 * Khách chốt ở buổi họp lần 1: chỉ còn 2 file — file này và phiếu từng lớp học phần. Góp ý sau đó: file này
 * KHÔNG nhập CLO (CLO và bảng CLO – PLO/PI của đề cương nhập ở phiếu từng môn), mà nhập ma trận môn × PLO/PI
 * đúng bảng "Ma trận chuẩn đầu ra các học phần trong chương trình đào tạo" của CTĐT (sheet `MaTran_CTDT`).
 *
 * Dòng 1 mỗi sheet là tên cột (StructureImport đọc theo tên cột). Dòng ví dụ lấy từ chương trình
 * đào tạo Thạc sĩ Tài chính – Ngân hàng mà khách gửi — xóa đi rồi điền của trường mình.
 */
class StructureTemplateExport
{
    public function build(): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        $this->sheet($book, 'HuongDan', ['Hướng dẫn'], [
            ['File này khai MỘT LẦN vào ĐẦU KHÓA cho cả chương trình. Tải lại file đã sửa là cập nhật, không nhân đôi.'],
            ['Cột Bậc ở sheet Nganh_Khoa ghi "Đại học" hoặc "Thạc sĩ" — quyết định ngưỡng đạt theo quy chế (đại học qua môn 4,0; thạc sĩ 5,5). Bỏ trống thì dùng ngưỡng đang khai ở màn hình Trường.'],
            ['Số tiết và Bộ môn phụ trách ở sheet MonHoc dùng cho mục Thông tin chung của báo cáo học phần; bỏ trống được.'],
            ['Loại học phần ở sheet MonHoc ghi "Bắt buộc" hoặc "Tự chọn". Học phần tự chọn ghi thêm Khối / Hướng (vd "Hướng Tài chính doanh nghiệp"); các môn cùng tên khối vào chung một khối. Bắt buộc thì bỏ trống Khối / Hướng được. Hai cột này để trống thì giữ nguyên khối đã xếp ở màn hình Danh mục → Cấu trúc CTĐT; số tín chỉ yêu cầu của từng khối đặt ở màn hình đó.'],
            ['Nganh_Khoa: mỗi khóa một dòng. PLO_PI: mỗi PI một dòng; PLO nào không chia PI thì để trống Mã PI.'],
            ['Trọng số các PI của một PLO phải cộng lại đúng 1.'],
            ['MonHoc: mọi môn của chương trình. SinhVien: danh sách sinh viên (không bắt buộc — phiếu lớp học phần tự thêm sinh viên chưa có).'],
            ['MaTran_CTDT: chép bảng "Ma trận chuẩn đầu ra các học phần trong chương trình đào tạo" của CTĐT — mỗi môn một dòng, mỗi PLO/PI một cột, ô ghi I, R, M hoặc A.'],
            ['Cột PLO áp mức cho mọi PI của PLO đó; muốn ghi riêng từng PI thì dùng cột mã PI (vd PI2.1) thay cho cột PLO. Trộn hai kiểu cột trong cùng bảng được, miễn đừng vừa có cột PLO2 vừa có cột PI của chính PLO2.'],
            ['Chừa sẵn cột cho PLO chưa dùng cũng được: cột để trống thì bỏ qua, chỉ báo lỗi nếu ghi mức vào cột chưa khai ở sheet PLO_PI. Ma trận áp cho các khóa khai ở sheet Nganh_Khoa của chính file này.'],
            ['Không khai CLO ở file này: CLO và bảng CLO – PLO/PI của đề cương khai ở PHIẾU LỚP HỌC PHẦN. Phiếu nối CLO vào PLO/PI mà ma trận CTĐT không ghi môn đó đóng góp thì phiếu bị chặn.'],
            ['Mã ngành có thể bỏ trống ở các sheet sau nếu file chỉ khai một ngành.'],
        ]);

        $this->sheet($book, 'Nganh_Khoa', ['Mã ngành', 'Tên ngành', 'Bậc', 'Khoa quản lý', 'Tên khóa', 'Năm bắt đầu'], [
            ['8340201', 'Tài chính – Ngân hàng', 'Thạc sĩ', 'Khoa Tài chính – Ngân hàng – Kế toán', 'Khóa 2026', 2026],
        ]);

        $this->sheet($book, 'PLO_PI', ['Mã ngành', 'Mã PLO', 'Mô tả PLO', 'Mã PI', 'Mô tả PI', 'Trọng số PI'], [
            ['8340201', 'PLO1', 'Hệ thống hóa các kiến thức chuyên sâu trong lĩnh vực Tài chính – Ngân hàng', null, null, null],
            ['8340201', 'PLO2', 'Cập nhật, phân tích, đánh giá và ứng dụng kiến thức tiên tiến vào thực tiễn doanh nghiệp', 'PI2.1', 'Phân tích được vấn đề thực tiễn', 0.5],
            ['8340201', 'PLO2', null, 'PI2.2', 'Ứng dụng lý thuyết để đề xuất giải pháp', 0.5],
            ['8340201', 'PLO5', 'Phát triển kỹ năng tổ chức quản trị và phân tích phản biện', null, null, null],
        ]);

        $this->sheet($book, 'MonHoc', ['Mã ngành', 'Mã môn', 'Tên môn', 'Tín chỉ', 'Số tiết', 'Bộ môn phụ trách', 'Loại học phần', 'Khối / Hướng'], [
            ['8340201', '81001', 'Triết học', 4, 60, 'Bộ môn Lý luận chính trị', 'Bắt buộc', null],
            ['8340201', '81002', 'Phương pháp nghiên cứu trong kinh doanh (định tính)', 3, 45, 'Bộ môn Quản trị Kinh doanh', 'Bắt buộc', null],
            ['8340201', '81003', 'Phân tích chính sách tiền tệ và tài khóa', 3, 45, 'Bộ môn Tài chính – Ngân hàng', 'Tự chọn', 'Hướng Tài chính doanh nghiệp'],
        ]);
        $this->listValidation($book->getSheetByName('MonHoc'), 'G2:G500', 'Bắt buộc,Tự chọn', 'Ghi "Bắt buộc" hoặc "Tự chọn".');
        $book->getSheetByName('MonHoc')->getColumnDimension('H')->setWidth(32);

        // Ví dụ lấy từ mục 13 "Ma trận chuẩn đầu ra các học phần" của CTĐT Thạc sĩ TCNH khách gửi.
        $this->sheet($book, 'MaTran_CTDT', ['Mã môn', 'Tên môn (ghi cho dễ đọc)', 'PLO1', 'PLO2', 'PLO5'], [
            ['81001', 'Triết học', 'R', null, 'R'],
            ['81002', 'Phương pháp nghiên cứu trong kinh doanh (định tính)', 'R', null, 'R'],
            ['81003', 'Phân tích chính sách tiền tệ và tài khóa', 'M', null, 'M'],
        ]);
        $this->levelValidation($book->getSheetByName('MaTran_CTDT'), 'C2:Z500');

        $this->sheet($book, 'SinhVien', ['MSSV', 'Họ và tên', 'Mã ngành', 'Năm khóa'], [
            ['2612TC0001', 'Nguyễn Văn An', '8340201', 2026],
            ['2612TC0002', 'Trần Thị Bình', '8340201', 2026],
        ]);

        $book->setActiveSheetIndex(1);

        return $book;
    }

    private function levelValidation(Worksheet $ws, string $range): void
    {
        [$first] = explode(':', $range);
        $dv = $ws->getCell($first)->getDataValidation();
        $dv->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Mức không hợp lệ')
            ->setError('Chỉ ghi I, R, M hoặc A.')
            ->setFormula1('"I,R,M,A"');
        $dv->setSqref($range);
    }

    private function listValidation(Worksheet $ws, string $range, string $values, string $error): void
    {
        [$first] = explode(':', $range);
        $dv = $ws->getCell($first)->getDataValidation();
        $dv->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Giá trị không hợp lệ')
            ->setError($error)
            ->setFormula1('"' . $values . '"');
        $dv->setSqref($range);
    }

    /**
     * @param array<int,string> $headers
     * @param array<int,array<int,mixed>> $rows
     */
    private function sheet(Spreadsheet $book, string $title, array $headers, array $rows): void
    {
        $ws = new Worksheet($book, $title);
        $book->addSheet($ws);

        $ws->fromArray($headers, null, 'A1');
        $ws->fromArray($rows, null, 'A2', true);

        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $ws->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $ws->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDE7F3');
        foreach (range(1, count($headers)) as $c) {
            $ws->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))
                ->setWidth($title === 'HuongDan' ? 110 : 22);
        }
        // Mã ngành / MSSV / mã môn giữ dạng chữ để Excel không cắt số 0 ở đầu.
        $ws->getStyle('A:C')->getNumberFormat()->setFormatCode('@');
        $ws->freezePane('A2');
    }
}
