# Mobile — Fase 5

## Isi
- **Supervisi (karyawan)** `/supervisi`: tab "Perlu saya nilai" (sebagai supervisor, status Draf–Observasi) dan "Hasil saya" (sebagai yang disupervisi). Formulir per indikator: skala, ya/sebagian/tidak, pilihan ganda, angka, teks, narasi, tanggal, tidak berlaku, catatan. Jawaban disimpan otomatis (`PUT visits/{id}/answers`), skor sementara dari server, kirim untuk analisis (`submit`, server memeriksa indikator wajib). Hasil final: skor, predikat, kekuatan, temuan, dan "Tandai sudah membaca" (`acknowledge`).
- **Pembahasan CBT (siswa)**: tombol "Lihat pembahasan" pada ujian yang nilainya sudah dirilis (`cbt/attempts/{id}/review`; server menahan penjelasan sebelum rilis).
- Tanpa perubahan backend: modul Supervisi sudah berbasis izin (`supervisi.*`) dan identitas pegawai dari peran aktif.

## Sengaja ditunda (butuh keputusan/desain backend)
- **UKS untuk wali/siswa** — modul UKS tidak punya portal; memberi izin `uks.*` ke wali-murid akan membuka data siswa lain. Perlu endpoint portal ber-pagar kepemilikan seperti Klinik.
- **PPDB** — memakai akun orang tua PPDB dengan login terpisah (bukan akun terpadu); perlu keputusan apakah ditautkan ke satu akun.
- **Pemindai kamera, cuti & slip gaji, Tahfiz/Ekskul staf, lampiran bukti Supervisi/tugas** — belum dikerjakan.
- Tes backend Fase 4 & 5 belum dijalankan (PHP 8.3 di sandbox).
