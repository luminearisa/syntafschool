<?php

namespace App\Http\Requests\Boarding;

use App\Models\BoardingDormitory;
use App\Models\BoardingFloor;
use App\Models\BoardingRoom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Buat/perbarui kamar (§5, §7).
 *
 * `dormitory_id` pada tabel kamar adalah DENORMALISASI dari rantai
 * lantai → gedung → asrama. Bila klien mengirim `floor_id` tanpa
 * `dormitory_id`, nilai asrama diturunkan otomatis di sini; bila keduanya
 * dikirim dan bertentangan, controller menolak — jangan pernah membiarkan
 * kamar "milik asrama A" duduk di lantai gedung asrama B, karena seluruh
 * filter dan pemeriksaan gender membaca kolom redundan itu.
 */
class BoardingRoomRequest extends FormRequest
{
    /** Otorisasi ditegakkan per rute lewat `permission:room.manage`. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }

        if ($this->filled('floor_id') && ! $this->filled('dormitory_id')) {
            $floor = BoardingFloor::query()->with('building')->find((int) $this->input('floor_id'));

            if ($floor !== null && $floor->building !== null) {
                $this->merge(['dormitory_id' => $floor->building->dormitory_id]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var BoardingRoom|null $current */
        $current = $this->route('room');
        $dormitoryId = $this->input('dormitory_id') ?? $current?->dormitory_id;

        return [
            'floor_id' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'integer',
                Rule::exists(BoardingFloor::class, 'id'),
            ],
            'dormitory_id' => ['nullable', 'integer', Rule::exists(BoardingDormitory::class, 'id')],
            'code' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string', 'max:30', 'regex:/^[A-Z0-9\-_.]+$/',
                Rule::unique(BoardingRoom::class, 'code')
                    ->where(fn ($query) => $query->where('dormitory_id', (int) $dormitoryId)->whereNull('deleted_at'))
                    ->ignore($current?->id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:100'],
            'capacity' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'integer', 'min:1', 'max:1000',
            ],
            'gender' => ['nullable', Rule::in(BoardingRoom::GENDERS)],
            'room_type' => ['nullable', Rule::in(BoardingRoom::ROOM_TYPES)],
            'status' => ['nullable', Rule::in(BoardingRoom::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Saat membuat kamar baru, `floor_id` wajib punya gedung induk supaya
     * asrama dapat diturunkan — tanpa itu baris kamar akan menggantung tanpa
     * `dormitory_id`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->isMethod('post') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $floor = BoardingFloor::query()->with('building')->find((int) $this->input('floor_id'));

            if ($floor === null || $floor->building === null) {
                $validator->errors()->add('floor_id', 'Lantai terpilih belum terhubung ke gedung mana pun, sehingga asrama induk kamar tidak dapat ditentukan.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'floor_id' => 'lantai induk',
            'dormitory_id' => 'asrama induk',
            'code' => 'kode kamar',
            'name' => 'nama kamar',
            'capacity' => 'kapasitas kamar',
            'gender' => 'jenis penghuni',
            'room_type' => 'tipe kamar',
            'status' => 'status kamar',
            'description' => 'deskripsi',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'floor_id.required' => 'Kamar harus ditempatkan pada satu lantai.',
            'floor_id.exists' => 'Lantai induk tidak ditemukan.',
            'dormitory_id.exists' => 'Asrama induk tidak ditemukan.',
            'code.required' => 'Kode kamar wajib diisi, misalnya A101.',
            'code.regex' => 'Kode kamar hanya boleh berisi huruf, angka, tanda hubung, titik, atau garis bawah.',
            'code.unique' => 'Kode kamar ini sudah dipakai pada asrama yang sama.',
            'name.required' => 'Nama kamar wajib diisi.',
            'capacity.required' => 'Kapasitas kamar wajib diisi — jumlah maksimal penghuni.',
            'capacity.integer' => 'Kapasitas kamar harus berupa angka bulat.',
            'capacity.min' => 'Kapasitas kamar minimal satu penghuni.',
            'gender.in' => 'Jenis penghuni kamar harus salah satu dari: male, female, mixed.',
            'room_type.in' => 'Tipe kamar tidak dikenali (standard, premium, isolation, staff, temporary).',
            'status.in' => 'Status kamar harus salah satu dari: active, inactive, maintenance.',
        ];
    }
}
