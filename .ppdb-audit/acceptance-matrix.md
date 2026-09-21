# Matriks penerimaan fitur PPDB (§42) — diuji terhadap kode FINAL

Tanggal: 2026-09-21. Setiap baris menuntut bukti bahwa **workflow benar-benar
berjalan** (tes HTTP-driven atau pemanggilan live), bukan sekadar rute/tabel/UI
ada. Verdict: PASS / PARTIAL / GAP / BROKEN / NEEDS_DECISION.

| # | Area | Verdict | Bukti |
|---|------|---------|-------|
| 1 | Halaman publik (daftar periode + detail slug) tanpa login | PASS | `daftar_periode_publik_tanpa_login_dan_mengabaikan_draft`, `halaman_publik_membawa_penawaran_biaya_syarat_dan_jadwal`; live `GET /api/v1/ppdb/public` → 200 berisi `PPDB-2026` |
| 2 | Periode: CRUD, jendela waktu, publish | PASS | `periode_draft_scheduled_dan_slug_asing_balas_404` + `POST /ppdb/periods/{period}/publish` |
| 3 | Program & gelombang + kuota dua sumbu | PASS | `PpdbAnnouncementService::quotaUsage()` (axes program + wave), `halaman_publik_menampilkan_sisa_kuota_sebenarnya` |
| 4 | Konfigurasi biaya per program/gelombang | PASS | `PpdbConfigTest` (fees 5 verba), `biaya_yang_sudah_ditagih_tidak_muncul_di_tagihan_kedua` |
| 5 | Form builder: field, seksi, urutan, tipe | PASS | `PpdbConfigTest` (`form-fields` + `reorder`), `skenario_c_formulir_dinamis_…` |
| 6 | Pertanyaan dinamis + penyimpanan jawaban | PASS | `PpdbConfigTest` (questions), `skenario_c_…`, payload `answers = {fields,questions,list}` |
| 7 | Syarat dokumen (format, ukuran, wajib) | PASS | `PpdbConfigTest` (document-requirements), `laporan_dokumen_menampilkan_syarat_yang_belum_ada` |
| 8 | Pendaftaran akun mandiri orang tua | PASS | `pendaftaran_mandiri_membuat_user_guardian_dan_satu_akun`, `…menolak_email_ganda_kredensial_lemah`, `…di_throttle_10_per_menit` |
| 9 | Satu akun orang tua ⇒ banyak anak, tanpa user baru | PASS | `skenario_b_satu_wali_mendaftarkan_dua_anak_tanpa_menambah_user` |
| 10 | Calon siswa: daftar + detail + ubah + soft delete (admin) | PASS | `GET /ppdb/applicants/{id}` baru + assertion smoke; gate FE `ppdb.update`/`ppdb.delete` = gate rute |
| 11 | Draft pendaftaran & kelengkapan (blockers) | PASS | `alur_penuh_orang_tua_mendaftar_sampai_anak_menjadi_siswa`, payload `blockers/progress` |
| 12 | Submit dengan gerbang kelengkapan | PASS | `skenario_d_verifikasi_bertahap_…`, `POST /ppdb/portal/registrations/{id}/submit` |
| 13 | Berkas benar-benar tertulis + berversi, riwayat tak tertimpa | PASS | `PpdbScenarioTest:408-409,470-471` (`Storage::disk('public')->exists`), `PpdbWorkflowTest:164` |
| 14 | Verifikasi petugas: verify / reject / need_revision / balik ke submitted | PASS | `skenario_d_verifikasi_bertahap_dan_detour_need_revision_kembali_ke_submitted` |
| 15 | Tagihan biaya pendaftaran otomatis & anti ganda | PASS | `biaya_yang_sudah_ditagih_tidak_muncul_di_tagihan_kedua`, `GET /ppdb/portal/registrations/{id}/payment` |
| 16 | Pembayaran manual + bukti + verifikasi/penolakan bendahara | PASS | `skenario_e_pembayaran_manual_bertahap_penolakan_dan_jalur_midtrans`, `…/payments/{payment}/verify\|reject` |
| 17 | Midtrans sandbox: snapshot → settlement + penanda tanganan callback | PASS | `skenario_e2_midtrans_sandbox_dari_snap_sampai_settlement`; `MIDTRANS_*` didokumentasikan |
| 18 | Siklus hidup tagihan: expired / cancel / refund + scheduler | PASS | `PpdbInvoiceLifecycleTest` (7 tes), `Schedule::ppdb:expire-invoices` harian 12:30 |
| 19 | Tahap seleksi + bobot wajib tepat 100% | PASS | `bobot_tahap_ditolak_sampai_totalnya_tepat_seratus`, `skor_gabungan_menolak_dihitung_dari_bobot_yang_belum_seratus` |
| 20 | Nilai per tahap, skor gabungan, ranking | PARTIAL | berjalan & tercatat; `buildComposite()` mengekstrapolasi dari bobot yang *sudah* dinilai — aturan pembulatan ini keputusan pemilik |
| 21 | Penjaga kuota + override berpermission + alasan wajib | PASS | `penetapan_lulus_di_atas_kuota_butuh_hak_dan_alasan`, `promosi_daftar_tunggu_tetap_lewat_penjaga_kuota`; 422 tanpa alasan, 403 tanpa `ppdb.registration.override_quota` |
| 22 | Pengumuman hasil & hak lihat pemilik saja | PASS | `orang_tua_hanya_melihat_pendaftaran_miliknya`, `skenario_g_hasil_terkunci_…` |
| 23 | Masa sanggah: jendela waktu & satu sanggahan pending | PASS | `masa_sanggah_tutup_menolak_sanggahan` |
| 24 | Sanggah diterima TIDAK meloloskan otomatis | PASS | `sanggah_diterima_hanya_membuka_keputusan_panitia` (status balik ke `selection`, hasil tak berubah) |
| 25 | Daftar ulang: buka (idempoten) + tagihan + kabari orang tua | PASS | `skenario_g_…`, `openReRegistration()` idempoten + `TYPE_RE_REGISTRATION_OPEN` |
| 26 | Selesai daftar ulang hanya bila lunas | PARTIAL | `daftar_ulang_belum_selesai_selama_tagihan_belum_lunas` hijau, tapi aksinya hanya lewat petugas (`ppdb.registration.manage`) — apakah orang tua boleh menandai sendiri = keputusan pemilik |
| 27 | Konversi calon → siswa (NIS penomoran existing, match NISN, tanpa duplikat) | PASS | `skenario_g_…konversi_dan_laporan`, `PpdbConversionService` memulihkan baris soft-delete alih-alih menyisipkan ganda |
| 28 | Notifikasi in-app terisolasi per pengguna | PASS | `kotak_masuk_notifikasi_terisolasi_per_pengguna` |
| 29 | Riwayat/audit setiap perpindahan status | PASS | trait `LogsPpdbActivity` + `activity('ppdb')`, `GET /ppdb/registrations/{id}/history`, `reset_password_sekali_pakai_dan_tercatat_di_audit` |
| 30 | Dashboard panitia + laporan + CSV Excel + PDF | PASS | `PpdbReportTest` 22 tes (`ekspor_csv_berbom_…`, `ekspor_csv_melindungi_sel_berkoma_dan_formula`, `pdf_setiap_jenis_ringan_dan_terbaca`) |
| 31 | RBAC tanpa bocor izin + gerbang FE cocok dengan rute | PASS | `PpdbLookupTest` (4), `GET /ppdb/lookups`; 19 permission gate di 121 rute; menu/rute panitia-ppdb cocok |
| 32 | Verifikasi visual browser + responsif | NEEDS_DECISION | butuh keputusan kredensial dev (DB dev hanya punya satu user `super-admin` tanpa password diketahui) — tidak dijalankan tanpa izin pemilik |

**Rekap: 29 PASS · 2 PARTIAL · 0 GAP · 0 BROKEN · 1 NEEDS_DECISION (32 baris).**
