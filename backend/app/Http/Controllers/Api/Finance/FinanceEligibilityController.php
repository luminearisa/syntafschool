<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Student;
use App\Services\Finance\FinancialEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Kelayakan finansial (§11 Phase 5) — HANYA BACA.
 *
 * Kelas ini adalah "integration layer" yang diminta §11: modul akademik atau
 * operasional menanyakan apakah seorang santri boleh ikut ujian / mengambil
 * rapor / menerima ijazah, dan Finance menjawab dengan putusan plus daftar
 * tagihan yang menyebabkannya. Tidak ada satu pun endpoint di sini yang
 * mengubah status akademik, nilai, atau kelulusan — dan tidak akan ada: yang
 * menulis di sisi itu adalah modulnya sendiri, setelah menanyakan ke
 * `FinancialEligibilityService` (atau `assertEligible()` di sisi server).
 *
 * Konfigurasi aturannya ikut dibaca (`rules`) supaya layar tidak perlu
 * mengarang label atau menyalin ambang: aturan hidup di Settings, bukan di
 * frontend.
 */
class FinanceEligibilityController extends BaseApiController
{
    public function __construct(private readonly FinancialEligibilityService $eligibility) {}

    /** Aturan & ambang berlaku (§11: "jangan hard-code aturan"). */
    public function rules(): JsonResponse
    {
        return $this->successResponse($this->eligibility->configuration(), 'Aturan kelayakan finansial berhasil diambil');
    }

    /** Seluruh aksi untuk satu santri: layar "status kelayakan". */
    public function index(Request $request, Student $student): JsonResponse
    {
        return $this->successResponse(
            $this->eligibility->checkAll($student, $this->asOf($request)),
            'Kelayakan finansial santri berhasil diambil',
        );
    }

    /**
     * Satu aksi untuk satu santri — jawaban yang dipakai modul pemanggil lewat
     * HTTP (jalur di dalam aplikasi memanggil service-nya langsung).
     */
    public function show(Request $request, Student $student, string $action): JsonResponse
    {
        return $this->successResponse(
            $this->eligibility->check($student, $action, $this->asOf($request)),
            'Kelayakan finansial santri berhasil diambil',
        );
    }

    /** `?as_of=YYYY-MM-DD` dipakai untuk memeriksa tanggal mundur. */
    private function asOf(Request $request): ?Carbon
    {
        $request->validate(['as_of' => 'nullable|date']);

        return $request->filled('as_of') ? Carbon::parse((string) $request->query('as_of')) : null;
    }
}
