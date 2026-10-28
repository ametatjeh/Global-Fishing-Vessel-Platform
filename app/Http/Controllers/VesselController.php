<?php

namespace App\Http\Controllers;

use App\Models\Vessel;
use Illuminate\Http\Request;

class VesselController extends Controller
{
    public function index(Request $request)
    {
        $query = Vessel::with(['latestPosition', 'sourceProvider'])
            ->withCount('identifiers');

        $search = $request->input('search') ?? $request->input('q');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('mmsi', 'like', "%{$search}%")
                  ->orWhere('imo', 'like', "%{$search}%")
                  ->orWhere('callsign', 'like', "%{$search}%")
                  ->orWhere('hull_code', 'like', "%{$search}%");
            });
        }

        if ($type = $request->input('vessel_type')) {
            $query->where('vessel_type', $type);
        }

        if ($flag = $request->input('flag_state')) {
            $query->where('flag_state', $flag);
        }

        $vessels = $query->orderBy('name')->paginate(20)->withQueryString();

        return view('vessels.index', compact('vessels'));
    }

    public function show(string $id)
    {
        $vessel = Vessel::with([
            'identifiers',
            'latestPosition',
            'positions' => fn ($q) => $q->orderBy('observed_at', 'desc')->limit(50),
            'tracks',
            'authorizations.maritimeZone',
            'complianceEvents.rule',
            'encountersAsVessel1.vessel2',
            'encountersAsVessel2.vessel1',
        ])->findOrFail($id);

        return view('vessels.show', compact('vessel'));
    }
}
