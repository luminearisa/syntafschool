<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbAnnouncementRequest;
use App\Http\Requests\Ppdb\PpdbResultSetRequest;
use App\Http\Resources\PpdbAnnouncementResource;
use App\Http\Resources\PpdbRegistrationResource;
use App\Models\PpdbAnnouncement;
use App\Models\PpdbRegistration;
use App\Models\User;
use App\Services\Ppdb\PpdbAnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pengumuman hasil seleksi + penetapan hasil per pendaftaran.
 *
 * `publish` hanya mengirim lewat kanal yang benar-benar ada di aplikasi ini
 * (in-app/database). Kanal `email`/`whatsapp` pada kolom `channel` diperlakukan
 * sebagai niat: dilaporkan sebagai "butuh konfigurasi" pada `channels`, bukan
 * pura-pura terkirim.
 *
 * `setResult` adalah satu-satunya jalan menulis hasil seleksi, dan selalu lewat
 * `PpdbAnnouncementService::setResult()` → mesin status, sehingga riwayat
 * pendaftaran + notifikasi hasil + jejak audit tetap satu jalur. Penetapan
 * `passed` dijaga kuota program/gelombangnya (audit §27): kuota penuh butuh
 * hak `ppdb.registration.override_quota` **dan** `quota_override_reason`.
 */
class PpdbAnnouncementController extends BaseApiController
{
    public function __construct(protected PpdbAnnouncementService $announcements)
    {
    }

    /** GET /ppdb/announcements — filter periode/status/kanal + pencarian judul/isi. */
    public function index(Request $request): JsonResponse
    {
        $announcements = $this->announcements->query($request)
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return $this->successResponse(
            PpdbAnnouncementResource::collection($announcements),
            'Daftar pengumuman berhasil diambil',
        );
    }

    /** POST /ppdb/announcements — selalu mulai sebagai draf. */
    public function store(PpdbAnnouncementRequest $request): JsonResponse
    {
        $announcement = PpdbAnnouncement::query()->create($request->validated() + [
            'status' => $request->validated('status') ?? PpdbAnnouncement::STATUS_DRAFT,
        ]);

        return $this->createdResponse(
            new PpdbAnnouncementResource($this->withRelations($announcement)),
            'Pengumuman berhasil disimpan sebagai draf',
        );
    }

    /** GET /ppdb/announcements/{announcement}. */
    public function show(PpdbAnnouncement $announcement): JsonResponse
    {
        return $this->successResponse(
            new PpdbAnnouncementResource($this->withRelations($announcement)),
            'Detail pengumuman berhasil diambil',
        );
    }

    /** PUT /ppdb/announcements/{announcement}. */
    public function update(PpdbAnnouncementRequest $request, PpdbAnnouncement $announcement): JsonResponse
    {
        // `published_at`/`published_by` distempel oleh aksi publish, bukan isian formulir.
        $announcement->fill($request->validated());

        if ($announcement->isDirty('status') && $announcement->status === PpdbAnnouncement::STATUS_PUBLISHED) {
            throw ValidationException::withMessages([
                'status' => 'Pengumuman diterbitkan lewat POST /announcements/{id}/publish, '
                    .'bukan dengan mengubah status langsung — hanya jalur itu riwayat & notifikasinya tercatat.',
            ]);
        }

        $announcement->save();

        return $this->successResponse(
            new PpdbAnnouncementResource($this->withRelations($announcement)),
            'Pengumuman berhasil diperbarui',
        );
    }

    /**
     * DELETE /ppdb/announcements/{announcement}.
     *
     * Pengumuman yang sudah terbit adalah jejak publik — diarsipkan, bukan dihapus.
     */
    public function destroy(PpdbAnnouncement $announcement): JsonResponse
    {
        if ($announcement->isPublished()) {
            throw ValidationException::withMessages([
                'status' => 'Pengumuman yang sudah terbit tidak dapat dihapus. Arsipkan lewat '
                    .'POST /announcements/'.$announcement->id.'/archive.',
            ]);
        }

        $announcement->delete();

        return $this->successResponse(null, 'Draf pengumuman berhasil dihapus');
    }

    /**
     * POST /ppdb/announcements/{announcement}/publish — terbitkan + kabari orang tua.
     *
     * `delivered` adalah jumlah penerima in-app sesungguhnya (dibatasi
     * `PpdbAnnouncementService::MAX_RECIPIENTS`); `channels` menjelaskan kanal
     * yang belum punya penyedia nyata.
     */
    public function publish(Request $request, PpdbAnnouncement $announcement): JsonResponse
    {
        $result = $this->announcements->publish($announcement, $this->actor($request));

        return $this->successResponse([
            'announcement' => new PpdbAnnouncementResource($this->withRelations($result['announcement'])),
            'delivered' => $result['delivered'],
            'channels' => $result['channels'],
        ], 'Pengumuman berhasil diterbitkan dan dikirim ke '.$result['delivered'].' orang tua');
    }

    /** POST /ppdb/announcements/{announcement}/archive. */
    public function archive(PpdbAnnouncement $announcement): JsonResponse
    {
        return $this->successResponse(
            new PpdbAnnouncementResource($this->withRelations($this->announcements->archive($announcement))),
            'Pengumuman berhasil diarsipkan',
        );
    }

    /**
     * POST /ppdb/registrations/{registration}/result — tetapkan hasil seleksi.
     *
     * Bertiga bentuknya: `passed` / `failed` / `waitlisted`. Pendaftaran yang
     * belum masuk tahap seleksi dinaikan ke `selection` lebih dulu di transaksi
     * yang sama (`stage_reached` menandakan itu terjadi).
     *
     * Kuota (audit §27 + keputusan pemilik): bila kursi program/gelombang sudah
     * penuh, permintaan ditolak 422 sampai pengirim mengirim
     * `quota_override_reason` — dan itu pun hanya sah bila pengirimnya memegang
     * hak `ppdb.registration.override_quota` (403 bila tidak). Alasan override
     * disimpan pada pendaftaran, riwayat, dan jejak audit.
     */
    public function setResult(
        PpdbResultSetRequest $request,
        PpdbRegistration $registration,
    ): JsonResponse {
        $result = $this->announcements->setResult(
            $registration,
            (string) $request->validated('result'),
            $request->validated('note'),
            $request->validated('announcement_id') === null ? null : (int) $request->validated('announcement_id'),
            $this->actor($request),
            $request->validated('quota_override_reason'),
        );

        $registration = $result['registration']->loadMissing([
            'applicant:id,full_name,nickname',
            'program:id,name,code,quota',
            'wave:id,name,code,quota',
        ]);

        $overQuota = (bool) ($result['quota']['over_quota'] ?? false);

        return $this->successResponse([
            'registration' => PpdbRegistrationResource::row($registration),
            'result' => $result['result'],
            'stage_reached' => $result['stage_reached'],
            'quota' => $result['quota'],
        ], $overQuota
            ? 'Hasil ditetapkan MELEWATI KUOTA — alasan override tercatat pada riwayat pendaftaran'
            : 'Hasil seleksi berhasil ditetapkan');
    }

    private function withRelations(PpdbAnnouncement $announcement): PpdbAnnouncement
    {
        return $announcement->loadMissing(['period:id,name,code', 'publishedBy:id,name']);
    }

    private function perPage(Request $request): int
    {
        return max(1, min(200, (int) $request->query('per_page', 15)));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Autentikasi diperlukan untuk mengakses data ini.');

        return $user;
    }
}
