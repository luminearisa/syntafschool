# Standar Akuntansi Modul Finance

**Rujukan:** [AbuNarasya/al-wafi](https://github.com/AbuNarasya/al-wafi) — bagian akuntansinya.
**Sifat perubahan:** aditif. Tidak ada tabel/kolom/alur lama yang diubah maknanya;
angka yang sudah ada tetap sama, sementara aturan yang selama ini hilang kini ditegakkan.

---

## 1. Sepuluh aturan yang diadopsi

| # | Aturan standar | Alasan aturan ini penting |
|---|---|---|
| 1 | **Uang dihitung sebagai string BCMath** (`Money`, skala 2; kuantiti 4) | 0,1 + 0,2 tidak pernah benar-benar 0,3 pada float |
| 2 | **Keseimbangan dibandingkan TEPAT** (Σ debet = Σ kredit) | Selisih satu sen adalah ketidakseimbangan, bukan pembulatan |
| 3 | **Minimal 2 baris; satu sisi per baris; tidak negatif** | Jurnal satu sisi adalah cara jurnal "kembar" lahir dan tak terlihat |
| 4 | **Tanpa hard delete** — koreksi lewat jurnal pembalik; jurnal asli ditandai `reversed` | Pembukuan harus dapat ditelusuri dua arah, selamanya |
| 5 | **Tutup buku mengikat**, tanpa toleransi hari | Laporan yang sudah disampaikan tidak boleh berubah diam-diam |
| 6 | **Buka periode & void jurnal lewat permohonan**, pengaju ≠ pemutus | Keputusan yang mengubah laporan orang lain tidak dilakukan seorang diri |
| 7 | **Dimensi melekat pada baris** (pusat biaya, unit bisnis, dana, persediaan) | Satu dokumen dapat menanggung beberapa unit/dana sekaligus |
| 8 | **Baris NERACA memakai unit penampung** (seluruh liabilitas + rekening kas/bank) | Unit adalah pusat laba; neraca hanya bermakna di tingkat yayasan |
| 9 | **Fund accounting**: dana terikat membatasi **baris beban** saja | Uang wakaf pembangunan tidak boleh terpakai untuk gaji |
| 10 | **Saldo awal: rancangan → posting**, jurnal pembuka wajib seimbang | Neraca awal yang diragukan membuat semua laporan sesudahnya dipertanyakan |

Tambahan: **nama akun di-snapshot** pada baris jurnal, dan **daftar modul asal jurnal** (`sumber_modul`) sebagai sumber kebenaran tunggal.

---

## 2. Modul standar yang kini lengkap

### Aset tetap & penyusutan
- Register aset (`finance_assets`) dengan metode **garis lurus** & **saldo menurun**; penyusutan bulanan
  dihitung dari (harga − residu) ÷ umur, tidak diketik manual.
- Penyusutan berjalan **per periode untuk seluruh aset** (bukan per aset), dengan kunci
  `unique(asset_id, periode)` di basis data sebagai penjaga idempotensi dan `preview()` untuk melihat
  akibatnya lebih dulu.
- **Pelepasan aset** menghasilkan satu jurnal yang menutup seluruh perjalanannya: uang diterima,
  akumulasi penyusutan dibuang, harga perolehan keluar dari neraca, selisihnya laba/rugi.

### Akrual dengan pembalikan otomatis
- `finance_accruals`: setiap akrual lahir dengan **tanggal balik**, dan pembalikannya dijalankan
  otomatis (`reverseDue`) — bukan bergantung pada siapa yang ingat.
- Sisi akun tidak bebas: akrual pendapatan = piutang (1) / pendapatan (4); akrual beban = beban (5) / utang (2).

### Persediaan yang bermuara ke buku besar
- Mutasi barang lewat `/finance/inventory/items/{item}/masuk|keluar` menghasilkan **satu jurnal dan satu pergerakan stok**
  dalam satu transaksi basis data; stok dibaca dengan `lockForUpdate` sehingga tidak dapat minus.
- Barang & jumlahnya melekat pada baris jurnal (`kode_persediaan`, `kuantiti`), jadi laporan per barang
  dapat disusun langsung dari buku besar.

---

## 3. Setelan terkait

| Kunci (`finance/*`) | Bawaan | Arti |
|---|---|---|
| `container_unit` | *(diisi `YAYASAN` oleh `FinanceStandardSeeder`)* | Unit penampung baris neraca |
| `require_cost_center_for_expense` | `false` | Wajibkan pusat biaya pada baris akun beban |
| `cost_center_exempt_modules` | `TutupBuku,Depresiasi,SaldoAwal` | Pengecualian kewajiban itu |
| `fund_control` | `enforce` | `enforce` menolak, `warning` mencatat, `off` mematikan |
| `fiscal_start_month` | `7` | Bulan awal tahun fiskal (tahun ajaran) |

Data awal: `FinanceStandardSeeder` (idempoten) menyiapkan unit YAYASAN/SEKOLAH/ASRAMA/KOPERASI,
pemetaan unit bawaan 12 modul, dan satu dana bebas `DANA-BEBAS`.

---

## 4. Endpoint

```
# unit bisnis                                     izin
GET|POST   /finance/business-units                 finance.account.view|manage
PATCH      /finance/business-units/{code}          finance.account.manage
POST       /finance/business-units/{code}/toggle   finance.account.manage
POST       /finance/business-units/defaults        finance.account.manage

# dana
GET|POST   /finance/funds                          finance.account.view|manage
PATCH      /finance/funds/{id}                     finance.account.manage
POST       /finance/funds/{id}/accounts            finance.account.manage
DELETE     /finance/funds/{id}                     finance.account.manage

# saldo awal
GET        /finance/opening-balances               finance.account.view
POST       /finance/opening-balances               finance.account.manage
DELETE     /finance/opening-balances/{id}          finance.account.manage
POST       /finance/opening-balances/post          finance.account.manage

# permohonan
GET        /finance/period-reopen-requests         finance.closing.view
POST       /finance/period-reopen-requests         finance.closing.manage
POST       /finance/period-reopen-requests/{id}/decide  finance.closing.approve
GET        /finance/periods/{id}/reopen-history    finance.closing.view
GET        /finance/journal-void-requests          finance.journal.view
POST       /finance/journal-void-requests          finance.journal.reverse
POST       /finance/journal-void-requests/{id}/decide   finance.journal.approve

# aset tetap & penyusutan
GET|POST   /finance/assets                         finance.asset.view|manage
GET|PATCH  /finance/assets/{id}                    finance.asset.view|manage
POST       /finance/assets/{id}/dispose            finance.asset.manage
GET        /finance/depreciations/preview          finance.depreciation.view
POST       /finance/depreciations/run              finance.depreciation.post

# akrual
GET        /finance/accruals                       finance.accrual.view
POST|PATCH /finance/accruals[/{id}]                finance.accrual.manage
POST       /finance/accruals/{id}/post             finance.accrual.manage
POST       /finance/accruals/reverse-due           finance.accrual.manage

# persediaan
POST       /finance/inventory/items/{item}/masuk   finance.inventory.post
POST       /finance/inventory/items/{item}/keluar  finance.inventory.post

# laporan
GET        /finance/reports/trial-balance          finance.report.view
GET        /finance/reports/profit-loss-by-unit    finance.report.view
GET        /finance/reports/fund-statement         finance.report.view
GET        /finance/reports/modules                finance.report.view
```

**Catatan urutan rute:** laporan dengan nama tetap (`reports/trial-balance`, …) **wajib didaftarkan
sebelum** pola `reports/{kind}`. Laravel mencocokkan rute menurut urutan pendaftaran, sehingga laporan
yang didaftarkan setelahnya tidak pernah tercapai dan menjawab 404 — pernah terjadi, dan sekarang dijaga.

Izin baru (aditif): `finance.closing.approve`, `finance.journal.approve`, `finance.asset.view`,
`finance.asset.manage`, `finance.depreciation.view`, `finance.depreciation.post`, `finance.accrual.view`,
`finance.accrual.manage`, `finance.inventory.post` — diberikan mengikuti izin "saudaranya" yang sudah ada.

---

## 5. Antarmuka (frontend)

| Menu | Berkas | Isi |
|---|---|---|
| Unit Bisnis | `features/finance/pages/FinanceUnitsPage.tsx` | daftar unit, buat/ubah/nonaktifkan, pemetaan unit bawaan per modul |
| Dana & Peruntukan | `FinanceFundsPage.tsx` | daftar dana, jenis & peruntukan, daftar akun yang boleh dibebani, akun pengurang |
| Saldo Awal | `FinanceOpeningBalancePage.tsx` | rancangan baris demi baris, ringkasan seimbang/selisih, posting jurnal pembuka |
| Aset Tetap | `FinanceAssetsPage.tsx` | register aset, pratinjau & jalankan penyusutan, pelepasan |
| Akrual | `FinanceAccrualsPage.tsx` | rancangan, posting, pembalikan jatuh tempo |
| Permohonan | `FinanceRequestsPage.tsx` | buka periode & pembatalan jurnal, dengan tombol keputusan |
| Laporan Akuntansi | `FinanceReportsPage.tsx` | neraca saldo, laba rugi per unit, laporan dana |

Seluruhnya memakai izin yang sama dengan API; akun COA dipilih lewat `AccountSelect` yang **hanya
menampilkan akun rincian** dan menyaring menurut kelompoknya — sehingga kesalahan seperti memakai akun
neraca untuk penyusutan tidak dapat terjadi di layar.

---

## 6. Bukti uji

```
tests/Feature/FinanceAccountingStandardTest.php   12 tes,    93 asersi  HIJAU (layanan & aturan)
tests/Feature/FinanceStandardApiTest.php          11 tes,   171 asersi  HIJAU (SELURUH endpoint via HTTP)
tests/Feature/FinanceAccountingE2ETest.php        10 tes,   277 asersi  HIJAU (alur bisnis lama, tidak tersentuh)
                                                 ─────────────────────
                                                  33 tes,   541 asersi
```

Uji HTTP membuktikan bukan sekadar status 200: jurnalnya lahir, stoknya bergerak, periodenya benar-benar
terbuka kembali, izin yang tidak dimiliki ditolak (403), dan permintaan cacat dijawab 422.

**Verifikasi peramban (Chromium headless).** Tujuh layar baru dimuat di desktop (1440×900) dan seluler
(390×844). Temuan nyata yang diperbaiki:

- **Kontras header tabel** hanya 3,82:1 (di bawah ambang WCAG AA 4,5:1) — aturan global `index.css`,
  kini 5,32:1; berlaku untuk seluruh tabel aplikasi, bukan hanya layar baru.
- **Tombol ikon tanpa nama** pada daftar dana & saldo awal — kini ber-`aria-label`.
- Menu terakhir pada sidebar ditandai "tertutup" oleh footer; setelah ditelusuri, menu itu **dapat
  dicapai** (elemen terlihat pada `top: 420` setelah navigasi digulir) — perilaku sidebar yang sudah
  ada sebelumnya (menu "Penilaian" pun sama), bukan cacat baru.

---

## 7. Yang sengaja TIDAK diambil (beserta alasannya)

| Dari rujukan | Alasan |
|---|---|
| Tabel `coa_groups` berjenjang terpisah | Proyek ini punya hierarki `parent_id` + aturan "posting hanya ke akun daun"; menambah tabel kelompok akan menduplikasi sumber kebenaran. Akar kelompok 1–5 tetap disediakan `CoaHierarchy`. |
| Nama tabel `journal_entries` | `finance_transactions` **adalah** jurnalnya; mengganti nama berarti memindahkan seluruh riwayat tanpa manfaat akuntansi. |
| Mesin persetujuan generik (`approval_flows`) | Proyek ini punya modul `Workflow` dan persetujuan spesifik per domain; mesin kedua akan membingungkan pengguna. |
| TA fleksibel untuk anggaran | Sudah ada: `finance_periods` + `fiscal_start_month` (bawaan 7 = tahun ajaran). |

---

## 8. Cacat nyata yang ditemukan selama penyelarasan

| Cacat | Akibat | Perbaikan |
|---|---|---|
| Keseimbangan jurnal ditoleransi 0,005 | selisih nyata lolos diam-diam | `JournalValidator` membandingkan tepat sebagai string |
| Seluruh perhitungan uang memakai float | pembulatan yang menyimpang dari kenyataan | `App\Support\Money` (BCMath) |
| Batas laporan memakai tanggal `Y-m-d` pada kolom datetime | transaksi hari terakhir rentang terbuang | batas memakai tanggal-waktu |
| Laporan dana menghitung dari sisi kas | satu pembayaran beban terhitung dua kali | penerimaan dari akun pendapatan, penggunaan dari akun beban; arus kas terpisah |
| Laporan bernama tetap didaftarkan setelah `reports/{kind}` | tiga laporan menjawab 404 | urutan pendaftaran diperbaiki + komentar penjaga |
| `reverse()` tidak dapat bertanggal | akrual hanya bisa dibalik di hari tombol ditekan | `reverse(..., ?Carbon $tanggal)` |
| Pesan periode tertutup tidak menyebut jalur pembukaan | pengguna tidak tahu harus ke mana | pesan menunjuk permohonan buka periode |

Catatan lintas modul: pola yang sama (kolom/enum yang dipakai model tetapi tidak ada di skema) juga
ditemukan pada modul BK dan diperbaiki — lihat `docs/PROGRESS.md`.
