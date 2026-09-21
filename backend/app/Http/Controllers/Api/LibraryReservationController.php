<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryReservationRequest;
use App\Http\Resources\LibraryReservationResource;
use App\Models\LibraryBibliography;
use App\Models\LibraryItem;
use App\Models\LibraryMember;
use App\Models\LibraryReservation;
use App\Services\Library\Circulation\CirculationService;
use App\Services\Library\Circulation\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reservasi judul.
 *
 * Pengambilan reservasi menghasilkan peminjaman, sehingga controller ini
 * memanggil ReservationService (tandai diambil) dan CirculationService
 * (checkout) secara berurutan — keduanya tidak saling bergantung.
 */
class LibraryReservationController extends BaseApiController
{
    public function __construct(
        protected ReservationService $reservations,
        protected CirculationService $circulation,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->reservations->query($request);

        if ($request->boolean('all')) {
            return $this->successResponse(
                LibraryReservationResource::collection($query->get()),
                'Daftar reservasi berhasil diambil'
            );
        }

        return $this->successResponse(
            LibraryReservationResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar reservasi berhasil diambil'
        );
    }

    public function store(LibraryReservationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $member = $this->resolveMember($validated, $request);
        $bibliography = LibraryBibliography::query()->findOrFail($validated['bibliography_id']);

        $reservation = $this->reservations->create($member, $bibliography, $validated);

        return $this->createdResponse(
            new LibraryReservationResource($reservation->load(['member', 'bibliography'])),
            'Reservasi berhasil dibuat'
        );
    }

    public function show(LibraryReservation $reservation): JsonResponse
    {
        return $this->successResponse(
            new LibraryReservationResource($reservation->load(['member', 'bibliography', 'item'])),
            'Detail reservasi berhasil diambil'
        );
    }

    /**
     * Ambil reservasi: tandai diambil lalu checkout eksemplar yang ditahan.
     */
    public function pickup(Request $request, LibraryReservation $reservation): JsonResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);
        $userId = $request->user()?->id;

        $reservation = $this->reservations->markPickedUp($reservation, ['handled_by' => $userId]);

        /** @var LibraryItem|null $item */
        $item = $reservation->item_id !== null ? LibraryItem::query()->find($reservation->item_id) : null;

        if ($item === null) {
            throw ValidationException::withMessages([
                'reservation' => 'Eksemplar yang ditahan tidak ditemukan.',
            ]);
        }

        $member = LibraryMember::query()->with('membershipType')->findOrFail($reservation->member_id);

        // Eksemplar ditahan berstatus `reserved` — lepaskan agar dapat dipinjam.
        $item->update(['status' => 'available']);

        $loan = $this->circulation->checkout($member, $item, [
            'borrowed_by' => $userId,
            'notes' => $validated['notes'] ?? 'Pengambilan reservasi '.$reservation->reservation_code,
        ]);

        $reservation = $this->reservations->complete($reservation);

        return $this->successResponse([
            'reservation' => new LibraryReservationResource($reservation->load(['member', 'bibliography', 'item'])),
            'loan' => new \App\Http\Resources\LibraryLoanResource($loan->load(['member', 'item', 'bibliography'])),
        ], 'Reservasi diambil dan dipinjamkan');
    }

    public function cancel(Request $request, LibraryReservation $reservation): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $reservation = $this->reservations->cancel($reservation, $validated['reason']);

        return $this->successResponse(
            new LibraryReservationResource($reservation->load(['member', 'bibliography'])),
            'Reservasi dibatalkan'
        );
    }

    /**
     * Tandai reservasi yang tidak diambil sebagai kedaluwarsa.
     */
    public function expire(Request $request): JsonResponse
    {
        $libraryId = $request->filled('library_id') ? (int) $request->query('library_id') : null;

        $count = $this->reservations->expireLapsed($libraryId);

        return $this->successResponse(['expired' => $count], "{$count} reservasi ditandai kedaluwarsa");
    }

    public function summary(Request $request): JsonResponse
    {
        $libraryId = $request->filled('library_id') ? (int) $request->query('library_id') : null;

        return $this->successResponse($this->reservations->summary($libraryId), 'Ringkasan reservasi berhasil diambil');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function resolveMember(array $validated, Request $request): LibraryMember
    {
        // Anggota boleh mengajukan reservasi untuk dirinya sendiri.
        $member = ! empty($validated['member_id'])
            ? LibraryMember::query()->findOrFail($validated['member_id'])
            : LibraryMember::query()->where('user_id', $request->user()?->id)->first();

        if ($member === null) {
            throw ValidationException::withMessages([
                'member_id' => 'Anggota tidak ditemukan atau Anda belum menjadi anggota perpustakaan.',
            ]);
        }

        return $member->load('membershipType');
    }
}