<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * KHUNG DỮ LIỆU MẪU — khai báo thuần, không có logic.
 *
 * Bộ mẫu cũ chỉ có 1 trường, 1 ngành, 3 môn tên "Môn IT01" và 1 PLO. Đem ra trình bày cho trường
 * thì không nói lên được gì: không thấy hệ thống chạy đa-trường, không thấy khoa/ngành, và số đo
 * thì môn nào cũng na ná nhau.
 *
 * Nay: 3 trường khác tính chất (kỹ thuật · kinh tế · sư phạm), mỗi trường có khoa và ngành riêng,
 * môn học đặt tên thật, CLO/PLO viết như đề cương thật.
 *
 * ⚠️ TÊN TRƯỜNG CỐ Ý LÀ TÊN HƯ CẤU và mang tiền tố "[DEMO]".
 * Hai lý do, đều đã có bài học:
 *   1. Dữ liệu kiểm thử từng đội lốt trường thật trên production (đặt tên theo NGÀNH, nhìn như
 *      trường) — khách mở danh sách Trường lên là thấy sai ngay.
 *   2. Gắn tên một trường CÓ THẬT vào điểm số bịa rồi xuất ra báo cáo là chuyện không nên làm,
 *      dù chỉ để demo — file đó có thể bị gửi đi.
 *
 * Phần tử thứ 5 của một môn (nếu có) là ĐỘ LỆCH ĐIỂM — cố ý cho vài môn khó hơn hẳn, để có PLO
 * KHÔNG đạt mục tiêu. Demo mà bảng nào cũng xanh 100% thì không cho người xem thấy hệ thống biết
 * phát hiện vấn đề, mà đó mới là công dụng chính của việc đo chuẩn đầu ra.
 *
 * Khóa để 2026 chứ không phải 2023/2024: khóa ≤2024 chạy phương án CHỈ ĐO CLO, không lên PI/PLO,
 * nên bảng mức đạt sẽ trống trơn — đúng lỗi đã gặp khi dựng bộ này lần đầu.
 */
final class DemoSchools
{
    /** Tiền tố mã của mọi trường mẫu — dùng để dọn sạch, kể cả bản mẫu đời cũ. */
    public const PREFIX = 'DEMO';

    /** Mã trường mẫu đời cũ (chỉ có 1 trường) — vẫn phải dọn được. */
    public const LEGACY_CODE = 'DEMO';

    /**
     * @return array<int, array{code:string, name:string, gv:string, programs:array<int, array<string,mixed>>}>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'DEMO-KT',
                'name' => '[DEMO] Đại học Kỹ thuật Tân Việt',
                'gv' => 'DEMO-GV-KT',
                'programs' => [
                    [
                        'code' => 'KTPM',
                        'name' => 'Kỹ thuật phần mềm',
                        'khoa' => 'Khoa Công nghệ thông tin',
                        'cohort' => 'Khóa 2026',
                        'year' => 2026,
                        'plos' => [
                            ['PLO1', 'Vận dụng kiến thức nền tảng về lập trình và cấu trúc dữ liệu để xây dựng phần mềm.', [
                                ['PI1.1', 'Viết chương trình đúng đặc tả yêu cầu', 0.6],
                                ['PI1.2', 'Lựa chọn cấu trúc dữ liệu phù hợp bài toán', 0.4],
                            ]],
                            ['PLO2', 'Phân tích, thiết kế và kiểm thử hệ thống phần mềm theo quy trình.', [
                                ['PI2.1', 'Phân tích và mô hình hóa yêu cầu', 0.5],
                                ['PI2.2', 'Thiết kế ca kiểm thử và gỡ lỗi', 0.5],
                            ]],
                        ],
                        'courses' => [
                            ['IT101', 'Nhập môn lập trình', 3, [['CLO1', 'Viết được chương trình cơ bản', 'PI1.1', 'I'], ['CLO2', 'Sử dụng kiểu dữ liệu đúng mục đích', 'PI1.2', 'I']]],
                            ['IT202', 'Cấu trúc dữ liệu và giải thuật', 4, [['CLO1', 'Cài đặt được cấu trúc dữ liệu cơ bản', 'PI1.1', 'R'], ['CLO2', 'Đánh giá độ phức tạp giải thuật', 'PI1.2', 'M']], -1.4],
                            ['IT303', 'Công nghệ phần mềm', 3, [['CLO1', 'Mô hình hóa yêu cầu người dùng', 'PI2.1', 'M'], ['CLO2', 'Áp dụng quy trình phát triển phần mềm', 'PI2.1', 'R']]],
                            ['IT304', 'Kiểm thử phần mềm', 3, [['CLO1', 'Thiết kế ca kiểm thử', 'PI2.2', 'M'], ['CLO2', 'Phân tích và gỡ lỗi', 'PI2.2', 'A']], -2.6],
                            ['IT405', 'Đồ án chuyên ngành', 4, [['CLO1', 'Xây dựng phần mềm hoàn chỉnh', 'PI1.1', 'A'], ['CLO2', 'Trình bày và bảo vệ giải pháp', 'PI2.1', 'A']]],
                        ],
                    ],
                    [
                        'code' => 'KTDK',
                        'name' => 'Kỹ thuật điều khiển và Tự động hóa',
                        'khoa' => 'Khoa Điện – Điện tử',
                        'cohort' => 'Khóa 2026',
                        'year' => 2026,
                        'plos' => [
                            ['PLO1', 'Vận dụng nguyên lý điều khiển tự động vào hệ thống công nghiệp.', [
                                ['PI1.1', 'Phân tích đặc tính hệ thống điều khiển', 0.5],
                                ['PI1.2', 'Thiết kế bộ điều khiển đáp ứng yêu cầu', 0.5],
                            ]],
                        ],
                        'courses' => [
                            ['DK201', 'Lý thuyết điều khiển tự động', 4, [['CLO1', 'Phân tích ổn định hệ thống', 'PI1.1', 'M'], ['CLO2', 'Khảo sát đáp ứng quá độ', 'PI1.1', 'R']]],
                            ['DK302', 'Điều khiển logic khả trình (PLC)', 3, [['CLO1', 'Lập trình PLC cho dây chuyền', 'PI1.2', 'M'], ['CLO2', 'Kết nối cảm biến và cơ cấu chấp hành', 'PI1.2', 'R']]],
                            ['DK403', 'Hệ thống SCADA', 3, [['CLO1', 'Thiết kế giao diện giám sát', 'PI1.2', 'A'], ['CLO2', 'Xử lý cảnh báo hệ thống', 'PI1.1', 'A']]],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'DEMO-KTE',
                'name' => '[DEMO] Đại học Kinh tế Hải Đăng',
                'gv' => 'DEMO-GV-KTE',
                'programs' => [
                    [
                        'code' => 'QTKD',
                        'name' => 'Quản trị kinh doanh',
                        'khoa' => 'Khoa Quản trị',
                        'cohort' => 'Khóa 2026',
                        'year' => 2026,
                        'plos' => [
                            ['PLO1', 'Vận dụng kiến thức quản trị vào điều hành hoạt động doanh nghiệp.', [
                                ['PI1.1', 'Phân tích môi trường kinh doanh', 0.4],
                                ['PI1.2', 'Hoạch định và tổ chức thực hiện', 0.6],
                            ]],
                            ['PLO2', 'Ra quyết định kinh doanh dựa trên dữ liệu và phân tích tài chính.', [
                                ['PI2.1', 'Đọc và phân tích báo cáo tài chính', 0.5],
                                ['PI2.2', 'Đề xuất phương án và đánh giá rủi ro', 0.5],
                            ]],
                        ],
                        'courses' => [
                            ['QT101', 'Quản trị học', 3, [['CLO1', 'Trình bày các chức năng quản trị', 'PI1.1', 'I'], ['CLO2', 'Vận dụng vào tình huống doanh nghiệp', 'PI1.2', 'R']]],
                            ['QT202', 'Quản trị chiến lược', 3, [['CLO1', 'Phân tích môi trường ngành', 'PI1.1', 'M'], ['CLO2', 'Xây dựng chiến lược cạnh tranh', 'PI1.2', 'M']]],
                            ['QT203', 'Quản trị nhân lực', 3, [['CLO1', 'Thiết kế quy trình tuyển dụng', 'PI1.2', 'R'], ['CLO2', 'Đánh giá hiệu quả nhân sự', 'PI1.2', 'M']], -1.5],
                            ['TC301', 'Phân tích báo cáo tài chính', 4, [['CLO1', 'Đọc hiểu báo cáo tài chính', 'PI2.1', 'M'], ['CLO2', 'Tính và diễn giải các tỉ số', 'PI2.1', 'A']], -2.8],
                            ['QT404', 'Đề án khởi nghiệp', 4, [['CLO1', 'Xây dựng phương án kinh doanh', 'PI2.2', 'A'], ['CLO2', 'Đánh giá rủi ro và tính khả thi', 'PI2.2', 'M']]],
                        ],
                    ],
                    [
                        'code' => 'TCNH',
                        'name' => 'Tài chính – Ngân hàng',
                        'khoa' => 'Khoa Tài chính',
                        'cohort' => 'Khóa 2026',
                        'year' => 2026,
                        'plos' => [
                            ['PLO1', 'Vận dụng nghiệp vụ tài chính – ngân hàng trong thực tiễn.', [
                                ['PI1.1', 'Thực hiện nghiệp vụ tín dụng', 0.5],
                                ['PI1.2', 'Phân tích và quản trị rủi ro', 0.5],
                            ]],
                        ],
                        'courses' => [
                            ['NH201', 'Nghiệp vụ ngân hàng thương mại', 3, [['CLO1', 'Thực hiện quy trình cấp tín dụng', 'PI1.1', 'M'], ['CLO2', 'Thẩm định hồ sơ vay', 'PI1.1', 'R']]],
                            ['NH302', 'Quản trị rủi ro tài chính', 3, [['CLO1', 'Nhận diện rủi ro tài chính', 'PI1.2', 'M'], ['CLO2', 'Đề xuất biện pháp phòng ngừa', 'PI1.2', 'A']], -1.6],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'DEMO-SP',
                'name' => '[DEMO] Đại học Sư phạm Ánh Dương',
                'gv' => 'DEMO-GV-SP',
                'programs' => [
                    [
                        'code' => 'SPTA',
                        'name' => 'Sư phạm Tiếng Anh',
                        'khoa' => 'Khoa Ngoại ngữ',
                        'cohort' => 'Khóa 2026',
                        'year' => 2026,
                        'plos' => [
                            ['PLO1', 'Sử dụng thành thạo tiếng Anh ở trình độ C1 trong môi trường sư phạm.', [
                                ['PI1.1', 'Nghe – nói học thuật', 0.5],
                                ['PI1.2', 'Đọc – viết học thuật', 0.5],
                            ]],
                            ['PLO2', 'Thiết kế và tổ chức hoạt động dạy học tiếng Anh hiệu quả.', [
                                ['PI2.1', 'Thiết kế kế hoạch bài dạy', 0.6],
                                ['PI2.2', 'Đánh giá kết quả học tập của người học', 0.4],
                            ]],
                        ],
                        'courses' => [
                            ['EN101', 'Nghe – Nói nâng cao', 3, [['CLO1', 'Trình bày quan điểm mạch lạc', 'PI1.1', 'M'], ['CLO2', 'Tham gia thảo luận học thuật', 'PI1.1', 'R']]],
                            ['EN102', 'Đọc – Viết học thuật', 3, [['CLO1', 'Viết bài luận có luận điểm rõ', 'PI1.2', 'M'], ['CLO2', 'Tổng hợp tài liệu tham khảo', 'PI1.2', 'R']], -2.4],
                            ['EN203', 'Phương pháp giảng dạy tiếng Anh', 4, [['CLO1', 'Thiết kế kế hoạch bài dạy', 'PI2.1', 'M'], ['CLO2', 'Lựa chọn phương pháp phù hợp người học', 'PI2.1', 'A']]],
                            ['EN304', 'Kiểm tra đánh giá trong dạy học', 3, [['CLO1', 'Thiết kế công cụ đánh giá', 'PI2.2', 'M'], ['CLO2', 'Phân tích kết quả đánh giá', 'PI2.2', 'A']], -1.5],
                        ],
                    ],
                ],
            ],
        ];
    }
}
