<?php

namespace App\Modules\UKS\Application\Services;

use App\Models\User;
use App\Modules\UKS\Application\Support\UksNumber;
use App\Modules\UKS\Domain\Enums\LetterType;
use App\Modules\UKS\Models\UksLetter;
use App\Modules\UKS\Models\UksVisit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Surat keterangan UKS (istirahat, sakit, rujukan) beserta berkas PDF-nya.
 *
 * Setiap surat membawa kode verifikasi 8 karakter yang dapat diperiksa siapa
 * saja lewat endpoint publik — sama seperti berkas modul Supervisi — sehingga
 * pihak luar dapat memastikan surat itu asli tanpa membuka data siswa lain.
 */
class LetterService
{
    public function __construct(private readonly UksAccessService $access)
    {
    }

    /** @param array<string, mixed> $filters */
    public function list(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = UksLetter::query()->with('student:id,full_name,nis');
        $this->access->scopeStudents($query, $actor);

        if (! empty($filters['student_id'])) {
            $query->where('student_id', (int) $filters['student_id']);
        }
        if (! empty($filters['letter_type'])) {
            $query->where('letter_type', $filters['letter_type']);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * @param array{student_id?: int, letter_type?: string, date_from: string, date_to: string, diagnosis_note?: ?string, recommendation?: ?string} $data
     */
    public function issue(array $data, User $actor, ?UksVisit $visit = null): UksLetter
    {
        $studentId = (int) ($data['student_id'] ?? $visit?->student_id);
        $this->access->assertCanSeeStudent($actor, $studentId);

        if ($data['date_to'] < $data['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
        }

        $type = LetterType::tryFrom((string) ($data['letter_type'] ?? LetterType::ISTIRAHAT->value)) ?? LetterType::ISTIRAHAT;

        return DB::transaction(function () use ($data, $actor, $visit, $studentId, $type): UksLetter {
            $letter = UksLetter::query()->create([
                'number' => UksNumber::next('uks-letter', 'SKU'),
                'visit_id' => $visit?->id,
                'student_id' => $studentId,
                'letter_type' => $type,
                'date_from' => $data['date_from'],
                'date_to' => $data['date_to'],
                'diagnosis_note' => $data['diagnosis_note'] ?? $visit?->complaint,
                'recommendation' => $data['recommendation'] ?? $visit?->advice,
                'verification_code' => UksNumber::verificationCode(),
                'issued_by' => $actor->id,
            ]);

            activity('uks')->causedBy($actor)->performedOn($letter)
                ->log('Surat '.$letter->number.' diterbitkan');

            return $letter;
        });
    }

    /** @return array{binary: string, filename: string} */
    public function pdf(User $actor, UksLetter $letter): array
    {
        $this->access->assertCanSeeLetter($actor, $letter);

        return $this->render($letter);
    }

    /** Pemeriksaan keaslian surat oleh pihak luar (tanpa data klinis). */
    public function verify(string $code): ?array
    {
        $letter = UksLetter::query()
            ->with('student:id,full_name,nis')
            ->where('verification_code', strtoupper(trim($code)))
            ->first();

        if ($letter === null) {
            return null;
        }

        return [
            'valid' => true,
            'number' => $letter->number,
            'letter_type' => $letter->letter_type->value,
            'letter_type_label' => $letter->letter_type->label(),
            'student_name' => $letter->student->full_name ?? null,
            'nis' => $letter->student->nis ?? null,
            'date_from' => $letter->date_from->toDateString(),
            'date_to' => $letter->date_to->toDateString(),
            'issued_at' => $letter->created_at?->toDateTimeString(),
        ];
    }

    /** @return array{binary: string, filename: string} */
    private function render(UksLetter $letter): array
    {
        $letter->loadMissing(['student:id,full_name,nis,nisn', 'issuedBy:id,name', 'visit:id,code,complaint']);

        $school = DB::table('schools')->first();

        $binary = Pdf::loadView('pdf.uks-letter', [
            'letter' => $letter,
            'schoolName' => $school->name ?? 'Sekolah',
            'schoolAddress' => $school->address ?? '',
        ])->setPaper('a4')->output();

        return [
            'binary' => $binary,
            'filename' => 'surat-uks-'.$letter->number.'.pdf',
        ];
    }
}
