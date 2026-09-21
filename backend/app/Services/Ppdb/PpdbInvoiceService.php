<?php

namespace App\Services\Ppdb;

use App\Models\PaymentMethod;
use App\Models\PpdbInvoice;
use App\Models\PpdbInvoiceItem;
use App\Models\PpdbParentAccount;
use App\Models\PpdbPayment;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationFee;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tagihan PPDB (biaya pendaftaran & daftar ulang).
 *
 * Baris tagihan selalu diambil dari `PpdbScopeService::fees()` (satu sumber
 * kebenaran konfigurasi biaya), lalu **disalin** ke `ppdb_invoice_items` supaya
 * tagihan lama tetap terbaca walau konfigurasi biaya diubah kemudian.
 *
 * Pembagian jenis biaya:
 * - tagihan `registration`    → biaya proses masuk (registration, selection, test, psychology)
 * - tagihan `re_registration` → biaya yang belum tertagih pada pendaftaran yang sama
 *                               (umumnya type `other`: daftar ulang, seragam, buku)
 * - tagihan `other`           → sama seperti daftar ulang (penagihan manual)
 *
 * Satu biaya hanya boleh hidup di satu tagihan untuk satu pendaftaran: biaya
 * yang sudah dipangkas tagihan yang belum dibatalkan/di-refund tidak ditagih
 * ulang, walaupun tagihan itu sudah lunas.
 *
 * Bila satu tipe biaya punya versi umum (tanpa program/gelombang) dan versi yang
 * lebih spesifik, versi paling spesifik menggantikan versi umum sehingga biaya
 * tidak ditagih dua kali.
 *
 * Status pembayaran pendaftaran tidak pernah ditulis langsung di sini: semuanya
 * kembali ke `PpdbRegistrationStateMachine` (`syncPaymentStatus` + `record`).
 *
 * Kelas ini juga menjadi rumah aksi daftar ulang (audit §30) karena tagihan
 * daftar ulang — bukan status pendaftaran — yang menentukan kapan peserta boleh
 * dianggap selesai.
 */
class PpdbInvoiceService
{
    /** Tipe biaya yang ditagih pada tagihan pendaftaran. */
    public const REGISTRATION_FEE_TYPES = [
        PpdbRegistrationFee::TYPE_REGISTRATION,
        PpdbRegistrationFee::TYPE_SELECTION,
        PpdbRegistrationFee::TYPE_TEST,
        PpdbRegistrationFee::TYPE_PSYCHOLOGY,
    ];

    public function __construct(
        private readonly PpdbScopeService $scope,
        private readonly PpdbNumberingService $numbering,
        private readonly PpdbNotificationService $notifications,
        private readonly PpdbRegistrationStateMachine $state,
        private readonly PpdbPortalAccess $access,
    ) {
    }

    /**
     * Jamin tersedia satu tagihan untuk pendaftaran + jenis ini.
     *
     * Idempoten dua arah: tagihan yang masih hidup dipakai ulang (tidak ada
     * piutang kedua untuk biaya yang sama, termasuk setelah lunas), dan tagihan
     * hanya dibangun ulang sesudah dibatalkan/di-refund.
     */
    public function ensureForRegistration(
        PpdbRegistration $registration,
        string $type = PpdbInvoice::TYPE_REGISTRATION,
    ): PpdbInvoice {
        $existing = $this->liveInvoiceFor($registration, $type);

        if ($existing !== null) {
            return $existing;
        }

        $invoice = DB::transaction(function () use ($registration, $type): PpdbInvoice {
            $lines = $this->linesFor($registration, $type);

            $amount = round(array_sum(array_map(fn (array $line) => (float) $line['amount'], $lines)), 2);

            $invoice = PpdbInvoice::create([
                'registration_id' => $registration->id,
                'number' => $this->numbering->next($type === PpdbInvoice::TYPE_RE_REGISTRATION ? 're_registration' : 'invoice'),
                'type' => $type,
                'amount' => $amount,
                'discount_amount' => 0,
                'total_amount' => $amount,
                'paid_amount' => 0,
                'status' => PpdbInvoice::STATUS_UNPAID,
                'due_date' => $this->dueDateFor($registration, $type, $lines),
                'issued_at' => Carbon::now(),
            ]);

            foreach ($lines as $line) {
                PpdbInvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'fee_id' => $line['id'],
                    'name' => $line['name'],
                    'amount' => $line['amount'],
                ]);
            }

            $invoice->setRelation('items', $invoice->items()->get());

            return $this->recalculateTotals($invoice);
        });

        $this->syncRegistration($invoice, 'invoice.created', null, null);

        return $invoice->refresh();
    }

    /**
     * Tagihan jenis ini yang masih berlaku: selain dibatalkan/refunded.
     *
     * Satu aturan yang dipakai `ensureForRegistration()` dan controller admin,
     * supaya "tagihan sudah ada" tidak ditafsir beda di dua tempat.
     */
    public function liveInvoiceFor(PpdbRegistration $registration, string $type): ?PpdbInvoice
    {
        return PpdbInvoice::query()
            ->where('registration_id', $registration->id)
            ->where('type', $type)
            ->whereNotIn('status', [PpdbInvoice::STATUS_CANCELLED, PpdbInvoice::STATUS_REFUNDED])
            ->latest('id')
            ->first();
    }

    /**
     * Baris tagihan yang berlaku untuk satu pendaftaran + jenis tagihan.
     *
     * Biaya yang sudah dipangkas tagihan hidup lain pada pendaftaran yang sama
     * tidak ditagih ulang — itu sebabnya tagihan daftar ulang hanya berisi sisa
     * biaya, bukan seluruh konfigurasi biaya.
     *
     * @return list<array<string, mixed>>
     */
    public function linesFor(PpdbRegistration $registration, string $type = PpdbInvoice::TYPE_REGISTRATION): array
    {
        $fees = $this->scope
            ->fees(
                (int) $registration->period_id,
                $registration->program_id === null ? null : (int) $registration->program_id,
                $registration->wave_id === null ? null : (int) $registration->wave_id,
            )
            ->all();

        if ($type === PpdbInvoice::TYPE_REGISTRATION) {
            $fees = array_values(array_filter(
                $fees,
                fn (array $fee) => in_array($fee['type'], self::REGISTRATION_FEE_TYPES, true),
            ));
        }

        $billed = $this->billedFeeIds((int) $registration->id);

        $fees = array_values(array_filter(
            $fees,
            fn (array $fee) => ! in_array((int) $fee['id'], $billed, true),
        ));

        return $this->keepMostSpecific($fees);
    }

    /**
     * Hitung ulang nominal terbayar + status dari pembayaran yang berstatus paid.
     *
     * Pembayaran sebagian — atau bukti bayar yang masih menunggu keputusan
     * bendahara — memindahkan tagihan ke `pending`; lunas baru saat
     * `paid_amount >= total_amount`. Status cancelled/refunded tidak diubah.
     */
    public function recalculateTotals(PpdbInvoice $invoice): PpdbInvoice
    {
        $paid = round((float) PpdbPayment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', PpdbPayment::STATUS_PAID)
            ->sum('amount'), 2);

        $total = round((float) $invoice->total_amount, 2);

        $invoice->paid_amount = $paid;

        if (in_array($invoice->status, [PpdbInvoice::STATUS_CANCELLED, PpdbInvoice::STATUS_REFUNDED], true)) {
            $invoice->save();

            return $invoice;
        }

        if ($paid >= $total) {
            $invoice->status = PpdbInvoice::STATUS_PAID;
            $invoice->paid_at ??= Carbon::now();
        } elseif ($paid > 0) {
            $invoice->status = PpdbInvoice::STATUS_PENDING;
            $invoice->paid_at = null;
        } elseif ($this->hasAwaitingPayment($invoice)) {
            // Bukti bayar sudah masuk tetapi belum diputuskan bendahara: `pending`
            // adalah satu-satunya status yang membuat keadaan itu terlihat di
            // portal maupun antrean verifikasi, jadi jangan turunkan ke `unpaid`.
            $invoice->status = PpdbInvoice::STATUS_PENDING;
            $invoice->paid_at = null;
        } elseif (in_array($invoice->status, [PpdbInvoice::STATUS_EXPIRED, PpdbInvoice::STATUS_FAILED], true)) {
            // Kedaluwarsa/gagal tidak diturunkan kembali selama belum ada pembayaran.
            $invoice->paid_at = null;
        } else {
            $invoice->status = PpdbInvoice::STATUS_UNPAID;
            $invoice->paid_at = null;
        }

        $invoice->save();

        return $invoice;
    }

    /**
     * Titik tunggal setelah sebuah pembayaran berubah status: hitung ulang
     * tagihan, sinkronkan pendaftaran, catat riwayat, kabari orang tua.
     */
    public function settle(
        PpdbInvoice $invoice,
        ?User $actor = null,
        string $action = 'payment.updated',
        ?string $note = null,
        bool $notifyParent = true,
    ): PpdbInvoice {
        $invoice = $this->recalculateTotals($invoice->refresh());

        $this->syncRegistration($invoice, $action, $note, $actor, $notifyParent);

        return $invoice;
    }

    /**
     * Tandai lunas tanpa menunggu pembayaran tercatat (mis. konfirmasi lisan
     * yang diproses petugas atau settlement penyedia).
     */
    public function markPaid(PpdbInvoice $invoice, ?User $actor = null, ?string $note = null, bool $notifyParent = true): PpdbInvoice
    {
        $invoice->forceFill([
            'paid_amount' => max((float) $invoice->paid_amount, round((float) $invoice->total_amount, 2)),
            'status' => PpdbInvoice::STATUS_PAID,
            'paid_at' => $invoice->paid_at ?? Carbon::now(),
        ])->save();

        $this->syncRegistration($invoice->refresh(), 'payment.paid', $note, $actor, $notifyParent);

        return $invoice->refresh();
    }

    /** Jatuh tempo tanpa pembayaran. */
    public function expire(PpdbInvoice $invoice, ?User $actor = null, ?string $note = null): PpdbInvoice
    {
        return $this->closeAs($invoice, PpdbInvoice::STATUS_EXPIRED, 'payment.expired', $actor, $note, 'kedaluwarsa');
    }

    /** Batalkan tagihan (mis. pendaftaran dibatalkan). */
    public function cancel(PpdbInvoice $invoice, ?User $actor = null, ?string $note = null): PpdbInvoice
    {
        return $this->closeAs($invoice, PpdbInvoice::STATUS_CANCELLED, 'payment.cancelled', $actor, $note, 'dibatalkan');
    }

    /** Dana dikembalikan setelah sempat lunas. */
    public function refund(PpdbInvoice $invoice, ?User $actor = null, ?string $note = null): PpdbInvoice
    {
        return $this->closeAs($invoice, PpdbInvoice::STATUS_REFUNDED, 'payment.refunded', $actor, $note, 'dikembalikan (refund)');
    }

    /**
     * Kedaluwarsakan tagihan yang melewati jatuh tempo — dipakai
     * `ppdb:expire-invoices` agar status `expired` benar-benar punya jalur.
     *
     * Idempoten: hanya `unpaid`/`pending` yang diambil dan setiap tagihan yang
     * lolos berpindah ke `expired`, jadi perintah yang dijalankan ulang tidak
     * menambah apa pun di atas hasil pertama. Tagihan tanpa `due_date`
     * dilewatkan: tenggatnya belum pernah ditetapkan sehingga tidak ada yang
     * bisa kedaluwarsa.
     *
     * "Lewat tenggat" disamakan dengan hitungan piutang lewat tempo pada
     * laporan (`PpdbReportService`): `due_date` sudah terlewat penuh, bukan
     * sebatas tanggal yang sama.
     *
     * Yang ditolak penjaga status (mis. baru dibayar sebagian) tidak dihukum
     * diam-diam: id-nya dikembalikan beserta alasannya supaya operator tahu
     * tagihan mana yang masih menunggu keputusan panitia.
     *
     * @return array{scanned: int, expired: int, skipped: list<array{invoice_id: int, number: string, reason: string}>}
     */
    public function expireOverdue(?int $periodId = null, int $limit = 500): array
    {
        $invoices = PpdbInvoice::query()
            ->whereIn('status', [PpdbInvoice::STATUS_UNPAID, PpdbInvoice::STATUS_PENDING])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', Carbon::now()->toDateString())
            ->when($periodId !== null, fn (Builder $q) => $q->whereHas(
                'registration',
                fn (Builder $r) => $r->where('period_id', $periodId),
            ))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $expired = 0;

        /** @var list<array{invoice_id: int, number: string, reason: string}> $skipped */
        $skipped = [];

        foreach ($invoices as $invoice) {
            try {
                DB::transaction(fn () => $this->expire($invoice, null, $this->expiryNote($invoice)));
                $expired++;
            } catch (ValidationException $exception) {
                $skipped[] = [
                    'invoice_id' => (int) $invoice->id,
                    'number' => (string) $invoice->number,
                    'reason' => implode(' ', array_map(
                        static fn (array $messages): string => implode(' ', $messages),
                        $exception->errors(),
                    )),
                ];
            }
        }

        return ['scanned' => $invoices->count(), 'expired' => $expired, 'skipped' => $skipped];
    }

    /**
     * Instruksi pembayaran manual, dibaca dari master `payment_methods` aktif.
     *
     * Tidak ada mesin pembayaran/baru: yang ditampilkan hanyalah metode bayar
     * yang sudah dipakai modul lain di aplikasi ini.
     *
     * @return list<array<string, mixed>>
     */
    public function manualInstructions(): array
    {
        return PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (PaymentMethod $method) => [
                'payment_method_id' => $method->id,
                'name' => $method->name,
                'code' => $method->code,
                'method' => PpdbPayment::METHOD_MANUAL,
                'instructions' => "Bayar lewat {$method->name}, kemudian unggah bukti transfer agar dapat diverifikasi panitia.",
            ])
            ->all();
    }

    /**
     * Query tagihan untuk daftar admin.
     *
     * Filter: status, jenis, periode, program, gelombang, tanggal terbit/jatuh
     * tempo; pencarian mencakup nomor tagihan, nomor pendaftaran, dan nama calon.
     * `paid_amount_sum` dihitung dari pembayaran berstatus `paid` agar daftar
     * admin tidak perlu menebak dari kolom `paid_amount`.
     */
    public function query(Request $request): Builder
    {
        return PpdbInvoice::query()
            ->with([
                'registration:id,registration_number,period_id,wave_id,program_id,parent_account_id,status,payment_status',
                'registration.applicant:id,full_name,nickname',
                'items',
            ])
            ->withSum(['payments as paid_amount_sum' => fn ($q) => $q->where('status', PpdbPayment::STATUS_PAID)], 'amount')
            ->withCount('payments')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->filled('period_id'), fn ($q) => $q->whereHas(
                'registration',
                fn ($r) => $r->where('period_id', (int) $request->input('period_id')),
            ))
            ->when($request->filled('program_id'), fn ($q) => $q->whereHas(
                'registration',
                fn ($r) => $r->where('program_id', (int) $request->input('program_id')),
            ))
            ->when($request->filled('wave_id'), fn ($q) => $q->whereHas(
                'registration',
                fn ($r) => $r->where('wave_id', (int) $request->input('wave_id')),
            ))
            ->when($request->filled('registration_id'), fn ($q) => $q->where('registration_id', (int) $request->input('registration_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('issued_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('issued_at', '<=', $request->date('to')))
            ->when($request->filled('due_from'), fn ($q) => $q->whereDate('due_date', '>=', $request->date('due_from')))
            ->when($request->filled('due_to'), fn ($q) => $q->whereDate('due_date', '<=', $request->date('due_to')))
            ->when($request->filled('outstanding'), fn ($q) => $q->outstanding())
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim($request->string('search')->toString());
                $q->where(fn ($s) => $s
                    ->where('number', 'like', "%{$search}%")
                    ->orWhereHas('registration', fn ($r) => $r->where('registration_number', 'like', "%{$search}%"))
                    ->orWhereHas('registration.applicant', fn ($a) => $a->where('full_name', 'like', "%{$search}%")));
            })
            ->orderByDesc('id');
    }

    /**
     * Ingatkan orang tua soal tagihan yang belum lunas.
     *
     * Dedupe key: `users.id` orang tua + type `payment_reminder` +
     * `data->registration_number` (nomor pendaftaran), tepat seperti
     * `PpdbNotificationService::alreadySent()` mencocokkan kolom itu. Artinya
     * satu orang tua hanya mendapat satu pengingat per pendaftaran, jadi
     * pengingat yang ditekan berkali-kali tidak menumpuk di inbox portal.
     *
     * @return array{sent: bool, reason: string, invoice: PpdbInvoice}
     */
    public function remind(PpdbInvoice $invoice, ?User $actor = null): array
    {
        $invoice->loadMissing('registration.parentAccount.user');

        $registration = $invoice->registration;

        if ($registration === null) {
            throw ValidationException::withMessages([
                'invoice' => 'Tagihan ini tidak terhubung ke pendaftaran mana pun.',
            ]);
        }

        if ($invoice->isPaid() || $invoice->outstandingAmount() <= 0) {
            throw ValidationException::withMessages([
                'invoice' => "Tagihan {$invoice->number} sudah lunas sehingga tidak perlu diingatkan.",
            ]);
        }

        $user = $registration->parentAccount?->user;

        if ($user === null) {
            throw ValidationException::withMessages([
                'invoice' => 'Tagihan ini belum terhubung ke akun orang tua aktif, pengingat tidak dapat dikirim.',
            ]);
        }

        if ($this->notifications->alreadySent((int) $user->id, PpdbActivityNotification::TYPE_PAYMENT_REMINDER, $registration->registration_number)) {
            return [
                'sent' => false,
                'reason' => 'already_sent',
                'invoice' => $invoice,
            ];
        }

        $due = $invoice->due_date === null
            ? 'secepatnya'
            : 'paling lambat '.$invoice->due_date->toFormattedDateString();

        $this->notifications->toParent(
            $registration->parentAccount,
            PpdbActivityNotification::TYPE_PAYMENT_REMINDER,
            'Pengingat pembayaran',
            "Tagihan {$invoice->number} sebesar Rp ".number_format($invoice->outstandingAmount(), 0, ',', '.')
                ." untuk pendaftaran {$registration->registration_number} belum dibayar. Selesaikan $due.",
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'registration_number' => $registration->registration_number,
            ],
        );

        $this->state->record(
            $registration,
            'payment.reminded',
            "Pengingat pembayaran tagihan {$invoice->number} dikirim.",
            $actor,
        );

        return ['sent' => true, 'reason' => 'sent', 'invoice' => $invoice];
    }

    /**
     * Buka daftar ulang satu peserta lulus (audit §30).
     *
     * Tiga hal terjadi dalam satu transaksi:
     * 1. tagihan jenis `re_registration` dijamin ada (nomor dari kode penomoran
     *    `re_registration`, jatuh tempo dari `ppdb_periods.re_registration_end`
     *    bila tidak ada biaya yang menetapkan tenggat sendiri);
     * 2. status pindah `passed` → `re_registration` lewat mesin status;
     * 3. orang tua dikabari beserta nominal dan tenggatnya.
     *
     * Dipanggil ulang pada pendaftaran yang sedang daftar ulang tidak membuat
     * tagihan kedua — hanya mengembalikan tagihan yang sudah ada.
     *
     * @return array{registration: PpdbRegistration, invoice: PpdbInvoice, opened: bool, deadline: string|null}
     */
    public function openReRegistration(PpdbRegistration $registration, ?User $actor = null, ?string $note = null): array
    {
        $registration->loadMissing('period', 'applicant', 'program');

        if ($registration->status === PpdbRegistration::STATUS_RE_REGISTRATION) {
            $invoice = $this->ensureReRegistrationInvoice($registration);

            return [
                'registration' => $registration,
                'invoice' => $invoice,
                'opened' => false,
                'deadline' => $invoice->due_date?->toDateString(),
            ];
        }

        if ($registration->status !== PpdbRegistration::STATUS_PASSED) {
            throw ValidationException::withMessages([
                'status' => $this->reRegistrationRefusal($registration),
            ]);
        }

        $invoice = DB::transaction(function () use ($registration, $actor, $note): PpdbInvoice {
            $invoice = $this->ensureReRegistrationInvoice($registration);

            $this->state->transition(
                $registration,
                PpdbRegistration::STATUS_RE_REGISTRATION,
                $note ?? $this->reRegistrationOpenNote($invoice),
                $actor,
            );

            return $invoice;
        });

        $this->announceReRegistration($invoice->refresh(), $registration);

        return [
            'registration' => $registration->refresh(),
            'invoice' => $invoice,
            'opened' => true,
            'deadline' => $invoice->due_date?->toDateString(),
        ];
    }

    /**
     * Tagihan daftar ulang, setelah biaya pendaftaran dipastikan punya
     * tagihannya sendiri.
     *
     * Tanpa langkah pertama, pendaftaran yang halaman pembayarannya belum pernah
     * dibuka akan menagih biaya pendaftaran di dalam tagihan daftar ulang:
     * nominalnya tetap benar, tapi tidak jelas lagi biaya apa yang dibayar.
     */
    private function ensureReRegistrationInvoice(PpdbRegistration $registration): PpdbInvoice
    {
        if ($this->linesFor($registration) !== []) {
            $this->ensureForRegistration($registration);
        }

        return $this->ensureForRegistration($registration, PpdbInvoice::TYPE_RE_REGISTRATION);
    }

    /**
     * Nyatakan daftar ulang selesai → `completed`.
     *
     * Syaratnya pembayaran daftar ulang sudah lunas; tanpa itu tidak ada jalan
     * ke status selesai. `failed` tidak punya jalur daftar ulang sama sekali dan
     * `waitlisted` harus ditetapkan lulus lebih dulu (lewat `setResult`, yang
     * dijaga kuota).
     *
     * @return array{registration: PpdbRegistration, invoice: PpdbInvoice|null, outstanding: list<array<string, mixed>>}
     */
    public function completeReRegistration(PpdbRegistration $registration, ?User $actor = null, ?string $note = null): array
    {
        $registration->loadMissing('period', 'applicant');

        if ($registration->status !== PpdbRegistration::STATUS_RE_REGISTRATION) {
            throw ValidationException::withMessages([
                'status' => $this->reRegistrationRefusal($registration),
            ]);
        }

        $invoices = $this->forRegistration($registration, PpdbInvoice::TYPE_RE_REGISTRATION);

        $outstanding = $invoices
            ->filter(fn (PpdbInvoice $invoice) => $invoice->outstandingAmount() > 0 && ! in_array(
                $invoice->status,
                [PpdbInvoice::STATUS_CANCELLED, PpdbInvoice::STATUS_REFUNDED],
                true,
            ))
            ->map(fn (PpdbInvoice $invoice) => [
                'invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'total_amount' => round((float) $invoice->total_amount, 2),
                'paid_amount' => round((float) $invoice->paid_amount, 2),
                'remaining' => $invoice->outstandingAmount(),
                'status' => $invoice->status,
            ])
            ->values()
            ->all();

        if ($outstanding !== []) {
            $total = round(array_sum(array_column($outstanding, 'remaining')), 2);

            throw ValidationException::withMessages([
                'invoice' => 'Daftar ulang belum dapat diselesaikan: tagihan '
                    .implode(', ', array_column($outstanding, 'number'))
                    .' masih kurang Rp '.number_format($total, 0, ',', '.').'.'
                    .'Bayar/verifikasi pembayarannya lebih dulu.',
            ]);
        }

        $this->state->transition(
            $registration,
            PpdbRegistration::STATUS_COMPLETED,
            $note ?? 'Daftar ulang selesai; tagihan daftar ulang sudah lunas.',
            $actor,
        );

        return [
            'registration' => $registration->refresh(),
            'invoice' => $invoices->first(),
            'outstanding' => [],
        ];
    }

    /**
     * Pesan penolakan aksi daftar ulang yang menggambarkan status sebenarnya.
     */
    private function reRegistrationRefusal(PpdbRegistration $registration): string
    {
        return match ($registration->status) {
            PpdbRegistration::STATUS_PASSED => 'Daftar ulang belum dibuka pada pendaftaran ini. '
                .'Jalankan aksi "open" lebih dulu agar tagihan daftar ulangnya terbentuk.',
            PpdbRegistration::STATUS_WAITLISTED => 'Peserta masih di daftar tunggu. Tetapkan yang bersangkutan lulus '
                .'lewat POST /ppdb/registrations/{id}/result (kuota tetap dijaga) sebelum membuka daftar ulang.',
            PpdbRegistration::STATUS_FAILED => 'Peserta yang belum lulus tidak memiliki jalur daftar ulang. '
                .'Bila ada sanggahan yang diterima, berkasnya diputus ulang oleh panitia lebih dahulu.',
            PpdbRegistration::STATUS_COMPLETED => 'Daftar ulang pendaftaran ini sudah selesai.',
            PpdbRegistration::STATUS_CANCELLED => 'Pendaftaran sudah dibatalkan sehingga tidak ada daftar ulang.',
            default => 'Daftar ulang hanya dapat dibuka pada pendaftaran yang dinyatakan lulus '
                .'(status sekarang: '.$registration->status.').',
        };
    }

    private function reRegistrationOpenNote(PpdbInvoice $invoice): string
    {
        return sprintf(
            'Daftar ulang dibuka. Tagihan %s sebesar Rp %s%s.',
            (string) $invoice->number,
            number_format((float) $invoice->total_amount, 0, ',', '.'),
            $invoice->due_date === null ? '' : ' (jatuh tempo '.$invoice->due_date->toDateString().')',
        );
    }

    /**
     * Kabar orang tua berisi nominal + tenggat daftar ulang.
     *
     * `PpdbRegistrationStateMachine::notify()` juga mengirim tipe yang sama lewat
     * `aboutRegistration()`, jadi notifikasi ini yang membawa detail tagihannya.
     */
    private function announceReRegistration(PpdbInvoice $invoice, PpdbRegistration $registration): void
    {
        $account = $registration->parentAccount ?? PpdbParentAccount::query()->find($registration->parent_account_id);

        if ($account === null) {
            return;
        }

        $this->notifications->toParent(
            $account,
            PpdbActivityNotification::TYPE_RE_REGISTRATION_OPEN,
            'Daftar ulang dibuka',
            sprintf(
                'Selamat, %s diterima di %s. Silakan selesaikan daftar ulang: tagihan %s sebesar Rp %s%s.',
                (string) $registration->applicant?->full_name,
                (string) $registration->program?->name,
                (string) $invoice->number,
                number_format((float) $invoice->total_amount, 0, ',', '.'),
                $invoice->due_date === null
                    ? ''
                    : ' paling lambat '.$invoice->due_date->toFormattedDateString(),
            ),
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'registration_number' => $registration->registration_number,
                'deadline' => $invoice->due_date?->toDateString(),
            ],
        );
    }

    /**
     * Tagihan sebuah pendaftaran lengkap dengan rincian & pembayaran.
     *
     * @return Collection<int, PpdbInvoice>
     */
    public function forRegistration(PpdbRegistration $registration, ?string $type = null): Collection
    {
        return PpdbInvoice::query()
            ->where('registration_id', $registration->id)
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->with(['items', 'payments.paymentMethod'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Pendaftaran milik pemanggil.
     *
     * Sengaja didelegasikan ke `PpdbPortalAccess`: seluruh endpoint portal harus
     * dijawab dengan bahasa yang sama (403 + pesan "bukan milik Anda"), dan
     * aturan "akun harus boleh bertransaksi" hanya hidup di satu tempat.
     */
    public function ownedRegistration(PpdbRegistration $registration, User $user): PpdbRegistration
    {
        return $this->access->registration($user, $registration);
    }

    /** Tagihan milik pemanggil — pemeriksaan turun ke pendaftaran induknya. */
    public function ownedInvoice(PpdbInvoice $invoice, User $user): PpdbInvoice
    {
        return $this->access->invoice($user, $invoice);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function dueDateFor(PpdbRegistration $registration, string $type, array $lines): ?Carbon
    {
        $deadlines = collect($lines)
            ->where('required', true)
            ->pluck('payment_deadline')
            ->filter()
            ->sort()
            ->values();

        if ($deadlines->isNotEmpty()) {
            return Carbon::parse((string) $deadlines->first())->startOfDay();
        }

        $registration->loadMissing('period');

        $fallback = $type === PpdbInvoice::TYPE_RE_REGISTRATION
            ? $registration->period?->re_registration_end
            : $registration->period?->registration_end;

        return $fallback !== null ? Carbon::parse($fallback->toDateString())->startOfDay() : null;
    }

    /**
     * id biaya yang sudah pernah muncul pada tagihan pendaftaran ini.
     *
     * @return list<int>
     */
    private function billedFeeIds(int $registrationId): array
    {
        $invoiceIds = PpdbInvoice::query()
            ->where('registration_id', $registrationId)
            ->whereNotIn('status', [PpdbInvoice::STATUS_CANCELLED, PpdbInvoice::STATUS_REFUNDED])
            ->pluck('id')
            ->all();

        if ($invoiceIds === []) {
            return [];
        }

        return array_values(array_unique(array_filter(
            PpdbInvoiceItem::query()->whereIn('invoice_id', $invoiceIds)->pluck('fee_id')->all(),
            fn ($id) => $id !== null,
        )));
    }

    /** Ada bukti bayar yang belum diputuskan (menunggu verifikasi bendahara). */
    private function hasAwaitingPayment(PpdbInvoice $invoice): bool
    {
        return PpdbPayment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', PpdbPayment::STATUS_PENDING)
            ->exists();
    }

    /**
     * Dari biaya bertipe sama, hanya level scope paling spesifik yang dipakai.
     *
     * @param  list<array<string, mixed>>  $fees
     * @return list<array<string, mixed>>
     */
    private function keepMostSpecific(array $fees): array
    {
        /** @var array<string, list<array<string, mixed>>> $groups */
        $groups = [];

        foreach ($fees as $fee) {
            $groups[$fee['type']][] = $fee;
        }

        $kept = [];

        foreach ($groups as $group) {
            $best = max(array_map(fn (array $fee) => $this->specificity($fee), $group));

            foreach ($group as $fee) {
                if ($this->specificity($fee) === $best) {
                    $kept[] = $fee;
                }
            }
        }

        return $kept;
    }

    /** 4 = program+gelombang, 3 = gelombang, 2 = program, 1 = umum. */
    private function specificity(array $fee): int
    {
        $program = $fee['program_id'] !== null;
        $wave = $fee['wave_id'] !== null;

        return match (true) {
            $program && $wave => 4,
            $wave => 3,
            $program => 2,
            default => 1,
        };
    }

    /**
     * Satu pintu penutupan tagihan: jaga status, tulis status, catat riwayat,
     * kabari orang tua.
     *
     * Alasan/`note` ikut ditulis ke kolom `notes` tagihan supaya petugas yang
     * membuka tagihan bulan depan masih tahu mengapa tagihan itu ditutup —
     * riwayat pendaftaran menyebut peristiwanya, `notes` menyimpan alasannya.
     */
    private function closeAs(PpdbInvoice $invoice, string $status, string $action, ?User $actor, ?string $note, string $label): PpdbInvoice
    {
        $this->assertTransitionAllowed($invoice, $status, $label);

        $invoice->forceFill([
            'status' => $status,
            'notes' => $note !== null && $note !== '' ? $note : $invoice->notes,
        ])->save();

        $invoice->refresh();

        $this->syncRegistration($invoice, $action, $note, $actor);

        $this->notifyParent(
            $invoice,
            PpdbActivityNotification::TYPE_PAYMENT_FAILED,
            'Status tagihan berubah',
            "Tagihan {$invoice->number} untuk pendaftaran {$invoice->registration?->registration_number} $label.",
        );

        return $invoice;
    }

    /**
     * Penjaga status akhir tagihan — transisi ilegal dibalas 422, bukan 500 dan
     * bukan state yang menghapus jejak uang.
     *
     * Berlaku untuk semua jalur (penjadwal `expire` maupun aksi panitia
     * `cancel`/`refund`), jadi aturannya tidak bisa dilewati endpoint baru:
     * - uang yang sudah diterima tidak boleh hilang oleh kedaluwarsa atau
     *   pembatalan; satu-satunya jalur keluar setelah ada pembayaran sah adalah
     *   refund — itu juga yang dijanjikan pesan `PpdbPaymentService::reject()`;
     * - refund hanya untuk tagihan yang memang sudah terbayar;
     * - `cancelled`/`refunded` adalah akhir, dan status tidak dipindahkan ke
     *   dirinya sendiri (membuat perintah penjadwal aman dijalankan ulang).
     */
    private function assertTransitionAllowed(PpdbInvoice $invoice, string $target, string $label): void
    {
        $number = (string) $invoice->number;
        $from = (string) $invoice->status;
        $received = $this->verifiedAmount($invoice);

        if ($from === $target) {
            throw ValidationException::withMessages([
                'status' => "Tagihan $number sudah berstatus \"$from\", tidak perlu diproses dua kali.",
            ]);
        }

        if (in_array($from, [PpdbInvoice::STATUS_CANCELLED, PpdbInvoice::STATUS_REFUNDED], true)) {
            throw ValidationException::withMessages([
                'status' => "Tagihan $number sudah ditutup dengan status \"$from\" sehingga tidak dapat $label lagi.",
            ]);
        }

        if ($target === PpdbInvoice::STATUS_REFUNDED) {
            if ($received <= 0 && ! $invoice->isPaid()) {
                throw ValidationException::withMessages([
                    'status' => "Refund ditolak: belum ada pembayaran sah pada tagihan $number. "
                        .'Verifikasi pembayarannya lebih dulu.',
                ]);
            }

            return;
        }

        if ($invoice->isPaid()) {
            throw ValidationException::withMessages([
                'status' => "Tagihan $number sudah lunas sehingga tidak dapat $label. "
                    .'Bila dananya dikembalikan, catat refund beserta alasannya.',
            ]);
        }

        if ($received > 0) {
            throw ValidationException::withMessages([
                'status' => "Tagihan $number sudah menerima pembayaran sah Rp "
                    .number_format($received, 0, ',', '.').' sehingga tidak dapat '
                    ."$label. Catat refund lebih dulu bila dana itu memang dikembalikan.",
            ]);
        }
    }

    /** Nominal pembayaran yang sudah disahkan panitia (status `paid`). */
    private function verifiedAmount(PpdbInvoice $invoice): float
    {
        return round((float) PpdbPayment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', PpdbPayment::STATUS_PAID)
            ->sum('amount'), 2);
    }

    /** Alasan kedaluwarsa yang dihasilkan sendiri — terbaca pada riwayat. */
    private function expiryNote(PpdbInvoice $invoice): string
    {
        return sprintf(
            'Jatuh tempo %s terlewat tanpa pembayaran; tagihan dikedaluwarsakan otomatis.',
            $invoice->due_date?->toDateString() ?? '-',
        );
    }

    /**
     * Sinkronkan status pembayaran pendaftaran + riwayat + (opsional) notifikasi.
     *
     * Dipakai juga oleh layanan pembayaran/seleksi supaya penulisan status
     * pendaftaran tetap lewat satu pintu.
     */
    public function syncRegistration(
        PpdbInvoice $invoice,
        string $action,
        ?string $note,
        ?User $actor,
        bool $notifyParent = false,
    ): void {
        $registration = $invoice->registration ?? PpdbRegistration::query()->find($invoice->registration_id);

        if ($registration === null) {
            return;
        }

        $paymentStatus = $this->state->syncPaymentStatus($registration);
        $registration->save();

        $this->state->record($registration, $action, $note, $actor, null, $paymentStatus);

        // Tagihan nol (tidak ada biaya yang berlaku) tidak perlu dilaporkan lunas.
        if ($notifyParent && round((float) $invoice->total_amount, 2) > 0) {
            $this->notifyParent($invoice, PpdbActivityNotification::TYPE_PAYMENT_PAID, 'Pembayaran diterima', $this->paidBody($invoice, $registration));
        }
    }

    private function paidBody(PpdbInvoice $invoice, PpdbRegistration $registration): string
    {
        $outstanding = $invoice->outstandingAmount();
        $prefix = "Tagihan {$invoice->number} (pendaftaran {$registration->registration_number})";

        return $outstanding > 0
            ? "$prefix sebagian sudah dibayar. Sisa tagihan: Rp ".number_format($outstanding, 0, ',', '.').'.'
            : "$prefix sudah lunas. Terima kasih.";
    }

    /**
     * Kirim notifikasi bertaut tagihan ke orang tua (kanal database existing).
     *
     * `PpdbNotificationService::toParent()` hanya menerima model akun, bukan
     * `parent_account_id`, jadi notifikasi benar-benar ke `users.id` pemiliknya.
     */
    public function notifyParent(PpdbInvoice $invoice, string $type, string $title, string $body): void
    {
        $invoice->loadMissing('registration.parentAccount');

        $account = $invoice->registration?->parentAccount;

        if ($account === null || $account->user_id === null) {
            return;
        }

        $this->notifications->toParent($account, $type, $title, $body, [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'registration_number' => $invoice->registration?->registration_number,
        ]);
    }
}
