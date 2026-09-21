<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LibraryMemberCardResource;
use App\Models\LibraryMember;
use App\Models\LibraryMemberCard;
use App\Services\Library\Membership\MemberCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kartu anggota: penerbitan, cetak ulang, pencabutan, dan batch print.
 *
 * Nomor kartu dibangkitkan dari konfigurasi penomoran perpustakaan dan token
 * QR dibuat server-side untuk sirkulasi (Fase 4).
 */
class LibraryMemberCardController extends BaseApiController
{
    public function __construct(protected MemberCardService $cards)
    {
    }

    /**
     * Kartu aktif milik seorang anggota.
     */
    public function show(LibraryMember $member): JsonResponse
    {
        $card = $this->cards->activeCard($member);

        return $this->successResponse(
            $card !== null ? new LibraryMemberCardResource($card->load('member.library', 'member.membershipType')) : null,
            $card !== null ? 'Kartu anggota berhasil diambil' : 'Anggota belum memiliki kartu aktif'
        );
    }

    /**
     * Terbitkan kartu (kartu aktif lama otomatis dicabut).
     */
    public function generate(Request $request, LibraryMember $member): JsonResponse
    {
        $validated = $request->validate([
            'card_number' => ['nullable', 'string', 'max:50', 'unique:library_member_cards,card_number'],
            'barcode' => ['nullable', 'string', 'max:50', 'unique:library_member_cards,barcode'],
            'issued_at' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $card = $this->cards->generate($member, $validated);

        return $this->createdResponse(
            new LibraryMemberCardResource($card->load('member.library', 'member.membershipType')),
            'Kartu anggota berhasil diterbitkan'
        );
    }

    /**
     * Terbitkan kartu untuk banyak anggota sekaligus (§41 & §56).
     */
    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'exists:library_members,id'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $result = $this->cards->batchGenerate($validated['member_ids'], $validated);

        return $this->createdResponse(
            $result,
            "{$result['generated']} kartu anggota berhasil diterbitkan"
        );
    }

    /**
     * Catat pencetakan kartu (print / batch print).
     */
    public function markPrinted(LibraryMemberCard $card): JsonResponse
    {
        $card = $this->cards->markPrinted($card);

        return $this->successResponse(
            new LibraryMemberCardResource($card->load('member.library')),
            'Pencetakan kartu tercatat'
        );
    }

    public function revoke(Request $request, LibraryMemberCard $card): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $card = $this->cards->revoke($card, $validated['reason']);

        return $this->successResponse(
            new LibraryMemberCardResource($card->load('member.library')),
            'Kartu anggota berhasil dicabut'
        );
    }

    /**
     * "Kartu Saya" — kartu milik pengguna yang sedang login (§40).
     *
     * Tidak memerlukan permission modul: setiap pengguna hanya melihat
     * kartunya sendiri.
     */
    public function mine(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;

        if ($userId === null) {
            throw ValidationException::withMessages(['user' => 'Pengguna tidak dikenali.']);
        }

        $card = $this->cards->cardForUser($userId);

        return $this->successResponse(
            $card !== null ? new LibraryMemberCardResource($card->load('member.library', 'member.membershipType')) : null,
            $card !== null ? 'Kartu perpustakaan Anda berhasil diambil' : 'Anda belum menjadi anggota perpustakaan'
        );
    }
}