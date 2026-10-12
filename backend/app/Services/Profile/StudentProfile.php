<?php

namespace App\Services\Profile;

use App\Models\Student;
use App\Models\User;
use App\Modules\UKS\Application\Services\UksAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Profil 360° siswa. */
final class StudentProfile extends ProfileProvider
{
    public function __construct(private readonly UksAccessService $uks) {}

    public function type(): string
    {
        return 'students';
    }

    public function find(int $id): object
    {
        return Student::query()
            ->with(['gender', 'religion', 'status', 'province', 'city', 'district', 'village', 'residenceType', 'transportation'])
            ->findOrFail($id);
    }

    public function header(object $e): array
    {
        /** @var Student $e */
        $class = $this->currentClass($e->id);

        return [
            'title' => $e->full_name,
            'subtitle' => trim(($e->nis ? 'NIS '.$e->nis : '').($e->nisn ? ' · NISN '.$e->nisn : '')),
            'initials' => self::initials($e->full_name)[0],
            'photo_path' => $e->photo_path,
            'is_active' => (bool) $e->is_active,
            'badges' => array_values(array_filter([
                ['label' => $e->is_active ? 'Aktif' : 'Nonaktif', 'tone' => $e->is_active ? 'green' : 'gray'],
                $e->status?->name ? ['label' => $e->status->name, 'tone' => 'blue'] : null,
                $class ? ['label' => 'Kelas '.$class->name, 'tone' => 'amber'] : null,
            ])),
            'facts' => array_values(array_filter([
                $e->gender?->name ? ['label' => 'Jenis kelamin', 'value' => $e->gender->name] : null,
                $e->birth_date ? ['label' => 'Usia', 'value' => $e->birth_date->age.' tahun'] : null,
                $e->phone ? ['label' => 'Telepon', 'value' => $e->phone] : null,
                $e->email ? ['label' => 'Email', 'value' => $e->email] : null,
            ])),
        ];
    }

    public function summary(object $e, User $user): array
    {
        $id = $e->id;
        $items = [];

        if ($this->allowed($user, ['academic.view', 'academic.portal', 'assessment.view'])) {
            $att = $this->attendanceSummary($id, 90);
            if ($att['total'] > 0) {
                $items[] = ['Kehadiran 90 hari', round($att['present'] / $att['total'] * 100, 1), 'percent', $att['present'] / $att['total'] >= 0.9 ? 'green' : 'amber', null];
            }
            $avg = $this->averageScore($id);
            if ($avg !== null) {
                $items[] = ['Rata-rata nilai', round($avg, 1), 'number', null, null];
            }
        }
        if ($this->allowed($user, ['finance.view', 'finance.invoice.view', 'finance.payment.view']) && Schema::hasTable('finance_invoices')) {
            $out = $this->invoiceTotals($id);
            $items[] = ['Sisa tagihan', $out['remaining'], 'money', $out['remaining'] > 0 ? 'amber' : 'green', null];
        }
        if ($this->allowed($user, ['boarding.view', 'boarding.point.view'])) {
            $placement = $this->boardingPlacement($id);
            if ($placement) {
                $items[] = ['Asrama', $placement, 'text', null, null];
            }
        }
        if ($this->allowed($user, ['tahfiz.view'])) {
            $juz = $this->tahfizJuz($id);
            if ($juz !== null) {
                $items[] = ['Hafalan (juz disentuh)', $juz, 'number', null, null];
            }
        }
        $items[] = ['Wali terdaftar', Block::count('student_guardians', ['student_id' => $id]), 'number', null, null];

        return [Block::stats($items)];
    }

    protected function definitions(): array
    {
        return [
            'profil' => ['label' => 'Profil', 'icon' => 'user', 'permissions' => [], 'build' => fn ($e) => $this->profil($e)],
            'akademik' => ['label' => 'Akademik', 'icon' => 'book', 'permissions' => ['academic.view', 'academic.portal', 'assessment.view', 'report-card.view'], 'build' => fn ($e) => $this->akademik($e)],
            'keuangan' => ['label' => 'Keuangan', 'icon' => 'wallet', 'permissions' => ['finance.view', 'finance.invoice.view', 'finance.payment.view'], 'build' => fn ($e) => $this->keuangan($e)],
            'dompet' => ['label' => 'Dompet', 'icon' => 'card', 'permissions' => ['wallet.view'], 'build' => fn ($e) => $this->dompet($e)],
            'asrama' => ['label' => 'Asrama', 'icon' => 'bed', 'permissions' => ['boarding.view', 'boarding.placement.view', 'boarding.point.view'], 'build' => fn ($e) => $this->asrama($e)],
            'tahfiz' => ['label' => 'Tahfiz', 'icon' => 'moon', 'permissions' => ['tahfiz.view'], 'build' => fn ($e) => $this->tahfiz($e)],
            'aktivitas' => ['label' => 'Ekskul & Prestasi', 'icon' => 'trophy', 'permissions' => ['activity.view', 'activity.registration.view', 'activity.membership.view', 'activity.portfolio.view'], 'build' => fn ($e) => $this->aktivitas($e)],
            'kesehatan' => ['label' => 'Kesehatan', 'icon' => 'heart', 'permissions' => ['clinic.patient.view', 'clinic.visit.view', 'clinic.record.view.all', 'uks.record.view', 'uks.visit.view'], 'build' => fn ($e, $u) => $this->kesehatan($e, $u)],
            'konseling' => ['label' => 'Konseling', 'icon' => 'chat', 'permissions' => ['bk.case.view.all', 'bk.manage'], 'build' => fn ($e) => $this->konseling($e)],
            'perpustakaan' => ['label' => 'Perpustakaan', 'icon' => 'library', 'permissions' => ['library.view', 'library.member.view', 'library.circulation.view'], 'build' => fn ($e) => $this->perpustakaan($e)],
            'belajar' => ['label' => 'Belajar Daring & Ujian', 'icon' => 'laptop', 'permissions' => ['lms.enrollment.view', 'lms.report.view', 'cbt.report.view', 'cbt.session.view'], 'build' => fn ($e) => $this->belajar($e)],
            'akun' => ['label' => 'Akun & Asal', 'icon' => 'key', 'permissions' => ['user.view', 'ppdb.view', 'ppdb.registration.view'], 'build' => fn ($e, $u) => $this->akun($e, $u)],
            'riwayat' => ['label' => 'Riwayat Perubahan', 'icon' => 'clock', 'permissions' => ['student.update'], 'build' => fn ($e) => $this->riwayat($e)],
        ];
    }

    // ------------------------------------------------------------------ profil

    /** @return list<array<string, mixed>> */
    private function profil(Student $e): array
    {
        $id = $e->id;
        $region = collect([$e->village?->name, $e->district?->name, $e->city?->name, $e->province?->name])->filter()->implode(', ');
        $class = $this->currentClass($id);

        $guardians = DB::table('student_guardians as sg')
            ->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
            ->leftJoin('guardian_relations as r', 'r.id', '=', 'sg.guardian_relation_id')
            ->leftJoin('occupations as o', 'o.id', '=', 'g.occupation_id')
            ->where('sg.student_id', $id)
            ->orderByDesc('sg.is_primary')
            ->get(['g.id', 'g.full_name', 'r.name as relation', 'o.name as occupation', 'g.phone', 'g.email', 'sg.is_primary'])
            ->map(fn ($r) => [
                'full_name' => $r->full_name, 'relation' => $r->relation, 'occupation' => $r->occupation,
                'phone' => $r->phone, 'email' => $r->email, 'is_primary' => (bool) $r->is_primary,
                '_link' => '/students/guardians/'.$r->id,
            ]);

        $siblings = DB::table('student_guardians as a')
            ->join('student_guardians as b', 'b.guardian_id', '=', 'a.guardian_id')
            ->join('students as s', 's.id', '=', 'b.student_id')
            ->where('a.student_id', $id)->where('b.student_id', '!=', $id)->whereNull('s.deleted_at')
            ->distinct()->get(['s.id', 's.full_name', 's.nis', 's.is_active'])
            ->map(fn ($r) => ['full_name' => $r->full_name, 'nis' => $r->nis, 'is_active' => (bool) $r->is_active, '_link' => '/students/'.$r->id]);

        return [
            Block::kv('Identitas', [
                'Nama lengkap' => $e->full_name,
                'Nama panggilan' => $e->nick_name,
                'NIS' => $e->nis,
                'NISN' => $e->nisn,
                'Jenis kelamin' => $e->gender?->name,
                'Agama' => $e->religion?->name,
                'Tempat, tanggal lahir' => trim(($e->birth_place ?? '').($e->birth_date ? ', '.$e->birth_date->translatedFormat('j F Y') : ''), ', '),
                'Usia' => $e->birth_date ? $e->birth_date->age.' tahun' : null,
                'Golongan darah' => $e->blood_type,
                'Anak ke / jumlah saudara' => $e->child_order || $e->sibling_count ? ($e->child_order ?? '-').' / '.($e->sibling_count ?? '-') : null,
            ]),
            Block::kv('Kontak & alamat', [
                'Alamat' => $e->address,
                'Wilayah' => $region ?: null,
                'Kode pos' => $e->postal_code,
                'Telepon' => $e->phone,
                'Email' => $e->email,
                'Jenis tempat tinggal' => $e->residenceType?->name,
                'Transportasi ke sekolah' => $e->transportation?->name,
            ]),
            Block::kv('Kesiswaan', [
                'Status' => $e->status?->name,
                'Keaktifan' => [$e->is_active, 'bool'],
                'Tanggal masuk' => [$e->admission_date?->toDateString(), 'date'],
                'Sekolah asal' => $e->previous_school,
                'Kelas saat ini' => $class?->name,
                'Terdaftar di sistem' => [$e->created_at?->toDateTimeString(), 'datetime'],
            ]),
            Block::table('Wali murid', [['full_name', 'Nama'], ['relation', 'Hubungan'], ['occupation', 'Pekerjaan'], ['phone', 'Telepon'], ['email', 'Email'], ['is_primary', 'Utama', 'bool']], $guardians),
            $siblings->isEmpty() ? null : Block::table('Saudara (satu wali)', [['full_name', 'Nama'], ['nis', 'NIS'], ['is_active', 'Aktif', 'bool']], $siblings),
            ProfileTable::make('Riwayat kelas & status', 'student_histories')->where('student_id', $id)
                ->columns(['academic_year_id' => 'Tahun ajaran', 'school_class_id' => 'Kelas', 'status' => ['Status', 'badge'], 'note' => 'Catatan'])->latest('academic_year_id')->toBlock(),
            ProfileTable::make('Penempatan kelas', 'class_students')->where('student_id', $id)
                ->columns(['academic_year_id' => 'Tahun ajaran', 'school_class_id' => 'Kelas', 'status' => ['Status', 'badge']])->latest('academic_year_id')->toBlock(),
        ];
    }

    // --------------------------------------------------------------- akademik

    /** @return list<array<string, mixed>> */
    private function akademik(Student $e): array
    {
        $id = $e->id;
        $blocks = [];

        $att = $this->attendanceSummary($id, 180);
        if ($att['total'] > 0) {
            $stats = [['Kehadiran 180 hari', round($att['present'] / $att['total'] * 100, 1), 'percent', $att['present'] / $att['total'] >= 0.9 ? 'green' : 'amber', $att['present'].' dari '.$att['total'].' hari tercatat']];
            foreach ($att['by_status'] as $name => $count) {
                $stats[] = [$name, $count, 'number', null, null];
            }
            $blocks[] = Block::stats($stats);
        } else {
            $blocks[] = Block::note('Belum ada data kehadiran 180 hari terakhir.');
        }

        // Nilai rata-rata per mata pelajaran
        if (Schema::hasTable('scores') && Schema::hasTable('assessments')) {
            $perSubject = DB::table('scores as sc')
                ->join('assessments as a', 'a.id', '=', 'sc.assessment_id')
                ->leftJoin('subjects as s', 's.id', '=', 'a.subject_id')
                ->where('sc.student_id', $id)->whereNotNull('sc.score')->where('a.max_score', '>', 0)
                ->groupBy('s.name')
                ->selectRaw('s.name as subject, count(*) as n, avg(sc.score / a.max_score * 100) as avg_pct')
                ->orderByDesc('avg_pct')->get()
                ->map(fn ($r) => ['subject' => $r->subject ?? '-', 'n' => (int) $r->n, 'avg' => round((float) $r->avg_pct, 1)]);
            $blocks[] = Block::table('Rata-rata nilai per mata pelajaran', [['subject', 'Mata pelajaran'], ['n', 'Penilaian', 'number'], ['avg', 'Rata-rata (%)', 'number']], $perSubject);

            $recent = DB::table('scores as sc')
                ->join('assessments as a', 'a.id', '=', 'sc.assessment_id')
                ->leftJoin('subjects as s', 's.id', '=', 'a.subject_id')
                ->where('sc.student_id', $id)->orderByDesc('a.date')->orderByDesc('sc.id')->limit(20)
                ->get(['a.date', 'a.name as assessment', 's.name as subject', 'sc.score', 'a.max_score', 'sc.grade', 'sc.predicate'])
                ->map(fn ($r) => (array) $r);
            $blocks[] = Block::table('Nilai terbaru', [['date', 'Tanggal', 'date'], ['subject', 'Mapel'], ['assessment', 'Penilaian'], ['score', 'Nilai', 'number'], ['max_score', 'Maks', 'number'], ['grade', 'Predikat']], $recent);
        }

        $blocks[] = ProfileTable::make('Rapor', 'report_cards')->where('student_id', $id)
            ->columns(['academic_year_id' => 'Tahun ajaran', 'semester_id' => 'Semester', 'school_class_id' => 'Kelas', 'type' => 'Jenis', 'status' => ['Status', 'badge'], 'average_score' => ['Rata-rata', 'number'], 'rank' => ['Peringkat', 'number'], 'published_at' => ['Terbit', 'date']])
            ->latest('published_at', 'id')->toBlock();

        $blocks[] = $this->attendanceRows($id);

        if (Schema::hasTable('assignment_submissions') && Schema::hasTable('assignments')) {
            $rows = DB::table('assignment_submissions as su')
                ->join('assignments as a', 'a.id', '=', 'su.assignment_id')
                ->leftJoin('subjects as s', 's.id', '=', 'a.subject_id')
                ->where('su.student_id', $id)->orderByDesc('a.due_date')->limit(20)
                ->get(['a.title', 's.name as subject', 'a.due_date', 'su.submitted_at', 'su.status', 'su.score', 'a.max_score'])
                ->map(fn ($r) => (array) $r);
            $blocks[] = Block::table('Tugas', [['title', 'Tugas'], ['subject', 'Mapel'], ['due_date', 'Tenggat', 'date'], ['submitted_at', 'Dikumpulkan', 'datetime'], ['status', 'Status', 'badge'], ['score', 'Nilai', 'number'], ['max_score', 'Maks', 'number']], $rows);
        }

        $class = $this->currentClass($id);
        if ($class && Schema::hasTable('schedules')) {
            $rows = DB::table('schedules as sc')
                ->leftJoin('days as d', 'd.id', '=', 'sc.day_id')
                ->leftJoin('subjects as s', 's.id', '=', 'sc.subject_id')
                ->leftJoin('employees as t', 't.id', '=', 'sc.employee_id')
                ->where('sc.school_class_id', $class->id)->orderBy('sc.day_id')->orderBy('sc.start_time')->limit(60)
                ->get(['d.name as day', 'sc.start_time', 'sc.end_time', 's.name as subject', 't.full_name as teacher'])
                ->map(fn ($r) => ['day' => $r->day, 'time' => substr((string) $r->start_time, 0, 5).'–'.substr((string) $r->end_time, 0, 5), 'subject' => $r->subject, 'teacher' => $r->teacher]);
            $blocks[] = Block::table('Jadwal pelajaran kelas '.$class->name, [['day', 'Hari'], ['time', 'Jam'], ['subject', 'Mapel'], ['teacher', 'Guru']], $rows);
        }

        foreach ([
            ['Bimbingan akademik', 'academic_advisories', ['semester_id' => 'Semester', 'type' => 'Jenis', 'title' => 'Judul', 'date' => ['Tanggal', 'date'], 'status' => ['Status', 'badge'], 'follow_up' => 'Tindak lanjut'], 'date'],
            ['Peringatan akademik', 'academic_alerts', ['type' => 'Jenis', 'level' => ['Tingkat', 'badge'], 'message' => 'Pesan', 'triggered_at' => ['Terpicu', 'datetime'], 'resolved_at' => ['Selesai', 'datetime']], 'triggered_at'],
            ['Dokumen akademik', 'academic_documents', ['document_type' => 'Jenis', 'document_number' => 'Nomor', 'title' => 'Judul', 'issue_date' => ['Tanggal terbit', 'date']], 'issue_date'],
            ['Kelulusan', 'graduations', ['academic_year_id' => 'Tahun ajaran', 'graduation_number' => 'Nomor', 'decision' => ['Keputusan', 'badge'], 'average_score' => ['Rata-rata', 'number'], 'approved_at' => ['Disetujui', 'date']], 'approved_at'],
        ] as [$title, $table, $cols, $order]) {
            $blocks[] = ProfileTable::make($title, $table)->where('student_id', $id)->columns($cols)->latest($order)->toBlock();
        }

        return $blocks;
    }

    private function attendanceRows(int $id): ?array
    {
        if (! Schema::hasTable('student_attendances')) {
            return null;
        }
        $rows = DB::table('student_attendances as a')
            ->leftJoin('attendance_statuses as s', 's.id', '=', 'a.attendance_status_id')
            ->leftJoin('subjects as sub', 'sub.id', '=', 'a.subject_id')
            ->where('a.student_id', $id)->orderByDesc('a.date')->limit(20)
            ->get(['a.date', 's.name as status', 'sub.name as subject', 'a.time_in', 'a.notes'])
            ->map(fn ($r) => (array) $r);

        return Block::table('Kehadiran terbaru', [['date', 'Tanggal', 'date'], ['status', 'Status', 'badge'], ['subject', 'Mapel'], ['time_in', 'Jam masuk'], ['notes', 'Catatan']], $rows);
    }

    // --------------------------------------------------------------- keuangan

    /** @return list<array<string, mixed>> */
    private function keuangan(Student $e): array
    {
        $id = $e->id;
        $blocks = [];

        if (Schema::hasTable('finance_invoices')) {
            $t = $this->invoiceTotals($id);
            $blocks[] = Block::stats([
                ['Total tagihan', $t['total'], 'money'],
                ['Terbayar', $t['paid'], 'money', 'green'],
                ['Sisa tagihan', $t['remaining'], 'money', $t['remaining'] > 0 ? 'amber' : 'green'],
                ['Lewat jatuh tempo', $t['overdue'], 'money', $t['overdue'] > 0 ? 'red' : null],
            ]);
        }

        $blocks[] = ProfileTable::make('Tagihan', 'finance_invoices')->where('student_id', $id)
            ->columns(['invoice_number' => 'No. tagihan', 'issue_date' => ['Terbit', 'date'], 'due_date' => ['Jatuh tempo', 'date'], 'total' => ['Total', 'money'], 'paid_amount' => ['Dibayar', 'money'], 'remaining_amount' => ['Sisa', 'money'], 'status' => ['Status', 'badge']])
            ->latest('issue_date')->limit(30)->toBlock();
        $blocks[] = ProfileTable::make('Pembayaran', 'finance_payments')->where('student_id', $id)
            ->columns(['payment_number' => 'No. pembayaran', 'paid_at' => ['Dibayar', 'datetime'], 'amount' => ['Jumlah', 'money'], 'method' => 'Metode', 'status' => ['Status', 'badge']])
            ->latest('paid_at', 'created_at')->limit(30)->toBlock();
        $blocks[] = ProfileTable::make('Kuitansi', 'finance_receipts')->where('student_id', $id)
            ->columns(['receipt_number' => 'No. kuitansi', 'received_at' => ['Diterima', 'datetime'], 'amount' => ['Jumlah', 'money'], 'method' => 'Metode', 'is_cancelled' => ['Batal', 'bool']])
            ->latest('received_at')->toBlock();
        $blocks[] = ProfileTable::make('Beasiswa', 'finance_scholarships')->where('student_id', $id)->latest('start_date')->toBlock();
        $blocks[] = ProfileTable::make('Diskon khusus', 'finance_discounts')->where('student_id', $id)->latest('start_date')->toBlock();
        $blocks[] = ProfileTable::make('Rencana cicilan', 'finance_payment_plans')->where('student_id', $id)
            ->columns(['plan_number' => 'No. rencana', 'total_amount' => ['Total', 'money'], 'installment_count' => ['Cicilan', 'number'], 'start_date' => ['Mulai', 'date'], 'status' => ['Status', 'badge']])->latest('start_date')->toBlock();
        $blocks[] = ProfileTable::make('Surat bebas tanggungan', 'finance_clearances')->where('student_id', $id)
            ->columns(['clearance_number' => 'Nomor', 'purpose' => 'Keperluan', 'status' => ['Status', 'badge'], 'outstanding_total' => ['Tunggakan', 'money'], 'evaluated_on' => ['Dievaluasi', 'date']])->latest('evaluated_on')->toBlock();
        $blocks[] = ProfileTable::make('Daftar ulang', 'finance_re_registrations')->where('student_id', $id)
            ->columns(['registration_number' => 'Nomor', 'next_academic_year_id' => 'Tahun ajaran berikut', 'amount' => ['Biaya', 'money'], 'paid_amount' => ['Dibayar', 'money'], 'due_date' => ['Jatuh tempo', 'date'], 'status' => ['Status', 'badge']])->latest('due_date')->toBlock();

        return $blocks;
    }

    // ----------------------------------------------------------------- dompet

    /** @return list<array<string, mixed>> */
    private function dompet(Student $e): array
    {
        if (! Schema::hasTable('wallets')) {
            return [];
        }
        $wallet = DB::table('wallets')->where('student_id', $e->id)->first();
        if (! $wallet) {
            return [Block::note('Siswa ini belum memiliki dompet.')];
        }

        return [
            Block::stats([
                ['Saldo', (float) $wallet->balance, 'money', 'green'],
                ['Ditahan', (float) $wallet->held_balance, 'money'],
            ]),
            Block::kv('Dompet', ['Nomor' => $wallet->number, 'Status' => [$wallet->status, 'badge'], 'Dibuka' => [$wallet->opened_at, 'datetime'], 'Alasan blokir' => $wallet->block_reason]),
            ProfileTable::make('Transaksi terakhir', 'wallet_transactions')->where('wallet_id', $wallet->id)
                ->columns(['posted_at' => ['Waktu', 'datetime'], 'transaction_number' => 'Nomor', 'type' => 'Jenis', 'direction' => 'Arah', 'amount' => ['Jumlah', 'money'], 'balance_after' => ['Saldo akhir', 'money'], 'status' => ['Status', 'badge'], 'description' => 'Keterangan'])
                ->latest('posted_at', 'created_at')->limit(25)->toBlock(),
        ];
    }

    // ----------------------------------------------------------------- asrama

    /** @return list<array<string, mixed>> */
    private function asrama(Student $e): array
    {
        $id = $e->id;
        $blocks = [];

        $points = Block::sum('boarding_violations', ['student_id' => $id], 'points');
        $blocks[] = Block::stats([
            ['Penempatan', $this->boardingPlacement($id) ?? 'Tidak di asrama', 'text'],
            ['Total poin pelanggaran', $points, 'number', $points > 0 ? 'amber' : 'green'],
            ['Prestasi asrama', Block::count('boarding_achievements', ['student_id' => $id]), 'number'],
            ['Izin keluar', Block::count('boarding_permissions', ['student_id' => $id]), 'number'],
            ['Kepulangan', Block::count('boarding_homecomings', ['student_id' => $id]), 'number'],
        ]);

        $blocks[] = ProfileTable::make('Riwayat penempatan', 'boarding_placements')->where('student_id', $id)
            ->resolve('room_id', 'boarding_rooms', 'name')->resolve('bed_id', 'boarding_beds', 'bed_number')
            ->columns(['dormitory_id' => 'Asrama', 'room_id' => 'Kamar', 'bed_id' => 'Kasur', 'start_date' => ['Mulai', 'date'], 'end_date' => ['Selesai', 'date'], 'status' => ['Status', 'badge'], 'end_reason' => 'Alasan akhir'])->latest('start_date')->toBlock();
        $blocks[] = ProfileTable::make('Pelanggaran', 'boarding_violations')->where('student_id', $id)
            ->resolve('violation_type_id', 'boarding_violation_types', 'name')
            ->columns(['date' => ['Tanggal', 'date'], 'violation_type_id' => 'Jenis', 'points' => ['Poin', 'number'], 'narrative' => 'Uraian', 'status' => ['Status', 'badge']])->latest('date')->toBlock();
        $blocks[] = ProfileTable::make('Tindakan disiplin', 'boarding_disciplinary_actions')->where('student_id', $id)->latest('date')->toBlock();
        $blocks[] = ProfileTable::make('Prestasi asrama', 'boarding_achievements')->where('student_id', $id)
            ->columns(['date' => ['Tanggal', 'date'], 'title' => 'Prestasi', 'category' => 'Kategori', 'level' => 'Tingkat', 'description' => 'Keterangan'])->latest('date')->toBlock();
        $blocks[] = ProfileTable::make('Izin keluar', 'boarding_permissions')->where('student_id', $id)
            ->columns(['permission_type' => 'Jenis', 'destination' => 'Tujuan', 'reason' => 'Alasan', 'start_at' => ['Mulai', 'datetime'], 'expected_return_at' => ['Kembali (rencana)', 'datetime'], 'late_return' => ['Terlambat', 'bool'], 'status' => ['Status', 'badge']])->latest('start_at')->toBlock();
        $blocks[] = ProfileTable::make('Kepulangan', 'boarding_homecomings')->where('student_id', $id)
            ->columns(['type' => 'Jenis', 'home_date' => ['Pulang', 'date'], 'return_date' => ['Kembali (rencana)', 'date'], 'actual_return_date' => ['Kembali (aktual)', 'date'], 'late_return' => ['Terlambat', 'bool'], 'status' => ['Status', 'badge']])->latest('home_date')->toBlock();
        $blocks[] = ProfileTable::make('Absensi asrama terbaru', 'boarding_attendances')->where('student_id', $id)
            ->resolve('activity_id', 'boarding_activities', 'name')->resolve('attendance_type_id', 'boarding_attendance_types', 'name')
            ->columns(['date' => ['Tanggal', 'date'], 'activity_id' => 'Kegiatan', 'attendance_type_id' => 'Kehadiran', 'is_late' => ['Terlambat', 'bool'], 'note' => 'Catatan'])->latest('date')->limit(20)->toBlock();
        $blocks[] = ProfileTable::make('Kesehatan di asrama', 'boarding_health_records')->where('student_id', $id)->latest('date')->toBlock();
        $blocks[] = ProfileTable::make('Titipan barang', 'boarding_item_deposits')->where('student_id', $id)->latest('deposited_at')->toBlock();
        $blocks[] = ProfileTable::make('Tamu / penjenguk', 'boarding_visitors')->where('student_id', $id)
            ->columns(['visit_date' => ['Tanggal', 'date'], 'visitor_name' => 'Pengunjung', 'relationship' => 'Hubungan', 'purpose' => 'Keperluan', 'status' => ['Status', 'badge']])->latest('visit_date')->toBlock();
        $blocks[] = ProfileTable::make('Kelompok asrama', 'boarding_group_members')->where('student_id', $id)->latest('joined_date')->toBlock();

        return $blocks;
    }

    // ----------------------------------------------------------------- tahfiz

    /** @return list<array<string, mixed>> */
    private function tahfiz(Student $e): array
    {
        if (! Schema::hasTable('tahfiz_participants')) {
            return [];
        }
        $participant = DB::table('tahfiz_participants')->where('student_id', $e->id)->first();
        if (! $participant) {
            return [Block::note('Siswa ini belum terdaftar sebagai peserta Tahfiz.')];
        }
        $pid = $participant->id;
        $prog = Schema::hasTable('tahfiz_participant_progress')
            ? DB::table('tahfiz_participant_progress')->where('participant_id', $pid)->selectRaw('count(distinct juz) as juz, count(distinct surah_id) as surahs, coalesce(sum(ayahs_memorized),0) as ayahs, coalesce(sum(ayahs_mutqin),0) as mutqin, coalesce(sum(setoran_count),0) as setoran')->first()
            : null;

        return [
            Block::stats([
                ['Juz disentuh', (int) ($prog->juz ?? 0)],
                ['Surah disentuh', (int) ($prog->surahs ?? 0)],
                ['Ayat dihafal', (int) ($prog->ayahs ?? 0)],
                ['Ayat mutqin', (int) ($prog->mutqin ?? 0), 'number', 'green'],
                ['Setoran', (int) ($prog->setoran ?? 0)],
            ]),
            Block::kv('Peserta', ['Nama' => $participant->name, 'Jenis' => $participant->participant_type, 'Status' => [$participant->status, 'badge']]),
            ProfileTable::make('Pendaftaran program', 'tahfiz_enrollments')->where('participant_id', $pid)
                ->resolve('program_id', 'tahfiz_programs', 'name')->resolve('halaqah_id', 'tahfiz_halaqahs', 'name')
                ->columns(['program_id' => 'Program', 'halaqah_id' => 'Halaqah', 'enrolled_at' => ['Terdaftar', 'date'], 'starting_juz' => 'Juz awal', 'status' => ['Status', 'badge']])->latest('enrolled_at')->toBlock(),
            ProfileTable::make('Setoran terbaru', 'tahfiz_memorization_records')->where('participant_id', $pid)
                ->columns(['date' => ['Tanggal', 'date'], 'type' => 'Jenis', 'surah_id' => 'Surah', 'ayah_start' => ['Ayat dari', 'number'], 'ayah_end' => ['Ayat sampai', 'number'], 'score' => ['Nilai', 'number'], 'status' => ['Status', 'badge'], 'teacher_note' => 'Catatan ustadz'])->latest('date')->limit(25)->toBlock(),
            ProfileTable::make('Capaian per surah', 'tahfiz_participant_progress')->where('participant_id', $pid)
                ->columns(['surah_id' => 'Surah', 'juz' => ['Juz', 'number'], 'ayahs_memorized' => ['Ayat dihafal', 'number'], 'ayahs_mutqin' => ['Ayat mutqin', 'number'], 'avg_score' => ['Rata-rata', 'number'], 'last_setor_date' => ['Setoran terakhir', 'date']])->latest('last_setor_date')->limit(40)->toBlock(),
            ProfileTable::make('Sertifikat tahfiz', 'tahfiz_certificates')->where('participant_id', $pid)
                ->columns(['certificate_number' => 'Nomor', 'title' => 'Judul', 'achievement_type' => 'Jenis', 'juz_count' => ['Juz', 'number'], 'issue_date' => ['Terbit', 'date'], 'status' => ['Status', 'badge']])->latest('issue_date')->toBlock(),
        ];
    }

    // -------------------------------------------------------------- aktivitas

    /** @return list<array<string, mixed>> */
    private function aktivitas(Student $e): array
    {
        $id = $e->id;
        $prog = fn (string $title, string $table) => ProfileTable::make($title, $table)->where('student_id', $id)->resolve('program_id', 'activity_programs', 'name')->resolve('period_id', 'activity_periods', 'name');

        return [
            Block::stats([
                ['Kegiatan diikuti', Block::count('activity_memberships', ['student_id' => $id])],
                ['Prestasi', Block::count('activity_achievements', ['student_id' => $id]), 'number', 'green'],
                ['Sertifikat', Block::count('activity_certificates', ['student_id' => $id])],
                ['Lomba', Block::count('activity_competition_participants', ['student_id' => $id])],
            ]),
            $prog('Keanggotaan', 'activity_memberships')->columns(['program_id' => 'Program', 'period_id' => 'Periode', 'role' => 'Peran', 'status' => ['Status', 'badge'], 'joined_at' => ['Bergabung', 'date'], 'ended_at' => ['Berakhir', 'date']])->latest('joined_at')->toBlock(),
            $prog('Pendaftaran ekskul', 'activity_registrations')->columns(['code' => 'Kode', 'program_id' => 'Program', 'period_id' => 'Periode', 'status' => ['Status', 'badge'], 'submitted_at' => ['Diajukan', 'datetime'], 'decision_notes' => 'Catatan keputusan'])->latest('submitted_at')->toBlock(),
            $prog('Prestasi', 'activity_achievements')->columns(['title' => 'Prestasi', 'type' => 'Jenis', 'level' => 'Tingkat', 'rank' => 'Peringkat', 'awarded_date' => ['Tanggal', 'date'], 'organizer' => 'Penyelenggara', 'verification_status' => ['Verifikasi', 'badge']])->latest('awarded_date')->toBlock(),
            $prog('Sertifikat', 'activity_certificates')->columns(['certificate_number' => 'Nomor', 'title' => 'Judul', 'type' => 'Jenis', 'issued_date' => ['Terbit', 'date'], 'status' => ['Status', 'badge']])->latest('issued_date')->toBlock(),
            $prog('Penyelesaian program', 'activity_completions')->columns(['program_id' => 'Program', 'period_id' => 'Periode', 'status' => ['Status', 'badge'], 'final_score' => ['Nilai akhir', 'number'], 'attendance_percentage' => ['Kehadiran (%)', 'number'], 'completed_at' => ['Selesai', 'datetime']])->latest('completed_at')->toBlock(),
            $prog('Persetujuan orang tua', 'activity_consents')->columns(['program_id' => 'Program', 'scope' => 'Cakupan', 'decision' => ['Keputusan', 'badge'], 'requested_at' => ['Diminta', 'datetime'], 'decided_at' => ['Diputuskan', 'datetime']])->latest('requested_at')->toBlock(),
            ProfileTable::make('Kehadiran kegiatan', 'activity_attendance')->where('student_id', $id)->columns(['recorded_at' => ['Waktu', 'datetime'], 'status' => ['Status', 'badge'], 'minutes_late' => ['Menit terlambat', 'number'], 'reason' => 'Alasan'])->latest('recorded_at')->limit(20)->toBlock(),
            ProfileTable::make('Perlombaan', 'activity_competition_participants')->where('student_id', $id)->columns(['registration_type' => 'Jenis', 'status' => ['Status', 'badge'], 'registered_at' => ['Terdaftar', 'datetime']])->latest('registered_at')->toBlock(),
        ];
    }

    // -------------------------------------------------------------- kesehatan

    /** @return list<array<string, mixed>> */
    private function kesehatan(Student $e, User $user): array
    {
        $id = $e->id;
        $blocks = [];

        if ($this->allowed($user, ['clinic.patient.view', 'clinic.visit.view', 'clinic.record.view.all']) && Schema::hasTable('clinic_patients')) {
            $patient = DB::table('clinic_patients')->where('student_id', $id)->first();
            if ($patient) {
                $blocks[] = Block::kv('Klinik sekolah — pasien', [
                    'No. rekam medis' => $patient->medical_record_number, 'Golongan darah' => $patient->blood_type,
                    'Peringatan alergi' => [(bool) $patient->allergy_alert, 'bool'], 'Perawatan khusus' => [(bool) $patient->special_care_alert, 'bool'],
                    'Kontak darurat' => trim(($patient->emergency_contact_name ?? '').' '.($patient->emergency_contact_phone ?? '')) ?: null,
                    'Asuransi' => $patient->insurance_provider, 'Status' => [$patient->status, 'badge'],
                ]);
                $pid = $patient->id;
                $blocks[] = ProfileTable::make('Kunjungan klinik', 'clinic_visits')->where('patient_id', $pid)
                    ->columns(['visit_number' => 'Nomor', 'visit_at' => ['Tanggal', 'date'], 'visit_type' => 'Jenis', 'chief_complaint' => 'Keluhan utama', 'priority' => ['Prioritas', 'badge'], 'status' => ['Status', 'badge']])->latest('visit_at')->limit(20)->toBlock();
                $blocks[] = ProfileTable::make('Pemeriksaan klinik', 'clinic_screenings')->where('patient_id', $pid)
                    ->columns(['screened_at' => ['Tanggal', 'date'], 'type' => 'Jenis', 'height_cm' => ['TB (cm)', 'number'], 'weight_kg' => ['BB (kg)', 'number'], 'bmi_category' => 'Kategori IMT', 'result' => ['Hasil', 'badge'], 'findings' => 'Temuan'])->latest('screened_at')->toBlock();
                $blocks[] = ProfileTable::make('Vaksinasi klinik', 'clinic_vaccinations')->where('patient_id', $pid)
                    ->columns(['given_at' => ['Tanggal', 'date'], 'vaccine' => 'Vaksin', 'dose' => ['Dosis', 'number'], 'next_due_at' => ['Berikutnya', 'date'], 'provider' => 'Pemberi'])->latest('given_at')->toBlock();
            } else {
                $blocks[] = Block::note('Belum terdaftar sebagai pasien klinik sekolah.');
            }
        }

        // UKS: baris dibatasi UksAccessService (kelas ampuan guru, anak sendiri, dll).
        if ($this->allowed($user, ['uks.record.view', 'uks.visit.view']) && $this->uks->canSeeStudent($user, $id) && Schema::hasTable('uks_visits')) {
            $profile = Schema::hasTable('uks_health_profiles') ? DB::table('uks_health_profiles')->where('student_id', $id)->first() : null;
            if ($profile) {
                $blocks[] = Block::kv('UKS — profil kesehatan', [
                    'Golongan darah' => $profile->blood_type, 'Alergi' => $profile->allergies, 'Penyakit kronis' => $profile->chronic_conditions,
                    'Obat rutin' => $profile->regular_medications, 'Kebutuhan khusus' => $profile->special_needs,
                    'Asuransi' => trim(($profile->insurance_provider ?? '').' '.($profile->insurance_number ?? '')) ?: null,
                    'Kontak darurat' => trim(($profile->emergency_contact_name ?? '').' ('.($profile->emergency_contact_relation ?? '-').') '.($profile->emergency_contact_phone ?? '')) ?: null,
                ]);
            }
            $blocks[] = ProfileTable::make('UKS — kunjungan', 'uks_visits')->where('student_id', $id)
                ->columns(['code' => 'Kode', 'arrived_at' => ['Tanggal', 'date'], 'visit_type' => 'Jenis', 'complaint' => 'Keluhan', 'severity' => ['Keparahan', 'badge'], 'treatment' => 'Penanganan', 'status' => ['Status', 'badge']])->latest('arrived_at')->limit(20)->toBlock();
            $blocks[] = ProfileTable::make('UKS — pemeriksaan berkala', 'uks_screenings')->where('student_id', $id)
                ->columns(['screened_at' => ['Tanggal', 'date'], 'screening_type' => 'Jenis', 'height_cm' => ['TB (cm)', 'number'], 'weight_kg' => ['BB (kg)', 'number'], 'bmi' => ['IMT', 'number'], 'result' => ['Hasil', 'badge'], 'findings' => 'Temuan'])->latest('screened_at')->toBlock();
            $blocks[] = ProfileTable::make('UKS — imunisasi', 'uks_immunizations')->where('student_id', $id)
                ->columns(['given_at' => ['Tanggal', 'date'], 'vaccine_name' => 'Vaksin', 'dose_number' => ['Dosis', 'number'], 'next_due_at' => ['Berikutnya', 'date'], 'provider' => 'Pemberi'])->latest('given_at')->toBlock();
            $blocks[] = ProfileTable::make('UKS — insiden', 'uks_incidents')->where('student_id', $id)
                ->columns(['code' => 'Kode', 'occurred_at' => ['Tanggal', 'date'], 'location' => 'Lokasi', 'severity' => ['Keparahan', 'badge'], 'description' => 'Kejadian', 'status' => ['Status', 'badge']])->latest('occurred_at')->toBlock();
            $blocks[] = ProfileTable::make('UKS — surat keterangan', 'uks_letters')->where('student_id', $id)
                ->columns(['number' => 'Nomor', 'letter_type' => 'Jenis', 'date_from' => ['Dari', 'date'], 'date_to' => ['Sampai', 'date']])->latest('date_from')->toBlock();
        }

        if ($blocks === []) {
            $blocks[] = Block::note('Tidak ada data kesehatan yang dapat Anda lihat untuk siswa ini.');
        }

        return $blocks;
    }

    // -------------------------------------------------------------- konseling

    /** @return list<array<string, mixed>> */
    private function konseling(Student $e): array
    {
        $id = $e->id;

        return [
            Block::note('Hanya metadata. Isi catatan sesi, ringkasan, dan dokumen konseling bersifat rahasia dan dibuka di modul Bimbingan & Konseling.', 'warn'),
            Block::stats([
                ['Kasus', Block::count('bk_cases', ['student_id' => $id])],
                ['Sesi konseling', Block::count('bk_counseling_sessions', ['student_id' => $id])],
                ['Janji temu', Block::count('bk_appointments', ['student_id' => $id])],
                ['Indikator risiko', Block::count('bk_risk_indicators', ['student_id' => $id]), 'number', 'amber'],
            ]),
            ProfileTable::make('Kasus', 'bk_cases')->where('student_id', $id)
                ->columns(['case_number' => 'Nomor', 'title' => 'Judul', 'category' => 'Kategori', 'priority' => ['Prioritas', 'badge'], 'status' => ['Status', 'badge'], 'opened_at' => ['Dibuka', 'date'], 'next_review_date' => ['Tinjau berikut', 'date']])->latest('opened_at')->toBlock(),
            ProfileTable::make('Janji temu', 'bk_appointments')->where('student_id', $id)
                ->columns(['scheduled_start' => ['Jadwal', 'datetime'], 'session_type' => 'Jenis', 'mode' => 'Mode', 'status' => ['Status', 'badge']])->latest('scheduled_start')->limit(15)->toBlock(),
            ProfileTable::make('Indikator risiko', 'bk_risk_indicators')->where('student_id', $id)
                ->columns(['detected_at' => ['Terdeteksi', 'datetime'], 'label' => 'Indikator', 'severity' => ['Keparahan', 'badge'], 'status' => ['Status', 'badge'], 'source_module' => 'Sumber'])->latest('detected_at')->toBlock(),
        ];
    }

    // ------------------------------------------------------------ perpustakaan

    /** @return list<array<string, mixed>> */
    private function perpustakaan(Student $e): array
    {
        if (! Schema::hasTable('library_members')) {
            return [];
        }
        $member = DB::table('library_members')->where('student_id', $e->id)->first();
        if (! $member) {
            return [Block::note('Siswa ini belum menjadi anggota perpustakaan.')];
        }
        $mid = $member->id;
        $open = Block::count('library_loans', ['member_id' => $mid, 'status' => ['active', 'overdue']]);
        $fines = Schema::hasTable('library_fines') ? (float) DB::table('library_fines')->where('member_id', $mid)->whereIn('status', ['unpaid', 'partial'])->selectRaw('coalesce(sum(amount - paid_amount - waived_amount),0) as s')->value('s') : 0.0;

        return [
            Block::stats([['Sedang dipinjam', $open], ['Denda belum lunas', $fines, 'money', $fines > 0 ? 'red' : 'green']]),
            Block::kv('Keanggotaan', ['No. anggota' => $member->member_number, 'Status' => [$member->status, 'badge'], 'Terdaftar' => [$member->registered_at, 'date'], 'Berlaku sampai' => [$member->valid_until, 'date']]),
            ProfileTable::make('Peminjaman', 'library_loans')->where('member_id', $mid)->resolve('bibliography_id', 'library_bibliographies', 'title')
                ->columns(['loan_code' => 'Kode', 'bibliography_id' => 'Judul', 'borrowed_at' => ['Dipinjam', 'date'], 'due_at' => ['Jatuh tempo', 'date'], 'returned_on' => ['Dikembalikan', 'date'], 'status' => ['Status', 'badge']])->latest('borrowed_at')->limit(25)->toBlock(),
            ProfileTable::make('Denda', 'library_fines')->where('member_id', $mid)
                ->columns(['assessed_at' => ['Tanggal', 'date'], 'type' => 'Jenis', 'amount' => ['Jumlah', 'money'], 'paid_amount' => ['Dibayar', 'money'], 'status' => ['Status', 'badge']])->latest('assessed_at')->toBlock(),
            ProfileTable::make('Reservasi', 'library_reservations')->where('member_id', $mid)->resolve('bibliography_id', 'library_bibliographies', 'title')
                ->columns(['reservation_code' => 'Kode', 'bibliography_id' => 'Judul', 'reserved_at' => ['Dipesan', 'date'], 'queue_position' => ['Antrean', 'number'], 'status' => ['Status', 'badge']])->latest('reserved_at')->toBlock(),
        ];
    }

    // ----------------------------------------------------------------- belajar

    /** @return list<array<string, mixed>> */
    private function belajar(Student $e): array
    {
        $id = $e->id;

        return [
            Block::stats([
                ['Kelas daring', Block::count('lms_enrollments', ['student_id' => $id])],
                ['Ujian CBT dikerjakan', Block::count('cbt_attempts', ['student_id' => $id])],
            ]),
            ProfileTable::make('Kelas daring (LMS)', 'lms_enrollments')->where('student_id', $id)->resolve('course_id', 'lms_courses', 'name')
                ->columns(['course_id' => 'Kelas', 'status' => ['Status', 'badge'], 'progress_percent' => ['Kemajuan (%)', 'number'], 'enrolled_at' => ['Terdaftar', 'date'], 'last_accessed_at' => ['Terakhir diakses', 'datetime'], 'xp_points' => ['XP', 'number']])->latest('enrolled_at')->toBlock(),
            ProfileTable::make('Ujian CBT', 'cbt_attempts')->where('student_id', $id)->resolve('session_id', 'cbt_sessions', 'title')
                ->columns(['session_id' => 'Ujian', 'attempt_number' => ['Percobaan', 'number'], 'submitted_at' => ['Dikumpulkan', 'datetime'], 'percentage' => ['Nilai (%)', 'number'], 'passed' => ['Lulus', 'bool'], 'status' => ['Status', 'badge'], 'integrity_score' => ['Skor integritas', 'number']])->latest('submitted_at', 'started_at')->limit(25)->toBlock(),
            ProfileTable::make('Jadwal ujian sekolah', 'exam_participants')->where('student_id', $id)->latest('id')->toBlock(),
        ];
    }

    // -------------------------------------------------------------------- akun

    /** @return list<array<string, mixed>> */
    private function akun(Student $e, User $viewer): array
    {
        $blocks = [];
        $morph = $e->getMorphClass();

        if ($this->allowed($viewer, ['user.view']) && Schema::hasTable('users')) {
            $account = DB::table('users')->where('userable_type', $morph)->where('userable_id', $e->id)->first();
            if ($account) {
                $roles = Schema::hasTable('model_has_roles')
                    ? DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.model_id', $account->id)->where('m.model_type', User::class)->pluck('r.name')->implode(', ')
                    : null;
                $blocks[] = Block::kv('Akun login', [
                    'Nama pengguna' => $account->username, 'Email' => $account->email, 'Telepon' => $account->phone,
                    'Aktif' => [(bool) $account->is_active, 'bool'], 'Login terakhir' => [$account->last_login_at, 'datetime'], 'Peran' => $roles ?: null,
                ]);
            } else {
                $blocks[] = Block::note('Siswa ini belum memiliki akun login.');
            }
        }

        if ($this->allowed($viewer, ['ppdb.view', 'ppdb.registration.view']) && Schema::hasTable('ppdb_registrations')) {
            $blocks[] = ProfileTable::make('Asal pendaftaran (PPDB)', 'ppdb_registrations')->where('student_id', $e->id)
                ->columns(['registration_number' => 'No. pendaftaran', 'program_id' => 'Program', 'status' => ['Status', 'badge'], 'selection_result' => ['Seleksi', 'badge'], 'submitted_at' => ['Diajukan', 'datetime'], 'completed_at' => ['Selesai', 'datetime']])
                ->resolve('program_id', 'ppdb_programs', 'name')->latest('submitted_at')->toBlock();
        }

        return $blocks === [] ? [Block::note('Tidak ada data akun yang dapat Anda lihat.')] : $blocks;
    }

    // ----------------------------------------------------------------- riwayat

    /** @return list<array<string, mixed>> */
    private function riwayat(Student $e): array
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
            ->get(['l.created_at', 'l.event', 'l.description', 'u.name as causer'])
            ->map(fn ($r) => (array) $r);

        return [Block::table('Perubahan data siswa', [['created_at', 'Waktu', 'datetime'], ['event', 'Aksi'], ['description', 'Keterangan'], ['causer', 'Oleh']], $rows)];
    }

    // ----------------------------------------------------------------- helper

    public function currentClass(int $studentId): ?object
    {
        return DB::table('class_students as cs')
            ->join('school_classes as c', 'c.id', '=', 'cs.school_class_id')
            ->where('cs.student_id', $studentId)->where('cs.status', 'aktif')
            ->orderByDesc('cs.academic_year_id')->first(['c.id', 'c.name', 'c.code']);
    }

    /** @return array{total: int, present: int, by_status: array<string, int>} */
    public function attendanceSummary(int $id, int $days): array
    {
        if (! Schema::hasTable('student_attendances')) {
            return ['total' => 0, 'present' => 0, 'by_status' => []];
        }
        $rows = DB::table('student_attendances as a')
            ->join('attendance_statuses as s', 's.id', '=', 'a.attendance_status_id')
            ->where('a.student_id', $id)->where('a.date', '>=', now()->subDays($days)->toDateString())
            ->groupBy('s.code', 's.name')->selectRaw('s.code as code, s.name as name, count(*) as c')->get();

        $present = 0;
        $by = [];
        foreach ($rows as $r) {
            $by[$r->name] = (int) $r->c;
            if (in_array(strtoupper((string) $r->code), ['H', 'T', 'PRESENT', 'LATE'], true)) {
                $present += (int) $r->c;
            }
        }

        return ['total' => array_sum($by), 'present' => $present, 'by_status' => $by];
    }

    public function averageScore(int $id): ?float
    {
        if (! Schema::hasTable('scores') || ! Schema::hasTable('assessments')) {
            return null;
        }
        $v = DB::table('scores as sc')->join('assessments as a', 'a.id', '=', 'sc.assessment_id')
            ->where('sc.student_id', $id)->whereNotNull('sc.score')->where('a.max_score', '>', 0)
            ->selectRaw('avg(sc.score / a.max_score * 100) as v')->value('v');

        return $v === null ? null : (float) $v;
    }

    /** @return array{total: float, paid: float, remaining: float, overdue: float} */
    public function invoiceTotals(int $id): array
    {
        $q = fn () => DB::table('finance_invoices')->where('student_id', $id)->whereNotIn('status', ['cancelled', 'canceled', 'void', 'draft']);

        return [
            'total' => (float) $q()->sum('total'),
            'paid' => (float) $q()->sum('paid_amount'),
            'remaining' => (float) $q()->sum('remaining_amount'),
            'overdue' => (float) $q()->where('remaining_amount', '>', 0)->where('due_date', '<', now()->toDateString())->sum('remaining_amount'),
        ];
    }

    private function boardingPlacement(int $id): ?string
    {
        if (! Schema::hasTable('boarding_placements')) {
            return null;
        }
        $p = DB::table('boarding_placements as p')
            ->leftJoin('boarding_dormitories as d', 'd.id', '=', 'p.dormitory_id')
            ->leftJoin('boarding_rooms as r', 'r.id', '=', 'p.room_id')
            ->where('p.student_id', $id)->whereNull('p.end_date')->where('p.status', 'active')
            ->first(['d.name as dorm', 'r.name as room']);

        return $p ? trim(($p->dorm ?? '').' · '.($p->room ?? ''), ' ·') : null;
    }

    private function tahfizJuz(int $id): ?int
    {
        if (! Schema::hasTable('tahfiz_participants') || ! Schema::hasTable('tahfiz_participant_progress')) {
            return null;
        }
        $pid = DB::table('tahfiz_participants')->where('student_id', $id)->value('id');

        return $pid ? (int) DB::table('tahfiz_participant_progress')->where('participant_id', $pid)->distinct()->count('juz') : null;
    }
}
