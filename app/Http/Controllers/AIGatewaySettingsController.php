<?php

namespace App\Http\Controllers;

use App\Services\AI\AIGatewayRouting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AIGatewaySettingsController extends Controller
{
    private function authorizeSystem(Request $request): void
    {
        abort_unless($request->user()?->isSystemAdmin(), 403, 'System Administrator access is required.');
    }

    public function show(Request $request, AIGatewayRouting $routing): JsonResponse
    {
        $this->authorizeSystem($request);
        $rows = $routing->rows();
        return response()->json([
            'providers' => AIGatewayRouting::PROVIDERS,
            'features' => AIGatewayRouting::FEATURES,
            'configured_default' => $routing->configuredDefault(),
            'global' => $rows['global'] ?? null,
            'overrides' => array_intersect_key($rows, AIGatewayRouting::FEATURES),
            'effective' => collect(AIGatewayRouting::FEATURES)->mapWithKeys(
                fn ($label, $key) => [$key => $routing->order($key === 'other' ? '' : $key)]
            ),
        ]);
    }

    public function save(Request $request, AIGatewayRouting $routing): JsonResponse
    {
        $this->authorizeSystem($request);
        $validFeatures = array_merge(['global'], array_keys(AIGatewayRouting::FEATURES));
        $data = $request->validate([
            'feature' => ['required', 'string', Rule::in($validFeatures)],
            'providers' => ['required', 'array', 'min:1', 'max:3'],
            'providers.*' => ['required', 'string', 'distinct', Rule::in(AIGatewayRouting::PROVIDERS)],
        ]);
        DB::table('ai_gateway_routes')->updateOrInsert(['feature' => $data['feature']], [
            'providers' => json_encode(array_values($data['providers'])),
            'updated_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $routing->resetCache();
        Log::info('AI gateway route updated', ['feature' => $data['feature'], 'providers' => $data['providers'], 'admin_id' => $request->user()->id]);
        return $this->show($request, $routing);
    }

    public function reset(Request $request, AIGatewayRouting $routing): JsonResponse
    {
        $this->authorizeSystem($request);
        $data = $request->validate([
            'feature' => ['required', 'string', Rule::in(array_merge(['global'], array_keys(AIGatewayRouting::FEATURES)))],
        ]);
        DB::table('ai_gateway_routes')->where('feature', $data['feature'])->delete();
        $routing->resetCache();
        Log::info('AI gateway route reset', ['feature' => $data['feature'], 'admin_id' => $request->user()->id]);
        return $this->show($request, $routing);
    }
}
