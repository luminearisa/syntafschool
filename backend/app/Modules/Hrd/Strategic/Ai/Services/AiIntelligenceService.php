<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Services;

use App\Modules\Hrd\Strategic\Ai\Models\HrAiKnowledgeChunk;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiKnowledgeDocument;
use App\Modules\Hrd\Strategic\Analytics\Services\MetricEngine;
use App\Modules\Hrd\Strategic\Analytics\Models\HrSemanticEntity;
use App\Modules\Hrd\Strategic\Analytics\Models\HrSemanticField;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lapisan semantik & asisten pengetahuan (§75, §122, §123).
 *
 *  - Natural language → rencana query → otorisasi → data → tabel. Tidak pernah
 *    mengeksekusi SQL bebas dari LLM: hanya entitas & kolom yang terdaftar.
 *  - Knowledge Assistant menjawab kebijakan HR dengan sitasi; tanpa sumber,
 *    ia menolak menjawab.
 */
class AiIntelligenceService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly MetricEngine $metrics,
    ) {}

    /**
     * Daftar entitas semantik yang boleh diakses AI/analitik.
     *
     * @return array<string, mixed>
     */
    public function semanticEntities(): array
    {
        return [
            'entities' => HrSemanticEntity::query()->where('is_active', true)->get()
                ->map(fn (HrSemanticEntity $entity): array => [
                    'code' => $entity->code, 'name' => $entity->name, 'source_model' => $entity->source_model,
                    'filters' => $entity->allowed_filters, 'dimensions' => $entity->allowed_dimensions,
                    'sensitivity' => $entity->sensitivity, 'permission' => $entity->requires_permission,
                ])->all(),
            'fields' => HrSemanticField::query()->count(),
            'whitelist_tables' => count($this->metrics->semanticWhitelist()),
        ];
    }

    /**
     * Natural language report builder (§122): pertanyaan → rencana → izin → data.
     *
     * @return array<string, mixed>
     */
    public function query(string $naturalLanguage, mixed $user, array $extraFilters = []): array
    {
        $text = mb_strtolower(trim($naturalLanguage));
        if ($text === '') {
            return ['status' => 'REFUSED', 'reason' => 'Pertanyaan kosong.'];
        }

        $plan = $this->buildPlan($text, $extraFilters, $user);
        if ($plan['entity'] === null) {
            return [
                'status' => 'REFUSED',
                'question' => $naturalLanguage,
                'reason' => 'Tidak ada entitas semantik yang cocok. AI tidak menebak tabel/kolom.',
                'available_entities' => HrSemanticEntity::query()->where('is_active', true)->pluck('code')->all(),
            ];
        }

        $entity = HrSemanticEntity::query()->where('code', $plan['entity'])->first();
        if ($entity?->requires_permission && ! $this->ctx->can($user, (string) $entity->requires_permission)) {
            return [
                'status' => 'DENIED', 'question' => $naturalLanguage, 'entity' => $entity->code,
                'reason' => 'PERMISSION_DITOLAK:'.$entity->requires_permission,
            ];
        }

        $table = $this->metrics->entityTable((string) $plan['entity']);
        if (! $table || ! Schema::hasTable($table)) {
            return ['status' => 'UNAVAILABLE', 'entity' => $plan['entity'], 'reason' => 'Sumber data belum tersedia.'];
        }

        $whitelist = $this->metrics->semanticWhitelist();
        $columns = array_values(array_intersect($plan['select'], $whitelist[$table] ?? []));
        if ($columns === []) {
            $columns = array_slice($whitelist[$table] ?? ['id'], 0, 5);
        }

        $query = DB::table($table)->select($columns);
        $appliedFilters = [];
        foreach ($plan['filters'] as $column => $value) {
            if (! in_array($column, $whitelist[$table] ?? [], true)) {
                continue;
            }
            $query->where($column, $value);
            $appliedFilters[$column] = $value;
        }

        $scope = $this->ctx->scopeOf($user, true);
        if ($scope['type'] === 'SELF' && $scope['employee_id'] && in_array('employee_id', $whitelist[$table] ?? [], true)) {
            $query->where('employee_id', $scope['employee_id']);
            $appliedFilters['employee_id'] = $scope['employee_id'];
        }

        $sourceTable = $table;
        if ($plan['groupBy'] && in_array($plan['groupBy'], $whitelist[$table] ?? [], true)) {
            $countColumn = in_array('id', $whitelist[$table] ?? [], true) ? 'id' : $columns[0];
            $rows = $query->selectRaw($plan['groupBy'].' as dimension, count('.$countColumn.') as total')
                ->groupBy($plan['groupBy'])->limit(50)->get();
            $resultTable = ['columns' => ['dimension', 'total'], 'rows' => $rows->map(fn ($row): array => (array) $row)->all()];
        } else {
            $rows = $query->limit(200)->get();
            $resultTable = ['columns' => $columns, 'rows' => $rows->map(fn ($row): array => (array) $row)->all()];
        }

        $this->ctx->audit('strategic.ai.semantic_query.executed', HrSemanticEntity::class, $entity?->getKey(), [
            'question' => mb_substr($naturalLanguage, 0, 200), 'entity' => $plan['entity'],
            'filters' => $appliedFilters, 'rows' => count($resultTable['rows']),
        ], null, $user);

        return [
            'status' => 'OK', 'question' => $naturalLanguage,
            'plan' => ['entity' => $plan['entity'], 'select' => $columns, 'filters' => $appliedFilters,
                'group_by' => $plan['groupBy'], 'order' => 'default', 'limit' => 200],
            'authorization' => ['scope' => $scope['type'], 'permission' => $entity?->requires_permission, 'allowed' => true],
            'table' => $resultTable,
            'explanation' => 'Query dibangun dari entitas semantik terdaftar — bukan SQL bebas.',
            'sources' => [$sourceTable => 'lapisan semantik'],
        ];
    }

    /**
     * Jawaban atas pertanyaan kebijakan HR, dengan sitasi (§75).
     *
     * @return array<string, mixed>
     */
    public function askKnowledge(string $question, mixed $user): array
    {
        $text = mb_strtolower(trim($question));
        if ($text === '') {
            return ['status' => 'REFUSED', 'answer' => 'Pertanyaan kosong.', 'citations' => []];
        }

        $keywords = array_values(array_filter(preg_split('/[^a-z0-9]+/', $text) ?: [], fn (string $word): bool => mb_strlen($word) > 3));
        $chunks = HrAiKnowledgeChunk::query()
            ->when($keywords !== [], function ($q) use ($keywords): void {
                foreach ($keywords as $word) {
                    $q->orWhere('content', 'like', '%'.$word.'%');
                }
            })->limit(5)->get();

        if ($chunks->isEmpty()) {
            return [
                'status' => 'NO_SOURCE',
                'answer' => 'Saya tidak menemukan sumber kebijakan yang relevan, jadi saya tidak akan mengarang jawaban. '
                    .'Silakan unggah dokumen kebijakan ke knowledge base atau hubungi HR Service.',
                'citations' => [], 'compliance' => ['no_source_no_answer' => true],
            ];
        }

        $documents = HrAiKnowledgeDocument::query()->whereIn('id', $chunks->pluck('document_id')->all())->get()->keyBy('id');
        $citations = [];
        $answerParts = [];
        foreach ($chunks as $chunk) {
            $document = $documents[$chunk->document_id] ?? null;
            if (! $document) {
                continue;
            }
            if ($document->sensitivity === 'RESTRICTED' && ! $this->ctx->can($user, 'hr.document.view')) {
                continue;
            }
            $answerParts[] = mb_substr((string) $chunk->content, 0, 400);
            $citations[] = [
                'document' => $document->title, 'code' => $document->code,
                'category' => $document->category, 'sensitivity' => $document->sensitivity,
                'chunk_index' => (int) $chunk->chunk_index,
            ];
        }

        if ($answerParts === []) {
            return ['status' => 'DENIED', 'answer' => 'Dokumen sumber ada, tetapi Anda tidak memiliki izin membacanya.', 'citations' => []];
        }

        return [
            'status' => 'OK', 'question' => $question,
            'answer' => implode(PHP_EOL.PHP_EOL, $answerParts),
            'citations' => $citations,
            'compliance' => ['no_source_no_answer' => true, 'source_count' => count($citations)],
        ];
    }

    /**
     * Simpan dokumen pengetahuan + potong menjadi chunk untuk RAG.
     *
     * @return array<string, mixed>
     */
    public function indexDocument(array $data, mixed $actor = null): array
    {
        $document = HrAiKnowledgeDocument::query()->updateOrCreate(
            ['code' => strtoupper((string) $data['code'])],
            [
                'title' => $data['title'], 'category' => strtoupper((string) ($data['category'] ?? 'POLICY')),
                'source_type' => strtoupper((string) ($data['source_type'] ?? 'DOCUMENT')),
                'source_id' => $data['source_id'] ?? null,
                'sensitivity' => strtoupper((string) ($data['sensitivity'] ?? 'INTERNAL')),
                'content_hash' => hash('sha256', (string) $data['content']),
                'indexed_at' => now(), 'status' => 'ACTIVE',
            ]
        );

        HrAiKnowledgeChunk::query()->where('document_id', $document->getKey())->delete();
        $chunks = array_chunk(preg_split('/\n{2,}/', (string) $data['content']) ?: [], 1);
        foreach ($chunks as $index => $group) {
            $content = trim(implode(PHP_EOL, $group));
            if ($content === '') {
                continue;
            }
            HrAiKnowledgeChunk::query()->create([
                'document_id' => $document->getKey(), 'chunk_index' => $index,
                'content' => $content, 'tokens' => str_word_count($content),
                'sensitivity' => $document->sensitivity,
            ]);
        }

        $this->ctx->audit('strategic.ai.knowledge_indexed', HrAiKnowledgeDocument::class, $document->getKey(), [
            'code' => $document->code, 'chunks' => HrAiKnowledgeChunk::query()->where('document_id', $document->getKey())->count(),
        ], null, $actor);

        return [
            'document' => $document->code, 'title' => $document->title,
            'chunks' => HrAiKnowledgeChunk::query()->where('document_id', $document->getKey())->count(),
            'sensitivity' => $document->sensitivity,
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array{entity:string|null,select:array<int,string>,filters:array<string,mixed>,groupBy:string|null}
     */
    private function buildPlan(string $text, array $extra, mixed $user): array
    {
        $entity = match (true) {
            str_contains($text, 'guru') || str_contains($text, 'teacher') || str_contains($text, 'pegawai aktif')
                || str_contains($text, 'employee') => 'EMPLOYEE',
            str_contains($text, 'posisi') || str_contains($text, 'position') => 'POSITION',
            str_contains($text, 'gaji') || str_contains($text, 'payroll') => 'PAYROLL_ITEM',
            str_contains($text, 'presensi') || str_contains($text, 'kehadiran') || str_contains($text, 'attendance') => 'ATTENDANCE',
            str_contains($text, 'cuti') || str_contains($text, 'leave') => 'LEAVE_REQUEST',
            str_contains($text, 'kinerja') || str_contains($text, 'performance') => 'PERFORMANCE_REVIEW',
            str_contains($text, 'pelatihan') || str_contains($text, 'training') => 'TRAINING_COMPLETION',
            str_contains($text, 'skill') || str_contains($text, 'keterampilan') => 'EMPLOYEE_SKILL',
            str_contains($text, 'talenta') || str_contains($text, 'talent') => 'TALENT_POOL_MEMBER',
            str_contains($text, 'sertifikasi') || str_contains($text, 'certification') => 'EMPLOYEE_SKILL',
            default => null,
        };

        $filters = [];
        foreach ($extra as $key => $value) {
            $filters[$key] = $value;
        }
        if (str_contains($text, 'aktif') || str_contains($text, 'active')) {
            $filters['is_active'] = true;
        }
        if (preg_match('/sekolah\s+([a-z]+)/', $text, $matches) === 1) {
            $filters['organization_id'] = $this->organizationIdByName($matches[1]);
        }

        $groupBy = match (true) {
            str_contains($text, 'berdasarkan sekolah'), str_contains($text, 'per sekolah'), str_contains($text, 'per organisasi') => 'organization_id',
            str_contains($text, 'berdasarkan departemen'), str_contains($text, 'per departemen') => 'department_id',
            str_contains($text, 'per posisi'), str_contains($text, 'berdasarkan posisi') => 'position_id',
            str_contains($text, 'sertifikasi'), str_contains($text, 'sertifikat') => 'proficiency_level_code',
            default => null,
        };

        $select = match ($entity) {
            'EMPLOYEE' => $groupBy ? ['id', 'employee_number', $groupBy] : ['id', 'employee_number', 'position_id', 'department_id', 'organization_id', 'is_active'],
            'POSITION' => ['id', 'code', 'title', 'department_id', 'authorized_headcount', 'filled_headcount'],
            'PAYROLL_ITEM' => ['id', 'employee_id', 'net_amount'],
            'ATTENDANCE' => ['id', 'employee_id', 'date', 'status'],
            'LEAVE_REQUEST' => ['id', 'employee_id', 'status', 'total_days'],
            default => $groupBy ? ['id', $groupBy] : ['id'],
        };

        return ['entity' => $entity, 'select' => array_values(array_filter(array_unique($select))), 'filters' => array_filter($filters, fn ($v) => $v !== null), 'groupBy' => $groupBy];
    }

    private function organizationIdByName(string $name): ?int
    {
        return $this->ctx->safe(fn (): ?int => ($id = DB::table('hr_organizations')->where('name', 'like', '%'.$name.'%')->value('id')) ? (int) $id : null, null);
    }
}
