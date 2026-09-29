<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Domain\Enums\ResourceType;
use App\Modules\LMS\Models\LmsResource;

/**
 * Validasi materi/resource (§17).
 *
 * Materi rusak (berkas hilang, tautan kosong, halaman tanpa isi) adalah
 * penyebab paling sering "course tidak bisa dibuka" — jadi diperiksa sebelum
 * terbit, bukan setelah siswa mengeluh.
 */
class ResourceValidator
{
    public function __construct(private readonly FileStorageProviderInterface $files) {}

    public function validate(LmsResource $resource): ValidationReport
    {
        $report = new ValidationReport;

        $item = $resource->item;
        $type = $resource->resource_type ?? $item?->resource_type;

        if ($type === null) {
            $report->error('resource.type_missing', 'Jenis materi belum ditentukan.', 'resource', (int) $resource->id);

            return $report;
        }

        $typeValue = $type instanceof ResourceType ? $type->value : (string) $type;

        switch ($typeValue) {
            case ResourceType::PAGE->value:
            case ResourceType::TEXT->value:
                if (! filled(strip_tags((string) $resource->content))) {
                    $report->error(
                        'resource.content_empty',
                        'Materi "'.$item?->title.'" belum memiliki isi.',
                        'resource',
                        (int) $resource->id,
                        'Tulis isi materi atau unggah berkas.',
                    );
                }

                break;

            case ResourceType::URL->value:
                $url = (string) $resource->external_url;

                if (! filled($url)) {
                    $report->error('resource.url_missing', 'Materi tautan belum memiliki alamat.', 'resource', (int) $resource->id);
                } elseif (! filter_var($url, FILTER_VALIDATE_URL)) {
                    $report->error('resource.url_invalid', 'Alamat tautan materi tidak valid: '.mb_strimwidth($url, 0, 60, '…'), 'resource', (int) $resource->id);
                } elseif (! str_starts_with($url, 'https://')) {
                    $report->warning('resource.url_insecure', 'Tautan materi tidak memakai HTTPS; sebagian browser akan memblokirnya.', 'resource', (int) $resource->id);
                }

                break;

            case ResourceType::VIDEO->value:
            case ResourceType::AUDIO->value:
            case ResourceType::FILE->value:
            case ResourceType::BOOK->value:
                if ($resource->media_source === 'EXTERNAL' || filled($resource->external_url)) {
                    if (! filter_var((string) $resource->external_url, FILTER_VALIDATE_URL)) {
                        $report->error('resource.media_url_invalid', 'Sumber media eksternal tidak valid.', 'resource', (int) $resource->id);
                    }

                    break;
                }

                if ($typeValue === ResourceType::BOOK->value) {
                    $chapters = $resource->item?->chapters()->count() ?? 0;

                    if ($chapters === 0) {
                        $report->error('resource.book_without_chapter', 'Materi buku belum memiliki bab.', 'resource', (int) $resource->id);
                    }

                    break;
                }

                if (! filled($resource->file_path)) {
                    $report->error('resource.file_missing', 'Berkas materi belum diunggah.', 'resource', (int) $resource->id);
                } elseif (! $this->files->exists((string) $resource->file_path)) {
                    $report->error(
                        'resource.file_not_found',
                        'Berkas materi tidak ditemukan pada penyimpanan: '.$resource->file_path,
                        'resource',
                        (int) $resource->id,
                        'Unggah ulang berkas atau ganti sumbernya.',
                    );
                }

                break;

            case ResourceType::FOLDER->value:
                $children = $resource->item?->children()->count() ?? 0;

                if ($children === 0) {
                    $report->warning('resource.folder_empty', 'Folder materi belum berisi apa pun.', 'resource', (int) $resource->id);
                }

                break;
        }

        return $report;
    }
}
