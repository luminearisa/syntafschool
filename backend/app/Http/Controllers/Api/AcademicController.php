<?php

namespace App\Http\Controllers\Api;

use App\Models\AcademicYear;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AcademicController extends BaseApiController
{
    /**
     * Ringkasan periode akademik aktif (tahun ajaran + semester aktif).
     */
    public function active(): JsonResponse
    {
        $academicYear = AcademicYear::where('is_active', true)->first();
        $semester = Semester::where('is_active', true)
            ->with('academicYear')
            ->first();

        return $this->successResponse([
            'academic_year' => $academicYear,
            'semester' => $semester,
        ], 'Periode akademik aktif berhasil diambil');
    }

    /**
     * Aktifkan tahun ajaran (hanya satu yang aktif).
     */
    public function activateYear(AcademicYear $academicYear): JsonResponse
    {
        DB::transaction(function () use ($academicYear) {
            AcademicYear::where('is_active', true)->update(['is_active' => false]);
            $academicYear->update(['is_active' => true]);
        });

        return $this->successResponse(
            $academicYear->refresh(),
            "Tahun ajaran {$academicYear->name} berhasil diaktifkan",
        );
    }

    /**
     * Aktifkan semester (hanya satu yang aktif).
     */
    public function activateSemester(Semester $semester): JsonResponse
    {
        DB::transaction(function () use ($semester) {
            Semester::where('is_active', true)->update(['is_active' => false]);
            $semester->update(['is_active' => true]);
        });

        return $this->successResponse(
            $semester->refresh()->load('academicYear'),
            "Semester {$semester->name} berhasil diaktifkan",
        );
    }
}
