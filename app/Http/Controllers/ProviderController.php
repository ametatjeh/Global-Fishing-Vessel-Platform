<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DataProvider;
use App\Models\ProviderIngestionRun;
use App\Services\Providers\GlobalFishingWatchAdapter;
use App\Services\Providers\CopernicusSarAdapter;
use App\Services\Providers\AisStreamAdapter;
use App\Services\Providers\FaoFishingAreaAdapter;
use App\Services\Providers\MarineRegionsEezAdapter;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    public function index()
    {
        $providers = DataProvider::with(['ingestionRuns' => fn ($q) => $q->latest()->limit(5)])->get();
        $recentRuns = ProviderIngestionRun::with('dataProvider')->orderBy('created_at', 'desc')->limit(15)->get();

        return view('providers.index', compact('providers', 'recentRuns'));
    }

    public function checkHealth(
        string $slug,
        GlobalFishingWatchAdapter $gfw,
        CopernicusSarAdapter $copernicus,
        AisStreamAdapter $ais,
        FaoFishingAreaAdapter $fao,
        MarineRegionsEezAdapter $eez
    ) {
        $adapter = match ($slug) {
            'global-fishing-watch' => $gfw,
            'copernicus-sentinel-1' => $copernicus,
            'ais-stream' => $ais,
            'fao-fisheries' => $fao,
            'marine-regions-eez' => $eez,
            default => null,
        };

        if (!$adapter) {
            return redirect()->route('providers.index')->with('error', 'Provider tidak dikenali.');
        }

        $result = $adapter->healthCheck();

        AuditLog::log('check_provider_health', 'data_provider', $slug, $result);

        $msgType = in_array($result['status'], ['connected', 'receiving_data', 'streaming', 'authenticated']) ? 'status' : 'warning';
        return redirect()->route('providers.index')
            ->with($msgType, "Health check untuk [{$slug}]: {$result['message']}");
    }
}
