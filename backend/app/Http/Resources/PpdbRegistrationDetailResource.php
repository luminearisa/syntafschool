<?php

namespace App\Http\Resources;

use App\Models\PpdbRegistration;
use Illuminate\Http\Request;

/**
 * Detail satu pendaftaran: baris dasar + skema formulir hidup, jawaban,
 * dokumen, progres, penghambat, dan aturan validasi.
 *
 * Isi `$detail` datang dari `PpdbRegistrationQueryService::detail()` (portal)
 * atau versi admin yang menambahkan riwayat/tagihan/seleksi.
 *
 * @property PpdbRegistration $resource
 */
class PpdbRegistrationDetailResource extends PpdbRegistrationResource
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(PpdbRegistration $resource, private readonly array $detail = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), $this->detail);
    }
}
