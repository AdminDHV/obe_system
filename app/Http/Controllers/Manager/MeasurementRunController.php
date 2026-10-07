<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\MeasurementRun;
use App\Models\Program;
use App\Models\School;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trang NHẬT KÝ ĐO LƯỜNG đầy đủ (phân trang + lọc) — thay cho chỗ chỉ hiện 10 run gần
 * nhất trên hub. Nay ghi cả run 'failed' (Sprint 41) nên operator thấy được đo lường lỗi.
 */
class MeasurementRunController extends Controller
{
    public function index(Request $request): Response
    {
        $runs = MeasurementRun::with(['program:id,code', 'cohort:id,name', 'user:id,full_name', 'school:id,name'])
            ->when($request->get('school_id'), fn ($q, $s) => $q->where('school_id', $s))
            ->when($request->get('program_id'), fn ($q, $p) => $q->where('program_id', $p))
            ->when($request->get('source'), fn ($q, $s) => $q->where('source', $s))
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->get('date_from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->get('date_to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (MeasurementRun $m) => [
                'id' => $m->id,
                'school_id' => $m->school_id,
                'school' => $m->school?->name,
                'program' => $m->program?->code,
                'cohort' => $m->cohort?->name,
                'source' => $m->source,
                'status' => $m->status,
                'pairs_count' => $m->pairs_count,
                'user' => $m->user?->full_name,
                'created_at' => $m->created_at?->format('d/m/Y H:i:s'),
            ]);

        return Inertia::render('Manager/MeasurementRuns/Index', [
            'runs' => $runs,
            'schools' => School::select('id', 'code', 'name')->orderBy('name')->get(),
            'programs' => Program::select('id', 'code', 'name', 'school_id')->orderBy('name')->get(),
            'currentFilters' => $request->only(['school_id', 'program_id', 'source', 'status', 'date_from', 'date_to', 'page']),
        ]);
    }
}
