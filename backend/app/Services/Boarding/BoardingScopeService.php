<?php

namespace App\Services\Boarding;

use App\Models\BoardingGroup;
use App\Models\BoardingGroupMember;
use App\Models\BoardingMusyrif;
use App\Models\BoardingPlacement;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pembatas akses ("scope") pengasuh asrama.
 *
 * ## Kenapa perlu
 *
 * Modul boarding dipakai dua jenis orang: petugas penuh (admin, kepala asrama)
 * yang melihat SEMUA santri, dan pengasuh (musyrif/musyrifah/wali kamar) yang
 * hanya boleh melihat SANTRI BINAANNYA. Bedanya bukan di layar semata — kalau
 * query-nya tidak dibatasi, satu endpoint membocorkan data seluruh pondok.
 *
 * ## Aturan main
 *
 * - `restricted()` FALSE (artinya bebas) bila pengguna memegang permission
 *   `boarding.settings.manage` (admin/kepala asrama) ATAU berperan `super-admin`,
 *   ATAU bila ia bukan pengasuh: peran petugas (absensi, perizinan, kedisiplinan,
 *   kesehatan) memang bekerja untuk seluruh santri, bukan hanya binaannya.
 * - Pengasuh = berperan `wali-kamar`/`musyrif` ATAU punya penugasan/kelompok
 *   binaan aktif. Hak pengasuh diambil dari gabungan (union):
 *   1. penugasan aktifnya di `boarding_musyrifs` per area — asrama / gedung /
 *      kamar, sehingga kepala asrama melihat seluruh kamarnya dan wali kamar
 *      hanya kamarnya sendiri. Setiap baris penugasan dinilai pada area
 *      tersempit yang ia isi (kamar mengalahkan gedung, gedung mengalahkan
 *      asrama), supaya baris wali kamar yang ikut menyimpan `dormitory_id`
 *      tidak diam-diam membuka satu asrama; dan
 *   2. kelompok pengasuhan yang pengasuhnya adalah karyawan yang sama
 *      (`boarding_groups.musyrif_employee_id`); anggota kelompok ikut terbaca
 *      walau mereka tinggal di kamar lain.
 * - Tanpa akun karyawan, tanpa penugasan, dan tanpa kelompok → scope KOSONG.
 *   Default-nya menolak, bukan menerima (§54).
 * - `studentIdsFor()` mengembalikan `null` untuk pengguna bebas. `null` dan `[]`
 *   sengaja beda arti: `null` = "jangan disaring", `[]` = "tidak ada yang boleh
 *   dilihat". Pemanggil wajib memperlakukan keduanya berbeda, kalau tidak
 *   pengasuh tanpa penugasan justru melihat semuanya.
 *
 * Hasil scope di-cache per instance (satu request), jadi controller yang
 * memanggil beberapa kali tidak memicu query berulang.
 */
class BoardingScopeService
{
    /** Permission yang membuat pemegangnya bebas dari scope pengasuh. */
    public const UNRESTRICTED_PERMISSION = 'boarding.settings.manage';

    public const SUPER_ADMIN_ROLE = 'super-admin';

    /** Peran yang selalu diperlakukan sebagai pengasuh (ter-scope). */
    public const CAREGIVER_ROLES = ['wali-kamar', 'musyrif'];

    /** @var array<int, list<int>> */
    private array $studentIdCache = [];

    /** @var array<int, bool> */
    private array $restrictedCache = [];

    /**
     * Apakah pengguna ini perlu dibatasi ke santri binaannya saja?
     */
    public function restricted(?User $user): bool
    {
        // Tanpa pengguna (job, seeder, portal publik) default menolak: lebih baik
        // scope kosong daripada tidak ada scope.
        if ($user === null) {
            return true;
        }

        $key = (int) $user->id;

        if (isset($this->restrictedCache[$key])) {
            return $this->restrictedCache[$key];
        }

        $unrestricted = $user->hasAnyPermission([self::UNRESTRICTED_PERMISSION])
            || $user->hasRole(self::SUPER_ADMIN_ROLE);

        if ($unrestricted) {
            return $this->restrictedCache[$key] = false;
        }

        // Hanya pengasuh yang dibatasi. Peran petugas (absensi, perizinan,
        // kedisiplinan, kesehatan) bekerja untuk seluruh santri — kalau mereka
        // ikut di-scope, gerbang pondok tidak bisa mencatat apa pun.
        $caregiver = $user->hasRole(self::CAREGIVER_ROLES)
            || $this->assignmentsFor($user)->isNotEmpty()
            || $this->groupIdsFor($user) !== [];

        return $this->restrictedCache[$key] = $caregiver;
    }

    /**
     * Identitas karyawan dari satu akun login (`users.userable`), atau null bila
     * akun tersebut bukan karyawan (siswa, wali, atau akun tanpa profil).
     */
    public function employeeFor(User $user): ?Employee
    {
        $userable = $user->userable;

        return $userable instanceof Employee ? $userable : null;
    }

    /**
     * Penugasan musyrif yang sedang berjalan milik pengguna.
     *
     * @return Collection<int, BoardingMusyrif>
     */
    public function assignmentsFor(?User $user): Collection
    {
        $employee = $user === null ? null : $this->employeeFor($user);

        if ($employee === null) {
            return new Collection;
        }

        return BoardingMusyrif::query()
            ->runningOn()
            ->forEmployee((int) $employee->id)
            ->get();
    }

    /**
     * Area garapan dari daftar penugasan, sudah dipersempit per baris.
     *
     * Satu baris `boarding_musyrifs` boleh mengisi beberapa kolom area sekaligus
     * (wali kamar biasanya menyimpan `dormitory_id` juga, hanya untuk konteks).
     * Kalau seluruh kolom di-union apa adanya, baris sekamar itu otomatis memberi
     * akses ke satu asrama penuh. Jadi setiap baris dinilai pada area TERSEMPIT
     * yang ia isi: kamar → gedung → asrama. Baris tanpa area (mis. koordinator
     * yang belum diberi asrama) tidak menambah akses apa pun — santri binaannya
     * masuk lewat jalur kelompok di `studentIdsFor()`.
     *
     * @param  Collection<int, BoardingMusyrif>  $assignments
     * @return array{room: list<int>, building: list<int>, dormitory: list<int>}
     */
    private function areasFrom(Collection $assignments): array
    {
        $areas = ['room' => [], 'building' => [], 'dormitory' => []];

        foreach ($assignments as $assignment) {
            $roomId = $assignment->room_id === null ? null : (int) $assignment->room_id;
            $buildingId = $assignment->building_id === null ? null : (int) $assignment->building_id;
            $dormitoryId = $assignment->dormitory_id === null ? null : (int) $assignment->dormitory_id;

            if ($roomId !== null) {
                $areas['room'][] = $roomId;
            } elseif ($buildingId !== null) {
                $areas['building'][] = $buildingId;
            } elseif ($dormitoryId !== null) {
                $areas['dormitory'][] = $dormitoryId;
            }
        }

        return [
            'room' => array_values(array_unique($areas['room'])),
            'building' => array_values(array_unique($areas['building'])),
            'dormitory' => array_values(array_unique($areas['dormitory'])),
        ];
    }

    /**
     * ID kelompok pengasuhan yang diasuh karyawan pengguna.
     *
     * @return list<int>
     */
    public function groupIdsFor(?User $user): array
    {
        $employee = $user === null ? null : $this->employeeFor($user);

        if ($employee === null) {
            return [];
        }

        return BoardingGroup::query()
            ->where('musyrif_employee_id', $employee->id)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Santri binaan pengguna.
     *
     * Aktor `null` = pemanggil sudah menegakkan otorisasinya sendiri (portal
     * orang tua: kepemilikan anak diverifikasi `BoardingPortalService::assertChild()`
     * sebelum service domain dipanggil tanpa scope), jadi tidak dibatasi lagi.
     *
     * @return list<int>|null  null = tidak perlu dibatasi
     */
    public function studentIdsFor(?User $user): ?array
    {
        if ($user === null || ! $this->restricted($user)) {
            return null;
        }

        $cacheKey = (int) ($user?->id ?? 0);

        if (isset($this->studentIdCache[$cacheKey])) {
            return $this->studentIdCache[$cacheKey];
        }

        $assignments = $this->assignmentsFor($user);
        $groupIds = $this->groupIdsFor($user);

        if ($assignments->isEmpty() && $groupIds === []) {
            return $this->studentIdCache[$cacheKey] = [];
        }

        $areas = $this->areasFrom($assignments);

        // Penempatan aktif pada seluruh area yang ditugaskan (union). Tanpa satu
        // pun area, query-nya dilewati saja: hasilnya pasti kosong, dan closure
        // `where` yang tidak menambah syarat apa pun tidak pernah dikirim ke DB.
        $hasArea = $areas['room'] !== [] || $areas['building'] !== [] || $areas['dormitory'] !== [];

        $placementIds = [];

        if ($hasArea) {
            $placementIds = BoardingPlacement::query()
                ->active()
                ->where(function ($query) use ($areas): void {
                    $query->when($areas['room'] !== [], fn ($q) => $q->orWhereIn('room_id', $areas['room']))
                        ->when($areas['building'] !== [], fn ($q) => $q->orWhereIn('building_id', $areas['building']))
                        ->when($areas['dormitory'] !== [], fn ($q) => $q->orWhereIn('dormitory_id', $areas['dormitory']));
                })
                ->pluck('student_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }

        // Anggota kelompok binaan yang masih aktif.
        $groupMemberIds = $groupIds === [] ? [] : BoardingGroupMember::query()
            ->current()
            ->whereIn('group_id', $groupIds)
            ->pluck('student_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $ids = array_values(array_unique([...$placementIds, ...$groupMemberIds]));
        sort($ids);

        return $this->studentIdCache[$cacheKey] = $ids;
    }

    /**
     * Boleh dibaca pengguna ini? Selalu true untuk pengguna bebas.
     */
    public function canAccessStudent(?User $user, int $studentId): bool
    {
        $ids = $this->studentIdsFor($user);

        return $ids === null || in_array($studentId, $ids, true);
    }

    /**
     * Gerbang akses satu santri: 403 dengan pesan yang bisa dibaca petugas.
     */
    public function assertCanAccessStudent(?User $user, int $studentId): void
    {
        if ($this->canAccessStudent($user, $studentId)) {
            return;
        }

        abort(403, 'Anda hanya dapat mengakses santri binaan Anda.');
    }

    /**
     * Potong daftar id santri sesuai hak akses — dipakai endpoint yang menerima
     * filter `student_ids` dari klien supaya filter itu tidak jadi jalan belakang.
     *
     * @param  list<int>  $studentIds
     * @return list<int>
     */
    public function intersectStudentIds(?User $user, array $studentIds): array
    {
        $allowed = $this->studentIdsFor($user);

        if ($allowed === null) {
            return array_values(array_unique(array_map(intval(...), $studentIds)));
        }

        return array_values(array_intersect($allowed, array_map(intval(...), $studentIds)));
    }

    /**
     * Ringkasan hak akses untuk ditampilkan di dashboard pengasuh.
     * `student_count` bernilai -1 bila pengguna tidak dibatasi.
     *
     * @return array{restricted: bool, dormitory_ids: list<int>, building_ids: list<int>, room_ids: list<int>, group_ids: list<int>, student_count: int}
     */
    public function describe(?User $user): array
    {
        $assignments = $this->assignmentsFor($user);
        $areas = $this->areasFrom($assignments);
        $ids = $this->studentIdsFor($user);

        return [
            'restricted' => $this->restricted($user),
            'dormitory_ids' => $areas['dormitory'],
            'building_ids' => $areas['building'],
            'room_ids' => $areas['room'],
            'group_ids' => $this->groupIdsFor($user),
            'student_count' => $ids === null ? -1 : count($ids),
        ];
    }
}
