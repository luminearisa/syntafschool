import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, RefreshCw, ScanLine, ShieldCheck, ShieldX, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { certificatesApi } from '../api/tahfizApi';
import { CertificateFormModal } from '../components/CertificateFormModal';
import { CertificateVerifyModal } from '../components/CertificateVerifyModal';
import { RevokeCertificateModal } from '../components/RevokeCertificateModal';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useParticipantOptions, useProgramOptions } from '../hooks/useTahfizOptions';
import { ACHIEVEMENT_TYPE_LABELS, toOptions } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  TahfizAchievementType,
  TahfizCertificate,
  TahfizCertificatePayload,
} from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const ACHIEVEMENT_OPTIONS = toOptions(ACHIEVEMENT_TYPE_LABELS);

const STATUS_FILTER_OPTIONS = [
  { value: 'issued', label: 'Terbit' },
  { value: 'revoked', label: 'Dicabut' },
];

/**
 * Sertifikat pencapaian Tahfiz.
 *
 * Penerbitan dan pencabutan butuh `tahfiz.approve`; verifikasi QR bersifat
 * publik dan tetap tersedia tanpa sesi.
 */
export function TahfizCertificatesPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [participantId, setParticipantId] = useState<number | ''>('');
  const [programId, setProgramId] = useState<number | ''>('');
  const [achievementType, setAchievementType] = useState<TahfizAchievementType | ''>('');
  const [status, setStatus] = useState<'issued' | 'revoked' | ''>('');
  const [search, setSearch] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [revoking, setRevoking] = useState<TahfizCertificate | null>(null);
  const [revokeErrors, setRevokeErrors] = useState<Record<string, string[]>>({});
  const [verifying, setVerifying] = useState<{ open: boolean; certificate: TahfizCertificate | null }>({
    open: false,
    certificate: null,
  });
  const [deleting, setDeleting] = useState<TahfizCertificate | null>(null);

  const { options: participantOptions } = useParticipantOptions();
  const { options: programOptions } = useProgramOptions();

  const filters: Record<string, string | number> = {};
  if (participantId) filters.participant_id = participantId;
  if (programId) filters.program_id = programId;
  if (achievementType) filters.achievement_type = achievementType;
  if (status) filters.status = status;

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'certificates', page, filters, search],
    queryFn: () =>
      certificatesApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'certificates'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizCertificatePayload) => certificatesApi.create(payload),
    onSuccess: () => {
      toast.success('Sertifikat diterbitkan');
      setFormOpen(false);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const revokeMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      certificatesApi.revoke(id, reason),
    onSuccess: (res) => {
      toast.success(res.message);
      setRevoking(null);
      setRevokeErrors({});
      invalidate();
    },
    onError: (err) => {
      setRevokeErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => certificatesApi.remove(id),
    onSuccess: () => {
      toast.success('Sertifikat dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Sertifikat Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Sertifikat pencapaian dengan verifikasi QR publik.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Button
            variant="outline"
            onClick={() => setVerifying({ open: true, certificate: null })}
          >
            <ScanLine className="h-4 w-4" /> Verifikasi
          </Button>
          <Can perm="tahfiz.approve">
            <Button
              onClick={() => {
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Terbitkan Sertifikat
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Peserta</label>
            <SearchSelect
              options={participantOptions}
              value={participantId}
              onChange={(v) => {
                setParticipantId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua peserta --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Program</label>
            <SearchSelect
              options={programOptions}
              value={programId}
              onChange={(v) => {
                setProgramId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua program --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Pencapaian</label>
            <Select
              value={achievementType}
              onChange={(e) => {
                setAchievementType(e.target.value as TahfizAchievementType | '');
                setPage(1);
              }}
            >
              <option value="">Semua pencapaian</option>
              {ACHIEVEMENT_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as 'issued' | 'revoked' | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {STATUS_FILTER_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="lg:col-span-2">
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Cari nomor sertifikat..."
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat sertifikat...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat sertifikat"
            description={getErrorMessage(listQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => listQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada sertifikat"
            description="Terbitkan sertifikat pertama untuk pencapaian peserta."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Nomor</th>
                  <th className="px-4 py-2 font-medium">Peserta</th>
                  <th className="px-4 py-2 font-medium">Program</th>
                  <th className="px-4 py-2 font-medium">Pencapaian</th>
                  <th className="px-3 py-2 text-center font-medium">Juz</th>
                  <th className="px-4 py-2 font-medium">Terbit</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Penanda Tangan</th>
                  <th className="w-40 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">
                      {row.certificate_number}
                    </td>
                    <td className="px-4 py-2 font-medium text-slate-800">
                      {row.participant?.name ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.program?.name ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{row.achievement_type_label}</td>
                    <td className="px-3 py-2 text-center text-slate-700">{row.juz_count ?? '—'}</td>
                    <td className="px-4 py-2 text-slate-600">{row.issue_date ?? '—'}</td>
                    <td className="px-4 py-2">
                      <Badge tone={row.is_valid ? 'green' : 'red'}>{row.status_label}</Badge>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.issuer?.name ?? row.signature_name ?? '—'}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Button
                          variant="ghost"
                          size="icon"
                          title="Verifikasi QR"
                          onClick={() => setVerifying({ open: true, certificate: row })}
                        >
                          <ScanLine className="h-3.5 w-3.5" />
                        </Button>
                        <Can perm="tahfiz.approve">
                          {row.status === 'issued' && (
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Cabut sertifikat"
                              onClick={() => {
                                setRevokeErrors({});
                                setRevoking(row);
                              }}
                            >
                              <ShieldX className="h-3.5 w-3.5 text-red-500" />
                            </Button>
                          )}
                        </Can>
                        <Can perm="tahfiz.delete">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Hapus"
                            onClick={() => setDeleting(row)}
                          >
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </Can>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <div className="flex items-center gap-2 text-xs text-slate-400">
        <ShieldCheck className="h-3.5 w-3.5" />
        Verifikasi QR dapat diakses publik tanpa login; sertifikat yang dicabut otomatis tidak valid.
      </div>

      <CertificateFormModal
        open={formOpen}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => setFormOpen(false)}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <RevokeCertificateModal
        open={!!revoking}
        certificate={revoking}
        submitting={revokeMutation.isPending}
        serverErrors={revokeErrors}
        onClose={() => setRevoking(null)}
        onSubmit={(reason) => {
          if (revoking) revokeMutation.mutate({ id: revoking.id, reason });
        }}
      />

      <CertificateVerifyModal
        open={verifying.open}
        certificate={verifying.certificate}
        onClose={() => setVerifying({ open: false, certificate: null })}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Sertifikat"
        message={`Hapus sertifikat "${deleting?.certificate_number ?? ''}"? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}