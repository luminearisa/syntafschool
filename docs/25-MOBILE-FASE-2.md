# Mobile Fase 2 — fitur wali murid & temuan alur backend

Layar baru (peran **wali murid**; Kesehatan, Konseling, Dompet juga terbuka baca untuk **siswa**):
Keuangan lengkap (tagihan + rincian + **bayar online**, riwayat pembayaran, kuitansi, cicilan, daftar ulang),
Asrama (ringkasan, **ajukan izin keluar / kepulangan**, pelanggaran, prestasi, absensi, tamu, kesehatan),
Tahfiz, Ekstrakurikuler (+ **persetujuan**), Kesehatan (klinik), Konseling BK (**ajukan konsultasi**, janji temu, **persetujuan**),
Dompet (saldo, riwayat, **top up gateway**).

## Alur backend yang dipakai apa adanya
| Fitur | Endpoint | Pagar |
|---|---|---|
| Keuangan baca | `finance/portal/children/{id}/{summary,invoices,payments,receipts,payment-plans,re-registrations}` | `assertChild` |
| Asrama baca | `boarding/portal/children/{id}/…` | `assertChild` |
| Tahfiz | `tahfiz/dashboards/wali?view=child&participant_id=` | anak dihitung dari `ContextService` (setelah perbaikan Fase 0) |
| Aktivitas | `activities/portal/{parent,consents}` + `…/consents/{id}/decide` | `ActivityConsentService::canDecide` |
| Klinik | `clinic/portal/{children,patients/{id}[/visits|/certificates]}` | `ClinicAccessService` (+ konfigurasi privasi `clinic.privacy.*`) |
| BK | `bk/portal/{summary,requests,appointments,consents}`, `requests/guardian`, `consents/{id}/decide` | `BkAccessService` |

## Yang ditambah di backend (celah alur)
1. **Bayar tagihan oleh orang tua belum ada.** `POST finance/portal/children/{student}/invoices/{invoice}/pay`:
   kepemilikan anak + invoice; **dimatikan bawaan** (`portal_payment_enabled`, `portal_payment_bank_account_id` di pengaturan Keuangan —
   sekolah harus memilih rekening tujuan karena `FinancePaymentService::record` mewajibkan akun bank/kas); hanya invoice berjalan, nominal ≤ sisa;
   satu pembayaran online yang menunggu per invoice per orang tua; `Idempotency-Key`; bila kanal gateway gagal dibuka, pembayaran yang baru
   lahir **ditolak** (bukan dibiarkan `pending`). Jurnal/kuitansi tetap lewat webhook/verifikasi yang sudah ada.
2. **Pengajuan izin keluar & kepulangan oleh orang tua** (service-nya menulis "menyusul portal orang tua"):
   `POST boarding/portal/children/{id}/{permissions,homecomings}` + `…/{x}/cancel`. Service dipanggil `actor=null` (lingkup pengasuh tak berlaku),
   `requested_by` diisi akun orang tua, pembatalan hanya atas pengajuan miliknya, tanggal lampau ditolak, wajib penempatan aktif.
3. **Dompet** tak punya portal: `GET mobile/children/{id}/wallet` (saldo, riwayat tanpa kolom staf, top up terakhir) dan
   `POST …/wallet/topups` (gateway saja, wali saja, satu top up menunggu per dompet).
4. Portal `invoices` tidak memuat rincian (hanya `items_count`) → kini `items.feeType` dimuat.

## Temuan yang TIDAK diubah (butuh keputusan)
- **Portal BK mengembalikan model utuh** (`case_id`, `decline_reason`, `case.case_number` pada persetujuan). Aplikasi tidak menampilkannya,
  tetapi API tetap membocorkan keberadaan kasus; sebaiknya dibuat Resource khusus portal.
- **`FinancePaymentResource` dipakai portal** dan memuat `bank_account_name`, `cash_account_name`, `verified_by_name` (data internal sekolah).
- `tahfiz … available_children` hanya `id,name,participant_type` (tanpa `student_id`), jadi pemilih anak Tahfiz terpisah dari pemilih global.
- **UKS belum dibuka** untuk wali: izin/lingkupnya (`UksAccessService`) perlu ditelaah dulu. Top up transfer manual (unggah bukti) belum ada di app.
- Jalur gateway nyata (Midtrans) belum diuji; tes hanya menutup jalur penolakan dan kepemilikan.
- Seluruh tes backend belum dijalankan (PHP 8.3 di sandbox vs Laravel 13 → butuh 8.4).
