<?php

namespace App\Services\Finance;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Konfigurasi modul finance, disimpan di tabel `settings` yang sudah ada
 * (group `finance`, kunci berprefix `finance.`) — bukan tabel konfigurasi baru
 * dan bukan .env, karena kebijakan ini diubah bendahara/manajer keuangan lewat
 * halaman Settings (§73).
 *
 * Kunci yang dikenal dibatasi daftar DEFAULTS; kunci asing ditolak supaya salah
 * ketik di UI tidak menciptakan setting hantu yang tidak pernah dibaca kode.
 */
class FinanceSettingsService
{
    public const GROUP = 'finance';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_STRING = 'string';

    /** Basis pengakuan pendapatan (§7). */
    public const BASIS_ACCRUAL = 'accrual';

    public const BASIS_CASH = 'cash';

    public const BASES = [self::BASIS_ACCRUAL, self::BASIS_CASH];

    /** @var array<string, array{0: string, 1: mixed, 2: string}> */
    public const DEFAULTS = [
        'currency' => [self::TYPE_STRING, 'IDR', 'Kode mata uang pembukuan'],
        'currency_symbol' => [self::TYPE_STRING, 'Rp', 'Simbol mata uang untuk tampilan'],
        // Default fiscal year dipakai saat membuat periode/budget baru.
        'fiscal_year' => [self::TYPE_INTEGER, 2026, 'Tahun fiskal berjalan'],
        'fiscal_start_month' => [self::TYPE_INTEGER, 7, 'Bulan awal tahun fiskal (1-12); 7 = Juli (tahun ajaran)'],
        'container_unit' => [self::TYPE_STRING, '', 'Unit penampung untuk baris NERACA: seluruh liabilitas & rekening kas/bank selalu memakai unit ini, karena neraca hanya bermakna di tingkat yayasan (kosongkan = perilaku lama)'],
        'require_cost_center_for_expense' => [self::TYPE_BOOLEAN, false, 'Wajibkan pusat biaya pada setiap baris akun beban (menggerakkan realisasi anggaran)'],
        'cost_center_exempt_modules' => [self::TYPE_STRING, 'TutupBuku,Depresiasi,SaldoAwal', 'Modul yang dikecualikan dari kewajiban pusat biaya, dipisahkan koma'],
        'fund_control' => [self::TYPE_STRING, 'enforce', 'Kebijakan peruntukan dana: enforce|warning|off'],
        // Ambang approval pengeluaran bertingkat (§32). 0 = semua satu langkah.
        'expense_approval_threshold' => [self::TYPE_INTEGER, 5000000, 'Nominal di atasnya pengeluaran butuh approval manajer'],
        'expense_approval_steps' => [self::TYPE_INTEGER, 1, 'Jumlah langkah persetujuan pengeluaran (1 atau 2)'],
        // Matriks approval transfer (§16): nominal menentukan berapa penyetuju,
        // bukan hanya siapa. Di bawah ambang ini satu penyetuju (manager) cukup;
        // di atasnya transfer menuntut penyetuju KEDUA yang orangnya berbeda,
        // dan uang baru boleh berpindah setelah keduanya tercatat.
        'transfer_approval_threshold' => [self::TYPE_INTEGER, 10000000, 'Nominal transfer di atasnya butuh persetujuan kedua (penyetuju berbeda)'],
        // Budget control (§10 Phase 4): apa yang terjadi bila pengajuan pengeluaran
        // melebihi sisa anggaran. none = hanya dilaporkan; warning = jalan terus
        // dengan catatan; block = ditolak saat diajukan; allow_with_approval =
        // jalan tetapi persetujuan terakhir harus pemegang hak budget.approve.
        // Anggaran tidak pernah dikurangi karena pengajuan — angka yang bergerak
        // hanya commitment (§9), realisasi tetap datang dari jurnal posted.
        'budget_control' => [self::TYPE_STRING, 'none', 'Kebijakan saat pengajuan pengeluaran melebihi anggaran: none|warning|block|allow_with_approval'],
        // Penagihan (§6 Phase 4): umur tagihan yang menaikkan tangga workflow.
        // Due Soon dan eskalasi adalah kebijakan bendahara — sebuah sekolah bisa
        // menganggap telat 3 hari sudah darurat, yang lain baru bergerak setelah 60.
        'collection_due_soon_days' => [self::TYPE_INTEGER, 7, 'Hari sebelum jatuh tempo saat sebuah tagihan mulai dianggap "due soon"'],
        'collection_escalation_days' => [self::TYPE_INTEGER, 30, 'Umur tunggakan (hari) yang menaikkan tagihan ke tangga eskalasi'],
        // Tangga pengingat otomatis (§7 Phase 5). Bilangan anak tangganya milik
        // bendahara, bukan kode: H-7/H-3/H-1/H+1/H+7 hanyalah default, dan sekolah
        // yang menagih per semester jelas butuh tangga berbeda. Negatif = hari
        // sebelum jatuh tempo, positif = sesudahnya. Satu anak tangga dilewati
        // sekali per tagihan — pengingat tidak diulang setiap hari.
        'invoice_reminders_enabled' => [self::TYPE_BOOLEAN, true, 'Kirim pengingat jatuh tempo otomatis ke santri dan wali'],
        'invoice_reminder_offsets' => [self::TYPE_STRING, '-7,-3,-1,1,7', 'Anak tangga pengingat dalam hari sebelum (-) dan sesudah (+) jatuh tempo, dipisahkan koma'],
        // Kanal & kalimat surat (§8 Phase 5). Surat selalu masuk kotak masuk
        // in-app; email adalah salinan yang harus dibuka sendiri oleh sekolah,
        // karena surat yang dikirim lewat mailer bawaan (`log`) tidak pernah
        // sampai ke siapa pun tapi tetap terhitung "terkirim" di log.
        'notification_email_enabled' => [self::TYPE_BOOLEAN, false, 'Salin surat keuangan keluarga (tagihan, pembayaran, daftar ulang, refund, angsuran) ke email wali; butuh MAIL_MAILER yang benar-benar mengirim'],
        // Kalimat notifikasi tinggal di FinanceNotificationTemplate; kunci ini
        // hanya berisi penggantiannya, per kunci peristiwa. Dibiarkan apa adanya
        // (bukan divalidasi token per token) supaya sekolah bisa menambah
        // sapaan sendiri; template yang rusak seluruhnya diabaikan saat dibaca.
        'notification_templates' => [self::TYPE_STRING, '', 'Timpa kalimat notifikasi per peristiwa, JSON: {"invoice_issued": {"title": "...", "body": "... {invoice_number} ..."}}'],
        // Pembagian AR §6 (SPP / daftar ulang / boarding) menurut kode akun COA,
        // bukan menurut nama jenis tagihan: akun pendapatan sudah menjadi sumber
        // kebenaran pemetaan sejak Phase 3, jadi kelompok penagihan mengikuti
        // buku besar, bukan ikut daftar jenis tagihan.
        'collection_spp_account_codes' => [self::TYPE_STRING, '4100', 'Kode akun pendapatan yang dihitung sebagai AR SPP (pisahkan dengan koma)'],
        'collection_re_registration_account_codes' => [self::TYPE_STRING, '4300', 'Kode akun pendapatan yang dihitung sebagai AR daftar ulang (pisahkan dengan koma)'],
        'collection_boarding_account_codes' => [self::TYPE_STRING, '4200', 'Kode akun pendapatan yang dihitung sebagai AR boarding (pisahkan dengan koma)'],
        // Rencana pembayaran / cicilan (§7 Phase 4). "Jika diizinkan konfigurasi"
        // dibaca harfiah: fiturnya mati sampai bendahara mengaktifkannya, dan
        // berapa jauh sebuah tagihan boleh dipecah juga keputusan bendahara —
        // sebuah plan panjang memperbesar risiko angsuran terakhir tidak datang.
        'payment_plan_enabled' => [self::TYPE_BOOLEAN, false, 'Aktifkan rencana pembayaran (cicilan) untuk invoice'],
        'payment_plan_max_installments' => [self::TYPE_INTEGER, 12, 'Jumlah angsuran terbanyak yang boleh dibuat dalam satu rencana pembayaran'],
        'payment_plan_min_amount' => [self::TYPE_INTEGER, 0, 'Nilai tagihan terendah yang boleh dicicil (0 = tanpa batas)'],
        // Denda keterlambatan SPP (§16) — dihitung billing engine bila aktif.
        'late_fee_enabled' => [self::TYPE_BOOLEAN, false, 'Aktifkan denda otomatis untuk tagihan lewat jatuh tempo'],
        'late_fee_type' => [self::TYPE_STRING, 'percentage', 'Jenis denda: percentage atau fixed'],
        'late_fee_value' => [self::TYPE_STRING, '0', 'Nilai denda (% dari sisa atau nominal tetap)'],
        'late_fee_grace_days' => [self::TYPE_INTEGER, 0, 'Masa tenggang (hari) sebelum denda dihitung'],
        // Otorisasi payment manual: wajib verifikasi sebelum saldo berubah (§24).
        'payment_require_verification' => [self::TYPE_BOOLEAN, true, 'Payment manual wajib diverifikasi sebelum confirmed'],
        // Auto-posting journal untuk tiap transaksi kas/bank (§47 double-entry).
        // Sengaja TIDAK dibaca oleh posting path: buku besar yang tidak lengkap
        // lebih berbahaya daripada berguna, dan §41/§51 menuntut setiap transaksi
        // confirmed menghasilkan jurnal. Kunci ini ditampilkan sebagai informasi
        // bahwa auto-posting tidak bisa dimatikan.
        'auto_post_journal' => [self::TYPE_BOOLEAN, true, 'Otomatis posting jurnal double-entry saat transaksi dikonfirmasi'],
        // ---- Kebijakan akuntansi (Phase 3 §7, §20, §33) ------------------
        // accrual: pendapatan diakui saat invoice terbit (Dr Piutang / Cr Pendapatan);
        // cash: pendapatan diakui saat uang masuk. Satu-satunya tempat kode akun
        // pemetaan berada — service tidak boleh men-hard-code '1300' dsb.
        'accounting_basis' => [self::TYPE_STRING, 'accrual', 'Basis pengakuan pendapatan: accrual atau cash'],
        'account_receivable_code' => [self::TYPE_STRING, '1300', 'Kode COA akun piutang (AR) default'],
        'account_deposit_code' => [self::TYPE_STRING, '2200', 'Kode COA akun titipan/deposit santri (kelebihan bayar)'],
        'account_payable_code' => [self::TYPE_STRING, '2100', 'Kode COA akun hutang (AP) vendor'],
        'account_gateway_clearing_code' => [self::TYPE_STRING, '1250', 'Kode COA akun dana gateway dalam penyelesaian sebelum masuk bank'],
        'account_write_off_code' => [self::TYPE_STRING, '5700', 'Kode COA beban penghapusan piutang'],
        'account_opening_equity_code' => [self::TYPE_STRING, '3100', 'Kode COA ekuitas saldo awal'],
        'account_revenue_fallback_code' => [self::TYPE_STRING, '4500', 'Kode COA pendapatan untuk jenis tagihan tanpa pemetaan'],
        'gateway_use_clearing_account' => [self::TYPE_BOOLEAN, false, 'Dana gateway/VA dicatat ke akun clearing lebih dulu sebelum masuk bank'],
        // Batas tunggu sebuah charge (§7 Phase 5). Provider biasanya mengirim
        // kedaluwarsanya sendiri; kunci ini dipakai bila ia tidak mengirim, dan
        // menjadi dasar scheduler menutup `pending` yang kanalnya sudah mati —
        // tanpa itu tagihan terbaca "sedang dibayar" selamanya.
        // Pembayaran online oleh orang tua dari aplikasi mobile/portal. Mati secara
        // bawaan: sekolah harus memilih rekening bank tujuan (akun COA yang menerima
        // dana gateway) sebelum tombol "Bayar" berfungsi.
        'portal_payment_enabled' => [self::TYPE_BOOLEAN, false, 'Izinkan orang tua membayar tagihan anak lewat payment gateway dari aplikasi'],
        'portal_payment_bank_account_id' => [self::TYPE_INTEGER, 0, 'ID rekening bank sekolah (finance_bank_accounts) tujuan pembayaran gateway orang tua; 0 = belum dipilih'],
        'gateway_payment_ttl_hours' => [self::TYPE_INTEGER, 24, 'Jam sebuah pembayaran gateway/VA menunggu sebelum dianggap kedaluwarsa bila provider tidak mengirim batas waktunya'],
        // Kebijakan kelebihan bayar (§19 Phase 2) — jangan diam-diam negatifkan invoice.
        'overpayment_policy' => [self::TYPE_STRING, 'reject', 'Kebijakan pembayaran melebihi sisa tagihan: reject|credit_balance|unallocated|refund'],
        // Pencocokan rekening koran (Phase 4 §4): seberapa jauh tanggal buku besar
        // boleh meleset dari tanggal bank sebelum baris dianggap "cocok karena
        // nominal + rentang tanggal". Toleransi milik bendahara — bank berbeda
        // punya jadwal posting berbeda (cut-off sore, nilai tukar, clearing).
        'reconciliation_date_tolerance_days' => [self::TYPE_INTEGER, 3, 'Toleransi selisih tanggal (hari) saat mencocokkan rekening koran bank'],
        // Daftar Ulang (§4 Phase 2). 0 = pakai academic_years.is_active / tahun berikutnya otomatis.
        'allow_re_registration_with_outstanding' => [self::TYPE_BOOLEAN, false, 'Izinkan daftar ulang meski masih punya tunggakan'],
        're_registration_current_academic_year_id' => [self::TYPE_INTEGER, 0, 'Tahun ajaran berjalan untuk daftar ulang (0 = auto: yang is_active)'],
        're_registration_next_academic_year_id' => [self::TYPE_INTEGER, 0, 'Tahun ajaran tujuan daftar ulang (0 = auto: sesudah berjalan)'],
        // Generate SPP terjadwal (§7 Phase 5). Fitur ini menerbitkan dokumen uang
        // tanpa tangan manusia, jadi matinya default — bendahara yang mengaktifkan
        // dan memilih paket mana yang menjadi "SPP". Siklus menentukan lebar
        // periode yang ditagih sekaligus menjadi kunci periode billing, sehingga
        // scheduler yang jalan berulang tetap idempoten: satu periode = satu
        // tagihan per santri, dan jendela berikutnya yang membuka tagihan baru.
        'spp_auto_generate_enabled' => [self::TYPE_BOOLEAN, false, 'Terbitkan tagihan SPP otomatis sesuai siklus yang dipilih'],
        'spp_auto_generate_fee_package_id' => [self::TYPE_INTEGER, 0, 'Paket tagihan yang diterbitkan otomatis (0 = belum diatur, scheduler menolak jalan)'],
        'spp_auto_generate_cycle' => [self::TYPE_STRING, 'monthly', 'Lebar periode SPP otomatis: monthly|quarterly|semester|academic_year'],
        // Jatuh tempo dihitung dari awal periode, bukan tanggal tetap: jendela
        // semester/tahun ajaran tidak punya "tanggal 10" yang bermakna.
        'spp_due_after_days' => [self::TYPE_INTEGER, 10, 'Hari sesudah awal periode saat SPP otomatis jatuh tempo'],
        // Invoice adalah dokumen yang menggerakkan pengakuan pendapatan (accrual)
        // dan membuat tagihan bisa dibayar. Bila dimatikan, scheduler hanya
        // menyusun billing dan penerbitan invoice tetap keputusan petugas.
        'spp_auto_issue_invoice' => [self::TYPE_BOOLEAN, true, 'Terbitkan invoice otomatis beserta billing SPP hasil jadwal'],
        // Kelayakan finansial (§11 Phase 5): aturan "boleh ikut ujian / ambil
        // rapor meski ada tunggakan" adalah kebijakan sekolah, bukan kode. Kuncinya
        // satu peta JSON per aksi (lihat FinancialEligibilityService::RULES),
        // dengan tiga ambang yang membatasinya:
        //  - basis: sekolah yang menahan layanan hanya untuk tagihan LEWAT jatuh
        //    tempo berbeda dari sekolah yang menahan untuk semua sisa tagihan;
        //  - grace: tiga hari telat bayar biasanya administrasi, bukan pelanggaran;
        //  - minimum: tunggakan Rp 5.000 tidak layak menghentikan ujian.
        // Daftar ulang TIDAK punya kunci baru di sini: `allow_re_registration_with_outstanding`
        // sudah menjadi knob-nya sejak Phase 2, dan dua kunci untuk satu keputusan
        // hanya menghasilkan konfigurasi yang saling bertentangan.
        'eligibility_enabled' => [self::TYPE_BOOLEAN, true, 'Aktifkan pemeriksaan kelayakan finansial; bila mati semua aksi dianggap layak'],
        'eligibility_basis' => [self::TYPE_STRING, 'overdue', 'Tunggakan yang dihitung: hanya yang lewat jatuh tempo (overdue) atau semua sisa tagihan berjalan (outstanding)'],
        'eligibility_grace_days' => [self::TYPE_INTEGER, 0, 'Masa tenggang (hari) sesudah jatuh tempo yang masih dianggap layak'],
        'eligibility_min_outstanding' => [self::TYPE_INTEGER, 0, 'Nilai tunggakan terendah yang boleh menghentikan sebuah aksi (0 = tunggakan berapapun menghentikan)'],
        // Peta aturan per aksi: {"exam":"allow","certificate":"block"}. Kosong =
        // pakai bawaan tiap aksi. Sama polanya dengan `notification_templates`:
        // satu kunci, rusak JSON = kembali ke bawaan, bukan error 500.
        'eligibility_rules' => [self::TYPE_STRING, '', 'Timpa aturan kelayakan per aksi, JSON: {"exam":"allow|approve|block","report_card":"...","certificate":"...","library_clearance":"...","graduation_clearance":"...","re_registration":"..."}'],
        // Keberesan finansial (§12 Phase 5). Daftar periksanya sendiri tetap
        // membaca dokumen yang ada — tagihan, piutang, pembayaran tertunda,
        // refund, deposit — dan sisa tagihan tidak punya kunci baru di sini
        // karena ambang, basis, dan tingkat ketahanannya sudah menjadi milik
        // §11. Yang boleh dipilih sekolah adalah dua hal "masih di tengah
        // jalan" yang ikut menahan keberesan atau tidak:
        //  - pembayaran pending: uangnya belum masuk kas dan belum ber-jurnal,
        //    jadi meloloskan keberesan di atasnya berarti meloloskan angka yang
        //    masih bisa hilang;
        //  - refund terbuka: uang keluar yang belum selesai — untuk santri yang
        //    benar-benar pergi, haknya harus tuntas lebih dulu.
        // Deposit (credit balance) tidak pernah menahan: itu uang sekolah yang
        // masih menjadi hak santri, dan ia dilaporkan supaya diselesaikan,
        // bukan supaya jadi alasan penolakan.
        'clearance_block_on_pending_payment' => [self::TYPE_BOOLEAN, true, 'Tahan keberesan finansial selama masih ada pembayaran yang menunggu verifikasi'],
        'clearance_block_on_open_refund' => [self::TYPE_BOOLEAN, true, 'Tahan keberesan finansial selama masih ada pengajuan refund yang belum selesai'],
    ];

    /** Opsi terbatas untuk kunci tertentu => list nilai yang boleh dipakai. */
    public const ALLOWED_VALUES = [
        'expense_approval_steps' => [1, 2],
        'late_fee_type' => ['percentage', 'fixed'],
        'overpayment_policy' => ['reject', 'credit_balance', 'unallocated', 'refund'],
        'accounting_basis' => self::BASES,
        'budget_control' => ['none', 'warning', 'block', 'allow_with_approval'],
        'spp_auto_generate_cycle' => ['monthly', 'quarterly', 'semester', 'academic_year'],
        'eligibility_basis' => ['overdue', 'outstanding'],
    ];

    /**
     * Seluruh nilai efektif: default ditimpa baris `settings` yang ada.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = Setting::query()
            ->where('group', self::GROUP)
            ->whereIn('key', array_map(
                fn (string $key): string => $this->columnKey($key),
                array_keys(self::DEFAULTS),
            ))
            ->pluck('value', 'key')
            ->all();

        $values = [];
        foreach (self::DEFAULTS as $key => [$type, $default]) {
            $column = $this->columnKey($key);

            $values[$key] = array_key_exists($column, $stored)
                ? $this->cast($type, $stored[$column], $default)
                : $default;
        }

        return $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, self::DEFAULTS)
            ? ($this->all()[$key] ?? $default)
            : $default;
    }

    /**
     * Simpan sebagian nilai. Kunci tak dikenal dan nilai di luar opsi ditolak.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(array $values, bool $replace = false): array
    {
        $unknown = array_diff(array_keys($values), array_keys(self::DEFAULTS));

        if ($unknown !== []) {
            throw new \InvalidArgumentException('Pengaturan tidak dikenal: '.implode(', ', $unknown));
        }

        foreach ($values as $key => $value) {
            $this->assertAllowed($key, $value);
        }

        return DB::transaction(function () use ($values, $replace): array {
            foreach ($values as $key => $value) {
                [$type] = self::DEFAULTS[$key];

                Setting::query()->updateOrCreate(
                    ['key' => $this->columnKey($key), 'group' => self::GROUP],
                    ['value' => $this->serialize($type, $value), 'type' => $type],
                );
            }

            return $replace ? $this->all() : array_intersect_key($this->all(), $values);
        });
    }

    /** Baris disimpan dengan prefix modul agar tidak menabrak kunci modul lain. */
    protected function columnKey(string $key): string
    {
        return 'finance.'.$key;
    }

    protected function assertAllowed(string $key, mixed $value): void
    {
        $allowed = self::ALLOWED_VALUES[$key] ?? null;

        if ($allowed === null) {
            return;
        }

        $normalized = is_numeric($value) ? (int) $value : (string) $value;

        if (! in_array($normalized, $allowed, true)) {
            throw new \InvalidArgumentException(
                $key.' hanya menerima salah satu dari: '.implode(', ', array_map('strval', $allowed)),
            );
        }
    }

    protected function serialize(string $type, mixed $value): string
    {
        return match ($type) {
            self::TYPE_BOOLEAN => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    protected function cast(string $type, mixed $value, mixed $default): mixed
    {
        return match ($type) {
            self::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_INTEGER => (int) $value,
            default => (string) $value,
        };
    }
}
