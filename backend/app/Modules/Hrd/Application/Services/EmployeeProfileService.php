<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeIdentifier;
use App\Modules\Hrd\Models\HrNote;
use App\Modules\Hrd\Models\HrReferenceItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Profil pegawai: identitas tambahan, kontak, alamat, kontak darurat,
 * tanggungan, dan catatan HR (§25–§27, §31, §75).
 *
 * Satu layanan untuk seluruh tabel anak profil, karena polanya sama: validasi
 * sesuai jenisnya, satu baris "utama" saja per jenis, jejak audit + linimasa, dan
 * pemeriksaan cakupan akses sebelum menyentuh data.
 *
 * Isi catatan HR (`visibility`) ditegakkan di sini — bukan di tampilan — sehingga
 * catatan kedisiplinan tidak pernah terkirim ke pemanggil yang tidak berhak.
 */
class EmployeeProfileService
{
    /** @var array<string, list<string>> */
    private const FIELDS = [
        'identifiers' => ['type_code', 'number', 'label', 'issued_date', 'expiry_date', 'is_primary', 'notes'],
        'contacts' => ['contact_type', 'value', 'label', 'is_primary', 'notes'],
        'addresses' => ['address_type', 'address', 'city', 'province', 'postal_code', 'country', 'is_primary', 'effective_from', 'effective_to'],
        'emergencyContacts' => ['name', 'relationship_code', 'phone', 'mobile', 'email', 'address', 'is_primary', 'priority', 'notes'],
        'dependents' => ['name', 'relationship_code', 'gender', 'birth_date', 'nik', 'education_level', 'occupation', 'dependent_status', 'is_beneficiary', 'notes'],
        'educations' => ['institution', 'level_code', 'degree', 'major', 'start_year', 'end_year', 'gpa', 'certificate_number', 'document_id', 'is_highest', 'notes'],
    ];

    private const RELATIONS = [
        'identifiers' => HrEmployeeIdentifier::class,
        'contacts' => \App\Modules\Hrd\Models\HrEmployeeContact::class,
        'addresses' => \App\Modules\Hrd\Models\HrEmployeeAddress::class,
        'emergencyContacts' => \App\Modules\Hrd\Models\HrEmergencyContact::class,
        'dependents' => \App\Modules\Hrd\Models\HrEmployeeDependent::class,
        'educations' => \App\Modules\Hrd\Models\HrEmployeeEducation::class,
    ];

    /** Jenis referensi yang dipakai identitas kontak darurat & tanggungan. */
    private const REFERENCE_GROUPS = [
        'emergencyContacts' => 'relationship_type',
        'dependents' => 'relationship_type',
        'educations' => 'education_level',
        'identifiers' => null,
    ];

    public function __construct(
        private readonly ReferenceService $references,
        private readonly HrdTimelineService $timeline,
        private readonly HrdAuditService $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(string $collection, HrEmployee $employee, array $attributes, User $actor): Model
    {
        $model = $this->modelFor($collection);

        $this->validate($collection, $attributes);
        $this->assertUniqueIdentifier($collection, $employee, $attributes);

        return DB::transaction(function () use ($collection, $model, $employee, $attributes, $actor): Model {
            $record = $model::query()->create([
                ...$this->only($collection, $attributes),
                'employee_id' => $employee->getKey(),
            ]);

            if (($attributes['is_primary'] ?? false) === true) {
                $this->clearPrimary($collection, $employee, $record);
            }

            $this->timeline->record($employee, $this->eventCode($collection, 'added'), $this->describe($collection, $record), [
                'category' => $this->categoryFor($collection),
                'source_type' => $record->getMorphClass(),
                'source_id' => $record->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
            ]);

            $this->audit->log($actor, "{$collection}.added", $record, null, $record->toArray(), null, null, (int) $employee->getKey());

            return $record;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(string $collection, HrEmployee $employee, Model $record, array $attributes, User $actor): Model
    {
        $this->assertBelongsTo($employee, $record);
        $this->validate($collection, [...$record->toArray(), ...$attributes]);

        return DB::transaction(function () use ($collection, $employee, $record, $attributes, $actor): Model {
            $before = $record->toArray();

            $record->fill($this->only($collection, $attributes))->save();

            if (($attributes['is_primary'] ?? false) === true) {
                $this->clearPrimary($collection, $employee, $record);
            }

            $this->audit->log($actor, "{$collection}.updated", $record, $before, $record->toArray(), null, null, (int) $employee->getKey());

            return $record->refresh();
        });
    }

    public function delete(string $collection, HrEmployee $employee, Model $record, User $actor, ?string $reason = null): void
    {
        $this->assertBelongsTo($employee, $record);

        DB::transaction(function () use ($collection, $employee, $record, $actor, $reason): void {
            $before = $record->toArray();

            $record->delete();

            $this->audit->log($actor, "{$collection}.deleted", $record, $before, null, $reason, null, (int) $employee->getKey());
        });
    }

    /** Seluruh profil pegawai dalam satu panggilan (dipakai tab profil). */
    public function profile(HrEmployee $employee, bool $canSeeSensitive = false): array
    {
        return [
            'identifiers' => $employee->identifiers()->get()->map(fn (HrEmployeeIdentifier $identifier) => [
                'id' => (int) $identifier->getKey(),
                'type_code' => $identifier->type_code,
                'number' => $canSeeSensitive ? $identifier->number : $identifier->maskedNumber(),
                'label' => $identifier->label,
                'issued_date' => $identifier->issued_date?->toDateString(),
                'expiry_date' => $identifier->expiry_date?->toDateString(),
                'is_primary' => (bool) $identifier->is_primary,
                'is_verified' => (bool) $identifier->is_verified,
            ])->all(),
            'contacts' => $employee->contacts()->get()->toArray(),
            'addresses' => $employee->addresses()->get()->toArray(),
            'emergency_contacts' => $employee->emergencyContacts()->orderByDesc('is_primary')->orderBy('priority')->get()->toArray(),
            'dependents' => $employee->dependents()->get()->toArray(),
            'educations' => $employee->educations()->orderByDesc('end_year')->get()->toArray(),
        ];
    }

    /* ── Catatan HR (§75 hr_notes) ──────────────────────────────────────── */

    /** @param array<string, mixed> $attributes */
    public function addNote(HrEmployee $employee, array $attributes, User $actor): HrNote
    {
        if (empty($attributes['body'])) {
            throw HrdValidationException::forField('body', 'Isi catatan wajib diisi.', 'hr.note.body_required');
        }

        $visibility = (string) ($attributes['visibility'] ?? 'hr');

        if (! in_array($visibility, ['hr', 'manager', 'employee', 'private'], true)) {
            throw HrdValidationException::forField('visibility', 'Tingkat kerahasiaan catatan tidak dikenal.', 'hr.note.visibility_invalid');
        }

        $note = HrNote::query()->create([
            'employee_id' => $employee->getKey(),
            'note_type' => $attributes['note_type'] ?? 'general',
            'visibility' => $visibility,
            'body' => $attributes['body'],
            'is_pinned' => (bool) ($attributes['is_pinned'] ?? false),
            'created_by' => $actor->getKey(),
        ]);

        $this->audit->log($actor, 'note.created', $note, null, ['visibility' => $visibility, 'note_type' => $note->note_type], null, null, (int) $employee->getKey());

        return $note;
    }

    /** @return \Illuminate\Support\Collection<int, HrNote> */
    public function notes(HrEmployee $employee, string $visibilityLevel = 'hr')
    {
        return HrNote::query()->where('employee_id', $employee->getKey())->visibleTo($visibilityLevel)->pinnedFirst()->get();
    }

    /* ── Bantu ──────────────────────────────────────────────────────────── */

    private function modelFor(string $collection): string
    {
        if (! isset(self::RELATIONS[$collection])) {
            throw HrdValidationException::forField('collection', "Bagian profil \"{$collection}\" tidak dikenal.", 'hr.profile.unknown_collection');
        }

        return self::RELATIONS[$collection];
    }

    /** @param array<string, mixed> $attributes */
    private function validate(string $collection, array $attributes): void
    {
        $group = self::REFERENCE_GROUPS[$collection] ?? null;

        if ($group !== null && isset($attributes['relationship_code'])) {
            $this->references->assertValid($group, $attributes['relationship_code'], 'relationship_code');
        }

        if ($group !== null && $collection === 'educations' && isset($attributes['level_code'])) {
            $this->references->assertValid('education_level', $attributes['level_code'], 'level_code');
        }

        if ($collection === 'contacts' && empty($attributes['value'])) {
            throw HrdValidationException::forField('value', 'Nilai kontak wajib diisi.', 'hr.contact.value_required');
        }

        if ($collection === 'addresses' && empty($attributes['address'])) {
            throw HrdValidationException::forField('address', 'Alamat wajib diisi.', 'hr.address.required');
        }

        if (in_array($collection, ['emergencyContacts', 'dependents'], true) && empty($attributes['name'])) {
            throw HrdValidationException::forField('name', 'Nama wajib diisi.', 'hr.profile.name_required');
        }

        if ($collection === 'educations' && ($attributes['end_year'] ?? 0) !== 0
            && ($attributes['start_year'] ?? 0) > ($attributes['end_year'] ?? 0)) {
            throw HrdValidationException::forField('end_year', 'Tahun selesai mendahului tahun mulai.', 'hr.education.invalid_years');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function assertUniqueIdentifier(string $collection, HrEmployee $employee, array $attributes): void
    {
        unset($employee);

        if ($collection !== 'identifiers' || empty($attributes['number'])) {
            return;
        }

        $existing = HrEmployeeIdentifier::query()
            ->where('type_code', $attributes['type_code'] ?? '')
            ->where('number', $attributes['number'])
            ->exists();

        if ($existing) {
            throw HrdValidationException::forField('number', 'Nomor identitas ini sudah tercatat pada pegawai lain.', 'hr.identifier.duplicate');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function only(string $collection, array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::FIELDS[$collection] ?? []));
    }

    private function clearPrimary(string $collection, HrEmployee $employee, Model $record): void
    {
        $model = $this->modelFor($collection);

        if (! in_array('is_primary', (new $model)->getFillable(), true) && ! array_key_exists('is_primary', $record->getAttributes())) {
            return;
        }

        $model::query()
            ->where('employee_id', $employee->getKey())
            ->whereKeyNot($record->getKey())
            ->update(['is_primary' => false]);
    }

    private function assertBelongsTo(HrEmployee $employee, Model $record): void
    {
        if ((int) $record->getAttribute('employee_id') !== (int) $employee->getKey()) {
            abort(404, 'Catatan profil tidak ditemukan pada pegawai ini.');
        }
    }

    private function eventCode(string $collection, string $action): string
    {
        return $collection.'.'.$action;
    }

    private function categoryFor(string $collection): string
    {
        return match ($collection) {
            'educations' => 'education',
            'contacts', 'addresses', 'emergencyContacts' => 'employment',
            default => 'lifecycle',
        };
    }

    private function describe(string $collection, Model $record): string
    {
        return match ($collection) {
            'identifiers' => 'Identitas ditambahkan: '.($record->getAttribute('type_code') ?? ''),
            'contacts' => 'Kontak ditambahkan: '.($record->getAttribute('value') ?? ''),
            'addresses' => 'Alamat ditambahkan',
            'emergencyContacts' => 'Kontak darurat: '.($record->getAttribute('name') ?? ''),
            'dependents' => 'Tanggungan ditambahkan: '.($record->getAttribute('name') ?? ''),
            'educations' => 'Pendidikan: '.($record->getAttribute('institution') ?? ''),
            default => 'Profil diperbarui',
        };
    }

    /** Label referensi untuk ditampilkan (mis. `spouse` → "Suami/Istri"). */
    public function referenceLabel(string $groupCode, ?string $code): ?string
    {
        return $this->references->label($groupCode, $code)
            ?? HrReferenceItem::query()->where('group_code', $groupCode)->where('code', $code)->value('label');
    }
}
