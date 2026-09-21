<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbConvertToStudentRequest;
use App\Http\Resources\PpdbRegistrationResource;
use App\Http\Resources\StudentResource;
use App\Models\PpdbRegistration;
use App\Models\User;
use App\Services\Ppdb\PpdbConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Konversi calon peserta didik menjadi siswa (`students`).
 *
 * Hanya satu jalur: pendaftaran harus sudah `completed` (daftar ulang selesai) —
 * `PpdbConversionService` menolaknya bila belum, jadi calon yang baru lulus tidak
 * pernah otomatis menjadi siswa.
 *
 * Yang dipakai ulang adalah sistem yang sudah ada, bukan salinan baru:
 * - NIS dari penomoran `document_sequences` (`PpdbNumberingService::next('student')`),
 *   bukan angka acak di controller;
 * - status siswa dari master `student_statuses` aktif ("Aktif"), bukan id hardcode;
 * - wali tidak disalin identitasnya — `guardian_id` milik calon ditautkan lewat
 *   `student_guardians`;
 * - orang yang sama tidak lahir dua kali: kecocokan NISN/baris existing dipulihkan
 *   (`created: false`) karena `nis`/`nisn` unik di database.
 *
 * Berkas calon yang sudah disetujui ikut dilaporkan (`documents`) supaya konversi
 * ulang tidak meninggalkan siswa tanpa dokumen.
 */
class PpdbApplicantConversionController extends BaseApiController
{
    public function __construct(protected PpdbConversionService $conversion)
    {
    }

    /**
     * POST /ppdb/registrations/{registration}/convert-to-student.
     *
     * `school_class_id` + `academic_year_id` opsional: hanya bila rombel benar-benar
     * dikirim (dan tahun ajarannya cocok) peserta didaftarkan ke kelas.
     */
    public function store(PpdbConvertToStudentRequest $request, PpdbRegistration $registration): JsonResponse
    {
        $result = $this->conversion->convert(
            $registration,
            $this->actor($request),
            array_filter($request->validated(), static fn (mixed $value): bool => $value !== null),
        );

        $student = $result['student'];

        return $this->createdResponse([
            'student' => new StudentResource($student),
            'created' => $result['created'],
            'matched_by' => $result['matched_by'] ?? null,
            'documents' => $result['documents'] ?? [],
            'skipped' => $result['skipped'] ?? [],
            'registration' => PpdbRegistrationResource::row($result['registration']),
        ], $result['created']
            ? "Calon berhasil dijadikan siswa dengan NIS {$student->nis}"
            : "Siswa sudah ada (NIS {$student->nis}) — data pendaftaran ini ditautkan ulang, tidak dibuat baris baru");
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Autentikasi diperlukan untuk mengakses data ini.');

        return $user;
    }
}
