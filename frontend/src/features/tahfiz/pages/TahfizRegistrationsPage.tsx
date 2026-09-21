import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, RefreshCw, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { registrationsApi } from '../api/tahfizApi';
import { RegistrationRejectModal } from '../components/RegistrationRejectModal';
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
import { useProgramOptions } from '../hooks/useTahfizOptions';
import {
  EXPERIENCE_LEVEL_LABELS,
  GENDER_LABELS,
  READING_ABILITY_LABELS,
  REGISTRATION_STATUS_LABELS,
  registrationStatusTone,
} from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  TahfizRegistrationRequest,
  TahfizRegistrationStatus,
} from '@/types/tahfiz';

/**
 * Review pengajuan pendaftaran mandiri Tahfiz.
 *
 * Pengajuan berasal dari formulir publik tanpa autentikasi; admin dapat
 * menyetujui (membuat peserta) atau menolak dengan catatan. Hanya pengajuan
 * berstatus `pending` yang dapat ditindak.
 */
export function TahfizRegistrationsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<TahfizRegistrationStatus | ''>('');
  const [programId, setProgramId] = useState<number | ''>('');
  const [search, setSearch] = useState('');

  const [rejecting, setRejecting] = useState<TahfizRegistrationRequest | null>(null);
  const [rejectErrors, setRejectErrors] = useState<Record<string, string[]>>({});

  const { options: programOptions } = useProgramOptions();

  const filters: Record<string, string | number> = {};
  if (status) filters.status = status;
  if (programId) filters.program_id = programId;

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'registrations', page, filters, search],
    queryFn: () =>
      registrationsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'registrations'] });

  const approveMutation = useMutation({
    mutationFn: (id: number) => registrationsApi.approve(id),
    onSuccess: (res) => {
      toast.success(res.message);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rejectMutation = useMutation({
    mutationFn: ({ id, note }: { id: number; note: string }) =>
      registrationsApi.reject(id, note),
    onSuccess: (res) => {
      toast.success(res.message);
      setRejecting(null);
      setRejectErrors({});
      invalidate();
    },
    onError: (err) => {
      setRejectErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Pendaftaran Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Review pengajuan pendaftaran mandiri. Pengajuan yang disetujui membuat peserta baru.
          </p>
        </div>
        <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
          <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as TahfizRegistrationStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {(Object.keys(REGISTRATION_STATUS_LABELS) as TahfizRegistrationStatus[]).map((key) => (
                <option key={key} value={key}>
                  {REGISTRATION_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Program</label>
            <SearchSelect
              options={programOptions}
              value={programId}
              onChange={(v) => {
                setProgramId(v === '' ? '' : Number(v));
                setPage(1);
              }}
              placeholder="-- Semua program --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Cari nama, telepon, atau email..."
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
            <Spinner /> Memuat pendaftaran...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat pendaftaran"
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
            title="Belum ada pengajuan"
            description="Pengajuan pendaftaran mandiri akan tampil di sini."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 font-medium">JK</th>
                  <th className="px-4 py-2 font-medium">Kontak</th>
                  <th className="px-4 py-2 font-medium">Program</th>
                  <th className="px-4 py-2 font-medium">Pengalaman</th>
                  <th className="px-4 py-2 font-medium">Bacaan</th>
                  <th className="px-4 py-2 font-medium">Hafalan</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-36 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2">
                      <div className="font-medium text-slate-800">{row.name}</div>
                      {row.birth_date && (
                        <div className="text-xs text-slate-400">{row.birth_date}</div>
                      )}
                    </td>
                    <td className="px-4 py-2 text-slate-600">
                      {row.gender ? GENDER_LABELS[row.gender] : '—'}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      <div>{row.phone ?? '—'}</div>
                      <div className="text-slate-400">{row.email ?? ''}</div>
                    </td>
                    <td className="px-4 py-2 text-slate-600">{row.program?.name ?? '—'}</td>
                    <td className="px-4 py-2 text-slate-600">
                      {row.experience_level
                        ? EXPERIENCE_LEVEL_LABELS[row.experience_level]
                        : '—'}
                    </td>
                    <td className="px-4 py-2 text-slate-600">
                      {row.reading_ability ? READING_ABILITY_LABELS[row.reading_ability] : '—'}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      <div>Terakhir: {row.last_memorized ?? '—'}</div>
                      <div>Target: {row.memorization_target ?? '—'}</div>
                      <div className="text-slate-400">
                        Jadwal: {row.schedule_preference ?? '—'}
                      </div>
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={registrationStatusTone(row.status)}>
                        {REGISTRATION_STATUS_LABELS[row.status]}
                      </Badge>
                      {row.status === 'approved' && row.participant && (
                        <div className="mt-1 text-xs text-slate-500">
                          Peserta: {row.participant.name}
                        </div>
                      )}
                      {row.review_note && (
                        <div className="mt-1 max-w-[14rem] text-xs text-red-600">
                          {row.review_note}
                        </div>
                      )}
                      {row.reviewed_at && (
                        <div className="mt-0.5 text-xs text-slate-400">{row.reviewed_at}</div>
                      )}
                    </td>
                    <td className="px-4 py-2">
                      {row.status === 'pending' ? (
                        <Can
                          perm="tahfiz.approve"
                          fallback={<span className="text-xs text-slate-400">Menunggu pemutus</span>}
                        >
                          <div className="flex flex-wrap gap-1">
                            <Button
                              size="sm"
                              variant="outline"
                              loading={approveMutation.isPending}
                              onClick={() => approveMutation.mutate(row.id)}
                            >
                              <CheckCircle2 className="h-3.5 w-3.5" /> Setujui
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => {
                                setRejectErrors({});
                                setRejecting(row);
                              }}
                            >
                              <XCircle className="h-3.5 w-3.5" /> Tolak
                            </Button>
                          </div>
                        </Can>
                      ) : (
                        <span className="text-xs text-slate-400">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <RegistrationRejectModal
        open={!!rejecting}
        registration={rejecting}
        submitting={rejectMutation.isPending}
        serverErrors={rejectErrors}
        onClose={() => setRejecting(null)}
        onSubmit={(note) => {
          if (rejecting) rejectMutation.mutate({ id: rejecting.id, note });
        }}
      />
    </div>
  );
}