<?php

namespace Database\Seeders;

use App\Models\QuranSurah;
use Illuminate\Database\Seeder;

/**
 * Referensi 114 surah Al-Quran — mushaf Utsmani (604 halaman).
 *
 * Ini adalah *single source of truth* struktur mushaf. Progress engine
 * menghitung halaman & juz dari kolom `page_start`/`page_end`/`juz_start`/
 * `juz_end`, dan ujian acak ayat mengambil `ayah_count` dari sini.
 *
 * Karena mushaf Utsmani standar memiliki 604 halaman (juz 1–29 masing-masing
 * 20 halaman, juz 30 sebanyak 24 halaman), rentang halaman setiap surah
 * dirunut dari halaman awal surah hingga halaman awal surah berikutnya.
 */
class QuranSurahSeeder extends Seeder
{
    /**
     * [nomor, nama, nama arab, jumlah ayat, halaman awal, tempat turun]
     *
     * Halaman akal dihitung dari halaman awal surah berikutnya; surah terakhir
     * berakhir di halaman 604.
     */
    private const SURAH_DATA = [
        [1, 'Al-Fatiha', 'ٱلْفَاتِحَة', 7, 1, 'makkah'],
        [2, 'Al-Baqarah', 'ٱلْبَقَرَة', 286, 2, 'madinah'],
        [3, 'Ali Imran', 'آلِ عِمْرَان', 200, 50, 'madinah'],
        [4, 'An-Nisa', 'ٱلنِّسَاء', 176, 77, 'madinah'],
        [5, 'Al-Maidah', 'ٱلْمَائِدَة', 120, 106, 'madinah'],
        [6, 'Al-Anam', 'ٱلْأَنْعَام', 165, 128, 'makkah'],
        [7, 'Al-Araf', 'ٱلْأَعْرَاف', 206, 151, 'makkah'],
        [8, 'Al-Anfal', 'ٱلْأَنْفَال', 75, 177, 'madinah'],
        [9, 'At-Tawbah', 'ٱلتَّوْبَة', 129, 187, 'madinah'],
        [10, 'Yunus', 'يُونُس', 109, 208, 'makkah'],
        [11, 'Hud', 'هُود', 123, 221, 'makkah'],
        [12, 'Yusuf', 'يُوسُف', 111, 235, 'makkah'],
        [13, 'Ar-Rad', 'ٱلرَّعْد', 43, 249, 'madinah'],
        [14, 'Ibrahim', 'إِبْرَاهِيم', 52, 255, 'makkah'],
        [15, 'Al-Hijr', 'ٱلْحِجْر', 99, 262, 'makkah'],
        [16, 'An-Nahl', 'ٱلنَّحْل', 128, 267, 'makkah'],
        [17, 'Al-Isra', 'ٱلْإِسْرَاء', 111, 282, 'makkah'],
        [18, 'Al-Kahf', 'ٱلْكَهْف', 110, 293, 'makkah'],
        [19, 'Maryam', 'مَرْيَم', 98, 305, 'makkah'],
        [20, 'Ta-Ha', 'طٰه', 135, 312, 'makkah'],
        [21, 'Al-Anbiya', 'ٱلْأَنْبِيَاء', 112, 322, 'makkah'],
        [22, 'Al-Hajj', 'ٱلْحَجّ', 78, 332, 'madinah'],
        [23, 'Al-Muminun', 'ٱلْمُؤْمِنُون', 118, 342, 'makkah'],
        [24, 'An-Nur', 'ٱلنُّور', 64, 350, 'madinah'],
        [25, 'Al-Furqan', 'ٱلْفُرْقَان', 77, 359, 'makkah'],
        [26, 'Asy-Syuara', 'ٱلشُّعَرَاء', 227, 367, 'makkah'],
        [27, 'An-Naml', 'ٱلنَّمْل', 93, 377, 'makkah'],
        [28, 'Al-Qasas', 'ٱلْقَصَص', 88, 385, 'makkah'],
        [29, 'Al-Ankabut', 'ٱلْعَنْكَبُوت', 69, 396, 'makkah'],
        [30, 'Ar-Rum', 'ٱلرُّوم', 60, 404, 'makkah'],
        [31, 'Luqman', 'لُقْمَان', 34, 411, 'makkah'],
        [32, 'As-Sajdah', 'ٱلسَّجْدَة', 30, 415, 'makkah'],
        [33, 'Al-Ahzab', 'ٱلْأَحْزَاب', 73, 418, 'madinah'],
        [34, 'Saba', 'سَبَإ', 54, 428, 'makkah'],
        [35, 'Fatir', 'فَاطِر', 45, 434, 'makkah'],
        [36, 'Ya-Sin', 'يٰس', 83, 440, 'makkah'],
        [37, 'As-Saffat', 'ٱلصَّافَّات', 182, 446, 'makkah'],
        [38, 'Sad', 'ص', 88, 453, 'makkah'],
        [39, 'Az-Zumar', 'ٱلزُّمَر', 75, 458, 'makkah'],
        [40, 'Ghafir', 'غَافِر', 85, 467, 'makkah'],
        [41, 'Fussilat', 'فُصِّلَت', 54, 477, 'makkah'],
        [42, 'Asy-Syura', 'ٱلشُّورٰى', 53, 483, 'makkah'],
        [43, 'Az-Zukhruf', 'ٱلزُّخْرُف', 89, 489, 'makkah'],
        [44, 'Ad-Dukhan', 'ٱلدُّخَان', 59, 496, 'makkah'],
        [45, 'Al-Jasiyah', 'ٱلْجَاثِيَة', 37, 499, 'makkah'],
        [46, 'Al-Ahqaf', 'ٱلْأَحْقَاف', 35, 502, 'makkah'],
        [47, 'Muhammad', 'مُحَمَّد', 38, 507, 'madinah'],
        [48, 'Al-Fath', 'ٱلْفَتْح', 29, 511, 'madinah'],
        [49, 'Al-Hujurat', 'ٱلْحُجُرَات', 18, 515, 'madinah'],
        [50, 'Qaf', 'ق', 45, 518, 'makkah'],
        [51, 'Adz-Dzariyat', 'ٱلذَّارِيَات', 60, 520, 'makkah'],
        [52, 'At-Tur', 'ٱلطُّور', 49, 523, 'makkah'],
        [53, 'An-Najm', 'ٱلنَّجْم', 62, 526, 'makkah'],
        [54, 'Al-Qamar', 'ٱلْقَمَر', 55, 528, 'makkah'],
        [55, 'Ar-Rahman', 'ٱلرَّحْمٰن', 78, 531, 'madinah'],
        [56, 'Al-Waqiah', 'ٱلْوَاقِعَة', 96, 533, 'makkah'],
        [57, 'Al-Hadid', 'ٱلْحَدِيد', 29, 537, 'madinah'],
        [58, 'Al-Mujadilah', 'ٱلْمُجَادِلَة', 22, 542, 'madinah'],
        [59, 'Al-Hasyr', 'ٱلْحَشْر', 24, 545, 'madinah'],
        [60, 'Al-Mumtahanah', 'ٱلْمُمْتَحَنَة', 13, 549, 'madinah'],
        [61, 'As-Saff', 'ٱلصَّفّ', 14, 551, 'madinah'],
        [62, 'Al-Jumuah', 'ٱلْجُمُعَة', 11, 553, 'madinah'],
        [63, 'Al-Munafiqun', 'ٱلْمُنَافِقُون', 11, 554, 'madinah'],
        [64, 'At-Taghabun', 'ٱلتَّغَابُن', 18, 556, 'madinah'],
        [65, 'At-Talaq', 'ٱلطَّلَاق', 12, 558, 'madinah'],
        [66, 'At-Tahrim', 'ٱلتَّحْرِيم', 12, 560, 'madinah'],
        [67, 'Al-Mulk', 'ٱلْمُلْك', 30, 562, 'makkah'],
        [68, 'Al-Qalam', 'ٱلْقَلَم', 52, 564, 'makkah'],
        [69, 'Al-Haqqah', 'ٱلْحَاقَّة', 52, 566, 'makkah'],
        [70, 'Al-Maarij', 'ٱلْمَعَارِج', 44, 568, 'makkah'],
        [71, 'Nuh', 'نُوح', 28, 570, 'makkah'],
        [72, 'Al-Jinn', 'ٱلْجِنّ', 28, 572, 'makkah'],
        [73, 'Al-Muzzammil', 'ٱلْمُزَّمِّل', 20, 574, 'makkah'],
        [74, 'Al-Muddaththir', 'ٱلْمُدَّثِّر', 56, 575, 'makkah'],
        [75, 'Al-Qiyamah', 'ٱلْقِيَامَة', 40, 577, 'makkah'],
        [76, 'Al-Insan', 'ٱلْإِنْسَان', 31, 578, 'madinah'],
        [77, 'Al-Mursalat', 'ٱلْمُرْسَلَات', 50, 580, 'makkah'],
        [78, 'An-Naba', 'ٱلنَّبَأ', 40, 582, 'makkah'],
        [79, 'An-Naziat', 'ٱلنَّازِعَات', 46, 583, 'makkah'],
        [80, 'Abasa', 'عَبَسَ', 42, 585, 'makkah'],
        [81, 'At-Takwir', 'ٱلتَّكْوِير', 29, 586, 'makkah'],
        [82, 'Al-Infitar', 'ٱلْإِنْفِطَار', 19, 587, 'makkah'],
        [83, 'Al-Mutaffifin', 'ٱلْمُطَفِّفِين', 36, 587, 'makkah'],
        [84, 'Al-Insyiqaq', 'ٱلْإِنْشِقَاق', 25, 589, 'makkah'],
        [85, 'Al-Buruj', 'ٱلْبُرُوج', 22, 590, 'makkah'],
        [86, 'At-Tariq', 'ٱلطَّارِق', 17, 591, 'makkah'],
        [87, 'Al-Ala', 'ٱلْأَعْلَى', 19, 591, 'makkah'],
        [88, 'Al-Ghasyiyah', 'ٱلْغَاشِيَة', 26, 592, 'makkah'],
        [89, 'Al-Fajr', 'ٱلْفَجْر', 30, 593, 'makkah'],
        [90, 'Al-Balad', 'ٱلْبَلَد', 20, 594, 'makkah'],
        [91, 'Asy-Syams', 'ٱلشَّمْس', 15, 595, 'makkah'],
        [92, 'Al-Lail', 'ٱلَّيْل', 21, 595, 'makkah'],
        [93, 'Adz-Dhuha', 'ٱلضُّحَىٰ', 11, 596, 'makkah'],
        [94, 'Al-Insyirah', 'ٱلْإِنْشِرَاح', 8, 596, 'makkah'],
        [95, 'At-Tin', 'ٱلتِّين', 8, 597, 'makkah'],
        [96, 'Al-Alaq', 'ٱلْعَلَق', 19, 597, 'makkah'],
        [97, 'Al-Qadr', 'ٱلْقَدْر', 5, 598, 'makkah'],
        [98, 'Al-Bayyinah', 'ٱلْبَيِّنَة', 8, 598, 'madinah'],
        [99, 'Az-Zalzalah', 'ٱلزَّلْزَلَة', 8, 599, 'madinah'],
        [100, 'Al-Adiyat', 'ٱلْعَادِيَات', 11, 600, 'makkah'],
        [101, 'Al-Qariah', 'ٱلْقَارِعَة', 11, 600, 'makkah'],
        [102, 'At-Takasur', 'ٱلتَّكَاثُر', 8, 601, 'makkah'],
        [103, 'Al-Asr', 'ٱلْعَصْر', 3, 601, 'makkah'],
        [104, 'Al-Humazah', 'ٱلْهُمَزَة', 9, 601, 'makkah'],
        [105, 'Al-Fil', 'ٱلْفِيل', 5, 602, 'makkah'],
        [106, 'Quraisy', 'قُرَيْش', 4, 602, 'makkah'],
        [107, 'Al-Maun', 'ٱلْمَاعُون', 7, 602, 'makkah'],
        [108, 'Al-Kautsar', 'ٱلْكَوْثَر', 3, 602, 'makkah'],
        [109, 'Al-Kafirun', 'ٱلْكَافِرُون', 6, 603, 'makkah'],
        [110, 'An-Nasr', 'ٱلنَّصْر', 3, 603, 'madinah'],
        [111, 'Al-Lahab', 'ٱلْمَسَد', 5, 603, 'makkah'],
        [112, 'Al-Ikhlas', 'ٱلْإِخْلَاص', 4, 604, 'makkah'],
        [113, 'Al-Falaq', 'ٱلْفَلَق', 5, 604, 'makkah'],
        [114, 'An-Nas', 'ٱلنَّاس', 6, 604, 'makkah'],
    ];

    public function run(): void
    {
        if (QuranSurah::query()->count() > 0) {
            $this->command?->warn('QuranSurah sudah terisi — seeder dilewati.');

            return;
        }

        // Juz yang berlaku pada halaman tertentu: juz 1–29 masing-masing
        // 20 halaman (1–20, 21–40, …), juz 30 menutup sampai halaman 604.
        $juzOfPage = static function (int $page): int {
            return (int) min(30, intdiv($page - 1, 20) + 1);
        };

        $rows = [];
        $count = count(self::SURAH_DATA);
        foreach (self::SURAH_DATA as $index => [$number, $name, $arabic, $ayahCount, $pageStart, $place]) {
            // Halaman akhir surah = halaman awal surah berikutnya dikurangi 1;
            // surah terakhir (An-Nas) berakhir di halaman 604.
            $pageEnd = $index + 1 < $count ? self::SURAH_DATA[$index + 1][4] - 1 : 604;

            $rows[] = [
                'number' => $number,
                'name' => $name,
                'arabic_name' => $arabic,
                'translation' => null,
                'ayah_count' => $ayahCount,
                'page_start' => $pageStart,
                'page_end' => $pageEnd,
                'juz_start' => $juzOfPage($pageStart),
                'juz_end' => $juzOfPage($pageEnd),
                'revelation_place' => $place,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Chunk agar insert tetap ringan.
        foreach (array_chunk($rows, 40) as $chunk) {
            QuranSurah::query()->insert($chunk);
        }

        $this->command?->info('QuranSurah: '.count($rows).' surah terisi (mushaf 604 halaman).');
    }
}
