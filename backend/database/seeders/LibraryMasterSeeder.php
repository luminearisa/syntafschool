<?php

namespace Database\Seeders;

use App\Services\Library\MasterData\LibraryMasterRegistry;
use App\Services\Library\MasterData\LibraryMasterService;
use Illuminate\Database\Seeder;

/**
 * Seed master data Library (Step 2).
 *
 * Seluruh data bersifat **global** (`library_id = NULL`) — perpustakaan dapat
 * menambah master miliknya sendiri lewat API. Tidak ada data buku/anggota di
 * sini: itu lingkup Step berikutnya.
 *
 * Idempoten: baris dicari berdasarkan `code` (atau `name` untuk tabel
 * existing yang belum punya kode) lalu diperbarui, bukan diduplikasi.
 */
class LibraryMasterSeeder extends Seeder
{
    /** @var array<string, array<int, int>> */
    private array $idByCode = [];

    public function run(): void
    {
        // ================= GLOBAL =================
        $this->seed('material-types', [
            ['code' => 'PRINTED', 'name' => 'Cetak'],
            ['code' => 'DIGITAL', 'name' => 'Digital'],
            ['code' => 'AUDIO', 'name' => 'Audio'],
            ['code' => 'VIDEO', 'name' => 'Video'],
            ['code' => 'MULTIMEDIA', 'name' => 'Multimedia'],
            ['code' => 'MANUSCRIPT', 'name' => 'Manuskrip'],
        ]);

        $this->seed('languages', [
            ['code' => 'ID', 'name' => 'Indonesia', 'native_name' => 'Bahasa Indonesia', 'iso_code' => 'id'],
            ['code' => 'EN', 'name' => 'Inggris', 'native_name' => 'English', 'iso_code' => 'en'],
            ['code' => 'AR', 'name' => 'Arab', 'native_name' => 'العربية', 'iso_code' => 'ar'],
            ['code' => 'MS', 'name' => 'Melayu', 'native_name' => 'Bahasa Melayu', 'iso_code' => 'ms'],
            ['code' => 'JA', 'name' => 'Jepang', 'native_name' => '日本語', 'iso_code' => 'ja'],
            ['code' => 'ZH', 'name' => 'Mandarin', 'native_name' => '中文', 'iso_code' => 'zh'],
        ]);

        $this->seed('countries', [
            ['code' => 'ID', 'name' => 'Indonesia', 'iso_code' => 'IDN', 'phone_code' => '+62'],
            ['code' => 'MY', 'name' => 'Malaysia', 'iso_code' => 'MYS', 'phone_code' => '+60'],
            ['code' => 'SA', 'name' => 'Arab Saudi', 'iso_code' => 'SAU', 'phone_code' => '+966'],
            ['code' => 'SG', 'name' => 'Singapura', 'iso_code' => 'SGP', 'phone_code' => '+65'],
            ['code' => 'US', 'name' => 'Amerika Serikat', 'iso_code' => 'USA', 'phone_code' => '+1'],
            ['code' => 'GB', 'name' => 'Inggris Raya', 'iso_code' => 'GBR', 'phone_code' => '+44'],
            ['code' => 'EG', 'name' => 'Mesir', 'iso_code' => 'EGY', 'phone_code' => '+20'],
        ]);

        $this->seed('creator-roles', [
            ['code' => 'AUTHOR', 'name' => 'Penulis'],
            ['code' => 'EDITOR', 'name' => 'Editor'],
            ['code' => 'TRANSLATOR', 'name' => 'Penerjemah'],
            ['code' => 'ILLUSTRATOR', 'name' => 'Ilustrator'],
            ['code' => 'COMPILER', 'name' => 'Penyusun'],
            ['code' => 'PHOTOGRAPHER', 'name' => 'Fotografer'],
            ['code' => 'COMPOSER', 'name' => 'Komposer'],
            ['code' => 'CONTRIBUTOR', 'name' => 'Kontributor'],
            ['code' => 'NARRATOR', 'name' => 'Narator'],
            ['code' => 'ORGANIZATION', 'name' => 'Organisasi'],
        ]);

        // ================= CORE (ber-scope, seed global) =================
        $this->seed('collection-types', [
            ['code' => 'BOOK', 'name' => 'Buku', 'is_physical' => true, 'is_digital' => false],
            ['code' => 'MAG', 'name' => 'Majalah', 'is_physical' => true, 'is_digital' => true],
            ['code' => 'JRN', 'name' => 'Jurnal', 'is_physical' => true, 'is_digital' => true],
            ['code' => 'NEWS', 'name' => 'Surat Kabar', 'is_physical' => true, 'is_digital' => true],
            ['code' => 'THS', 'name' => 'Skripsi', 'is_physical' => true, 'is_digital' => true],
            ['code' => 'REF', 'name' => 'Referensi', 'is_physical' => true, 'is_digital' => false],
            ['code' => 'ENC', 'name' => 'Ensiklopedia', 'is_physical' => true, 'is_digital' => false],
            ['code' => 'DICT', 'name' => 'Kamus', 'is_physical' => true, 'is_digital' => false],
            ['code' => 'QURAN', 'name' => "Al-Qur'an", 'is_physical' => true, 'is_digital' => true],
            ['code' => 'KITAB', 'name' => 'Kitab', 'is_physical' => true, 'is_digital' => false],
            ['code' => 'AUDIO', 'name' => 'Audio', 'is_physical' => false, 'is_digital' => true],
            ['code' => 'VIDEO', 'name' => 'Video', 'is_physical' => false, 'is_digital' => true],
            ['code' => 'EBOOK', 'name' => 'E-book', 'is_physical' => false, 'is_digital' => true],
            ['code' => 'DOC', 'name' => 'Dokumen', 'is_physical' => true, 'is_digital' => true],
            ['code' => 'MAP', 'name' => 'Peta', 'is_physical' => true, 'is_digital' => false],
            ['code' => 'MANUSCRIPT', 'name' => 'Manuskrip', 'is_physical' => true, 'is_digital' => false],
        ]);

        // Kategori (tabel existing `book_categories`) — hierarkis.
        $this->seed('categories', [
            ['code' => 'AGAMA', 'name' => 'Agama'],
            ['code' => 'EDU', 'name' => 'Pendidikan'],
            ['code' => 'SAINS', 'name' => 'Sains'],
            ['code' => 'TEKNOLOGI', 'name' => 'Teknologi'],
            ['code' => 'BAHASA', 'name' => 'Bahasa'],
            ['code' => 'SASTRA', 'name' => 'Sastra'],
            ['code' => 'SEJARAH', 'name' => 'Sejarah'],
            ['code' => 'SOSIAL', 'name' => 'Sosial'],
            ['code' => 'EKONOMI', 'name' => 'Ekonomi'],
            ['code' => 'MANAJEMEN', 'name' => 'Manajemen'],
            ['code' => 'ANAK', 'name' => 'Anak'],
            ['code' => 'REF', 'name' => 'Referensi'],
            ['code' => 'FIKSI', 'name' => 'Fiksi'],
            ['code' => 'NONFIKSI', 'name' => 'Nonfiksi'],
        ]);

        $this->seedChildren('categories', [
            ['code' => 'QURAN', 'name' => "Al-Qur'an", 'parent' => 'AGAMA'],
            ['code' => 'HADIS', 'name' => 'Hadits', 'parent' => 'AGAMA'],
            ['code' => 'FIQIH', 'name' => 'Fiqih', 'parent' => 'AGAMA'],
            ['code' => 'AQIDAH', 'name' => 'Aqidah', 'parent' => 'AGAMA'],
            ['code' => 'SEJARAH-ISLAM', 'name' => 'Sejarah Islam', 'parent' => 'AGAMA'],
        ]);

        $this->seed('subjects', [
            ['code' => 'ISLAM', 'name' => 'Islam'],
            ['code' => 'PROGRAMMING', 'name' => 'Pemrograman'],
            ['code' => 'AI', 'name' => 'Artificial Intelligence'],
            ['code' => 'MANAJEMEN', 'name' => 'Manajemen'],
            ['code' => 'PENDIDIKAN', 'name' => 'Pendidikan'],
            ['code' => 'SEJARAH', 'name' => 'Sejarah'],
        ]);

        $this->seedChildren('subjects', [
            ['code' => 'AQIDAH', 'name' => 'Aqidah', 'parent' => 'ISLAM'],
            ['code' => 'FIQIH', 'name' => 'Fiqih', 'parent' => 'ISLAM'],
            ['code' => 'HADIS', 'name' => 'Hadits', 'parent' => 'ISLAM'],
        ]);

        $this->seedChildren('subjects', [
            ['code' => 'FIQIH-IBADAH', 'name' => 'Ibadah', 'parent' => 'FIQIH'],
            ['code' => 'FIQIH-MUAMALAH', 'name' => 'Muamalah', 'parent' => 'FIQIH'],
        ]);

        $this->seed('classification-systems', [
            ['code' => 'DDC', 'name' => 'Dewey Decimal Classification', 'description' => 'Klasifikasi desimal Dewey (000–999).'],
            ['code' => 'LCC', 'name' => 'Library of Congress Classification'],
            ['code' => 'ISLAMIC', 'name' => 'Klasifikasi Islam'],
            ['code' => 'CUSTOM', 'name' => 'Klasifikasi Khusus Sekolah'],
        ]);

        $ddc = $this->idMap('classification-systems');
        $this->seed('classifications', [
            ['code' => '000', 'name' => 'Karya Umum', 'system_id' => $ddc['DDC'], 'level' => 1],
            ['code' => '100', 'name' => 'Filsafat & Psikologi', 'system_id' => $ddc['DDC'], 'level' => 1],
            ['code' => '200', 'name' => 'Agama', 'system_id' => $ddc['DDC'], 'level' => 1],
            ['code' => '300', 'name' => 'Ilmu Sosial', 'system_id' => $ddc['DDC'], 'level' => 1],
            ['code' => '500', 'name' => 'Sains & Matematika', 'system_id' => $ddc['DDC'], 'level' => 1],
            ['code' => '600', 'name' => 'Teknologi', 'system_id' => $ddc['DDC'], 'level' => 1],
            ['code' => '800', 'name' => 'Sastra', 'system_id' => $ddc['DDC'], 'level' => 1],
        ]);

        $this->seed('classifications', [
            ['code' => '297', 'name' => 'Islam', 'system_id' => $ddc['DDC'], 'level' => 2, 'parent_code' => '200'],
        ]);

        $this->seed('genres', [
            ['code' => 'FICTION', 'name' => 'Fiksi'],
            ['code' => 'NOVEL', 'name' => 'Novel'],
            ['code' => 'SHORTSTORY', 'name' => 'Cerpen'],
            ['code' => 'BIOGRAPHY', 'name' => 'Biografi'],
            ['code' => 'POETRY', 'name' => 'Puisi'],
            ['code' => 'DRAMA', 'name' => 'Drama'],
            ['code' => 'MYSTERY', 'name' => 'Misteri'],
            ['code' => 'FANTASY', 'name' => 'Fantasi'],
            ['code' => 'HISTORY', 'name' => 'Sejarah'],
            ['code' => 'EDUCATIONAL', 'name' => 'Edukatif'],
        ]);

        $this->seed('audience-types', [
            ['code' => 'CHILD', 'name' => 'Anak', 'min_age' => 6, 'max_age' => 12],
            ['code' => 'TEEN', 'name' => 'Remaja', 'min_age' => 13, 'max_age' => 18],
            ['code' => 'ADULT', 'name' => 'Dewasa', 'min_age' => 19],
            ['code' => 'ALL', 'name' => 'Semua Umur'],
            ['code' => 'SD', 'name' => 'SD'],
            ['code' => 'SMP', 'name' => 'SMP'],
            ['code' => 'SMA', 'name' => 'SMA'],
            ['code' => 'COLLEGE', 'name' => 'Perguruan Tinggi'],
            ['code' => 'GENERAL', 'name' => 'Umum'],
        ]);

        $this->seed('acquisition-sources', [
            ['code' => 'PURCHASE', 'name' => 'Pembelian'],
            ['code' => 'DONATION', 'name' => 'Sumbangan'],
            ['code' => 'GRANT', 'name' => 'Hibah'],
            ['code' => 'EXCHANGE', 'name' => 'Tukar Menukar'],
            ['code' => 'GIFT', 'name' => 'Hadiah'],
            ['code' => 'DEPOSIT', 'name' => 'Titipan'],
            ['code' => 'TRANSFER', 'name' => 'Transfer Internal'],
            ['code' => 'GOVERNMENT', 'name' => 'Pemerintah'],
            ['code' => 'INSTITUTION', 'name' => 'Institusi'],
            ['code' => 'OTHER', 'name' => 'Lainnya'],
        ]);

        $this->seed('conditions', [
            ['code' => 'NEW', 'name' => 'Baru', 'is_borrowable' => true],
            ['code' => 'EXCELLENT', 'name' => 'Sangat Baik', 'is_borrowable' => true],
            ['code' => 'GOOD', 'name' => 'Baik', 'is_borrowable' => true],
            ['code' => 'FAIR', 'name' => 'Cukup', 'is_borrowable' => true],
            ['code' => 'POOR', 'name' => 'Kurang', 'is_borrowable' => false],
            ['code' => 'DAMAGED', 'name' => 'Rusak', 'is_borrowable' => false],
            ['code' => 'LOST', 'name' => 'Hilang', 'is_borrowable' => false],
        ]);

        $this->seed('collection-statuses', [
            ['code' => 'ACTIVE', 'name' => 'Aktif', 'is_available' => true],
            ['code' => 'REF_ONLY', 'name' => 'Hanya Baca di Tempat', 'is_available' => false],
            ['code' => 'PROCESSING', 'name' => 'Dalam Proses', 'is_available' => false],
            ['code' => 'LOST', 'name' => 'Hilang', 'is_available' => false],
            ['code' => 'DAMAGED', 'name' => 'Rusak', 'is_available' => false],
            ['code' => 'WITHDRAWN', 'name' => 'Ditarik', 'is_available' => false],
            ['code' => 'ARCHIVED', 'name' => 'Diarsipkan', 'is_available' => false],
        ]);

        // ================= SUPPORTING =================
        $this->seed('series', [
            ['code' => 'ENSIKLOPEDIA-ISLAM', 'name' => 'Ensiklopedia Islam'],
            ['code' => 'SERI-ANAK-MUSLIM', 'name' => 'Seri Anak Muslim'],
            ['code' => 'CAMBRIDGE-EN', 'name' => 'Cambridge English'],
        ]);

        $this->seed('tags', [
            ['code' => 'AI', 'name' => 'AI'],
            ['code' => 'LARAVEL', 'name' => 'Laravel'],
            ['code' => 'PENDIDIKAN-ISLAM', 'name' => 'Pendidikan Islam'],
            ['code' => 'TAHFIZ', 'name' => 'Tahfiz'],
            ['code' => 'MANAJEMEN-SEKOLAH', 'name' => 'Manajemen Sekolah'],
        ]);

        // Jenis keanggotaan (Fase 3) — policy pinjam sebagai referensi Fase 4.
        $this->seed('membership-types', [
            ['code' => 'STUDENT', 'name' => 'Siswa', 'max_loan' => 3, 'loan_duration_days' => 7, 'renewal_limit' => 1],
            ['code' => 'TEACHER', 'name' => 'Guru', 'max_loan' => 10, 'loan_duration_days' => 14, 'renewal_limit' => 2],
            ['code' => 'EMPLOYEE', 'name' => 'Pegawai', 'max_loan' => 5, 'loan_duration_days' => 7, 'renewal_limit' => 1],
            ['code' => 'PARENT', 'name' => 'Wali Murid', 'max_loan' => 3, 'loan_duration_days' => 7, 'renewal_limit' => 1],
            ['code' => 'ALUMNI', 'name' => 'Alumni', 'max_loan' => 3, 'loan_duration_days' => 7, 'renewal_limit' => 1],
            ['code' => 'PUBLIC', 'name' => 'Umum', 'max_loan' => 5, 'loan_duration_days' => 7, 'renewal_limit' => 1],
            ['code' => 'VIP', 'name' => 'VIP / Khusus', 'max_loan' => 10, 'loan_duration_days' => 14, 'renewal_limit' => 3],
        ]);

        // Penerbit & kreator memakai tabel existing: dicari berdasarkan nama
        // karena baris lama belum memiliki kode.
        $indonesia = $this->idMap('countries')['ID'] ?? null;

        $this->seed('publishers', [
            ['code' => 'ERL', 'name' => 'Erlangga', 'short_name' => 'Erlangga', 'country_id' => $indonesia],
            ['code' => 'GRM', 'name' => 'Gramedia', 'short_name' => 'Gramedia', 'country_id' => $indonesia],
            ['code' => 'YDS', 'name' => 'Yudhistira', 'short_name' => 'Yudhistira', 'country_id' => $indonesia],
        ], matchBy: 'name');

        // Baris 'Unknown' sudah ada dari seeder referensi lama — dilengkapi
        // kode, bukan diduplikasi.
        $this->seed('creators', [
            ['code' => 'UNKNOWN', 'name' => 'Unknown'],
        ], matchBy: 'name');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function seed(string $key, array $rows, string $matchBy = 'code'): void
    {
        $service = app(LibraryMasterService::class);
        $master = $service->descriptor($key);
        $scoped = $master['scoped'];

        foreach ($rows as $row) {
            $query = $master['model']::query()->where($matchBy, $row[$matchBy]);

            if ($scoped) {
                $query->whereNull('library_id');
            }

            $existing = $query->first();

            $payload = array_merge(['status' => 'active'], $row);

            // Klasifikasi menunjuk sistem lewat id, bukan kode.
            if (isset($payload['parent_code'])) {
                $payload['parent_id'] = $this->idMap($key)[$payload['parent_code']] ?? null;
                unset($payload['parent_code']);
            }

            if ($existing === null) {
                $created = $service->create($master, $payload);
                $this->idByCode[$key][$row[$matchBy]] = $created->id;
            } else {
                $service->update($master, $existing, $payload);
                $this->idByCode[$key][$row[$matchBy]] = $existing->id;
            }
        }
    }

    /**
     * Seed baris turunan yang menunjuk induk lewat kode.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function seedChildren(string $key, array $rows): void
    {
        $service = app(LibraryMasterService::class);
        $master = $service->descriptor($key);
        $map = $this->idMap($key);

        foreach ($rows as $row) {
            $parentId = $map[$row['parent']] ?? null;

            $existing = $master['model']::query()->where('code', $row['code'])->first();

            $payload = [
                'code' => $row['code'],
                'name' => $row['name'],
                'parent_id' => $parentId,
                'status' => 'active',
            ];

            if ($existing === null) {
                $created = $service->create($master, $payload);
                $this->idByCode[$key][$row['code']] = $created->id;
            } else {
                $service->update($master, $existing, $payload);
                $this->idByCode[$key][$row['code']] = $existing->id;
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function idMap(string $key): array
    {
        if (isset($this->idByCode[$key])) {
            return $this->idByCode[$key];
        }

        $master = LibraryMasterRegistry::find($key);

        $this->idByCode[$key] = $master['model']::query()
            ->whereNotNull('code')
            ->pluck('id', 'code')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->idByCode[$key];
    }
}