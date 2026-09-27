<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceClearanceDecisionRequest;
use App\Http\Requests\Finance\FinanceClearanceRequest;
use App\Http\Resources\FinanceClearanceResource;
use App\Models\FinanceClearance;
use App\Models\Student;
use App\Services\Finance\FinanceClearanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Keberesan finansial / financial clearance (§12 Phase 5).
 *
 * Endpoint di sini ada tiga jenis: membaca daftar periksa (`index`, `show`,
 * `check`, `config`), menjalankan pemeriksaan (`store`, `refresh`), dan
 * mengambil keputusan (`approve`, `waive`). Yang terakhir inilah satu-satunya
 * yang menulis status final, dan keduanya wajib beralasan.
 *
 * `check` sengaja berbeda dari `show`: yang pertama menjawab pertanyaan modul
 * lain ("santri ini boleh lulus?") tanpa memerlukan dokumen lebih dulu, yang
 * kedua membaca dokumen yang memang sudah diajukan. Karena `check` tidak
 * menulis apa pun, ia aman dipanggil berkali-kali dari layar mana pun.
 */
class FinanceClearanceController extends BaseApiController
{
    public function __construct(private readonly FinanceClearanceService $clearances) {}

    /** Aturan & flag yang berlaku (§12: ketahanan tiap keperluan ada di Settings). */
    public function config(): JsonResponse
    {
        return $this->successResponse($this->clearances->config(), 'Konfigurasi keberesan finansial berhasil diambil');
    }

    /** Daftar keberesan dengan pemeriksaan terakhirnya. */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->clearances->paginate($request->query());

        return $this->successResponse(
            FinanceClearanceResource::collection($paginator->appends($request->query()))
                // Meta tambahan digabung dengan meta.pagination bawaan Laravel,
                // jadi hanya kamus label yang ditulis di sini — angka halaman
                // sudah ada, dan menyalinnya ulang justru menghasilkan nilai
                // dobel (`total` menjadi daftar, bukan angka).
                ->additional(['meta' => [
                    'purposes' => FinanceClearance::PURPOSES,
                    'purpose_labels' => FinanceClearance::PURPOSE_LABELS,
                    'statuses' => FinanceClearance::STATUSES,
                    'status_labels' => FinanceClearance::STATUS_LABELS,
                    'configuration' => $this->clearances->config(),
                ]]),
            'Daftar keberesan finansial berhasil diambil',
        );
    }

    /** Satu dokumen keberesan, lengkap dengan daftar periksanya. */
    public function show(FinanceClearance $clearance): JsonResponse
    {
        return $this->successResponse(
            new FinanceClearanceResource($clearance->load(['student:id,full_name,nis', 'requestedBy:id,name', 'decidedBy:id,name'])),
            'Detail keberesan finansial berhasil diambil',
        );
    }

    /**
     * Jawaban hidup untuk modul pemanggil (§12 + §11: integration layer).
     *
     * Read-only: tidak membuat dokumen, tidak mengubah apa pun.
     */
    public function check(Request $request, Student $student, string $purpose): JsonResponse
    {
        $request->validate(['as_of' => 'nullable|date']);

        $asOf = $request->filled('as_of') ? Carbon::parse((string) $request->query('as_of')) : null;

        return $this->successResponse(
            $this->clearances->verdict($student, $purpose, $asOf),
            'Pemeriksaan keberesan finansial berhasil diambil',
        );
    }

    /** Ajukan (atau isi ulang) keberesan satu santri untuk satu keperluan. */
    public function store(FinanceClearanceRequest $request): JsonResponse
    {
        $clearance = $this->clearances->request($request->validated(), $request->user());

        return $this->successResponse(
            new FinanceClearanceResource($clearance),
            'Keberesan finansial berhasil diperiksa',
            201,
        );
    }

    /** Isi ulang pemeriksaan dari dokumen yang ada sekarang. */
    public function refresh(Request $request, FinanceClearance $clearance): JsonResponse
    {
        $request->validate(['as_of' => 'nullable|date']);

        $asOf = $request->filled('as_of') ? Carbon::parse((string) $request->input('as_of')) : null;

        return $this->successResponse(
            new FinanceClearanceResource($this->clearances->refresh($clearance, $asOf)),
            'Pemeriksaan keberesan finansial berhasil dimutakhirkan',
        );
    }

    /** Keputusan "boleh" atas keberesan yang menunggu persetujuan. */
    public function approve(FinanceClearanceDecisionRequest $request, FinanceClearance $clearance): JsonResponse
    {
        return $this->successResponse(
            new FinanceClearanceResource(
                $this->clearances->approve($clearance, (string) $request->validated('reason'), $request->user()),
            ),
            'Keberesan finansial disetujui',
        );
    }

    /**
     * Pengecualian: layanan diberikan walau aturan menahan.
     *
     * Tidak ada nominal yang bergerak di sini — pembebasan piutang yang
     * sesungguhnya tetap lewat write-off (§21) yang punya jurnalnya sendiri.
     */
    public function waive(FinanceClearanceDecisionRequest $request, FinanceClearance $clearance): JsonResponse
    {
        return $this->successResponse(
            new FinanceClearanceResource(
                $this->clearances->waive($clearance, (string) $request->validated('reason'), $request->user()),
            ),
            'Keberesan finansial dikecualikan',
        );
    }
}
