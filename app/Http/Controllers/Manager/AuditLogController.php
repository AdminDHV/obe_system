<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = AuditLog::with('user')
            ->when($request->get('action'), fn($q, $a) => $q->where('action', $a))
            ->when($request->get('model_type'), fn($q, $m) => $q->where('model_type', $m))
            ->when($request->get('user_id'), fn($q, $u) => $q->where('user_id', $u))
            ->when($request->get('date_from'), fn($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->get('date_to'), fn($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate(20)
            ->through(fn(AuditLog $log) => [
                'uuid' => $log->uuid,
                'action' => $log->action,
                'model_type' => $log->model_type,
                'model_id' => $log->model_id,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at?->format('d/m/Y H:i:s'),
                'user' => $log->user ? ['full_name' => $log->user->full_name, 'email' => $log->user->email] : null,
            ]);

        $modelTypes = AuditLog::select('model_type')->distinct()->orderBy('model_type')->pluck('model_type');
        $actors = User::whereIn('id', AuditLog::select('user_id')->distinct()->pluck('user_id'))
            ->select('id', 'full_name')
            ->orderBy('full_name')
            ->get();

        return Inertia::render('Manager/AuditLogs/Index', [
            'logs' => $logs,
            'modelTypes' => $modelTypes,
            'actors' => $actors,
            'currentFilters' => $request->only(['action', 'model_type', 'user_id', 'date_from', 'date_to', 'page']),
        ]);
    }
}
