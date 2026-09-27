<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceAdjustment;
use App\Models\FinanceCollectionAction;
use App\Models\FinanceInvoice;
use App\Models\FinancePaymentAllocation;
use App\Models\FinancePaymentPlan;
use App\Models\FinancePaymentPlanInstallment;
use App\Models\User;
use App\Notifications\FinanceActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;

/**
 * Collection / penagihan piutang (§6): dashboard penagihan, alur status, dan
 * sejarah aksi penagihan per invoice.
 *
 * Dua aturan §6 menentukan seluruh bentuk layanan ini:
 *
 * 1. **Outstanding tidak pernah diubah dari halaman collection.** Sisa tagihan
 *    tetap hasil invoice − alokasi payment confirmed − adjustment/write-off yang
 *    disetujui, yaitu `FinanceInvoice::remaining_amount` yang dipelihara mesin
 *    pembayaran dan penyesuaian. Karena itu tidak ada satu pun method di sini
 *    yang menulis kolom angka pada invoice; yang ditulis hanya baris sejarah.
 * 2. **Stage dihitung, tidak disimpan sebagai status dokumen.** Alur §6
 *    (issued → due soon → due → overdue → reminder → escalation → collection
 *    action → settled) adalah fungsi jatuh tempo, sisa tagihan, dan aksi yang
 *    sudah tercatat. Menyimpannya sebagai kolom yang bisa diedit berarti
 *    menciptakan angka yang bisa berbeda dari kenyataannya tanpa ada yang bisa
 *    memeriksanya.
 *
 * Umur piutang, pembagian kelompok AR (SPP / daftar ulang / boarding / tagihan
 * lain), dan performa penagihan memakai sumber angka yang sama dengan laporan
 * dan ekspor (§20, §68): satu kali baca, dipakai layar maupun CSV.
 */
class FinanceCollectionService
{
    public const STAGE_ISSUED = 'issued';

    public const STAGE_DUE_SOON = 'due_soon';

    public const STAGE_DUE = 'due';

    public const STAGE_OVERDUE = 'overdue';

    public const STAGE_REMINDER = 'reminder';

    public const STAGE_ESCALATION = 'escalation';

    public const STAGE_COLLECTION_ACTION = 'collection_action';

    public const STAGE_SETTLED = 'settled';

    /** Tangga workflow §6, berurut dari yang paling awal. */
    public const STAGES = [
        self::STAGE_ISSUED,
        self::STAGE_DUE_SOON,
        self::STAGE_DUE,
        self::STAGE_OVERDUE,
        self::STAGE_REMINDER,
        self::STAGE_ESCALATION,
        self::STAGE_COLLECTION_ACTION,
        self::STAGE_SETTLED,
    ];

    public const STAGE_LABELS = [
        self::STAGE_ISSUED => 'Terbit',
        self::STAGE_DUE_SOON => 'Hampir Jatuh Tempo',
        self::STAGE_DUE => 'Jatuh Tempo',
        self::STAGE_OVERDUE => 'Tunggakan',
        self::STAGE_REMINDER => 'Sudah Diingatkan',
        self::STAGE_ESCALATION => 'Eskalasi',
        self::STAGE_COLLECTION_ACTION => 'Tindakan Penagihan',
        self::STAGE_SETTLED => 'Selesai',
    ];

    /** §6: kelompok outstanding yang wajib tampak di dashboard. */
    public const GROUP_SPP = 'spp';

    public const GROUP_RE_REGISTRATION = 're_registration';

    public const GROUP_BOARDING = 'boarding';

    public const GROUP_OTHER = 'other';

    public const GROUPS = [self::GROUP_SPP, self::GROUP_RE_REGISTRATION, self::GROUP_BOARDING, self::GROUP_OTHER];

    public const GROUP_LABELS = [
        self::GROUP_SPP => 'SPP',
        self::GROUP_RE_REGISTRATION => 'Daftar Ulang',
        self::GROUP_BOARDING => 'Boarding',
        self::GROUP_OTHER => 'Tagihan Lain',
    ];

    /** Naik ke tangga eskalasi menuntut hak khusus, bukan sekadar hak penagihan. */
    public const ESCALATE_PERMISSION = 'finance.collection.escalate';

    /**
     * Stage yang dilewati sebuah aksi. Aksi tidak menurunkan stage: mencatat
     * "sudah diingatkan" atas tagihan yang belum jatuh tempo tidak membuat
     * tagihannya lebih tertagih, hanya lebih terdokumentasi.
     */
    private const ACTION_STAGE = [
        FinanceCollectionAction::TYPE_REMINDER => self::STAGE_REMINDER,
        FinanceCollectionAction::TYPE_EMAIL => self::STAGE_REMINDER,
        FinanceCollectionAction::TYPE_WHATSAPP => self::STAGE_REMINDER,
        FinanceCollectionAction::TYPE_PARENT_NOTIFICATION => self::STAGE_REMINDER,
        FinanceCollectionAction::TYPE_PHONE_CALL => self::STAGE_ESCALATION,
        FinanceCollectionAction::TYPE_MANUAL_FOLLOW_UP => self::STAGE_ESCALATION,
        FinanceCollectionAction::TYPE_PAYMENT_PLAN => self::STAGE_COLLECTION_ACTION,
        FinanceCollectionAction::TYPE_WAIVER_REQUEST => self::STAGE_COLLECTION_ACTION,
        FinanceCollectionAction::TYPE_WRITE_OFF_REQUEST => self::STAGE_COLLECTION_ACTION,
    ];

    public function __construct(
        private readonly FinanceSettingsService $settings,
        private readonly FinanceReceivableService $receivables,
        private readonly FinanceNotificationService $notifications,
    ) {}

    // ==================== Workflow §6 ====================

    /** Jendela "due soon" (§6) dalam hari; dibaca dari konfigurasi. */
    public function dueSoonDays(): int
    {
        return max(0, min(365, (int) $this->settings->get('collection_due_soon_days', 7)));
    }

    /** Umur tunggakan yang menaikkan tagihan ke tangga eskalasi (§6). */
    public function escalationDays(): int
    {
        return max(1, min(3650, (int) $this->settings->get('collection_escalation_days', 30)));
    }

    /**
     * Anak tangga pengingat yang dikonfigurasi bendahara (§7), bersih dan urut.
     * Bilangan yang bukan angka, duplikat, dan yang di luar setahun dibuang:
     * satu salah ketik di Settings tidak boleh menghentikan scheduler.
     *
     * @return list<int>
     */
    public function reminderOffsets(): array
    {
        $raw = (string) $this->settings->get('invoice_reminder_offsets', '-7,-3,-1,1,7');

        $offsets = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if (! is_numeric($part)) {
                continue;
            }

            $value = (int) $part;

            if (abs($value) > 365) {
                continue;
            }

            $offsets[$value] = true;
        }

        $list = array_keys($offsets);
        sort($list);

        return array_map('intval', $list);
    }

    /**
     * Kirim pengingat jatuh tempo bagi tagihan yang hari ini menyentuh anak
     * tangganya (§7): H-7, H-3, H-1, H+1, H+7 pada default — tetapi bilangannya
     * milik konfigurasi, dan tangga 0 ("jatuh tempo hari ini") juga sah.
     *
     * Sebuah invoice hanya punya satu selisih hari terhadap satu tanggal acuan,
     * jadi paling banyak satu pengingat terkirim per tagihan per hari. Tangga
     * yang terlewat karena scheduler mati tidak disusulkan sekaligus pada hari
     * berikutnya: wali mendapat pengingat yang tertinggal, bukan lima sekaligus.
     *
     * @return array{enabled: bool, offsets: list<int>, checked: int, sent: int}
     */
    public function sendReminders(?Carbon $asOf = null): array
    {
        $offsets = $this->reminderOffsets();
        $enabled = (bool) $this->settings->get('invoice_reminders_enabled', true);

        if (! $enabled || $offsets === []) {
            return ['enabled' => $enabled, 'offsets' => $offsets, 'checked' => 0, 'sent' => 0];
        }

        $date = $asOf ?? Carbon::now();

        // Hanya tagihan yang masih punya sisa dan masih hidup. Yang lunas,
        // dibatalkan, atau tidak punya santri (mis. tagihan vendor) tidak
        // ditagih pengingat.
        $invoices = FinanceInvoice::query()
            ->whereIn('status', [
                FinanceInvoice::STATUS_ISSUED,
                FinanceInvoice::STATUS_PARTIAL,
                FinanceInvoice::STATUS_OVERDUE,
            ])
            ->whereNotNull('due_date')
            ->whereNotNull('student_id')
            ->where('remaining_amount', '>', 0)
            ->get();

        $sent = 0;

        foreach ($invoices as $invoice) {
            $days = $this->daysPast($invoice->due_date, $date);

            if (! in_array($days, $offsets, true)) {
                continue;
            }

            $sent += $this->notifications->invoiceReminder($invoice, $days);
        }

        return [
            'enabled' => $enabled,
            'offsets' => $offsets,
            'checked' => $invoices->count(),
            'sent' => $sent,
        ];
    }

    /**
     * Anak tangga pengingat yang sama, dipakai angsuran rencana pembayaran (§7, §8).
     *
     * Dipisah dari `sendReminders()` karena sumber tanggalnya berbeda: di sana
     * jatuh tempo invoice, di sini jatuh tempo tiap angsuran. Tangganya sendiri
     * sengaja dipakai ulang dari Settings (kunci `invoice_reminder_offsets`) —
     * satu sekolah tidak punya dua irama pengingat yang berbeda untuk tagihan dan
     * cicilannya, dan kunci kedua hanya akan menciptakan jawaban "kenapa H-3
     * cicilan tidak terkirim" yang harus dicari di dua tempat.
     *
     * Yang ditagih pesannya adalah sisa angsuran, bukan sisa invoice: wali yang
     * mencicil sudah berjanji pada angka per tanggal, dan menagih ulang total
     * tagihannya justru membingungkan.
     *
     * @return array{enabled: bool, offsets: list<int>, checked: int, sent: int}
     */
    public function sendInstallmentReminders(?Carbon $asOf = null): array
    {
        $offsets = $this->reminderOffsets();
        $enabled = (bool) $this->settings->get('invoice_reminders_enabled', true);

        if (! $enabled || $offsets === []) {
            return ['enabled' => $enabled, 'offsets' => $offsets, 'checked' => 0, 'sent' => 0];
        }

        $date = ($asOf ?? Carbon::now())->copy()->startOfDay();

        // Anak tangga → tanggal jatuh tempo: due = acuan − offset. Rentangnya
        // diambil dari ujung tangganya, jadi hanya angsuran yang bisa jatuh pada
        // salah satu anak tangga yang dibaca — bukan seluruh tabel, yang akan
        // membesar bersama sekolah. `whereBetween` dan bukan `whereIn` tanggal:
        // kolomnya menyimpan 'Y-m-d' di MySQL dan 'Y-m-d 00:00:00' lewat cast
        // Laravel, dan pencocokan string persis akan gagal di salah satunya.
        // Kecocokan tepatnya tetap diputaskan di bawah oleh `daysPast()`.
        $low = $date->copy()->subDays(max($offsets))->startOfDay();
        $high = $date->copy()->subDays(min($offsets))->endOfDay();

        $installments = FinancePaymentPlanInstallment::query()
            ->whereBetween('due_date', [$low, $high])
            ->where('outstanding_amount', '>', FinancePaymentPlan::EPSILON)
            ->with('plan')
            ->get()
            ->filter(fn (FinancePaymentPlanInstallment $i): bool => $i->plan !== null && ! $i->plan->isCancelled());

        $sent = 0;

        foreach ($installments as $installment) {
            $days = $this->daysPast($installment->due_date, $date);

            if (! in_array($days, $offsets, true)) {
                continue;
            }

            $sent += $this->notifications->installmentReminder($installment, $days);
        }

        return [
            'enabled' => $enabled,
            'offsets' => $offsets,
            'checked' => $installments->count(),
            'sent' => $sent,
        ];
    }

    public function stageSeverity(string $stage): int
    {
        $index = array_search($stage, self::STAGES, true);

        return $index === false ? 0 : (int) $index;
    }

    /**
     * Stage sebuah invoice pada tanggal acuan, termasuk aksi terakhir yang sudah
     * tercatat. Dipakai daftar, dashboard, laporan, dan pembekuan stage pada
     * baris sejarah — satu rumus, jadi tidak mungkin dua layar berbeda jawaban.
     */
    public function stageFor(FinanceInvoice $invoice, Carbon $asOf, ?FinanceCollectionAction $action = null): string
    {
        $stage = $this->dateStage($invoice, $asOf);

        if ($stage === self::STAGE_SETTLED) {
            return self::STAGE_SETTLED;
        }

        $candidates = [
            $action !== null ? (self::ACTION_STAGE[$action->action_type] ?? null) : null,
            $this->agedStage($invoice, $asOf, $stage),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $this->stageSeverity($candidate) > $this->stageSeverity($stage)) {
                $stage = $candidate;
            }
        }

        return $stage;
    }

    // ==================== Antrean, dashboard, sejarah ====================

    /**
     * Antrean penagihan: invoice outstanding + stage + aksi terakhir.
     *
     * @param  array<string, mixed>  $filters
     * @return array{as_of: string, rows: list<array<string, mixed>>, stages: list<string>, stage_labels: array<string,string>}
     */
    public function queue(array $filters = []): array
    {
        $asOf = $this->asOf($filters);
        $invoices = $this->query($filters)->limit($this->limit($filters))->get();
        $summary = $this->actionSummary($invoices->pluck('id')->all());

        $rows = [];
        foreach ($invoices as $invoice) {
            $row = $this->row($invoice, $asOf, $summary, (int) $invoice->id);

            // `stage` dan `never_contacted` disaring setelah barisnya dihitung:
            // stage bukan kolom database, jadi tidak bisa menjadi WHERE.
            if (filled($filters['stage'] ?? null) && $row['stage'] !== (string) $filters['stage']) {
                continue;
            }

            if (($filters['never_contacted'] ?? false) && $row['action_count'] > 0) {
                continue;
            }

            $rows[] = $row;
        }

        return [
            'as_of' => $asOf->toDateString(),
            'rows' => $rows,
            'stages' => self::STAGES,
            'stage_labels' => self::STAGE_LABELS,
        ];
    }

    /**
     * Dashboard penagihan (§6): total AR per ember umur, pembagian SPP/daftar
     * ulang/boarding/tagihan lain, tangga workflow, performa penagihan, dan
     * penunggak terbesar.
     *
     * Semuanya dihitung dari kumpulan invoice yang sama, jadi angka dashboard
     * selalu bisa dibuka menjadi daftar yang muncul ketika KPI-nya diklik.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(array $filters = []): array
    {
        $asOf = $this->asOf($filters);
        $invoices = $this->query($filters)->with('items')->limit($this->limit($filters))->get();
        $summary = $this->actionSummary($invoices->pluck('id')->all());

        $aging = $this->receivables->aging($filters);
        $buckets = [];
        foreach ($aging['buckets'] as $bucket) {
            $buckets[$bucket['bucket']] = [
                'label' => $bucket['label'],
                'count' => $bucket['count'],
                'total' => $bucket['total'],
            ];
        }

        $current = (float) ($buckets[FinanceReceivableService::BUCKET_CURRENT]['total'] ?? 0);
        $overdueTotal = round((float) $aging['grand_total'] - $current, 2);

        $stages = [];
        foreach (self::STAGES as $stage) {
            $stages[$stage] = ['count' => 0, 'total' => 0.0];
        }

        $neverContactedOverdue = 0;
        $contacted = 0;

        foreach ($invoices as $invoice) {
            $id = (int) $invoice->id;
            $stage = $this->stageFor($invoice, $asOf, $summary['latest'][$id] ?? null);
            $remaining = round((float) $invoice->remaining_amount, 2);

            $stages[$stage]['count']++;
            $stages[$stage]['total'] = round($stages[$stage]['total'] + $remaining, 2);

            if (($summary['counts'][$id] ?? 0) === 0 && $this->dateStage($invoice, $asOf) === self::STAGE_OVERDUE) {
                $neverContactedOverdue++;
            }

            if (($summary['counts'][$id] ?? 0) > 0) {
                $contacted++;
            }
        }

        return [
            'as_of' => $asOf->toDateString(),
            'receivable' => [
                'total' => $aging['grand_total'],
                'current' => $current,
                'overdue' => $overdueTotal,
                'invoice_count' => $invoices->count(),
            ],
            'aging' => $buckets,
            'by_category' => $this->groupOutstanding($invoices),
            'workflow' => array_map(fn (string $stage): array => [
                'stage' => $stage,
                'label' => self::STAGE_LABELS[$stage],
                'count' => $stages[$stage]['count'],
                'total' => $stages[$stage]['total'],
            ], self::STAGES),
            'performance' => $this->performance($filters, $invoices, $asOf),
            'top_overdue' => $this->topOverdue($invoices, $summary, $asOf, $this->topLimit($filters)),
            'attention' => [
                // Tunggakan yang belum pernah dihubungi sama sekali: di sinilah
                // penagihan seharusnya dimulai, dan angka inilah yang membuat
                // dashboard berguna dan bukan sekadar hiasan.
                'never_contacted_overdue' => $neverContactedOverdue,
                'contacted_invoices' => $contacted,
            ],
            'settings' => [
                'due_soon_days' => $this->dueSoonDays(),
                'escalation_days' => $this->escalationDays(),
            ],
        ];
    }

    /**
     * Baris laporan Collection (§20): ditagih, tertagih, sisa, stage, aksi
     * terakhir — ditutup baris TOTAL yang memuat collection rate dan overdue
     * rate supaya ekspor CSV membawa angka yang sama dengan layar.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function reportRows(array $filters = []): array
    {
        $asOf = $this->asOf($filters);
        $invoices = $this->query($filters)->limit($this->limit($filters))->get();
        $summary = $this->actionSummary($invoices->pluck('id')->all());

        $rows = [];
        foreach ($invoices as $invoice) {
            $rows[] = $this->row($invoice, $asOf, $summary, (int) $invoice->id);
        }

        $performance = $this->performance($filters, $invoices, $asOf);

        $total = $this->blankRow();
        $total['customer'] = 'TOTAL PENAGIHAN';
        $total['issue_date'] = $performance['from'];
        $total['due_date'] = $performance['to'];
        $total['total'] = $performance['billed'];
        $total['paid_amount'] = $performance['collected'];
        $total['written_off_amount'] = $performance['written_off'];
        $total['remaining_amount'] = $performance['outstanding'];
        $total['action_count'] = array_sum($summary['counts']);
        $total['stage_label'] = sprintf(
            'Collection rate %.2f%% · Overdue rate %.2f%%',
            $performance['collection_rate'],
            $performance['overdue_rate'],
        );

        $rows[] = $total;

        return $rows;
    }

    /** @return Collection<int, FinanceCollectionAction> */
    public function history(FinanceInvoice $invoice): Collection
    {
        return FinanceCollectionAction::query()
            ->with(['createdBy:id,name', 'reference'])
            ->where('invoice_id', (int) $invoice->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    // ==================== Mencatat aksi ====================

    /**
     * Catat satu aksi penagihan (§6). Aksi tidak mengubah satu rupiah pun angka
     * piutang — ia hanya menambah sejarah dan, untuk kanal yang benar-benar
     * tersedia di sistem, mengirim notifikasi in-app ke santri/wali.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(FinanceInvoice $invoice, array $data, ?User $actor = null): FinanceCollectionAction
    {
        $actionType = (string) ($data['action_type'] ?? '');

        if (! in_array($actionType, FinanceCollectionAction::TYPES, true)) {
            throw ValidationException::withMessages([
                'action_type' => 'Jenis aksi penagihan tidak dikenali: '.($actionType === '' ? '-' : $actionType),
            ]);
        }

        $this->assertEscalationAllowed($actionType, $actor);

        return DB::transaction(function () use ($invoice, $actionType, $data, $actor): FinanceCollectionAction {
            $fresh = FinanceInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isSettled()) {
                // Aksi atas piutang yang sudah habis hanya menggemukkan sejarah
                // dan memberi kesan palsu bahwa penagihan masih berjalan.
                throw ValidationException::withMessages([
                    'invoice_id' => "Invoice {$fresh->invoice_number} sudah selesai; tidak ada penagihan yang perlu dicatat.",
                ]);
            }

            $occurredAt = filled($data['occurred_at'] ?? null)
                ? Carbon::parse((string) $data['occurred_at'])
                : Carbon::now();

            $summary = $this->actionSummary([(int) $fresh->id]);

            $action = new FinanceCollectionAction([
                'invoice_id' => (int) $fresh->id,
                'action_type' => $actionType,
                // Stage dibekukan dari posisi SEBELUM aksi ini masuk, supaya
                // sejarah membaca "ditagih dari tangga mana".
                'stage' => $this->stageFor($fresh, $occurredAt->copy()->startOfDay(), $summary['latest'][(int) $fresh->id] ?? null),
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'occurred_at' => $occurredAt,
                'created_by' => $actor?->id,
            ]);

            $this->applyReference($action, $fresh, $data);

            // Notifikasi dihitung sebelum barisnya disimpan, supaya jumlah yang
            // benar-benar terkirim ikut menjadi atribut pertama baris sejarah —
            // bukan koreksi setelahnya.
            $action->notified_count = $this->deliver($fresh, $action);
            $action->save();

            return $action->refresh()->load(['createdBy:id,name', 'reference']);
        });
    }

    // ==================== Internal ====================

    /**
     * Stage murni dari tanggal + sisa tagihan, belum dicampuri aksi.
     */
    protected function dateStage(FinanceInvoice $invoice, Carbon $asOf): string
    {
        if ($invoice->isSettled()) {
            return self::STAGE_SETTLED;
        }

        $due = $invoice->due_date;

        if ($due === null) {
            return self::STAGE_ISSUED;
        }

        // Positif = tanggal acuan sudah lewat jatuh tempo.
        $days = $this->daysPast($due, $asOf);

        return match (true) {
            $days > 0 => self::STAGE_OVERDUE,
            $days === 0 => self::STAGE_DUE,
            $days >= -$this->dueSoonDays() => self::STAGE_DUE_SOON,
            default => self::STAGE_ISSUED,
        };
    }

    /**
     * Umur tunggakan saja sudah menaikkan tangga (§6): penunggak 90 hari berada
     * di eskalasi walaupun belum ada aksi, supaya antrean tidak perlu menunggu
     * seseorang sempat mencatat sesuatu untuk terlihat genting.
     */
    protected function agedStage(FinanceInvoice $invoice, Carbon $asOf, string $dateStage): ?string
    {
        if ($dateStage !== self::STAGE_OVERDUE || $invoice->due_date === null) {
            return null;
        }

        return $this->daysPast($invoice->due_date, $asOf) >= $this->escalationDays()
            ? self::STAGE_ESCALATION
            : null;
    }

    protected function daysPast(Carbon $dueDate, Carbon $asOf): int
    {
        return (int) $dueDate->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay(), false);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<FinanceInvoice>
     */
    protected function query(array $filters = []): Builder
    {
        return $this->receivables->query($filters);
    }

    /**
     * Aksi terakhir + jumlah aksi per invoice, dua query untuk seluruh antrean
     * (bukan satu query per baris).
     *
     * @param  array<int, int>  $invoiceIds
     * @return array{latest: array<int, FinanceCollectionAction>, counts: array<int, int>}
     */
    protected function actionSummary(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return ['latest' => [], 'counts' => []];
        }

        $latest = FinanceCollectionAction::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'invoice_id', 'action_type', 'stage', 'occurred_at', 'created_by'])
            ->groupBy('invoice_id')
            ->map(fn (Collection $group): FinanceCollectionAction => $group->last())
            ->all();

        $counts = FinanceCollectionAction::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->select('invoice_id', DB::raw('COUNT(*) as total'))
            ->groupBy('invoice_id')
            ->pluck('total', 'invoice_id')
            ->map(fn ($value): int => (int) $value)
            ->all();

        return ['latest' => $latest, 'counts' => $counts];
    }

    /**
     * Satu baris penagihan: angka AR warisan mesin piutang + posisi workflow-nya.
     *
     * @param  array{latest: array<int, FinanceCollectionAction>, counts: array<int, int>}  $summary
     * @return array<string, mixed>
     */
    protected function row(FinanceInvoice $invoice, Carbon $asOf, array $summary, int $invoiceId): array
    {
        $action = $summary['latest'][$invoiceId] ?? null;

        $row = $this->receivables->receivableRow($invoice, $asOf);
        $row['stage'] = $this->stageFor($invoice, $asOf, $action);
        $row['stage_label'] = self::STAGE_LABELS[$row['stage']];
        $row['days_until_due'] = $invoice->due_date === null ? null : -$this->daysPast($invoice->due_date, $asOf);
        $row['action_count'] = $summary['counts'][$invoiceId] ?? 0;
        $row['action_type'] = $action?->action_type;
        $row['action_label'] = $action?->typeLabel();
        $row['last_action_at'] = $action?->occurred_at?->toIso8601String();

        return $row;
    }

    /**
     * Kerangka baris kosong dengan kunci yang sama persis dengan `row()` —
     * dipakai baris TOTAL supaya tabel dan CSV tidak pernah punya kolom berbeda.
     *
     * @return array<string, mixed>
     */
    protected function blankRow(): array
    {
        return [
            'invoice_id' => null,
            'invoice_number' => '',
            'customer' => '',
            'nis' => '',
            'cost_center' => '',
            'issue_date' => null,
            'due_date' => null,
            'total' => 0.0,
            'paid_amount' => 0.0,
            'written_off_amount' => 0.0,
            'remaining_amount' => 0.0,
            'days_overdue' => null,
            'bucket' => '',
            'bucket_label' => '',
            'status' => '',
            'status_label' => '',
            'receivable_status' => '',
            'stage' => '',
            'stage_label' => '',
            'days_until_due' => null,
            'action_count' => 0,
            'action_type' => '',
            'action_label' => '',
            'last_action_at' => null,
        ];
    }

    /**
     * Pembagian outstanding §6 menurut akun pendapatan tiap item invoice.
     *
     * Pembayaran dialokasikan di level invoice, bukan per item (§17), jadi sisa
     * sebuah invoice dibagi proporsional ke itemnya — perlakuan yang sama dipakai
     * jurnal cash-basis saat memecah pendapatan per akun item. Selisih pembulatan
     * diserap kelompok terbesar: keempat kelompok harus menjumlah persis sama
     * dengan total AR, bukan "hampir sama".
     *
     * @param  Collection<int, FinanceInvoice>  $invoices
     * @return list<array<string, mixed>>
     */
    protected function groupOutstanding(Collection $invoices): array
    {
        $groups = $this->accountGroups();
        $raw = array_fill_keys(self::GROUPS, 0.0);
        $counts = array_fill_keys(self::GROUPS, 0);
        $total = 0.0;

        foreach ($invoices as $invoice) {
            $remaining = round((float) $invoice->remaining_amount, 2);

            if ($remaining <= 0.0001) {
                continue;
            }

            $total = round($total + $remaining, 2);
            $items = $invoice->items->filter(fn ($item): bool => (float) $item->total > 0.0001);
            $weight = round((float) $items->sum('total'), 2);

            if ($weight <= 0.0001) {
                // Invoice tanpa item tetap piutang: masuk kelompok lain, bukan
                // hilang dari total.
                $raw[self::GROUP_OTHER] += $remaining;
                $counts[self::GROUP_OTHER]++;

                continue;
            }

            $touched = [];
            foreach ($items as $item) {
                $group = $groups[(int) $item->account_id] ?? self::GROUP_OTHER;
                $raw[$group] += $remaining * ((float) $item->total / $weight);
                $touched[$group] = true;
            }

            foreach (array_keys($touched) as $group) {
                $counts[$group]++;
            }
        }

        $amounts = array_map(fn (float $value): float => round($value, 2), $raw);
        $drift = round($total - array_sum($amounts), 2);

        if (abs($drift) > 0.0001) {
            $largest = self::GROUP_OTHER;
            foreach (self::GROUPS as $group) {
                if ($amounts[$group] > $amounts[$largest]) {
                    $largest = $group;
                }
            }
            $amounts[$largest] = round($amounts[$largest] + $drift, 2);
        }

        return array_map(fn (string $group): array => [
            'group' => $group,
            'label' => self::GROUP_LABELS[$group],
            'invoices' => $counts[$group],
            'outstanding' => $amounts[$group],
            'share' => $total > 0 ? round($amounts[$group] / $total * 100, 2) : 0.0,
        ], self::GROUPS);
    }

    /**
     * account_id → kelompok AR, dari kode akun yang diisi di Settings (§6).
     *
     * @return array<int, string>
     */
    protected function accountGroups(): array
    {
        $configured = [
            self::GROUP_SPP => $this->codeList('collection_spp_account_codes'),
            self::GROUP_RE_REGISTRATION => $this->codeList('collection_re_registration_account_codes'),
            self::GROUP_BOARDING => $this->codeList('collection_boarding_account_codes'),
        ];

        $codes = array_unique(array_merge(...array_values($configured)));

        if ($codes === []) {
            return [];
        }

        $idsByCode = FinanceAccount::query()->whereIn('code', $codes)->pluck('id', 'code')->all();

        $groups = [];
        foreach ($configured as $group => $groupCodes) {
            foreach ($groupCodes as $code) {
                if (isset($idsByCode[$code])) {
                    // Urutan konfigurasi berarti: akun yang dipetakan ke dua
                    // kelompok mengikuti kelompok yang disebut lebih dulu.
                    $groups[(int) $idsByCode[$code]] ??= $group;
                }
            }
        }

        return $groups;
    }

    /** @return list<string> */
    protected function codeList(string $key): array
    {
        $value = (string) $this->settings->get($key, '');

        return array_values(array_filter(array_map(
            'trim',
            explode(',', $value),
        ), static fn (string $code): bool => $code !== ''));
    }

    /**
     * Billed / collected / written off / outstanding + dua rasio §20 untuk
     * sebuah jendela tanggal (default: bulan berjalan sampai tanggal acuan).
     *
     * `collected` dibaca dari alokasi payment yang confirmed — bukan dari
     * invoice — karena yang menentukan uang benar-benar dipakai membayar piutang
     * adalah alokasinya (§6, §21). Pembayaran yang belum dialokasikan tidak
     * dihitung tertagih: uangnya sudah masuk tetapi belum menuntaskan piutang
     * apa pun. Collection rate bisa melebihi 100% bila uang yang masuk bulan ini
     * melunasi tagihan bulan-bulan sebelumnya; itu fakta, jadi tidak dibulatkan
     * ke atas jadi "rapi".
     *
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, FinanceInvoice>  $invoices
     * @return array<string, float|int|string>
     */
    protected function performance(array $filters, Collection $invoices, Carbon $asOf): array
    {
        [$from, $to] = $this->window($filters, $asOf);

        // whereDate, bukan whereBetween pada kolom tanggal: nilai date di sini
        // bisa tersimpan dengan komponen jam, dan perbandingan string akan
        // membuang tagihan yang terbit tepat pada hari `to`.
        $billed = round((float) $this->scopeInvoices(FinanceInvoice::query()
            ->where('status', '!=', FinanceInvoice::STATUS_CANCELLED)
            ->whereDate('issue_date', '>=', $from)
            ->whereDate('issue_date', '<=', $to), $filters)->sum('total'), 2);

        $writtenOff = round((float) FinanceAdjustment::query()
            ->where('kind', FinanceAdjustment::KIND_WRITE_OFF)
            ->whereIn('status', [FinanceAdjustment::STATUS_APPROVED, FinanceAdjustment::STATUS_POSTED])
            ->whereDate('adjustment_date', '>=', $from)
            ->whereDate('adjustment_date', '<=', $to)
            ->when(filled($filters['cost_center_id'] ?? null), fn (Builder $q) => $q->where('cost_center_id', (int) $filters['cost_center_id']))
            ->sum('amount'), 2);

        $collected = round((float) FinancePaymentAllocation::query()
            ->whereHas('payment', fn (Builder $q) => $q->confirmed()->whereBetween('paid_at', [$from.' 00:00:00', $to.' 23:59:59']))
            ->whereHas('invoice', fn (Builder $q) => $this->scopeInvoices($q, $filters))
            ->sum('amount'), 2);

        $outstanding = round((float) $invoices->sum(fn (FinanceInvoice $invoice): float => (float) $invoice->remaining_amount), 2);
        $overdue = round((float) $invoices->sum(
            fn (FinanceInvoice $invoice): float => $this->dateStage($invoice, $asOf) === self::STAGE_OVERDUE
                ? (float) $invoice->remaining_amount
                : 0.0,
        ), 2);

        return [
            'from' => $from,
            'to' => $to,
            'billed' => $billed,
            'collected' => $collected,
            'written_off' => $writtenOff,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
            'collection_rate' => $billed > 0 ? round(($collected + $writtenOff) / $billed * 100, 2) : 0.0,
            'overdue_rate' => $outstanding > 0 ? round($overdue / $outstanding * 100, 2) : 0.0,
        ];
    }

    /**
     * Saringan unit/customer yang sama untuk query agregat: angka dashboard dan
     * angka laporan harus menyaring dengan cara yang sama, tidak boleh satu
     * menghormati filter cost center dan yang lain diam-diam mengabaikannya.
     *
     * @param  Builder<FinanceInvoice>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<FinanceInvoice>
     */
    protected function scopeInvoices(Builder $query, array $filters): Builder
    {
        return $query
            ->when(filled($filters['cost_center_id'] ?? null), fn (Builder $q) => $q->where('cost_center_id', (int) $filters['cost_center_id']))
            ->when(filled($filters['student_id'] ?? null), fn (Builder $q) => $q->where('student_id', (int) $filters['student_id']))
            ->when(filled($filters['customer_type'] ?? null), fn (Builder $q) => $q->where('customer_type', (string) $filters['customer_type']));
    }

    /**
     * Penunggak terbesar (§6): dikelompokkan per customer, hanya sisa yang sudah
     * lewat jatuh tempo, terurut dari yang terberat.
     *
     * @param  Collection<int, FinanceInvoice>  $invoices
     * @param  array{latest: array<int, FinanceCollectionAction>, counts: array<int, int>}  $summary
     * @return list<array<string, mixed>>
     */
    protected function topOverdue(Collection $invoices, array $summary, Carbon $asOf, int $limit): array
    {
        $byCustomer = [];

        foreach ($invoices as $invoice) {
            if ($this->dateStage($invoice, $asOf) !== self::STAGE_OVERDUE) {
                continue;
            }

            $id = (int) $invoice->id;
            $key = $invoice->student_id !== null
                ? 'student:'.$invoice->student_id
                : $invoice->customer_type.':'.$invoice->customer_id;

            $byCustomer[$key] ??= [
                'student_id' => $invoice->student_id !== null ? (int) $invoice->student_id : null,
                'customer' => $invoice->student?->full_name ?? ($invoice->customer_type.' #'.$invoice->customer_id),
                'nis' => $invoice->student?->nis,
                'invoices' => 0,
                'oldest_due_date' => null,
                'last_action_at' => null,
                'action_count' => 0,
                'overdue' => 0.0,
            ];

            $entry = &$byCustomer[$key];
            $entry['invoices']++;
            $entry['overdue'] = round($entry['overdue'] + (float) $invoice->remaining_amount, 2);

            $due = $invoice->due_date?->toDateString();
            if ($due !== null && ($entry['oldest_due_date'] === null || $due < $entry['oldest_due_date'])) {
                $entry['oldest_due_date'] = $due;
            }

            $entry['action_count'] += $summary['counts'][$id] ?? 0;
            $last = ($summary['latest'][$id] ?? null)?->occurred_at?->toIso8601String();
            if ($last !== null && ($entry['last_action_at'] === null || $last > $entry['last_action_at'])) {
                $entry['last_action_at'] = $last;
            }

            unset($entry);
        }

        $rows = array_values($byCustomer);
        usort($rows, fn (array $a, array $b): int => [$b['overdue'], $b['invoices']] <=> [$a['overdue'], $a['invoices']]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Aksi bertangga eskalasi ke atas bukan urusan petugas penagihan biasa:
     * §6 memisahkan reminder dari escalation dan collection action, dan batas
     * itu hanya berarti kalau ditegakkan di service, bukan di menu UI.
     */
    protected function assertEscalationAllowed(string $actionType, ?User $actor): void
    {
        $stage = self::ACTION_STAGE[$actionType] ?? self::STAGE_REMINDER;

        if ($this->stageSeverity($stage) < $this->stageSeverity(self::STAGE_ESCALATION)) {
            return;
        }

        if ($actor !== null && $actor->can(self::ESCALATE_PERMISSION)) {
            return;
        }

        throw UnauthorizedException::forPermissions([self::ESCALATE_PERMISSION]);
    }

    /**
     * Tautan dokumen hasil aksi. Aksi yang melahirkan dokumen wajib menautkannya,
     * dan tautannya harus dokumen milik invoice yang sama — sejarah penagihan
     * yang menunjuk write-off orang lain lebih menyesatkan daripada tanpa tautan.
     *
     * Jenis dokumen defaults per aksi: `payment_plan` menunjuk rencana
     * pembayarannya (§7), sisanya menunjuk penyesuaian. Klien boleh mengirim
     * `reference_type` selama jenisnya ada di daftar yang dikenali.
     *
     * @param  array<string, mixed>  $data
     */
    protected function applyReference(FinanceCollectionAction $action, FinanceInvoice $invoice, array $data): void
    {
        $type = (string) $data['action_type'];
        $referenceId = $data['reference_id'] ?? null;
        $needsDocument = in_array($type, FinanceCollectionAction::DOCUMENT_TYPES, true);

        if (! $needsDocument && ! filled($referenceId)) {
            return;
        }

        if ($needsDocument && ! filled($referenceId)) {
            // Waiver/write-off yang tidak ditautkan membuat orang berikutnya
            // mengira penghapusan piutang belum pernah diajukan sama sekali.
            throw ValidationException::withMessages([
                'reference_id' => 'Aksi '.FinanceCollectionAction::TYPE_LABELS[$type].' harus menautkan dokumen yang dilahirkannya.',
            ]);
        }

        $class = (string) ($data['reference_type'] ?? $this->defaultReferenceType($type));

        if (! in_array($class, FinanceCollectionAction::REFERENCE_TYPES, true)) {
            throw ValidationException::withMessages([
                'reference_type' => 'Jenis dokumen tautan tidak dikenali: '.$class,
            ]);
        }

        $document = $class::query()->whereKey((int) $referenceId)->first();

        if ($document === null) {
            throw ValidationException::withMessages(['reference_id' => 'Dokumen '.$class.' yang ditautkan tidak ditemukan.']);
        }

        if ((int) $document->invoice_id !== (int) $invoice->id) {
            throw ValidationException::withMessages([
                'reference_id' => $document->documentNumber()." bukan milik invoice {$invoice->invoice_number}.",
            ]);
        }

        $action->reference_type = $class;
        $action->reference_id = (int) $document->id;
    }

    /** Dokumen yang lazim lahir dari sebuah aksi penagihan. */
    protected function defaultReferenceType(string $actionType): string
    {
        return $actionType === FinanceCollectionAction::TYPE_PAYMENT_PLAN
            ? FinancePaymentPlan::class
            : FinanceAdjustment::class;
    }

    /**
     * Kanal yang benar-benar tersedia di sistem: reminder, email, dan
     * pemberitahuan orang tua dikirim lewat engine notifikasi in-app yang sudah
     * ada (§30 Phase 2). Yang dicatat adalah berapa yang sungguh terkirim — bukan
     * angka optimistis "seharusnya dua". Deduplikasi per hari menjaga agar tombol
     * yang ditekan dua kali tidak membanjiri wali.
     */
    protected function deliver(FinanceInvoice $invoice, FinanceCollectionAction $action): int
    {
        if (! in_array($action->action_type, FinanceCollectionAction::NOTIFIABLE_TYPES, true) || $invoice->student_id === null) {
            return 0;
        }

        return $this->notifications->notifyStudent(
            (int) $invoice->student_id,
            FinanceActivityNotification::TYPE_COLLECTION_REMINDER,
            'Pengingat tagihan',
            sprintf(
                'Tagihan %s sebesar %s belum terlunasi%s. Silakan selesaikan melalui kasir atau portal pembayaran.',
                $invoice->invoice_number,
                number_format((float) $invoice->remaining_amount, 0, ',', '.'),
                $invoice->due_date === null ? '' : ' (jatuh tempo '.$invoice->due_date->toDateString().')',
            ),
            [
                'reference' => $invoice->invoice_number.'@'.$action->occurred_at?->toDateString(),
                'invoice_id' => (int) $invoice->id,
                'amount' => (float) $invoice->remaining_amount,
                'due_date' => $invoice->due_date?->toDateString(),
                'action_type' => $action->action_type,
            ],
        );
    }

    /**
     * Jendela laporan: `from`/`to` eksplisit; bila tidak diisi, sebulan penuh
     * berjalan sampai tanggal acuan.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: string}
     */
    protected function window(array $filters, Carbon $asOf): array
    {
        $from = filled($filters['from'] ?? null)
            ? Carbon::parse((string) $filters['from'])->toDateString()
            : $asOf->copy()->startOfMonth()->toDateString();
        $to = filled($filters['to'] ?? null)
            ? Carbon::parse((string) $filters['to'])->toDateString()
            : $asOf->toDateString();

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function asOf(array $filters): Carbon
    {
        return filled($filters['as_of'] ?? null)
            ? Carbon::parse((string) $filters['as_of'])->startOfDay()
            : Carbon::today();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function limit(array $filters): int
    {
        return max(1, min(10000, (int) ($filters['limit'] ?? 5000)));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function topLimit(array $filters): int
    {
        return max(1, min(100, (int) ($filters['top'] ?? 10)));
    }
}
