<?php

namespace App\Services\Ppdb;

use App\Models\PpdbAnnouncement;
use App\Models\PpdbRegistration;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengumuman PPDB + penetapan hasil seleksi.
 *
 * Pengumuman hanya punya satu kanal nyata di aplikasi ini (database/in-app).
 * Kanal `email`/`whatsapp` pada kolom `channel` diperlakukan sebagai **niat**:
 * saat dipublikasikan kanal-kanal itu dilaporkan sebagai "butuh konfigurasi"
 * (mail driver / penyedia WhatsApp) — tidak ada mailer atau gateway WA baru.
 *
 * Penetapan hasil selalu lewat `PpdbRegistrationStateMachine` sehingga riwayat
 * dan notifikasi hasil seleksi tetap konsisten, dan penetapan `passed` dijaga
 * kuota program/gelombangnya (audit §27).
 */
class PpdbAnnouncementService
{
    /** Batas aman penerima in-app satu pengumuman. */
    public const MAX_RECIPIENTS = 500;

    /**
     * Status yang menempati kursi kuota.
     *
     * Hanya tiga status ini yang dihitung §27: pendaftaran yang batal, gagal,
     * atau masih waiting list tidak menyita kursi.
     */
    public const QUOTA_OCCUPYING = [
        PpdbRegistration::STATUS_PASSED,
        PpdbRegistration::STATUS_RE_REGISTRATION,
        PpdbRegistration::STATUS_COMPLETED,
    ];

    /**
     * Hak khusus menetapkan lulus di atas kuota.
     *
     * Sengaja tidak diberikan ke `panitia-ppdb` pada RolePermissionSeeder:
     * menambah kursi adalah keputusan kepemimpinan. Diperiksa di runtime, bukan
     * hanya pada middleware route, karena endpoint penetapan hasil juga dipakai
     * panitia biasa untuk kasus dalam kuota.
     */
    public const OVERRIDE_QUOTA_PERMISSION = 'ppdb.registration.override_quota';

    public function __construct(
        private readonly PpdbNotificationService $notifications,
        private readonly PpdbRegistrationStateMachine $state,
    ) {
    }

    public function query(Request $request): Builder
    {
        return PpdbAnnouncement::query()
            ->with('publishedBy:id,name')
            ->when($request->filled('period_id'), fn ($q) => $q->where('period_id', (int) $request->input('period_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')->toString()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where(fn ($s) => $s->where('title', 'like', "%{$search}%")->orWhere('body', 'like', "%{$search}%"));
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /**
     * Publish draft → published + stempel penerbit, lalu kirim in-app ke orang tua
     * pada periode pengumuman.
     *
     * @return array{announcement: PpdbAnnouncement, delivered: int, channels: array<string, string>}
     */
    public function publish(PpdbAnnouncement $announcement, User $actor): array
    {
        if ($announcement->status === PpdbAnnouncement::STATUS_PUBLISHED) {
            throw ValidationException::withMessages([
                'status' => 'Pengumuman sudah diterbitkan.',
            ]);
        }

        $recipients = [];

        DB::transaction(function () use ($announcement, $actor, &$recipients) {
            $announcement->forceFill([
                'status' => PpdbAnnouncement::STATUS_PUBLISHED,
                'published_at' => $announcement->published_at ?? Carbon::now(),
                'published_by' => $actor->id,
            ])->save();

            $announcement->refresh();

            $recipients = $this->recipientUserIds($announcement);

            foreach ($recipients as $userId) {
                $this->notifications->toUser(
                    (int) $userId,
                    PpdbActivityNotification::TYPE_ANNOUNCEMENT,
                    $announcement->title,
                    $this->excerpt($announcement->body),
                    [
                        'announcement_id' => $announcement->id,
                        'period_id' => $announcement->period_id,
                        'channel' => $announcement->channel,
                    ],
                );
            }
        });

        return [
            'announcement' => $announcement,
            'delivered' => count($recipients),
            'channels' => $this->channelReport($announcement),
        ];
    }

    /**
     * Arsipkan pengumuman yang sudah terbit.
     */
    public function archive(PpdbAnnouncement $announcement): PpdbAnnouncement
    {
        $announcement->forceFill(['status' => PpdbAnnouncement::STATUS_ARCHIVED])->save();

        return $announcement->refresh();
    }

    /**
     * Tetapkan hasil seleksi satu pendaftaran.
     *
     * Bila pendaftaran belum masuk tahap seleksi, status `verified` dinaikan ke
     * `selection` lebih dulu di dalam transaksi yang sama.
     *
     * `passed` melewati penjaga kuota (§27): bila kursi program/gelombang sudah
     * penuh, permintaan ditolak sampai pengirim mengirim alasan override yang
     * eksplisit **dan** memegang hak `ppdb.registration.override_quota`; alasan
     * itu disimpan pada pendaftaran + riwayat + jejak audit.
     *
     * @return array{registration: PpdbRegistration, result: string, stage_reached: bool, quota: array<string, mixed>}
     */
    public function setResult(
        PpdbRegistration $registration,
        string $result,
        ?string $note = null,
        ?int $announcementId = null,
        ?User $actor = null,
        ?string $quotaOverrideReason = null,
    ): array {
        if (! in_array($result, [PpdbRegistration::STATUS_PASSED, PpdbRegistration::STATUS_FAILED, PpdbRegistration::STATUS_WAITLISTED], true)) {
            throw ValidationException::withMessages(['result' => "Hasil \"$result\" tidak dikenali."]);
        }

        $registration->loadMissing('applicant', 'program', 'wave');

        $quota = $result === PpdbRegistration::STATUS_PASSED
            ? $this->assertQuotaAvailable($registration, $quotaOverrideReason, $actor)
            : ['over_quota' => false, 'reason' => null, 'usage' => $this->quotaUsage($registration)];

        $enteredSelection = false;
        $from = (string) $registration->status;

        DB::transaction(function () use ($registration, $result, $note, $announcementId, $actor, $from, $quota, &$enteredSelection) {
            if ($registration->status !== PpdbRegistration::STATUS_SELECTION) {
                if ($registration->status !== PpdbRegistration::STATUS_VERIFIED) {
                    throw ValidationException::withMessages([
                        'status' => 'Hasil seleksi hanya dapat diberikan pada pendaftaran berstatus terverifikasi '
                            .'atau sedang diseleksi (status sekarang: '.$registration->status.', dari: '.$from.');',
                    ]);
                }

                $this->state->transition($registration, PpdbRegistration::STATUS_SELECTION, null, $actor, notify: false);
                $enteredSelection = true;
            }

            $registration->forceFill([
                'selection_result' => $result,
                // Alasan override hanya boleh bertahan pada penetapan yang memang
                // menyetujuinya; penetapan biasa membersihkannya.
                'quota_override_reason' => $quota['over_quota'] ? $quota['reason'] : null,
            ])->save();

            $historyNote = $this->historyNote($result, $note, $announcementId, $quota);

            // Transisi menuliskan `announced_at` + mengirim TYPE_SELECTION_RESULT.
            $this->state->transition($registration, $result, $historyNote, $actor);

            $this->state->record($registration, 'selection.result', $historyNote, $actor, $from, $result);
        });

        return [
            'registration' => $registration->refresh(),
            'result' => $result,
            'stage_reached' => $enteredSelection,
            'quota' => array_merge($quota, ['usage' => $this->quotaUsage($registration->refresh())]),
        ];
    }

    /**
     * Pemakaian kuota program & gelombang satu pendaftaran, berikut sisa kursinya.
     *
     * Perhitungan memakai status yang menempati kursi (lihat QUOTA_OCCUPYING)
     * pada periode yang sama dan selalu mengecualikan pendaftaran itu sendiri agar
     * penetapan ulang tidak menghitung kursinya dua kali. Kuota null/0 berarti
     * tanpa batas — sesuai konvensi kolom `quota` di tabel gelombang & program.
     *
     * @return array<string, mixed>
     */
    public function quotaUsage(PpdbRegistration $registration): array
    {
        $registration->loadMissing('program:id,period_id,name,code,quota', 'wave:id,period_id,name,code,quota');

        $usage = [];
        $fullOn = [];

        foreach ($this->quotaAxes($registration) as $axis) {
            $limit = (int) $axis['quota'];

            if ($axis['quota'] === null || $limit <= 0) {
                continue;
            }

            $taken = $this->occupiedBy($registration, $axis['column'], $axis['value']);

            $row = [
                'axis' => $axis['label'],
                'scope' => $axis['key'],
                'name' => $axis['name'],
                'quota' => $limit,
                'taken' => $taken,
                'remaining' => max(0, $limit - $taken),
                'is_full' => $taken >= $limit,
            ];

            $usage[] = $row;

            if ($row['is_full']) {
                $fullOn[] = $row;
            }
        }

        return [
            'checked' => $usage !== [],
            'usage' => $usage,
            'full' => $fullOn,
            'is_full' => $fullOn !== [],
        ];
    }

    /**
     * Penjaga kuota §27.
     *
     * Melewati kuota hanya sah bila pengirimnya punya hak
     * `ppdb.registration.override_quota` DAN mengirim alasan yang tidak kosong.
     *
     * @return array{over_quota: bool, reason: string|null, usage: array<string, mixed>}
     *
     * @throws ValidationException kuota penuh tanpa alasan override
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException kuota penuh tanpa hak override
     */
    public function assertQuotaAvailable(PpdbRegistration $registration, ?string $overrideReason, ?User $actor = null): array
    {
        $usage = $this->quotaUsage($registration);

        if (! $usage['is_full']) {
            return ['over_quota' => false, 'reason' => null, 'usage' => $usage];
        }

        $reason = trim((string) $overrideReason);

        if ($reason === '') {
            $detail = collect($usage['full'])
                ->map(fn (array $row) => sprintf(
                    '%s "%s" sudah penuh (%d/%d)',
                    $row['axis'],
                    (string) $row['name'],
                    $row['taken'],
                    $row['quota'],
                ))
                ->implode('; ');

            throw ValidationException::withMessages([
                'quota' => "Kuota sudah penuh: $detail. "
                    .'Tetapkan `waitlisted` atau kirim `quota_override_reason` bila penambahan kursi ini disengaja.',
            ]);
        }

        abort_unless(
            $actor !== null && $actor->can(self::OVERRIDE_QUOTA_PERMISSION),
            403,
            'Menetapkan lulus di atas kuota membutuhkan hak '.self::OVERRIDE_QUOTA_PERMISSION.'.'
        );

        return ['over_quota' => true, 'reason' => $reason, 'usage' => $usage];
    }

    /**
     * Sumbu kuota yang berlaku untuk pendaftaran ini.
     *
     * @return list<array<string, mixed>>
     */
    private function quotaAxes(PpdbRegistration $registration): array
    {
        return [
            [
                'key' => 'program',
                'column' => 'program_id',
                'value' => $registration->program_id,
                'quota' => $registration->program?->quota === null ? null : (int) $registration->program->quota,
                'name' => $registration->program?->name ?? '-',
                'label' => 'program',
            ],
            [
                'key' => 'wave',
                'column' => 'wave_id',
                'value' => $registration->wave_id,
                'quota' => $registration->wave?->quota === null ? null : (int) $registration->wave->quota,
                'name' => $registration->wave?->name ?? '-',
                'label' => 'gelombang',
            ],
        ];
    }

    /**
     * Jumlah pendaftaran lain yang sudah menempati kursi pada satu sumbu.
     */
    private function occupiedBy(PpdbRegistration $registration, string $column, int|string|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return PpdbRegistration::query()
            ->where('period_id', $registration->period_id)
            ->where($column, $value)
            ->where('id', '!=', $registration->id)
            ->whereIn('status', self::QUOTA_OCCUPYING)
            ->count();
    }

    /**
     * @return array<string, string>
     */
    private function channelReport(PpdbAnnouncement $announcement): array
    {
        $report = [
            PpdbAnnouncement::CHANNEL_PORTAL => 'terkirim in-app (kanal database)',
        ];

        $external = [
            PpdbAnnouncement::CHANNEL_EMAIL => 'butuh konfigurasi mail pada config/mail.php (MAIL_MAILER) — tidak ada mailer baru yang dibuat',
            PpdbAnnouncement::CHANNEL_WHATSAPP => 'butuh penyedia WhatsApp terintegrasi — belum dikonfigurasi pada aplikasi ini',
        ];

        $wanted = match ($announcement->channel) {
            PpdbAnnouncement::CHANNEL_ALL => array_keys($external),
            PpdbAnnouncement::CHANNEL_EMAIL, PpdbAnnouncement::CHANNEL_WHATSAPP => [$announcement->channel],
            default => [],
        };

        foreach ($wanted as $channel) {
            $report[$channel] = $external[$channel];
        }

        return $report;
    }

    /**
     * id user pemilik akun orang tua yang punya pendaftaran pada periode ini.
     *
     * @return list<int>
     */
    private function recipientUserIds(PpdbAnnouncement $announcement): array
    {
        return PpdbRegistration::query()
            ->where('period_id', $announcement->period_id)
            ->whereNotNull('parent_account_id')
            ->with('parentAccount:id,user_id')
            ->get()
            ->pluck('parentAccount.user_id')
            ->filter()
            ->unique()
            ->take(self::MAX_RECIPIENTS)
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Catatan riwayat untuk penetapan hasil.
     *
     * Bila penetapan melewati kuota, alasan override + sumbu yang penuh ikut
     * ditulis agar jejak `ppdb_registration_histories` menjelaskan kenapa kursi
     * bertambah (audit §27 meminta alasan itu terdokumentasi di tiga tempat).
     *
     * @param array<string, mixed> $quota
     */
    private function historyNote(string $result, ?string $note, ?int $announcementId, array $quota = []): string
    {
        $label = match ($result) {
            PpdbRegistration::STATUS_PASSED => 'DITERIMA',
            PpdbRegistration::STATUS_WAITLISTED => 'DAFTAR TUNGGU',
            default => 'BELUM LULUS',
        };

        $text = 'Hasil seleksi: '.$label;

        if ($announcementId !== null) {
            $text .= " (pengumuman #$announcementId)";
        }

        if (filled($note)) {
            $text .= " — $note";
        }

        if (($quota['over_quota'] ?? false) === true) {
            $text .= sprintf(
                ' — MELEWATI KUOTA: %s (kursi penuh: %s)',
                trim((string) $quota['reason']),
                $this->fullAxesSummary($quota['usage'] ?? []),
            );
        }

        return $text;
    }

    /**
     * Ringkasan sumbu kuota yang penuh, untuk kalimat riwayat/audit.
     *
     * @param array<string, mixed> $usage
     */
    private function fullAxesSummary(array $usage): string
    {
        $full = collect($usage['full'] ?? [])->map(
            fn (array $row) => sprintf('%s %s %d/%d', $row['axis'], $row['name'], $row['taken'], $row['quota'])
        )->implode('; ');

        return $full === '' ? '-' : $full;
    }

    private function excerpt(string $body, int $length = 200): string
    {
        $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));

        return mb_strlen($plain) > $length ? mb_substr($plain, 0, $length - 1).'…' : $plain;
    }
}
