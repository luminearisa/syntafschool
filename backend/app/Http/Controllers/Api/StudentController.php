<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Student\StudentRequest;
use App\Http\Resources\StudentResource;
use App\Http\Resources\UserResource;
use App\Models\AcademicYear;
use App\Models\City;
use App\Models\ClassStudent;
use App\Models\District;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentHistory;
use App\Models\Village;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * A3 — Data Siswa.
 *
 * Menangani CRUD siswa, akun portal, relasi wali (satu wali utama per siswa),
 * rantai wilayah berjenjang, dan riwayat kelas per tahun ajaran.
 */
class StudentController extends BaseApiController
{
    /**
     * @var list<string>
     */
    protected array $filterable = [
        'school_id', 'status_id', 'gender_id', 'religion_id', 'is_active',
        'residence_type_id', 'transportation_id',
    ];

    /**
     * @var list<string>
     */
    protected array $with = [
        'gender', 'religion', 'status', 'school', 'residenceType', 'transportation',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Student::query()
            ->with($this->with)
            ->withCount('guardians');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nis', 'like', "%{$search}%")
                    ->orWhere('nisn', 'like', "%{$search}%")
                    ->orWhere('nick_name', 'like', "%{$search}%");
            });
        }

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        // Filter rombel: butuh tahun ajaran (default tahun ajaran aktif).
        if ($classId = $request->query('class_id')) {
            $query->inClass((int) $classId);
        } elseif ($academicYearId = $request->query('academic_year_id')) {
            $query->whereHas('classStudents', fn ($q) => $q->where('academic_year_id', (int) $academicYearId));
        } elseif ($request->boolean('unassigned')) {
            // Siswa yang belum punya rombel pada tahun ajaran aktif.
            $yearId = $this->activeAcademicYearId();
            if ($yearId) {
                $query->whereDoesntHave('classStudents', fn ($q) => $q->where('academic_year_id', $yearId));
            }
        }

        $query->orderBy('full_name');

        if ($request->boolean('all')) {
            return $this->successResponse(
                StudentResource::collection($query->get()),
                'Data siswa berhasil diambil',
            );
        }

        $paginator = $query->paginate((int) $request->query('per_page', 15));

        return $this->successResponse(
            StudentResource::collection($paginator),
            'Data siswa berhasil diambil',
        );
    }

    public function show(Student $student): JsonResponse
    {
        return $this->successResponse(
            new StudentResource($this->loadDetail($student)),
            'Detail siswa berhasil diambil',
        );
    }

    public function store(StudentRequest $request): JsonResponse
    {
        $payload = $this->payload($request);
        $this->assertRegionChain($payload);

        $guardians = (array) $request->validated('guardians', []);
        $this->assertSinglePrimary($guardians);

        $student = DB::transaction(function () use ($payload, $guardians) {
            $student = Student::create($payload);

            if ($guardians !== []) {
                $this->syncGuardians($student, $guardians);
            }

            return $student;
        });

        return $this->createdResponse(
            new StudentResource($this->loadDetail($student)),
            'Siswa berhasil dibuat',
        );
    }

    public function update(StudentRequest $request, Student $student): JsonResponse
    {
        $payload = $this->payload($request);
        $this->assertRegionChain($payload);

        $hasGuardians = $request->has('guardians');
        $guardians = (array) $request->validated('guardians', []);
        if ($hasGuardians) {
            $this->assertSinglePrimary($guardians);
        }

        DB::transaction(function () use ($student, $payload, $hasGuardians, $guardians) {
            $student->update($payload);

            if ($hasGuardians) {
                $this->syncGuardians($student, $guardians);
            }
        });

        return $this->successResponse(
            new StudentResource($this->loadDetail($student->refresh())),
            'Siswa berhasil diperbarui',
        );
    }

    public function destroy(Student $student): JsonResponse
    {
        $yearId = $this->activeAcademicYearId();

        if ($yearId && $student->classStudents()->where('academic_year_id', $yearId)->where('status', 'aktif')->exists()) {
            return $this->errorResponse(
                'Siswa masih terdaftar pada rombel tahun ajaran aktif. Keluarkan dari kelas terlebih dahulu.',
                422,
            );
        }

        $student->delete();

        return $this->successResponse(null, 'Siswa berhasil dihapus');
    }

    public function toggleActive(Student $student): JsonResponse
    {
        $student->update(['is_active' => ! $student->is_active]);

        return $this->successResponse(
            new StudentResource($this->loadDetail($student->refresh())),
            'Status siswa berhasil diubah',
        );
    }

    /**
     * Buat akun portal untuk siswa.
     */
    public function createAccount(Request $request, Student $student): JsonResponse
    {
        if ($student->user()->exists()) {
            return $this->errorResponse('Siswa ini sudah memiliki akun portal.', 422);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'username' => ['nullable', 'string', 'max:50', 'unique:users,username'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
        ], [], [
            'email' => 'email',
            'username' => 'username',
            'password' => 'password',
            'role' => 'role',
        ]);

        $user = DB::transaction(function () use ($student, $data) {
            $user = $student->user()->create([
                'name' => $student->full_name,
                'username' => $data['username'] ?? null,
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $student->phone,
                'is_active' => true,
            ]);

            $user->syncRoles([$data['role'] ?? 'siswa']);

            return $user;
        });

        return $this->createdResponse(
            new UserResource($user->load('roles')),
            'Akun portal siswa berhasil dibuat',
        );
    }

    // =================================================================
    // Wali murid
    // =================================================================

    /**
     * Tambahkan wali ke siswa. Boleh memilih wali yang sudah ada
     * (guardian_id) atau membuat wali baru sekaligus (full_name).
     */
    public function attachGuardian(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'full_name' => ['nullable', 'string', 'max:150'],
            'guardian_relation_id' => ['nullable', 'exists:guardian_relations,id'],
            'is_primary' => ['boolean'],
            'gender_id' => ['nullable', 'exists:genders,id'],
            'religion_id' => ['nullable', 'exists:religions,id'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'occupation_id' => ['nullable', 'exists:occupations,id'],
            'income_range_id' => ['nullable', 'exists:income_ranges,id'],
            'education_background_id' => ['nullable', 'exists:education_backgrounds,id'],
        ], [], [
            'guardian_id' => 'wali',
            'full_name' => 'nama wali',
            'guardian_relation_id' => 'hubungan wali',
            'is_primary' => 'wali utama',
        ]);

        if (empty($data['guardian_id']) && empty($data['full_name'])) {
            throw ValidationException::withMessages([
                'guardian_id' => 'Pilih wali yang sudah ada atau isi nama wali baru.',
            ]);
        }

        $isPrimary = (bool) ($data['is_primary'] ?? false);

        DB::transaction(function () use ($student, $data, $isPrimary) {
            $guardian = ! empty($data['guardian_id'])
                ? Guardian::findOrFail($data['guardian_id'])
                : Guardian::create($this->guardianAttributes($data));

            if ($student->guardians()->whereKey($guardian->id)->exists()) {
                throw ValidationException::withMessages([
                    'guardian_id' => 'Wali ini sudah terhubung dengan siswa tersebut.',
                ]);
            }

            if ($isPrimary) {
                $this->clearPrimary($student);
            }

            $student->guardians()->attach($guardian->id, [
                'guardian_relation_id' => $data['guardian_relation_id'] ?? null,
                'is_primary' => $isPrimary || ! $student->guardians()->exists(),
            ]);
        });

        return $this->createdResponse(
            new StudentResource($this->loadDetail($student->refresh())),
            'Wali berhasil ditambahkan',
        );
    }

    /**
     * Ubah hubungan / status wali utama pada relasi siswa–wali.
     */
    public function updateGuardian(Request $request, Student $student, Guardian $guardian): JsonResponse
    {
        $data = $request->validate([
            'guardian_relation_id' => ['nullable', 'exists:guardian_relations,id'],
            'is_primary' => ['required', 'boolean'],
        ], [], [
            'guardian_relation_id' => 'hubungan wali',
            'is_primary' => 'wali utama',
        ]);

        if (! $student->guardians()->whereKey($guardian->id)->exists()) {
            return $this->errorResponse('Wali ini tidak terhubung dengan siswa tersebut.', 404);
        }

        DB::transaction(function () use ($student, $guardian, $data) {
            if ($data['is_primary']) {
                $this->clearPrimary($student, $guardian->id);
            }

            $student->guardians()->updateExistingPivot($guardian->id, [
                'guardian_relation_id' => $data['guardian_relation_id'] ?? null,
                'is_primary' => (bool) $data['is_primary'],
            ]);
        });

        return $this->successResponse(
            new StudentResource($this->loadDetail($student->refresh())),
            'Relasi wali berhasil diperbarui',
        );
    }

    public function detachGuardian(Student $student, Guardian $guardian): JsonResponse
    {
        if (! $student->guardians()->whereKey($guardian->id)->exists()) {
            return $this->errorResponse('Wali ini tidak terhubung dengan siswa tersebut.', 404);
        }

        $wasPrimary = (bool) $student->guardians()->whereKey($guardian->id)->first()?->pivot->is_primary;

        DB::transaction(function () use ($student, $guardian, $wasPrimary) {
            $student->guardians()->detach($guardian->id);

            // Jangan biarkan siswa tanpa wali utama bila masih ada wali lain.
            if ($wasPrimary) {
                $next = $student->guardians()->first();
                if ($next) {
                    $student->guardians()->updateExistingPivot($next->id, ['is_primary' => true]);
                }
            }
        });

        return $this->successResponse(
            new StudentResource($this->loadDetail($student->refresh())),
            'Wali berhasil dilepas dari siswa',
        );
    }

    // =================================================================
    // Riwayat kelas
    // =================================================================

    public function histories(Student $student): JsonResponse
    {
        $items = $student->histories()
            ->with(['academicYear', 'schoolClass', 'educationLevel'])
            ->get()
            ->map(fn (StudentHistory $h) => [
                'id' => $h->id,
                'academic_year_id' => $h->academic_year_id,
                'academic_year' => $h->academicYear?->name,
                'school_class_id' => $h->school_class_id,
                'class_name' => $h->schoolClass?->name,
                'education_level' => $h->educationLevel?->name,
                'status' => $h->status,
                'note' => $h->note,
            ]);

        return $this->successResponse($items, 'Riwayat kelas siswa berhasil diambil');
    }

    /**
     * Simpan (buat/perbarui) riwayat kelas siswa untuk satu tahun ajaran.
     * Bila kelas diisi, keanggotaan rombel (class_students) ikut disinkronkan.
     */
    public function storeHistory(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'status' => ['required', 'in:'.implode(',', StudentHistory::STATUSES)],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'academic_year_id' => 'tahun ajaran',
            'school_class_id' => 'kelas',
            'status' => 'status',
            'note' => 'catatan',
        ]);

        $class = null;
        if (! empty($data['school_class_id'])) {
            $class = SchoolClass::find($data['school_class_id']);

            if ($class && $class->academic_year_id !== (int) $data['academic_year_id']) {
                throw ValidationException::withMessages([
                    'school_class_id' => 'Kelas tersebut bukan milik tahun ajaran yang dipilih.',
                ]);
            }
        }

        $history = DB::transaction(function () use ($student, $data, $class) {
            $history = StudentHistory::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'academic_year_id' => $data['academic_year_id'],
                ],
                [
                    'school_class_id' => $data['school_class_id'] ?? null,
                    'education_level_id' => $class?->education_level_id,
                    'status' => $data['status'],
                    'note' => $data['note'] ?? null,
                    'created_by' => auth()->id(),
                ],
            );

            if ($class) {
                ClassStudent::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'academic_year_id' => $data['academic_year_id'],
                    ],
                    [
                        'school_class_id' => $class->id,
                        'status' => match ($data['status']) {
                            'pindah' => 'pindah',
                            'keluar' => 'keluar',
                            default => 'aktif',
                        },
                    ],
                );
            }

            return $history;
        });

        return $this->createdResponse([
            'id' => $history->id,
            'academic_year_id' => $history->academic_year_id,
            'school_class_id' => $history->school_class_id,
            'status' => $history->status,
            'note' => $history->note,
        ], 'Riwayat kelas berhasil disimpan');
    }

    public function destroyHistory(Student $student, StudentHistory $history): JsonResponse
    {
        if ($history->student_id !== $student->id) {
            return $this->errorResponse('Riwayat ini bukan milik siswa tersebut.', 404);
        }

        DB::transaction(function () use ($student, $history) {
            // storeHistory() membuat riwayat sekaligus keanggotaan rombel, maka
            // penghapusannya pun harus simetris. Tanpa ini, keanggotaan rombel
            // menjadi yatim dan siswa tidak akan pernah bisa dihapus.
            if ($history->school_class_id) {
                ClassStudent::where('student_id', $student->id)
                    ->where('academic_year_id', $history->academic_year_id)
                    ->delete();
            }

            $history->delete();
        });

        return $this->successResponse(null, 'Riwayat kelas berhasil dihapus');
    }

    // =================================================================
    // Internal
    // =================================================================

    /**
     * @return array<string, mixed>
     */
    protected function payload(StudentRequest $request): array
    {
        return [
            'school_id' => $request->validated('school_id'),
            'nis' => $request->validated('nis'),
            'nisn' => $request->validated('nisn'),
            'full_name' => $request->validated('full_name'),
            'nick_name' => $request->validated('nick_name'),
            'gender_id' => $request->validated('gender_id'),
            'religion_id' => $request->validated('religion_id'),
            'birth_place' => $request->validated('birth_place'),
            'birth_date' => $request->validated('birth_date'),
            'blood_type' => $request->validated('blood_type'),
            'address' => $request->validated('address'),
            'province_id' => $request->validated('province_id'),
            'city_id' => $request->validated('city_id'),
            'district_id' => $request->validated('district_id'),
            'village_id' => $request->validated('village_id'),
            'postal_code' => $request->validated('postal_code') ?? $this->postalCodeFromVillage($request),
            'phone' => $request->validated('phone'),
            'email' => $request->validated('email'),
            'residence_type_id' => $request->validated('residence_type_id'),
            'transportation_id' => $request->validated('transportation_id'),
            'child_order' => $request->validated('child_order'),
            'sibling_count' => $request->validated('sibling_count'),
            'previous_school' => $request->validated('previous_school'),
            'admission_date' => $request->validated('admission_date'),
            'status_id' => $request->validated('status_id'),
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    /**
     * Ambil kode pos dari desa terpilih bila pengguna tidak mengisinya.
     */
    private function postalCodeFromVillage(StudentRequest $request): ?string
    {
        $villageId = $request->validated('village_id');

        if (! $villageId) {
            return null;
        }

        return Village::whereKey($villageId)->value('postal_code');
    }

    /**
     * Pastikan rantai wilayah konsisten:
     * kota milik provinsi, kecamatan milik kota, desa milik kecamatan.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertRegionChain(array $payload): void
    {
        if ($payload['city_id'] ?? null) {
            $provinceId = City::whereKey($payload['city_id'])->value('province_id');
            if ($provinceId && ($payload['province_id'] ?? null) && $provinceId !== $payload['province_id']) {
                throw ValidationException::withMessages([
                    'city_id' => 'Kabupaten/kota tidak berada di provinsi yang dipilih.',
                ]);
            }
        }

        if ($payload['district_id'] ?? null) {
            $cityId = District::whereKey($payload['district_id'])->value('city_id');
            if ($cityId && ($payload['city_id'] ?? null) && $cityId !== $payload['city_id']) {
                throw ValidationException::withMessages([
                    'district_id' => 'Kecamatan tidak berada di kabupaten/kota yang dipilih.',
                ]);
            }
        }

        if ($payload['village_id'] ?? null) {
            $districtId = Village::whereKey($payload['village_id'])->value('district_id');
            if ($districtId && ($payload['district_id'] ?? null) && $districtId !== $payload['district_id']) {
                throw ValidationException::withMessages([
                    'village_id' => 'Desa/kelurahan tidak berada di kecamatan yang dipilih.',
                ]);
            }
        }
    }

    /**
     * Satu siswa hanya boleh punya satu wali utama.
     *
     * @param  array<int, array<string, mixed>>  $guardians
     */
    private function assertSinglePrimary(array $guardians): void
    {
        $primaryCount = collect($guardians)->filter(fn ($g) => (bool) ($g['is_primary'] ?? false))->count();

        if ($primaryCount > 1) {
            throw ValidationException::withMessages([
                'guardians' => 'Hanya satu wali yang boleh ditandai sebagai wali utama.',
            ]);
        }
    }

    /**
     * Sinkronkan daftar wali siswa dari payload form siswa.
     *
     * @param  array<int, array<string, mixed>>  $guardians
     */
    private function syncGuardians(Student $student, array $guardians): void
    {
        if ($guardians === []) {
            $student->guardians()->detach();

            return;
        }

        $sync = [];
        $hasPrimary = collect($guardians)->contains(fn ($g) => (bool) ($g['is_primary'] ?? false));

        foreach (array_values($guardians) as $index => $row) {
            $guardian = ! empty($row['guardian_id'])
                ? Guardian::find($row['guardian_id'])
                : Guardian::create($this->guardianAttributes($row));

            if (! $guardian) {
                continue;
            }

            $sync[$guardian->id] = [
                'guardian_relation_id' => $row['guardian_relation_id'] ?? null,
                // Wali pertama otomatis jadi wali utama bila tidak ada yang ditandai.
                'is_primary' => (bool) ($row['is_primary'] ?? false) || (! $hasPrimary && $index === 0),
            ];
        }

        $student->guardians()->sync($sync);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function guardianAttributes(array $data): array
    {
        return [
            'full_name' => $data['full_name'] ?? null,
            'gender_id' => $data['gender_id'] ?? null,
            'religion_id' => $data['religion_id'] ?? null,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'occupation_id' => $data['occupation_id'] ?? null,
            'income_range_id' => $data['income_range_id'] ?? null,
            'education_background_id' => $data['education_background_id'] ?? null,
        ];
    }

    /**
     * Lepas status wali utama dari wali lain milik siswa ini.
     */
    private function clearPrimary(Student $student, ?int $exceptGuardianId = null): void
    {
        $query = DB::table('student_guardians')
            ->where('student_id', $student->id)
            ->where('is_primary', true);

        if ($exceptGuardianId) {
            $query->where('guardian_id', '!=', $exceptGuardianId);
        }

        $query->update(['is_primary' => false]);
    }

    private function activeAcademicYearId(): ?int
    {
        return AcademicYear::where('is_active', true)->value('id')
            ?? AcademicYear::query()->orderByDesc('id')->value('id');
    }

    /**
     * Muat relasi lengkap untuk halaman detail / setelah mutasi.
     */
    private function loadDetail(Student $student): Student
    {
        $yearId = $this->activeAcademicYearId();

        return $student->load([
            ...$this->with,
            // Wilayah berjenjang hanya dimuat di detail agar daftar tetap ringan.
            'province',
            'city',
            'district',
            'village',
            'guardians' => fn ($q) => $q->with('occupation'),
            'histories.academicYear',
            'histories.schoolClass',
            'user',
            'classStudents' => fn ($q) => $yearId
                ? $q->where('academic_year_id', $yearId)
                : $q,
            'classStudents.schoolClass',
        ])->loadCount('guardians');
    }
}
