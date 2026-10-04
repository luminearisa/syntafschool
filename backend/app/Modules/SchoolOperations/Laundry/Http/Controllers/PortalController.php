<?php

namespace App\Modules\SchoolOperations\Laundry\Http\Controllers;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryAnalyticsService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryEntitlementService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryOrderService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryScopeService;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Portal santri (§27, §62) & wali (§25/§26, §63, §71).
 *
 * Isolasi data (Rule 20): wali hanya anak yang menjadi authorized dependent
 * (dicek lewat ParentProvider/ContextService); santri hanya dirinya sendiri.
 * Saldo wallet selalu dari WalletProvider (§47) — tidak pernah dari tabel
 * laundry.
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly LaundryScopeService $scope,
        private readonly LaundryAnalyticsService $analytics,
        private readonly LaundryEntitlementService $entitlements,
        private readonly LaundryOrderService $orders,
    ) {
    }

    // =====================================================================
    // STUDENT (/api/v1/me/laundry)
    // =====================================================================

    public function meQuota(Request $request): JsonResponse
    {
        $studentId = $this->assertStudentSelf($request);

        return response()->json(['data' => $this->entitlements->currentState($studentId)]);
    }

    public function meOrders(Request $request): JsonResponse
    {
        $studentId = $this->assertStudentSelf($request);

        return response()->json(['data' => $this->studentOrders($studentId, $request)]);
    }

    public function meOrder(Request $request, int $id): JsonResponse
    {
        $studentId = $this->assertStudentSelf($request);
        $order = $this->assertOrderOfStudent($id, $studentId);

        return response()->json(['data' => $this->orders->present($order)]);
    }

    public function meStatement(Request $request): JsonResponse
    {
        $studentId = $this->assertStudentSelf($request);

        return response()->json(['data' => $this->analytics->studentStatement($studentId, $request->input('period'))]);
    }

    // =====================================================================
    // PARENT (/api/v1/parent/children/{studentId}/laundry)
    // =====================================================================

    /** Daftar anak yang terhubung + ringkasan laundry masing-masing. */
    public function children(Request $request): JsonResponse
    {
        $request->user()->can('laundry.portal.view') || abort(403);

        $children = $this->scope->childStudentIds($request->user());

        return response()->json(['data' => array_values(array_map(
            fn (int $studentId): array => $this->analytics->portalContext($studentId),
            $children
        ))]);
    }

    /** §71: header jatah + wallet + riwayat untuk satu anak. */
    public function childOverview(Request $request, int $studentId): JsonResponse
    {
        $this->assertChildAccess($request, $studentId);

        return response()->json(['data' => $this->analytics->portalContext($studentId)]);
    }

    public function childQuota(Request $request, int $studentId): JsonResponse
    {
        $this->assertChildAccess($request, $studentId);

        return response()->json(['data' => [
            'current' => $this->entitlements->currentState($studentId),
            'history' => $this->entitlements->historyFor($studentId),
        ]]);
    }

    public function childOrders(Request $request, int $studentId): JsonResponse
    {
        $this->assertChildAccess($request, $studentId);

        return response()->json(['data' => $this->studentOrders($studentId, $request)]);
    }

    /** §26: detail transaksi termasuk referensi wallet (saldo sebelum/sesudah). */
    public function childOrder(Request $request, int $studentId, int $orderId): JsonResponse
    {
        $this->assertChildAccess($request, $studentId);
        $order = $this->assertOrderOfStudent($orderId, $studentId);

        $payment = LaundryPayment::query()
            ->where('laundry_order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        return response()->json(['data' => array_merge($this->orders->present($order), [
            'wallet_payment' => $payment !== null ? [
                'amount' => (float) $payment->amount,
                'wallet_transaction' => $payment->wallet_transaction_number,
                'balance_before' => $payment->balance_before !== null ? (float) $payment->balance_before : null,
                'balance_after' => $payment->balance_after !== null ? (float) $payment->balance_after : null,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'status' => (string) $payment->status,
            ] : null,
        ])]);
    }

    /** §53/§72: statement + referensi transaksi wallet. */
    public function childStatement(Request $request, int $studentId): JsonResponse
    {
        $this->assertChildAccess($request, $studentId);

        return response()->json(['data' => $this->analytics->studentStatement($studentId, $request->input('period'))]);
    }

    // =====================================================================
    // INTERNAL
    // =====================================================================

    private function assertStudentSelf(Request $request): int
    {
        $request->user()->can('laundry.portal.view') || abort(403);

        $studentId = $this->scope->studentIdForUser($request->user());
        abort_if($studentId === null, 403, 'Akun ini tidak tertaut ke identitas santri.');

        return $studentId;
    }

    private function assertChildAccess(Request $request, int $studentId): void
    {
        $request->user()->can('laundry.portal.view') || abort(403);
        abort_unless(
            $this->scope->canAccessStudent($request->user(), $studentId),
            403,
            'Anda hanya dapat melihat data anak Anda.'
        );
    }

    private function assertOrderOfStudent(int $orderId, int $studentId): LaundryOrder
    {
        $order = LaundryOrder::query()->whereKey($orderId)->firstOrFail();
        abort_unless((int) $order->student_id === $studentId, 404);

        return $order;
    }

    private function studentOrders(int $studentId, Request $request): array
    {
        return LaundryOrder::query()
            ->where('student_id', $studentId)
            ->when($request->string('status')->toString(), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (LaundryOrder $o): array => $this->orders->presentSummary($o))
            ->all();
    }
}
