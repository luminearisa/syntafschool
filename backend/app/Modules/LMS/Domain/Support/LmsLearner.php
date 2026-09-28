<?php

namespace App\Modules\LMS\Domain\Support;

/**
 * Siswa/peserta dalam bentuk netral terhadap sumber data.
 *
 * Integrated Mode: dipetakan dari `students` + `class_students`
 * Standalone Mode: dipetakan dari `lms_learners`
 */
final readonly class LmsLearner
{
    public function __construct(
        public int $id,
        public ?int $userId,
        public string $name,
        public ?string $code = null,
        public ?int $classId = null,
        public ?string $className = null,
        public ?int $programId = null,
        public ?string $programName = null,
        public ?int $academicYearId = null,
        public ?string $photoPath = null,
        public ?int $guardianId = null,
        public ?string $relation = null,
        public bool $isPrimaryGuardian = false,
    ) {}

    public function hasAccount(): bool
    {
        return $this->userId !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'name' => $this->name,
            'code' => $this->code,
            'class_id' => $this->classId,
            'class_name' => $this->className,
            'program_id' => $this->programId,
            'program_name' => $this->programName,
            'academic_year_id' => $this->academicYearId,
            'photo_path' => $this->photoPath,
            'guardian_id' => $this->guardianId,
            'relation' => $this->relation,
            'is_primary_guardian' => $this->isPrimaryGuardian,
        ];
    }
}
