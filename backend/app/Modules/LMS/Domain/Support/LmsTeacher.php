<?php

namespace App\Modules\LMS\Domain\Support;

/**
 * Guru dalam bentuk netral terhadap sumber data.
 *
 * Integrated Mode: `employees` (is_teacher) + akun `users`
 * Standalone Mode: `lms_learners` bertipe teacher
 */
final readonly class LmsTeacher
{
    public function __construct(
        public int $id,
        public ?int $userId,
        public string $name,
        public ?string $email = null,
        public ?string $nip = null,
        public ?string $photoPath = null,
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
            'email' => $this->email,
            'nip' => $this->nip,
            'photo_path' => $this->photoPath,
        ];
    }
}
