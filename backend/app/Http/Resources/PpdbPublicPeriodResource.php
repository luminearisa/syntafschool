<?php

namespace App\Http\Resources;

use App\Models\PpdbPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bentuk publik satu periode PPDB — TANPA data pribadi.
 *
 * Dipakai daftar publik, halaman publik, dan kartu periode di dashboard portal,
 * jadi `summary()` menjadi satu-satunya definisi bentuk periode di sisi pembaca.
 * Konten halaman (FAQ, syarat dokumen, biaya, tahap seleksi) diserahkan lewat
 * `$extra` yang disusun `PpdbRegistrationQueryService::publicPeriodPayload()`.
 *
 * @property PpdbPeriod $resource
 */
class PpdbPublicPeriodResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $quota
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        PpdbPeriod $resource,
        private readonly array $quota = [],
        private readonly array $extra = [],
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(self::summary($this->resource, $this->quota), $this->extra);
    }

    /**
     * Ringkasan publik periode + kuota.
     *
     * @param  array<string, mixed>  $quota
     * @return array<string, mixed>
     */
    public static function summary(PpdbPeriod $period, array $quota = []): array
    {
        return [
            'id' => $period->id,
            'code' => $period->code,
            'name' => $period->name,
            'slug' => $period->slug,
            'public_title' => $period->public_title ?: $period->name,
            'public_description' => $period->public_description,
            'status' => $period->status,
            'academic_year' => $period->academicYear === null ? null : [
                'id' => $period->academicYear->id,
                'name' => $period->academicYear->name,
                'start_date' => $period->academicYear->start_date?->toDateString(),
                'end_date' => $period->academicYear->end_date?->toDateString(),
            ],
            'banner_url' => self::assetUrl($period->banner_path),
            'logo_url' => self::assetUrl($period->logo_path),
            'contact' => [
                'name' => $period->contact_name,
                'phone' => $period->contact_phone,
                'email' => $period->contact_email,
                'address' => $period->contact_address,
            ],
            'registration_open' => $period->isRegistrationWindowOpen(),
            'registration_start' => $period->registration_start?->toDateString(),
            'registration_end' => $period->registration_end?->toDateString(),
            'selection_announcement_date' => $period->selection_announcement_date?->toDateString(),
            're_registration_start' => $period->re_registration_start?->toDateString(),
            're_registration_end' => $period->re_registration_end?->toDateString(),
            'quota' => [
                'total_quota' => $quota['total_quota'] ?? null,
                'registered' => $quota['registered'] ?? 0,
                'remaining' => $quota['remaining'] ?? null,
                'open_program_count' => $quota['open_program_count'] ?? 0,
            ],
            'programs' => $quota['programs'] ?? [],
            'waves' => $quota['waves'] ?? [],
            'created_at' => $period->created_at?->toIso8601String(),
        ];
    }

    public static function assetUrl(?string $path): ?string
    {
        return filled($path) ? Storage::disk('public')->url((string) $path) : null;
    }
}
