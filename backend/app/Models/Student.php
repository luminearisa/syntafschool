<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends BaseModel
{
    use SoftDeletes;

    protected $casts = [
        'birth_date' => 'date',
        'admission_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class);
    }

    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StudentStatus::class, 'status_id');
    }

    /**
     * Program/peminatan siswa (IPA, IPS, Bahasa) — opsional.
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    public function residenceType(): BelongsTo
    {
        return $this->belongsTo(ResidenceType::class);
    }

    public function transportation(): BelongsTo
    {
        return $this->belongsTo(Transportation::class);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardians')
            ->withPivot(['guardian_relation_id', 'is_primary'])
            ->withTimestamps();
    }

    public function classStudents(): HasMany
    {
        return $this->hasMany(ClassStudent::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(StudentHistory::class)->orderByDesc('academic_year_id');
    }

    /**
     * Tagihan keuangan atas nama santri ini (§13). Dipakai mesin daftar ulang
     * untuk mengecek tunggakan (§4) dan portal keuangan orang tua.
     */
    public function billings(): HasMany
    {
        return $this->hasMany(FinanceBilling::class, 'student_id');
    }

    /**
     * Invoice keuangan atas nama santri ini (§16).
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(FinanceInvoice::class, 'student_id');
    }

    public function assignmentSubmissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    /**
     * Akun portal siswa (opsional).
     */
    public function user(): MorphOne
    {
        return $this->morphOne(User::class, 'userable');
    }

    /**
     * Wali utama siswa. Satu siswa hanya boleh punya satu wali utama,
     * aturan ini ditegakkan di StudentController.
     */
    public function primaryGuardian(): BelongsToMany
    {
        return $this->guardians()->wherePivot('is_primary', true);
    }

    /**
     * Keanggotaan rombel pada tahun ajaran tertentu.
     */
    public function classStudentFor(int $academicYearId): ?ClassStudent
    {
        return $this->classStudents()
            ->where('academic_year_id', $academicYearId)
            ->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Filter siswa yang terdaftar pada rombel tertentu.
     */
    public function scopeInClass(Builder $query, int $classId): Builder
    {
        return $query->whereHas('classStudents', fn ($q) => $q->where('school_class_id', $classId));
    }
}
