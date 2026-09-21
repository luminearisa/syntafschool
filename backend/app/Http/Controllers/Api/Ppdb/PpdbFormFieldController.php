<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbFormFieldReorderRequest;
use App\Http\Requests\Ppdb\PpdbFormFieldRequest;
use App\Http\Resources\PpdbFormFieldResource;
use App\Models\PpdbFormField;
use App\Services\Ppdb\PpdbPeriodService;
use App\Services\Ppdb\PpdbScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Form builder pendaftaran.
 *
 * Susunan formulir adalah DATA: admin menentukan field, section, tipe, urutan,
 * dan validasinya. Tipe & section tidak dikirim sebagai hardcode frontend
 * melainkan lewat `meta` respons daftar (sumbernya konstanta model).
 */
class PpdbFormFieldController extends BaseApiController
{
    public function __construct(
        protected PpdbPeriodService $config,
        protected PpdbScopeService $scopes,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = PpdbFormField::query();

        if ($request->filled('period_id')) {
            $query->where('period_id', $request->integer('period_id'));
        }

        if ($request->filled('section') && in_array($request->query('section'), PpdbFormField::SECTIONS, true)) {
            $query->where('section', (string) $request->query('section'));
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        $fields = $this->config->paginated($request, $query->orderBy('section')->orderBy('order')->orderBy('id'));

        return $this->successResponse(
            PpdbFormFieldResource::collection($fields)->additional([
                'meta' => $this->meta(),
            ]),
            'Daftar field form berhasil diambil'
        );
    }

    public function store(PpdbFormFieldRequest $request): JsonResponse
    {
        // Disegarkan agar nilai bawaan kolom (active, required, order) terisi.
        $field = PpdbFormField::create($this->normalize($request->validated()))->refresh();

        return $this->createdResponse(
            new PpdbFormFieldResource($field),
            'Field form berhasil dibuat'
        );
    }

    public function show(PpdbFormField $form_field): JsonResponse
    {
        return $this->successResponse(
            new PpdbFormFieldResource($form_field),
            'Detail field form berhasil diambil'
        );
    }

    public function update(PpdbFormFieldRequest $request, PpdbFormField $form_field): JsonResponse
    {
        $form_field->fill($this->normalize($request->validated()));
        $form_field->save();

        return $this->successResponse(
            new PpdbFormFieldResource($form_field->refresh()),
            'Field form berhasil diperbarui'
        );
    }

    /**
     * Simpan urutan hasil susun ulang (drag & drop).
     *
     * Semua field harus milik satu periode — urutan lintas periode akan
     * menghasilkan formulir yang kacau.
     */
    public function reorder(PpdbFormFieldReorderRequest $request): JsonResponse
    {
        $orders = $request->orders();
        $fields = PpdbFormField::query()->whereIn('id', array_keys($orders))->get()->keyBy('id');

        $this->assertSamePeriod($fields, count($orders), $request->integer('period_id') ?: null);

        DB::transaction(function () use ($fields, $orders): void {
            foreach ($orders as $id => $order) {
                $field = $fields->get($id);

                if ($field !== null && $field->order !== $order) {
                    $field->update(['order' => $order]);
                }
            }
        });

        $ordered = PpdbFormField::query()
            ->whereIn('id', array_keys($orders))
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return $this->successResponse(
            PpdbFormFieldResource::collection($ordered),
            'Urutan field form berhasil disimpan'
        );
    }

    /**
     * Field boleh dihapus: jawaban lama tetap menyimpan `field_key`-nya sendiri,
     * jadi riwayat pendaftar tidak ikut hilang.
     */
    public function destroy(PpdbFormField $form_field): JsonResponse
    {
        $form_field->delete();

        return $this->successResponse(null, 'Field form berhasil dihapus');
    }

    /**
     * Pilihan/validasi dibakukan sebelum disimpan supaya jawaban dinamis
     * selalu menerima bentuk yang sama.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        if (array_key_exists('options', $data)) {
            $data['options'] = $this->scopes->normalizeOptions($data['options']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function meta(): array
    {
        return [
            'sections' => PpdbFormField::SECTIONS,
            'section_labels' => PpdbFormField::SECTION_LABELS,
            'types' => PpdbFormField::TYPES,
        ];
    }

    /**
     * Semua field yang diurutkan harus ada dan milik satu periode.
     *
     * @param  Collection<int, PpdbFormField>  $fields
     */
    private function assertSamePeriod(Collection $fields, int $requested, ?int $periodId): void
    {
        if ($fields->count() !== $requested) {
            throw ValidationException::withMessages(['items' => 'Ada field yang tidak ditemukan.']);
        }

        $periods = $fields->pluck('period_id')->unique();

        if ($periods->count() !== 1 || ($periodId !== null && (int) $periods->first() !== $periodId)) {
            throw ValidationException::withMessages([
                'items' => 'Field yang diurutkan harus berasal dari satu periode yang sama.',
            ]);
        }
    }
}
