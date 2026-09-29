<?php

namespace App\Modules\Supervisi\Database\Seeders;

use App\Modules\Supervisi\Database\Seeders\Concerns\BuildsInstruments;
use Illuminate\Database\Seeder;

/**
 * Instrumen supervisi akademik bawaan (Fase 1).
 *
 * Butir-butirnya mengikuti instrumen yang lazim dipakai sekolah Indonesia:
 * perangkat pembelajaran 12 komponen (1–4), telaah RPP 36 butir (0–2),
 * pengamatan pelaksanaan pembelajaran 40 butir (ya/tidak), penilaian hasil
 * belajar 11 aspek (1–4), wawancara pra/pasca, dampak, serta walkthrough.
 */
class SupAcademicInstrumentSeeder extends Seeder
{
    use BuildsInstruments;

    public function run(): void
    {
        $this->perangkatPembelajaran();
        $this->telaahRpp();
        $this->pengamatanPembelajaran();
        $this->penilaianHasilBelajar();
        $this->walkthrough();
        $this->wawancara();
        $this->dampakSupervisi();
        $this->telaahSoal();
    }

    /** 1. Administrasi/perangkat pembelajaran — 12 komponen, skala 1–4 (maks 48). */
    protected function perangkatPembelajaran(): void
    {
        $version = $this->instrument([
            'code' => 'perangkat-pembelajaran',
            'name' => 'Administrasi & Perangkat Pembelajaran Guru',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
            'description' => 'Telaah kelengkapan 12 komponen administrasi pembelajaran sebelum observasi kelas.',
            'guidance' => 'Diperiksa sebelum observasi kelas (pra-observasi). Nilai 1–4 per komponen; bukti berupa berkas fisik/digital.',
        ]);

        $bagian = $this->section($version, 'A', 'Kelengkapan Administrasi Pembelajaran');
        $o = $this->scale14();

        $items = [
            'Program Tahunan tersusun untuk seluruh semester',
            'Program Semester memuat alokasi waktu dan penilaian',
            'Silabus/ATP sesuai capaian pembelajaran',
            'RPP/Modul Ajar tersedia untuk setiap pertemuan yang diamati',
            'Kalender pendidikan digunakan sebagai acuan penyusunan program',
            'Jadwal pelajaran sesuai beban mengajar',
            'Agenda harian mengajar terisi dan terpelihara',
            'Daftar nilai memuat penilaian sikap, pengetahuan, dan keterampilan',
            'Dokumen KKM/KKTP ditetapkan dengan mempertimbangkan intake, daya dukung, dan kompleksitas',
            'Daftar hadir peserta didik terpelihara',
            'Buku pedoman guru/bahan ajar tersedia',
            'Buku teks pelajaran sesuai mata pelajaran yang diampu',
        ];

        foreach ($items as $item) {
            $this->scale($bagian, $item, 'Periksa keberadaan, kelengkapan, dan kemutakhiran dokumen.', $o);
        }

        $this->publishVersion($version);
    }

    /** 2. Telaah RPP/Modul Ajar — 36 butir skala 0–2 (maks 72). */
    protected function telaahRpp(): void
    {
        $version = $this->instrument([
            'code' => 'telaah-rpp',
            'name' => 'Telaah RPP / Modul Ajar',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
            'description' => 'Analisis 36 butir komponen RPP sesuai panduan telaah perangkat pembelajaran (skor 0–2).',
            'guidance' => 'Skor 0 = tidak ada, 1 = ada tetapi kurang lengkap/tepat, 2 = ada dan lengkap.',
        ]);

        $o = $this->scale02();

        $identitas = $this->section($version, 'A', 'Identitas RPP', null, 1);
        $this->scale($identitas, 'Memuat identitas lengkap (satuan, mata pelajaran, kelas/semester, alokasi waktu)', 'Periksa kelengkapan identitas.', $o);

        $utama = $this->section($version, 'B', 'Komponen Utama', null, 1);
        $this->scale($utama, 'Menuliskan 3 komponen utama (tujuan, langkah, penilaian) secara eksplisit', 'Ketiga komponen harus tampak jelas.', $o);

        $kiKd = $this->section($version, 'C1', 'Rumusan KI/KD/Capaian Pembelajaran', null, 1);
        foreach ([
            'Menuliskan KI/CP dengan lengkap',
            'Menuliskan KD/TP dengan lengkap',
            'KD/TP dipilih dari silabus/ATP yang berlaku',
            'Kesesuaian KD dengan materi pokok',
            'Keruntutan antar-KD/TP',
            'Kesesuaian IPK dengan KD dan tujuan',
        ] as $item) {
            $this->scale($kiKd, $item, 'Periksa kesesuaian dan keruntutan rumusan.', $o);
        }

        $tujuan = $this->section($version, 'C2', 'Tujuan Pembelajaran', null, 1);
        foreach ([
            'Kesesuaian dengan KD/CP',
            'Mengandung kemampuan yang dapat diamati dan diukur',
            'Mencakup sikap, pengetahuan, dan keterampilan',
            'Terfokus pada peserta didik (bukan aktivitas guru)',
        ] as $item) {
            $this->scale($tujuan, $item, 'Periksa keterukuran tujuan.', $o);
        }

        $materi = $this->section($version, 'C3', 'Materi Pembelajaran', null, 1);
        foreach ([
            'Sesuai dengan tujuan pembelajaran',
            'Kesesuaian dengan karakteristik peserta didik',
            'Keruntutan (sederhana ke kompleks, konkret ke abstrak)',
            'Mutakhir dan kontekstual',
        ] as $item) {
            $this->scale($materi, $item, 'Periksa cakupan dan keruntutan materi.', $o);
        }

        $metode = $this->section($version, 'C4', 'Metode & Pendekatan', null, 1);
        foreach ([
            'Menggunakan pendekatan yang melibatkan peserta didik aktif',
            'Mendukung pengembangan kemampuan berpikir tingkat tinggi',
            'Kesesuaian dengan karakteristik materi',
            'Mendukung peran guru sebagai fasilitator',
            'Mengintegrasikan nilai karakter/PPK dan literasi',
        ] as $item) {
            $this->scale($metode, $item, 'Periksa kesesuaian metode dengan tujuan dan materi.', $o);
        }

        $media = $this->section($version, 'C5', 'Media & Sumber Belajar', null, 1);
        foreach ([
            'Sesuai dengan indikator/tujuan pembelajaran',
            'Memanfaatkan lingkungan/keadaan nyata sekolah',
            'Menjelaskan cara penggunaan media',
            'Menjelaskan cara memanfaatkan teknologi informasi (TPACK)',
        ] as $item) {
            $this->scale($media, $item, 'Periksa relevansi media & sumber.', $o);
        }

        $langkah = $this->section($version, 'C6', 'Langkah-Langkah Pembelajaran', null, 1);
        foreach ([
            'Memuat kegiatan pendahuluan, inti, dan penutup',
            'Pendahuluan memuat pengkondisian kelas, apersepsi, serta penyampaian tujuan dan rencana kegiatan',
            'Kegiatan inti mencirikan pembelajaran aktif dan interaksi multi-arah',
            'Kegiatan penutup memuat refleksi, umpan balik, dan tindak lanjut',
        ] as $item) {
            $this->scale($langkah, $item, 'Periksa kerincian langkah dan keterlaksanaannya.', $o);
        }

        $penilaian = $this->section($version, 'C7', 'Rencana Penilaian', null, 1);
        foreach ([
            'Memuat teknik penilaian yang lengkap',
            'Memuat rubrik/kriteria penilaian sikap',
            'Memuat rubrik/kriteria penilaian pengetahuan',
            'Memuat rubrik/kriteria penilaian keterampilan',
            'Kesesuaian teknik penilaian dengan tujuan',
            'Kesesuaian butir soal/tugas dengan IPK',
            'Menjelaskan cara pemberian umpan balik dan tindak lanjut hasil penilaian',
        ] as $item) {
            $this->scale($penilaian, $item, 'Periksa kesesuaian teknik, instrumen, dan tindak lanjut.', $o);
        }

        $this->publishVersion($version);
    }

    /** 3. Pengamatan pelaksanaan pembelajaran — 40 butir daftar periksa (maks 40). */
    protected function pengamatanPembelajaran(): void
    {
        $version = $this->instrument([
            'code' => 'pengamatan-pembelajaran',
            'name' => 'Pengamatan Pelaksanaan Pembelajaran di Kelas',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
            'description' => 'Lembar observasi 40 butir: pendahuluan, kegiatan inti (penguasaan materi, implementasi, media, interaksi, bahasa), dan penutup.',
            'guidance' => 'Beri tanda “Ya” bila perilaku tampak selama pengamatan, “Tidak” bila tidak tampak. Catat bukti fakta pada kolom catatan.',
            'scale_type' => 'YES_NO',
        ]);

        $pendahuluan = $this->section($version, 'A', 'Kegiatan Pendahuluan', null, 1);
        foreach ([
            'Menyiapkan fisik dan psikis peserta didik (salam, doa, kehadiran)',
            'Melakukan apersepsi dengan mengaitkan pelajaran sebelumnya',
            'Menyampaikan kompetensi/tujuan yang akan dicapai',
            'Menyampaikan cakupan materi dan penjelasan kegiatan',
            'Menyampaikan lingkup dan teknik penilaian yang akan digunakan',
        ] as $item) {
            $this->yesNo($pendahuluan, $item, 'Amati apakah perilaku tampak secara alami.');
        }

        $materi = $this->section($version, 'B1', 'Penguasaan Materi', null, 1);
        foreach ([
            'Menyesuaikan materi dengan tujuan pembelajaran',
            'Mengaitkan materi dengan pengetahuan lain yang relevan',
            'Menyajikan materi secara sistematis (mudah ke sulit, konkret ke abstrak)',
            'Mengaitkan materi dengan realitas kehidupan sehari-hari',
        ] as $item) {
            $this->yesNo($materi, $item, 'Bukti: contoh yang dipakai guru dan jawaban atas pertanyaan siswa.');
        }

        $inti = $this->section($version, 'B2', 'Penerapan Strategi Pembelajaran', null, 1);
        foreach ([
            'Melaksanakan pembelajaran sesuai kompetensi/tujuan yang akan dicapai',
            'Melaksanakan pembelajaran secara runtut',
            'Menguasai kelas dan mengelola waktu',
            'Melaksanakan pembelajaran yang bersifat kontekstual',
            'Melaksanakan pembelajaran yang memungkinkan tumbuhnya kebiasaan positif',
            'Melaksanakan pembelajaran sesuai alokasi waktu yang direncanakan',
            'Memotivasi peserta didik untuk berpartisipasi aktif',
            'Memberi kesempatan peserta didik bertanya dan berpendapat',
            'Mendorong peserta didik berpikir kritis dan memecahkan masalah',
            'Menggunakan pertanyaan pelacak (probing) — bukan jawaban serentak',
            'Memberi umpan balik terhadap jawaban/pekerjaan peserta didik',
            'Memanfaatkan kesalahan sebagai sumber belajar',
            'Menutup pembelajaran dengan menyimpulkan bersama peserta didik',
        ] as $item) {
            $this->yesNo($inti, $item, 'Amati perilaku guru dan respons peserta didik.');
        }

        $media = $this->section($version, 'B3', 'Pemanfaatan Media & Sumber Belajar', null, 1);
        foreach ([
            'Menunjukkan keterampilan memanfaatkan media pembelajaran',
            'Menghasilkan pesan yang menarik perhatian peserta didik',
            'Melibatkan peserta didik dalam pemanfaatan media',
            'Menunjukkan keterampilan memanfaatkan sumber belajar',
            'Menggunakan sumber belajar sesuai karakteristik materi',
            'Memanfaatkan teknologi informasi secara tepat (TPACK)',
        ] as $item) {
            $this->yesNo($media, $item, 'Catat jenis media/sumber yang benar-benar dipakai.');
        }

        $interaksi = $this->section($version, 'B4', 'Interaksi dengan Peserta Didik', null, 1);
        foreach ([
            'Menumbuhkan partisipasi aktif peserta didik',
            'Merespons positif partisipasi peserta didik',
            'Menunjukkan sikap terbuka terhadap respons peserta didik',
            'Menumbuhkan keceriaan dan antusiasme belajar',
            'Menumbuhkan hubungan interpersonal yang kondusif',
        ] as $item) {
            $this->yesNo($interaksi, $item, 'Bukti: ekspresi, pujian, dan cara menangani jawaban salah.');
        }

        $bahasa = $this->section($version, 'B5', 'Penggunaan Bahasa', null, 1);
        foreach ([
            'Menggunakan bahasa Indonesia yang baik, benar, dan lancar',
            'Menggunakan pilihan kata yang mudah dipahami peserta didik',
        ] as $item) {
            $this->yesNo($bahasa, $item, 'Nilai kejelasan instruksi lisan.');
        }

        $penutup = $this->section($version, 'C', 'Kegiatan Penutup', null, 1);
        foreach ([
            'Melakukan refleksi atau membuat rangkuman dengan melibatkan peserta didik',
            'Memberikan umpan balik terhadap proses dan hasil pembelajaran',
            'Memberikan apresiasi/penghargaan atas partisipasi peserta didik',
            'Memberikan tindak lanjut berupa tugas/PR atau rencana pertemuan berikutnya',
            'Menyampaikan rencana pembelajaran pada pertemuan berikutnya',
        ] as $item) {
            $this->yesNo($penutup, $item, 'Periksa apakah penutup dijalankan, bukan ditinggalkan karena waktu habis.');
        }

        $this->publishVersion($version);
    }

    /** 4. Supervisi penilaian hasil belajar — 11 aspek, skala 1–4 (maks 44). */
    protected function penilaianHasilBelajar(): void
    {
        $version = $this->instrument([
            'code' => 'penilaian-hasil-belajar',
            'name' => 'Supervisi Penilaian Hasil Belajar',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
            'description' => 'Menelaah 11 aspek penilaian: KKM, buku nilai, jenis tes, sikap, pengetahuan, keterampilan, analisis, remedial, pengayaan, analisis butir, dan bank soal.',
            'guidance' => 'Periksa dokumen penilaian dan bukti pelaksanaan tindak lanjutnya.',
        ]);

        $bagian = $this->section($version, 'A', 'Penilaian Proses & Hasil Belajar');
        $o = $this->scale14();

        foreach ([
            'Menetapkan KKM/KKTP dengan mempertimbangkan intake, daya dukung, dan kompleksitas',
            'Mengadministrasikan penilaian dalam buku daftar nilai',
            'Melaksanakan tes tulis dan/atau praktik sesuai rencana penilaian',
            'Melaksanakan penilaian sikap (observasi/jurnal, penilaian diri, antarteman)',
            'Melaksanakan penilaian pengetahuan (tulis, lisan, penugasan)',
            'Melaksanakan penilaian keterampilan (unjuk kerja, proyek, produk, portofolio)',
            'Menganalisis hasil penilaian harian untuk mengetahui ketuntasan belajar',
            'Melaksanakan pembelajaran remedial bagi peserta didik yang belum tuntas',
            'Melaksanakan pengayaan bagi peserta didik yang telah tuntas',
            'Menganalisis butir soal (tingkat kesulitan, daya beda, efektivitas pengecoh)',
            'Menyimpan butir soal dalam bank soal yang terpelihara',
        ] as $item) {
            $this->scale($bagian, $item, 'Bukti: dokumen, daftar hadir remedial, dan catatan analisis.', $o);
        }

        $this->publishVersion($version);
    }

    /** 5. Walkthrough kelas — fokus singkat + contoh indikator angka (maks 30). */
    protected function walkthrough(): void
    {
        $version = $this->instrument([
            'code' => 'walkthrough-kelas',
            'name' => 'Kunjungan Kelas Singkat (Walkthrough)',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
            'description' => 'Kunjungan 10–15 menit untuk umpan balik cepat: fokus satu hal + satu tindak lanjut.',
            'guidance' => 'Tidak dipakai mempredikat guru. Fokus pada indikator yang disepakati sebelum kunjungan.',
            'grade_scale' => 'yes_no',
            'requires_action_plan' => false,
            'scoring_mode' => 'WEIGHTED',
        ]);

        $bagian = $this->section($version, 'A', 'Fokus Kunjungan', 'Pilih 1–2 fokus yang paling relevan dengan kebutuhan guru saat ini.', 1);
        $o = $this->scale14();

        foreach ([
            'Tujuan pembelajaran tampak jelas bagi peserta didik',
            'Sebagian besar peserta didik terlibat aktif dalam kegiatan',
            'Guru memberi umpan balik spesifik atas pekerjaan peserta didik',
            'Iklim kelas aman, tertib, dan saling menghargai',
            'Media/sumber belajar digunakan untuk mendukung tujuan',
        ] as $item) {
            $this->scale($bagian, $item, 'Amati dalam waktu singkat; tulis bukti konkret.', $o);
        }

        // Contoh indikator bertipe angka langsung — bukan pilihan skala.
        // Bobot bagian lebih kecil (0,5) supaya data kuantitatif melengkapi,
        // bukan mendominasi, penilaian fokus observasi.
        $data = $this->section($version, 'B', 'Data Kuantitatif Kunjungan', null, 0.5);
        $this->number($data, 'Persentase peserta didik yang terlibat aktif (%)', 'Perkirakan dari pengamatan langsung; angka bebas 0–100.', [
            'score_min' => 0,
            'score_max' => 100,
            'score_step' => 1,
            'score_decimals' => 0,
            'score_unit' => '%',
            'direction' => 'HIGHER_BETTER',
            'min_comment_below' => 60,
        ]);
        $this->number($data, 'Jumlah interupsi/kegaduhan yang mengganggu pembelajaran (kali)', 'Semakin sedikit semakin baik; angka bebas 0–20.', [
            'score_min' => 0,
            'score_max' => 20,
            'score_step' => 1,
            'score_decimals' => 0,
            'score_unit' => 'kali',
            'direction' => 'LOWER_BETTER',
        ]);

        $this->publishVersion($version);
    }

    /** 6–7. Wawancara pra & pasca-observasi. */
    protected function wawancara(): void
    {
        $pra = $this->instrument([
            'code' => 'wawancara-pra-observasi',
            'name' => 'Wawancara Pra-Observasi',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
            'description' => 'Delapan pertanyaan persiapan supervisi klinis: kesepakatan fokus, metode, media, dan hal yang perlu perhatian khusus.',
            'guidance' => 'Lakukan sebagai percakapan, bukan interogasi. Tuliskan jawaban apa adanya.',
            'requires_action_plan' => false,
            'scoring_mode' => 'NARRATIVE',
        ]);

        $bagian = $this->section($pra, 'A', 'Pertanyaan Persiapan');
        foreach ([
            'Apa materi/kompetensi yang akan diajarkan pada pertemuan ini?',
            'Metode apa yang akan digunakan dan mengapa dipilih?',
            'Alat, bahan, atau media apa yang perlu disiapkan?',
            'Bagaimana tahapan kegiatan pembelajaran yang direncanakan?',
            'Persiapan tertulis (RPP/modul ajar) apa yang sudah dibuat?',
            'Menurut perkiraan Anda, bagian mana yang paling sulit bagi peserta didik?',
            'Kompetensi apa yang diharapkan muncul pada peserta didik?',
            'Hal khusus apa yang perlu saya perhatikan saat observasi?',
        ] as $item) {
            $this->narrative($bagian, $item, 'Catat jawaban guru beserta kesepakatan yang dibuat.');
        }

        $kesiapan = $this->section($pra, 'B', 'Kesiapan Guru', null, 0.5);
        $this->scale($kesiapan, 'Kesiapan perangkat dan rencana pembelajaran', 'Ringkasan hasil percakapan, bukan penilaian kinerja.', $this->scale14(), ['min_comment_below' => 3]);

        $this->publishVersion($pra);

        $pasca = $this->instrument([
            'code' => 'wawancara-pasca-observasi',
            'name' => 'Wawancara Pasca-Observasi & Refleksi',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
            'description' => 'Sembilan pertanyaan refleksi setelah pengamatan, fokus pada analisis guru sendiri sebelum umpan balik supervisor.',
            'guidance' => 'Mulai dari refleksi guru. Supervisor menambahkan data, bukan penilaian pribadi.',
            'requires_action_plan' => false,
            'scoring_mode' => 'NARRATIVE',
        ]);

        $refleksi = $this->section($pasca, 'A', 'Refleksi Guru');
        foreach ([
            'Bagaimana pendapat Anda tentang pelaksanaan pembelajaran tadi?',
            'Apakah pelaksanaan sesuai dengan rencana? Bagian mana yang berbeda?',
            'Apa yang paling memuaskan dari pembelajaran tadi?',
            'Apakah tujuan pembelajaran tercapai? Apa buktinya?',
            'Kesulitan apa yang dialami peserta didik?',
            'Kesulitan apa yang Anda alami sebagai guru?',
            'Apa alternatif solusi yang bisa dicoba?',
            'Mana yang sudah mantap dan mana yang perlu ditingkatkan?',
            'Apa rencana Anda untuk pertemuan berikutnya?',
        ] as $item) {
            $this->narrative($refleksi, $item, 'Tuliskan refleksi guru; jangan mengoreksi pada tahap ini.');
        }

        $komitmen = $this->section($pasca, 'B', 'Komitmen Tindak Lanjut', null, 0.5);
        $this->scale($komitmen, 'Keterbukaan guru menyusun perbaikan dan kesediaan mencoba ulang', 'Dasar penyusunan RTL bersama.', $this->scale14(), ['min_comment_below' => 3]);

        $this->publishVersion($pasca);
    }

    /** 8. Dampak/outcome supervisi — 5 indikator skala 1–4 (maks 20). */
    protected function dampakSupervisi(): void
    {
        $version = $this->instrument([
            'code' => 'dampak-supervisi',
            'name' => 'Dampak / Outcome Supervisi',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
            'description' => 'Menilai dampak supervisi terhadap pembelajaran dan hasil belajar setelah tindak lanjut dijalankan.',
            'guidance' => 'Diisi pada akhir periode supervisi (setelah RTL terlaksana), bukan segera setelah observasi.',
            'requires_action_plan' => false,
        ]);

        $bagian = $this->section($version, 'A', 'Perubahan yang Teramati');
        $o = $this->scale14();

        foreach ([
            'Motivasi belajar peserta didik meningkat',
            'Nilai/hasil belajar peserta didik meningkat',
            'Kualitas proses pembelajaran meningkat',
            'Kompetensi profesional guru meningkat',
            'Prestasi akademik/non-akademik sekolah terdampak positif',
        ] as $item) {
            $this->scale($bagian, $item, 'Bandingkan data sebelum dan sesudah tindak lanjut.', $o);
        }

        $this->publishVersion($version);
    }

    /** 9. Telaah kisi-kisi & soal — contoh instrumen daftar periksa + angka HOTS. */
    protected function telaahSoal(): void
    {
        $version = $this->instrument([
            'code' => 'telaah-kisi-kisi-dan-soal',
            'name' => 'Telaah Kisi-kisi & Analisis Butir Soal',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
            'description' => 'Memeriksa kisi-kisi, konstruksi soal pilihan ganda dan uraian, serta komposisi level kognitif (HOTS).',
            'guidance' => 'Periksa satu paket soal yang terakhir dipakai. Tandai “Ya” bila kriteria terpenuhi.',
            'grade_scale' => 'yes_no',
            'scoring_mode' => 'WEIGHTED',
        ]);

        $kisi = $this->section($version, 'A', 'Kisi-kisi', null, 1);
        foreach ([
            'Mencantumkan tujuan/indikator soal dengan jelas',
            'Mencantumkan materi dan ruang lingkup yang diujikan',
            'Mencantumkan konstruksi soal (bentuk, level kognitif)',
            'Sebaran level kognitif sesuai komposisi yang direncanakan',
        ] as $item) {
            $this->yesNo($kisi, $item, 'Bandingkan kisi-kisi dengan soal yang benar-benar dipakai.');
        }

        $pg = $this->section($version, 'B', 'Konstruksi Soal Pilihan Ganda', null, 1.5);
        foreach ([
            'Soal dirumuskan dengan jelas dan tidak bermakna ganda',
            'Pokok soal tidak memberi petunjuk kunci jawaban',
            'Rumusan pokok soal dan pilihan jawaban merupakan pernyataan yang diperlukan',
            'Pilihan jawaban homogen dan logis',
            'Hanya ada satu kunci jawaban yang benar',
            'Panjang rumusan pilihan jawaban relatif sama',
            'Butir soal tidak bergantung pada jawaban butir sebelumnya',
            'Menggunakan bahasa yang sesuai kaidah dan komunikatif',
            'Menggunakan semua opsi (tidak semua benar/salah)',
            'Menghindari penggunaan kata “semua benar/salah”',
        ] as $item) {
            $this->yesNo($pg, $item, 'Analisis satu per satu butir pada paket soal.');
        }

        $uraian = $this->section($version, 'C', 'Konstruksi Soal Uraian', null, 1);
        foreach ([
            'Menggunakan kata tugas yang menuntut jawaban uraian',
            'Rumusan soal sesuai dengan indikator',
            'Rumusan soal tidak bermakna ganda',
            'Batasan jawaban yang diharapkan jelas',
            'Rumusan soal tidak mengandung petunjuk kunci',
            'Rumusan soal tidak bergantung pada butir lain',
            'Menggunakan bahasa yang komunikatif',
            'Setiap butir disertai rubrik/kunci jawaban',
        ] as $item) {
            $this->yesNo($uraian, $item, 'Periksa rubrik penskoran tiap butir.');
        }

        // Indikator angka: komposisi HOTS tidak bisa dinilai dengan skala 1–4.
        // Bobot bagian kecil (0,5) karena fungsinya melengkapi telaah butir.
        $kognitif = $this->section($version, 'D', 'Komposisi Level Kognitif', null, 0.5);
        $this->number($kognitif, 'Proporsi soal HOTS (C4–C6) dalam paket soal (%)', 'Hitung dari jumlah butir; masukkan persentase 0–100.', [
            'score_min' => 0,
            'score_max' => 100,
            'score_step' => 1,
            'score_decimals' => 0,
            'score_unit' => '%',
            'min_comment_below' => 20,
        ]);
        $this->number($kognitif, 'Jumlah butir soal yang direvisi setelah analisis (butir)', 'Semakin sedikit semakin baik.', [
            'score_min' => 0,
            'score_max' => 40,
            'score_step' => 1,
            'score_decimals' => 0,
            'score_unit' => 'butir',
            'direction' => 'LOWER_BETTER',
        ]);

        $this->publishVersion($version);
    }
}
