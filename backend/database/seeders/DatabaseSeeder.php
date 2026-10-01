<?php

namespace Database\Seeders;

use App\Modules\LMS\Database\Seeders\LmsActivityDemoSeeder;
use App\Modules\LMS\Database\Seeders\LmsGradebookDemoSeeder;
use App\Modules\LMS\Database\Seeders\LmsDemoSeeder;
use App\Modules\LMS\Database\Seeders\LmsPermissionSeeder;
use App\Modules\CBT\Database\Seeders\CbtPermissionSeeder;
use App\Modules\Clinic\Database\Seeders\ClinicMasterSeeder;
use App\Modules\Clinic\Database\Seeders\ClinicPermissionSeeder;
use App\Modules\Supervisi\Database\Seeders\SupInstrumentSeeder;
use App\Modules\Supervisi\Database\Seeders\SupPermissionSeeder;
use App\Modules\UKS\Database\Seeders\UksPermissionSeeder;
use App\Modules\Activity\Database\Seeders\ActivityMasterSeeder;
use App\Modules\Activity\Database\Seeders\ActivityPermissionSeeder;
use App\Modules\Workflow\Database\Seeders\WorkflowPermissionSeeder;
use App\Modules\Workflow\Database\Seeders\WorkflowReferenceSeeder;
use App\Modules\Bk\Database\Seeders\BkEarlyWarningRuleSeeder;
use App\Modules\Bk\Database\Seeders\BkMasterSeeder;
use App\Modules\Bk\Database\Seeders\BkPermissionSeeder;
use App\Modules\Bk\Database\Seeders\BkPhase2PermissionSeeder;
use App\Modules\Bk\Database\Seeders\BkRetentionPolicySeeder;
use App\Modules\Bk\Database\Seeders\BkSlaPolicySeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(FinanceStandardSeeder::class);
        $this->call([
            // --- Fondasi referensi -------------------------------------
            RolePermissionSeeder::class,
            // Modul LMS: permission granular lms.* (§35).
            LmsPermissionSeeder::class,
            // Modul Supervisi: permission granular supervisi.* + instrumen bawaan.
            SupPermissionSeeder::class,
            // Modul UKS: permission granular uks.*.
            UksPermissionSeeder::class,
            // Modul Student Activity: permission granular activity.* + peran
            // ACTIVITY_*/COACH/MENTOR/JUDGE. Tanpa ini, instalasi baru tidak
            // memiliki peran modul sehingga layarnya tidak dapat dibuka siapa pun.
            ActivityPermissionSeeder::class,
            // Modul Workflow: permission granular workflow.* + peran approver.
            WorkflowPermissionSeeder::class,
            // Modul Bimbingan & Konseling: permission granular bk.* + peran
            // BK_ADMIN/BK_COORDINATOR/COUNSELOR. Aditif: izin modul lain pada
            // peran yang sama tidak pernah dihapus.
            BkPermissionSeeder::class,
            SupInstrumentSeeder::class,
            // Master aktivitas: kategori, keterampilan, tujuan, periode bawaan.
            ActivityMasterSeeder::class,
            // Master BK: label kasus + template asesmen bawaan.
            BkEarlyWarningRuleSeeder::class,
            BkSlaPolicySeeder::class,
            BkRetentionPolicySeeder::class,
            BkPhase2PermissionSeeder::class,
            BkMasterSeeder::class,
            CbtPermissionSeeder::class,
        ClinicPermissionSeeder::class,
        ClinicMasterSeeder::class,
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

            // --- Modul LMS: data contoh (kategori, course publik, course
            //     akademik yang pesertanya tersinkron dari rombel SIAKAD) ---
            LmsDemoSeeder::class,
            LmsActivityDemoSeeder::class,
            LmsGradebookDemoSeeder::class,
        ]);
    }
}
