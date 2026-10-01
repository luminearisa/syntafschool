<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Modules\Activity\Domain\Contracts\CertificateProviderInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * Penyiapan berkas sertifikat (§52).
 *
 * Sertifikat aktivitas dicetak dari template HTML yang dapat disesuaikan
 * sekolah; bila penyimpanan berkas tidak tersedia, jejak penerbitan tetap
 * tersimpan di basis data (nomor, kode verifikasi, tanggal) — jadi sertifikat
 * tidak pernah "hilang" hanya karena berkasnya gagal dibuat.
 */
class LocalCertificateProvider implements CertificateProviderInterface
{
    public function render(array $payload): array
    {
        $view = (string) config('activity.certificates.view', 'activity::certificate');

        try {
            if (! View::exists($view)) {
                return ['path' => null, 'reference' => null, 'status' => 'DB_ONLY'];
            }

            $contents = View::make($view, $payload)->render();
            $path = trim((string) config('activity.certificates.path', 'activity-certificates'), '/')
                .'/'.$payload['certificate_number'].'.html';

            Storage::disk((string) config('activity.documents.disk', 'public'))->put($path, $contents);

            return ['path' => $path, 'reference' => $path, 'status' => 'RENDERED'];
        } catch (\Throwable $exception) {
            return ['path' => null, 'reference' => null, 'status' => 'FAILED', 'reason' => $exception->getMessage()];
        }
    }

    public function revoke(string $path): bool
    {
        $disk = Storage::disk((string) config('activity.documents.disk', 'public'));

        return $path !== '' && $disk->exists($path) && $disk->delete($path);
    }
}
