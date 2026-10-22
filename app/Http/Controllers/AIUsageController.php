<?php

namespace App\Http\Controllers;

use App\Models\AIUsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AIUsageController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();

        abort_unless(
            $admin && $admin->role === 'admin' && $admin->isSystemAdmin(),
            403,
            'System Administrator access is required.'
        );

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'in:1,7,30,90'],
            'provider' => ['nullable', 'string', 'max:50'],
            'model' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:success,failed'],
            'school_id' => ['nullable', 'integer'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        $base = AIUsageLog::query()
            ->whereBetween('created_at', [$from, $to]);

        foreach (['provider', 'model', 'status', 'school_id'] as $filter) {
            if (isset($validated[$filter]) && $validated[$filter] !== '') {
                $base->where($filter, $validated[$filter]);
            }
        }

        $summaryRow = (clone $base)
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_requests")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_requests")
            ->selectRaw('SUM(CASE WHEN is_fallback = 1 THEN 1 ELSE 0 END) as fallback_requests')
            ->selectRaw('COALESCE(SUM(prompt_tokens), 0) as prompt_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(thinking_tokens), 0) as thinking_tokens')
            ->selectRaw('COALESCE(SUM(cached_tokens), 0) as cached_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('ROUND(AVG(latency_ms)) as avg_latency_ms')
            ->selectRaw('COUNT(DISTINCT user_id) as active_users')
            ->first();

        $requests = (int) ($summaryRow->requests ?? 0);
        $failed = (int) ($summaryRow->failed_requests ?? 0);

        $daily = (clone $base)
            ->selectRaw('DATE(created_at) as usage_date')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failures")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('usage_date')
            ->get();

        $providers = (clone $base)
            ->select('provider')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failures")
            ->selectRaw('ROUND(AVG(latency_ms)) as avg_latency_ms')
            ->groupBy('provider')
            ->orderByDesc('total_tokens')
            ->get();

        $models = (clone $base)
            ->whereNotNull('model')
            ->select('provider', 'model')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failures")
            ->selectRaw('ROUND(AVG(latency_ms)) as avg_latency_ms')
            ->groupBy('provider', 'model')
            ->orderByDesc('total_tokens')
            ->get();

        $features = (clone $base)
            ->selectRaw("COALESCE(feature, 'Unclassified') as feature")
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failures")
            ->groupBy(DB::raw("COALESCE(feature, 'Unclassified')"))
            ->orderByDesc('total_tokens')
            ->limit(12)
            ->get();

        $schools = (clone $base)
            ->leftJoin('schools', 'schools.id', '=', 'ai_usage_logs.school_id')
            ->select('ai_usage_logs.school_id', 'schools.school_name')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.total_tokens), 0) as total_tokens')
            ->selectRaw("SUM(CASE WHEN ai_usage_logs.status = 'failed' THEN 1 ELSE 0 END) as failures")
            ->selectRaw('MAX(ai_usage_logs.created_at) as last_used_at')
            ->groupBy('ai_usage_logs.school_id', 'schools.school_name')
            ->orderByDesc('total_tokens')
            ->limit(20)
            ->get();

        $recent = (clone $base)
            ->with([
                'user:id,name,email',
                'school:id,school_name',
            ])
            ->latest()
            ->limit(50)
            ->get([
                'id', 'user_id', 'school_id', 'provider', 'model', 'operation',
                'feature', 'route', 'status', 'is_fallback', 'http_status',
                'finish_reason', 'prompt_tokens', 'output_tokens', 'thinking_tokens',
                'cached_tokens', 'total_tokens', 'latency_ms', 'error_message', 'created_at',
            ]);

        return response()->json([
            'range' => [
                'days' => $days,
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'summary' => [
                'requests' => $requests,
                'successful_requests' => (int) ($summaryRow->successful_requests ?? 0),
                'failed_requests' => $failed,
                'failure_rate' => $requests > 0 ? round(($failed / $requests) * 100, 1) : 0,
                'fallback_requests' => (int) ($summaryRow->fallback_requests ?? 0),
                'prompt_tokens' => (int) ($summaryRow->prompt_tokens ?? 0),
                'output_tokens' => (int) ($summaryRow->output_tokens ?? 0),
                'thinking_tokens' => (int) ($summaryRow->thinking_tokens ?? 0),
                'cached_tokens' => (int) ($summaryRow->cached_tokens ?? 0),
                'total_tokens' => (int) ($summaryRow->total_tokens ?? 0),
                'avg_latency_ms' => (int) ($summaryRow->avg_latency_ms ?? 0),
                'active_users' => (int) ($summaryRow->active_users ?? 0),
            ],
            'daily' => $daily,
            'providers' => $providers,
            'models' => $models,
            'features' => $features,
            'schools' => $schools,
            'recent' => $recent,
        ]);
    }
}
