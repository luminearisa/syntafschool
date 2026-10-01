<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rujukan berkas pendukung (§66).
 *
 * Engine **tidak** menyalin berkas: yang disimpan hanya rujukan ke penyimpanan
 * yang sudah dipakai ERP (`source` + `file_path`/`external_ref`). Bila sekolah
 * memakai Document/e-Office, set `source = DOCUMENT_PROVIDER` dan simpan id
 * dokumennya — tidak ada dua salinan berkas yang bisa saling bertentangan.
 */
class WorkflowAttachment extends WorkflowModel
{
    protected $table = 'workflow_attachments';

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
