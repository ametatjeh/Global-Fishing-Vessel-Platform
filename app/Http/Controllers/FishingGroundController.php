<?php

namespace App\Http\Controllers;

use App\Models\FaoFishingArea;
use App\Models\EezZone;
use Illuminate\Http\Request;

class FishingGroundController extends Controller
{
    public function index(Request $request)
    {
        $faoAreas = FaoFishingArea::orderBy('area_code')->get();
        $eezZones = EezZone::orderBy('geoname')->paginate(15);

        return view('fishing-grounds.index', compact('faoAreas', 'eezZones'));
    }
}
