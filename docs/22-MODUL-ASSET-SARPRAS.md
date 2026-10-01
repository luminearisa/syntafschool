# Modul Asset & Sarpras (Facilities & Asset Management)

**Status:** backend, API, integrasi, dan otomasi **selesai dan teruji**; antarmuka
pengguna belum dibangun (lihat *Yang belum ada* di bagian akhir).
**Uji:** `tests/Feature/Asset` — **47 tes, 166 asersi, hijau**.

```
berkas            232 berkas PHP        18.683 baris
basis data        60 tabel (10 migrasi)
domain            44 enum · 7 mesin keadaan (state machine)
integrasi         15 kontrak · 16 adapter
layanan           28 layanan aplikasi
HTTP              17 controller · 139 rute · 4 Form Request · 2 Resource
kebijakan         11 Policy · 12 peran (RBAC) · 41 izin
uji               9 berkas · 1.316 baris
```

---

## 1. Yang dijawab modul ini

Modul ini adalah **sumber kebenaran tunggal untuk aset fisik dan fasilitas**:
di mana barangnya, siapa memegangnya, bagaimana kondisinya, berapa nilainya,
kapan harus dirawat, dan bagaimana ia berakhir. Ia **bukan** CRUD inventaris:
setiap perpindahan, peminjaman, perbaikan, penghitungan, dan penghapusan melewati
aturan yang sama, tercatat, dan tidak dapat diubah diam-diam.

Batas dengan modul lain ditegaskan (§111 pada spesifikasi):

| Urusan | Sumber kebenaran |
|---|---|
| Pengadaan (permintaan, PO, penerimaan) | modul Pengadaan |
| Stok habis pakai & suku cadang | modul Persediaan |
| Jurnal, penyusutan pembukuan, laba-rugi | modul Keuangan |
| Jadwal pelajaran & ruang akademik | modul Akademik |
| Siklus hidup aset & fasilitas | **modul ini** |

Modul ini tidak menyimpan buku besar kedua, tidak menyimpan stok kedua, dan
tidak membuat pesanan pembelian.

---

## 2. Siklus hidup yang berjalan

```
Pengakuan (ASSET / INVENTORY / EXPENSE)
   → Pendaftaran (kode · QR · barcode · label)
   → Pelabelan (cetak → tempel → verifikasi pindai)
   → Penempatan (lokasi + pemegang, dengan pengakuan)
   → Penggunaan (penugasan · peminjaman · reservasi)
   → Inspeksi (butir dapat dikonfigurasi)
   → Pemeliharaan (permintaan → triage → work order → verifikasi)
   → Pemindahan (permintaan → serah terima → konfirmasi penerima)
   → Stok opname (harapan → pindai → selisih → keputusan → penyesuaian)
   → Penilaian ulang / penyusutan
   → Penghapusan (inspeksi → valuasi → persetujuan → pelaksanaan)
   → Arsip (baris TETAP ada dan tetap dapat dicari)
```

Untuk fasilitas:

```
Kampus → Gedung → Lantai → Ruang → Fasilitas → Booking → Pemeliharaan → Inspeksi
```

---

## 3. Aturan yang benar-benar ditegakkan (bukan sekadar niat)

Semuanya punya uji otomatis; angka di kurung adalah jumlah invarian yang diuji.

1. **Tidak ada penghapusan aset.** `AssetPolicy::delete()` selalu `false`;
   penghapusan berarti status `DISPOSED` → `RETIRED`, dan barisnya tetap ada. (4 uji)
2. **Pengakuan sebelum pencatatan.** Barang habis pakai tidak dapat menjadi aset;
   permintaan ditolak **dengan pesan yang menyebut tempat yang benar** (Persediaan
   atau Beban), dan barang habis pakai tetap dicatat ke Persediaan. (3 uji)
3. **Transisi status tervalidasi.** Tujuh mesin keadaan: aset, peminjaman,
   pemindahan, pemeliharaan (permintaan + work order), booking, stok opname,
   penghapusan. Status akhir tidak dapat berpindah; lompatan ditolak 409. (5 uji)
4. **Satu aset, satu pinjaman berjalan.** Dijaga **kunci unik basis data**
   (`assets.active_loan_id`), bukan hanya pemeriksaan di kode — dua permintaan
   bersamaan tidak dapat sama-sama menang. (2 uji)
5. **Aset yang sedang diperbaiki/dipinjam/dihapus tidak dapat dipinjam atau
   dipindahkan**; aset yang dipinjam tidak dapat diusulkan penghapusan. (4 uji)
6. **Pemulangan selalu memeriksa kondisi.** Kondisi buruk melahirkan laporan
   kerusakan **dan** permintaan pemeliharaan — bukan sekadar catatan. (1 uji)
7. **Pemindahan wajib punya tujuan**, wajib serah terima, dan **baru selesai
   setelah penerima mengonfirmasi**; yang menyerahkan tidak boleh mengonfirmasi
   penerimaannya sendiri (kecuali pemegang izin pengelolaan). (3 uji)
8. **Riwayat pemegang tidak ditimpa.** Baris dibuka (`assigned_at`) dan ditutup
   (`released_at`); pertanyaan "siapa memegang ini bulan lalu" tetap terjawab. (1 uji)
9. **Booking fasilitas bebas benturan.** Diperiksa dua kali: sebelum menyimpan
   (agar pesannya jelas) dan **di dalam transaksi dengan kunci baris fasilitas**
   (agar benar-benar tidak dapat dilewati). (3 uji)
10. **Fasilitas ditutup → booking terdampak ditemukan dan diberi tahu.** (1 uji)
11. **Stok opname tidak mengubah aset secara langsung.** Selisih hidup di
    barisnya sendiri; penyesuaian hanya diterapkan setelah diputuskan; barang
    tidak ditemukan menjadi `UNDER_INVESTIGATION`, **bukan** `LOST`. (3 uji)
12. **Suku cadang diambil dari Persediaan.** Stok kurang → pekerjaan
    **ditahan** (`ON_HOLD`, permintaan `WAITING_PARTS`), bukan dianggap selesai. (1 uji)
13. **Suku cadang tidak disimpan dua kali**; pemakaian dicatat sebagai pergerakan
    stok persediaan + biaya pada work order.
14. **Pemeliharaan preventif idempoten.** Satu jadwal melahirkan satu work order
    per jatuh tempo; perintah terjadwal yang dijalankan dua kali tidak
    menggandakan pekerjaan. (1 uji)
15. **Temuan inspeksi yang gagal melahirkan permintaan pemeliharaan** — inspeksi
    tanpa akibat adalah formulir, bukan pengendalian. (1 uji)
16. **Work order tidak dapat melompat ke verifikasi** sebelum selesai. (1 uji)
17. **Penghapusan tidak dapat dilaksanakan tanpa persetujuan** dan inspeksi
    pra-penghapusan. (2 uji)
18. **Penggantian adalah rekomendasi**, bukan pembelian: usulan diteruskan ke
    Pengadaan lewat kontrak/outbox. (1 uji)
19. **Kontrol perubahan bersamaan (optimistic locking).** Setiap penyimpanan
    menaikkan `version`; penyimpanan dengan versi lama ditolak 409. (1 uji)
20. **Isolasi unit sekolah (tenant).** Global scope `BelongsToSchool`; aset unit
    lain tidak terlihat. (1 uji)
21. **Data keuangan hanya untuk pemegang izin keuangan** — dan itu ditegakkan di
    layanan **dan** di `AssetResource`, jadi angka tidak pernah ikut terkirim. (2 uji)
22. **QR publik tidak pernah memuat data keuangan** (hanya kode, nama, lokasi,
    kondisi, status) dan dibatasi laju. (1 uji)
23. **Jejak audit tidak dapat diubah atau dihapus** — dijaga di model, bukan
    hanya di kebijakan. (1 uji)
24. **Ekspor selalu tercatat** pada jejak audit. (1 uji)
25. **Akses yang ditolak ikut tercatat.** (1 uji)

---

## 4. Kalau modul lain belum terpasang

Modul ini **dapat dipakai sekolah yang belum memasang Keuangan/Persediaan/
Pengadaan**, dan itu disengaja: seluruh integrasi melewati kontrak, dan niat
yang belum dapat dikerjakan masuk **outbox** (`asset_integration_outbox`)
berstatus `PENDING` — bukan hilang.

| Kontrak | Bawaan | Perilaku bila modul tujuan ada |
|---|---|---|
| `FinanceProviderInterface` | `EloquentFinanceProvider` | Kapitalisasi/penyusutan/penghapusan dicatat ke `finance_assets` + layanan akuntansi; `bookValue()` dibaca dari sana |
| `InventoryProviderInterface` | `EloquentInventoryProvider` | Pengeluaran barang mengurangi stok persediaan + mencatat pergerakan |
| `ProcurementProviderInterface` | `EloquentProcurementProvider` | Permintaan penggantian dititipkan; `markRecognized()` menutup niatnya |
| `WorkflowProviderInterface` | `WorkflowEngineProvider` | Proses persetujuan dimulai pada Workflow Engine. **Tanpa mesin itu, permintaan tetap MENUNGGU** — tidak pernah disetujui diam-diam |
| `NotificationProviderInterface` | `DatabaseNotificationProvider` | Baris `asset_notifications` + kanal `notifications` Laravel |
| `DocumentProviderInterface` | `LocalDocumentProvider` | Berkas di disk privat + URL sementara |
| `AcademicProviderInterface` | `NullAcademicProvider` | Pemeriksaan benturan jadwal pelajaran **belum berjalan** sampai ruang dipetakan (`locations.academic_room_id`) — dinyatakan terus terang |
| `VendorProviderInterface` | `EloquentVendorProvider` | Membaca `finance_vendors` |
| `AssetProviderInterface`, `FacilityProviderInterface` | modul ini | Dipakai modul lain (Kegiatan, Event) untuk meminjam alat & memesan ruang — lewat aturan yang sama, tanpa jalan pintas |
| `AuditProviderInterface`, `BoardingProviderInterface`, `ClinicProviderInterface`, `EventProviderInterface`, `StudentActivityProviderInterface` | penyedia lokal/kosong | Titik sambung yang jelas untuk modul yang menyusul |

Perintah **`asset:integration:dispatch`** mengerjakan ulang entri `PENDING`
(percobaan dicatat, kegagalan disimpan di `last_error`).

---

## 5. Otomasi

| Perintah | Kegiatan | Idempoten |
|---|---|---|
| `asset:preventive` | Buat work order dari jadwal preventif yang jatuh tempo | ya (satu per jatuh tempo) |
| `asset:reminders` | Garansi berakhir (90/60/30 hari), inspeksi & pemeliharaan jatuh tempo, pinjaman terlambat, siklus opname | ya (sekali per hari per hal) |
| `asset:replacement-review` | Nilai aset dan usulkan penggantian | ya (`unique(asset_id, status)`) |
| `asset:integration:dispatch` | Kerjakan ulang outbox | ya (status entri) |
| `asset:health` | Ringkasan operasional untuk operator | — |

Jendela pengingat, ambang penggantian, batas peminjaman, dan SLA **dapat
dikonfigurasi** (`config/asset.php` + tabel `asset_settings`), bukan dipaku di kode.

---

## 6. Hak akses

12 peran: `ASSET_ADMIN`, `SARPRAS_MANAGER`, `ASSET_OFFICER`, `INVENTORY_OFFICER`,
`MAINTENANCE_MANAGER`, `TECHNICIAN`, `FACILITY_MANAGER`, `DEPARTMENT_MANAGER`,
`ASSET_CUSTODIAN`, `FINANCE`, `PROCUREMENT`, `AUDITOR` (+ `ERP_ADMIN`).

Pemisahan yang penting: **pengusul penghapusan ≠ penyetuju**; **petugas aset tidak
otomatis boleh mengubah angka keuangan**; **teknisi hanya mengerjakan work order
yang ditugaskan**; **wali kelas/guru hanya melihat aset unitnya** (dan hanya yang
ditugaskan kepadanya). Terdapat 41 izin granular; daftar lengkapnya ada di
`AssetPermissionSeeder`.

---

## 7. Uji yang ada (47 tes, 166 asersi)

| Berkas | Yang dibuktikan |
|---|---|
`AssetRegisterTest` (10) | kode/QR/barcode/label, pengakuan ASSET/INVENTORY/EXPENSE, verifikasi tempelan label, riwayat pemegang, transisi status terlarang, pengunci versi, izin keuangan, kondisi buruk, QR publik aman
`AssetLoanTest` (6) | alur lengkap, **anti-double loan** (jadwal bentrok + kunci basis data), batas hari, aset diperbaiki, pengembalian rusak → laporan + pemeliharaan, penandaan terlambat sekali saja
`AssetTransferTest` (4) | alur lengkap + riwayat pemegang, tujuan wajib & lokasi sama ditolak, konfirmasi bukan oleh pengirim, aset dipinjam tidak dapat dipindah
`MaintenanceTest` (6) | alur lengkap + biaya/waktu henti, suku cadang dari Persediaan (+ penahanan saat stok kurang), inspeksi gagal → pemeliharaan, preventif idempoten, eskalasi terlambat, verifikasi tidak dapat dilompati
`FacilityBookingTest` (6) | alur lengkap + pemanfaatan, benturan ditolak, fasilitas ditutup tidak dapat dibooking, penutupan melaporkan & memberi tahu booking terdampak, batas durasi & jam operasional, alasan ketersediaan
`AssetStockOpnameTest` (5) | daftar harapan, pindai + selisih tanpa mengubah aset, selisih lokasi/kondisi, penyesuaian hanya setelah disetujui, barang tak ditemukan bukan "hilang"
`AssetDisposalTest` (4) | penghapusan penuh tanpa menghapus baris, persetujuan wajib, aset dipinjam tidak dapat diusulkan, usulan penggantian → outbox pengadaan
`AssetSecurityTest` (6) | isolasi tenant, izin ditolak 403, akses ditolak tercatat, jejak audit immutable, ekspor tercatat, pencarian menghormati kewenangan

---

## 8. Yang **belum** ada (jujur, agar tidak dikira sudah)

1. **Antarmuka pengguna.** Seluruh 139 endpoint siap, tetapi layar React
   (halaman detail aset dengan 18 tab, dashboard aset/sarpras, pemindai QR di
   seluler) **belum dibangun**. Inilah pekerjaan berikutnya yang paling besar.
2. **Pemetaan ruang akademik.** Pemeriksaan benturan jadwal pelajaran baru
   berjalan setelah `locations.academic_room_id` diisi; bawaannya kosong dan
   dinyatakan kosong.
3. **Impor XLSX.** Validasi + pratinjau + konfirmasi sudah ada untuk CSV; XLSX
   perlu konversi lebih dulu (mis. lewat penyedia dokumen).
4. **RFID.** Kolom `assets.rfid` dan `asset_identifiers.type = RFID` tersedia,
   tetapi integrasi pembaca belum ada (dan itu pekerjaan perangkat, bukan basis data).
5. **Definisi alur persetujuan.** Kode memanggil Workflow Engine, tetapi
   *definisi* (`ASSET_DISPOSAL`, `ASSET_TRANSFER`, `ASSET_LOAN`) harus disusun
   sekolah pada modul Workflow. Tanpa definisi, permintaan menunggu — dan itu
   perilaku yang dipilih dengan sadar.
6. **Modul Vendor.** `vendor_id` disimpan sebagai rujukan tanpa kunci asing
   (tabel `vendors` belum ada; yang ada `finance_vendors`).
7. **Modul Pengadaan.** Permintaan penggantian masuk outbox; belum ada modul
   Pengadaan yang mengonsumsinya secara otomatis.
8. **Laporan berat.** Laporan dihitung langsung dari tabel operasional; untuk
   puluhan ribu aset perlu tabel ringkasan (agregat harian sudah ada untuk
   pemanfaatan fasilitas).
9. **Antrean pekerjaan.** Otomasi berjalan sebagai perintah terjadwal yang
   sinkron; untuk sekolah besar perlu dipecah ke job antrean (titik pecahnya
   sudah jelas: `AssetSendRemindersCommand`).

