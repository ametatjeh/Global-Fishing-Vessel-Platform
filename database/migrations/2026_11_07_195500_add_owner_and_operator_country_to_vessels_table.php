<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Additive and backward-compatible columns for owner and operator country codes.
     */
    public function up(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            if (!Schema::hasColumn('vessels', 'owner_country')) {
                $table->string('owner_country', 3)->nullable()->index()->after('owner');
            }
            if (!Schema::hasColumn('vessels', 'operator_country')) {
                $table->string('operator_country', 3)->nullable()->index()->after('operator');
            }
        });

        // Safe, non-destructive backfill of verified entities already in the database
        $this->backfillKnownCountries();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            if (Schema::hasColumn('vessels', 'owner_country')) {
                $table->dropColumn('owner_country');
            }
            if (Schema::hasColumn('vessels', 'operator_country')) {
                $table->dropColumn('operator_country');
            }
        });
    }

    /**
     * Populate verified owner/operator countries for known historical records.
     */
    protected function backfillKnownCountries(): void
    {
        // 1. Indonesian Maritime Entities
        DB::table('vessels')
            ->where(function ($q) {
                $q->where('owner', 'like', 'PT %')
                  ->orWhere('owner', 'like', 'CV %')
                  ->orWhere('owner', 'like', 'KUD %')
                  ->orWhere('owner', 'like', 'Koperasi %')
                  ->orWhere('owner', 'like', 'BRIN %')
                  ->orWhere('owner', 'like', 'Pengawasan SDKP %')
                  ->orWhere('owner', 'like', 'Satpolairud %')
                  ->orWhere('operator', 'like', 'PT %')
                  ->orWhere('operator', 'like', 'CV %')
                  ->orWhere('operator', 'like', 'KUD %')
                  ->orWhere('operator', 'like', 'Koperasi %')
                  ->orWhere('operator', 'like', 'BRIN %');
            })
            ->update([
                'owner_country' => 'IDN',
                'operator_country' => 'IDN',
            ]);

        // 2. International Verified Fleets
        $knownEntities = [
            'Pacific Ocean Fishery Co.' => 'TWN',
            'Tai Sheng Marine Corp' => 'TWN',
            'Fuzhou Pelagic Fishery Ltd' => 'CHN',
            'Shandong Pelagic Fisheries' => 'CHN',
            'China National Fisheries Corp' => 'CHN',
            'Hai Feng Oceanic Transport' => 'CHN',
            'Zhejiang Ocean Fisheries' => 'CHN',
            'Dongwon Industries Ltd' => 'KOR',
            'Sajo Deep Sea Fisheries' => 'KOR',
            'Nippon Suisan Kaisha' => 'JPN',
            'Maruha Nichiro Corp' => 'JPN',
            'Kyokuyo Marine Corp' => 'JPN',
            'Hokuyo Fishery Association' => 'JPN',
            'Pevcheche Tuna Fleet SA' => 'ESP',
            'Albacora SA' => 'ESP',
            'Inpesca Fisheries Spain' => 'ESP',
            'Compagnie Francaise du Thon Oceanique' => 'FRA',
            'Sapmer Fisheries SA' => 'FRA',
            'American Pelagic Association' => 'USA',
            'Pacific Ocean Harvesting LLC' => 'USA',
            'Port Louis Marine Services' => 'MUS',
            'Indian Ocean Tuna Fleet Ltd' => 'SYC',
            'Victoria Oceanic Harvest' => 'SYC',
            'Panama Marine Reefer Corp' => 'PAN',
            'Trans-Oceanic Carrier Inc' => 'PAN',
            'Monrovia Cold Logistics' => 'LBR',
            'Global Reefer Lines Ltd' => 'LBR',
            'Sealord Group New Zealand' => 'NZL',
            'Sanford Deep Sea Harvesters' => 'NZL',
            'Austral Fisheries Pty Ltd' => 'AUS',
            'Great Barrier Pelagics' => 'AUS',
            'Bergen Pelagic Harvesters' => 'NOR',
            'Tromso Marine Harvesting' => 'NOR',
            'Reykjavik Fisheries HF' => 'ISL',
            'Akureyri Pelagic Systems' => 'ISL',
            'Riga Marine Fleet' => 'LVA',
            'Baltic Harvest Marine' => 'LTU',
            'Gdynia Deep Sea Fisheries' => 'POL',
            'Pomeranian Maritime Corp' => 'POL',
            'Oceana Group South Africa' => 'ZAF',
            'NICKEL CORP CAYMAN' => 'CYM',
        ];

        foreach ($knownEntities as $entityName => $countryIso3) {
            DB::table('vessels')
                ->where('owner', $entityName)
                ->update(['owner_country' => $countryIso3]);

            DB::table('vessels')
                ->where('operator', $entityName)
                ->update(['operator_country' => $countryIso3]);
        }
    }
};

