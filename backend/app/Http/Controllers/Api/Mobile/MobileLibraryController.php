<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\LibraryFine;
use App\Models\LibraryLoan;
use App\Models\LibraryMember;
use App\Models\LibraryReservation;
use App\Services\Library\Circulation\CirculationService;
use App\Services\Library\Circulation\ReservationService;
use App\Services\Library\Opac\OpacService;
use App\Services\Mobile\ContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perpustakaan untuk anggota (siswa / pegawai): kartu, pinjaman, reservasi, denda.
 *
 * Endpoint perpustakaan yang ada berbasis petugas (`library.circulation.*`);
 * anggota hanya punya "Kartu Saya". Di sini anggota ditentukan dari peran aktif
 * (siswa → `student_id`, pegawai → `employee_id`, atau tautan `user_id`) dan
 * seluruh aturan bisnis (kelayakan, batas perpanjangan, antrean) tetap diputuskan
 * ReservationService / CirculationService yang sama dengan meja sirkulasi.
 */
class MobileLibraryController extends BaseApiController
{
    public function __construct(
        private readonly ContextService $contexts,
        private readonly ReservationService $reservations,
        private readonly CirculationService $circulation,
        private readonly OpacService $opac,
    ) {}

    public function me(Request $request): JsonResponse
    {
        $member = $this->member($request);

        if ($member === null) {
            return $this->successResponse(['is_member' => false], 'Anda belum menjadi anggota perpustakaan.');
        }

        $member->loadMissing('membershipType:id,name');

        $loans = LibraryLoan::query()->with('bibliography:id,title')->withCount('renewals')
            ->where('member_id', $member->id)->open()->orderBy('due_at')->get();

        $recent = LibraryLoan::query()->with('bibliography:id,title')
            ->where('member_id', $member->id)->where('status', LibraryLoan::STATUS_RETURNED)
            ->orderByDesc('returned_on')->limit(10)->get();

        $fines = LibraryFine::query()->where('member_id', $member->id)->whereIn('status', ['unpaid', 'partial'])->get();

        return $this->successResponse([
            'is_member' => true,
            'member' => [
                'member_number' => $member->member_number,
                'name' => $member->name,
                'status' => $member->status,
                'type' => $member->membershipType?->name,
                'valid_until' => $member->valid_until?->toDateString(),
            ],
            'loans' => $loans->map(fn (LibraryLoan $l) => [
                'id' => $l->id,
                'title' => $l->bibliography?->title,
                'borrowed_at' => $l->borrowed_at?->toDateString(),
                'due_at' => $l->due_at?->toDateString(),
                'is_overdue' => $l->isOverdue(),
                'overdue_days' => $l->overdueDays(),
                'renewals' => (int) $l->renewals_count,
            ])->values(),
            'history' => $recent->map(fn (LibraryLoan $l) => [
                'id' => $l->id,
                'title' => $l->bibliography?->title,
                'returned_on' => $l->returned_on?->toDateString(),
            ])->values(),
            'reservations' => LibraryReservation::query()->with('bibliography:id,title')
                ->where('member_id', $member->id)->open()->orderBy('reserved_at')->get()
                ->map(fn (LibraryReservation $r) => [
                    'id' => $r->id,
                    'code' => $r->reservation_code,
                    'title' => $r->bibliography?->title,
                    'status' => $r->status,
                    'queue_position' => $r->queue_position,
                    'expires_at' => $r->expires_at?->toDateString(),
                ])->values(),
            'fines' => [
                'outstanding' => round((float) $fines->sum(fn (LibraryFine $f) => max(0, (float) $f->amount - (float) $f->paid_amount - (float) $f->waived_amount)), 2),
                'count' => $fines->count(),
            ],
        ], 'Perpustakaan saya berhasil diambil');
    }

    public function reserve(Request $request): JsonResponse
    {
        $member = $this->member($request);
        abort_if($member === null, 403, 'Anda belum menjadi anggota perpustakaan.');

        $data = $request->validate(['slug' => ['required', 'string', 'max:255']]);

        // Katalog publik hanya mengenal slug (bukan id) — selesaikan di server.
        $bibliography = $this->opac->findBySlug($data['slug']);
        abort_if($bibliography === null, 404, 'Koleksi tidak ditemukan.');

        $reservation = $this->reservations->create($member->load('membershipType'), $bibliography, []);

        return $this->createdResponse(['code' => $reservation->reservation_code, 'queue_position' => $reservation->queue_position], 'Reservasi berhasil dibuat');
    }

    public function cancelReservation(Request $request, LibraryReservation $reservation): JsonResponse
    {
        $member = $this->member($request);
        abort_unless($member !== null && (int) $reservation->member_id === (int) $member->id, 404, 'Reservasi tidak ditemukan.');

        $this->reservations->cancel($reservation, 'Dibatalkan oleh anggota (aplikasi)');

        return $this->successResponse(null, 'Reservasi dibatalkan');
    }

    public function renew(Request $request, LibraryLoan $loan): JsonResponse
    {
        $member = $this->member($request);
        abort_unless($member !== null && (int) $loan->member_id === (int) $member->id, 404, 'Pinjaman tidak ditemukan.');

        $renewed = $this->circulation->renew($loan, ['renewed_by' => $request->user()->id, 'notes' => 'Diperpanjang lewat aplikasi']);

        return $this->successResponse(['due_at' => $renewed->due_at?->toDateString()], 'Pinjaman berhasil diperpanjang');
    }

    /** Anggota akun ini pada peran aktif (satu orang → satu keanggotaan aktif). */
    private function member(Request $request): ?LibraryMember
    {
        $user = $request->user();
        $type = $request->attributes->get('mobile_context');

        $query = LibraryMember::query();

        $query->where(function ($q) use ($user, $type): void {
            $q->where('user_id', $user->getKey());

            if ($type === ContextService::STUDENT && ($id = $this->contexts->studentId($user)) !== null) {
                $q->orWhere('student_id', $id);
            }

            if ($type === ContextService::EMPLOYEE && ($id = $this->contexts->employeeId($user)) !== null) {
                $q->orWhere('employee_id', $id);
            }
        });

        return $query->active()->latest('id')->first();
    }
}
