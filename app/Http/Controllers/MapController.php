<?php

namespace App\Http\Controllers;

use App\Models\DataProvider;
use Illuminate\Http\Request;

class MapController extends Controller
{
    public function index()
    {
        $providers = DataProvider::all()->keyBy('slug');

        return view('map.index', compact('providers'));
    }
}
