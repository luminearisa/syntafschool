# Mobile Fase 1 — fitur karyawan & temuan alur backend

Fitur yang kini ada di aplikasi (peran **karyawan**): Persetujuan (inbox approval + pengajuan saya),
Jurnal Mengajar & RPP, Absensi Siswa, Input Nilai, Tugas (buat/terbit/nilai), Aset & Sarpras
(lapor kerusakan lewat kode label, booking fasilitas). Supervisi belum (lihat "Belum dikerjakan").

## Alur backend yang dibaca dan dipakai apa adanya
| Fitur | Endpoint | Aturan yang dihormati |
|---|---|---|
| Approval | `workflows/inbox/{approvals,requests,counter}`, `approvals/{id}/{approve,reject,return}` | `Idempotency-Key` per keputusan; komentar wajib untuk reject/return/request-changes; keputusan hanya pada penugasan milik sendiri |
| Pengajuan saya | `instances/{id}` (+`cancel`, `revise`, `comments`) | batal & kirim ulang wajib alasan; `can_cancel`/`can_resubmit` dari server |
| Jurnal | `teaching-journals/today`, `POST teaching-journals`, `…/{id}/complete` | guru harus mengampu mapel di kelas, tanggal dalam semester, JP ≤ alokasi, satu jurnal per jam |
| RPP | `lesson-plans` (+`submit`/`approve`/`reject`) | draft→submitted→approved/rejected; isi hanya bisa diubah saat draft/rejected |
| Presensi siswa | `student-attendances/sheet`, `…/bulk` | hari efektif, anggota kelas aktif, jenis izin wajib untuk S/I, presensi terkunci → koreksi |
| Nilai | `assessments`, `…/sheet`, `…/scores/bulk`, `…/submit` | nilai ≤ maks; setelah diserahkan wajib alasan; final = terkunci; serah tak lengkap perlu `force`+alasan |
| Tugas | `assignments`, `…/submissions`, `…/grade`, `publish`, `close` | nilai ≤ maks tugas; hanya yang sudah mengumpulkan yang dinilai |
| Aset | `facilities`, `facilities/{id}/availability`, `…/book`, `facility-bookings` | bentrok jadwal ditolak server (409), booking bisa perlu persetujuan |

## Temuan & perbaikan di backend
1. **FK salah `recorded_by`** — `student_attendances`/`employee_attendances` mengarah ke `employees`,
   padahal kode & model mengisi ID **akun** → pelanggaran FK atau pencatat salah. Migrasi memindahkannya ke `users`.
2. **IDOR nilai siswa** — `students/{id}/scores`, `…/assignments`, `attendance-recap/student/{id}` hanya dijaga
   permission `*.view` yang juga dimiliki `wali-murid`/`siswa`: wali bisa membaca siswa mana pun lewat id.
   Ditambah middleware `student.family`: akun yang seluruh rolenya wali/siswa hanya boleh dirinya/anaknya (404 selain itu).
3. **Guru bisa mengubah kelas guru lain** — presensi dan nilai hanya dijaga `academic.attendance`/`academic.grade`.
   `TeachingAccess` membatasi guru (tertaut pegawai, `is_teacher`, bukan pengelola) ke kelas/penilaian/tugas yang ia ampu.
   Staf non-guru, admin, kepala sekolah tidak berubah.
4. **Guru tak bisa membuat tugas** — rute tugas mensyaratkan `academic.create/update/publish` yang tidak dimiliki
   `guru`. Rute kini juga menerima `academic.grade` (pola yang sama dengan RPP), dengan kepemilikan ditegakkan di controller.
5. **Role pegawai tanpa hak Workflow** — `guru`, `wali-kelas`, `staf` dan sejenisnya tidak punya izin inbox sama sekali.
   Seeder kini memberi `view/submit/cancel` permintaan dan `approval.view/decide` (penugasan tetap disaring `approver_id`),
   **tanpa** `workflow.request.create` (membuat request atas nama orang lain lewat `requester_id` tetap hak admin).
6. **Role pegawai tanpa hak Aset** — modul Aset memakai role sendiri (ASSET_CUSTODIAN, dst.). Warga sekolah kini mendapat
   `asset.maintenance.request`, `facility.view`, `facility.book`, `location.view` (tanpa `asset.view`).
7. **Pencarian QR publik tak mengembalikan id aset**, sehingga aplikasi tak bisa memanggil `assets/{id}/damage`.
   Ditambah `POST mobile/employee/damage-report` yang menyelesaikan kode di server.
8. Aplikasi: `Alert.alert` adalah no-op di web — diganti pembungkus `@/lib/dialog` (konfirmasi keluar/setujui kini jalan di web).

## Belum dikerjakan / keputusan
- **Supervisi** (modul instrumen observasi, offline sync): perlu fase tersendiri; sisi guru (baca laporan, acknowledge/dispute,
  rencana aksi) lebih dulu, sisi pengawas (isi formulir) kemudian.
- **Ajukan cuti/izin dari app**: definisi `LEAVE_REQUEST_APPROVAL` sudah ada, tetapi tak ada formulir cuti ataupun efek ke presensi
  saat disetujui; butuh keputusan (konteks `department` dari direktori organisasi, penjatahan cuti).
- `assignments/{id}/submissions` mendaftar anggota kelas tanpa menyaring status `aktif` (siswa pindah/keluar ikut tampil).
- `student:view` pada role `wali-murid`/`siswa` memungkinkan daftar seluruh siswa lewat `GET /students` pada token web;
  disarankan dicabut dari seeder + dipindah ke portal khusus (tidak diubah di sini karena bisa memutus layar web yang ada).
- Pemindai kamera untuk kode label (kini ketik manual).
