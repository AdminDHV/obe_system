<?php

namespace Tests;

use App\Services\OBE\ObeConfig;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * ObeConfig giữ tham số đo trong biến STATIC, khoá theo "school:<id>". `RefreshDatabase` dựng
     * lại bảng nhưng KHÔNG đụng tới biến static, nên test sau dùng lại hệ số mà test trước đã nạp
     * — hai test cùng tạo trường id 1 là dính nhau ngay.
     *
     * Lỗi này chỉ lộ ra khi test chạy chung một tiến trình và đúng thứ tự, nên chạy lẻ thì xanh mà
     * chạy cả bộ thì đỏ. Dọn ở đây một lần cho mọi test, thay vì bắt từng test tự nhớ.
     */
    protected function setUp(): void
    {
        parent::setUp();

        ObeConfig::flush();
    }
}
