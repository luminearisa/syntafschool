<?php

namespace App\Http\Controllers\Api;

use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GeneralSettingsController extends BaseApiController
{

    public function show(): JsonResponse
    {
        $school = School::query()
            ->with('foundation:id,name')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        return $this->successResponse(['school' => $school], 'Pengaturan umum berhasil diambil');
    }

    public function update(Request $request): JsonResponse
    {
        $schoolId = $request->input('school.id');
        $data = $request->validate([
            'school' => ['required', 'array'],
            'school.id' => ['nullable', 'integer', 'exists:schools,id'],
            'school.npsn' => [
                'nullable', 'string', 'max:20',
                Rule::unique('schools', 'npsn')->ignore($schoolId),
            ],
            'school.name' => ['required', 'string', 'max:150'],
            'school.jenjang' => ['required', Rule::in(['SD', 'SMP', 'SMA', 'SMK'])],
            'school.status' => ['required', Rule::in(['Negeri', 'Swasta'])],
            'school.accreditation' => ['nullable', 'string', 'max:5'],
            'school.foundation_id' => ['nullable', 'integer', 'exists:foundations,id'],
            'school.address' => ['nullable', 'string', 'max:5000'],
            'school.province_id' => ['nullable', 'string', 'max:2'],
            'school.city_id' => ['nullable', 'string', 'max:4'],
            'school.district_id' => ['nullable', 'string', 'max:7'],
            'school.village_id' => ['nullable', 'string', 'max:10'],
            'school.postal_code' => ['nullable', 'string', 'max:10'],
            'school.phone' => ['nullable', 'string', 'max:20'],
            'school.email' => ['nullable', 'email', 'max:100'],
            'school.website' => ['nullable', 'url', 'max:150'],
            'school.headmaster_name' => ['nullable', 'string', 'max:150'],
        ], [], [
            'school.name' => 'nama sekolah',
            'school.jenjang' => 'jenjang',
            'school.status' => 'status sekolah',
            'school.npsn' => 'NPSN',
            'school.email' => 'email sekolah',
            'school.website' => 'situs web',
        ]);

        $result = DB::transaction(function () use ($data, $schoolId): array {
            $school = $schoolId
                ? School::query()->findOrFail((int) $schoolId)
                : School::query()->where('is_active', true)->orderBy('id')->first() ?? new School();

            $school->fill($data['school']);
            $school->is_active = true;
            $school->save();

            return [
                'school' => $school->fresh('foundation:id,name'),
            ];
        });

        return $this->successResponse($result, 'Pengaturan umum berhasil disimpan');
    }
}
