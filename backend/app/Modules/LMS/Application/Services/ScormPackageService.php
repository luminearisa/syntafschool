<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Domain\Enums\ActivityStatus;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\ItemType;
use App\Modules\LMS\Domain\Enums\ScormPackageStatus;
use App\Modules\LMS\Domain\Enums\ScormVersion;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsScormOrganization;
use App\Modules\LMS\Models\LmsScormPackage;
use App\Modules\LMS\Models\LmsScormSco;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

/**
 * Impor paket SCORM 1.2 / 2004 (§12).
 *
 * Alur: `import()` → **validate** manifest & berkas → **extract** ke
 * penyimpanan → catat organisasi/SCO → `createActivity()` memasang item +
 * aktivitas bertipe SCORM pada kurikulum.
 *
 * Pelacakan **tidak** membuat mesin baru: nila dan ketuntasan tetap ditulis
 * lewat `ActivityGradeService` dan `CourseCompletionService` (lihat
 * `ScormTrackingService`).
 */
class ScormPackageService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly FileStorageProviderInterface $files,
        private readonly CourseContentService $content,
    ) {}

    /**
     * Impor paket SCORM dari unggahan.
     *
     * @return array{package:LmsScormPackage,validation:array<string,mixed>}
     */
    public function import(UploadedFile $file, LmsActor $actor, ?string $title = null): array
    {
        if (! $actor->can('lms.scorm.manage') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak berhak mengimpor paket SCORM.');
        }

        if (! class_exists(ZipArchive::class)) {
            LmsFault::invalid('Ekstensi zip PHP tidak tersedia pada server ini.');
        }

        $stored = $this->files->store($file, 'lms/scorm/packages', 'ADMIN_ONLY');
        $absolute = Storage::disk($this->files->disk())->path($stored['path']);

        $validation = $this->validate($absolute);

        $package = LmsScormPackage::query()->create([
            'title' => $title ?? ($validation['title'] ?? $file->getClientOriginalName()),
            'version' => ($validation['version'] ?? ScormVersion::UNKNOWN->value),
            'identifier' => $validation['identifier'] ?? null,
            'package_path' => $stored['path'],
            'storage_disk' => $stored['disk'],
            'status' => $validation['valid'] ? ScormPackageStatus::VALIDATED : ScormPackageStatus::INVALID,
            'manifest' => $validation['manifest'] ?? null,
            'validation' => $validation,
            'uploaded_by' => $actor->id,
        ]);

        if (! $validation['valid']) {
            $this->audit('LMS_SCORM_IMPORT_REJECTED', $package, ['errors' => $validation['errors']]);

            return ['package' => $package, 'validation' => $validation];
        }

        $extracted = $this->extract($absolute, (int) $package->id);

        $this->writeManifestRows($package, $validation['organizations'] ?? []);

        $package->fill([
            'manifest_path' => $extracted['manifest_path'],
            'status' => ScormPackageStatus::IMPORTED,
            'organizations_count' => count($validation['organizations'] ?? []),
            'sco_count' => LmsScormSco::query()->where('scorm_package_id', $package->id)->count(),
        ])->save();

        $this->audit('LMS_SCORM_IMPORTED', $package, [
            'version' => $package->version?->value,
            'sco_count' => (int) $package->sco_count,
            'files' => $extracted['files'],
        ]);

        return ['package' => $package->fresh(), 'validation' => $validation];
    }

    /**
     * Validasi manifest tanpa menyentuh basis data (§12: Validate Package).
     *
     * @return array<string, mixed>
     */
    public function validate(string $absolutePath): array
    {
        $errors = [];
        $warnings = [];

        if (! is_file($absolutePath)) {
            return $this->invalid('Berkas paket tidak ditemukan.');
        }

        $zip = new ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            return $this->invalid('Arsip tidak dapat dibuka (rusak atau bukan zip).');
        }

        try {
            $manifestRaw = $zip->getFromName('imsmanifest.xml');

            if ($manifestRaw === false || $manifestRaw === '') {
                return $this->invalid('Paket tidak memuat imsmanifest.xml.');
            }

            $manifest = $this->parseManifest($manifestRaw);

            if ($manifest === null) {
                return $this->invalid('imsmanifest.xml tidak dapat dibaca sebagai XML.');
            }

            $version = $this->detectVersion($manifest);
            $resources = $this->resources($manifest);
            $organizations = $this->organizations($manifest, $resources);

            if ($organizations === []) {
                $errors[] = 'Manifest tidak memuat organisasi (organization) dengan item.';
            }

            $scoCount = 0;
            foreach ($organizations as $organization) {
                $scoCount += count($organization['scoes']);
            }

            if ($scoCount === 0) {
                $errors[] = 'Manifest tidak memuat satu pun SCO yang dapat diluncurkan.';
            }

            // Setiap SCO harus menunjuk berkas yang benar-benar ada di arsip.
            foreach ($organizations as $organization) {
                foreach ($organization['scoes'] as $sco) {
                    $href = $sco['launch_path'];

                    if ($href === null || $zip->locateName($href) === false) {
                        $errors[] = 'Berkas peluncuran SCO "'.($sco['title'] ?? $sco['identifier']).'" tidak ada di dalam paket ('.$href.').';
                    }
                }
            }

            if ($version === ScormVersion::SCORM_12) {
                $warnings[] = 'SCORM 1.2 didukung; sebagian fitur 2004 (mis. completion_status) tidak tersedia.';
            }

            if ($version === ScormVersion::UNKNOWN) {
                $errors[] = 'Versi SCORM tidak dikenali (hanya 1.2 dan 2004 yang didukung).';
            }

            return [
                'valid' => $errors === [],
                'errors' => $errors,
                'warnings' => $warnings,
                'version' => $version->value,
                'identifier' => isset($manifest->identifier) ? (string) $manifest->identifier : null,
                'title' => $this->firstOrganizationTitle($manifest),
                'organizations' => $organizations,
                'manifest' => ['identifier' => isset($manifest->identifier) ? (string) $manifest->identifier : null, 'version' => (string) ($manifest->attributes()['version'] ?? '')],
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Pasang paket/SCO sebagai aktivitas pada kurikulum course (§12: Create Activity).
     *
     * @param  array<string, mixed>  $options  {title, description, is_required, completion_rule, due_at}
     */
    public function createActivity(
        LmsScormPackage $package,
        LmsCourse $course,
        LmsCourseSection $section,
        LmsActor $actor,
        ?LmsScormSco $sco = null,
        array $options = [],
    ): LmsActivity {
        if (! $package->isReady()) {
            LmsFault::invalid('Paket SCORM belum siap dipakai (status: '.($package->status?->value ?? '-').').');
        }

        $sco ??= LmsScormSco::query()->where('scorm_package_id', $package->id)->orderBy('id')->first();

        if ($sco === null) {
            LmsFault::invalid('Paket SCORM tidak memiliki SCO.');
        }

        $item = $this->content->createItem($section, [
            'title' => $options['title'] ?? $package->title,
            'description' => $options['description'] ?? null,
            'item_type' => ItemType::ACTIVITY->value,
            'activity_type' => ActivityType::SCORM->value,
            'visibility' => 'VISIBLE',
            'is_required' => $options['is_required'] ?? true,
            'completion_type' => $options['completion_rule'] ?? 'PASS',
            'completion_min_grade' => $options['completion_min_grade'] ?? null,
            'sort_order' => (int) $section->items()->max('sort_order') + 1,
        ], $actor);

        $activity = LmsActivity::query()->create([
            'learning_item_id' => $item->id,
            'course_id' => $course->id,
            'section_id' => $section->id,
            'activity_type' => ActivityType::SCORM->value,
            'title' => $options['title'] ?? $package->title,
            'description' => $options['description'] ?? null,
            'status' => $options['status'] ?? ActivityStatus::PUBLISHED->value,
            'due_at' => $options['due_at'] ?? null,
            'visibility' => 'VISIBLE',
            'completion_rule' => $options['completion_rule'] ?? 'PASS',
            'is_graded' => (bool) ($options['is_graded'] ?? true),
            'settings' => [
                'scorm_package_id' => (int) $package->id,
                'scorm_sco_id' => (int) $sco->id,
                'scorm_version' => $package->version?->value,
                'launch_path' => $sco->launch_path,
                'tracking_mode' => $options['tracking_mode'] ?? 'COMPLETION_AND_SCORE',
            ],
            'published_at' => now(),
            'created_by' => $actor->id,
        ]);

        $this->audit('LMS_SCORM_ACTIVITY_CREATED', $activity, [
            'course_id' => (int) $course->id,
            'package_id' => (int) $package->id,
            'sco_id' => (int) $sco->id,
        ]);

        return $activity;
    }

    /** @return list<array<string, mixed>> */
    public function packages(int $limit = 50): array
    {
        return LmsScormPackage::query()
            ->orderByDesc('id')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (LmsScormPackage $package) => $this->payload($package))
            ->all();
    }

    /** @return array<string, mixed> */
    public function payload(LmsScormPackage $package, bool $withScoes = true): array
    {
        return [
            'id' => (int) $package->id,
            'title' => $package->title,
            'version' => $package->version?->value,
            'version_label' => $package->version?->label(),
            'status' => $package->status?->value,
            'status_label' => $package->status?->label(),
            'tone' => $package->status?->tone(),
            'identifier' => $package->identifier,
            'organizations_count' => (int) $package->organizations_count,
            'sco_count' => (int) $package->sco_count,
            'validation' => $package->validation,
            'created_at' => $package->created_at?->toIso8601String(),
            'scoes' => $withScoes
                ? $package->scoes()->orderBy('id')->get()->map(fn (LmsScormSco $sco) => [
                    'id' => (int) $sco->id,
                    'identifier' => $sco->identifier,
                    'title' => $sco->title,
                    'launch_path' => $sco->launch_path,
                ])->all()
                : [],
        ];
    }

    /**
     * Ekstrak berkas paket ke penyimpanan LMS.
     *
     * @return array{files:int,manifest_path:string|null}
     */
    private function extract(string $absolutePath, int $packageId): array
    {
        $zip = new ZipArchive;
        $disk = Storage::disk($this->files->disk());
        $prefix = 'lms/scorm/packages/'.$packageId;
        $files = 0;
        $manifestPath = null;

        if ($zip->open($absolutePath) !== true) {
            LmsFault::invalid('Arsip paket SCORM tidak dapat dibuka.');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                // Tolak path traversal dari paket yang tidak tepercaya.
                if (str_contains($name, '..') || str_starts_with($name, '/')) {
                    continue;
                }

                if (str_ends_with($name, '/')) {
                    continue;
                }

                $contents = $zip->getFromIndex($index);

                if ($contents === false) {
                    continue;
                }

                $target = $prefix.'/'.ltrim($name, '/');
                $disk->put($target, $contents);
                $files++;

                if (strtolower(basename($name)) === 'imsmanifest.xml' && ! str_contains($name, '/')) {
                    $manifestPath = $target;
                }
            }
        } finally {
            $zip->close();
        }

        return ['files' => $files, 'manifest_path' => $manifestPath];
    }

    private function parseManifest(string $xml): ?SimpleXMLElement
    {
        try {
            $previous = libxml_use_internal_errors(true);
            $element = simplexml_load_string($xml);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $element === false ? null : $element;
        } catch (Throwable) {
            return null;
        }
    }

    private function detectVersion(SimpleXMLElement $manifest): ScormVersion
    {
        $attributes = $manifest->attributes();

        if (isset($attributes['version'])) {
            $version = strtoupper((string) $attributes['version']);

            if (str_contains($version, '2004') || str_contains($version, 'CAM')) {
                return ScormVersion::SCORM_2004;
            }

            if (str_contains($version, '1.2')) {
                return ScormVersion::SCORM_12;
            }
        }

        $schemaVersion = (string) ($manifest->metadata->schemaversion ?? '');

        if (str_contains($schemaVersion, '2004')) {
            return ScormVersion::SCORM_2004;
        }

        if (str_contains($schemaVersion, '1.2')) {
            return ScormVersion::SCORM_12;
        }

        // 1.2 tidak memakai namespace `adlcp` 2004; kehadiran `cmi.completion_status`
        // pada file manifest juga jadi penanda kuat versi 2004.
        $raw = $manifest->asXML() ?: '';

        return match (true) {
            str_contains($raw, 'imsss') || str_contains($raw, '2004') => ScormVersion::SCORM_2004,
            str_contains($raw, '1.2') => ScormVersion::SCORM_12,
            default => ScormVersion::UNKNOWN,
        };
    }

    /**
     * @return list<array{identifier:string,title:string|null,is_default:bool,scoes:list<array<string,mixed>>}>
     */
    private function organizations(SimpleXMLElement $manifest, array $resources = []): array
    {
        $organizations = [];
        $defaultId = trim((string) ($manifest->organizations->attributes()['default'] ?? ''));

        foreach ($manifest->organizations->organization ?? [] as $organization) {
            $identifier = trim((string) $organization->attributes()['identifier']);
            $title = trim((string) ($organization->title ?? ''));
            $scoes = [];
            $position = 0;

            foreach ($organization->item as $item) {
                $position++;
                $reference = trim((string) ($item->attributes()['identifierref'] ?? ''));

                $scoes[] = [
                    'identifier' => trim((string) $item->attributes()['identifier']),
                    'title' => trim((string) ($item->title ?? '')) ?: null,
                    // Berkas peluncuran berasal dari resource yang dirujuk item.
                    'launch_path' => $reference !== '' ? ($resources[$reference] ?? null) : null,
                    'position' => $position,
                ];
            }

            if ($scoes === []) {
                continue;
            }

            $organizations[] = [
                'identifier' => $identifier,
                'title' => $title !== '' ? $title : null,
                'is_default' => $defaultId !== '' ? $identifier === $defaultId : count($organizations) === 0,
                'scoes' => $scoes,
            ];
        }

        return $organizations;
    }

    /** @param list<array<string, mixed>> $organizations */
    private function writeManifestRows(LmsScormPackage $package, array $organizations): void
    {
        foreach ($organizations as $organization) {
            $row = LmsScormOrganization::query()->create([
                'scorm_package_id' => $package->id,
                'identifier' => (string) $organization['identifier'],
                'title' => $organization['title'],
                'is_default' => (bool) $organization['is_default'],
            ]);

            foreach ((array) $organization['scoes'] as $sco) {
                LmsScormSco::query()->create([
                    'scorm_package_id' => $package->id,
                    'organization_id' => $row->id,
                    'identifier' => (string) $sco['identifier'],
                    'title' => $sco['title'],
                    'launch_path' => $sco['launch_path'],
                    'scorm_type' => 'SCO',
                    'parameters' => ['position' => $sco['position'] ?? null],
                ]);
            }
        }
    }

    /**
     * Peta `identifier` resource → berkas peluncuran (§12).
     *
     * Item pada organisasi menunjuk `identifierref` *resource*, bukan berkas
     * langsung — tanpa peta ini, validasi akan mencari berkas bernama "RES-1".
     *
     * @return array<string, string|null>
     */
    private function resources(SimpleXMLElement $manifest): array
    {
        $map = [];

        foreach ($manifest->resources->resource ?? [] as $resource) {
            $identifier = trim((string) $resource->attributes()['identifier']);
            $href = trim((string) ($resource->attributes()['href'] ?? ''));

            $map[$identifier] = $href !== '' ? $href : null;
        }

        return $map;
    }

    private function firstOrganizationTitle(SimpleXMLElement $manifest): ?string
    {
        foreach ($manifest->organizations->organization ?? [] as $organization) {
            $title = trim((string) ($organization->title ?? ''));

            if ($title !== '') {
                return $title;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function invalid(string $message): array
    {
        return [
            'valid' => false,
            'errors' => [$message],
            'warnings' => [],
            'version' => ScormVersion::UNKNOWN->value,
            'identifier' => null,
            'title' => null,
            'organizations' => [],
            'manifest' => [],
        ];
    }
}
