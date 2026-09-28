<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Wallet\WalletBlockRequest;
use App\Http\Requests\Wallet\WalletStoreRequest;
use App\Http\Requests\Wallet\WalletUpdateRequest;
use App\Http\Resources\WalletResource;
use App\Models\Student;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Wallet untuk petugas/admin (§4B, §5, §21, §22).
 *
 * Kelas ini tipis: setiap pembacaan dan penulisan dititipkan ke `WalletService`.
 * Yang dikerjakan di sini hanya memformalkan HTTP — memvalidasi lewat FormRequest,
 * memayungi aturan kepemilikan tepat-satu-pemilik, dan membungkus hasil menjadi
 * `JsonResponse`.
 *
 * Otorisasi cukup di rute (`permission:wallet.*`): yang membedakan dua petugas
 * adalah haknya, bukan wallet mana yang ia sentuh — wallet sekolah boleh dilihat
 * siapa pun yang memegang `wallet.view` (§1, §2). Lihat `WalletPortalController`
 * untuk sisi pemilik saldo, yang justru tidak memakai permission sama sekali.
 *
 * Saldo tidak pernah ditulis dari sini: satu-satunya jalan adalah ledger
 * (`WalletLedgerService`), dan endpoint ini hanya membaca akibatnya (§8).
 */
class WalletController extends BaseApiController
{
    public function __construct(protected WalletService $wallets)
    {
    }

    /**
     * GET /wallet/wallets — daftar + pencarian (§5).
     *
     * `search` menutup nomor rekening, nama pemilik, NIS, NIP, ID pemilik
     * (termasuk parent id), dan UID kartu RFID.
     */
    /** Pilihan santri minimum untuk membuka wallet tanpa membuka endpoint data siswa umum. */
    public function studentOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) ($data['search'] ?? ''));

        $students = Student::query()
            ->where('is_active', true)
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('full_name', 'like', "%{$term}%")
                ->orWhere('nis', 'like', "%{$term}%")))
            ->orderBy('full_name')
            ->limit(200)
            ->get(['id', 'nis', 'full_name']);

        return $this->successResponse($students, 'Pilihan santri berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $wallets = $this->paginate($request, $this->wallets->query($request));

        return $this->successResponse(
            WalletResource::collection($wallets)->additional([
                'meta' => [
                    'total' => $wallets->total(),
                    'owner_types' => $this->ownerTypeOptions(),
                    'statuses' => Wallet::STATUSES,
                ],
            ]),
            'Daftar wallet berhasil diambil',
        );
    }

    /**
     * POST /wallet/wallets — buat manual oleh petugas (§4B).
     *
     * Nomor rekening terbit otomatis, saldo lahir 0, status aktif.
     */
    public function store(WalletStoreRequest $request): JsonResponse
    {
        $wallet = $this->wallets->create($request->validated(), $request->user());

        return $this->createdResponse(
            new WalletResource($this->wallets->withRelations($wallet)),
            'Wallet berhasil dibuat',
        );
    }

    /** GET /wallet/wallets/{wallet} — detail + ringkasan saldo dan kartu (§22). */
    public function show(Wallet $wallet): JsonResponse
    {
        return $this->successResponse(
            new WalletResource($this->wallets->withRelations($wallet)),
            'Detail wallet berhasil diambil',
        );
    }

    /** PATCH /wallet/wallets/{wallet} — hanya catatan internal (§4B). */
    public function update(WalletUpdateRequest $request, Wallet $wallet): JsonResponse
    {
        $wallet = $this->wallets->update($wallet, $request->validated(), $request->user());

        return $this->successResponse(new WalletResource($wallet), 'Wallet berhasil diperbarui');
    }

    /** POST /wallet/wallets/{wallet}/block — alasan wajib (§21). */
    public function block(WalletBlockRequest $request, Wallet $wallet): JsonResponse
    {
        $wallet = $this->wallets->block($wallet, $request->validated(), $request->user());

        return $this->successResponse(new WalletResource($wallet), 'Wallet berhasil diblokir');
    }

    /** POST /wallet/wallets/{wallet}/unblock — kembali aktif (§21). */
    public function unblock(WalletBlockRequest $request, Wallet $wallet): JsonResponse
    {
        $wallet = $this->wallets->unblock($wallet, $request->validated(), $request->user());

        return $this->successResponse(new WalletResource($wallet), 'Wallet berhasil diaktifkan kembali');
    }

    /** POST /wallet/wallets/{wallet}/close — penutupan permanen, alasan wajib (§2). */
    public function close(WalletBlockRequest $request, Wallet $wallet): JsonResponse
    {
        $wallet = $this->wallets->close($wallet, $request->validated(), $request->user());

        return $this->successResponse(new WalletResource($wallet), 'Wallet berhasil ditutup');
    }

    /**
     * GET /wallet/wallets/{wallet}/ledger — buku besar, hanya dibaca (§9).
     *
     * Permission tersendiri (`wallet.ledger.view`): melihat rantai pembukuan
     * orang lain bukan bagian dari sekadar melihat daftar wallet.
     */
    public function ledger(Request $request, Wallet $wallet): JsonResponse
    {
        $transactions = $this->wallets->ledger($wallet, $request, staff: true);

        return $this->successResponse(
            $transactions,
            'Buku besar wallet berhasil diambil',
        );
    }

    // =====================================================================
    // HELPER
    // =====================================================================

    /** @param  Builder<Wallet>  $query */
    private function paginate(Request $request, Builder $query): LengthAwarePaginator
    {
        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $query->paginate($perPage)->appends($request->query());
    }

    private function collection(LengthAwarePaginator $wallets): ResourceCollection
    {
        return WalletResource::collection($wallets);
    }

    private function total(LengthAwarePaginator $wallets): int
    {
        return $wallets->total();
    }

    /**
     * Pilihan tipe pemilik untuk penyaring daftar.
     *
     * @return list<array{value: string, label: string}>
     */
    private function ownerTypeOptions(): array
    {
        return array_map(
            static fn (string $type): array => [
                'value' => $type,
                'label' => WalletService::ownerTypeLabel($type),
            ],
            Wallet::OWNER_TYPES,
        );
    }
}
