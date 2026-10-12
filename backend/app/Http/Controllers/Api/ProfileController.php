<?php

namespace App\Http\Controllers\Api;

use App\Services\Profile\ProfileService;
use App\Support\ContextPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profil 360° (siswa, wali, pegawai): satu halaman berisi semua yang berhubungan
 * dengan orangnya, dikumpulkan dari seluruh modul dan dimuat per bagian.
 *
 * Izin dasar dijaga di route (`student.view` / `employee.view`); setiap bagian
 * punya izin modulnya sendiri (lihat ProfileProvider), sehingga melihat profil
 * tidak otomatis membuka keuangan, kesehatan, konseling, atau gaji.
 */
class ProfileController extends BaseApiController
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function student(Request $request, int $student): JsonResponse
    {
        return $this->successResponse($this->profiles->overview($request->user(), 'students', $student), 'Profil siswa berhasil diambil');
    }

    public function studentSection(Request $request, int $student, string $section): JsonResponse
    {
        return $this->successResponse($this->profiles->section($request->user(), 'students', $student, $section), 'Bagian profil berhasil diambil');
    }

    public function guardian(Request $request, int $guardian): JsonResponse
    {
        $this->denyFamilyOnly($request);

        return $this->successResponse($this->profiles->overview($request->user(), 'guardians', $guardian), 'Profil wali berhasil diambil');
    }

    public function guardianSection(Request $request, int $guardian, string $section): JsonResponse
    {
        $this->denyFamilyOnly($request);

        return $this->successResponse($this->profiles->section($request->user(), 'guardians', $guardian, $section), 'Bagian profil berhasil diambil');
    }

    public function employee(Request $request, int $employee): JsonResponse
    {
        $this->denyFamilyOnly($request);

        return $this->successResponse($this->profiles->overview($request->user(), 'employees', $employee), 'Profil pegawai berhasil diambil');
    }

    public function employeeSection(Request $request, int $employee, string $section): JsonResponse
    {
        $this->denyFamilyOnly($request);

        return $this->successResponse($this->profiles->section($request->user(), 'employees', $employee, $section), 'Bagian profil berhasil diambil');
    }

    /** Akun wali-murid/siswa tidak boleh membuka profil wali lain maupun pegawai (hanya anak sendiri via profil siswa). */
    private function denyFamilyOnly(Request $request): void
    {
        $roles = $request->user()->getRoleNames();
        $familyOnly = $roles->isNotEmpty() && $roles->diff(array_values(ContextPermissions::FAMILY_ROLES))->isEmpty();

        abort_if($familyOnly, 403, 'Anda tidak berwenang membuka profil ini.');
    }
}
