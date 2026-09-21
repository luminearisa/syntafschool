<?php

namespace App\Services\Ppdb;

use App\Models\PpdbParentAccount;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationAppeal;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Masa sanggah hasil seleksi (audit §29).
 *
 * Aturan main yang dijaga di sini, bukan di controller:
 * - orang tua hanya boleh menyanggah pendaftaran miliknya sendiri — pemeriksaan
 *   kepemilikan lewat `PpdbPortalAccess` (satu pintu untuk seluruh portal);
 * - hanya selama jendela sanggah periode buka (`ppdb_periods.appeal_start` /
 *   `appeal_end`, dilihat lewat `PpdbPeriod::isAppealWindowOpen()`);
 * - hanya hasil `failed` atau `waitlisted` yang bisa disanggah;
 * - maksimal satu sanggahan berstatus `pending` per pendaftaran.
 *
 * **Keputusan `accepted` TIDAK mengubah hasil seleksi.** Sanggahan yang diterima
 * hanya membuka kembali berkas itu untuk dipertimbangkan panitia; penetapan hasil
 * tetap lewat `PpdbAnnouncementService::setResult()` (dan tetap dijaga kuota).
 * Tidak ada jalur promosi otomatis di modul ini.
 *
 * Jejak audit: `PpdbRegistrationAppeal` memakai trait `LogsPpdbActivity`, jadi
 * setiap `save()` menulis `activity_log` (log_name `ppdb`, event
 * `PPDB_APPEAL_CREATED` / `PPDB_APPEAL_UPDATED`) dengan `causer` = petugas yang
 * sedang login. Riwayat pendaftaran ditulis lewat `PpdbRegistrationStateMachine`.
 */
class PpdbAppealService
{
    /** Hasil seleksi yang boleh disanggah. */
    public const APPEALABLE_RESULTS = [
        PpdbRegistration::STATUS_FAILED,
        PpdbRegistration::STATUS_WAITLISTED,
    ];

    /** Permission panitia yang berhak mendapat kabar sanggahan baru. */
    public const REVIEWER_PERMISSION = 'ppdb.registration.verify';

    public function __construct(
        private readonly PpdbNotificationService $notifications,
        private readonly PpdbRegistrationStateMachine $state,
        private readonly PpdbPortalAccess $access,
    ) {
    }

    /**
     * Daftar sanggahan untuk panitia.
     *
     * Filter: status, periode, program, gelombang, dan pencarian nomor
     * pendaftaran/nama calon/alasan.
     */
    public function query(Request $request): Builder
    {
        return PpdbRegistrationAppeal::query()
            ->with([
                'registration:id,registration_number,period_id,program_id,wave_id,status,selection_result,applicant_id,parent_account_id',
                'registration.applicant:id,full_name,nickname',
                'registration.parentAccount.user:id,name,phone',
                'registration.program:id,name,code',
                'registration.wave:id,name,code',
                'period:id,name,code,appeal_start,appeal_end',
                'reviewedBy:id,name',
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('period_id'), fn ($q) => $q->where('period_id', (int) $request->input('period_id')))
            ->when($request->filled('program_id'), fn ($q) => $q->whereHas(
                'registration',
                fn ($r) => $r->where('program_id', (int) $request->input('program_id')),
            ))
            ->when($request->filled('wave_id'), fn ($q) => $q->whereHas(
                'registration',
                fn ($r) => $r->where('wave_id', (int) $request->input('wave_id')),
            ))
            ->when($request->filled('registration_id'), fn ($q) => $q->where('registration_id', (int) $request->input('registration_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim($request->string('search')->toString());
                $q->where(fn ($s) => $s
                    ->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('registration', fn ($r) => $r->where('registration_number', 'like', "%{$search}%"))
                    ->orWhereHas('registration.applicant', fn ($a) => $a->where('full_name', 'like', "%{$search}%")));
            })
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('id');
    }

    /**
     * Sanggahan milik satu pendaftaran (portal orang tua).
     *
     * Kepemilikan diperiksa lebih dulu, jadi ID pendaftaran milik orang lain tidak
     * pernah mengembalikan data apa pun.
     *
     * @return Collection<int, PpdbRegistrationAppeal>
     */
    public function forRegistration(PpdbRegistration $registration, User $user): Collection
    {
        $this->access->registration($user, $registration);

        return $registration->loadMissing([
            'appeals.period:id,name,code,appeal_start,appeal_end',
            'appeals.reviewedBy:id,name',
            'period:id,name,code,appeal_start,appeal_end',
        ])->appeals;
    }

    /**
     * Kelayakan pendaftaran untuk disanggah + alasan penolaknya.
     *
     * Dipakai portal (menyembunyikan/menonaktifkan tombol sanggah dengan alasan)
     * dan `submit()` (penolakan server-side tetap terjadi walau UI diloloskan).
     *
     * @return array{
     *     can_appeal: bool,
     *     window_open: bool,
     *     appeal_start: string|null,
     *     appeal_end: string|null,
     *     days_left: int|null,
     *     result: string|null,
     *     pending_id: int|null,
     *     reasons: list<string>,
     * }
     */
    public function eligibility(PpdbRegistration $registration): array
    {
        $registration->loadMissing('period:id,name,code,appeal_start,appeal_end');

        $period = $registration->period;

        $windowOpen = $period !== null && $period->isAppealWindowOpen();

        $pending = PpdbRegistrationAppeal::query()
            ->where('registration_id', $registration->id)
            ->pending()
            ->first();

        $reasons = [];

        if ($period === null) {
            $reasons[] = 'Pendaftaran ini tidak terhubung ke periode penerimaan.';
        } elseif (! $windowOpen) {
            $reasons[] = $period->appeal_start === null || $period->appeal_end === null
                ? 'Masa sanggah belum dijadwalkan pada periode ini.'
                : sprintf(
                    'Masa sanggah ditutup %s (berlangsung %s s.d. %s).',
                    $period->appeal_end->toDateString(),
                    $period->appeal_start->toDateString(),
                    $period->appeal_end->toDateString(),
                );
        }

        if (! in_array((string) $registration->selection_result, self::APPEALABLE_RESULTS, true)) {
            $reasons[] = match ((string) $registration->selection_result) {
                PpdbRegistration::STATUS_PASSED => 'Anda sudah dinyatakan lulus, tidak ada yang perlu disanggah.',
                '', '-' => 'Hasil seleksi belum diumumkan untuk pendaftaran ini.',
                default => 'Hasil seleksi pada pendaftaran ini belum dapat disanggah.',
            };
        }

        if ($pending !== null) {
            $reasons[] = 'Sanggahan Anda masih dalam pemeriksaan. Tunggu keputusan panitia sebelum mengirim sanggahan baru.';
        }

        return [
            'can_appeal' => $reasons === [],
            'window_open' => $windowOpen,
            'appeal_start' => $period?->appeal_start?->toDateString(),
            'appeal_end' => $period?->appeal_end?->toDateString(),
            'days_left' => $windowOpen && $period !== null
                ? (int) now()->startOfDay()->diffInDays($period->appeal_end, false)
                : null,
            'result' => $registration->selection_result,
            'pending_id' => $pending?->id,
            'reasons' => $reasons,
        ];
    }

    /**
     * Orang tua mengirim sanggahan (dengan lampiran opsional).
     *
     * @param  array{reason?: string, attachment?: UploadedFile|null}  $data
     */
    public function submit(PpdbRegistration $registration, User $user, array $data): PpdbRegistrationAppeal
    {
        // Kepemilikan diperiksa lewat gerbang portal yang sama dengan endpoint
        // portal lain: pendaftaran orang lain → 403, bukan data hantu.
        $registration = $this->access->registration($user, $registration);

        $eligibility = $this->eligibility($registration);

        if (! $eligibility['can_appeal']) {
            throw ValidationException::withMessages([
                'reason' => implode(' ', $eligibility['reasons']),
            ]);
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan sanggahan wajib diisi.',
            ]);
        }

        $account = $this->access->account($user);

        $appeal = DB::transaction(function () use ($registration, $account, $reason, $data, $user) {
            // Diperiksa ulang di dalam transaksi agar dua klik bersamaan tidak
            // menghasilkan dua sanggahan pending pada satu pendaftaran.
            if (PpdbRegistrationAppeal::query()->where('registration_id', $registration->id)->pending()->exists()) {
                throw ValidationException::withMessages([
                    'reason' => 'Masih ada sanggahan yang belum diputus pada pendaftaran ini.',
                ]);
            }

            $appeal = PpdbRegistrationAppeal::create([
                'registration_id' => $registration->id,
                'period_id' => $registration->period_id,
                'parent_account_id' => $account?->id ?? $registration->parent_account_id,
                'reason' => $reason,
                'status' => PpdbRegistrationAppeal::STATUS_PENDING,
            ]);

            $path = $this->storeAttachment($data['attachment'] ?? null, $appeal);

            if ($path !== null) {
                $appeal->forceFill(['attachment_path' => $path])->save();
            }

            $this->state->record(
                $registration,
                'appeal.submitted',
                'Sanggahan dikirim oleh orang tua/wali: '.$this->excerpt($reason),
                $user,
            );

            return $appeal;
        });

        $this->notifyReviewers($appeal);

        $this->notifications->toParent(
            $appeal->parentAccount ?? PpdbParentAccount::query()->find($appeal->parent_account_id),
            PpdbActivityNotification::TYPE_APPEAL_SUBMITTED,
            'Sanggahan diterima',
            sprintf(
                'Sanggahan untuk pendaftaran %s sudah kami terima dan sedang diperiksa panitia.',
                (string) $appeal->registration?->registration_number,
            ),
            [
                'appeal_id' => $appeal->id,
                'registration_number' => $appeal->registration?->registration_number,
            ],
        );

        return $appeal->refresh()->load(['registration:id,registration_number,selection_result,status', 'period:id,name,code,appeal_start,appeal_end']);
    }

    /**
     * Panitia memutuskan sanggahan.
     *
     * `review_note` wajib (aturan §29), pemeriksa & waktu keputusan distempel,
     * dan hasil seleksi **tidak** ikut berubah. Yang diterima hanya memindahkan
     * status kembali ke `selection` supaya berkas bisa diputus ulang; hasil baru
     * tetap harus ditetapkan panitia lewat `PpdbAnnouncementService::setResult()`
     * (dan tetap lewat penjaga kuota). Menolak sanggahan tidak mengubah apa pun
     * selain status sanggahan + riwayat.
     */
    public function decide(
        PpdbRegistrationAppeal $appeal,
        string $decision,
        ?string $note,
        User $actor,
    ): PpdbRegistrationAppeal {
        if (! in_array($decision, [PpdbRegistrationAppeal::STATUS_ACCEPTED, PpdbRegistrationAppeal::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Keputusan harus accepted atau rejected.',
            ]);
        }

        if (trim((string) $note) === '') {
            throw ValidationException::withMessages([
                'review_note' => 'Catatan pemeriksaan wajib diisi saat memutuskan sanggahan.',
            ]);
        }

        if ($appeal->isDecided()) {
            throw ValidationException::withMessages([
                'decision' => 'Sanggahan ini sudah diputus ('.$appeal->status.') dan tidak dapat diubah.',
            ]);
        }

        $appeal->loadMissing('registration.applicant', 'period');

        $registration = $appeal->registration;

        DB::transaction(function () use ($appeal, $decision, $note, $actor, $registration) {
            // reviewed_by + reviewed_at adalah stempel pemeriksa, bukan isian user.
            $appeal->forceFill([
                'status' => $decision,
                'review_note' => trim((string) $note),
                'reviewed_by' => $actor->id,
                'reviewed_at' => Carbon::now(),
            ])->save();

            if ($registration !== null) {
                $this->state->record(
                    $registration,
                    'appeal.decided',
                    sprintf(
                        'Sanggahan #%d %s oleh %s: %s%s',
                        $appeal->id,
                        $decision === PpdbRegistrationAppeal::STATUS_ACCEPTED ? 'diterima' : 'ditolak',
                        $actor->name,
                        $this->excerpt((string) $appeal->review_note),
                        $decision === PpdbRegistrationAppeal::STATUS_ACCEPTED
                            ? ' — hasil seleksi belum berubah, berkas dibuka kembali untuk diputus ulang panitia.'
                            : '',
                    ),
                    $actor,
                );

                // "Dibuka kembali" di sini benar-benar perpindahan status: tanpa
                // `failed|waitlisted → selection`, panitia tidak punya jalan untuk
                // menetapkan hasil baru. Hasil lama dibiarkan terpasang sebagai
                // jejak sampai panitia memutuskan ulang lewat `setResult()`.
                if ($decision === PpdbRegistrationAppeal::STATUS_ACCEPTED
                    && in_array((string) $registration->status, self::APPEALABLE_RESULTS, true)) {
                    $this->state->transition(
                        $registration,
                        PpdbRegistration::STATUS_SELECTION,
                        'Keputusan sanggahan: penilaian seleksi dibuka kembali oleh panitia.',
                        $actor,
                        notify: false,
                    );
                }
            }
        });

        $this->notifyDecision($appeal->refresh(), $actor);

        return $appeal->refresh()->load(['registration:id,registration_number,selection_result,status', 'reviewedBy:id,name', 'period:id,name,code,appeal_start,appeal_end']);
    }

    /**
     * Simpan lampiran sanggahan pada disk `public`.
     */
    private function storeAttachment(?UploadedFile $file, PpdbRegistrationAppeal $appeal): ?string
    {
        if ($file === null) {
            return null;
        }

        $extension = Str::lower((string) $file->getClientOriginalExtension()) ?: 'pdf';

        $name = Carbon::now()->format('YmdHis').'-'.Str::lower(Str::random(6)).'.'.$extension;

        $stored = Storage::disk('public')->putFileAs("ppdb/appeals/{$appeal->registration_id}", $file, $name);

        return is_string($stored) ? $stored : null;
    }

    /**
     * Kabari panitia pemeriksa bahwa ada berkas baru.
     */
    private function notifyReviewers(PpdbRegistrationAppeal $appeal): void
    {
        $this->notifications->toStaff(
            self::REVIEWER_PERMISSION,
            PpdbActivityNotification::TYPE_APPEAL_SUBMITTED,
            'Sanggahan baru perlu diperiksa',
            sprintf(
                'Pendaftaran %s mengajukan sanggahan atas hasil %s.',
                (string) $appeal->registration?->registration_number,
                (string) $appeal->registration?->selection_result,
            ),
            [
                'appeal_id' => $appeal->id,
                'registration_number' => $appeal->registration?->registration_number,
            ],
        );
    }

    /**
     * Kabari orang tua atas keputusan (kanal database yang sudah ada).
     */
    private function notifyDecision(PpdbRegistrationAppeal $appeal, User $actor): void
    {
        $accepted = $appeal->status === PpdbRegistrationAppeal::STATUS_ACCEPTED;

        $this->notifications->toParent(
            $appeal->parentAccount ?? PpdbParentAccount::query()->find($appeal->parent_account_id),
            PpdbActivityNotification::TYPE_APPEAL_DECIDED,
            $accepted ? 'Sanggahan Anda diterima' : 'Sanggahan Anda belum diterima',
            $accepted
                ? sprintf(
                    'Sanggahan pada pendaftaran %s diterima. Berkas Anda dibuka kembali untuk dipertimbangkan panitia; '
                    .'hasil seleksi belum berubah dan akan diumumkan kembali bila ada keputusan baru.',
                    (string) $appeal->registration?->registration_number,
                )
                : sprintf(
                    'Sanggahan pada pendaftaran %s ditolak. Alasan: %s',
                    (string) $appeal->registration?->registration_number,
                    (string) $appeal->review_note,
                ),
            [
                'appeal_id' => $appeal->id,
                'decision' => $appeal->status,
                'registration_number' => $appeal->registration?->registration_number,
                'reviewed_by' => $actor->name,
            ],
        );
    }

    /**
     * Potongan teks untuk catatan riwayat/notifikasi.
     */
    private function excerpt(?string $text, int $length = 160): string
    {
        $plain = trim((string) preg_replace('/\s+/', ' ', (string) $text));

        return mb_strlen($plain) > $length ? mb_substr($plain, 0, $length - 1).'…' : $plain;
    }
}
