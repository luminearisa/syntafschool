# Standar Akuntansi Modul Finance

**Rujukan:** [AbuNarasya/al-wafi](https://github.com/AbuNarasya/al-wafi) — bagian akuntansinya.
**Sifat perubahan:** aditif. Tidak ada tabel, kolom, atau alur lama yang diubah maknanya;
angka yang sudah ada tetap sama, sementara aturan yang selama ini hilang kini ditegakkan.

---

## 1. Sepuluh aturan yang diadopsi

| # | Aturan standar | Alasan aturan ini penting |
|---|---|---|
| 1 | **Uang dihitung sebagai string BCMath, bukan float** (`Money`, skala 2; kuantiti skala 4) | 0,1 + 0,2 tidak pernah benar-benar 0,3 pada float. Modul ini dulu menutupinya dengan `TOLERANCE = 0.005` — dan toleransi itu menyembunyikan ketidakseimbangan yang sesungguhnya. |
| 2 | **Keseimbangan dibandingkan TEPAT** (Σ debet = Σ kredit) | Selisih satu sen adalah ketidakseimbangan, bukan pembulatan. |
| 3 | **Minimal 2 baris; setiap baris satu sisi; tidak boleh negatif** | Jurnal satu sisi adalah cara paling umum jurnal "kembar" lahir dan tak pernah terlihat. |
| 4 | **Tidak ada hard delete jurnal** — koreksi lewat jurnal pembalik (`reversal_of`), jurnal asli ditandai `void/reversed` | Pembukuan harus dapat ditelusuri dua arah, selamanya. |
| 5 | **Tutup buku mengikat, tanpa toleransi hari** | Laporan yang sudah dicetak dan disampaikan ke yayasan tidak boleh berubah angkanya diam-diam. |
| 6 | **Membuka periode & membatalkan jurnal lewat permohonan dengan pemisahan tugas** | Keputusan yang mengubah laporan orang lain tidak boleh dilakukan seorang diri. |
| 7 | **Dimensi melekat pada baris**: pusat biaya (realisasi anggaran) & unit bisnis (laba rugi/arus kas per unit), bukan hanya di kepala dokumen | Satu dokumen dapat menanggung beberapa unit sekaligus (SPP + asrama). |
| 8 | **Baris NERACA selalu memakai unit penampung** (seluruh liabilitas + setiap rekening kas/bank) | Unit adalah pusat laba; neraca hanya bermakna di tingkat yayasan. Satu rekening kas memang menampung uang banyak unit. |
| 9 | **Fund accounting (dana)**: dana terikat membatasi **baris beban** saja, selebihnya bebas | Uang wakaf pembangunan tidak boleh terpakai untuk gaji. Yang dibatasi adalah belanja-nya, bukan kasnya — kas menumpang rekening yang sama. |
| 10 | **Saldo awal punya tahap rancangan → posting**, dan jurnal pembukanya wajib seimbang | Neraca awal yang tidak dapat dipercaya membuat semua laporan sesudahnya dipertanyakan. |

Tambahan dari standar yang sama: **nama akun di-snapshot** pada baris jurnal, dan **daftar modul asal jurnal** (`sumber_modul`) sebagai sumber kebenaran tunggal.

---

## 2. Matriks kesenjangan

| Aspek | Standar rujukan | Keadaan sebelumnya di proyek ini | Sekarang |
|---|---|---|---|
| Aritmetika uang | BCMath string, tanpa float | `round((float) …)`, toleransi 0,005 saat uji keseimbangan | `App\Support\Money` + perbandingan tepat |
| Validasi double-entry | Fungsi murni terpisah, 3 aturan | Tersebar di engine, satu baris pun diterima | `JournalValidator` (murni, tanpa DB) |
| Asal modul jurnal | Daftar sah `SumberModul` | `reference_type` bebas diisi; asal jurnal tak dapat dipastikan | `SumberModul` (29 modul) + kolom `sumber_modul`, nilai di luar daftar ditolak |
| Cabang akun (root 1–5) | `CoaDetail::akarKelompok()` dari kode | Tidak ada; hanya kolom `type` yang bisa diubah orang | `CoaHierarchy` (kode lebih dulu, tipe sebagai cadangan) |
| Dimensi unit bisnis | `kode_unit` per baris + unit penampung untuk neraca | Tidak ada dimensi unit sama sekali | `finance_business_units`, `finance_unit_defaults`, `kode_unit` per baris + aturan unit penampung |
| Dana (fund accounting) | `dana`, `dana_akun`, akun pengurang dana bebas | Tidak ada | `finance_funds`, `finance_fund_accounts`, `finance_fund_contra_accounts`, `DanaPolicy` |
| Saldo awal | Tabel tersendiri + flag `posted` → jurnal pembuka | Menumpang tipe transaksi `opening_balance` per rekening kas/bank | Tabel `finance_opening_balances` + `OpeningBalanceService` (rancangan → posting, wajib seimbang) |
| Buka periode | Permohonan + persetujuan orang lain | Izin saja (`reopen`) | `finance_period_reopen_requests` + `PeriodReopenService` (pemisahan tugas) |
| Pembatalan jurnal | `void_approvals` | Izin saja (`reverse`) | `finance_journal_void_requests` + `JournalVoidService` (pemisahan tugas) |
| Laporan | Neraca saldo, laba rugi per unit, laporan dana | Laba rugi/neraca/arus kas (tanpa dimensi unit & dana) | + `StandardReportService`: neraca saldo, laba rugi per unit, laporan dana (penerimaan/penggunaan/saldo + arus kas) |
| Pusat biaya wajib untuk akun beban | `BagianPolicy` wajib untuk akun beban pada modul tertentu | Tidak diwajibkan | Kebijakan `require_cost_center_for_expense` (bawaan **mati** agar alur lama tidak tertahan) |
| Penomoran dokumen | `PREFIX-YYMM-NNNN` | Sudah lebih fleksibel (template `{SEQ:n}` + reset berkala) | **Dipertahankan** — sudah memenuhi standar, bahkan lebih umum |

---

## 3. Yang sengaja TIDAK diambil (beserta alasannya)

| Dari rujukan | Alasan tidak diambil |
|---|---|
| Tabel `coa_groups` berjenjang 1–3 terpisah dari `coa_detail` | Proyek ini sudah punya hierarki `parent_id` + aturan "posting hanya ke akun daun" (`isLeaf()`). Menambah tabel kelompok akan menduplikasi sumber kebenaran tanpa menambah kemampuan. Akar kelompok 1–5 tetap disediakan lewat `CoaHierarchy`. |
| Tabel jurnal bernama `journal_entries` | Di proyek ini `finance_transactions` **adalah** jurnalnya. Mengganti nama tabel berarti memindahkan seluruh riwayat tanpa manfaat akuntansi. |
| Baris penggerak persediaan (`kode_persediaan`, `kuantiti`) | Belum ada modul persediaan di proyek ini. Menambah kolom yang tak dipakai hanya melahirkan janji kosong — dicatat sebagai prasyarat saat modul itu dibangun. |
| Register aset tetap & depresiasi | Belum ada modul aset. Modul `sumber_modul` sudah menyediakan `DEPRESIASI` dan `PELEPASAN_ASET` sehingga tidak perlu perubahan skema saat modul itu tiba. |
| Tabel `accrues` terpisah | Akrual di proyek ini sudah tertangani lewat tipe transaksi `invoice`/`payable` + subledger. |
| Alur persetujuan generik (`approval_flows/steps/instances`) | Proyek ini punya modul `Workflow` dan persetujuan spesifik per domain (budget, expense, transfer, clearance). Menambah mesin persetujuan kedua akan membingungkan pengguna. |
| TA fleksibel untuk anggaran | Sudah ada: `finance_periods` + setelan `fiscal_start_month` (bawaan 7 = tahun ajaran). |
| Akun pengurang dana bebas sebagai kolom di tabel dana | Dibuat sebagai tabel `finance_fund_contra_accounts` agar satu dana dapat punya beberapa akun pengurang. |

---

## 4. Perubahan perilaku yang akan terasa

1. **Jurnal tidak seimbang kini ditolak walau selisihnya satu sen** (dulu ditoleransi sampai 0,005).
2. **Pesan periode tertutup menyebutkan jalur pembukaannya** (menu Tutup Buku → permohonan → persetujuan orang lain), bukan sekadar "periode closed".
3. **Baris kas/bank & liabilitas berhenti mengikuti unit dokumen** begitu `container_unit` diisi; sebelum diisi, perilakunya sama seperti sebelumnya.
4. **Dana terikat menolak beban di luar peruntukannya** dengan pesan yang menyebut nama dana, jenisnya, dan peruntukannya. Mode `warning` dapat dipakai lebih dulu bila sekolah baru mulai menandai dana.
5. **Saldo awal harus seimbang** sebelum dapat diposting; selisihnya disebutkan lengkap, dan jurnal pembuka yang sudah jadi tidak dapat diubah lagi.
6. **Pembatalan jurnal bernilai apa pun kini butuh persetujuan orang lain** (pengaju ≠ penyetuju).

---

## 5. Setelan terkait

| Kunci (`finance/*`) | Bawaan | Arti |
|---|---|---|
| `container_unit` | *(kosong)* | Unit penampung untuk baris neraca (kas/bank & liabilitas). Kosong = perilaku lama. |
| `require_cost_center_for_expense` | `false` | Wajibkan pusat biaya pada baris akun beban. |
| `cost_center_exempt_modules` | `TutupBuku,Depresiasi,SaldoAwal` | Modul yang dikecualikan dari kewajiban itu. |
| `fund_control` | `enforce` | `enforce` menolak, `warning` mencatat saja, `off` mematikan penjaga peruntukan. |
| `fiscal_start_month` | `7` | Bulan awal tahun fiskal (tahun ajaran). |

---

## 6. Endpoint baru

```
GET    /api/v1/finance/business-units                  finance.account.view
POST   /api/v1/finance/business-units                  finance.account.manage
PATCH  /api/v1/finance/business-units/{code}           finance.account.manage
POST   /api/v1/finance/business-units/{code}/toggle    finance.account.manage
POST   /api/v1/finance/business-units/defaults         finance.account.manage

GET    /api/v1/finance/funds                           finance.account.view
POST   /api/v1/finance/funds                           finance.account.manage
PATCH  /api/v1/finance/funds/{id}                      finance.account.manage
POST   /api/v1/finance/funds/{id}/accounts             finance.account.manage
DELETE /api/v1/finance/funds/{id}                      finance.account.manage

GET    /api/v1/finance/opening-balances                finance.account.view
POST   /api/v1/finance/opening-balances                finance.account.manage
DELETE /api/v1/finance/opening-balances/{id}           finance.account.manage
POST   /api/v1/finance/opening-balances/post           finance.account.manage

GET    /api/v1/finance/period-reopen-requests          finance.closing.view
POST   /api/v1/finance/period-reopen-requests          finance.closing.manage
POST   /api/v1/finance/period-reopen-requests/{id}/decide  finance.closing.approve
GET    /api/v1/finance/periods/{periodId}/reopen-history   finance.closing.view

GET    /api/v1/finance/journal-void-requests           finance.journal.view
POST   /api/v1/finance/journal-void-requests           finance.journal.reverse
POST   /api/v1/finance/journal-void-requests/{id}/decide   finance.journal.approve

GET    /api/v1/finance/reports/trial-balance           finance.report.view
GET    /api/v1/finance/reports/profit-loss-by-unit     finance.report.view
GET    /api/v1/finance/reports/fund-statement          finance.report.view
GET    /api/v1/finance/reports/modules                 finance.report.view
```

Dua izin baru ditambahkan (aditif) pada seeder: `finance.closing.approve`, `finance.journal.approve` — keduanya untuk peran penyetuju, bukan pencatat.

---

## 7. Berkas utama

```
app/Support/Money.php                                   aritmetika BCMath (skala 2 & 4)
app/Support/Accounting/SumberModul.php                  daftar modul asal jurnal yang sah
app/Support/Accounting/JournalValidator.php             aturan double-entry, fungsi murni
app/Support/Accounting/CoaHierarchy.php                 akar kelompok 1–5, aturan baris neraca
app/Services/Finance/Accounting/UnitResolver.php        unit 3 tingkat + unit penampung
app/Services/Finance/Accounting/DanaPolicy.php          penjaga peruntukan dana (baris beban)
app/Services/Finance/Accounting/BusinessUnitService.php unit bisnis & unit bawaan modul
app/Services/Finance/Accounting/FundService.php         dana & daftar akunnya
app/Services/Finance/Accounting/OpeningBalanceService.php  saldo awal: rancangan → posting
app/Services/Finance/Accounting/PeriodReopenService.php permohonan buka periode
app/Services/Finance/Accounting/JournalVoidService.php  permohonan pembatalan jurnal
app/Services/Finance/Accounting/StandardReportService.php  neraca saldo, laba rugi per unit, dana
app/Services/Finance/FinancialTransactionEngine.php     (diubah) money + dimensi + penjaga
database/migrations/2026_12_16_100001_create_finance_standard_tables.php
```

---

## 8. Bukti uji

```
tests/Feature/FinanceAccountingE2ETest.php        10 tes, 277 asersi  — HIJAU (tidak tersentuh)
tests/Feature/FinanceAccountingStandardTest.php   12 tes,  93 asersi  — HIJAU (baru)
```

Uji baru menjaga: presisi uang tepat, penolakan jurnal tidak sah, penolakan modul asal tak dikenal,
periode tertutup + permohonan buka periode dengan pemisahan tugas, dana terikat hanya membatasi
baris beban, dana nonaktif/tak dikenal ditolak, pewarisan unit 3 tingkat + unit penampung untuk
kas, snapshot nama akun, saldo awal wajib seimbang & terkunci, pemisahan tugas pada pembatalan
jurnal beserta penyalinan dimensi, dan laporan (neraca saldo seimbang, laba rugi per unit, aliran dana).

Catatan jujur: cakupan uji **HTTP** untuk endpoint baru belum ada — berkas ini menguji layanan,
engine, dan aturan. Endpoint baru sudah memakai izin yang benar-benar ada di basis data
(`finance.*` 83 izin + 2 baru), tetapi jalur HTTP-nya belum diuji satu per satu.
