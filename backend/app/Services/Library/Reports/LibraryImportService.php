<?php

namespace App\Services\Library\Reports;

use App\Models\Library;
use App\Models\LibraryBibliography;
use App\Models\LibraryBibliographyIdentifier;
use App\Models\LibraryItem;
use App\Models\LibraryMember;
use App\Services\Library\Catalog\CatalogService;
use App\Services\Library\Catalog\CollectionItemService;
use App\Services\Library\MasterData\LibraryMasterRegistry;
use App\Services\Library\Membership\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Impor sederhana (CSV) untuk katalog, eksemplar, dan anggota (§5).
 *
 * Dua langkah: **preview** (validasi tiap baris, deteksi duplikat, tanpa
 * menulis apa pun) lalu **commit** (hanya baris valid yang disimpan, di dalam
 * satu transaksi). Baris tidak valid dilaporkan per nomor baris agar dapat
 * diperbaiki — data invalid tidak pernah dimasukkan.
 */
class LibraryImportService
{
    public function __construct(
        protected CatalogService $catalog,
        protected CollectionItemService $items,
        protected MembershipService $members,
    ) {
    }

    /**
     * Header & contoh baris untuk template CSV.
     *
     * @return array{headers:list<string>,sample:list<string>}
     */
    public function template(string $type): array
    {
        return match ($type) {
            'catalog' => [
                'headers' => ['title', 'subtitle', 'isbn13', 'publisher', 'publication_year', 'language', 'collection_type'],
                'sample' => ['Belajar Laravel', 'Panduan Modern', '9786020000011', 'Erlangga', '2026', 'Indonesia', 'Buku'],
            ],
            'items' => [
                'headers' => ['bibliography_control_number', 'barcode', 'location', 'condition'],
                'sample' => ['BIB-2026-001', 'BC-0001', 'Lantai 1', 'Baik'],
            ],
            'members' => [
                'headers' => ['member_type', 'nis_or_nip', 'name', 'phone', 'email', 'valid_until'],
                'sample' => ['student', '2026001', '', '0812345678', '', '2027-06-30'],
            ],
            default => throw ValidationException::withMessages(['type' => "Jenis impor \"{$type}\" tidak dikenal."]),
        };
    }

    /**
     * Baca CSV menjadi baris ber-nomor (baris 1 = header).
     *
     * @return list<array{row:int,data:array<string,string>}>
     */
    public function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Berkas tidak dapat dibaca.']);
        }

        $headers = fgetcsv($handle) ?: [];
        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $headers);

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle)) !== false) {
            $line++;

            // Lewati baris kosong.
            if ($values === [null] || $values === []) {
                continue;
            }

            $values = array_pad(array_slice($values, 0, count($headers)), count($headers), '');

            $rows[] = [
                'row' => $line,
                'data' => array_combine($headers, array_map(fn ($v) => trim((string) $v), $values)) ?: [],
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Validasi tanpa menulis apa pun.
     *
     * @return array{total:int,valid:int,invalid:int,duplicate:int,rows:list<array<string,mixed>>}
     */
    public function preview(string $type, array $parsedRows, Library $library): array
    {
        $rows = [];
        $valid = 0;
        $invalid = 0;
        $duplicate = 0;

        foreach ($parsedRows as $entry) {
            $errors = $this->validateRow($type, $entry['data'], $library);
            $isDuplicate = $errors === [] && $this->isDuplicate($type, $entry['data'], $library);

            if ($errors === []) {
                $valid++;
            } else {
                $invalid++;
            }

            if ($isDuplicate) {
                $duplicate++;
            }

            $rows[] = [
                'row' => $entry['row'],
                'data' => $entry['data'],
                'errors' => $errors,
                'is_duplicate' => $isDuplicate,
            ];
        }

        return [
            'total' => count($parsedRows),
            'valid' => $valid,
            'invalid' => $invalid,
            'duplicate' => $duplicate,
            'rows' => $rows,
        ];
    }

    /**
     * Simpan hanya baris valid (satu transaksi).
     *
     * @param  list<array<string,mixed>>  $rows  keluaran `preview()['rows']`
     * @return array{imported:int,skipped:int}
     */
    public function commit(string $type, array $rows, Library $library): array
    {
        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($type, $rows, $library, &$imported, &$skipped): void {
            foreach ($rows as $entry) {
                $data = $entry['data'] ?? [];

                if (($entry['errors'] ?? []) !== []) {
                    $skipped++;

                    continue;
                }

                match ($type) {
                    'catalog' => $this->importCatalog($data, $library),
                    'items' => $this->importItem($data, $library),
                    'members' => $this->importMember($data, $library),
                    default => throw ValidationException::withMessages(['type' => "Jenis impor \"{$type}\" tidak dikenal."]),
                };

                $imported++;
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @param  array<string,string>  $row
     * @return list<string>
     */
    protected function validateRow(string $type, array $row, Library $library): array
    {
        $errors = [];

        if ($type === 'catalog') {
            if (blank($row['title'] ?? null)) {
                $errors[] = 'Title wajib diisi.';
            }

            if (filled($row['isbn13'] ?? null) && strlen(preg_replace('/[^0-9Xx]/', '', $row['isbn13'])) !== 13) {
                $errors[] = 'ISBN-13 harus 13 digit.';
            }

            if (filled($row['publication_year'] ?? null) && ! preg_match('/^\d{4}$/', $row['publication_year'])) {
                $errors[] = 'Tahun terbit harus 4 digit.';
            }
        }

        if ($type === 'items') {
            if (blank($row['bibliography_control_number'] ?? null)) {
                $errors[] = 'Nomor kontrol bibliografi wajib diisi.';
            } elseif (! LibraryBibliography::query()
                ->where('library_id', $library->id)
                ->where('control_number', $row['bibliography_control_number'])
                ->exists()) {
                $errors[] = 'Record bibliografi tidak ditemukan pada perpustakaan ini.';
            }

            if (filled($row['barcode'] ?? null) && LibraryItem::query()->where('barcode', $row['barcode'])->exists()) {
                $errors[] = 'Barcode sudah terdaftar.';
            }

            if (filled($row['location'] ?? null) && ! $this->locationId($library, $row['location'])) {
                $errors[] = "Lokasi \"{$row['location']}\" tidak ditemukan.";
            }

            if (filled($row['condition'] ?? null) && ! $this->masterId('conditions', $row['condition'], $library)) {
                $errors[] = "Kondisi \"{$row['condition']}\" tidak ditemukan.";
            }
        }

        if ($type === 'members') {
            $memberType = strtolower($row['member_type'] ?? '');

            if (! in_array($memberType, ['student', 'employee', 'public'], true)) {
                $errors[] = 'member_type harus salah satu: student, employee, public.';
            }

            if ($memberType === 'student') {
                $nis = $row['nis_or_nip'] ?? '';

                if (blank($nis)) {
                    $errors[] = 'NIS wajib diisi untuk anggota siswa.';
                } elseif (! \App\Models\Student::query()->where('nis', $nis)->exists()) {
                    $errors[] = "Siswa dengan NIS {$nis} tidak ditemukan.";
                }
            }

            if ($memberType === 'employee') {
                $nip = $row['nis_or_nip'] ?? '';

                if (blank($nip)) {
                    $errors[] = 'NIP wajib diisi untuk anggota pegawai.';
                } elseif (! \App\Models\Employee::query()->where('nip', $nip)->exists()) {
                    $errors[] = "Pegawai dengan NIP {$nip} tidak ditemukan.";
                }
            }

            if ($memberType === 'public' && blank($row['name'] ?? null)) {
                $errors[] = 'Nama wajib diisi untuk anggota umum.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,string>  $row
     */
    protected function isDuplicate(string $type, array $row, Library $library): bool
    {
        if ($type === 'catalog' && filled($row['isbn13'] ?? null)) {
            $normalized = LibraryBibliographyIdentifier::normalize($row['isbn13']);

            return LibraryBibliography::query()
                ->where('library_id', $library->id)
                ->whereHas('identifiers', fn ($q) => $q->where('normalized_value', $normalized))
                ->exists();
        }

        if ($type === 'members') {
            $nis = $row['nis_or_nip'] ?? '';

            if (strtolower($row['member_type'] ?? '') === 'student' && filled($nis)) {
                $studentId = \App\Models\Student::query()->where('nis', $nis)->value('id');

                return LibraryMember::query()
                    ->where('library_id', $library->id)
                    ->where('student_id', $studentId)
                    ->exists();
            }
        }

        return false;
    }

    /**
     * @param  array<string,string>  $row
     */
    protected function importCatalog(array $row, Library $library): void
    {
        $payload = [
            'library_id' => $library->id,
            'title' => $row['title'],
            'subtitle' => $row['subtitle'] ?? null,
            'publication_year' => filled($row['publication_year'] ?? null) ? (int) $row['publication_year'] : null,
            'publisher_id' => $this->masterId('publishers', $row['publisher'] ?? null, $library),
            'language_id' => $this->masterId('languages', $row['language'] ?? null, $library),
            'collection_type_id' => $this->masterId('collection-types', $row['collection_type'] ?? null, $library),
            'status' => LibraryBibliography::STATUS_DRAFT,
        ];

        if (filled($row['isbn13'] ?? null)) {
            $payload['identifiers'] = [[
                'identifier_type' => 'isbn13',
                'value' => $row['isbn13'],
                'is_primary' => true,
            ]];
        }

        $this->catalog->create($payload);
    }

    /**
     * @param  array<string,string>  $row
     */
    protected function importItem(array $row, Library $library): void
    {
        $bibliography = LibraryBibliography::query()
            ->where('library_id', $library->id)
            ->where('control_number', $row['bibliography_control_number'])
            ->firstOrFail();

        $this->items->create($bibliography, [
            'barcode' => filled($row['barcode'] ?? null) ? $row['barcode'] : null,
            'location_id' => $this->locationId($library, $row['location'] ?? null),
            'condition_id' => $this->masterId('conditions', $row['condition'] ?? null, $library),
        ]);
    }

    /**
     * @param  array<string,string>  $row
     */
    protected function importMember(array $row, Library $library): void
    {
        $type = strtolower($row['member_type'] ?? 'public');

        $payload = [
            'library_id' => $library->id,
            'valid_from' => now()->toDateString(),
            'valid_until' => filled($row['valid_until'] ?? null) ? $row['valid_until'] : null,
        ];

        if ($type === 'student') {
            $payload['student_id'] = \App\Models\Student::query()->where('nis', $row['nis_or_nip'])->value('id');
            $payload['membership_type_id'] = $this->masterId('membership-types', 'STUDENT', $library);
        } elseif ($type === 'employee') {
            $payload['employee_id'] = \App\Models\Employee::query()->where('nip', $row['nis_or_nip'])->value('id');
            $payload['membership_type_id'] = $this->masterId('membership-types', 'EMPLOYEE', $library);
        } else {
            $payload['name'] = $row['name'] ?? null;
            $payload['membership_type_id'] = $this->masterId('membership-types', 'PUBLIC', $library);
        }

        if (filled($row['phone'] ?? null)) {
            $payload['phone'] = $row['phone'];
        }

        if (filled($row['email'] ?? null)) {
            $payload['email'] = $row['email'];
        }

        $this->members->register($payload);
    }

    /**
     * Cari id master berdasarkan nama atau kode (case-insensitive).
     */
    protected function masterId(string $key, ?string $value, Library $library): ?int
    {
        if (blank($value)) {
            return null;
        }

        $master = LibraryMasterRegistry::find($key);

        if ($master === null) {
            return null;
        }

        $query = $master['model']::query()
            ->where(fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower($value)])
                ->orWhereRaw('lower(code) = ?', [mb_strtolower($value)]));

        if ($master['scoped']) {
            $query->where(fn ($q) => $q->whereNull('library_id')->orWhere('library_id', $library->id));
        }

        return (int) ($query->value('id') ?? 0) ?: null;
    }

    protected function locationId(Library $library, ?string $name): ?int
    {
        if (blank($name)) {
            return null;
        }

        return (int) (\App\Models\LibraryLocation::query()
            ->where('library_id', $library->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->value('id') ?? 0) ?: null;
    }
}