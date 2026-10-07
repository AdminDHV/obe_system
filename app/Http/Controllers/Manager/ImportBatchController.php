<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\FileBatch;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trang LỊCH SỬ IMPORT đầy đủ (phân trang + lọc) — thay cho chỗ chỉ hiện 5 dòng/loại
 * trên hub Báo cáo. Cho phép audit mọi lần nhập file (khung/điểm/SV/khảo sát).
 */
class ImportBatchController extends Controller
{
    public function index(Request $request): Response
    {
        $batches = FileBatch::with('user:id,full_name,email')
            ->when($request->get('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->get('school_id'), fn ($q, $s) => $q->where('school_id', $s))
            ->when($request->get('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->get('date_from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->get('date_to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (FileBatch $b) => [
                'uuid' => $b->uuid,
                'type' => $b->type,
                'school_id' => $b->school_id,
                'file_name' => $b->file_name,
                'status' => $b->status,
                'processed_rows' => $b->processed_rows,
                'total_rows' => $b->total_rows,
                'summary' => $b->summary,
                'error_log' => $b->error_log ?? [],
                'downloadable' => !empty($b->file_path),
                'created_at' => $b->created_at?->format('d/m/Y H:i:s'),
                'user' => $b->user ? ['full_name' => $b->user->full_name, 'email' => $b->user->email] : null,
            ]);

        return Inertia::render('Manager/ImportBatches/Index', [
            'batches' => $batches,
            'types' => FileBatch::select('type')->distinct()->orderBy('type')->pluck('type'),
            'schools' => School::select('id', 'code', 'name')->orderBy('name')->get(),
            'actors' => User::whereIn('id', FileBatch::select('user_id')->distinct()->pluck('user_id'))
                ->select('id', 'full_name')->orderBy('full_name')->get(),
            'currentFilters' => $request->only(['type', 'status', 'school_id', 'user_id', 'date_from', 'date_to', 'page']),
        ]);
    }
}
