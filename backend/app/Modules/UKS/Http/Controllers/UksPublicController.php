<?php

namespace App\Modules\UKS\Http\Controllers;

use App\Modules\UKS\Application\Services\LetterService;
use Illuminate\Http\JsonResponse;

/** Endpoint publik: pemeriksaan keaslian surat keterangan UKS. */
class UksPublicController extends UksController
{
    public function __construct(private readonly LetterService $letters)
    {
    }

    public function verify(string $code): JsonResponse
    {
        $result = $this->letters->verify($code);

        if ($result === null) {
            return $this->errorResponse('Surat dengan kode tersebut tidak ditemukan.', 404);
        }

        return $this->successResponse($result, 'Surat terverifikasi.');
    }
}
