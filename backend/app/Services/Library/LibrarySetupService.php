<?php

namespace App\Services\Library;

use App\Models\Library;
use App\Models\LibraryLocation;
use App\Models\LibraryNumberingConfig;
use App\Models\LibraryOperatingHour;
use App\Models\LibrarySetting;

/**
 * Kelengkapan konfigurasi sebuah perpustakaan.
 *
 * Dipakai untuk checklist onboarding dan sebagai syarat minimum sebelum
 * sebuah perpustakaan boleh diaktifkan.
 */
class LibrarySetupService
{
    /** Bobot tiap pemeriksaan (total 100). */
    private const WEIGHTS = [
        'basic_information' => 25,
        'operating_hours' => 20,
        'locations' => 20,
        'numbering' => 20,
        'catalog_configuration' => 15,
    ];

    /**
     * @return list<array{key:string,label:string,status:string,detail:string,weight:int}>
     */
    public function checks(Library $library): array
    {
        $locationCount = LibraryLocation::query()
            ->where('library_id', $library->id)
            ->where('status', LibraryLocation::STATUS_ACTIVE)
            ->count();

        $hourCount = LibraryOperatingHour::query()->where('library_id', $library->id)->count();
        $numberingCount = LibraryNumberingConfig::query()->where('library_id', $library->id)->count();
        $catalogCount = LibrarySetting::query()
            ->where('library_id', $library->id)
            ->where('group', LibrarySetting::GROUP_CATALOG)
            ->count();

        $basicComplete = $this->hasBasicInformation($library);

        return [
            [
                'key' => 'basic_information',
                'label' => 'Informasi Dasar',
                'status' => $basicComplete ? 'complete' : 'incomplete',
                'detail' => $basicComplete
                    ? 'Nama, kode, dan zona waktu terisi.'
                    : 'Lengkapi nama, kode, dan zona waktu.',
                'weight' => self::WEIGHTS['basic_information'],
            ],
            [
                'key' => 'operating_hours',
                'label' => 'Jam Operasional',
                'status' => $hourCount > 0 ? 'complete' : 'incomplete',
                'detail' => $hourCount > 0
                    ? "{$hourCount} baris jam operasional tersedia."
                    : 'Atur jam operasional mingguan.',
                'weight' => self::WEIGHTS['operating_hours'],
            ],
            [
                'key' => 'locations',
                'label' => 'Lokasi',
                'status' => $locationCount > 0 ? 'complete' : 'incomplete',
                'detail' => $locationCount > 0
                    ? "{$locationCount} lokasi aktif."
                    : 'Tambahkan minimal satu lokasi.',
                'weight' => self::WEIGHTS['locations'],
            ],
            [
                'key' => 'numbering',
                'label' => 'Penomoran',
                'status' => $numberingCount > 0 ? 'complete' : 'warning',
                'detail' => $numberingCount > 0
                    ? "{$numberingCount} konfigurasi penomoran."
                    : 'Penomoran belum dikonfigurasi.',
                'weight' => self::WEIGHTS['numbering'],
            ],
            [
                'key' => 'catalog_configuration',
                'label' => 'Konfigurasi Katalog',
                'status' => $catalogCount > 0 ? 'complete' : 'warning',
                'detail' => $catalogCount > 0
                    ? 'Preferensi ISBN/barcode/RFID tersedia.'
                    : 'Preferensi katalog belum diatur.',
                'weight' => self::WEIGHTS['catalog_configuration'],
            ],
        ];
    }

    /**
     * Ringkasan kelengkapan.
     *
     * @return array{status:string,percent:int,checks:list<array<string,mixed>>}
     */
    public function summary(Library $library): array
    {
        $checks = $this->checks($library);

        $score = 0.0;
        $hasIncomplete = false;
        $hasWarning = false;

        foreach ($checks as $check) {
            $score += match ($check['status']) {
                'complete' => (float) $check['weight'],
                'warning' => $check['weight'] / 2,
                default => 0.0,
            };

            $hasIncomplete = $hasIncomplete || $check['status'] === 'incomplete';
            $hasWarning = $hasWarning || $check['status'] === 'warning';
        }

        return [
            'status' => $hasIncomplete ? 'incomplete' : ($hasWarning ? 'warning' : 'complete'),
            'percent' => (int) round($score),
            'checks' => $checks,
        ];
    }

    /**
     * Pemeriksaan yang wajib terpenuhi sebelum perpustakaan dapat diaktifkan.
     *
     * @return list<string>  label pemeriksaan yang belum lengkap
     */
    public function missingRequirements(Library $library): array
    {
        if (! $this->hasBasicInformation($library)) {
            return ['Informasi Dasar', 'Jam Operasional', 'Lokasi'];
        }

        $required = ['basic_information', 'operating_hours', 'locations'];

        return array_values(array_map(
            fn (array $check) => $check['label'],
            array_filter($this->checks($library), fn (array $check) => in_array($check['key'], $required, true) && $check['status'] !== 'complete'),
        ));
    }

    private function hasBasicInformation(Library $library): bool
    {
        return filled($library->name) && filled($library->code) && filled($library->timezone);
    }
}