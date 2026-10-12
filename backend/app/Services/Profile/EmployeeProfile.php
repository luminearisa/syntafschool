<?php

namespace App\Services\Profile;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profil 360° pegawai (guru/staf). Data SDM yang sensitif (identitas resmi,
 * gaji, catatan SDM) hanya tampil bagi pemegang izin SDM yang sesuai.
 */
final class EmployeeProfile extends ProfileProvider
{
    private const HR_READ = ['hr.employee.view', 'hr.employee.view.all', 'employee.update'];

    public function type(): string
    {
        return 'employees';
    }

    public function find(int $id): object
    {
        return Employee::query()->with(['gender', 'religion'])->findOrFail($id);
    }

    public function header(object $e): array
    {
        /** @var Employee $e */
        return [
            'title' => $e->full_name,
            'subtitle' => trim(($e->position ?? '').($e->nip ? ' · NIP '.$e->nip : ''), ' ·'),
            'initials' => self::initials($e->full_name)[0],
            'photo_path' => $e->photo_path,
            'is_active' => (bool) $e->is_active,
            'badges' => array_values(array_filter([
                ['label' => $e->is_active ? 'Aktif' : 'Nonaktif', 'tone' => $e->is_active ? 'green' : 'gray'],
                $e->is_teacher ? ['label' => 'Guru', 'tone' => 'blue'] : null,
                $e->employment_status ? ['label' => ucfirst((string) $e->employment_status), 'tone' => 'amber'] : null,
            ])),
            'facts' => array_values(array_filter([
                $e->join_date ? ['label' => 'Bergabung', 'value' => $e->join_date->translatedFormat('j F Y')] : null,
                $e->phone ? ['label' => 'Telepon', 'value' => $e->phone] : null,
                $e->email ? ['label' => 'Email', 'value' => $e->email] : null,
            ])),
        ];
    }

    public function summary(object $e, User $user): array
    {
        $id = $e->id;
        $items = [];
        if ($e->join_date) {
            $months = $e->join_date->diffInMonths(now());
            $items[] = ['Masa kerja', intdiv($months, 12).' th '.($months % 12).' bln', 'text', null, null];
        }
        if ($e->is_teacher || Schema::hasTable('class_subjects')) {
            $classes = Block::count('school_classes', ['homeroom_teacher_id' => $id]);
            $teach = Block::count('class_subjects', ['employee_id' => $id]);
            if ($teach > 0 || $classes > 0) {
                $items[] = ['Mengajar (kelas × mapel)', $teach, 'number', null, null];
                $items[] = ['Wali kelas', $classes, 'number', null, null];
            }
        }
        if ($this->allowed($user, ['hr.leave.view', 'hr.employee.view.all']) && Schema::hasTable('hr_leave_balances')) {
            $avail = DB::table('hr_leave_balances')->where('employee_id', $id)->sum('available_days');
            $items[] = ['Sisa cuti (hari)', round((float) $avail, 1), 'number', null, null];
        }
        $att = $this->attendanceRate($id, 30);
        if ($att !== null) {
            $items[] = ['Kehadiran 30 hari', $att, 'percent', $att >= 90 ? 'green' : 'amber', null];
        }

        return $items === [] ? [] : [Block::stats($items)];
    }

    protected function definitions(): array
    {
        return [
            'profil' => ['label' => 'Profil', 'icon' => 'user', 'permissions' => [], 'build' => fn ($e, $u) => $this->profil($e, $u)],
            'kepegawaian' => ['label' => 'Kepegawaian', 'icon' => 'briefcase', 'permissions' => ['hr.employee.view', 'hr.employment.view', 'hr.contract.view', 'hr.employee.view.all', 'employee.update'], 'build' => fn ($e, $u) => $this->kepegawaian($e, $u)],
            'kualifikasi' => ['label' => 'Kualifikasi', 'icon' => 'award', 'permissions' => ['hr.employee.view', 'hr.certification.view', 'hr.skills.view', 'hr.skill.view', 'hr.document.view', 'employee.update'], 'build' => fn ($e) => $this->kualifikasi($e)],
            'mengajar' => ['label' => 'Mengajar', 'icon' => 'book', 'permissions' => ['academic.view'], 'build' => fn ($e) => $this->mengajar($e)],
            'kehadiran' => ['label' => 'Kehadiran', 'icon' => 'clock', 'permissions' => ['hr.attendance.view', 'hr.employee.view.all', 'employee.update'], 'build' => fn ($e) => $this->kehadiran($e)],
            'cuti' => ['label' => 'Cuti & Lembur', 'icon' => 'calendar', 'permissions' => ['hr.leave.view', 'hr.overtime.view', 'hr.employee.view.all'], 'build' => fn ($e) => $this->cuti($e)],
            'gaji' => ['label' => 'Gaji & Tunjangan', 'icon' => 'wallet', 'permissions' => ['hr.payroll.view', 'hr.compensation.view', 'hr.benefits.view'], 'build' => fn ($e, $u) => $this->gaji($e, $u)],
            'kinerja' => ['label' => 'Kinerja & Pengembangan', 'icon' => 'chart', 'permissions' => ['hr.performance.view', 'hr.training.view', 'hr.learning.view', 'supervisi.visit.view.all', 'supervisi.appraisal.view'], 'build' => fn ($e, $u) => $this->kinerja($e, $u)],
            'peran' => ['label' => 'Peran & Tanggung Jawab', 'icon' => 'users', 'permissions' => ['employee.view'], 'build' => fn ($e, $u) => $this->peran($e, $u)],
            'akun' => ['label' => 'Akun & Keanggotaan', 'icon' => 'key', 'permissions' => ['user.view', 'wallet.view', 'library.member.view'], 'build' => fn ($e, $u) => $this->akun($e, $u)],
            'riwayat' => ['label' => 'Riwayat Perubahan', 'icon' => 'history', 'permissions' => ['employee.update'], 'build' => fn ($e) => $this->riwayat($e)],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function profil(Employee $e, User $user): array
    {
        $id = $e->id;
        $blocks = [
            Block::kv('Identitas', [
                'Nama lengkap' => $e->full_name,
                'NIP' => $e->nip,
                'Jenis kelamin' => $e->gender?->name,
                'Agama' => $e->religion?->name,
                'Tempat, tanggal lahir' => trim(($e->birth_place ?? '').($e->birth_date ? ', '.$e->birth_date->translatedFormat('j F Y') : ''), ', '),
                'Usia' => $e->birth_date ? $e->birth_date->age.' tahun' : null,
            ]),
            Block::kv('Kepegawaian', [
                'Jabatan' => $e->position,
                'Jenis pegawai' => $e->employee_type,
                'Status kepegawaian' => [$e->employment_status, 'badge'],
                'Tanggal bergabung' => [$e->join_date?->toDateString(), 'date'],
                'Guru' => [(bool) $e->is_teacher, 'bool'],
                'Aktif' => [(bool) $e->is_active, 'bool'],
            ]),
            Block::kv('Kontak', ['Alamat' => $e->address, 'Telepon' => $e->phone, 'Email' => $e->email]),
        ];

        if ($this->allowed($user, self::HR_READ)) {
            $blocks[] = ProfileTable::make('Alamat tambahan', 'hr_employee_addresses')->where('employee_id', $id)
                ->columns(['address_type' => 'Jenis', 'address' => 'Alamat', 'city' => 'Kota', 'province' => 'Provinsi', 'is_primary' => ['Utama', 'bool']])->toBlock();
            $blocks[] = ProfileTable::make('Kontak tambahan', 'hr_employee_contacts')->where('employee_id', $id)
                ->columns(['contact_type' => 'Jenis', 'value' => 'Nilai', 'label' => 'Label', 'is_primary' => ['Utama', 'bool']])->toBlock();
            $blocks[] = ProfileTable::make('Kontak darurat', 'hr_emergency_contacts')->where('employee_id', $id)
                ->columns(['name' => 'Nama', 'relationship_code' => 'Hubungan', 'phone' => 'Telepon', 'mobile' => 'HP', 'is_primary' => ['Utama', 'bool']])->latest('priority')->toBlock();
            $blocks[] = ProfileTable::make('Tanggungan keluarga', 'hr_employee_dependents')->where('employee_id', $id)
                ->columns(['name' => 'Nama', 'relationship_code' => 'Hubungan', 'gender' => 'JK', 'birth_date' => ['Lahir', 'date'], 'occupation' => 'Pekerjaan', 'dependent_status' => 'Status'])->toBlock();
        }
        if ($this->allowed($user, ['hr.employee.sensitive.view'])) {
            $blocks[] = ProfileTable::make('Identitas resmi (sensitif)', 'hr_employee_identifiers')->where('employee_id', $id)
                ->columns(['type_code' => 'Jenis', 'number' => 'Nomor', 'issued_date' => ['Terbit', 'date'], 'expiry_date' => ['Berlaku sampai', 'date'], 'is_verified' => ['Terverifikasi', 'bool']])->toBlock();
        }

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function kepegawaian(Employee $e, User $user): array
    {
        $id = $e->id;
        $pos = fn (ProfileTable $t) => $t->resolve('position_id', 'hr_positions', 'title')->resolve('department_id', 'hr_departments', 'name')->resolve('organization_id', 'hr_organizations', 'name');

        return [
            $pos(ProfileTable::make('Penugasan / jabatan', 'hr_employee_assignments')->where('employee_id', $id))
                ->columns(['position_id' => 'Jabatan', 'department_id' => 'Departemen', 'organization_id' => 'Unit', 'assignment_type' => 'Jenis', 'is_primary' => ['Utama', 'bool'], 'start_date' => ['Mulai', 'date'], 'end_date' => ['Selesai', 'date'], 'status' => ['Status', 'badge']])->latest('start_date')->toBlock(),
            $pos(ProfileTable::make('Riwayat kepegawaian', 'hr_employment_records')->where('employee_id', $id))
                ->columns(['employment_number' => 'Nomor', 'employment_type_code' => 'Jenis', 'worker_type_code' => 'Pekerja', 'position_id' => 'Jabatan', 'department_id' => 'Departemen', 'start_date' => ['Mulai', 'date'], 'end_date' => ['Selesai', 'date'], 'status' => ['Status', 'badge']])->latest('start_date')->toBlock(),
            ProfileTable::make('Kontrak', 'hr_contracts')->where('employee_id', $id)->resolve('contract_type_id', 'hr_contract_types', 'name')->resolve('position_id', 'hr_positions', 'title')
                ->columns(['contract_number' => 'Nomor', 'contract_type_id' => 'Jenis', 'position_id' => 'Jabatan', 'start_date' => ['Mulai', 'date'], 'end_date' => ['Berakhir', 'date'], 'renewal_date' => ['Perpanjangan', 'date'], 'is_signed' => ['Ditandatangani', 'bool'], 'status' => ['Status', 'badge']])->latest('start_date')->toBlock(),
            ProfileTable::make('Atasan', 'hr_employee_managers')->where('employee_id', $id)->resolve('manager_employee_id', 'employees', 'full_name')
                ->columns(['manager_employee_id' => 'Atasan', 'relationship_type' => 'Jenis', 'is_primary' => ['Utama', 'bool'], 'start_date' => ['Mulai', 'date'], 'end_date' => ['Selesai', 'date']])->latest('start_date')->toBlock(),
            ProfileTable::make('Riwayat perubahan status', 'hr_employee_status_histories')->where('employee_id', $id)
                ->columns(['effective_date' => ['Tanggal', 'date'], 'from_status' => 'Dari', 'to_status' => 'Menjadi', 'reason' => 'Alasan'])->latest('effective_date')->toBlock(),
            ProfileTable::make('Lini masa karier', 'hr_employee_timeline_events')->where('employee_id', $id)
                ->columns(['occurred_at' => ['Tanggal', 'date'], 'category' => 'Kategori', 'title' => 'Peristiwa', 'description' => 'Keterangan'])->latest('occurred_at')->limit(25)->toBlock(),
            $this->allowed($user, ['hr.note.view.sensitive']) ? ProfileTable::make('Catatan SDM (sensitif)', 'hr_notes')->where('employee_id', $id)->columns(['created_at' => ['Tanggal', 'datetime'], 'note_type' => 'Jenis', 'body' => 'Catatan', 'visibility' => 'Visibilitas'])->latest('created_at')->toBlock() : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function kualifikasi(Employee $e): array
    {
        $id = $e->id;

        return [
            ProfileTable::make('Pendidikan', 'hr_employee_education')->where('employee_id', $id)
                ->columns(['institution' => 'Institusi', 'level_code' => 'Jenjang', 'degree' => 'Gelar', 'major' => 'Jurusan', 'start_year' => ['Mulai', 'number'], 'end_year' => ['Lulus', 'number'], 'is_highest' => ['Tertinggi', 'bool'], 'verified' => ['Terverifikasi', 'bool']])->latest('end_year')->toBlock(),
            ProfileTable::make('Sertifikasi', 'hr_employee_certifications')->where('employee_id', $id)
                ->columns(['certificate_name' => 'Sertifikat', 'issuer' => 'Penerbit', 'issued_date' => ['Terbit', 'date'], 'expiry_date' => ['Kedaluwarsa', 'date'], 'status' => ['Status', 'badge'], 'verified' => ['Terverifikasi', 'bool']])->latest('issued_date')->toBlock(),
            ProfileTable::make('Keahlian', 'hr_employee_skills')->where('employee_id', $id)->resolve('skill_id', 'hr_skills', 'name')
                ->columns(['skill_id' => 'Keahlian', 'proficiency_level_code' => 'Level', 'years_experience' => ['Pengalaman (th)', 'number'], 'verified' => ['Terverifikasi', 'bool']])->toBlock(),
            ProfileTable::make('Dokumen kepegawaian', 'hr_employee_documents')->where('employee_id', $id)->resolve('document_type_id', 'hr_document_types', 'name')
                ->columns(['document_type_id' => 'Jenis', 'title' => 'Judul', 'document_number' => 'Nomor', 'issue_date' => ['Terbit', 'date'], 'expiry_date' => ['Kedaluwarsa', 'date'], 'status' => ['Status', 'badge'], 'verified' => ['Terverifikasi', 'bool']])->latest('issue_date')->toBlock(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function mengajar(Employee $e): array
    {
        $id = $e->id;
        $blocks = [];

        $blocks[] = ProfileTable::make('Mata pelajaran diampu', 'class_subjects')->where('employee_id', $id)
            ->columns(['academic_year_id' => 'Tahun ajaran', 'school_class_id' => 'Kelas', 'subject_id' => 'Mapel', 'weekly_hours' => ['JP / minggu', 'number']])->latest('academic_year_id')->limit(60)->toBlock();
        $blocks[] = ProfileTable::make('Wali kelas', 'school_classes')->where('homeroom_teacher_id', $id)
            ->columns(['academic_year_id' => 'Tahun ajaran', 'name' => 'Kelas', 'code' => 'Kode', 'capacity' => ['Kapasitas', 'number'], 'is_active' => ['Aktif', 'bool']])->latest('academic_year_id')->toBlock();

        if (Schema::hasTable('schedules')) {
            $rows = DB::table('schedules as sc')
                ->leftJoin('days as d', 'd.id', '=', 'sc.day_id')->leftJoin('subjects as s', 's.id', '=', 'sc.subject_id')->leftJoin('school_classes as c', 'c.id', '=', 'sc.school_class_id')
                ->where('sc.employee_id', $id)->orderBy('sc.day_id')->orderBy('sc.start_time')->limit(80)
                ->get(['d.name as day', 'sc.start_time', 'sc.end_time', 'c.name as class', 's.name as subject'])
                ->map(fn ($r) => ['day' => $r->day, 'time' => substr((string) $r->start_time, 0, 5).'–'.substr((string) $r->end_time, 0, 5), 'class' => $r->class, 'subject' => $r->subject]);
            $blocks[] = Block::table('Jadwal mengajar', [['day', 'Hari'], ['time', 'Jam'], ['class', 'Kelas'], ['subject', 'Mapel']], $rows);
        }

        $blocks[] = Block::stats([
            ['Jurnal mengajar', Block::count('teaching_journals', ['teacher_id' => $id])],
            ['Penilaian dibuat', Block::count('assessments', ['employee_id' => $id])],
            ['Tugas dibuat', Block::count('assignments', ['teacher_id' => $id])],
        ]);
        $blocks[] = ProfileTable::make('Jurnal mengajar terbaru', 'teaching_journals')->where('teacher_id', $id)
            ->columns(['date' => ['Tanggal', 'date'], 'class_id' => 'Kelas', 'subject_id' => 'Mapel', 'topic' => 'Topik', 'student_present_count' => ['Hadir', 'number'], 'status' => ['Status', 'badge']])->latest('date')->limit(15)->toBlock();
        $blocks[] = ProfileTable::make('Penilaian terbaru', 'assessments')->where('employee_id', $id)
            ->columns(['date' => ['Tanggal', 'date'], 'name' => 'Penilaian', 'school_class_id' => 'Kelas', 'subject_id' => 'Mapel', 'max_score' => ['Maks', 'number'], 'status' => ['Status', 'badge']])->latest('date')->limit(15)->toBlock();
        $blocks[] = ProfileTable::make('Tugas terbaru', 'assignments')->where('teacher_id', $id)
            ->columns(['title' => 'Tugas', 'class_id' => 'Kelas', 'subject_id' => 'Mapel', 'due_date' => ['Tenggat', 'date'], 'status' => ['Status', 'badge']])->latest('due_date')->limit(15)->toBlock();
        $blocks[] = ProfileTable::make('Kelas daring (LMS)', 'lms_courses')->where('teacher_id', $id)
            ->columns(['code' => 'Kode', 'name' => 'Kelas', 'status' => ['Status', 'badge'], 'start_date' => ['Mulai', 'date'], 'end_date' => ['Selesai', 'date']])->latest('start_date')->toBlock();

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function kehadiran(Employee $e): array
    {
        $id = $e->id;
        $blocks = [];
        $rate = $this->attendanceRate($id, 30);
        if ($rate !== null) {
            $blocks[] = Block::stats([['Kehadiran 30 hari', $rate, 'percent', $rate >= 90 ? 'green' : 'amber']]);
        }
        $blocks[] = ProfileTable::make('Absensi pegawai', 'employee_attendances')->where('employee_id', $id)->resolve('attendance_status_id', 'attendance_statuses', 'name')->resolve('leave_type_id', 'leave_types', 'name')
            ->columns(['date' => ['Tanggal', 'date'], 'attendance_status_id' => 'Status', 'check_in' => 'Masuk', 'check_out' => 'Pulang', 'leave_type_id' => 'Jenis izin', 'notes' => 'Catatan'])->latest('date')->limit(30)->toBlock();
        $blocks[] = ProfileTable::make('Catatan kehadiran SDM', 'hr_attendance_records')->where('employee_id', $id)
            ->columns(['date' => ['Tanggal', 'date'], 'clock_in' => 'Masuk', 'clock_out' => 'Pulang', 'worked_minutes' => ['Menit kerja', 'number'], 'late_minutes' => ['Menit terlambat', 'number'], 'status' => ['Status', 'badge']])->latest('date')->limit(30)->toBlock();

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function cuti(Employee $e): array
    {
        $id = $e->id;

        return [
            ProfileTable::make('Saldo cuti', 'hr_leave_balances')->where('employee_id', $id)->resolve('leave_type_id', 'hr_leave_types', 'name')
                ->columns(['leave_type_id' => 'Jenis cuti', 'period_code' => 'Periode', 'entitlement_days' => ['Hak', 'number'], 'used_days' => ['Terpakai', 'number'], 'pending_days' => ['Menunggu', 'number'], 'available_days' => ['Tersedia', 'number']])->latest('period_code')->toBlock(),
            ProfileTable::make('Pengajuan cuti', 'hr_leave_requests')->where('employee_id', $id)->resolve('leave_type_id', 'hr_leave_types', 'name')
                ->columns(['code' => 'Kode', 'leave_type_id' => 'Jenis', 'start_date' => ['Mulai', 'date'], 'end_date' => ['Selesai', 'date'], 'days' => ['Hari', 'number'], 'status' => ['Status', 'badge'], 'reason' => 'Alasan'])->latest('start_date')->limit(25)->toBlock(),
            ProfileTable::make('Lembur', 'hr_overtime_records')->where('employee_id', $id)
                ->columns(['date' => ['Tanggal', 'date'], 'actual_minutes' => ['Menit', 'number'], 'verified_minutes' => ['Diverifikasi (menit)', 'number'], 'amount' => ['Nilai', 'money'], 'status' => ['Status', 'badge']])->latest('date')->limit(20)->toBlock(),
            ProfileTable::make('Perjalanan dinas', 'hr_business_trips')->where('employee_id', $id)
                ->columns(['code' => 'Kode', 'title' => 'Judul', 'destination' => 'Tujuan', 'start_date' => ['Mulai', 'date'], 'end_date' => ['Selesai', 'date'], 'status' => ['Status', 'badge']])->latest('start_date')->toBlock(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function gaji(Employee $e, User $user): array
    {
        $id = $e->id;
        $blocks = [Block::note('Data penggajian bersifat rahasia. Akses ini tercatat.', 'warn')];

        if ($this->allowed($user, ['hr.payroll.view', 'hr.compensation.view'])) {
            $blocks[] = ProfileTable::make('Penetapan gaji pokok', 'hr_employee_salary_assignments')->where('employee_id', $id)
                ->columns(['effective_from' => ['Berlaku mulai', 'date'], 'effective_to' => ['Sampai', 'date'], 'base_amount' => ['Gaji pokok', 'money'], 'status' => ['Status', 'badge'], 'reason' => 'Alasan'])->latest('effective_from')->toBlock();
            $blocks[] = ProfileTable::make('Slip gaji', 'hr_payslips')->where('employee_id', $id)
                ->columns(['period_code' => 'Periode', 'number' => 'Nomor', 'gross_amount' => ['Bruto', 'money'], 'deduction_total' => ['Potongan', 'money'], 'tax_total' => ['Pajak', 'money'], 'net_amount' => ['Neto', 'money'], 'status' => ['Status', 'badge']])->latest('issued_at', 'period_code')->limit(24)->toBlock();
        }
        if ($this->allowed($user, ['hr.benefits.view', 'hr.compensation.view'])) {
            $blocks[] = ProfileTable::make('Tunjangan / benefit', 'hr_benefit_enrollments')->where('employee_id', $id)
                ->columns(['code' => 'Kode', 'coverage_start' => ['Mulai', 'date'], 'coverage_end' => ['Selesai', 'date'], 'employee_contribution' => ['Iuran pegawai', 'money'], 'employer_contribution' => ['Iuran sekolah', 'money'], 'status' => ['Status', 'badge']])->latest('coverage_start')->toBlock();
        }

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function kinerja(Employee $e, User $user): array
    {
        $id = $e->id;
        $blocks = [];

        if ($this->allowed($user, ['hr.performance.view'])) {
            $blocks[] = ProfileTable::make('Sasaran kinerja', 'hr_performance_goals')->where('employee_id', $id)
                ->columns(['title' => 'Sasaran', 'goal_type' => 'Jenis', 'target_value' => ['Target', 'number'], 'progress' => ['Capaian (%)', 'number'], 'due_date' => ['Tenggat', 'date'], 'status' => ['Status', 'badge']])->latest('due_date')->toBlock();
            $blocks[] = ProfileTable::make('Hasil KPI', 'hr_kpi_results')->where('employee_id', $id)->resolve('kpi_id', 'hr_kpi_definitions', 'name')
                ->columns(['kpi_id' => 'KPI', 'period_code' => 'Periode', 'value' => ['Nilai', 'number'], 'target' => ['Target', 'number'], 'achievement' => ['Capaian (%)', 'number']])->latest('recorded_at')->toBlock();
        }
        if ($this->allowed($user, ['hr.training.view', 'hr.learning.view'])) {
            $blocks[] = ProfileTable::make('Pelatihan selesai', 'hr_training_completions')->where('employee_id', $id)
                ->columns(['code' => 'Kode', 'completed_at' => ['Selesai', 'date'], 'score' => ['Nilai', 'number'], 'result' => ['Hasil', 'badge'], 'certificate_number' => 'No. sertifikat'])->latest('completed_at')->toBlock();
            $blocks[] = ProfileTable::make('Pelatihan ditugaskan', 'hr_training_assignments')->where('employee_id', $id)->resolve('training_id', 'hr_training_catalog', 'title')
                ->columns(['training_id' => 'Pelatihan', 'scheduled_start' => ['Mulai', 'date'], 'scheduled_end' => ['Selesai', 'date'], 'status' => ['Status', 'badge']])->latest('scheduled_start')->toBlock();
        }
        if ($this->allowed($user, ['supervisi.visit.view.all', 'supervisi.appraisal.view'])) {
            $blocks[] = ProfileTable::make('Supervisi (sebagai yang disupervisi)', 'sup_visits')->where('supervisee_employee_id', $id)->resolve('supervisor_employee_id', 'employees', 'full_name')
                ->columns(['code' => 'Kode', 'observed_at' => ['Tanggal', 'datetime'], 'activity_kind' => 'Jenis', 'supervisor_employee_id' => 'Supervisor', 'final_score' => ['Skor', 'number'], 'grade_label' => 'Predikat', 'status' => ['Status', 'badge']])->latest('observed_at')->limit(20)->toBlock();
            $blocks[] = ProfileTable::make('Supervisi (sebagai supervisor)', 'sup_visits')->where('supervisor_employee_id', $id)->resolve('supervisee_employee_id', 'employees', 'full_name')
                ->columns(['code' => 'Kode', 'observed_at' => ['Tanggal', 'datetime'], 'supervisee_employee_id' => 'Guru dinilai', 'final_score' => ['Skor', 'number'], 'status' => ['Status', 'badge']])->latest('observed_at')->limit(20)->toBlock();
        }

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function peran(Employee $e, User $user): array
    {
        $id = $e->id;
        $blocks = [];

        if ($this->allowed($user, ['boarding.view', 'boarding.musyrif.view'])) {
            $blocks[] = ProfileTable::make('Musyrif asrama', 'boarding_musyrifs')->where('employee_id', $id)->resolve('dormitory_id', 'boarding_dormitories', 'name')->resolve('room_id', 'boarding_rooms', 'name')
                ->columns(['dormitory_id' => 'Asrama', 'room_id' => 'Kamar', 'position' => 'Posisi', 'start_date' => ['Mulai', 'date'], 'end_date' => ['Selesai', 'date'], 'status' => ['Status', 'badge']])->latest('start_date')->toBlock();
            $blocks[] = ProfileTable::make('Pembina kelompok asrama', 'boarding_groups')->where('musyrif_employee_id', $id)->columns(['code' => 'Kode', 'name' => 'Kelompok', 'status' => ['Status', 'badge']])->toBlock();
        }
        if ($this->allowed($user, ['tahfiz.view'])) {
            $blocks[] = ProfileTable::make('Halaqah (pengampu)', 'tahfiz_halaqahs')->where('instructor_id', $id)->columns(['code' => 'Kode', 'name' => 'Halaqah', 'schedule_summary' => 'Jadwal', 'status' => ['Status', 'badge']])->toBlock();
        }
        if ($this->allowed($user, ['activity.view'])) {
            $blocks[] = ProfileTable::make('Pembina ekskul', 'activity_program_coaches')->where('employee_id', $id)->resolve('program_id', 'activity_programs', 'name')
                ->columns(['program_id' => 'Program', 'role' => 'Peran', 'starts_on' => ['Mulai', 'date'], 'ends_on' => ['Selesai', 'date'], 'is_active' => ['Aktif', 'bool']])->toBlock();
            $blocks[] = ProfileTable::make('Pembimbing / pelatih program', 'activity_programs')->where('coach_employee_id', $id)->columns(['code' => 'Kode', 'name' => 'Program', 'status' => ['Status', 'badge'], 'starts_on' => ['Mulai', 'date']])->toBlock();
        }
        if ($this->allowed($user, ['uks.officer.view', 'uks.view'])) {
            $blocks[] = ProfileTable::make('Petugas UKS', 'uks_officers')->where('employee_id', $id)->toBlock();
        }
        if ($this->allowed($user, ['asset.view'])) {
            $blocks[] = ProfileTable::make('Penanggung jawab aset', 'asset_custodians')->where('employee_id', (string) $id)->columns(['name' => 'Nama', 'custodian_type' => 'Jenis', 'position' => 'Jabatan', 'is_active' => ['Aktif', 'bool']])->toBlock();
        }
        $blocks[] = ProfileTable::make('Dosen/guru pembimbing akademik', 'academic_advisories')->where('advisor_employee_id', $id)->columns(['student_id' => 'Siswa', 'type' => 'Jenis', 'title' => 'Judul', 'date' => ['Tanggal', 'date'], 'status' => ['Status', 'badge']])->latest('date')->limit(15)->toBlock();

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function akun(Employee $e, User $viewer): array
    {
        $blocks = [];
        $morph = $e->getMorphClass();

        if ($this->allowed($viewer, ['user.view']) && Schema::hasTable('users')) {
            $account = DB::table('users')->where('userable_type', $morph)->where('userable_id', $e->id)->first();
            if ($account) {
                $roles = Schema::hasTable('model_has_roles')
                    ? DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.model_id', $account->id)->where('m.model_type', User::class)->pluck('r.name')->implode(', ')
                    : null;
                $identities = Schema::hasTable('user_identities') ? DB::table('user_identities')->where('user_id', $account->id)->pluck('identity_type')->implode(', ') : null;
                $blocks[] = Block::kv('Akun login', [
                    'Nama pengguna' => $account->username, 'Email' => $account->email, 'Aktif' => [(bool) $account->is_active, 'bool'],
                    'Login terakhir' => [$account->last_login_at, 'datetime'], 'Peran' => $roles ?: null, 'Identitas tertaut (wali/siswa)' => $identities ?: null,
                ]);
            } else {
                $blocks[] = Block::note('Pegawai ini belum memiliki akun login.');
            }
        }
        if ($this->allowed($viewer, ['wallet.view']) && Schema::hasTable('wallets')) {
            $w = DB::table('wallets')->where('employee_id', $e->id)->first();
            $blocks[] = $w ? Block::kv('Dompet', ['Nomor' => $w->number, 'Saldo' => [(float) $w->balance, 'money'], 'Status' => [$w->status, 'badge']]) : Block::note('Tidak memiliki dompet.');
        }
        if ($this->allowed($viewer, ['library.member.view']) && Schema::hasTable('library_members')) {
            $m = DB::table('library_members')->where('employee_id', $e->id)->first();
            $blocks[] = $m ? Block::kv('Anggota perpustakaan', ['No. anggota' => $m->member_number, 'Status' => [$m->status, 'badge'], 'Berlaku sampai' => [$m->valid_until, 'date']]) : Block::note('Bukan anggota perpustakaan.');
        }

        return $blocks === [] ? [Block::note('Tidak ada data yang dapat Anda lihat.')] : $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function riwayat(Employee $e): array
    {
        if (! Schema::hasTable('activity_log')) {
            return [];
        }
        $rows = DB::table('activity_log as l')
            ->leftJoin('users as u', function ($j) {
                $j->on('u.id', '=', 'l.causer_id')->where('l.causer_type', '=', User::class);
            })
            ->where('l.subject_type', $e->getMorphClass())->where('l.subject_id', $e->id)
            ->orderByDesc('l.created_at')->limit(40)
            ->get(['l.created_at', 'l.event', 'l.description', 'u.name as causer'])->map(fn ($r) => (array) $r);

        return [Block::table('Perubahan data pegawai', [['created_at', 'Waktu', 'datetime'], ['event', 'Aksi'], ['description', 'Keterangan'], ['causer', 'Oleh']], $rows)];
    }

    private function attendanceRate(int $id, int $days): ?float
    {
        if (! Schema::hasTable('employee_attendances')) {
            return null;
        }
        $rows = DB::table('employee_attendances as a')->join('attendance_statuses as s', 's.id', '=', 'a.attendance_status_id')
            ->where('a.employee_id', $id)->where('a.date', '>=', now()->subDays($days)->toDateString())
            ->groupBy('s.code')->selectRaw('s.code as code, count(*) as c')->pluck('c', 'code');
        $total = (int) $rows->sum();
        if ($total === 0) {
            return null;
        }
        $present = (int) collect($rows)->filter(fn ($c, $code) => in_array(strtoupper((string) $code), ['H', 'T', 'PRESENT', 'LATE'], true))->sum();

        return round($present / $total * 100, 1);
    }
}
