# Analisis Cakupan Mobile — semua modul × (wali murid · siswa · karyawan)

Status per sel: ✅ API peran-terbatas sudah ada · 🟡 ada tetapi perlu adaptasi/verifikasi · 🔴 belum ada (perlu dibuat) · — bukan untuk peran ini.
Kolom "Mobile" = kondisi aplikasi `mobile/` saat ini (v1: Beranda, Akademik, Keuangan, Presensi, Kabar, Akun).

## 1. Matriks modul

| Modul | Wali murid | Siswa | Karyawan | Mobile v1 |
|---|---|---|---|---|
| Akademik: jadwal, kalender | ✅ jadwal · 🟡 `academic-calendar` | ✅ · 🟡 | ✅ jadwal mengajar | jadwal saja |
| Absensi siswa | ✅ | ✅ | ✅ input `student-attendances/bulk`, `/sheet` | baca saja |
| Absensi pegawai | — | — | ✅ + koreksi `attendance-corrections` | check-in/out + riwayat |
| Penilaian & rapor | ✅ | ✅ | ✅ input nilai, RPP, generate/approve rapor | baca saja |
| Tugas (`assignments`) | 🟡 `students/{id}/assignments` | 🟡 kumpul `submissions` | ✅ buat/nilai | — |
| Pengajaran (RPP, jurnal) | — | — | ✅ `lesson-plans`, `teaching-journals/today` | — |
| Keuangan | ✅ portal 8 endpoint (summary, invoices, payments, receipts, receivables, payment-plans, re-reg) · 🔴 bayar online dari app | — | 🟡 ajuan/PO lewat Workflow | ringkasan + tagihan |
| Dompet (wallet) | 🔴 hanya admin (`wallet.view`) — tak ada portal; top-up gateway sudah ada | 🔴 | — | — |
| Asrama | ✅ portal 10 endpoint (baca) · 🔴 ajukan izin/pulang | 🔴 tak ada tampilan santri sendiri | ✅ musyrif (absen, pelanggaran, izin) | ringkasan saja |
| Tahfiz | 🟡 `/dashboard/wali` (lihat §2-C) | ✅ `/dashboard/peserta`, progres | ✅ guru/musyrif, setoran | — |
| Perpustakaan | 🟡 OPAC publik; pinjaman/reservasi anak? | ✅ `members/me/card`, reservasi, OPAC | ✅ sirkulasi (scan) | — |
| Aktivitas/Ekskul | ✅ `portal/parent`, persetujuan | ✅ `my-programs`, daftar, portofolio | ✅ pembina: absen, penilaian | — |
| BK | ✅ `portal/*`, persetujuan, umpan balik wali | ✅ permintaan, umpan balik siswa | ✅ konselor (kasus, janji temu) | — |
| Klinik / UKS | ✅ `clinic/portal/*` · 🟡 UKS (izin/surat) | ✅ `clinic.portal.view` | ✅ petugas: antrean, kunjungan | — |
| LMS | ✅ `lms/parent/*`, `children/{id}/grades` | ✅ ±30 endpoint `my/*` (kursus, kuis, forum, sertifikat, gamifikasi) | ✅ guru (374 rute; subset mobile: nilai, pengumuman, forum) | — |
| CBT (ujian) | — | ✅ `my/sessions`, `attempts/*` | ✅ pengawas `proctor/monitor` | — |
| PPDB | 🟡 auth orang tua terpisah (`ppdb/auth`) | — | ✅ panitia | — |
| Aset & Sarpras | — | — | ✅ pinjam aset, booking fasilitas, lapor rusak, work order teknisi | — |
| Supervisi | — | — | ✅ guru: `visits/acknowledge`, PIP; kepsek/pengawas | — |
| Workflow/Approval | — | — | ✅ `inbox/approvals`, `inbox/requests`, decide — **inti pekerjaan karyawan** | — |
| Kepegawaian: cuti, slip gaji | — | — | 🔴 tidak ada modul cuti/payroll (hanya master `leave_types`) | — |
| Notifikasi | 🟡 | 🟡 | 🟡 | hanya tabel `notifications` |
| Dasbor manajemen | — | — | 🟡 kepala sekolah/bendahara: KPI ringkas | — |

## 2. Temuan lintas modul (menentukan desain)

**A. Satu akun = satu set permission.** Role Spatie menempel pada akun, bukan pada peran aktif.
Guru yang juga wali murid memegang `guru` + `wali-murid` sekaligus, jadi di mode wali ia akan lolos
permission pegawai, dan di mode karyawan lolos permission wali. v1 menutup ini hanya dengan allowlist
URL (`EnforceContext`), yang berarti **mode wali/siswa belum bisa memakai endpoint modul** —
hanya `mobile/*`. Perlu **resolusi permission per konteks**: di `ctx:guardian` hanya permission role
`wali-murid`; di `ctx:student` hanya `siswa`; di `ctx:employee` semua role kecuali keduanya.

**B. Pengenalan "siapa wali siapa" terpecah di ≥4 cara.**
| Modul | Mekanisme |
|---|---|
| Keuangan, Asrama | `ppdb_parent_accounts.user_id → guardian_id → student_guardians` |
| Aktivitas, UKS, Klinik | `users.userable = Guardian` |
| LMS | `userable` + role (`ErpUserProvider`) |
| Tahfiz wali | `Guardian::where('user_id', …)` — tabel `guardians` **tidak punya kolom `user_id`** (kemungkinan bug; perlu dikonfirmasi) |
| BK | `student_guardians` via provider |

Akibatnya wali yang ditautkan lewat `user_identities` (v1) **tidak dikenali** modul-modul itu. Perlu
satu resolver (`ContextService`) yang dipakai semua provider, atau satu tautan kanonik.

**C. Notifikasi terpecah.** Laravel `notifications` (UKS, Aktivitas, LMS, Asset, Perpustakaan), tabel
Tahfiz sendiri, notifikasi Keuangan sendiri, Workflow. Inbox v1 hanya membaca satu tabel → perlu agregator.

**D. Aksi tulis yang hilang untuk wali/siswa**: bayar tagihan online, ajukan izin/pulang santri,
dompet (saldo, riwayat, top-up) dan kartu santri. Kebanyakan baru bisa dibaca.

**E. Modul yang tidak ada**: cuti/izin pegawai dan slip gaji. Bukan soal mobile — butuh modul backend baru.

**F. Ujian CBT di HP** punya risiko integritas (pindah aplikasi, screenshot). Endpoint `heartbeat`/`incidents`
sudah ada, tetapi tetap keputusan kebijakan sekolah.

**G. Berkas**: kuitansi, rapor, kartu perpustakaan, sertifikat berbentuk PDF/unduhan →
perlu `expo-file-system` + berbagi. Unggah (bukti top-up, tugas, lampiran Workflow) → `expo-image-picker`/`document-picker`.

## 3. Arsitektur yang diusulkan

**Backend**
1. `ContextService` jadi sumber tunggal identitas; adaptasi `PpdbParentAccount::childStudentIds`,
   provider Aktivitas/Klinik/BK/UKS/LMS, dan perbaiki Tahfiz agar semuanya membaca satu sumber.
2. Permission per konteks (override `User::hasPermissionTo` membaca konteks dari token).
3. `EnforceContext` berubah dari "hanya `mobile/*`" menjadi **allowlist per modul & peran**
   (`config/mobile.php`: mis. wali → `finance/portal/*`, `boarding/portal/*`, `clinic/portal/*`, `bk/portal/*`, …).
   Kepemilikan data tetap ditegakkan modulnya (`assertChild`), konteks hanya pagar kasar.
4. Endpoint baru: dompet portal, ajuan izin santri, bayar tagihan, agregator notifikasi.
5. (Terpisah) modul cuti & payroll bila diinginkan.

**Mobile**
- **Registry fitur**: tiap modul mendaftar `{ key, roles, icon, routes }`; tab "Layanan" menampilkan grid
  fitur sesuai peran aktif, 4 tab inti tetap (Beranda · Layanan · Kabar · Akun).
- Komponen generik (daftar, detail, form, status, filter bulan, unduh/berbagi) agar 20+ modul tidak
  menjadi 20 gaya berbeda.
- Satu lapisan API per modul + React Query; tipe diambil dari kontrak backend.

## 4. Urutan pengerjaan yang disarankan

| Fase | Isi | Alasan |
|---|---|---|
| 0 | Fondasi: resolver identitas tunggal, permission per konteks, allowlist per modul, agregator notifikasi, registry fitur + tab Layanan | Tanpa ini tiap modul menambah celah/duplikasi |
| 1 | **Karyawan inti**: Workflow approval/pengajuan, jurnal & RPP, input absensi siswa, input nilai, tugas, aset (lapor/pinjam/booking), supervisi | Nilai terbesar harian guru/staf |
| 2 | **Wali**: keuangan penuh + bayar, asrama + izin, tahfiz, aktivitas + persetujuan, klinik/UKS, BK, dompet | Kebutuhan utama orang tua |
| 3 | **Siswa**: LMS, tugas kumpul, perpustakaan, tahfiz, aktivitas, BK, kartu | |
| 4 | CBT, PPDB, dasbor manajemen, PDF/unduhan | Risiko/kebijakan lebih tinggi |
| — | Cuti & slip gaji | Modul backend baru, bukan mobile |

## 5. Keputusan yang dibutuhkan
1. Urutan fase di atas disetujui? 2. CBT di HP diizinkan (dengan kunci/ pemantauan) atau hanya pantau jadwal/hasil?
3. Bayar online memakai gateway yang sudah ada (`PaymentGateway`)? 4. Modul cuti/payroll dibangun sekarang atau ditunda?
5. Tahfiz wali: dikonfirmasi bug `guardians.user_id` lalu diperbaiki di Fase 0.
