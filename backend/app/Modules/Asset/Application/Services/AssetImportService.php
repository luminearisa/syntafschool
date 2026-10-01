<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Models\AssetImport;
use Illuminate\Support\Facades\DB;

/**
 * Impor massal dengan pratinjau.
 *
 * Data TIDAK PERNAH langsung dimasukkan: berkas diunggah, diperiksa, lalu
 * ditampilkan sebagai pratinjau berisi baris yang sah dan baris yang bermasalah —
 * baru setelah itu diimpor. Impor "langsung" pada data aset menghasilkan label
 * yang tercetak untuk barang yang salah, dan itu pekerjaan yang mahal untuk
 * dibatalkan.
 */
class AssetImportService
{
    /** @var array<string, list<string>> */
    protected array $required = [
        'assets' => ['name', 'category_code', 'acquisition_cost'],
        'locations' => ['name', 'location_type'],
        'facilities' => ['name'],
        'asset_categories' => ['code', 'name'],
        'custodians' => ['name', 'custodian_type'],
    ];

    public function __construct(private readonly AssetAuditService $audit) {}

    /** Unggah + validasi: menghasilkan pratinjau, belum menyimpan apa pun. */
    public function upload(User $actor, string $type, string $path, ?string $originalName = null): AssetImport
    {
        if (! array_key_exists($type, $this->required)) {
            throw new AssetValidationException(['type' => ['Jenis impor tidak dikenal: '.$type]]);
        }

        $rows = $this->read($path);
        $summary = $this->validate($type, $rows);

        return AssetImport::query()->create([
            'type' => $type,
            'file_path' => $path,
            'original_name' => $originalName,
            'status' => $summary['invalid'] === count($rows) && $rows !== [] ? 'FAILED' : 'VALIDATED',
            'summary' => [
                'total' => count($rows),
                'valid' => $summary['valid'],
                'invalid' => $summary['invalid'],
                'errors' => array_slice($summary['errors'], 0, 50),
            ],
            'preview' => array_slice($summary['preview'], 0, 20),
            'created_by' => $actor->id,
        ]);
    }

    /** Konfirmasi impor: baris sah dimasukkan, baris bermasalah dilewati & dilaporkan. */
    public function confirm(User $actor, AssetImport $import): array
    {
        if ($import->status === 'IMPORTED') {
            throw new AssetValidationException(['status' => ['Berkas ini sudah diimpor.']]);
        }

        $rows = $this->read((string) $import->file_path);
        $summary = $this->validate($import->type, $rows);

        $created = 0;
        $skipped = [];

        DB::transaction(function () use ($actor, $import, $summary, &$created, &$skipped): void {
            foreach ($summary['rows'] as $index => $row) {
                if ($row['valid']) {
                    $this->persist($actor, $import->type, $row['data']);
                    $created++;

                    continue;
                }

                $skipped[] = ['line' => $index + 2, 'errors' => $row['errors'], 'data' => $row['data']];
            }

            $import->forceFill([
                'status' => 'IMPORTED',
                'confirmed_at' => now(),
                'summary' => array_merge((array) $import->summary, [
                    'created' => $created,
                    'skipped' => count($skipped),
                    'skipped_details' => array_slice($skipped, 0, 50),
                ]),
            ])->save();

            $this->audit->record(AuditAction::ASSET_IMPORTED, $import, $actor, [
                'reason' => 'Impor massal '.$import->type.' dikonfirmasi.',
                'context' => ['created' => $created, 'skipped' => count($skipped)],
            ]);
        });

        return ['created' => $created, 'skipped' => $skipped, 'import' => $import->refresh()];
    }

    /** Baca CSV (atau XLSX yang telah dikonversi menjadi CSV oleh penyedia dokumen). */
    protected function read(string $path): array
    {
        $full = str_starts_with($path, '/') ? $path : storage_path('app/'.$path);

        if (! is_file($full)) {
            throw new AssetValidationException(['file' => ['Berkas impor tidak ditemukan.']]);
        }

        $handle = fopen($full, 'r');

        if ($handle === false) {
            throw new AssetValidationException(['file' => ['Berkas impor tidak dapat dibaca.']]);
        }

        $header = fgetcsv($handle) ?: [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }

            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), null)) ?: [];
        }

        fclose($handle);

        return $rows;
    }

    protected function validate(string $type, array $rows): array
    {
        $required = $this->required[$type];
        $valid = 0;
        $errors = [];
        $preview = [];
        $validated = [];

        foreach ($rows as $index => $row) {
            $rowErrors = [];

            foreach ($required as $column) {
                if (! isset($row[$column]) || trim((string) $row[$column]) === '') {
                    $rowErrors[] = "Kolom $column wajib diisi.";
                }
            }

            if ($type === 'assets' && isset($row['acquisition_cost']) && ! is_numeric($row['acquisition_cost'])) {
                $rowErrors[] = 'Harga perolehan harus berupa angka.';
            }

            $validated[] = ['data' => $row, 'valid' => $rowErrors === [], 'errors' => $rowErrors];

            if ($rowErrors === []) {
                $valid++;
            } else {
                $errors[] = ['line' => $index + 2, 'errors' => $rowErrors];
            }

            $preview[] = $row + ['_valid' => $rowErrors === [], '_errors' => $rowErrors];
        }

        return [
            'rows' => $validated,
            'valid' => $valid,
            'invalid' => count($rows) - $valid,
            'errors' => $errors,
            'preview' => $preview,
        ];
    }

    protected function persist(User $actor, string $type, array $row): void
    {
        match ($type) {
            'asset_categories' => app(AssetCategoryService::class)->createCategory($actor, [
                'code' => $row['code'],
                'name' => $row['name'],
                'kind' => $row['kind'] ?? 'ASSET',
                'useful_life_months' => isset($row['useful_life_months']) ? (int) $row['useful_life_months'] : null,
                'capitalization_threshold' => $row['capitalization_threshold'] ?? null,
            ]),
            'locations' => app(LocationService::class)->create($actor, [
                'name' => $row['name'],
                'location_type' => $row['location_type'],
                'code' => $row['code'] ?? null,
                'parent_location_id' => $row['parent_location_id'] ?? null,
                'capacity' => isset($row['capacity']) ? (int) $row['capacity'] : null,
            ]),
            'facilities' => app(FacilityService::class)->create($actor, [
                'name' => $row['name'],
                'location_id' => $row['location_id'] ?? null,
                'capacity_total' => isset($row['capacity_total']) ? (int) $row['capacity_total'] : null,
                'facility_code' => $row['facility_code'] ?? null,
            ]),
            'custodians' => \App\Modules\Asset\Models\AssetCustodian::query()->create([
                'name' => $row['name'],
                'custodian_type' => $row['custodian_type'],
                'user_id' => $row['user_id'] ?? null,
                'unit_id' => $row['unit_id'] ?? null,
            ]),
            default => app(AssetRegisterService::class)->register($actor, [
                'name' => $row['name'],
                'category_id' => \App\Modules\Asset\Models\AssetCategory::query()->where('code', $row['category_code'])->value('id'),
                'acquisition_cost' => (string) $row['acquisition_cost'],
                'serial_number' => $row['serial_number'] ?? null,
                'asset_code' => $row['asset_code'] ?? null,
                'location_id' => $row['location_id'] ?? null,
                'purchase_date' => $row['purchase_date'] ?? null,
                'brand' => $row['brand'] ?? null,
                'model' => $row['model'] ?? null,
                'force_asset' => true,
            ]),
        };
    }
}
