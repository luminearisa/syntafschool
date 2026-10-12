<?php

namespace App\Services\Profile;

use App\Models\Guardian;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Profil 360° wali murid: identitas, anak-anak, keuangan seluruh anak, persetujuan, komunikasi, akun. */
final class GuardianProfile extends ProfileProvider
{
    public function __construct(private readonly StudentProfile $students) {}

    public function type(): string
    {
        return 'guardians';
    }

    public function find(int $id): object
    {
        return Guardian::query()->findOrFail($id);
    }

    public function header(object $e): array
    {
        /** @var Guardian $e */
        $children = $this->childRows($e->id);
        $active = $children->where('is_active', true)->count();

        return [
            'title' => $e->full_name,
            'subtitle' => $children->count().' anak terdaftar',
            'initials' => self::initials($e->full_name)[0],
            'photo_path' => null,
            'is_active' => true,
            'badges' => array_values(array_filter([
                ['label' => 'Wali murid', 'tone' => 'blue'],
                $active > 0 ? ['label' => $active.' anak aktif', 'tone' => 'green'] : ['label' => 'Tidak ada anak aktif', 'tone' => 'gray'],
            ])),
            'facts' => array_values(array_filter([
                $e->phone ? ['label' => 'Telepon', 'value' => $e->phone] : null,
                $e->email ? ['label' => 'Email', 'value' => $e->email] : null,
            ])),
        ];
    }

    public function summary(object $e, User $user): array
    {
        $ids = $this->childRows($e->id)->pluck('id')->all();
        $items = [['Anak', count($ids), 'number', null, null]];

        if ($this->allowed($user, ['finance.view', 'finance.invoice.view', 'finance.payment.view']) && Schema::hasTable('finance_invoices') && $ids !== []) {
            $remaining = (float) DB::table('finance_invoices')->whereIn('student_id', $ids)->whereNotIn('status', ['cancelled', 'canceled', 'void', 'draft'])->sum('remaining_amount');
            $items[] = ['Sisa tagihan seluruh anak', $remaining, 'money', $remaining > 0 ? 'amber' : 'green', null];
        }
        if (Schema::hasTable('activity_consents')) {
            $pending = DB::table('activity_consents')->where('guardian_id', $e->id)->where('decision', 'PENDING')->count();
            if ($pending > 0) {
                $items[] = ['Persetujuan menunggu', $pending, 'number', 'amber', null];
            }
        }
        $account = $this->account($e);
        $items[] = ['Akun login', $account ? ($account->is_active ? 'Aktif' : 'Nonaktif') : 'Belum ada', 'text', $account ? null : 'gray', null];

        return [Block::stats($items)];
    }

    protected function definitions(): array
    {
        return [
            'profil' => ['label' => 'Profil', 'icon' => 'user', 'permissions' => [], 'build' => fn ($e) => $this->profil($e)],
            'anak' => ['label' => 'Anak', 'icon' => 'users', 'permissions' => [], 'build' => fn ($e, $u) => $this->anak($e, $u)],
            'keuangan' => ['label' => 'Keuangan', 'icon' => 'wallet', 'permissions' => ['finance.view', 'finance.invoice.view', 'finance.payment.view'], 'build' => fn ($e) => $this->keuangan($e)],
            'persetujuan' => ['label' => 'Persetujuan', 'icon' => 'check', 'permissions' => ['activity.consent.view', 'bk.consent.view', 'uks.visit.view', 'bk.case.view.all'], 'build' => fn ($e, $u) => $this->persetujuan($e, $u)],
            'komunikasi' => ['label' => 'Komunikasi', 'icon' => 'chat', 'permissions' => ['bk.case.view.all', 'bk.meeting.view', 'bk.manage'], 'build' => fn ($e) => $this->komunikasi($e)],
            'keanggotaan' => ['label' => 'Keanggotaan Lain', 'icon' => 'library', 'permissions' => ['library.view', 'library.member.view', 'wallet.view', 'tahfiz.view'], 'build' => fn ($e, $u) => $this->keanggotaan($e, $u)],
            'akun' => ['label' => 'Akun & PPDB', 'icon' => 'key', 'permissions' => ['user.view', 'ppdb.view', 'ppdb.registration.view'], 'build' => fn ($e, $u) => $this->akun($e, $u)],
            'riwayat' => ['label' => 'Riwayat Perubahan', 'icon' => 'clock', 'permissions' => ['student.update'], 'build' => fn ($e) => $this->riwayat($e)],
        ];
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function childRows(int $guardianId)
    {
        return DB::table('student_guardians as sg')
            ->join('students as s', 's.id', '=', 'sg.student_id')
            ->leftJoin('guardian_relations as r', 'r.id', '=', 'sg.guardian_relation_id')
            ->where('sg.guardian_id', $guardianId)->whereNull('s.deleted_at')
            ->orderByDesc('s.is_active')->orderBy('s.full_name')
            ->get(['s.id', 's.full_name', 's.nis', 's.nisn', 's.is_active', 's.gender_id', 's.birth_date', 'r.name as relation', 'sg.is_primary']);
    }

    private function account(Guardian $e): ?object
    {
        return Schema::hasTable('users') ? DB::table('users')->where('userable_type', $e->getMorphClass())->where('userable_id', $e->id)->first() : null;
    }

    /** @return list<array<string, mixed>> */
    private function profil(Guardian $e): array
    {
        $lookup = fn (string $table, ?int $id, string $col = 'name') => $id && Schema::hasTable($table) ? DB::table($table)->where('id', $id)->value($col) : null;

        $children = $this->childRows($e->id)->map(function ($c) {
            $class = $this->students->currentClass($c->id);

            return ['full_name' => $c->full_name, 'nis' => $c->nis, 'class' => $class?->name, 'relation' => $c->relation, 'is_primary' => (bool) $c->is_primary, 'is_active' => (bool) $c->is_active, '_link' => '/students/'.$c->id];
        });

        return [
            Block::kv('Identitas', [
                'Nama lengkap' => $e->full_name,
                'Jenis kelamin' => $lookup('genders', $e->gender_id),
                'Agama' => $lookup('religions', $e->religion_id),
                'Pendidikan terakhir' => $lookup('education_backgrounds', $e->education_background_id),
                'Pekerjaan' => $lookup('occupations', $e->occupation_id),
                'Penghasilan' => $lookup('income_ranges', $e->income_range_id, 'label'),
            ]),
            Block::kv('Kontak', ['Alamat' => $e->address, 'Telepon' => $e->phone, 'Email' => $e->email]),
            Block::table('Anak', [['full_name', 'Nama'], ['nis', 'NIS'], ['class', 'Kelas'], ['relation', 'Hubungan'], ['is_primary', 'Wali utama', 'bool'], ['is_active', 'Aktif', 'bool']], $children),
        ];
    }

    /** Ringkasan satu baris per anak (hanya angka yang boleh dilihat pembaca). */
    private function anak(Guardian $e, User $user): array
    {
        $canFinance = $this->allowed($user, ['finance.view', 'finance.invoice.view', 'finance.payment.view']);
        $canAcademic = $this->allowed($user, ['academic.view', 'academic.portal', 'assessment.view']);

        $columns = [['full_name', 'Nama'], ['class', 'Kelas'], ['is_active', 'Aktif', 'bool']];
        if ($canAcademic) {
            $columns[] = ['attendance', 'Kehadiran 90 hr (%)', 'number'];
            $columns[] = ['average', 'Rata-rata nilai', 'number'];
        }
        if ($canFinance) {
            $columns[] = ['remaining', 'Sisa tagihan', 'money'];
        }

        $rows = $this->childRows($e->id)->map(function ($c) use ($canAcademic, $canFinance) {
            $row = ['full_name' => $c->full_name, 'class' => $this->students->currentClass($c->id)?->name, 'is_active' => (bool) $c->is_active, '_link' => '/students/'.$c->id];
            if ($canAcademic) {
                $att = $this->students->attendanceSummary($c->id, 90);
                $avg = $this->students->averageScore($c->id);
                $row['attendance'] = $att['total'] > 0 ? round($att['present'] / $att['total'] * 100, 1) : null;
                $row['average'] = $avg === null ? null : round($avg, 1);
            }
            if ($canFinance && Schema::hasTable('finance_invoices')) {
                $row['remaining'] = $this->students->invoiceTotals($c->id)['remaining'];
            }

            return $row;
        });

        return [Block::table('Ringkasan per anak', $columns, $rows), Block::note('Klik nama anak untuk membuka profil lengkapnya.')];
    }

    /** @return list<array<string, mixed>> */
    private function keuangan(Guardian $e): array
    {
        $ids = $this->childRows($e->id)->pluck('id')->all();
        if ($ids === [] || ! Schema::hasTable('finance_invoices')) {
            return [Block::note('Tidak ada data keuangan.')];
        }
        $q = fn () => DB::table('finance_invoices')->whereIn('student_id', $ids)->whereNotIn('status', ['cancelled', 'canceled', 'void', 'draft']);

        return [
            Block::stats([
                ['Total tagihan', (float) $q()->sum('total'), 'money'],
                ['Terbayar', (float) $q()->sum('paid_amount'), 'money', 'green'],
                ['Sisa', (float) $q()->sum('remaining_amount'), 'money', 'amber'],
                ['Lewat jatuh tempo', (float) $q()->where('remaining_amount', '>', 0)->where('due_date', '<', now()->toDateString())->sum('remaining_amount'), 'money', 'red'],
            ]),
            ProfileTable::make('Tagihan seluruh anak', 'finance_invoices')->where('student_id', $ids)
                ->columns(['student_id' => 'Anak', 'invoice_number' => 'No. tagihan', 'due_date' => ['Jatuh tempo', 'date'], 'total' => ['Total', 'money'], 'remaining_amount' => ['Sisa', 'money'], 'status' => ['Status', 'badge']])->latest('issue_date')->limit(40)->toBlock(),
            ProfileTable::make('Pembayaran', 'finance_payments')->where('student_id', $ids)
                ->columns(['student_id' => 'Anak', 'payment_number' => 'No. pembayaran', 'paid_at' => ['Dibayar', 'datetime'], 'amount' => ['Jumlah', 'money'], 'method' => 'Metode', 'status' => ['Status', 'badge']])->latest('paid_at', 'created_at')->limit(40)->toBlock(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function persetujuan(Guardian $e, User $user): array
    {
        $gid = $e->id;
        $blocks = [];
        if ($this->allowed($user, ['activity.consent.view'])) {
            $blocks[] = ProfileTable::make('Persetujuan kegiatan', 'activity_consents')->where('guardian_id', $gid)->resolve('program_id', 'activity_programs', 'name')
                ->columns(['student_id' => 'Anak', 'program_id' => 'Program', 'scope' => 'Cakupan', 'decision' => ['Keputusan', 'badge'], 'requested_at' => ['Diminta', 'datetime'], 'decided_at' => ['Diputuskan', 'datetime']])->latest('requested_at')->toBlock();
        }
        if ($this->allowed($user, ['uks.visit.view'])) {
            $blocks[] = ProfileTable::make('Persetujuan tindakan UKS', 'uks_consents')->where('guardian_id', $gid)
                ->columns(['decided_at' => ['Tanggal', 'date'], 'decision' => ['Keputusan', 'badge'], 'channel' => 'Saluran', 'note' => 'Catatan'])->latest('decided_at')->toBlock();
        }
        if ($this->allowed($user, ['bk.consent.view', 'bk.case.view.all'])) {
            $blocks[] = ProfileTable::make('Persetujuan konseling', 'bk_consents')->where('guardian_id', $gid)
                ->columns(['student_id' => 'Anak', 'consent_number' => 'Nomor', 'scope' => 'Cakupan', 'status' => ['Status', 'badge'], 'requested_at' => ['Diminta', 'datetime'], 'decided_at' => ['Diputuskan', 'datetime']])->latest('requested_at')->toBlock();
        }

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function komunikasi(Guardian $e): array
    {
        $gid = $e->id;

        return [
            ProfileTable::make('Pengajuan konsultasi', 'bk_support_requests')->where('guardian_id', $gid)
                ->columns(['request_number' => 'Nomor', 'student_id' => 'Anak', 'topic' => 'Topik', 'urgent' => ['Mendesak', 'bool'], 'status' => ['Status', 'badge']])->latest('created_at')->toBlock(),
            ProfileTable::make('Pertemuan orang tua', 'bk_parent_meeting_participants')->where('guardian_id', $gid)
                ->columns(['student_id' => 'Anak', 'role' => 'Peran', 'attendance_status' => ['Kehadiran', 'badge'], 'notes' => 'Catatan'])->latest('id')->toBlock(),
            ProfileTable::make('Umpan balik', 'bk_feedback')->where('guardian_id', $gid)->columns(['submitted_at' => ['Tanggal', 'datetime'], 'rating' => ['Penilaian', 'number'], 'status' => ['Status', 'badge']])->latest('submitted_at')->toBlock(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function keanggotaan(Guardian $e, User $user): array
    {
        $gid = $e->id;
        $blocks = [];
        if ($this->allowed($user, ['wallet.view']) && Schema::hasTable('wallets')) {
            $w = DB::table('wallets')->where('guardian_id', $gid)->first();
            $blocks[] = $w ? Block::kv('Dompet', ['Nomor' => $w->number, 'Saldo' => [(float) $w->balance, 'money'], 'Status' => [$w->status, 'badge']]) : Block::note('Tidak memiliki dompet.');
        }
        if ($this->allowed($user, ['library.view', 'library.member.view']) && Schema::hasTable('library_members')) {
            $m = DB::table('library_members')->where('guardian_id', $gid)->first();
            $blocks[] = $m ? Block::kv('Anggota perpustakaan', ['No. anggota' => $m->member_number, 'Status' => [$m->status, 'badge'], 'Berlaku sampai' => [$m->valid_until, 'date']]) : Block::note('Bukan anggota perpustakaan.');
        }
        if ($this->allowed($user, ['tahfiz.view']) && Schema::hasTable('tahfiz_participants')) {
            $p = DB::table('tahfiz_participants')->where('guardian_id', $gid)->first();
            $blocks[] = $p ? Block::kv('Peserta Tahfiz', ['Nama' => $p->name, 'Jenis' => $p->participant_type, 'Status' => [$p->status, 'badge']]) : Block::note('Bukan peserta Tahfiz.');
        }

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function akun(Guardian $e, User $viewer): array
    {
        $blocks = [];
        if ($this->allowed($viewer, ['user.view'])) {
            $account = $this->account($e);
            if ($account) {
                $identities = Schema::hasTable('user_identities') ? DB::table('user_identities')->where('user_id', $account->id)->pluck('identity_type')->implode(', ') : null;
                $roles = Schema::hasTable('model_has_roles')
                    ? DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.model_id', $account->id)->where('m.model_type', User::class)->pluck('r.name')->implode(', ')
                    : null;
                $blocks[] = Block::kv('Akun login', [
                    'Nama pengguna' => $account->username, 'Email' => $account->email, 'Aktif' => [(bool) $account->is_active, 'bool'],
                    'Login terakhir' => [$account->last_login_at, 'datetime'], 'Peran' => $roles ?: null, 'Identitas tertaut' => $identities ?: null,
                ]);
            } else {
                $blocks[] = Block::note('Wali ini belum memiliki akun login.');
            }
        }
        if ($this->allowed($viewer, ['ppdb.view', 'ppdb.registration.view']) && Schema::hasTable('ppdb_parent_accounts')) {
            $blocks[] = ProfileTable::make('Akun orang tua PPDB', 'ppdb_parent_accounts')->where('guardian_id', $e->id)
                ->columns(['account_status' => ['Status', 'badge'], 'verified_at' => ['Diverifikasi', 'datetime'], 'last_activity_at' => ['Aktivitas terakhir', 'datetime']])->toBlock();
            $blocks[] = ProfileTable::make('Pendaftar PPDB', 'ppdb_applicants')->where('guardian_id', $e->id)
                ->columns(['full_name' => 'Nama calon siswa', 'previous_school' => 'Sekolah asal', 'status' => ['Status', 'badge']])->toBlock();
        }

        return $blocks === [] ? [Block::note('Tidak ada data akun yang dapat Anda lihat.')] : $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function riwayat(Guardian $e): array
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

        return [Block::table('Perubahan data wali', [['created_at', 'Waktu', 'datetime'], ['event', 'Aksi'], ['description', 'Keterangan'], ['causer', 'Oleh']], $rows)];
    }
}
