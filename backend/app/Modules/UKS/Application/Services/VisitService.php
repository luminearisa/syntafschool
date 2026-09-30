<?php

namespace App\Modules\UKS\Application\Services;

use App\Models\ClassStudent;
use App\Models\Student;
use App\Models\User;
use App\Modules\UKS\Application\Support\UksNumber;
use App\Modules\UKS\Domain\Enums\ConsentDecision;
use App\Modules\UKS\Domain\Enums\ReferralStatus;
use App\Modules\UKS\Domain\Enums\Severity;
use App\Modules\UKS\Domain\Enums\VisitStatus;
use App\Modules\UKS\Domain\Enums\VisitType;
use App\Modules\UKS\Models\UksConsent;
use App\Modules\UKS\Models\UksReferral;
use App\Modules\UKS\Models\UksVisit;
use App\Modules\UKS\Models\UksVisitMedication;
use App\Modules\UKS\Notifications\UksNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Siklus hidup kunjungan UKS: catat keluhan → tangani → obat → istirahat/
 * pulang/rujuk → selesai, plus persetujuan orang tua.
 */
class VisitService
{
    public function __construct(
        private readonly UksAccessService $access,
        private readonly MedicineStockService $stock,
        private readonly UksNotifier $notifier,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function list(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = UksVisit::query()
            ->with(['student:id,full_name,nis', 'schoolClass:id,name', 'handledBy:id,name'])
            ->withCount('medications');

        $this->access->scopeStudents($query, $actor);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (! empty($filters['visit_type'])) {
            $query->where('visit_type', $filters['visit_type']);
        }
        if (! empty($filters['student_id'])) {
            $query->where('student_id', (int) $filters['student_id']);
        }
        if (! empty($filters['date'])) {
            $query->whereDate('arrived_at', $filters['date']);
        }
        if (! empty($filters['open_only'])) {
            $query->whereNotIn('status', [VisitStatus::PULANG->value, VisitStatus::DIRUJUK->value, VisitStatus::SELESAI->value]);
        }

        return $query->orderByDesc('arrived_at')->orderByDesc('id')->paginate($perPage);
    }

    public function detail(User $actor, UksVisit $visit): UksVisit
    {
        $this->access->assertCanSeeVisit($actor, $visit);

        return $visit->load([
            'student:id,full_name,nis,nisn',
            'schoolClass:id,name',
            'reportedBy:id,name',
            'handledBy:id,name',
            'medications.medicine:id,name,unit',
            'medications.givenBy:id,name',
            'consents.guardian:id,full_name',
            'consents.decidedBy:id,name',
            'referral',
            'letters:id,visit_id,number,letter_type,verification_code,date_from,date_to',
        ]);
    }

    /**
     * @param array{student_id: int, visit_type?: string, complaint: string, symptoms?: ?string, severity?: string, arrived_at?: ?string, notes?: ?string} $data
     */
    public function create(array $data, User $actor): UksVisit
    {
        $student = Student::query()->findOrFail((int) $data['student_id']);
        $this->access->assertCanSeeStudent($actor, (int) $student->id);

        $severity = Severity::tryFrom((string) ($data['severity'] ?? Severity::RINGAN->value)) ?? Severity::RINGAN;

        $visit = DB::transaction(function () use ($data, $actor, $student, $severity): UksVisit {
            return UksVisit::query()->create([
                'code' => UksNumber::next('uks-visit', 'UKS'),
                'student_id' => $student->id,
                'school_class_id' => $this->currentClassId((int) $student->id),
                'visit_type' => VisitType::tryFrom((string) ($data['visit_type'] ?? ''))->value ?? VisitType::KELUHAN->value,
                'complaint' => $data['complaint'],
                'symptoms' => $data['symptoms'] ?? null,
                'severity' => $severity,
                'arrived_at' => $data['arrived_at'] ?? now(),
                'status' => VisitStatus::MENUNGGU,
                'notes' => $data['notes'] ?? null,
                'reported_by' => $actor->id,
            ]);
        });

        $this->notifier->visitRecorded($visit, $actor);

        if ($severity->requiresParentNotice()) {
            $this->notifier->parentAlert($visit, 'Keluhan tingkat '.$severity->label().' dicatat di UKS dan menunggu penanganan.');
        }

        return $visit->refresh();
    }

    /**
     * Penanganan oleh petugas: tindakan, anjuran, status lanjutan.
     *
     * @param array{treatment?: ?string, advice?: ?string, status?: string, needs_rest?: bool, sent_home?: bool, notes?: ?string} $data
     */
    public function handle(UksVisit $visit, array $data, User $actor): UksVisit
    {
        $this->guardOpen($visit);

        $status = isset($data['status'])
            ? (VisitStatus::tryFrom((string) $data['status']) ?? $visit->status)
            : VisitStatus::DITANGANI;

        if ($status === VisitStatus::MENUNGGU) {
            $status = VisitStatus::DITANGANI;
        }

        $visit->fill([
            'treatment' => $data['treatment'] ?? $visit->treatment,
            'advice' => $data['advice'] ?? $visit->advice,
            'needs_rest' => (bool) ($data['needs_rest'] ?? $visit->needs_rest),
            'sent_home' => (bool) ($data['sent_home'] ?? $visit->sent_home),
            'notes' => $data['notes'] ?? $visit->notes,
            'status' => $visit->sent_home || ($data['sent_home'] ?? false) ? VisitStatus::PULANG : $status,
            'handled_by' => $actor->id,
            'left_at' => in_array($status, [VisitStatus::PULANG, VisitStatus::SELESAI, VisitStatus::DIRUJUK], true) ? now() : $visit->left_at,
        ]);
        $visit->save();

        if ($visit->status === VisitStatus::PULANG) {
            $this->notifier->parentAlert($visit, 'Siswa dipulangkan dari UKS. Mohon dipantau di rumah.');
        }

        return $visit->refresh();
    }

    /**
     * Memberi obat: stok berkurang, mutasi tercatat, dan baris obat kunjungan
     * tersimpan — semuanya dalam satu transaksi.
     *
     * @param array{medicine_id: int, quantity: float|int, dosage?: ?string} $data
     */
    public function giveMedication(UksVisit $visit, array $data, User $actor): UksVisitMedication
    {
        $this->guardOpen($visit);

        return DB::transaction(function () use ($visit, $data, $actor): UksVisitMedication {
            $medicine = \App\Modules\UKS\Models\UksMedicine::query()->findOrFail((int) $data['medicine_id']);

            if (! $medicine->is_active) {
                throw ValidationException::withMessages(['medicine_id' => 'Obat ini tidak aktif.']);
            }

            $quantity = (float) $data['quantity'];
            $this->stock->consumeForVisit($medicine, $quantity, (int) $visit->id, $actor);

            $row = UksVisitMedication::query()->create([
                'visit_id' => $visit->id,
                'medicine_id' => $medicine->id,
                'medicine_name' => $medicine->name,
                'dosage' => $data['dosage'] ?? null,
                'quantity' => $quantity,
                'unit' => $medicine->unit,
                'given_by' => $actor->id,
                'given_at' => now(),
            ]);

            if ($visit->status === VisitStatus::MENUNGGU) {
                $visit->status = VisitStatus::DITANGANI;
                $visit->handled_by = $actor->id;
                $visit->save();
            }

            return $row;
        });
    }

    /**
     * @param array{facility_name: string, facility_type?: ?string, reason: string} $data
     */
    public function refer(UksVisit $visit, array $data, User $actor): UksReferral
    {
        if ($visit->referral()->exists()) {
            throw ValidationException::withMessages(['visit' => 'Kunjungan ini sudah memiliki rujukan.']);
        }

        return DB::transaction(function () use ($visit, $data, $actor): UksReferral {
            $referral = UksReferral::query()->create([
                'visit_id' => $visit->id,
                'facility_name' => $data['facility_name'],
                'facility_type' => $data['facility_type'] ?? null,
                'reason' => $data['reason'],
                'status' => ReferralStatus::DIAJUKAN,
                'referred_at' => now(),
                'referred_by' => $actor->id,
            ]);

            $visit->status = VisitStatus::DIRUJUK;
            $visit->handled_by = $visit->handled_by ?? $actor->id;
            $visit->left_at = $visit->left_at ?? now();
            $visit->save();

            $this->notifier->parentAlert(
                $visit,
                'Siswa dirujuk ke '.$data['facility_name'].'. Alasan: '.$data['reason'],
            );

            return $referral;
        });
    }

    /** @param array{status?: string, outcome?: ?string, returned_at?: ?string} $data */
    public function updateReferral(UksReferral $referral, array $data): UksReferral
    {
        if (isset($data['status']) && ReferralStatus::tryFrom((string) $data['status']) instanceof ReferralStatus) {
            $referral->status = ReferralStatus::from((string) $data['status']);
        }
        if (array_key_exists('outcome', $data)) {
            $referral->outcome = $data['outcome'];
        }
        if (! empty($data['returned_at'])) {
            $referral->returned_at = $data['returned_at'];
        }
        $referral->save();

        return $referral;
    }

    /**
     * Persetujuan orang tua: dari aplikasi (orang tua sendiri) atau dicatat
     * petugas hasil telepon/tatap muka.
     *
     * @param array{decision: string, note?: ?string, channel?: string, guardian_id?: ?int} $data
     */
    public function consent(UksVisit $visit, array $data, User $actor): UksConsent
    {
        $this->access->assertCanConsent($actor, $visit);

        $decision = ConsentDecision::tryFrom((string) $data['decision']);
        if ($decision === null) {
            throw ValidationException::withMessages(['decision' => 'Keputusan harus DISETUJUI atau DITOLAK.']);
        }

        $context = $this->access->context($actor);

        $consent = UksConsent::query()->create([
            'visit_id' => $visit->id,
            'guardian_id' => $context->guardian?->id ?? ($data['guardian_id'] ?? null),
            'decision' => $decision,
            'note' => $data['note'] ?? null,
            'channel' => $data['channel'] ?? ($context->isGuardian() ? 'APLIKASI' : 'TELEPON'),
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]);

        $this->notifier->consentRecorded($visit, $consent, $actor);

        return $consent;
    }

    public function close(UksVisit $visit, User $actor): UksVisit
    {
        if ($visit->status === VisitStatus::SELESAI) {
            return $visit;
        }

        $visit->status = VisitStatus::SELESAI;
        $visit->left_at = $visit->left_at ?? now();
        $visit->handled_by = $visit->handled_by ?? $actor->id;
        $visit->save();

        return $visit;
    }

    /** Tandai orang tua sudah dihubungi (mis. lewat telepon). */
    public function markParentNotified(UksVisit $visit, User $actor): UksVisit
    {
        $visit->parent_notified = true;
        $visit->parent_notified_at = $visit->parent_notified_at ?? now();
        $visit->save();

        activity('uks')->causedBy($actor)->performedOn($visit)
            ->log('Orang tua ditandai sudah diberitahu untuk kunjungan '.$visit->code);

        return $visit;
    }

    private function guardOpen(UksVisit $visit): void
    {
        if (! $visit->status->isOpen()) {
            throw ValidationException::withMessages([
                'visit' => 'Kunjungan '.$visit->code.' sudah '.$visit->status->label().' dan tidak dapat diubah.',
            ]);
        }
    }

    private function currentClassId(int $studentId): ?int
    {
        $activeYearIds = DB::table('academic_years')->where('is_active', true)->pluck('id');

        return ClassStudent::query()
            ->where('student_id', $studentId)
            ->where('status', 'aktif')
            ->when($activeYearIds->isNotEmpty(), fn ($q) => $q->whereIn('academic_year_id', $activeYearIds))
            ->orderByDesc('id')
            ->value('school_class_id');
    }
}
