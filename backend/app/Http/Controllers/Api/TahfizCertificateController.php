<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizCertificateRequest;
use App\Http\Resources\TahfizCertificateResource;
use App\Models\TahfizCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sertifikat Tahfiz.
 *
 * Nomor sertifikat dan token QR dibangkitkan server-side dan unik. Token QR
 * memungkinkan verifikasi publik tanpa login — dengan memindai, siapa pun
 * bisa memastikan keaslian sertifikat tanpa membuka data sensitif.
 */
class TahfizCertificateController extends BaseApiController
{
    protected array $filterable = [
        'participant_id', 'program_id', 'achievement_type', 'status',
    ];

    protected array $with = ['participant', 'program', 'examiner', 'issuer'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizCertificate::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        $query->latest('issue_date');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizCertificateResource::collection($query->get()),
                'Daftar sertifikat berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizCertificateResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar sertifikat berhasil diambil'
        );
    }

    public function show(TahfizCertificate $certificate): JsonResponse
    {
        return $this->successResponse(
            new TahfizCertificateResource($certificate->load($this->with)),
            'Detail sertifikat berhasil diambil'
        );
    }

    /**
     * Terbitkan sertifikat: nomor & token QR dibangkitkan di sini.
     */
    public function store(TahfizCertificateRequest $request): JsonResponse
    {
        $certificate = DB::transaction(function () use ($request): TahfizCertificate {
            $payload = $request->validated();
            $payload['certificate_number'] = $this->nextNumber();
            $payload['qr_token'] = Str::random(64);
            $payload['issuer_id'] = $request->user()?->id;
            $payload['status'] = TahfizCertificate::STATUS_ISSUED;

            return TahfizCertificate::create($payload);
        });

        return $this->createdResponse(
            new TahfizCertificateResource($certificate->load($this->with)),
            'Sertifikat berhasil diterbitkan.'
        );
    }

    public function update(TahfizCertificateRequest $request, TahfizCertificate $certificate): JsonResponse
    {
        if ($certificate->status === TahfizCertificate::STATUS_REVOKED) {
            throw ValidationException::withMessages([
                'status' => 'Sertifikat yang sudah dicabut tidak dapat diubah. Terbitkan ulang sebagai sertifikat baru.',
            ]);
        }

        $certificate->update($request->validated());

        return $this->successResponse(
            new TahfizCertificateResource($certificate->load($this->with)),
            'Sertifikat berhasil diperbarui.'
        );
    }

    /**
     * Cabut sertifikat — token QR kemudian tidak lagi valid.
     */
    public function revoke(Request $request, TahfizCertificate $certificate): JsonResponse
    {
        $validated = $request->validate([
            'revoke_reason' => ['required', 'string', 'max:2000'],
        ]);

        if ($certificate->status === TahfizCertificate::STATUS_REVOKED) {
            throw ValidationException::withMessages([
                'status' => 'Sertifikat sudah dicabut.',
            ]);
        }

        $certificate->update([
            'status' => TahfizCertificate::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoke_reason' => $validated['revoke_reason'],
        ]);

        return $this->successResponse(
            new TahfizCertificateResource($certificate->load($this->with)),
            'Sertifikat berhasil dicabut.'
        );
    }

    public function destroy(TahfizCertificate $certificate): JsonResponse
    {
        $certificate->delete();

        return $this->successResponse(null, 'Sertifikat berhasil dihapus.');
    }

    /**
     * Verifikasi publik lewat token QR — tanpa login.
     *
     * Hanya menampilkan data yang relevan untuk keaslian: nomor, peserta,
     * jenis prestasi, dan status. Tidak membuka catatan setoran atau nilai.
     */
    public function verify(Request $request): JsonResponse
    {
        $qrToken = $request->input('qr_token') ?? $request->query('token');
        $qrToken = is_string($qrToken) ? trim($qrToken) : '';

        $certificate = TahfizCertificate::query()
            ->with(['participant', 'program'])
            ->where('qr_token', $qrToken)
            ->where('status', TahfizCertificate::STATUS_ISSUED)
            ->first();

        if ($certificate === null) {
            return $this->errorResponse('Token verifikasi tidak valid atau sertifikat telah dicabut.', 404);
        }

        return $this->successResponse([
            'certificate_number' => $certificate->certificate_number,
            'is_valid' => true,
            'participant_name' => $certificate->participant?->name,
            'program_name' => $certificate->program?->name,
            'achievement_type' => $certificate->achievement_type,
            'juz_count' => $certificate->juz_count,
            'issue_date' => $certificate->issue_date?->toDateString(),
            'signature_name' => $certificate->signature_name,
            'examiner_name' => $certificate->examiner?->full_name,
        ], 'Sertifikat terverifikasi.');
    }

    /**
     * Nomor urut unik: THZ-CERT-<tahun>-<urutan>.
     */
    protected function nextNumber(): string
    {
        $year = now()->year;
        $prefix = "THZ-CERT-{$year}-";

        $latest = TahfizCertificate::query()
            ->where('certificate_number', 'like', $prefix.'%')
            ->max('certificate_number');

        $sequence = 1;
        if ($latest !== null) {
            $parts = explode('-', (string) $latest);
            $sequence = ((int) end($parts)) + 1;
        }

        // SQLite memperlakukan NULL berbeda pada indeks unik, jadi keunikan
        // tetap ditegakkan di aplikasi dengan mencoba ulang bila bentrok.
        $number = $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);

        while (TahfizCertificate::query()->where('certificate_number', $number)->exists()) {
            $sequence++;
            $number = $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
        }

        return $number;
    }
}
