<?php

namespace App\Contracts;

use Illuminate\Http\Request;

/**
 * Kontrak payment gateway untuk modul wallet (§29).
 *
 * Aplikasi ini sudah punya satu jembatan Midtrans (`PpdbMidtransGateway`), tetapi
 * kelas itu milik modul PPDB dan terikat pada `PpdbInvoice`/`PpdbPayment` — tidak
 * bisa dipakai ulang tanpa menyeret domain pendaftaran ke dalam wallet. Karena itu
 * wallet bergantung pada **interface ini** saja, dan penyedia konkret dipilih lewat
 * `PaymentGatewayManager`. Midtrans menjadi provider pertama (§29).
 *
 * Tiga aturan yang membuat interface ini bisa dipakai uang sungguhan:
 *
 * 1. Status yang keluar dari `checkStatus()` dan `handleWebhook()` sudah dipetakan
 *    ke `WalletTopUp::PAYMENT_STATUSES`. Wallet tidak boleh tahu bahwa Midtrans
 *    menyebut pelunasan "settlement" — penerjemahan itu tugas provider.
 * 2. `verifySignature()` adalah satu-satunya penjaga pintu webhook (rutenya publik),
 *    jadi ia harus menolak secara default: tanpa kredensial, tanpa pengecualian.
 * 3. Kegagalan konfigurasi/ jaringan dilempar sebagai `RuntimeException` dengan pesan
 *    berbahasa Indonesia yang bisa dibaca pengguna; pemanggil mengubahnya jadi 422,
 *    bukan 500.
 */
interface PaymentGateway
{
    /**
     * Buat satu pembayaran di sisi provider.
     *
     * `$intent` minimal memuat:
     * - `reference`  : nomor dokumen kita (order id) — provider WAJIB memanggilnya
     *                  balik pada notifikasi, karena itu nomor ini yang dipakai
     *                  untuk mencocokkan pembayaran.
     * - `amount`     : nominal rupiah (decimal 2).
     * - `description`, `customer` (name/email/phone), `metadata`, `expires_at`.
     *
     * @param  array<string, mixed>  $intent
     * @return array{reference: string, amount: float, token: string|null, redirect_url: string|null, provider_reference: string|null, expires_at: string|null, raw: array<string, mixed>}
     *
     * @throws \RuntimeException bila provider belum dikonfigurasi atau menolak permintaan
     */
    public function createPayment(array $intent): array;

    /**
     * Tanya status pembayaran ke provider (rekonsiliasi, bukan satu-satunya sumber
     * kebenaran — notifikasi tetap jalur utama).
     *
     * @return array{reference: string, status: string, amount: float, provider_reference: string|null, raw: array<string, mixed>}
     *
     * @throws \RuntimeException ketika pertanyaan status tidak dapat dilakukan
     */
    public function checkStatus(string $reference): array;

    /**
     * Baca notifikasi dari body permintaan dan normalkan.
     *
     * Method ini TIDAK memverifikasi tanda tangan — pemanggil harus memanggil
     * `verifySignature()` lebih dulu. Normalisasi membuat pemanggil tidak pernah
     * membaca kunci mentah milik provider.
     *
     * @return array{reference: string, status: string, amount: float, provider_reference: string|null, raw: array<string, mixed>}
     *         `status` salah satu `WalletTopUp::PAYMENT_STATUSES`, atau string kosong
     *         bila notifikasi tidak dikenali (pemanggil mengabaikannya).
     */
    public function handleWebhook(Request $request): array;

    /**
     * Sahkah notifikasi ini berasal dari provider?
     *
     * Perbandingan harus waktu-konstan (`hash_equals`) dan menolak saat provider
     * belum punya kredensial.
     */
    public function verifySignature(Request $request): bool;

    /** Apakah penyedia ini yang menangani nama provider `$provider`? */
    public function supports(string $provider): bool;

    /** Kredensial sudah terpasang sehingga provider boleh ditawarkan ke pengguna? */
    public function isConfigured(): bool;
}
