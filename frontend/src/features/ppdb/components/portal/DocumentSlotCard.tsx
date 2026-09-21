import { useRef, useState, type ReactNode } from 'react';
import { useMutation } from '@tanstack/react-query';
import { AlertCircle, Check, Clock, Download, FileText, RefreshCw, Upload } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { ppdbPortalDocumentsApi } from '../../api/ppdbApi';
import { getErrorMessage } from '@/lib/axios';
import { cn } from '@/lib/utils';
import {
  acceptAttribute,
  allowedExtensionsOf,
  formatDateTime,
  formatFileSize,
  formatKbFloor,
  formatKbLimit,
  validateFileAgainstRequirement,
} from './format';
import { DOCUMENT_STATUS_LABELS, documentStatusTone, type DocumentState } from './labels';
import type { PpdbDocumentSlot, PpdbPortalDocument } from '@/types/ppdbPortal';

interface Props {
  slot: PpdbDocumentSlot;
  registrationId: number;
  disabled?: boolean;
  flagged?: boolean;
  flagNote?: string | null;
  onUploaded?: () => void;
}

function StatusChip({ status }: { status: DocumentState }) {
  return (
    <Badge tone={documentStatusTone(status)}>{DOCUMENT_STATUS_LABELS[status] ?? status}</Badge>
  );
}

/**
 * Tautan berkas. `PpdbApplicantDocumentResource` mengirim URL lengkap pada `url`
 * (dibangun lewat `Storage::disk('public')`); `file_path` masih relatif dan tidak
 * boleh diklik, jadi tautan diganti keterangan bila berkas tak tersedia daring.
 */
function FileLink({
  document,
  className,
  fallback,
  children,
}: {
  document: PpdbPortalDocument;
  className: string;
  fallback: string;
  children: ReactNode;
}) {
  if (!document.url) {
    return <span className="shrink-0 text-xs text-slate-400">{fallback}</span>;
  }

  return (
    <a href={document.url} target="_blank" rel="noreferrer" className={className}>
      {children}
    </a>
  );
}

/**
 * Satu kartu per persyaratan dokumen: berkas aktif, riwayat versi, unggah ulang.
 *
 * `PpdbDocumentService::annotated()` mengirim baris DATAR: kolom persyaratan ada
 * pada slot itu sendiri, berkas aktif pada `document`, seluruh versinya pada
 * `versions`.
 */
export function DocumentSlotCard({
  slot,
  registrationId,
  disabled,
  flagged,
  flagNote,
  onUploaded,
}: Props) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [rejected, setRejected] = useState<string | null>(null);
  const active = slot.document;
  const versions = slot.versions;
  const extensions = allowedExtensionsOf(slot.allowed_extensions, slot.file_types);
  const uploadLabel = active ? 'Ganti berkas' : 'Unggah berkas';

  const uploadMutation = useMutation({
    mutationFn: (file: File) => ppdbPortalDocumentsApi.upload(registrationId, slot.id, file),
    onSuccess: () => {
      toast.success(`${slot.name} terkirim. Panitia akan meninjau berkas ini.`);
      onUploaded?.();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const handleFile = (file: File | undefined) => {
    setRejected(null);
    if (!file) return;
    const problem = validateFileAgainstRequirement(file, {
      extensions,
      maxSizeKb: slot.max_size_kb,
      minSizeKb: slot.min_size_kb,
    });
    if (problem) {
      setRejected(problem);
      return;
    }
    uploadMutation.mutate(file);
    if (inputRef.current) inputRef.current.value = '';
  };

  const status: DocumentState = slot.status;

  return (
    <Card className={cn('p-4', flagged && 'border-amber-300 bg-amber-50/40')}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <p className="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-slate-900">
            {slot.name}
            {slot.required ? (
              <span className="text-xs font-normal text-red-500">wajib</span>
            ) : (
              <span className="text-xs font-normal text-slate-400">pendukung</span>
            )}
          </p>
          {slot.description && <p className="mt-0.5 text-xs text-slate-500">{slot.description}</p>}
          <p className="mt-1 text-xs text-slate-500">
            Format: {extensions.length ? extensions.join(', ') : 'umum'} ·{' '}
            {formatKbLimit(slot.max_size_kb)} per berkas
            {slot.min_size_kb ? ` · ${formatKbFloor(slot.min_size_kb)}` : ''}
          </p>
        </div>
        <StatusChip status={status} />
      </div>

      {active && (
        <div className="mt-3 rounded-lg border border-slate-200 bg-white p-3">
          <div className="flex items-start gap-2">
            <FileText className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-slate-800">{active.original_filename}</p>
              <p className="mt-0.5 text-xs text-slate-500">
                Versi {active.version} · {formatFileSize(active.file_size)} · diunggah{' '}
                {formatDateTime(active.uploaded_at ?? active.created_at)}
              </p>
              {active.review_note && (
                <p
                  className={cn(
                    'mt-1.5 rounded px-2 py-1.5 text-xs',
                    active.status === 'rejected' ? 'bg-red-50 text-red-700' : 'bg-slate-50 text-slate-600',
                  )}
                >
                  Catatan panitia: {active.review_note}
                </p>
              )}
            </div>
            <FileLink
              document={active}
              className="inline-flex min-h-9 items-center gap-1 rounded-lg px-2 text-xs font-medium text-brand-600 hover:bg-brand-50"
              fallback="berkas tidak tersedia daring"
            >
              <Download className="h-3.5 w-3.5" />
              Lihat
            </FileLink>
          </div>
        </div>
      )}

      {flagged && flagNote && (
        <p className="mt-2 rounded-lg bg-amber-100 px-2.5 py-2 text-xs text-amber-800">{flagNote}</p>
      )}

      {rejected && (
        <p className="mt-2 flex items-start gap-1.5 rounded-lg bg-red-50 px-2.5 py-2 text-xs text-red-700">
          <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          {rejected}
        </p>
      )}

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <input
          ref={inputRef}
          type="file"
          className="hidden"
          accept={acceptAttribute(extensions)}
          onChange={(e) => handleFile(e.target.files?.[0])}
        />
        {disabled ? (
          <p className="text-xs text-slate-500">
            Berkas tidak dapat diubah lagi karena pendaftaran sudah dikirim.
          </p>
        ) : (
          <Button
            size="sm"
            variant={slot.status === 'rejected' ? 'primary' : 'outline'}
            loading={uploadMutation.isPending}
            onClick={() => inputRef.current?.click()}
          >
            {!uploadMutation.isPending && (active ? <RefreshCw className="h-4 w-4" /> : <Upload className="h-4 w-4" />)}
            {uploadLabel}
          </Button>
        )}
        {uploadMutation.isPending && (
          <span className="inline-flex items-center gap-2 text-xs text-slate-500">
            <Spinner className="h-4 w-4" /> Mengunggah berkas...
          </span>
        )}
        {slot.status === 'approved' && (
          <span className="inline-flex items-center gap-1 text-xs text-green-700">
            <Check className="h-3.5 w-3.5" /> Dokumen dinyatakan sesuai
          </span>
        )}
        {slot.status === 'under_review' && (
          <span className="inline-flex items-center gap-1 text-xs text-amber-700">
            <Clock className="h-3.5 w-3.5" /> Sedang ditinjau panitia
          </span>
        )}
      </div>

      {versions.length > 1 && (
        <details className="mt-3 rounded-lg border border-slate-200 bg-white">
          <summary className="min-h-10 cursor-pointer px-3 py-2 text-xs font-medium text-slate-600">
            Riwayat versi ({versions.length} berkas)
          </summary>
          <ul className="divide-y divide-slate-100 border-t border-slate-100">
            {versions
              .slice()
              .sort((a, b) => b.version - a.version)
              .map((version) => (
                <li key={version.id} className="flex items-center justify-between gap-2 px-3 py-2 text-xs">
                  <span className="min-w-0 flex-1 truncate text-slate-600">
                    v{version.version} · {version.original_filename}
                    <span className="ml-1 text-slate-400">{formatDateTime(version.uploaded_at ?? version.created_at)}</span>
                  </span>
                  {version.is_active ? (
                    <Badge tone="brand">aktif</Badge>
                  ) : (
                    <FileLink
                      document={version}
                      className="shrink-0 font-medium text-brand-600 hover:underline"
                      fallback="arsip lokal"
                    >
                      lihat arsip
                    </FileLink>
                  )}
                </li>
              ))}
          </ul>
        </details>
      )}
    </Card>
  );
}
