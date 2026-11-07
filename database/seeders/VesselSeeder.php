<?php

namespace Database\Seeders;

use App\Models\Vessel;
use App\Models\VesselIdentifier;
use App\Models\VesselPosition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class VesselSeeder extends Seeder
{
    public function run(): void
    {
        \Illuminate\Support\Facades\DB::table('vessels')->delete();

        $vesselNames = [
            // Indonesian Fishing Vessels
            ['KM MINA SEJATI 01', 'IDN', 'fishing', 48.5, 380, 'YB8101', '9102341', '525001001', 'verified', 'PT Perikanan Nusantara'],
            ['KM BINTANG SAMUDERA 08', 'IDN', 'fishing', 52.0, 420, 'YB8102', '9102342', '525001002', 'verified', 'PT Samudera Bahari'],
            ['KM LAUTAN BERLIAN 12', 'IDN', 'fishing', 60.2, 510, 'YB8103', '9102343', '525001003', 'verified', 'PT Indo Tuna Raya'],
            ['KM BARUNA JAYA IV', 'IDN', 'research', 60.4, 1200, 'YB8104', '9102344', '525001004', 'verified', 'BRIN Geomaritim'],
            ['KM MUTIARA LAUT 05', 'IDN', 'fishing', 42.1, 290, 'YB8105', '9102345', '525001005', 'verified', 'PT Mutiara Timur'],
            ['KM CAHAYA ABADI 09', 'IDN', 'fishing', 38.6, 250, 'YB8106', '9102346', '525001006', 'provider_reported', 'PT Bahari Berkah'],
            ['KM SURYA KENCANA 22', 'IDN', 'carrier', 75.0, 1450, 'YB8107', '9102347', '525001007', 'verified', 'PT Samudera Logistik'],
            ['KM ANUGERAH BAHARI 03', 'IDN', 'fishing', 45.3, 310, 'YB8108', '9102348', '525001008', 'verified', 'PT Bahari Perdana'],
            ['KM TENGGIRI EMAS 17', 'IDN', 'fishing', 39.0, 220, 'YB8109', '9102349', '525001009', 'unverified', 'Koperasi Nelayan Sejahtera'],
            ['KM RAJAWALI LAUT 88', 'IDN', 'fishing', 55.4, 460, 'YB8110', '9102350', '525001010', 'verified', 'PT Rajawali Fishery'],
            ['KM NUSANTARA JAYA 02', 'IDN', 'carrier', 82.0, 1890, 'YB8111', '9102351', '525001011', 'verified', 'PT Pelayaran Nusantara'],
            ['KM SAMUDERA PACIFIC 07', 'IDN', 'fishing', 58.1, 490, 'YB8112', '9102352', '525001012', 'verified', 'PT Pasifik Tuna Mandiri'],
            ['KM SEGARA INDAH 15', 'IDN', 'fishing', 36.2, 180, 'YB8113', '9102353', '525001013', 'provider_reported', 'CV Segara Mandiri'],
            ['KM BINTANG KEJORA 04', 'IDN', 'fishing', 41.5, 275, 'YB8114', '9102354', '525001014', 'verified', 'PT Bintang Laut Sejati'],
            ['KM SINAR TIMUR 11', 'IDN', 'fishing', 44.0, 305, 'YB8115', '9102355', '525001015', 'verified', 'PT Sinar Jaya Mina'],
            ['KM MAJU BERSAMA 06', 'IDN', 'support', 50.0, 390, 'YB8116', '9102356', '525001016', 'provider_reported', 'PT Maritim Maju'],
            ['KM GELORA SAMUDERA 18', 'IDN', 'fishing', 47.8, 360, 'YB8117', '9102357', '525001017', 'verified', 'PT Gelora Perikanan'],
            ['KM TIRTA SAMUDRA 25', 'IDN', 'tanker', 88.5, 2300, 'YB8118', '9102358', '525001018', 'verified', 'PT Tirta Bunker Maritim'],
            ['KM BERKAH NELAYAN 02', 'IDN', 'fishing', 32.5, 140, 'YB8119', '9102359', '525001019', 'unverified', 'KUD Mina Sejahtera'],
            ['KM CAKRAWALA SAMUDERA', 'IDN', 'fishing', 53.2, 435, 'YB8120', '9102360', '525001020', 'verified', 'PT Cakrawala Fishery'],

            // International Fishing & Reefer Fleet
            ['F/V PACIFIC PIONEER', 'TWN', 'fishing', 68.5, 850, 'BK3001', '9201001', '416001001', 'verified', 'Pacific Ocean Fishery Co.'],
            ['F/V TAI SHENG NO. 11', 'TWN', 'fishing', 72.0, 920, 'BK3002', '9201002', '416001002', 'verified', 'Tai Sheng Marine Corp'],
            ['F/V FU YUAN YU 788', 'CHN', 'fishing', 76.4, 1150, 'BZ4001', '9201003', '412001001', 'provider_reported', 'Fuzhou Pelagic Fishery Ltd'],
            ['F/V LU RONG YUAN YU 588', 'CHN', 'fishing', 81.2, 1380, 'BZ4002', '9201004', '412001002', 'verified', 'Shandong Pelagic Fisheries'],
            ['F/V SHUN XING 8', 'CHN', 'carrier', 98.0, 2800, 'BZ4003', '9201005', '412001003', 'verified', 'China National Fisheries Corp'],
            ['F/V DONG WON 707', 'KOR', 'fishing', 64.0, 780, 'DS5001', '9201006', '440001001', 'verified', 'Dongwon Industries Ltd'],
            ['F/V SAJO COLUMBIA', 'KOR', 'fishing', 70.5, 890, 'DS5002', '9201007', '440001002', 'verified', 'Sajo Deep Sea Fisheries'],
            ['F/V SHIN SHIN MARU NO. 8', 'JPN', 'fishing', 59.8, 620, 'JA6001', '9201008', '431001001', 'verified', 'Nippon Suisan Kaisha'],
            ['F/V TAIYO MARU NO. 55', 'JPN', 'fishing', 65.2, 740, 'JA6002', '9201009', '431001002', 'verified', 'Maruha Nichiro Corp'],
            ['F/V PLAYA DE BAKIO', 'ESP', 'fishing', 85.0, 1950, 'EB7001', '9201010', '224001001', 'verified', 'Pevcheche Tuna Fleet SA'],
            ['F/V ALBACORA CUATRO', 'ESP', 'fishing', 88.0, 2100, 'EB7002', '9201011', '224001002', 'verified', 'Albacora SA'],
            ['F/V TXORI ARGI', 'ESP', 'fishing', 105.0, 3100, 'EB7003', '9201012', '224001003', 'verified', 'Inpesca Fisheries Spain'],
            ['F/V CAP SAINT PIERRE', 'FRA', 'fishing', 78.4, 1420, 'FN8001', '9201013', '227001001', 'verified', 'Compagnie Francaise du Thon Oceanique'],
            ['F/V VIA AVENIR', 'FRA', 'fishing', 68.0, 980, 'FN8002', '9201014', '227001002', 'verified', 'Sapmer Fisheries SA'],
            ['F/V CAPE BLANCO', 'USA', 'fishing', 58.0, 710, 'WD9001', '9201015', '366001001', 'verified', 'American Pelagic Association'],
            ['F/V OCEAN MONARCH', 'USA', 'fishing', 62.5, 830, 'WD9002', '9201016', '366001002', 'verified', 'Pacific Ocean Harvesting LLC'],
            ['R/V MAURITIUS PRIDE', 'MUS', 'support', 71.0, 1100, '3B1001', '9201017', '645001001', 'verified', 'Port Louis Marine Services'],
            ['F/V SEYCHELLES PREMIER', 'SYC', 'fishing', 74.0, 1250, 'S72001', '9201018', '664001001', 'verified', 'Indian Ocean Tuna Fleet Ltd'],
            ['F/V ISLE DE CERF', 'SYC', 'fishing', 66.8, 910, 'S72002', '9201019', '664001002', 'verified', 'Victoria Oceanic Harvest'],
            ['F/V ATLANTIC SPIRIT', 'PAN', 'carrier', 112.0, 4200, 'HO3001', '9201020', '351001001', 'provider_reported', 'Panama Marine Reefer Corp'],

            // Additional Global Fleet
            ['F/V CORONA BOREALIS', 'PAN', 'carrier', 115.0, 4600, 'HO3002', '9301001', '351001002', 'verified', 'Trans-Oceanic Carrier Inc'],
            ['F/V SILVER FJORD', 'LBR', 'carrier', 128.0, 5200, 'A84001', '9301002', '636001001', 'verified', 'Monrovia Cold Logistics'],
            ['F/V OCEANIC FORTUNE', 'LBR', 'carrier', 134.0, 5800, 'A84002', '9301003', '636001002', 'verified', 'Global Reefer Lines Ltd'],
            ['F/V HAI FENG 688', 'CHN', 'carrier', 104.0, 3400, 'BZ4004', '9301004', '412001004', 'provider_reported', 'Hai Feng Oceanic Transport'],
            ['F/V JIN HAI 709', 'CHN', 'fishing', 73.0, 1050, 'BZ4005', '9301005', '412001005', 'verified', 'Zhejiang Ocean Fisheries'],
            ['F/V KORYO MARU NO. 1', 'JPN', 'fishing', 61.0, 680, 'JA6003', '9301006', '431001003', 'verified', 'Kyokuyo Marine Corp'],
            ['F/V HOKUYO MARU NO. 18', 'JPN', 'fishing', 64.5, 730, 'JA6004', '9301007', '431001004', 'verified', 'Hokuyo Fishery Association'],
            ['F/V PACIFIC DISCOVERY', 'NZL', 'fishing', 52.0, 480, 'ZM5001', '9301008', '512001001', 'verified', 'Sealord Group New Zealand'],
            ['F/V SOUTHERN OCEAN', 'NZL', 'fishing', 58.5, 590, 'ZM5002', '9301009', '512001002', 'verified', 'Sanford Deep Sea Harvesters'],
            ['F/V AUSTRAL LEADER', 'AUS', 'fishing', 62.0, 710, 'VK6001', '9301010', '503001001', 'verified', 'Austral Fisheries Pty Ltd'],
            ['F/V CORAL SEA EXPLORER', 'AUS', 'fishing', 49.0, 390, 'VK6002', '9301011', '503001002', 'verified', 'Great Barrier Pelagics'],
            ['F/V NORDIC PEARL', 'NOR', 'fishing', 76.0, 1600, 'LA7001', '9301012', '257001001', 'verified', 'Bergen Pelagic Harvesters'],
            ['F/V ARCTIC VIKING', 'NOR', 'fishing', 84.0, 2200, 'LA7002', '9301013', '257001002', 'verified', 'Tromso Marine Harvesting'],
            ['F/V POLAR STAR 09', 'ISL', 'fishing', 72.5, 1450, 'TF8001', '9301014', '251001001', 'verified', 'Reykjavik Fisheries HF'],
            ['F/V HAFORN', 'ISL', 'fishing', 68.0, 1200, 'TF8002', '9301015', '251001002', 'verified', 'Akureyri Pelagic Systems'],
            ['F/V BALTIC TRADER', 'LVA', 'fishing', 54.0, 490, 'YL9001', '9301016', '275001001', 'provider_reported', 'Riga Marine Fleet'],
            ['F/V KLAIPEDA WAVE', 'LTU', 'fishing', 56.5, 520, 'LY1001', '9301017', '277001001', 'provider_reported', 'Baltic Harvest Marine'],
            ['F/V GDYNIA STAR', 'POL', 'fishing', 59.0, 580, 'SN2001', '9301018', '261001001', 'verified', 'Gdynia Deep Sea Fisheries'],
            ['F/V HEL PEARL', 'POL', 'fishing', 51.5, 430, 'SN2002', '9301019', '261001002', 'verified', 'Pomeranian Maritime Corp'],
            ['F/V CAPE TOWN BREEZE', 'ZAF', 'fishing', 46.0, 350, 'ZR3001', '9301020', '601001001', 'verified', 'Oceana Group South Africa'],

            // 48 more vessels to reach exactly 88 total
            ['KM BANDA SEJATI 01', 'IDN', 'fishing', 40.0, 260, 'YB8121', '9401001', '525001021', 'verified', 'PT Maluku Perikanan'],
            ['KM ARU MANDIRI 02', 'IDN', 'fishing', 43.5, 290, 'YB8122', '9401002', '525001022', 'verified', 'PT Aru Mina Bahari'],
            ['KM NATUNA INDAH 03', 'IDN', 'patrol', 48.0, 340, 'YB8123', '9401003', '525001023', 'verified', 'Pengawasan SDKP Natuna'],
            ['KM BIAK JAYA 04', 'IDN', 'fishing', 39.0, 240, 'YB8124', '9401004', '525001024', 'verified', 'PT Papua Mina Makmur'],
            ['KM SORONG MAKMUR 05', 'IDN', 'carrier', 72.0, 1300, 'YB8125', '9401005', '525001025', 'verified', 'PT Lautan Papua Logistik'],
            ['KM MAKASSAR RAYA 06', 'IDN', 'fishing', 46.0, 330, 'YB8126', '9401006', '525001026', 'verified', 'PT Celebes Pelagic'],
            ['KM KENDARI TUNA 07', 'IDN', 'fishing', 42.0, 280, 'YB8127', '9401007', '525001027', 'verified', 'PT Sultra Mina Sejahtera'],
            ['KM BITUNG SAMUDRA 08', 'IDN', 'fishing', 54.0, 440, 'YB8128', '9401008', '525001028', 'verified', 'PT Samudera Bitung Mandiri'],
            ['KM BALI ANUGERAH 09', 'IDN', 'fishing', 37.0, 210, 'YB8129', '9401009', '525001029', 'verified', 'PT Benoa Tuna Fisheries'],
            ['KM CILACAP RAYA 10', 'IDN', 'fishing', 35.0, 190, 'YB8130', '9401010', '525001030', 'unverified', 'KUD Mina Raharja Cilacap'],
            ['KM BANYUWANGI BERKAH', 'IDN', 'fishing', 38.0, 230, 'YB8131', '9401011', '525001031', 'provider_reported', 'PT Blambangan Fishery'],
            ['KM ACEH BAHARI 12', 'IDN', 'fishing', 44.5, 310, 'YB8132', '9401012', '525001032', 'verified', 'PT Serambi Mina Samudera'],
            ['KM SABANG JAYA 13', 'IDN', 'fishing', 39.5, 250, 'YB8133', '9401013', '525001033', 'verified', 'CV Sabang Maritim Mandiri'],
            ['KM SIBOLGA EMAS 14', 'IDN', 'fishing', 41.0, 270, 'YB8134', '9401014', '525001034', 'verified', 'PT Sibolga Perikanan'],
            ['KM PADANG RAYA 15', 'IDN', 'fishing', 43.0, 290, 'YB8135', '9401015', '525001035', 'provider_reported', 'PT Ranah Minang Bahari'],
            ['KM BENGKULU MARITIM', 'IDN', 'fishing', 36.5, 200, 'YB8136', '9401016', '525001036', 'unverified', 'Koperasi Samudera Bengkulu'],
            ['KM LAMPUNG SEJAHTERA', 'IDN', 'fishing', 40.5, 265, 'YB8137', '9401017', '525001037', 'verified', 'PT Krakatau Mina Sejati'],
            ['KM SERANG BAHARI 18', 'IDN', 'fishing', 34.0, 175, 'YB8138', '9401018', '525001038', 'unverified', 'KUD Samudera Banten'],
            ['KM MUARA BARU 19', 'IDN', 'carrier', 65.0, 950, 'YB8139', '9401019', '525001039', 'verified', 'PT Muara Baru Cold Storage'],
            ['KM PEKALONGAN MINA', 'IDN', 'fishing', 42.0, 280, 'YB8140', '9401020', '525001040', 'verified', 'PT Mina Baruna Pekalongan'],
            ['KM JUWANA MANDIRI 21', 'IDN', 'fishing', 45.0, 315, 'YB8141', '9401021', '525001041', 'verified', 'PT Pati Mina Bersaudara'],
            ['KM REMBANG JAYA 22', 'IDN', 'fishing', 38.5, 235, 'YB8142', '9401022', '525001042', 'provider_reported', 'CV Rembang Fishery'],
            ['KM TUBAN LAUTAN 23', 'IDN', 'fishing', 37.0, 215, 'YB8143', '9401023', '525001043', 'unverified', 'Koperasi Nelayan Ronggolawe'],
            ['KM BRONDONG RAYA 24', 'IDN', 'fishing', 41.5, 275, 'YB8144', '9401024', '525001044', 'verified', 'PT Brondong Lamongan Marine'],
            ['KM PROBOLINGGO SAMUDRA', 'IDN', 'fishing', 36.0, 195, 'YB8145', '9401025', '525001045', 'unverified', 'KUD Bahari Jaya'],
            ['KM AMBON MANISE 26', 'IDN', 'fishing', 47.0, 350, 'YB8146', '9401026', '525001046', 'verified', 'PT Maluku Tuna Harvest'],
            ['KM TERNATE PERDANA 27', 'IDN', 'fishing', 44.0, 310, 'YB8147', '9401027', '525001047', 'verified', 'PT Halmahera Mina'],
            ['KM BACAN BERKAH 28', 'IDN', 'fishing', 39.0, 245, 'YB8148', '9401028', '525001048', 'provider_reported', 'CV Bacan Marine Resource'],
            ['KM TUAL SEJAHTERA 29', 'IDN', 'fishing', 48.0, 370, 'YB8149', '9401029', '525001049', 'verified', 'PT Kei Mina Raya'],
            ['KM SAUMLAKI MINA 30', 'IDN', 'fishing', 43.0, 300, 'YB8150', '9401030', '525001050', 'verified', 'PT Tanimbar Perikanan Mandiri'],
            ['KM MERAUKE JAYA 31', 'IDN', 'fishing', 50.0, 410, 'YB8151', '9401031', '525001051', 'verified', 'PT Animha Mina Perdana'],
            ['KM AGATS BAHARI 32', 'IDN', 'fishing', 35.0, 185, 'YB8152', '9401032', '525001052', 'unverified', 'KUD Asmat Sejahtera'],
            ['KM TIMIKA MARITIM 33', 'IDN', 'support', 56.0, 520, 'YB8153', '9401033', '525001053', 'verified', 'PT Mimika Marine Supply'],
            ['KM FAKFAK RAYA 34', 'IDN', 'fishing', 42.5, 290, 'YB8154', '9401034', '525001054', 'provider_reported', 'PT Onin Mina Mandiri'],
            ['KM KAIMANA TUNA 35', 'IDN', 'fishing', 45.0, 320, 'YB8155', '9401035', '525001055', 'verified', 'PT Teluk Triton Pelagic'],
            ['KM MANOKWARI INDAH 36', 'IDN', 'fishing', 46.5, 335, 'YB8156', '9401036', '525001056', 'verified', 'PT Mansinam Fishery'],
            ['KM NABIRE BAHARI 37', 'IDN', 'fishing', 38.0, 230, 'YB8157', '9401037', '525001057', 'unverified', 'KUD Cenderawasih Laut'],
            ['KM SERUI SAMUDRA 38', 'IDN', 'fishing', 40.0, 260, 'YB8158', '9401038', '525001058', 'provider_reported', 'CV Yapen Marine Harvesters'],
            ['KM JAYAPURA PERDANA 39', 'IDN', 'carrier', 68.0, 1100, 'YB8159', '9401039', '525001059', 'verified', 'PT Pasifik Papua Logistik'],
            ['KM TARAKAN MINA 40', 'IDN', 'fishing', 44.0, 310, 'YB8160', '9401040', '525001060', 'verified', 'PT Kaltara Mina Sejati'],
            ['KM NUNUKAN BAHARI 41', 'IDN', 'patrol', 42.0, 270, 'YB8161', '9401041', '525001061', 'verified', 'Satpolairud Nunukan'],
            ['KM BALIKPAPAN RAYA 42', 'IDN', 'tanker', 92.0, 2600, 'YB8162', '9401042', '525001062', 'verified', 'PT Mahakam Bunker Samudera'],
            ['KM SAMARINDA JAYA 43', 'IDN', 'carrier', 74.0, 1400, 'YB8163', '9401043', '525001063', 'verified', 'PT Borneo Cold Logistics'],
            ['KM BANJARMASIN INDAH 44', 'IDN', 'fishing', 43.5, 295, 'YB8164', '9401044', '525001064', 'provider_reported', 'PT Barito Mina Mandiri'],
            ['KM KOTABARU SAMUDRA 45', 'IDN', 'fishing', 47.0, 345, 'YB8165', '9401045', '525001065', 'verified', 'PT Laut Sebuku Fishery'],
            ['KM PONTIANAK SEJATI 46', 'IDN', 'fishing', 45.0, 320, 'YB8166', '9401046', '525001066', 'verified', 'PT Kapuas Mina Bahari'],
            ['KM KETAPANG RAYA 47', 'IDN', 'fishing', 39.0, 245, 'YB8167', '9401047', '525001067', 'unverified', 'KUD Mina Kalbar Mandiri'],
            ['KM SINGKAWANG BAHARI 48', 'IDN', 'fishing', 41.0, 270, 'YB8168', '9401048', '525001068', 'verified', 'PT Singkawang Pelagic'],
        ];

        $vesselNames = array_slice($vesselNames, 0, 88);
        $providers = \App\Models\DataProvider::pluck('id')->all();
        $fallbackProviderId = !empty($providers) ? $providers[0] : null;

        foreach ($vesselNames as $index => $v) {
            $vessel = Vessel::create([
                'name' => $v[0],
                'flag_state' => $v[1],
                'vessel_type' => $v[2],
                'length_meters' => $v[3],
                'tonnage' => $v[4],
                'callsign' => $v[5],
                'imo' => $v[6],
                'mmsi' => $v[7],
                'verification_status' => $v[8],
                'operator' => $v[9],
                'owner' => $v[9],
                'owner_country' => $v[1],
                'operator_country' => $v[1],
                'source_provider_id' => $fallbackProviderId,
                'first_seen_at' => now()->subDays(rand(30, 360)),
                'last_seen_at' => now()->subHours(rand(1, 48)),
                'metadata' => [
                    'hull_material' => 'Steel',
                    'gross_tonnage' => $v[4],
                    'engine_power_kw' => rand(300, 2500),
                ],
            ]);

            VesselIdentifier::create([
                'vessel_id' => $vessel->id,
                'identifier_type' => 'mmsi',
                'identifier_value' => $v[7],
                'source_provider_id' => $fallbackProviderId,
                'valid_from' => now()->subYears(3),
                'is_current' => true,
            ]);

            // Add position in Indonesian/Regional waters
            // Lat: -10 to +6, Lon: 95 to 141
            $lat = rand(-1000, 600) / 100.0;
            $lon = rand(9500, 14100) / 100.0;
            $speed = rand(20, 130) / 10.0;
            $course = rand(0, 359);

            VesselPosition::create([
                'vessel_id' => $vessel->id,
                'source_provider_id' => $fallbackProviderId,
                'latitude' => $lat,
                'longitude' => $lon,
                'speed_knots' => $speed,
                'course_degrees' => $course,
                'observed_at' => $vessel->last_seen_at,
                'received_at' => now(),
                'geom' => \Illuminate\Support\Facades\DB::raw("ST_SetSRID(ST_MakePoint({$lon}, {$lat}), 4326)"),
            ]);
        }
    }
}
