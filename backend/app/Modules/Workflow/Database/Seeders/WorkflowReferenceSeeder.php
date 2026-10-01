<?php

namespace App\Modules\Workflow\Database\Seeders;

use App\Models\User;
use App\Modules\Workflow\Application\Services\WorkflowBuilderService;
use App\Modules\Workflow\Application\Services\WorkflowDefinitionService;
use App\Modules\Workflow\Models\WorkflowCalendar;
use App\Modules\Workflow\Models\WorkflowDefinition;
use Illuminate\Database\Seeder;

/**
 * Definisi rujukan bawaan (§13 contoh).
 *
 * Empat alur yang hampir selalu dibutuhkan sekolah/pesantren, dibuat sebagai
 * **contoh yang benar-benar tayang** (bukan mock): Pengadaan, Cuti, Pengeluaran,
 * dan Persetujuan Dokumen. Ketiganya memakai jumlah tingkat yang sama tetapi
 * ambang nominal, jenis approver, dan SLA yang berbeda — semuanya berasal dari
 * konfigurasi, bukan dari kode engine.
 *
 * Sekolah dapat mengubahnya lewat layar Workflow; karena setiap perubahan
 * menghasilkan versi baru, request lama tetap memakai alur yang lama.
 */
class WorkflowReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCalendar();

        $admin = User::query()->orderBy('id')->first();

        if ($admin === null) {
            // Tanpa pengguna, definisi tetap dibuat tanpa pemilik.
            $admin = null;
        }

        foreach ($this->definitions() as $payload) {
            $definition = WorkflowDefinition::query()->firstOrCreate(
                ['code' => $payload['code']],
                [
                    'name' => $payload['name'],
                    'description' => $payload['description'],
                    'module' => $payload['module'],
                    'entity_type' => $payload['entity_type'],
                    'event' => $payload['event'] ?? null,
                    'trigger_type' => $payload['trigger_type'] ?? 'MANUAL',
                    'owner_user_id' => $admin?->id,
                    'owner_department' => $payload['owner_department'] ?? null,
                    'required_context' => $payload['required_context'] ?? [],
                    'settings' => $payload['settings'] ?? null,
                ],
            );

            // Sudah pernah diterbitkan: jangan menimpa versi yang sedang berjalan.
            if ($definition->current_version_id !== null) {
                continue;
            }

            $builder = app(WorkflowBuilderService::class);
            $definitions = app(WorkflowDefinitionService::class);

            $version = $definition->versions()->create([
                'version' => 1,
                'status' => 'DRAFT',
                'label' => 'v1 (bawaan)',
                'config' => $payload['config'] ?? null,
                'created_by' => $admin?->id,
            ]);

            $builder->saveGraph(
                $version,
                $payload['nodes'],
                $payload['edges'],
                $payload['conditions'] ?? [],
                [],
                $admin,
            );

            if ($admin !== null) {
                $builder->publish($definition->refresh()->versions()->latest('version')->first(), $admin, 'Versi bawaan Syntaf School.');
            }

            unset($definitions);
        }
    }

    protected function seedCalendar(): void
    {
        $calendar = WorkflowCalendar::query()->firstOrCreate(
            ['code' => 'DEFAULT'],
            [
                'name' => 'Kalender Sekolah (Senin–Jumat)',
                'timezone' => config('workflow.business_calendar.timezone', 'Asia/Jakarta'),
                'work_days' => [1, 2, 3, 4, 5],
                'work_start' => config('workflow.business_calendar.work_start', '08:00'),
                'work_end' => config('workflow.business_calendar.work_end', '17:00'),
                'is_default' => true,
                'is_active' => true,
            ],
        );

        if ($calendar->wasRecentlyCreated) {
            // Hari libur nasional umum; admin dapat menambah/menghapus sendiri.
            foreach ([
                ['2027-01-01', 'Tahun Baru'],
                ['2027-05-01', 'Hari Buruh'],
                ['2027-12-25', 'Natal'],
            ] as [$date, $name]) {
                $calendar->holidays()->firstOrCreate(['date' => $date], ['name' => $name]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function definitions(): array
    {
        return [
            [
                'code' => 'PURCHASE_REQUEST_APPROVAL',
                'name' => 'Persetujuan Permintaan Pengadaan',
                'description' => 'Atasan langsung memeriksa kebutuhan, lalu nominal menentukan siapa yang menyetujui: bendahara (≥ 1 juta) atau kepala sekolah (≥ 10 juta).',
                'module' => 'procurement',
                'entity_type' => 'procurement.purchase_request',
                'event' => 'PurchaseRequestSubmitted',
                'required_context' => ['amount', 'department'],
                'owner_department' => 'sarana',
                'config' => [
                    'comment_required_on' => ['REJECT', 'RETURN', 'REQUEST_CHANGES'],
                    'return_strategy' => 'RESUME_FROM_PREVIOUS_STEP',
                    'empty_approver' => 'PAUSE',
                ],
                'nodes' => [
                    ['key' => 'start', 'type' => 'START', 'name' => 'Diajukan', 'sequence' => 1],
                    [
                        'key' => 'supervisor', 'type' => 'APPROVAL', 'name' => 'Atasan Langsung', 'sequence' => 2,
                        'approval_mode' => 'PARALLEL', 'quorum_policy' => 'ALL', 'sla_minutes' => 1440,
                        'approvers' => [['resolver' => 'manager', 'levels' => 1]],
                    ],
                    ['key' => 'amount_gate', 'type' => 'CONDITION', 'name' => 'Cek Nominal', 'sequence' => 3],
                    [
                        'key' => 'finance', 'type' => 'APPROVAL', 'name' => 'Bendahara', 'sequence' => 4,
                        'approval_mode' => 'PARALLEL', 'quorum_policy' => 'ALL', 'sla_minutes' => 1440,
                        'approvers' => [['resolver' => 'role', 'role' => 'bendahara']],
                    ],
                    [
                        'key' => 'director', 'type' => 'APPROVAL', 'name' => 'Kepala Sekolah', 'sequence' => 5,
                        'approval_mode' => 'PARALLEL', 'quorum_policy' => 'ALL', 'sla_minutes' => 2880,
                        'approvers' => [['resolver' => 'role', 'role' => 'kepala-sekolah']],
                    ],
                    ['key' => 'end', 'type' => 'END', 'name' => 'Disetujui', 'sequence' => 6,
                        'actions' => ['approved' => [
                            ['handler' => 'event.publish', 'params' => ['name' => 'PurchaseRequestApproved']],
                            ['handler' => 'notification.send', 'params' => ['to' => 'requester', 'event' => 'WORKFLOW_COMPLETED', 'title' => 'Pengadaan disetujui']],
                        ]],
                    ],
                ],
                'edges' => [
                    ['from_key' => 'start', 'to_key' => 'supervisor', 'priority' => 0],
                    ['from_key' => 'supervisor', 'to_key' => 'amount_gate', 'priority' => 0],
                    ['from_key' => 'amount_gate', 'to_key' => 'director', 'priority' => 0,
                        'condition' => ['field' => 'amount', 'operator' => '>=', 'value' => 10000000]],
                    ['from_key' => 'amount_gate', 'to_key' => 'finance', 'priority' => 1,
                        'condition' => ['field' => 'amount', 'operator' => '>=', 'value' => 1000000]],
                    ['from_key' => 'amount_gate', 'to_key' => 'end', 'priority' => 9, 'is_default' => true],
                    ['from_key' => 'finance', 'to_key' => 'end', 'priority' => 0],
                    ['from_key' => 'director', 'to_key' => 'end', 'priority' => 0],
                ],
            ],
            [
                'code' => 'LEAVE_REQUEST_APPROVAL',
                'name' => 'Persetujuan Cuti / Izin Pegawai',
                'description' => 'Atasan langsung menyetujui, kepegawaian memverifikasi administrasi dan sisa cuti.',
                'module' => 'hrd',
                'entity_type' => 'hrd.leave_request',
                'event' => 'LeaveRequestSubmitted',
                'required_context' => ['department'],
                'owner_department' => 'kepegawaian',
                'config' => ['empty_approver' => 'PAUSE'],
                'nodes' => [
                    ['key' => 'start', 'type' => 'START', 'name' => 'Diajukan', 'sequence' => 1],
                    ['key' => 'supervisor', 'type' => 'APPROVAL', 'name' => 'Atasan Langsung', 'sequence' => 2,
                        'approval_mode' => 'PARALLEL', 'quorum_policy' => 'ALL', 'sla_minutes' => 720,
                        'approvers' => [['resolver' => 'manager', 'levels' => 1]]],
                    ['key' => 'hr', 'type' => 'REVIEW', 'name' => 'Verifikasi Kepegawaian', 'sequence' => 3,
                        'approval_mode' => 'PARALLEL', 'quorum_policy' => 'ALL', 'sla_minutes' => 720,
                        'approvers' => [['resolver' => 'department', 'fallback_user_id' => null]]],
                    ['key' => 'end', 'type' => 'END', 'name' => 'Disetujui', 'sequence' => 4],
                ],
                'edges' => [
                    ['from_key' => 'start', 'to_key' => 'supervisor'],
                    ['from_key' => 'supervisor', 'to_key' => 'hr'],
                    ['from_key' => 'hr', 'to_key' => 'end'],
                ],
            ],
            [
                'code' => 'EXPENSE_APPROVAL',
                'name' => 'Persetujuan Pengeluaran / Reimbursement',
                'description' => 'Bendahara memeriksa bukti & anggaran; di atas 5 juta ditambah persetujuan kepala sekolah. Pemeriksa anggaran terpisah dari penyetuju akhir.',
                'module' => 'finance',
                'entity_type' => 'finance.expense_request',
                'event' => 'ExpenseRequestSubmitted',
                'required_context' => ['amount'],
                'owner_department' => 'keuangan',
                'settings' => ['sod' => ['requester_cannot_be_final_approver' => true]],
                'nodes' => [
                    ['key' => 'start', 'type' => 'START', 'name' => 'Diajukan', 'sequence' => 1],
                    ['key' => 'budget_review', 'type' => 'REVIEW', 'name' => 'Pemeriksaan Anggaran', 'sequence' => 2,
                        'approval_mode' => 'PARALLEL', 'quorum_policy' => 'ANY', 'sla_minutes' => 480,
                        'approvers' => [['resolver' => 'role', 'role' => 'bendahara']]],
                    ['key' => 'amount_gate', 'type' => 'CONDITION', 'name' => 'Cek Nominal', 'sequence' => 3],
                    ['key' => 'director', 'type' => 'APPROVAL', 'name' => 'Kepala Sekolah', 'sequence' => 4,
                        'approval_mode' => 'PARALLEL', 'quorum_policy' => 'ALL', 'sla_minutes' => 1440,
                        'approvers' => [['resolver' => 'role', 'role' => 'kepala-sekolah']]],
                    ['key' => 'end', 'type' => 'END', 'name' => 'Selesai', 'sequence' => 5],
                ],
                'edges' => [
                    ['from_key' => 'start', 'to_key' => 'budget_review'],
                    ['from_key' => 'budget_review', 'to_key' => 'amount_gate'],
                    ['from_key' => 'amount_gate', 'to_key' => 'director', 'priority' => 0,
                        'condition' => ['field' => 'amount', 'operator' => '>', 'value' => 5000000]],
                    ['from_key' => 'amount_gate', 'to_key' => 'end', 'priority' => 9, 'is_default' => true],
                    ['from_key' => 'director', 'to_key' => 'end'],
                ],
            ],
            [
                'code' => 'DOCUMENT_APPROVAL',
                'name' => 'Persetujuan Dokumen / e-Office',
                'description' => 'Administrasi memeriksa kelengkapan (review), kepala sekolah menerbitkan persetujuan. Dua orang berbeda, sesuai pemisahan tugas.',
                'module' => 'document',
                'entity_type' => 'document.document',
                'event' => 'DocumentSubmitted',
                'required_context' => [],
                'owner_department' => 'tata-usaha',
                'settings' => ['sod' => ['forbid_same_user_multiple_steps' => true]],
                'nodes' => [
                    ['key' => 'start', 'type' => 'START', 'name' => 'Diajukan', 'sequence' => 1],
                    ['key' => 'review', 'type' => 'REVIEW', 'name' => 'Pemeriksaan Kelengkapan', 'sequence' => 2,
                        'approval_mode' => 'PARALLEL', 'quorum_policy' => 'ANY', 'sla_minutes' => 480,
                        'approvers' => [['resolver' => 'role', 'role' => 'admin-sekolah']]],
                    ['key' => 'approve', 'type' => 'APPROVAL', 'name' => 'Persetujuan Kepala Sekolah', 'sequence' => 3,
                        'approval_mode' => 'PARALLEL', 'quorum_policy' => 'ALL', 'sla_minutes' => 1440,
                        'approvers' => [['resolver' => 'role', 'role' => 'kepala-sekolah']]],
                    ['key' => 'end', 'type' => 'END', 'name' => 'Disetujui', 'sequence' => 4],
                ],
                'edges' => [
                    ['from_key' => 'start', 'to_key' => 'review'],
                    ['from_key' => 'review', 'to_key' => 'approve'],
                    ['from_key' => 'approve', 'to_key' => 'end'],
                ],
            ],
        ];
    }
}
