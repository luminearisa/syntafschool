<?php

namespace App\Http\Resources;

use App\Models\BoardingPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu izin keluar santri (§28) beserta keadaan antreannya.
 *
 * Dua hal yang membuat daftar ini bisa dipakai petugas tanpa membaca dokumen:
 *
 * - **Flag kelayakan tindakan** (`can_submit`, `can_approve`, `can_check_out`,
 *   …) disusun dari status + `current_step`/`required_steps`, jadi tombol di
 *   layar dan penjaga di `BoardingPermissionService` memakai keadaan yang sama.
 *   Flag ini TIDAK memeriksa permission — hak ada di middleware rute; kalau
 *   flag ikut menyaring hak, frontend akan menyembunyikan aksi yang sebenarnya
 *   boleh dan petugas mengira datanya rusak.
 * - **Langkah persetujuan** (`current_step`/`required_steps`) ikut ditampilkan
 *   karena §27 membolehkan 1 atau 2 langkah per asrama; satu angka "approved"
 *   saja tidak menjelaskan apakah antrean pengasuh masih tersisa satu orang.
 *
 * `checked_out_at` dibaca dari jejak audit (tabel izin tidak punya kolomnya,
 * §28) sehingga hanya terisi pada halaman detail, saat service mengambilnya.
 */
class BoardingPermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = $this->staffView($request);

        return [
            'id' => (int) $this->id,
            'student_id' => (int) $this->student_id,
            'student' => $this->when(
                $this->relationLoaded('student') && $this->student !== null,
                fn (): array => [
                    'id' => (int) $this->student->id,
                    'full_name' => $this->student->full_name,
                    'nis' => $this->student->nis,
                ],
            ),
            'permission_type' => $this->permission_type,
            'permission_type_label' => $this->typeLabel(),
            'destination' => $this->destination,
            'reason' => $this->reason,
            'start_at' => $this->start_at?->toIso8601String(),
            'expected_return_at' => $this->expected_return_at?->toIso8601String(),
            'actual_return_at' => $this->actual_return_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
            'checked_in_at' => $this->actual_return_at?->toIso8601String(),
            'late_return' => (bool) $this->late_return,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'is_active' => $this->isActive(),
            'is_out' => $this->isOut(),
            'is_overdue' => $this->hasOverdueReturn(),
            'current_step' => (int) $this->current_step,
            'required_steps' => (int) $this->required_steps,
            'remaining_steps' => max(0, max(1, (int) $this->required_steps) - (int) $this->current_step),
            'can_submit' => $this->status === BoardingPermission::STATUS_DRAFT,
            'can_approve' => $this->status === BoardingPermission::STATUS_SUBMITTED && ! $this->isFullyApproved(),
            'can_reject' => in_array($this->status, [
                BoardingPermission::STATUS_DRAFT,
                BoardingPermission::STATUS_SUBMITTED,
                BoardingPermission::STATUS_APPROVED,
            ], true),
            'can_check_out' => $this->status === BoardingPermission::STATUS_APPROVED,
            'can_check_in' => $this->status === BoardingPermission::STATUS_OUT,
            'can_cancel' => in_array($this->status, [
                BoardingPermission::STATUS_DRAFT,
                BoardingPermission::STATUS_SUBMITTED,
                BoardingPermission::STATUS_APPROVED,
            ], true),
            'requested_by' => $this->when($staff, $this->nullableId($this->requested_by)),
            'requested_by_name' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'checked_out_by' => $this->when($staff, $this->nullableId($this->checked_out_by)),
            'checked_out_by_name' => $this->whenLoaded('checkedOutBy', fn () => $this->checkedOutBy?->name),
            'checked_in_by' => $this->when($staff, $this->nullableId($this->checked_in_by)),
            'checked_in_by_name' => $this->whenLoaded('checkedInBy', fn () => $this->checkedInBy?->name),
            // Timeline persetujuan: isi halaman detail pengasuh (§27).
            'approvals' => $this->when(
                $staff && $this->relationLoaded('approvals'),
                fn () => BoardingPermissionApprovalResource::collection($this->approvals),
            ),
            'attachment_path' => $this->when($staff, $this->attachment_path),
            'notes' => $this->when($staff, $this->notes),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Petugas modul: pemegang salah satu hak baca/kelola izin asrama. */
    private function staffView(Request $request): bool
    {
        return $request->user()?->hasAnyPermission([
            'boarding.permission.view',
            'boarding.permission.manage',
            'boarding.permission.approve',
        ]) ?? false;
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
