<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

/**
 * Trait standar respons API Syntaf School.
 */
trait ApiResponse
{
    protected function successResponse(mixed $data = null, string $message = 'OK', int $code = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'message' => $message,
        ];

        if ($data instanceof ResourceCollection) {
            $response = $data->response()->getData(true);
            $payload['data'] = $response['data'] ?? [];
            if (isset($response['meta'])) {
                $payload['meta'] = $response['meta'];
            } elseif (isset($response['links'])) {
                $payload['meta'] = $response['links'];
            }

            return response()->json($payload, $code);
        }

        $payload['data'] = $data;

        if ($data instanceof AbstractPaginator) {
            $payload['data'] = $data->items();
            $payload['meta'] = [
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
            ];
        }

        return response()->json($payload, $code);
    }

    protected function createdResponse(mixed $data = null, string $message = 'Data berhasil dibuat'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    protected function errorResponse(string $message = 'Terjadi kesalahan', int $code = 400, mixed $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $code);
    }

    protected function validationErrorResponse(mixed $errors, string $message = 'Data yang diberikan tidak valid'): JsonResponse
    {
        return $this->errorResponse($message, 422, $errors);
    }
}
