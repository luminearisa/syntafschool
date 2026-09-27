<?php

namespace App\Services\Finance;

/**
 * Kalimat surat Keuangan (§8 Phase 5): satu tempat untuk seluruh teks
 * notifikasi, supaya format pesannya bisa dibaca, dibandingkan, dan diubah
 * sekolah tanpa menyentuh kode.
 *
 * `FinanceNotificationService` tahu PERISTIWA (invoice terbit, pembayaran
 * gagal, angsuran lewat tempo) dan angkanya; kelas ini tahu kalimatnya. Tiap
 * peristiwa punya kunci template berisi `title` + `body` dengan token `{...}`
 * yang diisi dari konteks peristiwa:
 *
 *   invoice_issued → "Invoice {invoice_number} sebesar {amount_text} terbit."
 *
 * Sekolah boleh menimpa kalimatnya lewat Settings → Keuangan
 * (`notification_templates`, JSON `{"kunci": {"title": "...", "body": "..."}}`)
 * — misalnya untuk menambahkan sapaan wali `{student_name}`. Token yang tidak
 * dikenal dibiarkan apa adanya: surat yang masih membaca `{nomor_tagihan}` lebih
 * mudah dilacak dan diperbaiki daripada kalimat yang kehilangan angkanya
 * diam-diam.
 *
 * WhatsApp disebut §8 sebagai kanal "jika provider tersedia". Di aplikasi ini
 * tidak ada satu pun penyedia WhatsApp yang terkonfigurasi — tidak ada gateway,
 * tidak ada kredensial, tidak ada nomor tujuan yang diverifikasi — jadi kanal
 * itu tidak ditawarkan. Perlakuannya sama dengan pengumuman PPDB: kanal yang
 * tidak bisa berangkat tidak boleh muncul sebagai pilihan di layar.
 */
class FinanceNotificationTemplate
{
    /**
     * Kunci template → kalimat bawaan. Isinya kalimat yang sudah dipakai modul
     * ini sejak Phase 2 — memindahkannya ke sini tidak mengubah satu pun huruf
     * — ditambah surat §8 yang memang lahir pada fase ini (angsuran & refund).
     *
     * @var array<string, array{title: string, body: string}>
     */
    public const DEFAULTS = [
        'invoice_issued' => [
            'title' => 'Tagihan baru diterbitkan',
            'body' => 'Invoice {invoice_number} sebesar {amount_text} terbit. Jatuh tempo {due_date}.',
        ],
        'payment_received' => [
            'title' => 'Pembayaran diterima',
            'body' => 'Pembayaran {payment_number} sebesar {amount_text}{receipt_text} diterima.',
        ],
        'payment_pending_verification' => [
            'title' => 'Pembayaran menunggu verifikasi',
            'body' => 'Pembayaran {payment_number} sebesar {amount_text} menunggu verifikasi.',
        ],
        'payment_rejected' => [
            'title' => 'Pembayaran ditolak',
            'body' => 'Pembayaran {payment_number} ditolak. Alasan: {reason}',
        ],
        'payment_failed' => [
            'title' => 'Pembayaran gagal',
            'body' => 'Pembayaran {payment_number} sebesar {amount_text} gagal diproses kanal pembayaran. Tagihannya masih terbuka dan dapat dibayar ulang.',
        ],
        'payment_cancelled' => [
            'title' => 'Pembayaran dibatalkan',
            'body' => 'Pembayaran {payment_number} dibatalkan. Alasan: {reason}',
        ],
        'payment_expired' => [
            'title' => 'Batas waktu pembayaran lewat',
            'body' => 'Pembayaran {payment_number} sebesar {amount_text} kedaluwarsa sebelum dikonfirmasi. Tagihan masih terbuka.',
        ],
        'payment_late_capture' => [
            'title' => 'Dana gateway masuk setelah pembayaran ditutup',
            'body' => 'Pembayaran {payment_number} sebesar {amount_text} dilaporkan lunas oleh provider padahal statusnya [{status_label}]. Dananya belum menyentuh buku besar — rekonsiliasi atau kembalikan lewat refund resmi.',
        ],
        'billing_overdue' => [
            'title' => 'Tagihan lewat jatuh tempo',
            'body' => 'Invoice {invoice_number} sebesar {amount_text} belum lunas dan telah lewat jatuh tempo ({due_date}).',
        ],
        'collection_reminder_before' => [
            'title' => 'Pengingat jatuh tempo tagihan',
            'body' => 'Invoice {invoice_number} sebesar {amount_text} jatuh tempo {days} hari lagi ({due_date}).',
        ],
        'collection_reminder_today' => [
            'title' => 'Pengingat jatuh tempo tagihan',
            'body' => 'Invoice {invoice_number} sebesar {amount_text} jatuh tempo hari ini ({due_date}).',
        ],
        'collection_reminder_after' => [
            'title' => 'Tagihan belum terlunasi',
            'body' => 'Invoice {invoice_number} sebesar {amount_text} sudah {days} hari lewat jatuh tempo ({due_date}). Silakan selesaikan melalui kasir atau portal pembayaran.',
        ],
        'installment_due_before' => [
            'title' => 'Pengingat jatuh tempo angsuran',
            'body' => 'Angsuran {installment_sequence} dari {plan_number} sebesar {amount_text} jatuh tempo {days} hari lagi ({due_date}).',
        ],
        'installment_due_today' => [
            'title' => 'Pengingat jatuh tempo angsuran',
            'body' => 'Angsuran {installment_sequence} dari {plan_number} sebesar {amount_text} jatuh tempo hari ini ({due_date}).',
        ],
        'installment_overdue' => [
            'title' => 'Angsuran lewat jatuh tempo',
            'body' => 'Angsuran {installment_sequence} dari {plan_number} sebesar {amount_text} sudah {days} hari lewat jatuh tempo ({due_date}). Silakan selesaikan sebelum angsuran berikutnya berjalan.',
        ],
        're_registration_billed' => [
            'title' => 'Tagihan daftar ulang terbit',
            'body' => 'Daftar ulang {registration_number} ({academic_year}) ditagih sebesar {amount_text}. Jatuh tempo {due_date}.',
        ],
        're_registration_completed' => [
            'title' => 'Daftar ulang selesai',
            'body' => 'Daftar ulang {registration_number} untuk tahun ajaran {academic_year} selesai. Santri aktif pada tahun ajaran baru.',
        ],
        're_registration_expired' => [
            'title' => 'Daftar ulang kedaluwarsa',
            'body' => 'Daftar ulang {registration_number} melewati batas {due_date} dan belum lunas. Silakan hubungi bagian keuangan.',
        ],
        'refund_approved' => [
            'title' => 'Refund disetujui',
            'body' => 'Refund {refund_number} sebesar {amount_text} atas pembayaran {payment_number} disetujui. Uang akan dikembalikan setelah diproses kasir.',
        ],
        'refund_completed' => [
            'title' => 'Refund selesai',
            'body' => 'Refund {refund_number} sebesar {amount_text} sudah diproses dan dibayarkan. Sisa tagihan yang tadinya tertutup refund hidup kembali.',
        ],
    ];

    /** Kunci Settings tempat sekolah menyimpan kalimat pengganti. */
    public const SETTINGS_KEY = 'notification_templates';

    public function __construct(private readonly FinanceSettingsService $settings) {}

    /**
     * Judul + isi sebuah peristiwa.
     *
     * @param  array<string, mixed>  $data  nilai token, sudah termasuk angka yang
     *                                      siap baca (`amount_text`, bukan `amount`)
     * @return array{0: string, 1: string}
     */
    public function render(string $key, array $data = []): array
    {
        $template = $this->template($key);

        return [
            $this->fill($template['title'], $data),
            $this->fill($template['body'], $data),
        ];
    }

    /**
     * Daftar kunci beserta kalimat bawaannya — dipakai layar Settings supaya
     * penulisan template tidak perlu menghapal token dari kode.
     *
     * @return array<string, array{title: string, body: string, tokens: list<string>}>
     */
    public function catalog(): array
    {
        $catalog = [];

        foreach (self::DEFAULTS as $key => $template) {
            $tokens = [];

            foreach ([$template['title'], $template['body']] as $text) {
                preg_match_all('/\{([a-z0-9_]+)\}/', $text, $found);

                foreach ($found[1] as $token) {
                    $tokens[$token] = $token;
                }
            }

            $catalog[$key] = $template + ['tokens' => array_values($tokens)];
        }

        return $catalog;
    }

    /**
     * Template berlaku: bawaan ditimpa override sekolah. Override yang tidak
     * dikenal kuncinya diabaikan, dan JSON yang rusak diabaikan seluruhnya —
     * surat masih terkirim dengan kalimat bawaan daripada tidak terkirim sama
     * sekali karena satu tanda kurung salah ketik.
     *
     * @return array{title: string, body: string}
     */
    public function template(string $key): array
    {
        $default = self::DEFAULTS[$key] ?? ['title' => $key, 'body' => ''];

        $overrides = $this->overrides();

        $override = $overrides[$key] ?? null;

        if (! is_array($override)) {
            return $default;
        }

        return [
            'title' => is_string($override['title'] ?? null) && $override['title'] !== '' ? $override['title'] : $default['title'],
            'body' => is_string($override['body'] ?? null) && $override['body'] !== '' ? $override['body'] : $default['body'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function overrides(): array
    {
        $raw = (string) $this->settings->get(self::SETTINGS_KEY, '');

        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Ganti `{token}` dengan nilainya. Token yang nilainya tidak dikirim ikut
     * dibiarkan (lihat docblock kelas), dan nilai non-skalar tidak dirender —
     * template bukan tempat menyimpan objek.
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(string $text, array $data): string
    {
        if ($text === '') {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\{([a-z0-9_]+)\}/',
            function (array $match) use ($data): string {
                $value = $data[$match[1]] ?? null;

                if ($value === null && ! array_key_exists($match[1], $data)) {
                    return $match[0];
                }

                if ($value === null) {
                    return '-';
                }

                return is_scalar($value) ? (string) $value : $match[0];
            },
            $text,
        );
    }
}
