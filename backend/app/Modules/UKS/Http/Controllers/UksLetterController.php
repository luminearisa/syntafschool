<?php

namespace App\Modules\UKS\Http\Controllers;

use App\Modules\UKS\Application\Services\LetterService;
use App\Modules\UKS\Domain\Enums\LetterType;
use App\Modules\UKS\Models\UksLetter;
use App\Modules\UKS\Models\UksVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UksLetterController extends UksController
{
    public function __construct(private readonly LetterService $letters)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'student_id' => ['nullable', 'integer'],
            'letter_type' => ['nullable', Rule::enum(LetterType::class)],
        ]);

        return $this->successResponse(
            $this->letters->list($request->user(), $filters, $this->perPage($request)),
            'Daftar surat keterangan.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $visit = isset($data['visit_id']) ? UksVisit::query()->find($data['visit_id']) : null;

        return $this->createdResponse(
            $this->letters->issue($data, $request->user(), $visit),
            'Surat keterangan diterbitkan.',
        );
    }

    public function download(Request $request, UksLetter $letter): StreamedResponse
    {
        $pdf = $this->letters->pdf($request->user(), $letter);

        return response()->streamDownload(
            function () use ($pdf): void {
                echo $pdf['binary'];
            },
            preg_replace('/[^A-Za-z0-9._-]+/', '-', $pdf['filename']) ?: 'surat-uks.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'visit_id' => ['nullable', 'integer', 'exists:uks_visits,id'],
            'student_id' => ['required_without:visit_id', 'integer', 'exists:students,id'],
            'letter_type' => ['nullable', Rule::enum(LetterType::class)],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'diagnosis_note' => ['nullable', 'string', 'max:1000'],
            'recommendation' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
