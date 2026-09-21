<?php

namespace App\Services\Ppdb;

use App\Models\PpdbFormField;
use App\Models\PpdbPeriod;
use App\Models\PpdbProgram;
use App\Models\PpdbQuestion;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationAnswer;
use App\Models\PpdbWave;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

/**
 * Aturan turunan konfigurasi PPDB.
 *
 * Konfigurasi penerimaan adalah DATA (lihat migrasi 2026_09_22_100001), jadi
 * aturan yang membuatnya tetap aman harus hidup di satu tempat, bukan tersebar
 * di tiap controller: kode & slug periode yang dibangkitkan otomatis, perpindahan
 * status periode, proteksi hapus, dan dua pembantu list (urutan whitelist &
 * paginasi) yang dipakai seluruh endpoint ADMIN — KONFIGURASI.
 *
 * Aturan scope "satu periode" (program/gelombang harus milik periode yang sama)
 * juga dibaca dari sini lewat `scopeErrors()` supaya tidak disalin di request
 * pertanyaan, dokumen, dan biaya.
 */
class PpdbPeriodService
{
    /**
     * Daftar periode untuk halaman konfigurasi.
     *
     * Filter: `status`, `academic_year_id`, `search` (kode/nama/slug).
     */
    public function query(Request $request): Builder
    {
        // `academicYear` ikut di daftar: baris periode menampilkan tahun ajarannya,
        // dan tanpa relasi ini resource menyembunyikan kuncinya (`whenLoaded`).
        $query = PpdbPeriod::query()
            ->with('academicYear:id,name,start_date,end_date')
            ->withCount(['waves', 'programs', 'registrations']);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->integer('academic_year_id'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');

            $query->where(fn (Builder $q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%"));
        }

        return $this->sorted(
            $query,
            $request,
            ['code', 'name', 'slug', 'status', 'registration_start', 'registration_end', 'selection_announcement_date', 'created_at'],
            'created_at'
        )->orderByDesc('id');
    }

    /**
     * Terapkan urutan permintaan (`sort_by` + `sort_dir`) secara aman: hanya
     * kolom pada `$sortable` yang diterima, sisanya jatuh ke bawaan.
     *
     * Tanpa permintaan eksplisit dipakai `$defaultDirection`; begitu pengguna
     * memilih kolom sendiri arahnya naik (mis. nama A–Z) kecuali diminta turun.
     *
     * @param  list<string>  $sortable
     */
    public function sorted(
        Builder $query,
        Request $request,
        array $sortable,
        string $default = 'created_at',
        string $defaultDirection = 'desc',
    ): Builder {
        $requested = (string) $request->query('sort_by', '');
        $sortBy = in_array($requested, $sortable, true) ? $requested : $default;
        $direction = strtolower((string) $request->query('sort_dir', ''));

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $sortBy === $default ? $defaultDirection : 'asc';
        }

        return $query->orderBy($sortBy, $direction);
    }

    /**
     * Paginasi seragam daftar konfigurasi; `all=1` mengambil seluruh baris.
     *
     * @return LengthAwarePaginator<int, mixed>|Collection<int, mixed>
     */
    public function paginated(Request $request, Builder $query): LengthAwarePaginator|Collection
    {
        if ($request->boolean('all')) {
            return $query->get();
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $query->paginate($perPage)->appends($request->query());
    }

    /**
     * Buat periode. Kode & slug dibangkitkan bila kosong dan dijamin unik
     * (diberi akhiran -2, -3, ...).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PpdbPeriod
    {
        $status = (string) ($data['status'] ?? PpdbPeriod::STATUS_DRAFT);

        // Periode baru belum punya program & gelombang: membukanya langsung akan
        // menghasilkan form pendaftaran kosong. Gunakan endpoint terbitkan.
        if ($status === PpdbPeriod::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'status' => 'Periode baru belum dapat langsung dibuka. Lengkapi programnya lalu terbitkan periode.',
            ]);
        }

        $data['code'] = $this->uniqueCode($data['code'] ?? null, $data);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? null, (string) ($data['name'] ?? ''), (string) $data['code']);
        $data['status'] = $status;

        return PpdbPeriod::create($data);
    }

    /**
     * Perbarui periode. Perubahan status diuji terhadap `PpdbPeriod::TRANSITIONS`.
     *
     * Slug sengaja tidak dirombak saat hanya nama yang berubah: slug adalah
     * alamat halaman publik yang sudah tersebar.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PpdbPeriod $period, array $data): PpdbPeriod
    {
        if (array_key_exists('code', $data)) {
            $data['code'] = blank($data['code'])
                ? $period->code
                : $this->uniqueCode((string) $data['code'], $data, $period);
        }

        if (array_key_exists('slug', $data)) {
            $data['slug'] = blank($data['slug'])
                ? $period->slug
                : $this->uniqueSlug(
                    (string) $data['slug'],
                    (string) ($data['name'] ?? $period->name),
                    (string) ($data['code'] ?? $period->code),
                    $period,
                );
        }

        $to = $data['status'] ?? null;

        if (filled($to) && $to !== $period->status) {
            $this->assertTransition($period, (string) $to);
        }

        $period->fill($data);
        $period->save();

        return $period->refresh();
    }

    /**
     * Terbitkan periode: status menjadi `open` bila transisi legal dan periode
     * punya minimal satu program aktif.
     */
    public function publish(PpdbPeriod $period): PpdbPeriod
    {
        $this->assertTransition($period, PpdbPeriod::STATUS_OPEN);

        $period->status = PpdbPeriod::STATUS_OPEN;
        $period->save();

        return $period->refresh();
    }

    /**
     * Hapus lembut periode — ditolak bila masih ada pendaftaran.
     */
    public function delete(PpdbPeriod $period): void
    {
        $this->assertNotInUse($period->registrations()->count(), 'Periode', 'pendaftaran', 'period');

        $period->delete();
    }

    /**
     * Uji perpindahan status terhadap `PpdbPeriod::TRANSITIONS` dan syarat
     * khusus untuk status `open`.
     */
    public function assertTransition(PpdbPeriod $period, string $to): void
    {
        if (! in_array($to, PpdbPeriod::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Status tujuan tidak dikenali.']);
        }

        $allowed = $period->allowedTransitions();

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => $allowed === []
                    ? "Periode berstatus {$period->status} bersifat final dan tidak dapat diubah lagi."
                    : "Perubahan status {$period->status} → {$to} tidak diizinkan. Status yang tersedia: ".implode(', ', $allowed).'.',
            ]);
        }

        if ($to === PpdbPeriod::STATUS_OPEN && ! $this->hasActiveProgram($period)) {
            throw ValidationException::withMessages([
                'programs' => 'Periode harus punya minimal satu program aktif sebelum dibuka.',
            ]);
        }
    }

    public function hasActiveProgram(PpdbPeriod $period): bool
    {
        return $period->programs()->where('status', PpdbProgram::STATUS_ACTIVE)->exists();
    }

    /**
     * Tolak penghapusan konfigurasi yang masih dipakai data transaksi — kolom
     * `nullOnDelete`/`cascadeOnDelete` pada tabel transaksi akan menghapus atau
     * memutus jejak pendaftaran bila baris konfigurasinya hilang.
     */
    public function assertNotInUse(int $used, string $label, string $subject, string $attribute = 'usage'): void
    {
        if ($used > 0) {
            throw ValidationException::withMessages([
                $attribute => "{$label} masih dipakai oleh {$used} {$subject} dan tidak dapat dihapus.",
            ]);
        }
    }

    /**
     * Label scope sebuah aturan konfigurasi (pertanyaan/dokumen/biaya) untuk
     * ditampilkan di daftar — hindari frontend menebak ulang aturannya.
     */
    public static function scopeLabel(?int $programId, ?int $waveId): string
    {
        return match ([filled($programId), filled($waveId)]) {
            [true, true] => 'program_wave',
            [true, false] => 'program',
            [false, true] => 'wave',
            default => 'global',
        };
    }

    /**
     * Periksa program & gelombang yang dipakai sebuah aturan konfigurasi
     * (pertanyaan, dokumen, biaya) tetap berada dalam satu periode.
     *
     * Nilai null berarti aturan itu global untuk periodenya. Hasilnya dipakai
     * request agar pesan error menempel pada kolom yang salah.
     *
     * @return array<string, string> atribut => pesan
     */
    public function scopeErrors(?int $periodId, ?int $programId, ?int $waveId): array
    {
        if ($periodId === null) {
            return [];
        }

        $errors = [];

        if ($programId !== null && $this->periodIdOfProgram($programId) !== $periodId) {
            $errors['program_id'] = 'Program terpilih bukan milik periode ini.';
        }

        if ($waveId !== null && $this->periodIdOfWave($waveId) !== $periodId) {
            $errors['wave_id'] = 'Gelombang terpilih bukan milik periode ini.';
        }

        return $errors;
    }

    /**
     * Gelombang & penawaran program hanya boleh dipakai bersama periode yang
     * sama; nilai null berarti aturan berlaku global untuk periode tersebut.
     */
    private function periodIdOfProgram(?int $programId): ?int
    {
        $periodId = $programId === null ? null : PpdbProgram::query()->whereKey($programId)->value('period_id');

        return $periodId === null ? null : (int) $periodId;
    }

    private function periodIdOfWave(?int $waveId): ?int
    {
        $periodId = $waveId === null ? null : PpdbWave::query()->whereKey($waveId)->value('period_id');

        return $periodId === null ? null : (int) $periodId;
    }

    /**
     * Apakah kunci field masih dipakai jawaban pendaftaran pada periodenya?
     * Jawaban menyimpan `field_key` (bukan id), sehingga mengganti kunci akan
     * memutus jawaban yang sudah ada.
     */
    public function fieldKeyInUse(PpdbFormField $field): bool
    {
        return PpdbRegistrationAnswer::query()
            ->where('field_key', $field->field_key)
            ->whereIn('registration_id', PpdbRegistration::query()->where('period_id', $field->period_id)->select('id'))
            ->exists();
    }

    /** Berapa jawaban pendaftar yang memakai pertanyaan ini. */
    public function questionAnswerUsage(PpdbQuestion $question): int
    {
        return PpdbRegistrationAnswer::query()->where('question_id', $question->id)->count();
    }

    /**
     * Aturan keunikan kode dalam satu periode (dipakai seluruh request
     * konfigurasi).
     */
    public static function uniqueInPeriod(string $table, string $column, ?int $periodId, ?int $ignoreId = null): Unique
    {
        $rule = Rule::unique($table, $column)->ignore($ignoreId);

        return $periodId === null ? $rule : $rule->where('period_id', $periodId);
    }

    /**
     * Kode periode: pakai yang diminta, atau bangkitkan `PPDB-{tahun}`.
     *
     * @param  array<string, mixed>  $data
     */
    private function uniqueCode(?string $code, array $data, ?PpdbPeriod $ignore = null): string
    {
        $base = trim((string) $code);

        if ($base === '') {
            $base = 'PPDB-'.$this->intakeYear($data);
        }

        return $this->available($base, fn (string $value) => $this->taken('code', $value, $ignore));
    }

    /**
     * Slug halaman publik: slug dari nama (atau kode sebagai cadangan),
     * dijamin unik.
     */
    private function uniqueSlug(?string $slug, string $name, string $code, ?PpdbPeriod $ignore = null): string
    {
        $source = filled($slug) ? (string) $slug : ($name !== '' ? $name : $code);
        $base = Str::limit(Str::slug($source) ?: Str::slug('ppdb'), 90, '');

        return $this->available($base, fn (string $value) => $this->taken('slug', $value, $ignore));
    }

    /**
     * Tahun masuk yang dipakai sebagai dasar kode: tahun tanggal pendaftaran
     * terdekat yang diketahui, selain itu tahun ini.
     *
     * @param  array<string, mixed>  $data
     */
    private function intakeYear(array $data): int
    {
        foreach (['registration_start', 'selection_announcement_date', 're_registration_start'] as $key) {
            if (filled($data[$key] ?? null)) {
                return (int) Str::substr((string) $data[$key], 0, 4);
            }
        }

        return (int) now()->format('Y');
    }

    /** Baris periode dengan kolom bernilai sama (termasuk yang terhapus lembut). */
    private function taken(string $column, string $value, ?PpdbPeriod $ignore = null): bool
    {
        return PpdbPeriod::withTrashed()
            ->where($column, $value)
            ->when($ignore !== null, fn (Builder $q) => $q->whereKeyNot($ignore->id))
            ->exists();
    }

    /** candidate, candidate-2, candidate-3, ... sampai bebas. */
    private function available(string $base, callable $taken): string
    {
        $candidate = $base;
        $suffix = 2;

        while ($taken($candidate)) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
