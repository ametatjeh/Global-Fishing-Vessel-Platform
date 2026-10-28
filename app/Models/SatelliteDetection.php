<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SatelliteDetection extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'detected_at' => 'datetime',
        'received_at' => 'datetime',
        'estimated_length_meters' => 'float',
        'estimated_width_meters' => 'float',
        'estimated_heading' => 'float',
        'confidence_score' => 'float',
        'distance_to_nearest_ais_meters' => 'float',
        'ais_gap_duration_minutes' => 'integer',
        'tcr_db' => 'float',
        'reviewed_at' => 'datetime',
        'review_metadata' => 'array',
    ];

    public const REVIEW_PENDING = 'pending_review';
    public const REVIEW_POSSIBLE_VESSEL = 'possible_vessel';
    public const REVIEW_POSSIBLE_ICE_CLUTTER = 'possible_ice_or_clutter';
    public const REVIEW_INCONCLUSIVE = 'inconclusive';
    public const REVIEW_OTHER = 'reviewed_other';

    public static function getReviewStatuses(): array
    {
        return [
            self::REVIEW_PENDING => 'Belum Ditinjau (Pending Review)',
            self::REVIEW_POSSIBLE_VESSEL => 'Pola Konsisten Kapal (Possible Vessel)',
            self::REVIEW_POSSIBLE_ICE_CLUTTER => 'Es Laut / Clutter / Non-Kapal (Possible Ice / Clutter)',
            self::REVIEW_INCONCLUSIVE => 'Bukti Belum Memadai (Inconclusive)',
            self::REVIEW_OTHER => 'Lainnya / Catatan Khusus (Reviewed Other)',
        ];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(SatelliteScene::class, 'satellite_scene_id');
    }

    public function correlatedVessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class, 'correlated_vessel_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function scopeDarkVesselCandidates(Builder $query): Builder
    {
        return $query->where('correlation_status', 'dark_vessel_candidate');
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('review_status', self::REVIEW_PENDING);
    }

    public function scopeReviewed(Builder $query): Builder
    {
        return $query->where('review_status', '!=', self::REVIEW_PENDING);
    }

    public function scopeInBoundingBox(Builder $query, ?float $minLng, ?float $minLat, ?float $maxLng, ?float $maxLat): Builder
    {
        if ($minLng === null || $minLat === null || $maxLng === null || $maxLat === null) {
            return $query;
        }

        $minY = min($minLat, $maxLat);
        $maxY = max($minLat, $maxLat);

        $query->whereBetween('latitude', [$minY, $maxY]);

        if ($minLng > $maxLng) {
            return $query->where(function ($q) use ($minLng, $maxLng) {
                $q->where('longitude', '>=', $minLng)
                  ->orWhere('longitude', '<=', $maxLng);
            });
        }

        return $query->whereBetween('longitude', [$minLng, $maxLng]);
    }

    public function scopeWhereIdentifierOrId(Builder $query, string $identifier): Builder
    {
        if (\Illuminate\Support\Str::isUuid($identifier)) {
            return $query->where(function ($q) use ($identifier) {
                $q->where('id', $identifier)
                  ->orWhere('detection_identifier', $identifier);
            });
        }

        return $query->where('detection_identifier', $identifier);
    }

    public static function findByIdentifierOrId(string $identifier): ?self
    {
        return static::whereIdentifierOrId($identifier)->first();
    }

    public static function findByIdentifierOrIdOrFail(string $identifier): self
    {
        return static::whereIdentifierOrId($identifier)->firstOrFail();
    }
}
