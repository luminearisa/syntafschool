<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // --- Fondasi referensi -------------------------------------
            RolePermissionSeeder::class,
            ReferenceSeeder::class,
            AcademicSeeder::class,
            ReferenceFinanceLibrarySeeder::class,
            SchoolSeeder::class,
            RegionSeeder::class,

            // --- Master akademik ---------------------------------------
            SubjectSeeder::class,
            RoomSeeder::class,
            AchievementTypeSeeder::class,
            EventTypeSeeder::class,
            AcademicCalendarSeeder::class,

            // --- Kurikulum (A5) ----------------------------------------
            SubjectGroupSeeder::class,
            ProgramSeeder::class,
            CurriculumStructureSeeder::class,

            // --- Kelas & jadwal ----------------------------------------
            TimeSlotSeeder::class,

            // --- Kepegawaian & kesiswaan -------------------------------
            EmployeeSeeder::class,
            ClassroomSeeder::class,
            StudentSeeder::class,

            // --- Mapel per kelas & jadwal ------------------------------
            ClassSubjectSeeder::class,
            ScheduleSeeder::class,

            // --- Pembelajaran (A11) ------------------------------------
            LessonPlanSeeder::class,
            TeachingJournalSeeder::class,
            AssignmentSeeder::class,

            // --- Presensi (A6) -----------------------------------------
            StudentAttendanceSeeder::class,
            EmployeeAttendanceSeeder::class,

            // --- Penilaian (A7): konfigurasi lalu transaksi -------------
            AssessmentTypeSeeder::class,
            GradeScaleSeeder::class,
            GradingFormulaSeeder::class,
            PassingGradeRuleSeeder::class,
            AssessmentWeightSeeder::class,
            AssessmentSeeder::class,

            // --- Modul Tahfiz: referensi & konfigurasi ------------------
            QuranSurahSeeder::class,
            TahfizConfigSeeder::class,

            // --- Modul Library: perpustakaan + konfigurasi minimum ------
            LibrarySeeder::class,

            // --- Modul Library: master data (Step 2) --------------------
            LibraryMasterSeeder::class,

            // --- Modul PPDB: periode aktif + formulir dinamis -----------
            PpdbConfigSeeder::class,

            // --- Modul Boarding/Pesantren: master absensi, kegiatan,
            //     jenis pelanggaran, dan struktur asrama dasar -----------
            BoardingConfigSeeder::class,

            // --- Modul Finance/Keuangan: COA default, cost center, fee type,
            //     kas/bank, periode fiskal, dan konfigurasi penomoran ---------
            FinanceConfigSeeder::class,

            // --- Akun login (paling akhir: butuh siswa & pegawai) ------
            UserSeeder::class,
        ]);
    }
}
