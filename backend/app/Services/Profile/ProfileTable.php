<?php

namespace App\Services\Profile;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembentuk blok tabel untuk "Profil 360°".
 *
 * Profil menggabungkan data dari puluhan modul. Agar tidak ada satu modul yang
 * mematahkan seluruh halaman (tabel belum dimigrasi, kolom berganti nama), blok
 * dibangun terhadap skema yang SEDANG berlaku: tabel yang tidak ada dilewati,
 * kolom yang diminta tetapi tidak ada dibuang, dan bila kolom tidak ditentukan
 * dipilih otomatis (urutan skema, tanpa kolom teknis/rahasia) dengan label
 * berbahasa Indonesia dan format (tanggal, uang, lencana) yang disimpulkan dari tipe.
 *
 * Hanya MEMBACA. Kolom bertanda rahasia (token, path berkas, hash, kunci
 * idempotensi, JSON mentah) tidak pernah dikirim.
 */
final class ProfileTable
{
    /** @var array<string, array{0: list<array<string, mixed>>, 1: array<string, string>}> */
    private static array $schemaCache = [];

    /** @var array<string, array{0: string, 1: string}> kolom FK → [tabel, kolom label] */
    private const FK = [
        'school_class_id' => ['school_classes', 'name'],
        'class_id' => ['school_classes', 'name'],
        'academic_year_id' => ['academic_years', 'name'],
        'current_academic_year_id' => ['academic_years', 'name'],
        'next_academic_year_id' => ['academic_years', 'name'],
        'semester_id' => ['semesters', 'name'],
        'subject_id' => ['subjects', 'name'],
        'guardian_id' => ['guardians', 'full_name'],
        'employee_id' => ['employees', 'full_name'],
        'advisor_employee_id' => ['employees', 'full_name'],
        'musyrif_employee_id' => ['employees', 'full_name'],
        'coach_employee_id' => ['employees', 'full_name'],
        'teacher_id' => ['employees', 'full_name'],
        'student_id' => ['students', 'full_name'],
        'education_level_id' => ['education_levels', 'name'],
        'gender_id' => ['genders', 'name'],
        'religion_id' => ['religions', 'name'],
        'dormitory_id' => ['boarding_dormitories', 'name'],
        'building_id' => ['boarding_buildings', 'name'],
        'floor_id' => ['boarding_floors', 'name'],
        'bed_id' => ['boarding_beds', 'code'],
        'group_id' => ['boarding_groups', 'name'],
        'guardian_relation_id' => ['guardian_relations', 'name'],
        'fee_type_id' => ['finance_fee_types', 'name'],
        'payment_method_id' => ['finance_payment_methods', 'name'],
        'surah_id' => ['quran_surahs', 'name'],
    ];

    /** @var array<string, string> */
    private const WORDS = [
        'id' => 'ID', 'nis' => 'NIS', 'nisn' => 'NISN', 'nip' => 'NIP', 'nik' => 'NIK',
        'date' => 'Tanggal', 'at' => '', 'on' => '', 'start' => 'Mulai', 'end' => 'Selesai',
        'status' => 'Status', 'amount' => 'Jumlah', 'total' => 'Total', 'name' => 'Nama',
        'title' => 'Judul', 'description' => 'Deskripsi', 'notes' => 'Catatan', 'note' => 'Catatan',
        'type' => 'Jenis', 'number' => 'Nomor', 'code' => 'Kode', 'reason' => 'Alasan',
        'paid' => 'Dibayar', 'remaining' => 'Sisa', 'due' => 'Jatuh tempo', 'issue' => 'Terbit',
        'method' => 'Metode', 'score' => 'Nilai', 'grade' => 'Predikat', 'rank' => 'Peringkat',
        'result' => 'Hasil', 'level' => 'Tingkat', 'priority' => 'Prioritas', 'severity' => 'Keparahan',
        'category' => 'Kategori', 'class' => 'Kelas', 'year' => 'Tahun', 'academic' => 'Akademik',
        'semester' => 'Semester', 'subject' => 'Mapel', 'employee' => 'Pegawai', 'student' => 'Siswa',
        'guardian' => 'Wali', 'teacher' => 'Guru', 'program' => 'Program', 'period' => 'Periode',
        'location' => 'Lokasi', 'destination' => 'Tujuan', 'purpose' => 'Keperluan', 'role' => 'Peran',
        'decision' => 'Keputusan', 'scope' => 'Cakupan', 'source' => 'Sumber', 'mode' => 'Mode',
        'points' => 'Poin', 'point' => 'Poin', 'percentage' => 'Persen', 'percent' => 'Persen',
        'progress' => 'Kemajuan', 'balance' => 'Saldo', 'direction' => 'Arah', 'reference' => 'Referensi',
        'invoice' => 'Tagihan', 'payment' => 'Pembayaran', 'receipt' => 'Kuitansi', 'billing' => 'Penagihan',
        'dormitory' => 'Asrama', 'room' => 'Kamar', 'bed' => 'Kasur', 'building' => 'Gedung', 'floor' => 'Lantai',
        'visit' => 'Kunjungan', 'complaint' => 'Keluhan', 'chief' => 'Utama', 'findings' => 'Temuan',
        'recommendation' => 'Rekomendasi', 'treatment' => 'Penanganan', 'advice' => 'Saran',
        'vaccine' => 'Vaksin', 'dose' => 'Dosis', 'next' => 'Berikutnya', 'given' => 'Diberikan',
        'screened' => 'Diperiksa', 'height' => 'Tinggi', 'weight' => 'Berat', 'vision' => 'Penglihatan',
        'left' => 'Kiri', 'right' => 'Kanan', 'dental' => 'Gigi', 'borrowed' => 'Dipinjam',
        'returned' => 'Dikembalikan', 'renewed' => 'Diperpanjang', 'count' => 'Jumlah', 'fine' => 'Denda',
        'member' => 'Anggota', 'joined' => 'Bergabung', 'ended' => 'Berakhir', 'registered' => 'Terdaftar',
        'submitted' => 'Diajukan', 'decided' => 'Diputuskan', 'approved' => 'Disetujui', 'verified' => 'Diverifikasi',
        'published' => 'Terbit', 'opened' => 'Dibuka', 'closed' => 'Ditutup', 'resolved' => 'Selesai',
        'attempt' => 'Percobaan', 'passed' => 'Lulus', 'max' => 'Maks', 'enrolled' => 'Terdaftar',
        'completed' => 'Selesai', 'last' => 'Terakhir', 'accessed' => 'Diakses', 'time' => 'Waktu',
        'in' => 'Masuk', 'out' => 'Keluar', 'late' => 'Terlambat', 'minutes' => 'Menit', 'session' => 'Sesi',
        'attendance' => 'Kehadiran', 'homecoming' => 'Kepulangan', 'return' => 'Kembali', 'home' => 'Pulang',
        'actual' => 'Aktual', 'expected' => 'Perkiraan', 'permission' => 'Izin', 'requested' => 'Diminta',
        'violation' => 'Pelanggaran', 'narrative' => 'Uraian', 'action' => 'Tindakan', 'officer' => 'Petugas',
        'condition' => 'Kondisi', 'visitor' => 'Pengunjung', 'relationship' => 'Hubungan', 'identity' => 'Identitas',
        'deposited' => 'Dititipkan', 'received' => 'Diterima', 'issued' => 'Diterbitkan', 'certificate' => 'Sertifikat',
        'verification' => 'Verifikasi', 'rank_' => '', 'organizer' => 'Penyelenggara', 'awarded' => 'Diraih',
        'ownership' => 'Kepemilikan', 'ayah' => 'Ayat', 'page' => 'Halaman', 'juz' => 'Juz', 'surah' => 'Surah',
        'fluency' => 'Kelancaran', 'tajwid' => 'Tajwid', 'makhraj' => 'Makharij', 'mistakes' => 'Kesalahan',
        'memorized' => 'Dihafal', 'mutqin' => 'Mutqin', 'setoran' => 'Setoran', 'murojaah' => 'Murojaah',
        'avg' => 'Rata-rata', 'participant' => 'Peserta', 'enrollment' => 'Pendaftaran', 'halaqah' => 'Halaqah',
        'follow' => 'Tindak', 'up' => 'lanjut', 'required' => 'Wajib', 'activated' => 'Aktif', 'cancel' => 'Batal',
        'discount' => 'Diskon', 'scholarship' => 'Beasiswa', 'percentage_' => '', 'plan' => 'Rencana', 'installment' => 'Cicilan',
        'clearance' => 'Surat bebas tanggungan', 'outstanding' => 'Tunggakan', 'credit' => 'Kredit', 'headline' => 'Ringkasan',
        'tax' => 'Pajak', 'subtotal' => 'Subtotal', 'customer' => 'Pelanggan', 'cost' => 'Biaya', 'center' => 'Pusat',
        'gateway' => 'Gerbang', 'transaction' => 'Transaksi', 'proof' => 'Bukti', 'bank' => 'Bank', 'cash' => 'Kas',
        'currency' => 'Mata uang', 'fee' => 'Biaya', 'requirements' => 'Persyaratan', 'motivation' => 'Motivasi',
        'waitlist' => 'Daftar tunggu', 'position' => 'Posisi', 'idempotency' => '', 'key' => '',
        'advisory' => 'Bimbingan', 'alert' => 'Peringatan', 'message' => 'Pesan', 'triggered' => 'Terpicu',
        'document' => 'Dokumen', 'issued_by' => 'Diterbitkan oleh', 'graduation' => 'Kelulusan', 'criteria' => 'Kriteria',
        'summary' => 'Ringkasan', 'average' => 'Rata-rata', 'blood' => 'Golongan darah', 'allergy' => 'Alergi',
        'emergency' => 'Darurat', 'contact' => 'Kontak', 'relation' => 'Hubungan', 'insurance' => 'Asuransi',
        'provider' => 'Penyedia', 'patient' => 'Pasien', 'medical' => 'Medis', 'record' => 'Rekam',
        'parent' => 'Orang tua', 'notified' => 'Diberitahu', 'counselor' => 'Konselor', 'case' => 'Kasus',
        'appointment' => 'Janji temu', 'scheduled' => 'Dijadwalkan', 'metric' => 'Metrik', 'observed' => 'Teramati',
        'value' => 'Nilai', 'threshold' => 'Ambang', 'window' => 'Rentang', 'days' => 'Hari', 'evaluation' => 'Evaluasi',
        'matched' => 'Cocok', 'detected' => 'Terdeteksi', 'indicator' => 'Indikator', 'label' => 'Label',
        'confidentiality' => 'Kerahasiaan', 'phone' => 'Telepon', 'email' => 'Email', 'address' => 'Alamat',
        'position_' => '', 'department' => 'Departemen', 'join' => 'Bergabung', 'employment' => 'Kepegawaian',
        'is' => '', 'has' => '', 'active' => 'Aktif', 'group' => 'Kelompok', 'by' => 'oleh', 'of' => '', 'for' => 'untuk', 'to' => 'ke', 'from' => 'dari',
    ];

    /** Kolom yang tidak pernah ditampilkan pada mode otomatis. */
    private const HIDDEN = '/^(id|created_at|updated_at|deleted_at|school_id|library_id|clinic_id|uuid|remember_token|password|idempotency_key|dedup_key|metadata|snapshot|data|context|evidence|checklist|properties)$|(_path|_token|_hash|_secret|_key|_snapshot|_json|_files|_by|_ip)$|^(client_ip|user_agent|device|public_token|qr_token|verification_code)$/';

    private string $table;

    private string $title;

    /** @var list<array{0: string, 1: mixed, 2: string}> */
    private array $wheres = [];

    /** @var list<Closure> */
    private array $scopes = [];

    /** @var array<string, array{0: string, 1: string|null}>|null */
    private ?array $columns = null;

    /** @var list<string> */
    private array $order = [];

    private int $limit = 15;

    /** @var array<string, array{0: string, 1: string}> */
    private array $fk = [];

    /** @var list<string> */
    private array $hide = [];

    private function __construct(string $title, string $table)
    {
        $this->title = $title;
        $this->table = $table;
    }

    public static function make(string $title, string $table): self
    {
        return new self($title, $table);
    }

    public static function exists(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /** @param  mixed  $value  array → whereIn */
    public function where(string $column, mixed $value, string $operator = '='): self
    {
        $this->wheres[] = [$column, $value, $operator];

        return $this;
    }

    /** @param  Closure(Builder): mixed  $scope  untuk kondisi khusus (OR, subquery) */
    public function scope(Closure $scope): self
    {
        $this->scopes[] = $scope;

        return $this;
    }

    /** @param  array<string, string|array{0: string, 1: string}>  $spec  kolom => label | [label, format] */
    public function columns(array $spec): self
    {
        $this->columns = [];
        foreach ($spec as $column => $def) {
            $this->columns[$column] = is_array($def) ? [$def[0], $def[1] ?? null] : [$def, null];
        }

        return $this;
    }

    public function hide(string ...$columns): self
    {
        $this->hide = array_merge($this->hide, $columns);

        return $this;
    }

    /** Urutkan menurun menurut kolom pertama yang ada. */
    public function latest(string ...$candidates): self
    {
        $this->order = $candidates;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(1, min(200, $limit));

        return $this;
    }

    /** Selesaikan kolom FK ke label dari tabel lain (menimpa bawaan). */
    public function resolve(string $column, string $table, string $labelColumn): self
    {
        $this->fk[$column] = [$table, $labelColumn];

        return $this;
    }

    public function count(): int
    {
        if (! Schema::hasTable($this->table)) {
            return 0;
        }

        return (int) $this->base()->count();
    }

    /** @return array<string, mixed>|null null bila tabel/kolom penyaring tidak ada */
    public function toBlock(): ?array
    {
        if (! Schema::hasTable($this->table)) {
            return null;
        }

        [$columns, $types] = self::schema($this->table);
        $present = array_column($columns, 'name');

        // Semua kolom penyaring wajib ada; bila tidak, tabel ini tidak berlaku bagi entitas ini.
        foreach ($this->wheres as [$col]) {
            if (! in_array($col, $present, true)) {
                return null;
            }
        }

        $spec = $this->resolveColumns($present, $types);
        if ($spec === []) {
            return null;
        }

        $query = $this->base();
        $total = (int) (clone $query)->count();

        foreach ($this->order as $candidate) {
            if (in_array($candidate, $present, true)) {
                $query->orderByDesc($candidate);
                break;
            }
        }
        $hasId = in_array('id', $present, true);
        if ($hasId) {
            $query->orderByDesc('id');
        }

        $rows = $query->limit($this->limit)->get(array_merge($hasId ? ['id'] : [], array_keys($spec)))
            ->map(fn ($row): array => (array) $row)
            ->all();

        $labels = $this->fkLabels($rows, array_keys($spec));

        $outColumns = [];
        foreach ($spec as $column => [$label, $format]) {
            $isFk = isset($labels[$column]);
            $outColumns[] = [
                'key' => $isFk ? self::stripId($column) : $column,
                'label' => $label,
                'format' => $isFk ? 'text' : ($format ?? 'text'),
            ];
        }

        $outRows = [];
        foreach ($rows as $row) {
            $out = [];
            foreach ($spec as $column => $_) {
                $value = $row[$column] ?? null;
                if (isset($labels[$column])) {
                    $out[self::stripId($column)] = $value === null ? null : ($labels[$column][$value] ?? null);

                    continue;
                }
                $out[$column] = is_string($value) && mb_strlen($value) > 160 ? mb_substr($value, 0, 157).'…' : $value;
            }
            $outRows[] = $out;
        }

        return [
            'type' => 'table',
            'title' => $this->title,
            'columns' => $outColumns,
            'rows' => $outRows,
            'total' => $total,
        ];
    }

    private function base(): Builder
    {
        $query = DB::table($this->table);
        [$columns] = self::schema($this->table);
        $present = array_column($columns, 'name');

        if (in_array('deleted_at', $present, true)) {
            $query->whereNull('deleted_at');
        }
        foreach ($this->wheres as [$col, $value, $op]) {
            is_array($value) ? $query->whereIn($col, $value === [] ? [-1] : $value) : $query->where($col, $op, $value);
        }
        foreach ($this->scopes as $scope) {
            $scope($query);
        }

        return $query;
    }

    /**
     * @param  list<string>  $present
     * @param  array<string, string>  $types
     * @return array<string, array{0: string, 1: string}>
     */
    private function resolveColumns(array $present, array $types): array
    {
        $spec = [];

        if ($this->columns !== null) {
            foreach ($this->columns as $column => [$label, $format]) {
                if (in_array($column, $present, true)) {
                    $spec[$column] = [$label, $format ?? self::inferFormat($column, $types[$column] ?? '')];
                }
            }

            return $spec;
        }

        $filtered = array_map(fn (array $w): string => $w[0], $this->wheres);

        foreach ($present as $column) {
            if (count($spec) >= 8) {
                break;
            }
            if (preg_match(self::HIDDEN, $column) === 1 || in_array($column, $this->hide, true) || in_array($column, $filtered, true)) {
                continue;
            }
            $type = $types[$column] ?? '';
            if (preg_match('/json|blob|binary/i', $type) === 1) {
                continue;
            }
            // Kolom *_id hanya tampil bila bisa diselesaikan menjadi label.
            if (str_ends_with($column, '_id') && ! isset($this->fk[$column]) && ! isset(self::FK[$column])) {
                continue;
            }
            $spec[$column] = [self::humanize($column), self::inferFormat($column, $type)];
        }

        return $spec;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @return array<string, array<int|string, string>>
     */
    private function fkLabels(array $rows, array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            if (! str_ends_with($column, '_id')) {
                continue;
            }
            [$table, $label] = $this->fk[$column] ?? self::FK[$column] ?? [null, null];
            if ($table === null || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $label)) {
                continue;
            }
            $ids = array_values(array_unique(array_filter(array_column($rows, $column), fn ($v) => $v !== null)));
            if ($ids === []) {
                $out[$column] = [];

                continue;
            }
            $out[$column] = DB::table($table)->whereIn('id', $ids)->pluck($label, 'id')->all();
        }

        return $out;
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, string>} */
    private static function schema(string $table): array
    {
        if (! isset(self::$schemaCache[$table])) {
            $columns = Schema::getColumns($table);
            $types = [];
            foreach ($columns as $c) {
                $types[$c['name']] = strtolower((string) ($c['type'] ?? $c['type_name'] ?? ''));
            }
            self::$schemaCache[$table] = [$columns, $types];
        }

        return self::$schemaCache[$table];
    }

    private static function stripId(string $column): string
    {
        return preg_replace('/_id$/', '', $column) ?: $column;
    }

    public static function inferFormat(string $column, string $type): string
    {
        if (preg_match('/(^|_)(status|decision|result|priority|severity|level|condition)$|^status_/', $column) === 1) {
            return 'badge';
        }
        if (preg_match('/^(is_|has_)|^(activated|passed|matched|late_return|is_late)$/', $column) === 1 || str_contains($type, 'tinyint(1)') || $type === 'boolean' || $type === 'bool') {
            return 'bool';
        }
        if (str_contains($type, 'datetime') || str_contains($type, 'timestamp') || preg_match('/_at$/', $column) === 1) {
            return 'datetime';
        }
        if ($type === 'date' || preg_match('/(^|_)(date|on)$/', $column) === 1 || str_ends_with($column, '_date')) {
            return 'date';
        }
        if (preg_match('/decimal|numeric|float|double/', $type) === 1) {
            return preg_match('/amount|total|price|balance|paid|remaining|subtotal|fee|nominal|tax|discount|outstanding|credit|fine/', $column) === 1 ? 'money' : 'number';
        }
        if (preg_match('/int/', $type) === 1 && preg_match('/amount|total_amount|balance/', $column) === 1) {
            return 'money';
        }
        if (preg_match('/int/', $type) === 1) {
            return 'number';
        }

        return 'text';
    }

    /** Label penuh untuk kolom yang terjemahan per-katanya janggal. */
    private const COLUMN_LABELS = [
        'fee_type' => 'Jenis biaya', 'violation_type' => 'Jenis pelanggaran', 'attendance_type' => 'Jenis kehadiran',
        'left_date' => 'Keluar', 'joined_date' => 'Bergabung', 'is_active' => 'Aktif', 'group' => 'Kelompok',
        'start_date' => 'Mulai', 'end_date' => 'Selesai', 'due_date' => 'Jatuh tempo', 'issue_date' => 'Terbit',
        'birth_date' => 'Tanggal lahir', 'paid_at' => 'Dibayar', 'created_at' => 'Dibuat', 'effective_date' => 'Berlaku',
        'full_name' => 'Nama lengkap', 'nick_name' => 'Nama panggilan', 'academic_year' => 'Tahun ajaran',
        'school_class' => 'Kelas', 'education_level' => 'Jenjang', 'late_minutes' => 'Menit terlambat',
    ];

    public static function humanize(string $column): string
    {
        $column = preg_replace('/_id$/', '', $column) ?: $column;
        if (isset(self::COLUMN_LABELS[$column])) {
            return self::COLUMN_LABELS[$column];
        }
        $parts = array_values(array_filter(explode('_', $column), fn ($p) => $p !== ''));
        // "xxx_date" / "xxx_at" → "Xxx" (tanggal sudah tersirat dari format kolom).
        if (count($parts) > 1 && in_array(end($parts), ['date', 'at', 'on'], true)) {
            array_pop($parts);
        }
        $words = [];
        foreach ($parts as $part) {
            $word = self::WORDS[$part] ?? ucfirst($part);
            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words === [] ? ucfirst($column) : implode(' ', $words);
    }
}
