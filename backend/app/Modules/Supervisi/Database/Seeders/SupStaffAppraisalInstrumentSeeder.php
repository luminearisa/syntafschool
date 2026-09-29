<?php

namespace App\Modules\Supervisi\Database\Seeders;

use App\Modules\Supervisi\Database\Seeders\Concerns\BuildsInstruments;
use Illuminate\Database\Seeder;

/**
 * Instrumen supervisi tenaga kependidikan, penilaian kinerja (PKG/NKKS),
 * tinjauan kinerja pegawai (skala 1–5 berbobot), PIP, dan evaluasi program.
 */
class SupStaffAppraisalInstrumentSeeder extends Seeder
{
    use BuildsInstruments;

    public function run(): void
    {
        $this->tendikInstruments();
        $this->supervisiManajerial();
        $this->pkgPembelajaran();
        $this->pkgBk();
        $this->nkks();
        $this->tinjauanKinerjaPegawai();
        $this->pip();
        $this->evaluasiProgram();
    }

    /** Instrumen per jabatan tenaga kependidikan (Permendiknas 24/2008). */
    protected function tendikInstruments(): void
    {
        $jabatan = [
            [
                'code' => 'tendik-kepala-administrasi',
                'name' => 'Supervisi Kepala Tenaga Administrasi Sekolah',
                'position' => 'kepala administrasi/TU',
                'items' => [
                    'Menyusun program kerja administrasi sekolah tahunan dan semester',
                    'Membagi tugas pelaksana urusan administrasi secara jelas dan tertulis',
                    'Mengelola administrasi kepegawaian (data induk, kenaikan, cuti, penilaian)',
                    'Mengelola administrasi kesiswaan bersama wali kelas dan BK',
                    'Mengelola administrasi sarana dan prasarana termasuk inventaris',
                    'Mengelola administrasi keuangan sekolah sesuai ketentuan',
                    'Mengelola persuratan dan pengarsipan sekolah',
                    'Mengelola hubungan sekolah dengan masyarakat dan komite',
                    'Melakukan pengawasan internal terhadap pelaksana urusan',
                    'Meningkatkan kompetensi diri dan staf administrasi',
                ],
            ],
            [
                'code' => 'tendik-persuratan-arsip',
                'name' => 'Supervisi Pelaksana Urusan Persuratan & Pengarsipan',
                'position' => 'persuratan dan pengarsipan',
                'items' => [
                    'Menerima, mencatat, dan mendistribusikan surat masuk dengan tertib',
                    'Mencatat dan mengirim surat keluar sesuai prosedur',
                    'Menggunakan buku agenda/wajib surat secara terpelihara',
                    'Menata arsip aktif dan inaktif berdasarkan klasifikasi',
                    'Menjaga kerahasiaan dokumen sekolah',
                    'Menyediakan kembali arsip yang diminta dengan cepat dan tepat',
                    'Melakukan penyusutan/retensi arsip sesuai jadwal',
                    'Memanfaatkan aplikasi pengarsipan digital (bila tersedia)',
                ],
            ],
            [
                'code' => 'tendik-sarpras',
                'name' => 'Supervisi Pelaksana Urusan Sarana & Prasarana',
                'position' => 'sarana dan prasarana',
                'items' => [
                    'Menyusun rencana kebutuhan sarana dan prasarana',
                    'Mencatat penerimaan dan pengeluaran barang pada buku inventaris',
                    'Menata ruang, barang, dan lingkungan sekolah secara tertib',
                    'Melakukan pemeliharaan rutin sarana dan prasarana',
                    'Menangani kerusakan barang secara cepat dan terdokumentasi',
                    'Melakukan penghapusan barang sesuai prosedur',
                    'Menyusun laporan kondisi sarana dan prasarana berkala',
                    'Menjaga keamanan dan keselamatan penggunaan fasilitas',
                ],
            ],
            [
                'code' => 'tendik-perpustakaan',
                'name' => 'Supervisi Kepala Perpustakaan Sekolah',
                'position' => 'perpustakaan',
                'items' => [
                    'Menyusun program kerja perpustakaan dan mengelola anggarannya',
                    'Melakukan pengembangan koleksi sesuai kebutuhan kurikulum',
                    'Mengelola katalogisasi dan klasifikasi koleksi',
                    'Menyelenggarakan layanan sirkulasi dan peminjaman secara tertib',
                    'Menyelenggarakan layanan referensi dan literasi informasi',
                    'Melakukan pemeliharaan dan penyiangan koleksi',
                    'Menyusun laporan dan statistik pemanfaatan perpustakaan',
                    'Mengembangkan layanan perpustakaan digital',
                ],
            ],
            [
                'code' => 'tendik-laboratorium',
                'name' => 'Supervisi Kepala Laboratorium Sekolah',
                'position' => 'laboratorium',
                'items' => [
                    'Menyusun program kerja laboratorium dan jadwal penggunaannya',
                    'Mengelola alat dan bahan praktikum termasuk kartu stok',
                    'Menjaga keselamatan kerja dan keamanan bahan berbahaya',
                    'Menyiapkan alat/bahan untuk kegiatan praktikum guru',
                    'Melakukan perawatan dan kalibrasi alat secara berkala',
                    'Mengelola pembuangan limbah sesuai prosedur',
                    'Menyusun laporan kegiatan dan inventaris laboratorium',
                    'Membina keterampilan kerja ilmiah peserta didik di laboratorium',
                ],
            ],
            [
                'code' => 'tendik-layanan-khusus',
                'name' => 'Supervisi Petugas Layanan Khusus (Kebersihan & Keamanan)',
                'position' => 'layanan khusus',
                'items' => [
                    'Melaksanakan tugas kebersihan sesuai jadwal dan area tanggung jawab',
                    'Menjaga kebersihan dan kelayakan ruang kelas serta fasilitas umum',
                    'Mengelola sampah dan menjaga sanitasi lingkungan sekolah',
                    'Melaksanakan tugas pengamanan sekolah dan tamu',
                    'Mengelola kunci, pintu, dan area parkir',
                    'Melaporkan kejadian/kondisi tidak aman kepada pimpinan',
                ],
            ],
        ];

        foreach ($jabatan as $data) {
            $version = $this->instrument([
                'code' => $data['code'],
                'name' => $data['name'],
                'category' => 'MANAJERIAL',
                'target_type' => 'TENDIK',
                'description' => 'Instrumen supervisi manajerial untuk jabatan '.$data['position'].' berbasis tupoksi dan standar tenaga administrasi sekolah.',
                'guidance' => 'Nilai pelaksanaan tugas sehari-hari (bukan hanya dokumen). Sertakan bukti dan satu tindak lanjut.',
                'requires_action_plan' => true,
            ]);

            $bagian = $this->section($version, 'A', 'Pelaksanaan Tugas Pokok & Fungsi');
            $o = $this->scale14();

            foreach ($data['items'] as $item) {
                $this->scale($bagian, $item, 'Periksa bukti kerja dan tanyakan kendala yang dihadapi.', $o);
            }

            // Ketepatan waktu pelaporan sebagai angka langsung (contoh fleksibilitas).
            $data2 = $this->section($version, 'B', 'Data Kuantitatif Kinerja', null, 0.5);
            $this->number($data2, 'Ketepatan waktu penyampaian laporan bulanan (hari keterlambatan)', 'Semakin kecil semakin baik; tulis 0 bila tepat waktu.', [
                'score_min' => 0,
                'score_max' => 14,
                'score_step' => 1,
                'score_decimals' => 0,
                'score_unit' => 'hari',
                'direction' => 'LOWER_BETTER',
                'min_comment_below' => 3,
            ]);

            $this->publishVersion($version);
        }
    }

    /** Supervisi manajerial sekolah — 8 aspek administrasi (per buku panduan pengawas). */
    protected function supervisiManajerial(): void
    {
        $version = $this->instrument([
            'code' => 'supervisi-manajerial-sekolah',
            'name' => 'Supervisi Manajerial Sekolah (8 Aspek Administrasi)',
            'category' => 'MANAJERIAL',
            'target_type' => 'UMUM',
            'description' => 'Menelaah 8 aspek administrasi sekolah: kurikulum, kepegawaian, kesiswaan, sarpras, keuangan, humas, perpustakaan, dan program kerja sekolah.',
            'guidance' => 'Dipirkan oleh kepala sekolah/pengawas; dapat dipakai untuk supervisi manajerial lintas unit.',
        ]);

        $bagian = $this->section($version, 'A', 'Aspek Administrasi Sekolah');
        $o = $this->scale14();

        foreach ([
            'Administrasi kurikulum dan pembelajaran tersusun serta terpelihara',
            'Administrasi kepegawaian lengkap dan mutakhir',
            'Administrasi kesiswaan mendukung pembinaan peserta didik',
            'Administrasi sarana dan prasarana sesuai buku inventaris',
            'Administrasi keuangan tertib, transparan, dan akuntabel',
            'Administrasi hubungan sekolah–masyarakat dan komite berjalan',
            'Administrasi perpustakaan dan laboratorium dikelola baik',
            'Program kerja sekolah dilaksanakan dan dilaporkan sesuai rencana',
        ] as $item) {
            $this->scale($bagian, $item, 'Bandingkan dokumen dengan pelaksanaan nyata.', $o);
        }

        $this->publishVersion($version);
    }

    /** PKG — 14 kompetensi guru (maks 56). */
    protected function pkgPembelajaran(): void
    {
        $version = $this->instrument([
            'code' => 'pkg-pembelajaran',
            'name' => 'Penilaian Kinerja Guru (PKG) — Pembelajaran',
            'category' => 'PKG',
            'target_type' => 'GURU',
            'description' => 'PKG 14 kompetensi guru mata pelajaran/kelas (skor 1–4, maksimum 56).',
            'guidance' => 'Setiap kompetensi dinilai 1–4 dengan bukti (dokumen, observasi, wawancara). Nilai akhir dikonversi /56×100 dan dikoreksi kehadiran bila diaktifkan.',
            'grade_scale' => 'pkg_sebutan',
            'scoring_mode' => 'PERCENT',
        ]);

        $sections = [
            'A' => ['Kompetensi Pedagogik', [
                'Mengenal karakteristik peserta didik',
                'Menguasai teori belajar dan prinsip-prinsip pembelajaran yang mendidik',
                'Mengembangkan kurikulum yang terkait dengan mata pelajaran yang diampu',
                'Menyelenggarakan pembelajaran yang mendidik (termasuk pemanfaatan TIK)',
                'Memfasilitasi pengembangan potensi peserta didik',
                'Berkomunikasi secara efektif, empatik, dan santun dengan peserta didik',
                'Menyelenggarakan penilaian dan evaluasi proses serta hasil belajar',
            ]],
            'B' => ['Kompetensi Kepribadian', [
                'Bertindak sesuai dengan norma agama, hukum, sosial, dan kebudayaan nasional',
                'Menampilkan diri sebagai pribadi yang jujur, berakhlak mulia, dan teladan',
                'Menampilkan diri sebagai pribadi yang mantap, stabil, dewasa, arif, dan berwibawa',
                'Menunjukkan etos kerja, tanggung jawab, rasa bangga, dan percaya diri',
            ]],
            'C' => ['Kompetensi Sosial', [
                'Bersikap inklusif, bertindak objektif, serta tidak diskriminatif',
                'Berkomunikasi dengan sesama guru, tenaga kependidikan, orang tua, dan masyarakat',
            ]],
            'D' => ['Kompetensi Profesional', [
                'Berkomunikasi dengan komunitas profesi sendiri dan profesi lain secara lisan dan tulisan',
            ]],
        ];

        $o = $this->scale14();

        foreach ($sections as $code => [$title, $items]) {
            $section = $this->section($version, $code, $title);
            foreach ($items as $item) {
                $this->scale($section, $item, 'Kumpulkan bukti dari dokumen, observasi kelas, dan wawancara.', $o);
            }
        }

        $this->publishVersion($version);
    }

    /** PKG — 17 kompetensi guru BK/konselor (maks 68). */
    protected function pkgBk(): void
    {
        $version = $this->instrument([
            'code' => 'pkg-bimbingan-konseling',
            'name' => 'Penilaian Kinerja Guru (PKG) — BK/Konselor',
            'category' => 'PKG',
            'target_type' => 'GURU',
            'description' => 'PKG 17 kompetensi guru bimbingan dan konseling (skor 1–4, maksimum 68).',
            'guidance' => 'Bukti: dokumen layanan, jurnal konseling, dan hasil asesmen kebutuhan peserta didik.',
            'grade_scale' => 'pkg_sebutan',
        ]);

        $bagian = $this->section($version, 'A', 'Kompetensi Profesional & Layanan BK');
        $o = $this->scale14();

        foreach ([
            'Menguasai teori dan praksis pendidikan',
            'Mengaplikasikan perkembangan fisiologis dan psikologis serta perilaku konseli',
            'Menguasai esensi layanan BK dalam jalur, jenis, dan jenjang satuan pendidikan',
            'Menyelenggarakan layanan orientasi',
            'Menyelenggarakan layanan informasi',
            'Menyelenggarakan layanan penempatan dan penyaluran',
            'Menyelenggarakan layanan penguasaan konten',
            'Menyelenggarakan layanan konseling individual',
            'Menyelenggarakan layanan konseling kelompok',
            'Menyelenggarakan layanan bimbingan kelompok',
            'Menyelenggarakan layanan konsultasi',
            'Menyelenggarakan layanan mediasi',
            'Mengaplikasikan pendekatan, teknik, dan keterampilan konseling',
            'Menyelenggarakan layanan advokasi',
        ] as $item) {
            $this->scale($bagian, $item, 'Periksa dokumen program, jurnal layanan, dan laporan hasil.', $o);
        }

        $pengembangan = $this->section($version, 'B', 'Evaluasi & Pengembangan Diri');
        foreach ([
            'Merancang dan melaksanakan evaluasi program BK',
            'Menguasai karakteristik kepribadian dan profesionalitas konselor',
            'Mengembangkan keprofesionalan secara berkelanjutan',
        ] as $item) {
            $this->scale($pengembangan, $item, 'Periksa laporan evaluasi program dan riwayat pengembangan diri.', $o);
        }

        $this->publishVersion($version);
    }

    /** NKKS — kinerja kepala sekolah (maks 24). */
    protected function nkks(): void
    {
        $version = $this->instrument([
            'code' => 'kepala-sekolah-nkks',
            'name' => 'Penilaian Kinerja Kepala Sekolah (NKKS)',
            'category' => 'KEPALA_SEKOLAH',
            'target_type' => 'KEPALA_SEKOLAH',
            'description' => 'Enam indikator kinerja kepala sekolah (skor 1–4, maksimum 24) sesuai tugas manajerial, kewirausahaan, dan supervisi.',
            'guidance' => 'Dinilai oleh pengawas sekolah/atasan; fokus pada bukti pelaksanaan, bukan kelengkapan dokumen semata.',
            'grade_scale' => 'pkg_sebutan',
        ]);

        $bagian = $this->section($version, 'A', 'Tugas Manajerial, Kewirausahaan & Supervisi');
        $o = $this->scale14();

        foreach ([
            'Menyusun dan melaksanakan program kerja sekolah berbasis evaluasi diri',
            'Mengelola kurikulum dan pembelajaran secara efektif',
            'Mengelola kepegawaian dan pengembangan profesionalisme guru/tendik',
            'Mengelola sarana-prasarana dan keuangan sekolah secara akuntabel',
            'Melaksanakan supervisi akademik dan manajerial secara terprogram',
            'Mengembangkan kewirausahaan/unit usaha dan kemitraan sekolah',
        ] as $item) {
            $this->scale($bagian, $item, 'Bandingkan program, laporan, dan bukti pelaksanaan di lapangan.', $o);
        }

        $this->publishVersion($version);
    }

    /** Tinjauan kinerja pegawai: skala 1–5, berbobot, multi-sumber (opsional). */
    protected function tinjauanKinerjaPegawai(): void
    {
        $version = $this->instrument([
            'code' => 'tinjauan-kinerja-pegawai',
            'name' => 'Tinjauan Kinerja Pegawai (Skala 1–5 Berbobot)',
            'category' => 'KINERJA_PEGAWAI',
            'target_type' => 'UMUM',
            'description' => 'Penilaian kinerja berbobot untuk guru/tendik: hasil kerja, perilaku kerja (nilai dasar), dan kompetensi teknis.',
            'guidance' => 'Bobot: hasil kerja 50%, perilaku kerja 30%, kompetensi teknis 20%. Dapat dinilai atasan, rekan, dan/atau diri sendiri sesuai kebijakan sekolah.',
            'grade_scale' => 'hr_1_5',
            'scoring_mode' => 'WEIGHTED',
            'allows_multi_source' => true,
        ]);

        $o = $this->scale15();

        $hasil = $this->section($version, 'A', 'Hasil Kerja', 'Capaian target kerja periode ini.', 50);
        foreach ([
            'Mencapai target kuantitas kerja yang disepakati',
            'Menyelesaikan pekerjaan sesuai mutu yang diharapkan',
            'Menyelesaikan pekerjaan sesuai tenggat waktu',
        ] as $item) {
            $this->scale($hasil, $item, 'Bandingkan bukti capaian dengan target periode.', $o);
        }

        $perilaku = $this->section($version, 'B', 'Perilaku Kerja (Nilai Dasar)', 'Selaras nilai dasar ASN BerAKHLAK / budaya sekolah.', 30);
        foreach ([
            'Berorientasi pelayanan: ramah, responsif, dan solutif',
            'Akuntabel: jujur, bertanggung jawab, dan dapat dipercaya',
            'Kompeten: terus belajar dan meningkatkan kemampuan',
            'Harmonis: menghargai perbedaan dan membangun kerja sama',
            'Loyal: berkomitmen pada sekolah dan tugas',
            'Adaptif: cepat menyesuaikan diri dengan perubahan',
            'Kolaboratif: memberi kontribusi dan membuka kerja sama',
        ] as $item) {
            $this->scale($perilaku, $item, 'Amati perilaku sehari-hari, bukan penilaian pribadi.', $o);
        }

        $teknis = $this->section($version, 'C', 'Kompetensi Teknis', 'Keterampilan khusus yang dibutuhkan jabatan.', 20);
        foreach ([
            'Menguasai keterampilan teknis utama jabatannya',
            'Mampu menyelesaikan masalah teknis secara mandiri',
        ] as $item) {
            $this->scale($teknis, $item, 'Uji dengan contoh kasus nyata di unit kerja.', $o);
        }

        $this->publishVersion($version);
    }

    /** PIP — rencana perbaikan kinerja dengan target terukur (contoh indikator angka). */
    protected function pip(): void
    {
        $version = $this->instrument([
            'code' => 'pip-perbaikan-kinerja',
            'name' => 'Rencana Perbaikan Kinerja (PIP)',
            'category' => 'PIP',
            'target_type' => 'UMUM',
            'description' => 'Memantau perbaikan kinerja pegawai yang belum memenuhi standar: target terukur, dukungan, dan jadwal review.',
            'guidance' => 'Disepakati bersama yang dinilai. Bukan sanksi, tetapi rencana dukungan: target, waktu, dan bantuan yang diberikan.',
            'grade_scale' => 'kemendikbud_90_80_70',
            'scoring_mode' => 'AVERAGE',
        ]);

        $bagian = $this->section($version, 'A', 'Target Perbaikan Terukur');
        $this->number($bagian, 'Capaian kehadiran (persen)', 'Hitung dari data absensi periode pemantauan.', [
            'score_min' => 0, 'score_max' => 100, 'score_step' => 1, 'score_decimals' => 0,
            'score_unit' => '%', 'min_comment_below' => 95,
        ]);
        $this->number($bagian, 'Ketepatan waktu penyelesaian laporan (persen dokumen tepat waktu)', 'Semakin tinggi semakin baik.', [
            'score_min' => 0, 'score_max' => 100, 'score_step' => 1, 'score_decimals' => 0, 'score_unit' => '%',
        ]);
        $this->number($bagian, 'Jumlah keluhan layanan tervalidasi (kali)', 'Semakin sedikit semakin baik.', [
            'score_min' => 0, 'score_max' => 20, 'score_step' => 1, 'score_decimals' => 0,
            'score_unit' => 'kali', 'direction' => 'LOWER_BETTER', 'min_comment_below' => 2,
        ]);

        $cats = $this->section($version, 'B', 'Catatan Perkembangan');
        foreach ([
            'Kendala utama yang menghambat perbaikan kinerja',
            'Bentuk dukungan yang diberikan sekolah',
            'Rencana langkah berikutnya dan tenggatnya',
        ] as $item) {
            $this->narrative($cats, $item, 'Tuliskan hasil kesepakatan atasan dan pegawai.');
        }

        $this->publishVersion($version);
    }

    /** Evaluasi program supervisi (CIPP). */
    protected function evaluasiProgram(): void
    {
        $version = $this->instrument([
            'code' => 'evaluasi-program-supervisi',
            'name' => 'Evaluasi Program Supervisi (CIPP)',
            'category' => 'EVALUASI_PROGRAM',
            'target_type' => 'UMUM',
            'description' => 'Evaluasi program supervisi pada empat dimensi: context, input, process, product.',
            'guidance' => 'Diisi pada akhir periode program oleh kepala sekolah bersama tim supervisi.',
            'requires_action_plan' => false,
        ]);

        $context = $this->section($version, 'A', 'Context — Kebutuhan & Tujuan');
        $input = $this->section($version, 'B', 'Input — Sumber Daya');
        $process = $this->section($version, 'C', 'Process — Pelaksanaan');
        $product = $this->section($version, 'D', 'Product — Hasil & Dampak');
        $o = $this->scale14();

        $this->scale($context, 'Tujuan program supervisi selaras dengan kebutuhan nyata guru/tendik', 'Bandingkan program dengan hasil analisis kebutuhan.', $o);
        $this->scale($context, 'Sasaran program mencakup seluruh pegawai yang membutuhkan pembinaan', 'Periksa daftar sasaran dan cakupannya.', $o);
        $this->scale($input, 'Ketersediaan supervisor, instrumen, dan waktu pelaksanaan memadai', 'Periksa beban supervisor dan jadwal.', $o);
        $this->scale($input, 'Instrumen dan bukti dukung yang dipakai sesuai standar', 'Periksa versi instrumen dan bukti pelaksanaan.', $o);
        $this->scale($process, 'Program dilaksanakan sesuai jadwal dan prosedur (pra-observasi, observasi, pasca)', 'Hitung tingkat keterlaksanaan kegiatan.', $o);
        $this->scale($process, 'Umpan balik dan RTL dijalankan serta dipantau', 'Periksa papan pemantauan RTL.', $o);
        $this->scale($product, 'Pelaksanaan supervisi meningkatkan kualitas pembelajaran/layanan', 'Bandingkan data sebelum dan sesudah.', $o);
        $this->scale($product, 'Hasil supervisi dimanfaatkan untuk pengembangan kompetensi pegawai', 'Periksa dokumen pengembangan dan rencana pelatihan.', $o);

        $this->number($process, 'Tingkat keterlaksanaan kegiatan supervisi dari rencana (%)', 'Hitung dari jumlah kegiatan terlaksana dibagi rencana.', [
            'score_min' => 0, 'score_max' => 100, 'score_step' => 1, 'score_decimals' => 0, 'score_unit' => '%',
            'min_comment_below' => 80,
        ]);

        $this->publishVersion($version);
    }
}
