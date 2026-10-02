<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\AssetGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{
    Mosque, MosqueAccessRule, MosqueArea, MosqueAssetAssignment, MosqueDisplaySetting, MosqueEquipment,
    MosqueUtilizationLog
};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Master masjid (§5-§9, §65): masjid, area, aturan akses,
 * perlengkapan, penautan aset, dan pengaturan layar digital.
 *
 * Prinsip: TIDAK ada master aset kedua. Peralatan masjid menaut ke `asset_id`
 * pada Asset Management (§7); modul ini hanya menyimpan penugasan ruang.
 */
class MosqueService
{
    public function __construct(
        protected ReligiousContext $context,
        protected AssetGatewayInterface $assets,
    ) {
    }

    // ── Masjid ───────────────────────────────────────────────────────────────

    public function create(array $payload): Mosque
    {
        $code = $payload['code'] ?? $this->context->nextCode('MOS', 'mosques');

        $mosque = Mosque::query()->create([
            'campus_id' => $payload['campus_id'] ?? null,
            'code' => $code,
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
            'location' => $payload['location'] ?? null,
            'capacity' => $payload['capacity'] ?? null,
            'gender_access' => strtoupper((string) ($payload['gender_access'] ?? 'MIXED')),
            'operational_status' => strtoupper((string) ($payload['operational_status'] ?? 'ACTIVE')),
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'timezone' => $payload['timezone'] ?? config('religious.prayer_time.timezone', 'Asia/Jakarta'),
            'qibla_direction' => $payload['qibla_direction'] ?? null,
            'contact_person' => $payload['contact_person'] ?? null,
            'contact_phone' => $payload['contact_phone'] ?? null,
            'open_24h' => (bool) ($payload['open_24h'] ?? false),
            'notes' => $payload['notes'] ?? null,
            'active' => (bool) ($payload['active'] ?? true),
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.mosque.created', $mosque, [
            'campus_id' => $mosque->campus_id, 'mosque_id' => $mosque->getKey(),
        ]);
        $this->context->publish('MosqueCreated', $mosque, ['code' => $mosque->code]);

        return $mosque;
    }

    public function update(Mosque $mosque, array $payload): Mosque
    {
        $before = $mosque->only(array_keys($payload));
        $mosque->fill($payload)->save();

        $this->context->audit('religious.mosque.updated', $mosque, [
            'before' => $before, 'after' => $mosque->only(array_keys($payload)),
            'campus_id' => $mosque->campus_id, 'mosque_id' => $mosque->getKey(),
        ]);

        return $mosque;
    }

    public function list(array $filters = []): array
    {
        $query = Mosque::query();

        if (! empty($filters['campus_id'])) {
            $query->where('campus_id', (int) $filters['campus_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('operational_status', strtoupper((string) $filters['status']));
        }
        if (! empty($filters['term'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['term'].'%')
                ->orWhere('code', 'like', '%'.$filters['term'].'%'));
        }

        $this->context->applyScope($query, null, 'campus_id', 'id');

        return $query->orderBy('name')->limit((int) ($filters['limit'] ?? 200))->get()
            ->map(fn (Mosque $m): array => $this->summary($m))->all();
    }

    public function summary(Mosque $mosque): array
    {
        return [
            'id' => $mosque->getKey(),
            'code' => $mosque->code,
            'name' => $mosque->name,
            'campus_id' => $mosque->campus_id,
            'capacity' => $mosque->capacity,
            'gender_access' => $mosque->gender_access,
            'operational_status' => $mosque->operational_status,
            'active' => (bool) $mosque->active,
            'area_count' => $mosque->mosqueAreas()->count(),
            'pending_bookings' => $mosque->mosqueBookings()->where('status', 'REQUESTED')->count(),
        ];
    }

    /** Ringkasan masjid untuk dashboard (§71, §72). */
    public function overview(int $mosqueId): array
    {
        $mosque = Mosque::query()->findOrFail($mosqueId);

        return [
            'mosque' => $this->summary($mosque),
            'areas' => $this->areas($mosqueId),
            'equipment_total' => MosqueEquipment::query()->where('mosque_id', $mosqueId)->sum('quantity'),
            'equipment_attention' => MosqueEquipment::query()->where('mosque_id', $mosqueId)
                ->whereIn('condition', ['DAMAGED', 'NEEDS_REPAIR'])->count(),
            'asset_assignments' => MosqueAssetAssignment::query()->where('mosque_id', $mosqueId)
                ->where('status', 'ACTIVE')->count(),
            'open_maintenance' => DB::table('mosque_maintenance_requests')->where('mosque_id', $mosqueId)
                ->whereIn('status', ['REPORTED', 'IN_PROGRESS'])->count(),
            'display' => MosqueDisplaySetting::query()->where('mosque_id', $mosqueId)
                ->where('active', true)->first()?->toArray(),
        ];
    }

    // ── Area (§6) ───────────────────────────────────────────────────────

    public function addArea(Mosque $mosque, array $payload): MosqueArea
    {
        $area = MosqueArea::query()->create([
            'mosque_id' => $mosque->getKey(),
            'code' => $payload['code'] ?? strtoupper(substr((string) ($payload['name'] ?? 'AREA'), 0, 6)).'-'.$mosque->getKey(),
            'name' => $payload['name'],
            'area_type' => strtoupper((string) ($payload['area_type'] ?? 'MAIN_HALL')),
            'capacity' => $payload['capacity'] ?? null,
            'gender' => strtoupper((string) ($payload['gender'] ?? 'MIXED')),
            'floor' => $payload['floor'] ?? null,
            'status' => strtoupper((string) ($payload['status'] ?? 'ACTIVE')),
            'bookable' => (bool) ($payload['bookable'] ?? true),
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.mosque_area.created', $area, [
            'mosque_id' => $mosque->getKey(), 'campus_id' => $mosque->campus_id,
        ]);

        return $area;
    }

    public function areas(int $mosqueId, array $filters = []): array
    {
        $query = MosqueArea::query()->where('mosque_id', $mosqueId);

        if (! empty($filters['bookable'])) {
            $query->where('bookable', true);
        }
        if (! empty($filters['area_type'])) {
            $query->where('area_type', strtoupper((string) $filters['area_type']));
        }

        return $query->orderBy('name')->get()->map(fn (MosqueArea $a): array => [
            'id' => $a->getKey(),
            'code' => $a->code,
            'name' => $a->name,
            'area_type' => $a->area_type,
            'capacity' => $a->capacity,
            'gender' => $a->gender,
            'floor' => $a->floor,
            'status' => $a->status,
            'bookable' => (bool) $a->bookable,
        ])->all();
    }

    // ── Aturan akses (§9) ───────────────────────────────────────────────

    public function addAccessRule(Mosque $mosque, array $payload): MosqueAccessRule
    {
        $policy = strtoupper((string) ($payload['access_policy'] ?? 'INSTITUTION_ONLY'));
        if (! array_key_exists($policy, (array) config('religious.mosque.access_policies', []))) {
            throw ValidationException::withMessages([
                'access_policy' => 'Kebijakan akses tidak dikenal: '.$policy,
            ]);
        }

        $rule = MosqueAccessRule::query()->create([
            'mosque_id' => $mosque->getKey(),
            'area_id' => $payload['area_id'] ?? null,
            'access_policy' => $policy,
            'audience' => $payload['audience'] ?? null,
            'gender' => strtoupper((string) ($payload['gender'] ?? 'MIXED')),
            'day_of_week' => $payload['day_of_week'] ?? null,
            'time_from' => $payload['time_from'] ?? null,
            'time_to' => $payload['time_to'] ?? null,
            'valid_from' => $payload['valid_from'] ?? null,
            'valid_until' => $payload['valid_until'] ?? null,
            'requires_escort' => (bool) ($payload['requires_escort'] ?? false),
            'status' => 'ACTIVE',
            'notes' => $payload['notes'] ?? null,
            'active' => true,
        ]);

        $this->context->audit('religious.mosque_access_rule.created', $rule, [
            'mosque_id' => $mosque->getKey(), 'campus_id' => $mosque->campus_id,
        ]);

        return $rule;
    }

    /** Evaluasi apakah audiens tertentu boleh mengakses masjid/area. */
    public function accessAllowed(int $mosqueId, ?int $areaId, string $audience, ?string $at = null): array
    {
        $rules = MosqueAccessRule::query()
            ->where('mosque_id', $mosqueId)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('area_id')->orWhere('area_id', $areaId))
            ->get();

        if ($rules->isEmpty()) {
            return ['allowed' => true, 'policy' => 'DEFAULT', 'reason' => 'Tidak ada aturan khusus.'];
        }

        $audience = strtoupper($audience);
        $carbon = $at ? \Carbon\Carbon::parse($at) : now();
        $time = $carbon->format('H:i:s');

        foreach ($rules as $rule) {
            $ruleAudience = strtoupper((string) ($rule->audience ?? ''));

            if ($rule->day_of_week && strtoupper((string) $rule->day_of_week) !== strtoupper($carbon->format('l'))) {
                continue;
            }
            if ($rule->time_from && $rule->time_to && ($time < $rule->time_from || $time > $rule->time_to)) {
                continue;
            }

            $policy = strtoupper((string) $rule->access_policy);

            if ($policy === 'PUBLIC') {
                return ['allowed' => true, 'policy' => $policy, 'rule_id' => $rule->getKey()];
            }

            if ($ruleAudience === '' || $ruleAudience === $audience) {
                $allowed = in_array($policy, ['INSTITUTION_ONLY', 'STUDENT_ONLY', 'STAFF_ONLY', 'RESTRICTED'], true);

                return [
                    'allowed' => $allowed,
                    'policy' => $policy,
                    'rule_id' => $rule->getKey(),
                    'requires_escort' => (bool) $rule->requires_escort,
                ];
            }
        }

        return ['allowed' => false, 'policy' => 'RESTRICTED', 'reason' => 'Audiens tidak tercakup aturan aktif.'];
    }

    // ── Perlengkapan & aset (§7, §8) ────────────────────────────────

    public function addEquipment(Mosque $mosque, array $payload): MosqueEquipment
    {
        if (! empty($payload['asset_id']) && $this->assets->available() && $this->assets->find((int) $payload['asset_id']) === null) {
            throw ValidationException::withMessages([
                'asset_id' => 'Aset tidak ditemukan pada Asset Management.',
            ]);
        }

        $equipment = MosqueEquipment::query()->create([
            'mosque_id' => $mosque->getKey(),
            'area_id' => $payload['area_id'] ?? null,
            'asset_id' => $payload['asset_id'] ?? null,
            'code' => $payload['code'] ?? 'EQ-'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'name' => $payload['name'],
            'equipment_type' => strtoupper((string) ($payload['equipment_type'] ?? 'OTHER')),
            'quantity' => (int) ($payload['quantity'] ?? 1),
            'condition' => strtoupper((string) ($payload['condition'] ?? 'GOOD')),
            'status' => 'ACTIVE',
            'last_checked_at' => $payload['last_checked_at'] ?? now(),
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.mosque_equipment.created', $equipment, [
            'mosque_id' => $mosque->getKey(), 'campus_id' => $mosque->campus_id,
        ]);

        return $equipment;
    }

    /** Tautkan aset institusi ke masjid/area tertentu (§7). */
    public function assignAsset(Mosque $mosque, int $assetId, array $payload = []): MosqueAssetAssignment
    {
        $assignment = MosqueAssetAssignment::query()->create([
            'mosque_id' => $mosque->getKey(),
            'area_id' => $payload['area_id'] ?? null,
            'asset_id' => $assetId,
            'assigned_at' => $payload['assigned_at'] ?? now()->toDateString(),
            'assigned_by' => $this->context->actorId(),
            'quantity' => (int) ($payload['quantity'] ?? 1),
            'condition' => strtoupper((string) ($payload['condition'] ?? 'GOOD')),
            'status' => 'ACTIVE',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.mosque_asset.assigned', $assignment, [
            'mosque_id' => $mosque->getKey(), 'campus_id' => $mosque->campus_id,
        ]);
        $this->context->publish('MosqueAssetAssigned', $assignment, ['asset_id' => $assetId]);

        return $assignment;
    }

    /** Lepas penautan aset (mis. dipindahkan ke unit lain). */
    public function releaseAsset(MosqueAssetAssignment $assignment, ?string $reason = null): MosqueAssetAssignment
    {
        $assignment->forceFill([
            'status' => 'RELEASED',
            'released_at' => now()->toDateString(),
            'notes' => $reason ?? $assignment->notes,
        ])->save();

        $this->context->audit('religious.mosque_asset.released', $assignment, [], $reason);

        return $assignment;
    }

    // ── Pengaturan layar digital (§65, §89) ─────────────────────────

    public function saveDisplaySetting(Mosque $mosque, array $payload): MosqueDisplaySetting
    {
        $setting = MosqueDisplaySetting::query()->updateOrCreate(
            ['mosque_id' => $mosque->getKey(), 'code' => $payload['code'] ?? 'DEFAULT'],
            [
                'name' => $payload['name'] ?? ($mosque->name.' Display'),
                'display_mode' => strtoupper((string) ($payload['display_mode'] ?? 'FULLSCREEN')),
                'theme' => $payload['theme'] ?? null,
                'show_current_prayer' => (bool) ($payload['show_current_prayer'] ?? true),
                'show_next_prayer' => (bool) ($payload['show_next_prayer'] ?? true),
                'show_iqamah_countdown' => (bool) ($payload['show_iqamah_countdown'] ?? true),
                'show_imam' => (bool) ($payload['show_imam'] ?? true),
                'show_muadzin' => (bool) ($payload['show_muadzin'] ?? true),
                'show_activities' => (bool) ($payload['show_activities'] ?? true),
                'show_announcements' => (bool) ($payload['show_announcements'] ?? true),
                'announcement_ids' => $payload['announcement_ids'] ?? null,
                'refresh_seconds' => (int) ($payload['refresh_seconds'] ?? 30),
                'status' => 'ACTIVE',
                'active' => true,
                'meta' => $payload['meta'] ?? null,
            ]
        );

        $this->context->audit('religious.mosque_display.saved', $setting, [
            'mosque_id' => $mosque->getKey(), 'campus_id' => $mosque->campus_id,
        ]);

        return $setting;
    }

    // ── Utilisasi (§72) ─────────────────────────────────────────────────

    /** Catat pemakaian masjid/area (dipanggil presensi & kegiatan). */
    public function logUtilization(int $mosqueId, ?int $areaId, string $date, int $attendance, ?string $prayerType = null, ?int $activityId = null, ?int $capacity = null): MosqueUtilizationLog
    {
        $capacity ??= MosqueArea::query()->whereKey($areaId)->value('capacity')
            ?? Mosque::query()->whereKey($mosqueId)->value('capacity');

        $pct = ($capacity && $capacity > 0) ? round(min(100, ($attendance / $capacity) * 100), 2) : null;

        return MosqueUtilizationLog::query()->create([
            'mosque_id' => $mosqueId,
            'area_id' => $areaId,
            'log_date' => $date,
            'prayer_type' => $prayerType,
            'activity_id' => $activityId,
            'attendance_count' => $attendance,
            'capacity' => $capacity,
            'utilization_pct' => $pct,
            'source' => 'SYSTEM',
            'meta' => null,
        ]);
    }
}
