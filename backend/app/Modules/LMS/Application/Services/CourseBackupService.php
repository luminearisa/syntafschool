<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Domain\Enums\ImportConflictStrategy;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCourse;
use Illuminate\Support\Facades\Storage;

/**
 * Backup & pemulihan course (§15, §40).
 *
 * Backup = ekspor penuh (struktur + soal + struktur nilai + capaian) yang
 * disimpan sebagai berkas. Restore = impor berkas itu menjadi **course baru**
 * atau ke course kosong. Karena itu backup tidak pernah menyentuh data siswa.
 *
 * Backup dianggap sah hanya setelah diuji: `verify()` memvalidasi berkas tanpa
 * menulis apa pun ke basis data.
 */
class CourseBackupService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly CourseExportService $exports,
        private readonly CourseImportService $imports,
        private readonly FileStorageProviderInterface $files,
    ) {}

    /**
     * Buat backup course.
     *
     * @return array{path:string,disk:string,size:int,counts:array<string,int>,checksum:string}
     */
    public function backup(LmsCourse $course, LmsActor $actor, ?string $label = null): array
    {
        if (! $actor->can('lms.course.export') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak berhak membuat backup course.');
        }

        $payload = $this->exports->export($course, $actor, ['include_content' => true, 'include_files' => true, 'include_activities' => true]);

        if ($label !== null) {
            $payload['backup_label'] = $label;
        }

        $stored = $this->exports->store($payload, $course, $actor);

        $this->audit('LMS_COURSE_BACKUP_CREATED', $course, ['path' => $stored['path'], 'size' => $stored['size']]);

        return [
            'path' => $stored['path'],
            'disk' => $stored['disk'],
            'size' => $stored['size'],
            'checksum' => $payload['integrity']['checksum'] ?? '',
            'counts' => $payload['integrity']['counts'] ?? [],
        ];
    }

    /**
     * Uji berkas backup tanpa mengubah apa pun (§40: Backup Verification).
     *
     * @return array{valid:bool,errors:list<string>,warnings:list<string>,summary:array<string,mixed>}
     */
    public function verify(string $path): array
    {
        $contents = $this->read($path);

        if ($contents === null) {
            return ['valid' => false, 'errors' => ['Berkas backup tidak ditemukan atau tidak dapat dibaca.'], 'warnings' => [], 'summary' => []];
        }

        $result = $this->imports->validate($contents);

        return [
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'summary' => $result['summary'],
        ];
    }

    /**
     * Pulihkan backup menjadi course baru (bawaan) atau ke course kosong.
     *
     * @return array<string, mixed>
     */
    public function restore(string $path, LmsActor $actor, ?int $targetCourseId = null, bool $replaceTarget = false): array
    {
        if (! $actor->can('lms.course.import') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak berhak memulihkan backup course.');
        }

        $contents = $this->read($path);

        if ($contents === null) {
            LmsFault::notFound('Berkas backup tidak ditemukan.');
        }

        $validation = $this->imports->validate($contents);

        if (! $validation['valid']) {
            return ['restored' => false, 'errors' => $validation['errors']];
        }

        $target = $targetCourseId !== null ? LmsCourse::query()->find($targetCourseId) : null;

        $result = $this->imports->import(
            payload: (array) $validation['payload'],
            actor: $actor,
            strategy: $replaceTarget ? ImportConflictStrategy::REPLACE : ImportConflictStrategy::DUPLICATE,
            target: $target,
            idempotencyKey: 'restore:'.md5($path.$path),
        );

        $this->audit('LMS_COURSE_RESTORED', $target, [
            'course_id' => $result['course_id'],
            'path' => $path,
            'strategy' => $replaceTarget ? 'REPLACE' : 'DUPLICATE',
        ]);

        return ['restored' => true, 'course_id' => $result['course_id'], 'summary' => $result['summary'], 'verification' => $result['verification']];
    }

    /**
     * Daftar berkas backup yang tersimpan.
     *
     * @return list<array{path:string,size:int,modified_at:string}>
     */
    public function backups(): array
    {
        $disk = Storage::disk($this->files->disk());
        $files = $disk->files('lms/exports/courses');

        return collect($files)
            ->filter(fn (string $file) => str_ends_with($file, '.json'))
            ->map(fn (string $file) => [
                'path' => $file,
                'size' => (int) $disk->size($file),
                'modified_at' => date('c', (int) $disk->lastModified($file)),
            ])
            ->sortByDesc('modified_at')
            ->values()
            ->all();
    }

    private function read(string $path): ?string
    {
        $disk = Storage::disk($this->files->disk());

        if (! $disk->exists($path)) {
            return null;
        }

        return $disk->get($path);
    }
}
