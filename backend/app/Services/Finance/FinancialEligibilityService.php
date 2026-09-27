<?php

namespace App\Services\Finance;

use App\Models\FinanceInvoice;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Kelayakan finansial santri (§11 Phase 5).
 *
 * ## Arah pertanyaan: modul akademik bertanya, Finance menjawab
 *
 * Finance TIDAK mengubah status akademik siapa pun. Kelas ini hanya mengembalikan
 * putusan atas satu aksi ("boleh ikut ujian?", "boleh ambil rapor?") dan modul
 * pemanggil yang memutuskan apa yang terjadi selanjutnya — sama seperti modul
 * lain memanggil `FinanceReceivableService` untuk membaca piutang. Karena itu
 * tidak ada satu pun tulisan ke tabel akademik di sini, dan `assertEligible()`
 * hanya melempar `ValidationException` yang membuat TRANSAKSI PEMANGGIL gagal,
 * bukan mengubah baris santri.
 *
 * ## Tidak ada aturan yang di-hardcode
 *
 * Aksi-aksinya (exam, report_card, ...) memang disebut §11, tetapi bagaimana tiap
 * aksi diperlakukan adalah kebijakan sekolah: aturan per aksi
 * (`eligibility_rules`), dasar hitung tunggakan (`eligibility_basis`), masa
 * tenggang (`eligibility_grace_days`), dan nilai tunggakan terendah yang masih
 * boleh menghentikan layanan (`eligibility_min_outstanding`) — semuanya dibaca
 * dari `FinanceSettingsService`. Karena itu hasil pemeriksaan selalu menyebut
 * aturan dan ambang yang memakainya: sebuah "tidak boleh" harus bisa dijelaskan
 * kembali ke konfigurasi, bukan ke satu baris `if`.
 *
 * ## Angka dibaca, tidak dihitung ulang
 *
 * Sisa tagihan diambil dari kolom `remaining_amount` yang dipelihara
 * `FinanceOutstandingRecalculator`, dan satu baris piutang dibentuk
 * `FinanceReceivableService::receivableRow()`. Jadi jawaban §11 dan daftar §12
 * ("jangan hanya 'Belum Lunas'") memakai sumber angka yang sama dengan layar
 * AR/penagihan: nomor invoice, nominal, jatuh tempo, sisa, status.
 */
class FinancialEligibilityService
{
    public const RESULT_ELIGIBLE = 'ELIGIBLE';

    public const RESULT_REQUIRES_APPROVAL = 'REQUIRES_APPROVAL';

    public const RESULT_NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    /** Aturan per aksi: izinkan, izinkan dengan persetujuan, atau tahan. */
    public const RULE_ALLOW = 'allow';

    public const RULE_APPROVE = 'approve';

    public const RULE_BLOCK = 'block';

    public const RULES = [self::RULE_ALLOW, self::RULE_APPROVE, self::RULE_BLOCK];

    public const RULE_LABELS = [
        self::RULE_ALLOW => 'Boleh',
        self::RULE_APPROVE => 'Butuh persetujuan',
        self::RULE_BLOCK => 'Ditahan',
    ];

    /** Dasar hitung tunggakan. */
    public const BASIS_OVERDUE = 'overdue';

    public const BASIS_OUTSTANDING = 'outstanding';

    /**
     * Aksi yang diketahui. Nama kunci inilah yang dipakai `eligibility_rules`,
     * jadi menambah aksi baru = menambah satu entri di sini + satu bawaan di
     * `RULE_DEFAULTS`, bukan percabangan di tempat lain.
     *
     * @var array<string, string>
     */
    public const ACTION_LABELS = [
        'exam' => 'Ujian',
        're_registration' => 'Daftar ulang',
        'report_card' => 'Rapor',
        'certificate' => 'Sertifikat / ijazah',
        'library_clearance' => 'Keberesan perpustakaan',
        'graduation_clearance' => 'Keberesan kelulusan',
    ];

    /**
     * Bawaan tiap aksi bila sekolah tidak mengatur apa pun.
     *
     * Pilihannya sengaja tidak seragam, karena risikonya berbeda: menahan rapor
     * atau ujian berarti menghukum anak atas kelalaian orang tuanya, jadi aksi
     * itu lahir sebagai "butuh persetujuan" — ada jejak keputusan, bukan larangan
     * otomatis. Menahan ijazah dan keberesan kelulusan adalah keputusan keuangan
     * penuh (uang keluar dari sekolah tetap tercatat), sedangkan keberesan
     * perpustakaan sudah punya aturannya sendiri di modul Library (denda buku),
     * sehingga Finance tidak menambah gerbang kedua di sana.
     *
     * @var array<string, string>
     */
    public const RULE_DEFAULTS = [
        'exam' => self::RULE_APPROVE,
        're_registration' => self::RULE_BLOCK,
        'report_card' => self::RULE_APPROVE,
        'certificate' => self::RULE_APPROVE,
        'library_clearance' => self::RULE_ALLOW,
        'graduation_clearance' => self::RULE_BLOCK,
    ];

    /**
     * Dasar hitung yang mengikuti gerbang daftar ulang Phase 2.
     *
     * `allow_re_registration_with_outstanding` sejak Phase 2 berarti "tunggakan
     * BERAPAPUN", bukan "yang sudah lewat jatuh tempo". Kalau aksi ini ikut
     * basis global, layar kelayakan dan proses generasi tagihan bisa memberi
     * jawaban berbeda untuk santri yang sama — jadi satu kunci, satu makna.
     *
     * @var array<string, string>
     */
    public const ACTION_BASIS = [
        're_registration' => self::BASIS_OUTSTANDING,
    ];

    /** Hak yang menahan/membuka keputusan "butuh persetujuan". */
    public const APPROVAL_PERMISSION = 'finance.eligibility.approve';

    public function __construct(
        private readonly FinanceSettingsService $settings,
        private readonly FinanceReceivableService $receivables,
    ) {}

    /**
     * Daftar aksi yang dikenal, sekaligus urutan tampilan layar kelayakan.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return array_keys(self::ACTION_LABELS);
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('eligibility_enabled', true);
    }

    /**
     * Aturan berlaku untuk satu aksi, sebagai hasil tumpukan: override sekolah →
     * kebijakan daftar ulang Phase 2 → bawaan kode.
     */
    public function ruleFor(string $action): string
    {
        $override = $this->overrides()[$action] ?? null;

        if ($override !== null) {
            return $override;
        }

        if ($action === 're_registration') {
            return $this->settings->get('allow_re_registration_with_outstanding', false)
                ? self::RULE_ALLOW
                : self::RULE_BLOCK;
        }

        return self::RULE_DEFAULTS[$action] ?? self::RULE_ALLOW;
    }

    /**
     * Dasar hitung untuk satu aksi (lihat `ACTION_BASIS`).
     */
    public function basisFor(string $action): string
    {
        return self::ACTION_BASIS[$action] ?? (string) $this->settings->get('eligibility_basis', self::BASIS_OVERDUE);
    }

    /**
     * Peta aturan efektif seluruh aksi + ambangnya — dipakai layar Settings dan
     * portal supaya label tidak perlu direplikasi di frontend.
     *
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $rules = [];

        foreach ($this->actions() as $action) {
            $rule = $this->ruleFor($action);

            $rules[$action] = [
                'action' => $action,
                'label' => self::ACTION_LABELS[$action],
                'rule' => $rule,
                'rule_label' => self::RULE_LABELS[$rule],
                'basis' => $this->basisFor($action),
                'default' => self::RULE_DEFAULTS[$action] ?? self::RULE_ALLOW,
                'overridden' => array_key_exists($action, $this->overrides()),
            ];
        }

        return [
            'enabled' => $this->enabled(),
            'basis' => (string) $this->settings->get('eligibility_basis', self::BASIS_OVERDUE),
            'grace_days' => $this->graceDays(),
            'min_outstanding' => $this->minOutstanding(),
            'approval_permission' => self::APPROVAL_PERMISSION,
            'actions' => $rules,
            'warnings' => $this->warnings(),
        ];
    }

    /**
     * Putusan satu aksi untuk satu santri.
     *
     * @return array<string, mixed>
     */
    public function check(Student|int $student, string $action, ?Carbon $asOf = null): array
    {
        $student = $this->resolveStudent($student);

        return $this->assess($student, $action, $this->moment($asOf), $this->openInvoices($student));
    }

    /**
     * Baris tagihan yang menghalangi sebuah aksi, beserta total dan ambangnya.
     *
     * Dirinci §11 dan dipakai ulang §12: keberesan finansial menanyakan angka
     * yang sama dengan gerbang kelayakan, jadi keduanya harus punya satu
     * penghasil baris — kalau tidak, "tidak ada tunggakan" di layar kelayakan
     * bisa berarti "masih ditahan" di layar keberesan.
     *
     * @return array<string, mixed>
     */
    public function blocking(Student|int $student, string $action, ?Carbon $asOf = null): array
    {
        $this->assertAction($action);
        $student = $this->resolveStudent($student);
        $now = $this->moment($asOf);
        $basis = $this->basisFor($action);

        return [
            'student_id' => (int) $student->id,
            'action' => $action,
            'as_of' => $now->toDateString(),
            'basis' => $basis,
            'grace_days' => $this->graceDays(),
            'min_outstanding' => $this->minOutstanding(),
            ...$this->figures($this->openInvoices($student), $basis, $now),
        ];
    }

    /**
     * Seluruh aksi sekaligus untuk satu santri (layar "status kelayakan").
     *
     * Baris piutang dibaca SATU kali untuk semua aksi: kalau tidak, membuka
     * layar itu berarti enam query AR yang menjawab pertanyaan yang sama.
     *
     * @return array<string, mixed>
     */
    public function checkAll(Student|int $student, ?Carbon $asOf = null): array
    {
        $student = $this->resolveStudent($student);
        $now = $this->moment($asOf);
        $invoices = $this->openInvoices($student);

        $results = [];

        foreach ($this->actions() as $action) {
            $results[$action] = $this->assess($student, $action, $now, $invoices);
        }

        return [
            'student_id' => (int) $student->id,
            'full_name' => $student->full_name,
            'nis' => $student->nis,
            'as_of' => $now->toDateString(),
            'configuration' => $this->configuration(),
            'results' => $results,
        ];
    }

    /**
     * Gerbang untuk modul pemanggil: bukan ELIGIBLE → permintaan gagal.
     *
     * Yang gagal adalah transaksi PEMANGGIL (mis. menyimpan nilai ujian), jadi
     * status akademik santri tidak pernah ditulis oleh Finance. Pesan error
     * menyebut jumlah tagihannya; rinciannya ada di `check()` yang sama.
     */
    public function assertEligible(Student|int $student, string $action, ?Carbon $asOf = null): array
    {
        $verdict = $this->check($student, $action, $asOf);

        if ($verdict['result'] !== self::RESULT_ELIGIBLE) {
            throw ValidationException::withMessages([
                'financial_eligibility' => $verdict['message'],
            ]);
        }

        return $verdict;
    }

    // ==================== Internal ====================

    private function assertAction(string $action): void
    {
        if (! array_key_exists($action, self::ACTION_LABELS)) {
            throw ValidationException::withMessages([
                'action' => 'Aksi kelayakan tidak dikenal: '.$action,
            ]);
        }
    }

    /** Masa tenggang global (§11); negatif tidak punya makna, jadi dipangkas ke nol. */
    public function graceDays(): int
    {
        return max(0, (int) $this->settings->get('eligibility_grace_days', 0));
    }

    public function minOutstanding(): float
    {
        return round((float) $this->settings->get('eligibility_min_outstanding', 0), 2);
    }

    /**
     * Terapkan aturan satu aksi atas baris piutang santri.
     *
     * @param  Collection<int, FinanceInvoice>  $invoices
     * @return array<string, mixed>
     */
    private function assess(Student $student, string $action, Carbon $now, Collection $invoices): array
    {
        $this->assertAction($action);

        $enabled = $this->enabled();
        $rule = $this->ruleFor($action);
        $basis = $this->basisFor($action);
        $graceDays = $this->graceDays();
        $minimum = $this->minOutstanding();

        $figures = $this->figures($invoices, $basis, $now);
        $lines = $figures['lines'];
        $blockingTotal = $figures['total'];
        $material = $figures['material'];

        $result = match (true) {
            ! $enabled, $rule === self::RULE_ALLOW, ! $material => self::RESULT_ELIGIBLE,
            $rule === self::RULE_BLOCK => self::RESULT_NOT_ELIGIBLE,
            default => self::RESULT_REQUIRES_APPROVAL,
        };

        return [
            'student_id' => (int) $student->id,
            'full_name' => $student->full_name,
            'nis' => $student->nis,
            'action' => $action,
            'action_label' => self::ACTION_LABELS[$action],
            'result' => $result,
            'rule' => $rule,
            'rule_label' => self::RULE_LABELS[$rule],
            'basis' => $basis,
            'as_of' => $now->toDateString(),
            'enabled' => $enabled,
            'thresholds' => [
                'grace_days' => $graceDays,
                'min_outstanding' => $minimum,
                'material' => $material,
            ],
            'blocking_count' => count($lines),
            'blocking_total' => $blockingTotal,
            'outstanding' => $figures['outstanding'],
            'blocking' => $lines,
            'approval_permission' => $result === self::RESULT_REQUIRES_APPROVAL ? self::APPROVAL_PERMISSION : null,
            'message' => $this->message($action, $result, $blockingTotal, count($lines)),
        ];
    }

    /**
     * Invoice outstanding santri: query yang sama dengan layar AR, jadi angka
     * kelayakan dan angka piutang tidak mungkin bersumber berbeda. Baris item
     * ikut dimuat supaya keterangan tersedia tanpa query tambahan.
     *
     * @return Collection<int, FinanceInvoice>
     */
    private function openInvoices(Student $student): Collection
    {
        return $this->receivables
            ->query(['student_id' => (int) $student->id])
            ->with('items:invoice_id,description')
            ->orderBy('due_date')
            ->orderBy('finance_invoices.id')
            ->get();
    }

    /**
     * Titik waktu pemeriksaan, selalu awal hari: "lewat jatuh tempo" tidak boleh
     * bergantung pada jam berapa layar dibuka.
     */
    private function moment(?Carbon $asOf): Carbon
    {
        return ($asOf ?? Carbon::now())->copy()->startOfDay();
    }

    /**
     * Angka pemeriksaan atas sekumpulan invoice santri: baris penghalang,
     * totalnya, apakah total itu material, dan ringkasan piutangnya.
     *
     * Satu fungsi ini dipakai §11 (putusan per aksi) dan §12 (baris "Show
     * Outstanding" pada keberesan). Ambangnya dibaca dari Settings di sini
     * supaya tidak ada pemanggil yang bisa lupa memakainya.
     *
     * @param  Collection<int, FinanceInvoice>  $invoices
     * @return array<string, mixed>
     */
    private function figures(Collection $invoices, string $basis, Carbon $now): array
    {
        $minimum = $this->minOutstanding();
        $lines = $this->blockingLines($invoices, $basis, $this->graceDays(), $now);
        $total = round(array_sum(array_map(
            fn (array $line): float => (float) $line['remaining_amount'],
            $lines,
        )), 2);

        // Di bawah ambang minimum, tunggakan dianggap tidak material: layanan
        // tidak berhenti hanya karena sisa receh. Barisnya tetap ikut dilaporkan
        // supaya "kenapa masih ada selisih" tidak menjadi pertanyaan terbuka.
        return [
            'lines' => $lines,
            'total' => $total,
            'material' => $total > 0.0001 && ($minimum <= 0.0001 || $total >= $minimum),
            'outstanding' => $this->receivables->summarize($invoices, $now),
        ];
    }

    /**
     * Baris piutang yang menghalangi sebuah aksi, dibentuk sebagai baris AR:
     * nomor invoice, keterangan, nominal, jatuh tempo, sisa, status (§12).
     *
     * @param  Collection<int, FinanceInvoice>  $invoices
     * @return list<array<string, mixed>>
     */
    private function blockingLines(Collection $invoices, string $basis, int $graceDays, Carbon $asOf): array
    {
        $lines = [];

        foreach ($invoices as $invoice) {
            $row = $this->receivables->receivableRow($invoice, $asOf);

            if ($row['remaining_amount'] <= 0.0001) {
                continue;
            }

            // Masa tenggang hanya berlaku pada basis `overdue`: ia menggeser
            // garis "berapa hari lewat jatuh tempo masih ditoleransi". Pada
            // basis `outstanding` yang dihitung adalah sisa tagihannya, bukan
            // keterlambatannya, jadi tagihan yang belum jatuh tempo tetap ikut.
            if ($basis === self::BASIS_OVERDUE && $row['days_overdue'] <= $graceDays) {
                continue;
            }

            $row['description'] = $this->describe($invoice);
            $lines[] = $row;
        }

        return $lines;
    }

    /**
     * Keterangan singkat baris: nama jenis tagihan, bukan hanya nomor invoice.
     */
    private function describe(FinanceInvoice $invoice): string
    {
        $descriptions = $invoice->items
            ->pluck('description')
            ->filter()
            ->values()
            ->all();

        if ($descriptions === []) {
            return (string) $invoice->invoice_number;
        }

        return count($descriptions) > 1
            ? $descriptions[0].' (+'.(count($descriptions) - 1).' baris lain)'
            : (string) $descriptions[0];
    }

    /**
     * Override sekolah atas aturan per aksi.
     *
     * Dibaca longgar: entri yang aksinya tidak dikenal atau nilainya bukan
     * `allow|approve|block` diabaikan saat dipakai, tetapi dilaporkan lewat
     * `warnings()` supaya salah ketik di Settings tidak menjadi perbedaan
     * antara "saya mengira ujian ditahan" dan kenyataannya tidak.
     *
     * @return array<string, string>
     */
    private function overrides(): array
    {
        $raw = trim((string) $this->settings->get('eligibility_rules', ''));

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $overrides = [];

        foreach ($decoded as $action => $rule) {
            if (is_string($action) && is_string($rule) && in_array($rule, self::RULES, true)) {
                $overrides[$action] = $rule;
            }
        }

        return $overrides;
    }

    /**
     * Entri override yang tidak dipakai, dengan alasannya.
     *
     * @return list<array<string, string>>
     */
    private function warnings(): array
    {
        $raw = trim((string) $this->settings->get('eligibility_rules', ''));

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [['value' => $raw, 'reason' => 'Bukan JSON object sehingga seluruh override diabaikan.']];
        }

        $known = $this->overrides();
        $warnings = [];

        foreach ($decoded as $action => $rule) {
            // Entri hanya "dipakai" bila aksinya dikenal DAN nilainya sah. Tanpa
            // syarat pertama, `{"upacara":"allow"}` hilang tanpa jejak: nilainya
            // sah sehingga lolos `overrides()`, padahal aksinya tidak ada.
            if (array_key_exists((string) $action, self::ACTION_LABELS) && isset($known[$action])) {
                continue;
            }

            $reason = ! array_key_exists((string) $action, self::ACTION_LABELS)
                ? 'Aksi tidak dikenal.'
                : 'Nilai aturan harus salah satu dari: '.implode(', ', self::RULES).'.';

            $warnings[] = ['action' => (string) $action, 'value' => (string) json_encode($rule), 'reason' => $reason];
        }

        return $warnings;
    }

    private function message(string $action, string $result, float $total, int $count): string
    {
        $label = self::ACTION_LABELS[$action];

        if ($result === self::RESULT_ELIGIBLE) {
            return 'Tidak ada tunggakan yang menghalangi '.$label.'.';
        }

        $symbol = (string) $this->settings->get('currency_symbol', 'Rp');
        $detail = $count.' tagihan sebesar '.$symbol.' '.number_format($total, 0, ',', '.').' belum selesai';

        return $result === self::RESULT_NOT_ELIGIBLE
            ? $label.' ditahan: '.$detail.'.'
            : $label.' butuh persetujuan: '.$detail.'.';
    }

    private function resolveStudent(Student|int $student): Student
    {
        if ($student instanceof Student) {
            return $student;
        }

        $found = Student::query()->whereKey($student)->first(['id', 'full_name', 'nis']);

        if ($found === null) {
            throw ValidationException::withMessages([
                'student_id' => 'Santri tidak ditemukan.',
            ]);
        }

        return $found;
    }
}
