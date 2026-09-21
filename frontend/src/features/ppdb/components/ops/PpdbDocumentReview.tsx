import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronDown, ChevronRight, ExternalLink, FileCheck2, ShieldAlert } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbDocumentsApi } from '../../api/ppdbApi';
import {
  EmptyList,
  ErrorLine,
  FileReviewBadge,
  LoadingLine,
  NoteModal,
  SectionCard,
} from './PpdbOpsUi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { formatDateTime, fileUrl } from './labels';
import type { PpdbApplicantDocument } from '@/types/ppdb';

/** Satu syarat dokumen + berkas aktifnya + seluruh versi yang pernah diunggah. */
export interface DocumentSlot {
  key: string;
  requirementId: number | null;
  name: string;
  required: boolean;
  active: PpdbApplicantDocument | null;
  versions: PpdbApplicantDocument[];
}

export function groupDocumentsByRequirement(rows: PpdbApplicantDocument[]): DocumentSlot[] {
  const groups = new Map<string, DocumentSlot>();
  for (const row of rows) {
    const key = row.requirement_id != null ? `req-${row.requirement_id}` : `name-${row.requirement_name}`;
    const slot =
      groups.get(key) ??
      {
        key,
        requirementId: row.requirement_id ?? null,
        name: row.requirement_name || 'Dokumen tanpa syarat',
        required: true,
        active: null,
        versions: [],
      };
    slot.versions.push(row);
    if (row.is_active || !slot.active || row.version > (slot.active.version ?? 0)) {
      slot.active = slot.active?.is_active && !row.is_active ? slot.active : row;
    }
    groups.set(key, slot);
  }

  return [...groups.values()]
    .map((slot) => ({ ...slot, versions: [...slot.versions].sort((a, b) => b.version - a.version) }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

/**
 * `documents` pada respons detail bisa berupa daftar datar atau slot
 * `{ requirement, active, versions }` seperti di portal — keduanya dinormalisasi.
 */
export function normalizeDocumentSlots(value: unknown): DocumentSlot[] | null {
  if (!Array.isArray(value) || value.length === 0) return null;

  const looksLikeSlots = value.every(
    (item) => item && typeof item === 'object' && 'requirement' in (item as Record<string, unknown>),
  );

  if (!looksLikeSlots) {
    return groupDocumentsByRequirement(value as PpdbApplicantDocument[]);
  }

  return (value as Record<string, unknown>[])
    .map((item) => {
      const requirement = (item.requirement ?? {}) as { id?: number; name?: string; required?: boolean };
      const versions = Array.isArray(item.versions) ? (item.versions as PpdbApplicantDocument[]) : [];
      const active = (item.active as PpdbApplicantDocument | null) ?? versions.find((v) => v.is_active) ?? versions[0] ?? null;
      return {
        key: `req-${requirement.id ?? requirement.name ?? Math.random()}`,
        requirementId: requirement.id ?? null,
        name: requirement.name ?? 'Dokumen',
        required: requirement.required ?? true,
        active,
        versions,
      } satisfies DocumentSlot;
    })
    .sort((a, b) => a.name.localeCompare(b.name));
}

type ReviewStatus = 'approved' | 'rejected' | 'under_review';

function formatFileSize(bytes: number | undefined): string {
  const size = Number(bytes ?? 0);
  if (!size) return '—';
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${Math.round(size / 1024)} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

function DocumentSlotRow({
  slot,
  busyId,
  onReview,
}: {
  slot: DocumentSlot;
  busyId: number | null;
  onReview: (document: PpdbApplicantDocument, status: ReviewStatus) => void;
}) {
  const [showVersions, setShowVersions] = useState(false);
  const active = slot.active;
  const url = fileUrl(active?.file_url, active?.file_path);

  return (
    <li className="px-4 py-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-800">
            {slot.name}
            {!slot.required && <Badge tone="gray">opsional</Badge>}
          </p>
          {active ? (
            <p className="mt-0.5 truncate text-xs text-slate-500">
              Versi {active.version} · {active.original_filename} · {formatFileSize(active.file_size)} ·
              diunggah {formatDateTime(active.uploaded_at)}
              {active.uploader_name ? ` oleh ${active.uploader_name}` : ''}
            </p>
          ) : (
            <p className="mt-0.5 text-xs text-amber-600">Belum ada berkas diunggah.</p>
          )}
          {active?.review_note && (
            <p className="mt-1 text-xs text-slate-500">
              Catatan reviewer
              {active.reviewer_name ? ` (${active.reviewer_name})` : ''}: {active.review_note}
            </p>
          )}
        </div>

        <div className="flex shrink-0 flex-col items-end gap-1.5">
          {active ? <FileReviewBadge status={active.status} /> : <Badge tone="gray">Kosong</Badge>}
          <div className="flex flex-wrap items-center justify-end gap-1">
            {url && (
              <a
                href={url}
                target="_blank"
                rel="noreferrer"
                className="inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-medium text-slate-600 hover:bg-slate-100"
              >
                <ExternalLink className="h-3.5 w-3.5" /> Buka berkas
              </a>
            )}
            {slot.versions.length > 1 && (
              <button
                type="button"
                onClick={() => setShowVersions((value) => !value)}
                className="inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-medium text-slate-600 hover:bg-slate-100"
              >
                {showVersions ? (
                  <ChevronDown className="h-3.5 w-3.5" />
                ) : (
                  <ChevronRight className="h-3.5 w-3.5" />
                )}
                {slot.versions.length} versi
              </button>
            )}
            <Can perm="ppdb.registration.verify">
              {active && active.status !== 'approved' && (
                <Button
                  size="sm"
                  variant="outline"
                  loading={busyId === active.id}
                  onClick={() => onReview(active, 'approved')}
                >
                  <FileCheck2 className="h-3.5 w-3.5" /> Setujui
                </Button>
              )}
              {active && active.status !== 'rejected' && (
                <Button
                  size="sm"
                  variant="outline"
                  loading={busyId === active.id}
                  onClick={() => onReview(active, 'rejected')}
                >
                  <ShieldAlert className="h-3.5 w-3.5" /> Tolak
                </Button>
              )}
              {active && active.status !== 'under_review' && (
                <Button
                  size="sm"
                  variant="ghost"
                  loading={busyId === active.id}
                  onClick={() => onReview(active, 'under_review')}
                >
                  Tandai ditinjau
                </Button>
              )}
            </Can>
          </div>
        </div>
      </div>

      {showVersions && (
        <ul className="mt-2 space-y-1 rounded-lg bg-slate-50 px-3 py-2">
          {slot.versions.map((version) => {
            const versionUrl = fileUrl(version.file_url, version.file_path);
            return (
              <li key={version.id} className="flex items-center justify-between gap-2 text-xs text-slate-600">
                <span className="truncate">
                  v{version.version} · {version.original_filename} · {formatDateTime(version.uploaded_at)}
                  {version.is_active ? ' · aktif' : ''}
                </span>
                <span className="flex shrink-0 items-center gap-2">
                  <FileReviewBadge status={version.status} />
                  {versionUrl && (
                    <a href={versionUrl} target="_blank" rel="noreferrer" className="text-brand-700 hover:underline">
                      buka
                    </a>
                  )}
                </span>
              </li>
            );
          })}
        </ul>
      )}
    </li>
  );
}

/**
 * Grup dokumen per syarat + tinjau inline.
 * Dipakai pada halaman detail pendaftaran dan antrean verifikasi.
 */
export function DocumentReviewPanel({
  registrationId,
  slots,
  title = 'Dokumen',
  description,
  onChanged,
}: {
  registrationId: number;
  /** Slot siap pakai dari respons detail; bila tidak ada, panel menarik `/ppdb/documents`. */
  slots?: DocumentSlot[] | null;
  title?: string;
  description?: string;
  onChanged?: () => void;
}) {
  const queryClient = useQueryClient();
  const [pending, setPending] = useState<{ document: PpdbApplicantDocument; status: ReviewStatus } | null>(null);

  const listQuery = useQuery({
    queryKey: ['ppdb', 'documents', { registration_id: registrationId }],
    enabled: !slots,
    queryFn: () => ppdbDocumentsApi.list({ registration_id: registrationId, per_page: 100 }),
  });

  const resolvedSlots = useMemo<DocumentSlot[]>(
    () => slots ?? groupDocumentsByRequirement(listQuery.data?.data ?? []),
    [slots, listQuery.data],
  );

  const reviewMutation = useMutation({
    mutationFn: (args: { document: PpdbApplicantDocument; status: ReviewStatus; note: string }) => {
      // `note` adalah nama field pada klien beku; kontrak admin mengirim
      // `review_note` — keduanya dikirim agar bentuk mana pun diterima server.
      const payload: Parameters<typeof ppdbDocumentsApi.review>[1] & { review_note?: string } = {
        status: args.status,
        note: args.note,
        review_note: args.note,
      };
      return ppdbDocumentsApi.review(args.document.id, payload);
    },
    onSuccess: (document) => {
      toast.success(`Dokumen ${document.requirement_name ?? ''} diperbarui.`.trim());
      setPending(null);
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
      onChanged?.();
    },
    onError: (error) => {
      const field = getValidationErrors(error)?.status?.[0];
      toast.error(field ?? getErrorMessage(error));
    },
  });

  const isFetching = !slots && (listQuery.isLoading || listQuery.isFetching);

  return (
    <SectionCard
      title={title}
      description={description}
      action={
        <Button size="sm" variant="ghost" onClick={() => listQuery.refetch()} disabled={!slots && listQuery.isFetching}>
          Muat ulang
        </Button>
      }
      bodyClassName="rounded-lg border border-slate-100"
    >
      {!slots && listQuery.isLoading ? (
        <LoadingLine label="Memuat dokumen..." />
      ) : !slots && listQuery.isError ? (
        <ErrorLine message={getErrorMessage(listQuery.error)} onRetry={() => listQuery.refetch()} />
      ) : resolvedSlots.length === 0 ? (
        <EmptyList
          title="Belum ada dokumen"
          description="Orang tua belum mengunggah berkas apa pun untuk pendaftaran ini."
        />
      ) : (
        <ul className={isFetching ? 'divide-y divide-slate-50 opacity-60' : 'divide-y divide-slate-50'}>
          {resolvedSlots.map((slot) => (
            <DocumentSlotRow
              key={slot.key}
              slot={slot}
              busyId={reviewMutation.isPending ? (reviewMutation.variables?.document.id ?? null) : null}
              onReview={(document, status) => setPending({ document, status })}
            />
          ))}
        </ul>
      )}

      <NoteModal
        open={pending != null}
        title={
          pending?.status === 'approved'
            ? 'Setujui dokumen'
            : pending?.status === 'rejected'
              ? 'Tolak dokumen'
              : 'Tandai dokumen sedang ditinjau'
        }
        description={
          pending
            ? `${pending.document.requirement_name} · versi ${pending.document.version} (${pending.document.original_filename})`
            : undefined
        }
        noteLabel={pending?.status === 'rejected' ? 'Alasan penolakan' : 'Catatan review'}
        noteRequired={pending?.status !== 'approved'}
        placeholder={
          pending?.status === 'rejected'
            ? 'Contoh: scan rapor tidak jelas, unggah ulang yang lebih terbaca.'
            : 'Contoh: sesuai dengan identitas calon.'
        }
        confirmLabel="Simpan hasil review"
        tone={pending?.status === 'rejected' ? 'danger' : 'primary'}
        busy={reviewMutation.isPending}
        errors={getValidationErrors(reviewMutation.error)}
        onClose={() => setPending(null)}
        onSubmit={(note) => {
          if (pending) reviewMutation.mutate({ ...pending, note });
        }}
      />
    </SectionCard>
  );
}
