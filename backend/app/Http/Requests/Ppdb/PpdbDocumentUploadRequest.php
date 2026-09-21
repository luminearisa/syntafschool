<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbRegistration;
use App\Services\Ppdb\PpdbPortalAccess;
use App\Services\Ppdb\PpdbScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Unggah dokumen persyaratan dari portal.
 *
 * Batasan berkas (ekstensi, ukuran minimum & maksimum) diambil dari baris
 * `ppdb_document_requirements` milik persyaratan yang dituju — bukan hardcoded,
 * sehingga panitia cukup mengubah konfigurasi.
 */
class PpdbDocumentUploadRequest extends FormRequest
{
    /** Cache hasil resolusi scope supaya tidak dibaca dua kali per request. */
    private ?Collection $scopeCache = null;

    /**
     * Kepemilikan diperiksa SEBELUM aturan berkas: orang tua lain tidak boleh
     * diberi sinyal apa pun (bahkan 422) tentang persyaratan milik pendaftarannya.
     * `PpdbPortalAccess::registration()` menolak dengan 403 + pesan Indonesia
     * bila pendaftaran bukan miliknya atau akun pemanggil tidak aktif.
     */
    public function authorize(): bool
    {
        $registration = $this->registration();
        $user = $this->user();

        if ($registration === null || $user === null) {
            // Binding tidak ter-resolve / tidak ada user: controller yang menolak.
            return true;
        }

        app(PpdbPortalAccess::class)->registration($user, $registration);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'requirement_id' => ['required', 'integer', 'exists:ppdb_document_requirements,id'],
            'file' => ['required', 'file'],
        ];

        if ($this->registration() === null) {
            return $rules;
        }

        // Persyaratan harus berasal dari scope pendaftaran ini (periode + program
        // + gelombang), bukan sekadar ada di tabel konfigurasi.
        $rules['requirement_id'] = [
            'required',
            'integer',
            Rule::in($this->scopeIds()),
        ];

        $requirement = $this->requirement();

        if ($requirement === null) {
            return $rules;
        }

        $extensions = array_values(array_filter($requirement['allowed_extensions'] ?? []));
        $fileRules = ['required', 'file'];

        if ($extensions !== []) {
            $fileRules[] = 'mimes:'.implode(',', $extensions);
        }

        // Aturan max/min berkas dihitung dalam KILOBYTE (= kolom konfigurasi).
        if (! empty($requirement['max_size_kb'])) {
            $fileRules[] = 'max:'.(int) $requirement['max_size_kb'];
        }

        if (! empty($requirement['min_size_kb'])) {
            $fileRules[] = 'min:'.(int) $requirement['min_size_kb'];
        }

        $rules['file'] = $fileRules;

        return $rules;
    }

    /**
     * Persyaratan yang dipilih, bila ada di scope pendaftaran ini.
     *
     * @return array<string, mixed>|null
     */
    public function requirement(): ?array
    {
        $requirementId = (int) $this->input('requirement_id');

        if ($requirementId <= 0) {
            return null;
        }

        return $this->scopeRequirements()->first(
            static fn (array $requirement): bool => (int) $requirement['id'] === $requirementId
        );
    }

    /**
     * ID persyaratan yang berlaku untuk pendaftaran ini (periode + program +
     * gelombang). Kosong bila pendaftaran belum ter-resolve.
     *
     * @return list<int>
     */
    public function scopeIds(): array
    {
        return $this->scopeRequirements()->map(
            static fn (array $requirement): int => (int) $requirement['id']
        )->values()->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function scopeRequirements(): Collection
    {
        $registration = $this->registration();

        if ($registration === null) {
            return collect();
        }

        if ($this->scopeCache === null) {
            $this->scopeCache = app(PpdbScopeService::class)->documentRequirements(
                (int) $registration->period_id,
                $registration->program_id === null ? null : (int) $registration->program_id,
                $registration->wave_id === null ? null : (int) $registration->wave_id,
            );
        }

        return $this->scopeCache;
    }

    public function registration(): ?PpdbRegistration
    {
        $registration = $this->route('registration');

        return $registration instanceof PpdbRegistration ? $registration : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'file' => 'berkas dokumen',
            'requirement_id' => 'persyaratan dokumen',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $extensions = array_values(array_filter($this->requirement()['allowed_extensions'] ?? []));

        return [
            'requirement_id.required' => 'Pilih persyaratan dokumen yang akan diunggah.',
            'requirement_id.exists' => 'Persyaratan dokumen tersebut tidak ditemukan.',
            'requirement_id.in' => 'Persyaratan dokumen tersebut tidak berlaku untuk program dan gelombang '
                .'pendaftaran ini. Muat ulang halaman, lalu pilih persyaratan yang tersedia.',
            'file.required' => 'Berkas dokumen wajib diunggah.',
            'file.file' => 'Berkas yang diunggah tidak valid.',
            'file.mimes' => $extensions === []
                ? 'Format berkas tidak diizinkan.'
                : 'Format berkas harus salah satu dari: '.implode(', ', $extensions).'.',
            'file.max' => 'Ukuran berkas melebihi batas yang ditentukan.',
            'file.min' => 'Ukuran berkas terlalu kecil.',
        ];
    }
}
