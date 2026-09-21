import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, Gavel, Paperclip } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbAppealsApi } from '../../api/ppdbApi';
import { usePpdbPeriodOptions } from '../../components/ops/PpdbOpsFilters';
import {
  FilterField,
  LoadingLine,
  Notice,
  OpsTable,
  PageHeader,
  ReloadButton,
  SearchInput,
  SectionCard,
  SidePanel,
  StatCard,
  useUrlFilters,
  type OpsColumn,
} from '../../components/ops/PpdbOpsUi';
import {
  APPEAL_STATUS_LABELS,
  APPEAL_STATUS_OPTIONS,
  SELECTION_RESULT_LABELS,
  appealStatusTone,
  formatDateTime,
} from '../../components/ops/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import type { PpdbAppeal } from '@/types/ppdb';

export function PpdbAppealsPage() {
  const filters = useUrlFilters(['period_id', 'status']);
  const queryClient = useQueryClient();
  const periodOptions = usePpdbPeriodOptions();

  const [reviewing, setReviewing] = useState<PpdbAppeal | null>(null);
  const [decision, setDecision] = useState<'accepted' | 'rejected'>('accepted');
  const [note, setNote] = useState('');

  const listQuery = useQuery({
    queryKey: ['ppdb', 'appeals', filters.values],
    placeholderData: (previous) => previous,
    queryFn: () => ppdbAppealsApi.list({ ...filters.apiParams(), per_page: 15 }),
  });

  const detailQuery = useQuery({
    queryKey: ['ppdb', 'appeals', reviewing?.id],
    enabled: reviewing !== null,
    queryFn: () => ppdbAppealsApi.show(reviewing?.id as number),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const appeal = detailQuery.data ?? reviewing;

  const decideMutation = useMutation({
    mutationFn: (args: { id: number; decision: 'accepted' | 'rejected' }) =>
      ppdbAppealsApi.decide(args.id, { decision: args.decision, review_note: note }),
    onSuccess: (result, args) => {
      toast.success(
        args.decision === 'accepted'
          ? 'Sanggahan diterima — hasil seleksi belum berubah, berkas siap diputus ulang.'
          : 'Sanggahan ditolak.',
      );
      setReviewing(null);
      setNote('');
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
      if (args.decision === 'accepted' && result.result_changed) {
        toast.message('Periksa kembali hasil pada pendaftaran terkait.');
      }
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const columns: OpsColumn<PpdbAppeal>[] = [
    {
      key: 'reason',
      label: 'Sanggahan',
      render: (row) => (
        <div className="min-w-0">
          <button
            type="button"
            className="line-clamp-2 text-left text-sm font-medium text-slate-800 hover:text-brand-700"
            onClick={() => {
              setReviewing(row);
              setDecision('accepted');
              setNote('');
            }}
          >
            {row.reason}
          </button>
          <p className="mt-0.5 text-xs text-slate-400">
            {row.registration?.applicant_name ?? 'calon tidak tercatat'} ·{' '}
            <Link
              to={`/ppdb/registrations/${row.registration_id}`}
              className="text-brand-600 hover:underline"
            >
              {row.registration?.registration_number ?? `#${row.registration_id}`}
            </Link>
            {row.registration?.selection_result && (
              <span className="text-slate-400">
                {' '}· hasil {SELECTION_RESULT_LABELS[row.registration.selection_result]}
              </span>
            )}
          </p>
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (row) => (
        <Badge tone={appealStatusTone(row.status)}>
          {row.status_label ?? row.status}
        </Badge>
      ),
    },
    {
      key: 'period',
      label: 'Periode',
      quiet: true,
      render: (row) => <span className="text-xs text-slate-500">{row.period?.name ?? '-'}</span>,
    },
    {
      key: 'created_at',
      label: 'Diajukan',
      quiet: true,
      render: (row) => <span className="text-xs text-slate-500">{formatDateTime(row.created_at)}</span>,
    },
    {
      key: 'reviewed',
      label: 'Diputus',
      quiet: true,
      render: (row) => (
        <span className="text-xs text-slate-500">
          {row.reviewed_at ? formatDateTime(row.reviewed_at) : 'belum'}
          {row.reviewed_by_name && <span className="block text-slate-400">oleh {row.reviewed_by_name}</span>}
        </span>
      ),
    },
    {
      key: 'actions',
      label: 'Aksi',
      align: 'right',
      render: (row) => (
        <Can perm="ppdb.registration.verify">
          <Button
            size="sm"
            variant={row.is_decided ? 'ghost' : 'outline'}
            onClick={() => {
              setReviewing(row);
              setDecision('accepted');
              setNote('');
            }}
          >
            <Gavel className="h-3.5 w-3.5" /> {row.is_decided ? 'Riwayat' : 'Putuskan'}
          </Button>
        </Can>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <PageHeader
        title="Masa Sanggah"
        description="Periksa sanggahan orang tua atas hasil seleksi. Menerima sanggahan hanya membuka berkas untuk diputus ulang — hasil seleksi tidak pernah berubah otomatis."
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label="Total sanggahan" value={meta?.total ?? 0} hint="Sesuai filter periode/status." />
        <StatCard
          label="Menunggu pemeriksaan"
          value={rows.filter((row) => row.status === 'pending').length}
          tone="amber"
          hint="Dihitung dari baris pada halaman ini."
        />
        <StatCard
          label="Sudah diputus"
          value={rows.filter((row) => row.is_decided).length}
          tone="green"
          hint="Terima atau tolak; catatan pemeriksa wajib."
        />
      </div>

      <SectionCard title="Daftar sanggahan" description="Sanggahan tertunda ditampilkan lebih dulu.">
        <div className="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <FilterField label="Periode">
            <Select
              value={filters.get('period_id')}
              onChange={(event) => filters.set({ period_id: event.target.value })}
            >
              <option value="">Semua periode</option>
              {periodOptions.options.map((option) => (
                <option key={String(option.value)} value={String(option.value)}>
                  {option.label}
                </option>
              ))}
            </Select>
          </FilterField>
          <FilterField label="Status">
            <Select
              value={filters.get('status')}
              onChange={(event) => filters.set({ status: event.target.value })}
            >
              <option value="">Semua status</option>
              {APPEAL_STATUS_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {APPEAL_STATUS_LABELS[option.value]}
                </option>
              ))}
            </Select>
          </FilterField>
          <SearchInput
            value={filters.get('search')}
            onChange={(value) => filters.set({ search: value })}
            placeholder="Nomor pendaftaran atau nama calon"
            label="Cari sanggahan"
          />
        </div>

        <OpsTable
          columns={columns}
          rows={rows}
          rowKey={(row) => row.id}
          loading={listQuery.isLoading}
          error={listQuery.isError ? getErrorMessage(listQuery.error) : undefined}
          emptyTitle="Belum ada sanggahan"
          emptyDescription="Sanggahan muncul di sini setelah orang tua mengirimnya dari portal selama masa sanggah dibuka."
          footer={meta && <Pagination meta={meta} onPageChange={filters.setPage} />}
        />

        <div className="mt-3 flex justify-end">
          <ReloadButton onClick={() => listQuery.refetch()} busy={listQuery.isFetching} />
        </div>
      </SectionCard>

      <SidePanel
        open={reviewing !== null}
        title="Detail sanggahan"
        description={
          appeal
            ? `${appeal.registration?.applicant_name ?? 'Calon'} · ${appeal.registration?.registration_number ?? '-'}`
            : undefined
        }
        onClose={() => setReviewing(null)}
        footer={
          appeal &&
          !appeal.is_decided && (
            <Can perm="ppdb.registration.verify">
              <div className="flex flex-wrap items-center gap-2">
                <Button
                  variant={decision === 'accepted' ? 'primary' : 'outline'}
                  size="sm"
                  loading={decideMutation.isPending && decision === 'accepted'}
                  disabled={note.trim().length < 10}
                  onClick={() => decideMutation.mutate({ id: appeal.id, decision: 'accepted' })}
                >
                  Terima sanggahan
                </Button>
                <Button
                  variant={decision === 'rejected' ? 'danger' : 'outline'}
                  size="sm"
                  loading={decideMutation.isPending && decision === 'rejected'}
                  disabled={note.trim().length < 10}
                  onClick={() => decideMutation.mutate({ id: appeal.id, decision: 'rejected' })}
                >
                  Tolak sanggahan
                </Button>
                <span className="text-xs text-slate-400">Catatan minimal 10 karakter.</span>
              </div>
            </Can>
          )
        }
      >
        {appeal ? (
          <div className="space-y-4">
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-slate-400">Alasan sanggahan</p>
              <p className="mt-1 whitespace-pre-line text-sm text-slate-700">{appeal.reason}</p>
            </div>

            {appeal.attachment_url && (
              <a
                href={appeal.attachment_url}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1.5 text-sm text-brand-600 hover:underline"
              >
                <Paperclip className="h-3.5 w-3.5" /> Lampiran pendukung <ExternalLink className="h-3 w-3" />
              </a>
            )}

            <div className="grid grid-cols-2 gap-3 text-sm">
              <div>
                <p className="text-xs text-slate-400">Status</p>
                <Badge tone={appealStatusTone(appeal.status)}>{appeal.status_label ?? appeal.status}</Badge>
              </div>
              <div>
                <p className="text-xs text-slate-400">Hasil seleksi saat ini</p>
                <p className="text-slate-700">
                  {appeal.registration?.selection_result
                    ? SELECTION_RESULT_LABELS[appeal.registration.selection_result]
                    : 'belum diumumkan'}
                </p>
              </div>
              <div>
                <p className="text-xs text-slate-400">Masa sanggah</p>
                <p className="text-slate-700">
                  {appeal.period?.appeal_start ?? '-'} s.d. {appeal.period?.appeal_end ?? '-'}
                </p>
              </div>
              <div>
                <p className="text-xs text-slate-400">Diajukan</p>
                <p className="text-slate-700">{formatDateTime(appeal.created_at)}</p>
              </div>
            </div>

            {detailQuery.isLoading && <LoadingLine label="Memuat detail..." />}

            {appeal.is_decided ? (
              <Notice tone="gray">
                <p className="text-xs font-medium uppercase tracking-wide text-slate-400">Sudah diputus</p>
                <p className="mt-1 text-sm text-slate-600">
                  {appeal.review_note ?? 'Tanpa catatan pemeriksa.'}
                  {appeal.reviewed_by_name && (
                    <span className="block text-xs text-slate-400">
                      {appeal.reviewed_by_name} · {formatDateTime(appeal.reviewed_at)}
                    </span>
                  )}
                </p>
              </Notice>
            ) : (
              <>
                <Notice tone="amber">
                  <p className="text-xs font-medium uppercase tracking-wide">Menerima tidak mengubah hasil</p>
                  <p className="mt-1 text-sm text-slate-600">
                    Sanggahan yang diterima hanya membuka kembali keputusan untuk panitia. Tetapkan hasil baru
                    secara eksplisit pada{' '}
                    <Link
                      to={`/ppdb/registrations/${appeal.registration_id}`}
                      className="text-brand-600 hover:underline"
                    >
                      detail pendaftaran
                    </Link>{' '}
                    bila memang berubah — kuota tetap dijaga.
                  </p>
                </Notice>
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600">Catatan pemeriksa</label>
                  <Textarea
                    rows={4}
                    value={note}
                    onChange={(event) => setNote(event.target.value)}
                    placeholder="Jelaskan dasar keputusan, mis. berkas nilai yang ditinjau ulang."
                  />
                </div>
              </>
            )}
          </div>
        ) : (
          <LoadingLine />
        )}
      </SidePanel>
    </div>
  );
}
