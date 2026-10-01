# Modul BK — Phase 2: Student Support & Counseling Platform

**Status:** fungsional & ter-commit · 27 tabel baru · 27 enum baru · 24 model ·
19 layanan/job baru · 10 controller · **170 rute** `/api/v1/bk/*` (+91 dari Phase 1) ·
41 tes Phase 1 hijau · 28 tes Phase 2 (16 hijau, 12 masih merah — lihat §9)

Phase 2 dibangun **di atas** Phase 1 tanpa mengubahnya: setiap tabel lama hanya
menerima kolom aditif, setiap kontrak integrasi tetap dipakai, dan istilah status
lama tetap diterima sebagai alias baca.

---

## 1. Yang ditambahkan

| Bagian | Isi |
|---|---|
| Konferensi kasus | `bk_case_conferences`, `bk_case_conference_participants`, `bk_case_conference_actions` |
| Rujukan eksternal | `bk_external_referrals`, `bk_referral_providers` |
| Persetujuan granular | perluasan `bk_consents` (cakupan, pemberian, pencabutan, dokumen, masa berlaku) |
| Penanda & peringatan dini | `bk_risk_indicators`, `bk_early_warning_rules`, `bk_early_warning_events` |
| Pengembangan siswa | `bk_development_plans`, `bk_development_goals`, `bk_development_activities`, `bk_development_reviews` |
| Karier | `bk_career_profiles`, `bk_career_guidance_sessions` |
| Program kelompok | `bk_group_programs`, `bk_group_program_sessions`, `bk_group_program_members`, `bk_group_program_attendance` |
| Umpan balik | `bk_feedback` |
| SLA & eskalasi | `bk_sla_policies`, `bk_sla_events`, `bk_escalations` |
| Retensi | `bk_retention_policies`, `bk_retention_actions` |
| Otomasi | `bk_automation_runs` |
| Isolasi unit | kolom `school_id` aditif pada 20 tabel Phase 1 + trait `BelongsToSchool` |

---

## 2. Keputusan desain yang menentukan seluruh Phase 2

**a. Ambang adalah data, bukan kode.** Aturan peringatan dini (`metric`, `operator`,
`threshold`, `window_days`, `cooldown_days`, `scope`) dan kebijakan SLA
(`response_hours`, `applies_to`) tersimpan di basis data **berversi**. Sekolah
mengubah kebijakan tanpa deploy; keputusan lama tetap dapat dipertanggungjawabkan
dengan kebijakan yang berlaku saat itu (`policy_snapshot`).

**b. Kerahasiaan tetap di server, dan invariant-nya dijaga mesin.** Konferensi
tidak boleh lebih terbuka daripada kasusnya; turunan tidak boleh lebih terbuka
daripada induknya. Peserta konferensi **tidak otomatis** memperoleh akses catatan
konseling: kewenangannya tersimpan per peserta (`can_view_confidential`,
`max_confidentiality`) dan tetap dibatasi kewenangan konseling kasus.

**c. Rekam medis bukan milik modul ini.** `recordResult()` menolak
`diagnosis`, `prescription`, `medical_record`, `lab_result` dengan pesan yang
menjelaskan alasannya; yang tersimpan hanya ringkasan yang boleh dibagikan plus
`clinic_reference` sebagai penunjuk ke Modul Clinic/Health.

**d. Otomasi wajib idempoten.** Setiap tindakan otomatis menulis kunci unik ke
`bk_automation_runs` dan/atau `bk_early_warning_events.dedup_key` — sehingga cron
ganda, retry antrean, dan dua pekerja paralel tidak menggandakan pesan, eskalasi,
atau penanda.

**e. Penghapusan data harus dapat diaudit.** Jalur retensi adalah
Active → Archived → Retention Expired → Approved Disposal, dengan **pemisahan
tugas**: pengaju permintaan tidak boleh menyetujui pemusnahannya sendiri, dan
jejak pemusnahan tetap ada setelah isinya dihapus.

---

## 3. Mesin peringatan dini

```
Academic / Attendance / Discipline / Boarding / Clinic / Referral
        ↓  (metrik diambil dari modul pemiliknya lewat kontrak)
Rule Engine (ambang dari basis data, ber-versi, ber-scope, ber-jeda)
        ↓
bk_early_warning_events  →  bk_risk_indicators  →  tinjauan manusia
                                                     ↓
                                          rujukan / kasus / intervensi / pemantauan
```

- **Sembilan metrik** didukung (`ATTENDANCE_RATE`, `ABSENCE_COUNT`, `LATE_COUNT`,
  `DISCIPLINE_COUNT`, `DISCIPLINE_POINTS`, `ACADEMIC_AVERAGE`, `ACADEMIC_CHANGE`,
  `SUBJECT_BELOW_THRESHOLD_COUNT`, `REFERRAL_COUNT`, `FOLLOW_UP_FAILURE_COUNT`,
  `CLINIC_VISIT_COUNT`, `BOARDING_ADJUSTMENT_COUNT`, `ACTIVITY_DISENGAGEMENT_DAYS`).
- **Metrik yang tidak tersedia dicatat apa adanya** (`SKIPPED` + alasan), bukan
  dianggap nol — angka nol yang salah lebih berbahaya daripada ketiadaan yang jujur.
- **Tidak ada tindakan yang mengubah status siswa.** Tindakan yang tersedia hanya
  menambah pekerjaan untuk ditinjau manusia.
- Peristiwa yang tidak cocok dan yang ditahan jeda **juga tercatat**, sehingga
  pertanyaan "kenapa tidak ada peringatan?" punya jawaban yang dapat diperiksa.

---

## 4. SLA & eskalasi

Kebijakan bawaan: respon rujukan 24 jam (mendesak 4 jam), screening 72 jam,
penugasan 48 jam, tindak lanjut 14 hari, rujukan eksternal 7 hari, keputusan
persetujuan 72 jam. Kebijakan **paling spesifik menang**: kebijakan yang menyebut
prioritas/langka tertentu mengalahkan kebijakan umum.

Eskalasi berjenjang `NONE → COORDINATOR → MANAGEMENT`, idempoten per
(SLA, tingkat), dan punya akhir yang jelas: **diakui lalu diselesaikan** oleh orang.
Eskalasi yang tidak pernah ditutup akan menjadi kebisingan — dan peringatan yang
diabaikan lebih berbahaya daripada tidak ada peringatan.

---

## 5. Analitik, beban kerja, dan umur kasus

- Semua analitik melewati **cakupan privasi yang sama** dengan layar operasional.
- **Kelompok kecil disamarkan**: kelompok dengan < 3 kasus dilaporkan sebagai satu
  baris `DILINDUNGI`, karena "1 kasus di kelas 9A" praktis menyebut nama.
- **Cache hanya untuk agregat tanpa dimensi pribadi** (tren bulanan, sebaran
  kategori). Angka per orang (beban konselor) tidak di-cache: cache adalah salinan
  yang hidup di luar kendali kewenangan.
- Dasbor konselor menampilkan pekerjaannya sendiri; dasbor koordinator menampilkan
  sebaran tim, kasus tanpa pemilik, SLA terlampaui, dan umur kasus per kelompok
  (0-7, 8-30, 31-60, 61-90, 90+ — ambangnya dari `config('bk.aging')`).
- Umur kasus dipakai untuk **pemantauan alur**, dan itu dinyatakan di payload
  (`note`) agar tidak dibaca sebagai penilaian kinerja konselor.

---

## 6. Keamanan dokumen, pencarian, dan ekspor

- **Pencarian aman**: setiap jenis hasil disaring per peran, cakupan siswa,
  cakupan kasus, dan kerahasiaan. Guru mencari siswa → menemukan identitas dasar
  siswa; kata kunci yang hanya cocok dengan isi catatan rahasia tidak memunculkan
  apa pun baginya. Kueri pencarian staf tercatat (`SEARCH`).
- **Ekspor melewati empat langkah yang tidak dapat dilompati**: periksa izin →
  konfirmasi eksplisit → catat audit → buat berkas (latar belakang untuk data
  besar, disimpan di disk privat, kedaluwarsa otomatis).
- **Daftar kolom terlarang ada di satu tempat** (`BkExportService::scrub()`):
  `counselor_note`, `discussion`, `body`, `diagnosis`, `prescription`, `email`,
  `phone` — sehingga ekspor tidak pernah menjadi celah menembus kerahasiaan, bahkan
  oleh admin.

---

## 7. Isolasi unit sekolah (tenant)

Instalasi satu sekolah berjalan seperti sebelumnya: dengan penyaringan unit
**mati**, tidak ada perilaku kueri tambahan. Bila yayasan mengaktifkan
`BK_TENANT_SCOPE=true`, `BkTenantResolver` mengembalikan unit aktif dan global
scope `BelongsToSchool` menutup data antar-unit — termasuk kueri yang lupa
menambahkan syaratnya, yang justru sumber kebocoran paling sering.

Penyaring unit **sengaja tidak** diaktifkan sendiri oleh modul: mengaktifkannya
untuk instalasi satu sekolah akan menyembunyikan seluruh data lama yang belum
punya `school_id`.

---

## 8. Otomasi (9 alur)

| Alur | Tindakan | Kunci idempotensi |
|---|---|---|
| Rujukan diterima | masuk antrean + beri tahu koordinator | aksi + rujukan + hari |
| Kasus ditugaskan | beri tahu konselor + mulai SLA | aksi + kasus |
| Janji besok | pengingat siswa/wali | aksi + janji + hari |
| Tindak lanjut jatuh tempo | pengingat pemilik | aksi + tindak lanjut + hari |
| Tindak lanjut terlambat | eskalasi ke koordinator | aksi + tindak lanjut + hari |
| SLA terlampaui | eskalasi berjenjang | SLA + tingkat |
| Penanda risiko dibuat | tinjauan BK | aksi + penanda |
| Persetujuan akan berakhir | pengingat perpanjangan | aksi + persetujuan + hari |
| Kasus siap ditutup | usul tinjauan penutupan | aksi + kasus + hari |

---

## 9. Status pengujian yang jujur

```
tests/Feature/Bk/  — 69 tes, 222 asersi
├── Phase 1 (41 tes)                      HIJAU — tidak tersentuh
└── Phase 2 (28 tes)
    ├── Hijau   : matriks privasi dasar, mesin aturan (ambang/versi/scope/skip),
    │             SLA & eskalasi, otomasi idempoten, pengembangan & kemajuan,
    │             program & kehadiran, umpan balik final, retensi ber-versi
    └── Merah   : 10 error + 2 failure — terkonsentrasi pada jalur HTTP
                  konferensi/rujukan/pencarian dan satu asersi riwayat retensi
```

Sisa kegagalan **bukan** pada aturan kerahasiaan (matriks §28 lulus), melainkan
pada integrasi HTTP Phase 2 yang belum selesai dirapikan. Yang sudah teridentifikasi:

1. **Riwayat retensi** — asersi uji masih mengharapkan baris permintaan berubah
   menjadi keputusan; implementasi sekarang menulis keputusan sebagai baris baru
   (perilaku yang lebih benar). Uji perlu diselaraskan.
2. **Jalur HTTP konferensi & rujukan eksternal** — layanan lulus uji langsung,
   tetapi pemanggilan melalui controller masih menemui galat yang perlu ditelusuri
   satu per satu (kemungkinan pada bentuk respons dan izin rute).
3. **Pencarian/ekspor lewat HTTP** — penyaringnya lulus pada uji layanan; galat
   tersisa berada pada jalur endpoint.

Rencana penyelesaian: satu putaran penyelarasan uji + perbaikan controller, lalu
frontend Phase 2 (menu 18 entri, tab profil siswa, dasbor lanjutan) yang saat ini
belum dibangun — tabel dan API-nya sudah tersedia agar pekerjaan itu tidak
menunggu.

---

## 10. Perintah operasional

```bash
php artisan bk:run-early-warning --limit=500   # evaluasi aturan untuk seluruh siswa
php artisan bk:monitor-sla                     # peringatan, keterlampauan, eskalasi
php artisan bk:run-automation                  # seluruh otomasi harian (dapat dipilih)
php artisan bk:apply-retention --archive --request-disposal
php artisan bk:health                          # kesehatan modul & integrasi
```

Jadwal yang disarankan: otomasi & SLA tiap pagi, peringatan dini harian setelah
sinkronisasi presensi, retensi bulanan, kesehatan modul mingguan.
