<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\FileBatch;
use App\Models\School;
use App\Models\User;
use App\Support\ImportOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MỌI lần import phải kết thúc bằng một trạng thái thật — không được nằm mãi ở "Đang xử lý".
 *
 * Trên production đã có 3 batch kẹt "Đang xử lý" suốt hai tuần dù dữ liệu vào đủ (42/42 ·
 * 2580/2580 · 4338/4338), vì `obe:validate-client-data` tạo FileBatch rồi chạy import đồng bộ và
 * không bao giờ đánh dấu xong. Màn hình thì hiện "đang xử lý lâu hơn thường lệ — kiểm tra worker",
 * đẩy người vận hành đi tìm sự cố worker không hề tồn tại.
 *
 * Cảnh báo sai còn tai hại hơn không cảnh báo: lần sau worker hỏng thật thì không ai tin nữa.
 */
class ImportOutcomeFinalizedTest extends TestCase
{
    use RefreshDatabase;

    private function batch(int $ok, int $total): FileBatch
    {
        $school = School::create(['code' => 'S1', 'name' => 'Trường 1']);

        return FileBatch::create([
            'user_id' => User::factory()->create(['school_id' => $school->id])->id,
            'school_id' => $school->id,
            'file_name' => 'diem.xlsx',
            'file_path' => 'imports/diem.xlsx',
            'type' => 'grade_bulk',
            'status' => 'processing',
            'processed_rows' => $ok,
            'total_rows' => $total,
        ]);
    }

    public function test_xu_ly_het_dong_thi_thanh_hoan_thanh(): void
    {
        $b = $this->batch(42, 42);

        ImportOutcome::finalize($b);

        $this->assertSame('completed', $b->fresh()->status);
        $this->assertSame(['ok' => 42, 'total' => 42, 'failed' => 0], $b->fresh()->summary);
    }

    /** Có dòng nhưng KHÔNG dòng nào khớp khung ⇒ thất bại, kèm câu giải thích ngay đầu log. */
    public function test_khong_dong_nao_khop_thi_thanh_that_bai(): void
    {
        $b = $this->batch(0, 30);

        ImportOutcome::finalize($b);

        $b->refresh();
        $this->assertSame('failed', $b->status);
        $this->assertStringContainsString('0/30 dòng khớp khung', $b->error_log[0]);
    }

    /** Xử lý được một phần vẫn là hoàn thành — số dòng hỏng nằm ở summary. */
    public function test_xu_ly_mot_phan_van_la_hoan_thanh(): void
    {
        $b = $this->batch(38, 42);

        ImportOutcome::finalize($b);

        $this->assertSame('completed', $b->fresh()->status);
        $this->assertSame(4, $b->fresh()->summary['failed']);
    }

    public function test_loi_cung_thi_thanh_that_bai_chu_khong_ket_dang_xu_ly(): void
    {
        $b = $this->batch(10, 42);

        ImportOutcome::fail($b, new \RuntimeException('hết bộ nhớ'), 'TestCase');

        $b->refresh();
        $this->assertSame('failed', $b->status);
        $this->assertStringContainsString('hết bộ nhớ', implode(' ', $b->error_log));
    }
}
