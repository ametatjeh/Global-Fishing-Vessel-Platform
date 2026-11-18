<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Global Maritime Intelligence Platform Settings
    |--------------------------------------------------------------------------
    */
    'platform_name' => 'Global Fishing Vessel Tracking',
    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Native Encounter Detection Defaults
    |--------------------------------------------------------------------------
    */
    'encounters' => [
        'algorithm_version' => 'v2.0-open-sea-spatial-temporal',
        'max_distance_meters' => (float) env('ENCOUNTER_MAX_DISTANCE_METERS', 500.0),
        'min_duration_minutes' => (int) env('ENCOUNTER_MIN_DURATION_MINUTES', 30),
        'timestamp_tolerance_minutes' => (int) env('ENCOUNTER_TIMESTAMP_TOLERANCE_MINUTES', 10),
        'port_exclusion_enabled' => (bool) env('ENCOUNTER_PORT_EXCLUSION_ENABLED', true),
        'default_port_buffer_meters' => (int) env('ENCOUNTER_PORT_BUFFER_METERS', 5000),
        'categories' => [
            'fishing_carrier' => 'Fishing – Carrier',
            'fishing_support' => 'Fishing – Support',
            'fishing_bunker' => 'Fishing – Bunker',
            'fishing_fishing' => 'Fishing – Fishing',
            'other' => 'Other Encounter',
        ],
        'statuses' => [
            'candidate' => 'Candidate',
            'needs_review' => 'Needs Review',
            'verified' => 'Verified',
            'rejected' => 'Rejected',
        ],
        'disclaimer' => 'Observational proximity detection in open sea waters. Does not automatically establish proof of transshipment, maritime boundary violation, or illegal activity.',
    ],

    /*
    |--------------------------------------------------------------------------
    | FAO Major Fishing Areas Metadata & Sources
    |--------------------------------------------------------------------------
    */
    'fao' => [
        'name' => 'FAO Major Fishing Areas for Statistical Purposes',
        'version' => '2026.1-CWP',
        'source_url' => 'https://www.fao.org/fishery/geoserver/wfs',
        'license' => 'FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO',
        'disclaimer' => 'FAO statistical fishing areas are designated for reporting, assessment, and statistical aggregation. They do not delineate actual, exclusive, or real-time fishing locations.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Marine Regions EEZ Metadata & Sources
    |--------------------------------------------------------------------------
    */
    'eez' => [
        'name' => 'Maritime Boundaries and Exclusive Economic Zones (200NM)',
        'version' => 'v12',
        'source_url' => 'https://www.marineregions.org/downloads.php',
        'license' => 'Creative Commons Attribution 4.0 International (CC BY 4.0)',
        'attribution' => 'Flanders Marine Institute (VLIZ) Maritime Boundaries Geodatabase (version 12). Available online at https://www.marineregions.org/.',
        'disclaimer' => 'EEZ boundaries delineate sovereign maritime economic rights under UNCLOS. Presence inside an EEZ does not automatically infer illegal fishing or unauthorized operation without license cross-matching.',
    ],

    /*
    |--------------------------------------------------------------------------
    | External Data Providers
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'gfw' => [
            'name' => 'Global Fishing Watch (Events API v3)',
            'base_url' => env('GFW_API_BASE_URL', 'https://gateway.api.globalfishingwatch.org/v3'),
            'token' => env('GFW_API_ACCESS_TOKEN', env('GFW_API_TOKEN', null)),
            'dataset' => env('GFW_VESSELS_DATASET', 'public-global-vessel-identity:latest'),
            'rate_limit_per_minute' => (int) env('GFW_RATE_LIMIT_PER_MINUTE', 60),
            'timeout' => (int) env('GFW_API_TIMEOUT', 30),
            'cache_ttl' => (int) env('GFW_API_CACHE_TTL', 3600),
            'search_cache_ttl' => (int) env('GFW_SEARCH_CACHE_TTL', 604800), // 7 days cache for stable identifiers
            'license_type' => 'GFW Research & Non-Commercial Terms',
            'commercial_use_allowed' => false,
            'monetization_allowed' => false,
            'free_access_guaranteed' => true,
            'redistribution_allowed' => false,
            'source_url' => 'https://globalfishingwatch.org',
            'research_access_url' => 'https://globalfishingwatch.org/our-apis/tokens',
            'badge' => 'NON-COMMERCIAL RESEARCH',
            'attribution' => 'This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes. GFW data are subject to applicable licensing, attribution, and usage restrictions.',
            'disclaimer' => 'Notice: Displayed vessel activity represents estimates or analytical findings based on available data sources, not conclusive proof of infractions.',
            'unknown_policy' => [
                'allow_limited_monitoring' => true,
                'exempt_from_fishing_compliance' => true,
                'async_enrichment_enabled' => (bool) env('GFW_ASYNC_ENRICHMENT_ENABLED', true),
            ],
        ],
        'copernicus_sar' => [
            'name' => 'Copernicus Data Space Ecosystem (Sentinel-1 SAR)',
            'base_url' => env('COPERNICUS_API_URL', 'https://dataspace.copernicus.eu/analyse/apis'),
            'auth_mode' => env('COPERNICUS_AUTH_MODE', 'password'), // 'password' (default for CDSE OData download) or 'client_credentials'
            'username' => env('CDSE_USERNAME', env('COPERNICUS_USERNAME', null)),
            'password' => env('CDSE_PASSWORD', env('COPERNICUS_PASSWORD', null)),
            'totp' => env('CDSE_TOTP', null),
            'client_id' => env('COPERNICUS_CLIENT_ID', null),
            'client_secret' => env('COPERNICUS_CLIENT_SECRET', null),
            'auth_url' => env('CDSE_AUTH_URL', env('COPERNICUS_AUTH_URL', 'https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token')),
            'odata_url' => env('COPERNICUS_ODATA_URL', 'https://catalogue.dataspace.copernicus.eu/odata/v1'),
            'download_url' => env('COPERNICUS_DOWNLOAD_URL', 'https://download.dataspace.copernicus.eu/odata/v1'),
            'timeout' => (int) env('COPERNICUS_TIMEOUT', 30),
            'license_type' => 'Copernicus Open Access / Full Free & Open',
            'commercial_use_allowed' => true,
            'redistribution_allowed' => true,
            'disclaimer' => 'SAR detection provides synthetic aperture radar backscatter analysis. Dark vessel candidate status requires correlation with AIS and persistence validation.',
            'pipeline' => [
                'storage_dir' => env('SAR_STORAGE_DIR', storage_path('app/sar_products')),
                'min_free_disk_gb' => (float) env('SAR_MIN_FREE_DISK_GB', 10.0),
                'memory_limit_mb' => (int) env('SAR_MEMORY_LIMIT_MB', 8192),
                'cfar_guard_cells' => 5,
                'cfar_training_cells' => 20,
                'cfar_pfa' => 1e-5,
                'correlation_max_distance_meters' => (float) env('SAR_AIS_CORRELATION_MAX_DISTANCE', 1500.0),
                'correlation_time_tolerance_minutes' => (int) env('SAR_AIS_CORRELATION_TIME_TOLERANCE', 30),
            ],
        ],
        'ais_stream' => [
            'name' => 'AIS Live Telemetry Stream / Provider',
            'provider' => env('AIS_PROVIDER', 'ais-stream'),
            'api_key' => env('AIS_STREAM_API_KEY', env('AIS_API_KEY', null)),
            'ingest_token' => env('AIS_INGEST_TOKEN', env('AIS_STREAM_API_KEY', env('AIS_API_KEY', null))),
            'is_configured' => !empty(env('AIS_STREAM_API_KEY', env('AIS_API_KEY'))),
            'license_type' => 'Provider Proprietary License',
            'bounding_boxes' => env('AIS_BOUNDING_BOXES')
                ? json_decode(env('AIS_BOUNDING_BOXES'), true)
                : [[[-90.0, -180.0], [90.0, 180.0]]],
            'filter_message_types' => env('AIS_FILTER_MESSAGE_TYPES')
                ? explode(',', env('AIS_FILTER_MESSAGE_TYPES'))
                : [
                    'PositionReport',
                    'ShipStaticData',
                    'StandardClassBPositionReport',
                    'ExtendedClassBPositionReport',
                    'StaticDataReport',
                ],
            'filter_mmsi' => env('AIS_FILTER_MMSI')
                ? explode(',', env('AIS_FILTER_MMSI'))
                : null,
            'connect_timeout' => (int) env('AIS_CONNECT_TIMEOUT', 10),
            'socket_timeout' => (int) env('AIS_SOCKET_TIMEOUT', 15),
            'reconnect_delay_seconds' => (int) env('AIS_RECONNECT_DELAY', 5),
            'max_reconnect_attempts' => (int) env('AIS_MAX_RECONNECT_ATTEMPTS', 10),
            'rate_limit_per_minute' => (int) env('AIS_STREAM_RATE_LIMIT_PER_MINUTE', 120),
            'batch_size' => (int) env('AIS_BATCH_SIZE', 50),
        ],
        'fao_global_record' => [
            'name' => 'FAO Global Record of Fishing Vessels',
            'base_url' => env('FAO_GLOBAL_RECORD_URL', 'https://www.fao.org/global-record/api/v1'),
            'api_key' => env('FAO_GLOBAL_RECORD_API_KEY', null),
            'rate_limit_per_minute' => (int) env('FAO_RATE_LIMIT_PER_MINUTE', 30),
            'timeout' => (int) env('FAO_GLOBAL_RECORD_TIMEOUT', 15),
            'cache_ttl' => (int) env('FAO_GLOBAL_RECORD_CACHE_TTL', 604800),
            'license_type' => 'FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO',
        ],
        'flag_registry' => [
            'name' => 'Official Flag State Registry & ITU MID Resolution',
            'rate_limit_per_minute' => (int) env('FLAG_REGISTRY_RATE_LIMIT_PER_MINUTE', 300),
            'license_type' => 'ITU-R Radio Regulations & Sovereign Flag Data',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Infrastructure & Observability Settings
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'disk_monitor_path' => env('DISK_MONITOR_PATH', null),
    ],
    'backup' => [
        'root_dir' => env('BACKUP_ROOT_DIR', null),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
    ],
];
