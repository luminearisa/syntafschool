import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCheck, ClipboardList, Hourglass } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbRegistrationsApi } from '../../api/ppdbApi';
import { DocumentReviewPanel } from '../../components/ops/PpdbDocumentReview';
import { PpdbScopeFilters } from '../../components/ops/PpdbOpsFilters';
import { RegistrationWorkflowBar } from '../../components/ops/PpdbRegistrationWorkflow';
import {
  DocumentStatusBadge,
  FieldList,
  FieldRow,
  FilterField,
  LoadingLine,
  NoteModal,
  Notice,
  OpsTable,
  PageHeader,
  PaymentStatusBadge,
  RegistrationStatusBadge,
  ReloadButton,
  SectionCard,
  SidePanel,
  StatCard,
  TabsNav,
  useUrlFilters,
  type OpsColumn,
} from '../../components/ops/PpdbOpsUi';
import { Pagination } from '@/components/common/Pagination';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import {
  DOCUMENT_STATUS_OPTIONS,
  REGISTRATION_STATUS_LABELS,
  formatDateTime,
  relativeAge,
} from '../../components/ops/labels';
import type { PpdbRegistration } from '@/types/ppdb';

const QUEUE_TABS: { key: 'submitted' | 'under_review'; label: string; hint: string }[] = [
  { key: 'submitted', label: 'Terkirim', hint: 'Baru dikirim orang tua, belum disentuh petugas.' },
  { key: 'under_review', label: 'Sedang Ditinjau', hint: 'Sudah dibuka petugas, keputusan belum selesai.' },
];

export function PpdbVerificationPage() {
  const filters = useUrlFilters([
    'search',
    'period_id',
    'wave_id',
    'program_id',
    'document_status',
    'status',
  ]);
  const queryClient = useQueryClient();

  const queueKey: 'submitted' | 'under_review' = QUEUE_TABS.some((tab) => tab.key === filters.get('status'))
    ? (filters.get('status') as 'submitted' | 'under_review')
    : 'submitted';

  const [selected, setSelected] = useState<number[]>([]);
  const [reviewing, setReviewing] = useState<PpdbRegistration | null>(null);
  const [bulkOpen, setBulkOpen] = useState(false);

  const listQuery = useQuery({
    queryKey: ['ppdb', 'verification-queue', queueKey, filters.values],
    placeholderData: (previous) => previous,
    queryFn: () =>
      ppdbRegistrationsApi.list({
        ...filters.apiParams({ status: queueKey }),
        per_page: 15,
        sort_by: 'submitted_at',
        sort_dir: 'asc',
      }),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const countsQuery = useQuery({
    queryKey: ['ppdb', 'verification-queue-counts', filters.numeric('period_id')],
    staleTime: 1000 * 30,
    queryFn: async () => {
      const [submitted, underReview] = await Promise.all([
        ppdbRegistrationsApi.list({
          page: 1,
          per_page: 1,
          status: 'submitted',
          period_id: filters.numeric('period_id') || undefined,
        }),
        ppdbRegistrationsApi.list({
          page: 1,
          per_page: 1,
          status: 'under_review',
          period_id: filters.numeric('period_id') || undefined,
        }),
      ]);
      return { submitted: submitted.meta.total, under_review: underReview.meta.total };
    },
  });

  /* Pilihan tidak boleh menyeberang halaman/status — risiko menyetujui baris tak terlihat. */
  useEffect(() => {
    setSelected([]);
  }, [queueKey, filters.page]);

  const bulkMutation = useMutation({
    mutationFn: async (args: { ids: number[]; note: string }) => {
      const results = await Promise.allSettled(
        args.ids.map((id) => ppdbRegistrationsApi.verify(id, args.note || undefined)),
      );
      return {
        approved: results.filter((item) => item.status === 'fulfilled').length,
        rejected: results.filter((item) => item.status === 'rejected').length,
      };
    },
    onSuccess: (result) => {
      toast.success(
        result.rejected === 0
          ? `${result.approved} pendaftaran diverifikasi.`
          : `${result.approved} diverifikasi, ${result.rejected} gagal — buka berkasnya satu per satu.`,
      );
      setSelected([]);
      setBulkOpen(false);
      queryClient.invalidateQueries({ queryKey: ['ppdb'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const oldestWaiting = useMemo(
    () => rows.map((row) => relativeAge(row.submitted_at)).filter((age): age is string => Boolean(age))[0],
    [rows],
  );

  const selectableIds = rows.map((row) => row.id);
  const selectedOnPage = selected.filter((id) => selectableIds.includes(id));

  const columns = useMemo<OpsColumn<PpdbRegistration>[]>(
    () => [
      {
        key: 'select',
        label: 'Pilih',
        align: 'center',
        render: (row) => (
          <input
            type="checkbox"
            aria-label={`Pilih ${row.registration_number}`}
            className="h-4 w-4 rounded border-slate-300"
            checked={selected.includes(row.id)}
            onChange={(event) =>
              setSelected((current) =>
                event.target.checked
                  ? [...current, row.id]
                  : current.filter((id) => id !== row.id),
              )
            }
          />
        ),
      },
      {
        key: 'registration_number',
        label: 'Calon & nomor pendaftaran',
        render: (row) => (
          <div>
            <p className="text-sm font-medium text-slate-800">
              {row.applicant?.full_name ?? 'Calon belum terisi'}
            </p>
            <p className="font-mono text-xs text-slate-400">{row.registration_number}</p>
            <p className="text-xs text-slate-400">
              {row.program?.name ?? 'Program —'} · {row.wave?.name ?? 'Tanpa gelombang'}
            </p>
          </div>
        ),
      },
      {
        key: 'waiting',
        label: 'Menunggu sejak',
        render: (row) => (
          <div className="text-xs text-slate-500">
            <p>{row.submitted_at ? formatDateTime(row.submitted_at) : 'belum dikirim'}</p>
            {relativeAge(row.submitted_at) && (
              <p className="text-slate-400">{relativeAge(row.submitted_at)}</p>
            )}
          </div>
        ),
      },
      {
        key: 'documents',
        label: 'Dokumen',
        render: (row) => <DocumentStatusBadge status={row.document_status} />,
      },
      {
        key: 'payment',
        label: 'Pembayaran',
        render: (row) => <PaymentStatusBadge status={row.payment_status} />,
      },
      {
        key: 'revision',
        label: 'Riwayat revisi',
        quiet: true,
        render: (row) =>
          row.revision_note ? (
            <span className="text-xs text-amber-600">{row.revision_note}</span>
          ) : (
            <span className="text-xs text-slate-400">belum pernah diminta revisi</span>
          ),
      },
      {
        key: 'actions',
        label: 'Aksi',
        align: 'right',
        render: (row) => (
          <div className="flex flex-wrap items-center justify-end gap-1.5">
            <Button size="sm" variant="outline" onClick={() => setReviewing(row)}>
              <ClipboardList className="h-3.5 w-3.5" /> Tinjau dokumen
            </Button>
            <RegistrationWorkflowBar registration={row} variant="queue" onChanged={() => listQuery.refetch()} />
          </div>
        ),
      },
    ],
    [selected, listQuery],
  );

  return (
    <div className="space-y-4">
      <PageHeader
        title="Antrean Verifikasi"
        description="Periksa berkas pendaftar yang menunggu keputusan petugas, lalu setujui, minta revisi, atau tolak."
        badge={meta ? <Badge tone="amber">{meta.total} menunggu</Badge> : undefined}
        actions={
          <>
            <Can perm="ppdb.registration.verify">
              <Button
                variant="outline"
                disabled={selectedOnPage.length === 0}
                onClick={() => setBulkOpen(true)}
              >
                <CheckCheck className="h-4 w-4" /> Setujui terpilih ({selectedOnPage.length})
              </Button>
            </Can>
            <ReloadButton onClick={() => listQuery.refetch()} busy={listQuery.isFetching} />
          </>
        }
      />

      <TabsNav
        tabs={QUEUE_TABS.map((tab) => ({
          key: tab.key,
          label: tab.label,
          count: countsQuery.data?.[tab.key],
        }))}
        active={queueKey}
        onChange={(key) => filters.set({ status: key })}
      />

      <PpdbScopeFilters filters={filters} searchPlaceholder="Nomor pendaftaran, nama calon...">
        <FilterField label="Status dokumen">
          <Select
            value={filters.get('document_status')}
            onChange={(event) => filters.set({ document_status: event.target.value })}
          >
            <option value="">Semua status dokumen</option>
            {DOCUMENT_STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </FilterField>
      </PpdbScopeFilters>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard
          label={REGISTRATION_STATUS_LABELS[queueKey]}
          value={meta?.total ?? 0}
          hint={QUEUE_TABS.find((tab) => tab.key === queueKey)?.hint}
          tone={queueKey === 'submitted' ? 'blue' : 'amber'}
        />
        <StatCard
          label="Dokumen belum lengkap"
          value={rows.filter((row) => row.document_status !== 'approved').length}
          hint="Dari baris yang tampil di halaman ini."
          tone="amber"
        />
        <StatCard
          label="Paling lama menunggu"
          value={oldestWaiting ?? '—'}
          hint={oldestWaiting ? 'Segera tangani lebih dulu.' : 'Tidak ada antrean tertunda.'}
          tone="gray"
        />
      </div>

      {selected.length > 0 && (
        <Notice tone="blue">
          <span className="font-medium">{selectedOnPage.length} baris dipilih di halaman ini.</span>{' '}
          Pilihan otomatis kosong saat pindah halaman atau ganti status agar tidak ada berkas tak
          terlihat yang ikut disetujui.{' '}
          <button type="button" className="font-medium underline" onClick={() => setSelected([])}>
            Kosongkan
          </button>
        </Notice>
      )}

      <OpsTable
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
        loading={listQuery.isLoading}
        error={listQuery.isError ? getErrorMessage(listQuery.error) : undefined}
        emptyTitle="Antrean bersih"
        emptyDescription={`Tidak ada pendaftaran berstatus ${REGISTRATION_STATUS_LABELS[queueKey]} pada lingkup ini.`}
        footer={meta && <Pagination meta={meta} onPageChange={filters.setPage} />}
      />

      <SidePanel
        open={Boolean(reviewing)}
        title={reviewing ? `Tinjau ${reviewing.registration_number}` : 'Tinjau berkas'}
        description={
          reviewing
            ? `${reviewing.applicant?.full_name ?? 'Calon belum terisi'} — ${
                reviewing.program?.name ?? 'program belum dipilih'
              }`
            : undefined
        }
        onClose={() => setReviewing(null)}
        footer={
          reviewing && (
            <RegistrationWorkflowBar
              registration={reviewing}
              variant="queue"
              onChanged={() => {
                setReviewing(null);
                listQuery.refetch();
              }}
            />
          )
        }
      >
        {reviewing ? (
          <div className="space-y-4">
            <SectionCard title="Ringkasan sumbu status">
              <FieldList>
                <FieldRow label="Status pendaftaran">
                  <RegistrationStatusBadge status={reviewing.status} />
                </FieldRow>
                <FieldRow label="Status dokumen">
                  <DocumentStatusBadge status={reviewing.document_status} />
                </FieldRow>
                <FieldRow label="Status pembayaran">
                  <PaymentStatusBadge status={reviewing.payment_status} />
                </FieldRow>
                <FieldRow label="Dikirim">
                  {reviewing.submitted_at ? formatDateTime(reviewing.submitted_at) : '—'}
                </FieldRow>
                <FieldRow label="Akun orang tua">
                  {reviewing.parent_account?.name ?? reviewing.parent_account?.email ?? '—'}
                </FieldRow>
              </FieldList>
            </SectionCard>

            <DocumentReviewPanel
              registrationId={reviewing.id}
              title="Dokumen pendaftaran"
              description="Setujui atau tolak per berkas. Keputusan tercatat pada riwayat pendaftaran."
              onChanged={() => listQuery.refetch()}
            />

            <Notice tone="gray">
              <span className="inline-flex items-center gap-1">
                <Hourglass className="h-3.5 w-3.5" />
              </span>{' '}
              Berkas yang belum lengkap sebaiknya dikembalikan lewat "Minta revisi" — orang tua
              mendapat daftar bagian yang harus diperbaiki.
            </Notice>
          </div>
        ) : (
          <LoadingLine />
        )}
      </SidePanel>

      <NoteModal
        open={bulkOpen}
        title={`Setujui ${selectedOnPage.length} pendaftaran`}
        description="Semua berkas terpilih berpindah ke Terverifikasi. Catatan yang Anda tulis dipakai bersama untuk seluruh baris."
        noteLabel="Catatan petugas (opsional)"
        noteRequired={false}
        confirmLabel="Verifikasi terpilih"
        busy={bulkMutation.isPending}
        onClose={() => setBulkOpen(false)}
        onSubmit={(note) => bulkMutation.mutate({ ids: selectedOnPage, note })}
      />
    </div>
  );
}
