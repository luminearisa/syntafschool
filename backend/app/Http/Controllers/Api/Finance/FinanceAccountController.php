<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceAccountRequest;
use App\Http\Resources\FinanceAccountResource;
use App\Models\FinanceAccount;
use App\Models\FinancialTransactionLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Chart of Accounts (§5, §6). Kelas tipis: aturan di model + guard di rute.
 *
 * Akun `is_system` (hasil seed) tidak boleh dihapus agar buku besar tetap utuh;
 * akun yang sudah pernah dipakai jurnal juga dikunci — menghapusnya akan
 * membuat baris `finance_transaction_lines` menggantung.
 */
class FinanceAccountController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = FinanceAccount::query()
            ->withCount('children')
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%"));
            })
            ->orderBy('code');

        if ($request->boolean('all')) {
            return $this->successResponse(
                FinanceAccountResource::collection($query->get()),
                'Daftar akun berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 50)));

        return $this->successResponse(
            FinanceAccountResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['types' => FinanceAccount::TYPES, 'type_labels' => FinanceAccount::TYPE_LABELS]]),
            'Daftar akun berhasil diambil',
        );
    }

    /** COA sebagai pohon (induk → anak) untuk tampilan hierarki. */
    public function tree(Request $request): JsonResponse
    {
        $roots = FinanceAccount::query()
            ->with('children.children')
            ->whereNull('parent_id')
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->orderBy('code')
            ->get();

        return $this->successResponse(
            FinanceAccountResource::collection($roots),
            'Struktur COA berhasil diambil',
        );
    }

    public function show(FinanceAccount $account): JsonResponse
    {
        $account->load(['parent', 'children'])->loadCount('children');

        return $this->successResponse(new FinanceAccountResource($account), 'Detail akun berhasil diambil');
    }

    public function store(FinanceAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->assertParentValid($data['parent_id'] ?? null, null, $data['type'] ?? null);

        $account = FinanceAccount::create([
            ...$data,
            'is_system' => false, // akun buatan pengguna bukan akun sistem
        ]);

        return $this->createdResponse(new FinanceAccountResource($account), 'Akun berhasil dibuat');
    }

    public function update(FinanceAccountRequest $request, FinanceAccount $account): JsonResponse
    {
        $data = $request->validated();

        // Tipe akun sistem menentukan saldo normal & laporan; mengubahnya akan
        // mengacaukan jurnal lama. Hanya akun non-sistem yang boleh ganti tipe.
        if ($account->is_system && isset($data['type']) && $data['type'] !== $account->type) {
            throw ValidationException::withMessages([
                'type' => 'Tipe akun sistem tidak boleh diubah agar buku besar tetap konsisten.',
            ]);
        }

        $this->assertParentValid($data['parent_id'] ?? null, $account->id, $data['type'] ?? $account->type);

        $account->update($data);

        return $this->successResponse(new FinanceAccountResource($account->fresh()), 'Akun berhasil diperbarui');
    }

    public function destroy(FinanceAccount $account): JsonResponse
    {
        if ($account->is_system) {
            throw ValidationException::withMessages([
                'account' => 'Akun bawaan sistem tidak boleh dihapus. Nonaktifkan bila tidak dipakai.',
            ]);
        }

        if ($account->children()->exists()) {
            throw ValidationException::withMessages([
                'account' => 'Pindahkan atau hapus akun anak sebelum menghapus akun induk ini.',
            ]);
        }

        if (FinancialTransactionLine::query()->where('account_id', $account->id)->exists()) {
            throw ValidationException::withMessages([
                'account' => 'Akun sudah pernah dipakai jurnal dan tidak boleh dihapus. Nonaktifkan saja.',
            ]);
        }

        $account->delete();

        return $this->successResponse(null, 'Akun berhasil dihapus');
    }

    /**
     * Cegah siklus hierarchy (akun jadi induk dirinya sendiri / keturunannya)
     * dan paksa induk satu tipe agar laporan tidak mencampur kategori.
     */
    protected function assertParentValid(?int $parentId, ?int $selfId, ?string $type): void
    {
        if ($parentId === null) {
            return;
        }

        if ($selfId !== null && $parentId === $selfId) {
            throw ValidationException::withMessages(['parent_id' => 'Akun tidak boleh menjadi induk dirinya sendiri.']);
        }

        $parent = FinanceAccount::query()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages(['parent_id' => 'Akun induk tidak ditemukan.']);
        }

        if ($type !== null && $parent->type !== $type) {
            throw ValidationException::withMessages([
                'parent_id' => "Akun induk harus bertipe sama ({$parent->type}).",
            ]);
        }

        // Induk tidak boleh keturunan dari akun ini (mencegah loop).
        if ($selfId !== null) {
            $cursor = $parent;
            while ($cursor !== null) {
                if ((int) $cursor->id === $selfId) {
                    throw ValidationException::withMessages(['parent_id' => 'Akun induk tidak boleh keturunan dari akun ini.']);
                }
                $cursor = $cursor->parent_id !== null ? FinanceAccount::query()->find($cursor->parent_id) : null;
            }
        }
    }
}
