<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Trang "Hướng dẫn sử dụng" — nội dung tĩnh ở frontend (resources/js/config/guideContent.js). */
class GuideController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Manager/Guide/Index');
    }
}
