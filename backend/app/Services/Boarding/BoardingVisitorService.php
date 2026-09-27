<?php

namespace App\Services\Boarding;

use App\Models\BoardingPlacement;
use App\Models\BoardingVisitor;
use App\Models\Student;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Kunjungan ke asrama (§40): tamu dicatat `waiting` saat tiba di gerbang,
 * `visiting` setelah masuk area, `finished` setelah pulang, dan `cancelled`
 * bila batal.
 *
 * ## Keputusan
 *
 * 1. **Kunjungan adalah transaksi, bukan master**: identitas tamu disimpan teks
 *    (`visitor_name`, `identity_number`) dan tidak dibuat tabel tamu baru; satu
 *    baris = satu kali datang.
 * 2. **`visit_date` tidak boleh di masa lalu** saat dicatat. Kunjungan mundur
 *    mengubah daftar "siapa yang ada di dalam" (§42) menjadi tebak-tebakan;
 *    kalau tamu memang datang kemarin, petugas mencatatnya lewat absensi/status,
 *    bukan memalsukan tanggal.
 * 3. **Check out menuntut `visiting`** (§40): memulangkan tamu yang belum pernah
 *    masuk hanya menutupi baris `waiting` yang terlupa — dan baris seperti inilah
 *    yang bikin dashboard gerbang menyesatkan.
 * 4. **Santri yang dikunjungi harus penghuni asrama** (penempatan aktif), dan
 *    pengasuh hanya boleh mencatat kunjungan ke santri binaannya (§54).
 *
 * Tidak ada notifikasi orang tua di sini: antrean `waiting` sudah tampil di
 * layar gerbang/pengasuh, dan §40 tidak meminta pesan terpisah. Semua tulis
 * di dalam `DB::transaction`; penolakan bisnis lewat
 * `ValidationException::withMessages` (422 berkoncal).
 */
class BoardingVisitorService
{
    public function __construct(private readonly BoardingScopeService $scopes)
    {
    }

    // =====================================================================
    // Baca
    // =====================================================================

    /**
     * Daftar kunjungan (§40): filter status, santri, tanggal, dan `present=1`
     * untuk "siapa yang masih ada di dalam area".
     */
    public function query(Request $request, ?User $actor = null): Builder
    {
        $query = BoardingVisitor::query()
            ->with(['student:id,full_name,nis,nisn'])
            ->orderByDesc('visit_date')
            ->orderByDesc('id');

        $allowedIds = $this->scopes->studentIdsFor($actor);

        if ($allowedIds !== null) {
            $query->whereIn('student_id', $allowedIds);
        }

        return $query
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->forStudent($request->filled('student_id') ? (int) $request->input('student_id') : null)
            ->onDate($request->filled('visit_date') ? $request->date('visit_date')->toDateString() : null)
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('visit_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('visit_date', '<=', $request->date('to')))
            ->when($request->boolean('present'), fn (Builder $q) => $q->present())
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('visitor_name', 'like', "%$term%")
                        ->orWhere('identity_number', 'like', "%$term%")
                        ->orWhereHas('student', fn (Builder $s) => $s
                            ->where('full_name', 'like', "%$term%")
                            ->orWhere('nis', 'like', "%$term%"));
                });
            });
    }

    public function withDetails(BoardingVisitor $visitor): BoardingVisitor
    {
        return $visitor->load([
            'student:id,full_name,nis,nisn',
            'approvedBy:id,name',
            'recordedBy:id,name',
        ]);
    }

    public function assertAccessible(BoardingVisitor $visitor, ?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        $this->scopes->assertCanAccessStudent($actor, (int) $visitor->student_id);
    }

    // =====================================================================
    // Tulis
    // =====================================================================

    /**
     * Catat tamu yang datang (§40). Barisnya lahir sebagai `waiting`.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): BoardingVisitor
    {
        $student = $this->assertStudentPlaced($data['student_id'] ?? null, $actor);

        $visitDate = $this->date($data['visit_date'] ?? now()->toDateString(), 'visit_date');

        if ($visitDate->lessThan(Carbon::now()->startOfDay())) {
            throw ValidationException::withMessages([
                'visit_date' => 'Tanggal kunjungan tidak boleh di masa lalu. Kunjungan dicatat saat tamu tiba di gerbang (§40).',
            ]);
        }

        /** @var BoardingVisitor $visitor */
        $visitor = DB::transaction(function () use ($data, $student, $visitDate, $actor): BoardingVisitor {
            $visitor = BoardingVisitor::create([
                'visitor_name' => trim((string) ($data['visitor_name'] ?? '')),
                'identity_number' => $this->nullableText($data['identity_number'] ?? null, 50),
                'phone' => $this->nullableText($data['phone'] ?? null, 30),
                'relationship' => $this->nullableText($data['relationship'] ?? null, 50),
                'student_id' => $student->id,
                'visit_date' => $visitDate,
                'purpose' => $this->nullableText($data['purpose'] ?? null, 255),
                'status' => BoardingVisitor::STATUS_WAITING,
                'recorded_by' => $actor?->id,
                'notes' => $this->nullableText($data['notes'] ?? null, 1000),
            ]);

            $this->audit($visitor, 'BOARDING_VISITOR_CREATED', 'Kunjungan dicatat', [
                'student_id' => (int) $visitor->student_id,
                'visitor_name' => (string) $visitor->visitor_name,
            ]);

            return $visitor;
        });

        return $this->withDetails($visitor);
    }

    /**
     * Tamu dipersilakan masuk: `waiting` → `visiting` + `check_in_at` (§40).
     */
    public function checkIn(BoardingVisitor $visitor, ?User $actor = null, mixed $at = null): BoardingVisitor
    {
        $this->assertAccessible($visitor, $actor);

        $moment = $at === null ? Carbon::now() : $this->moment($at, 'check_in_at');

        /** @var BoardingVisitor $visiting */
        $visiting = DB::transaction(function () use ($visitor, $actor, $moment): BoardingVisitor {
            $locked = $this->lock((int) $visitor->id);

            if ($locked->status !== BoardingVisitor::STATUS_WAITING) {
                throw ValidationException::withMessages([
                    'status' => 'Check in hanya berlaku untuk tamu yang masih menunggu (status saat ini: '
                        .$this->statusNow($locked).') (§40).',
                ]);
            }

            $locked->forceFill([
                'status' => BoardingVisitor::STATUS_VISITING,
                'check_in_at' => $moment,
                // Petugas yang mempersilakan masuk = penanggung jawab kunjungan.
                'approved_by' => $actor?->id,
            ])->save();

            $this->audit($locked, 'BOARDING_VISITOR_CHECKED_IN', 'Tamu masuk area kunjungan', [
                'check_in_at' => $moment->toIso8601String(),
            ]);

            return $locked;
        });

        return $this->withDetails($visiting);
    }

    /**
     * Tamu pulang: `visiting` → `finished` + `check_out_at` (§40).
     *
     * Ditolak bila statusnya belum `visiting` — menunggu yang belum pernah masuk
     * tidak bisa "pulang", dan selesai ganda akan mencatatkan durasi palsu.
     */
    public function checkOut(BoardingVisitor $visitor, ?User $actor = null, mixed $at = null, ?string $note = null): BoardingVisitor
    {
        $this->assertAccessible($visitor, $actor);

        $moment = $at === null ? Carbon::now() : $this->moment($at, 'check_out_at');

        /** @var BoardingVisitor $finished */
        $finished = DB::transaction(function () use ($visitor, $actor, $moment, $note): BoardingVisitor {
            $locked = $this->lock((int) $visitor->id);

            if ($locked->status !== BoardingVisitor::STATUS_VISITING) {
                throw ValidationException::withMessages([
                    'status' => 'Tamu hanya dapat di-check out setelah masuk area kunjungan (status saat ini: '
                        .$this->statusNow($locked).') (§40).',
                ]);
            }

            $locked->forceFill([
                'status' => BoardingVisitor::STATUS_FINISHED,
                'check_out_at' => $moment,
                'notes' => $this->appendNote((string) $locked->notes, $note),
            ])->save();

            $this->audit($locked, 'BOARDING_VISITOR_CHECKED_OUT', 'Tamu pulang dari kunjungan', [
                'check_out_at' => $moment->toIso8601String(),
                'duration_minutes' => $locked->check_in_at === null
                    ? null
                    : (int) round(($moment->getTimestamp() - $locked->check_in_at->getTimestamp()) / 60),
            ]);

            return $locked;
        });

        return $this->withDetails($finished);
    }

    /**
     * Batal sebelum tamu pulang (§40): `waiting`/`visiting` → `cancelled`.
     */
    public function cancel(BoardingVisitor $visitor, ?User $actor = null, ?string $note = null): BoardingVisitor
    {
        $this->assertAccessible($visitor, $actor);

        /** @var BoardingVisitor $cancelled */
        $cancelled = DB::transaction(function () use ($visitor, $actor, $note): BoardingVisitor {
            $locked = $this->lock((int) $visitor->id);

            if (! in_array($locked->status, [
                BoardingVisitor::STATUS_WAITING,
                BoardingVisitor::STATUS_VISITING,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Kunjungan yang sudah '.$this->statusNow($locked).' tidak dapat dibatalkan (§40).',
                ]);
            }

            $locked->forceFill([
                'status' => BoardingVisitor::STATUS_CANCELLED,
                'notes' => $this->appendNote((string) $locked->notes, $note),
            ])->save();

            $this->audit($locked, 'BOARDING_VISITOR_CANCELLED', 'Kunjungan dibatalkan', [
                'cancelled_by' => $actor?->id,
            ]);

            return $locked;
        });

        return $this->withDetails($cancelled);
    }

    // =====================================================================
    // Penjaga keadaan
    // =====================================================================

    private function assertStudentPlaced(mixed $studentId, ?User $actor): Student
    {
        $id = (int) $studentId;

        if ($id <= 0) {
            throw ValidationException::withMessages(['student_id' => 'Santri yang dikunjungi wajib dipilih.']);
        }

        $student = Student::query()->whereKey($id)->first();

        if ($student === null) {
            throw ValidationException::withMessages(['student_id' => 'Santri tidak ditemukan.']);
        }

        if ($actor !== null) {
            $this->scopes->assertCanAccessStudent($actor, $id);
        }

        $placed = BoardingPlacement::query()
            ->where('student_id', $id)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->exists();

        if (! $placed) {
            throw ValidationException::withMessages([
                'student_id' => 'Santri ini tidak tinggal di asrama (tidak ada penempatan aktif), sehingga kunjungan tidak dapat dicatat di modul boarding (§40).',
            ]);
        }

        return $student;
    }

    private function lock(int $id): BoardingVisitor
    {
        /** @var BoardingVisitor $visitor */
        $visitor = BoardingVisitor::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $visitor;
    }

    private function statusNow(BoardingVisitor $visitor): string
    {
        return BoardingVisitor::STATUS_LABELS[(string) $visitor->status] ?? (string) $visitor->status;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(BoardingVisitor $visitor, string $event, string $description, array $properties = []): void
    {
        activity('boarding')
            ->event($event)
            ->performedOn($visitor)
            ->withProperties($properties + ['status' => (string) $visitor->status])
            ->log($description);
    }

    // =====================================================================
    // Utilitas
    // =====================================================================

    public function date(mixed $value, string $field): Carbon
    {
        return $this->moment($value, $field)->startOfDay();
    }

    public function moment(mixed $value, string $field): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        $value = trim((string) $value);

        if ($value === '') {
            throw ValidationException::withMessages([$field => 'Tanggal/waktu wajib diisi.']);
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => 'Format waktu "'.Str::limit($value, 40).' " tidak dikenali. Gunakan contoh: 2026-09-25 15:30.',
            ]);
        }
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }

    private function appendNote(string $existing, ?string $extra): ?string
    {
        $extra = trim((string) $extra);

        if ($extra === '') {
            return $this->nullableText($existing, 1000);
        }

        $line = '['.Carbon::now()->format('d/m/Y H:i').'] '.$extra;

        return Str::limit($existing === '' ? $line : $existing.' | '.$line, 1000, '');
    }
}
