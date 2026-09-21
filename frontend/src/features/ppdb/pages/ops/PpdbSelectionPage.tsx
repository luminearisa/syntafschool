import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { CalendarClock, ListChecks, Pencil, Plus, Scale, Trash2, UserPlus, Users } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbSelectionApi } from '../../api/ppdbApi';
import { ppdbHttp } from '../../api/http';
import {
  PpdbScopeFilters,
  RegistrationMultiPicker,
  usePpdbPeriodOptions,
  usePpdbProgramOptions,
} from '../../components/ops/PpdbOpsFilters';
import {
  ConfirmModal,
  EmptyList,
  ErrorLine,
  FilterField,
  LoadingLine,
  Notice,
  PageHeader,
  ReloadButton,
  SectionCard,
  StatCard,
  useUrlFilters,
} from '../../components/ops/PpdbOpsUi';
import {
  PARTICIPANT_STATUS_LABELS,
  PARTICIPANT_STATUS_OPTIONS,
  SELECTION_RESULT_LABELS,
  SELECTION_RESULT_OPTIONS,
  STAGE_STATUS_LABELS,
  STAGE_STATUS_OPTIONS,
  STAGE_TYPE_LABELS,
  STAGE_TYPE_OPTIONS,
  formatDateTime,
  participantStatusTone,
  stageStatusTone,
  toNumber,
} from '../../components/ops/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Pagination } from '@/components/common/Pagination';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { cn, formatDate } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  PpdbParticipantStatus,
  PpdbSelectionListResponse,
  PpdbSelectionParticipant,
  PpdbSelectionResult,
  PpdbSelectionStage,
  PpdbSelectionStagePayload,
  PpdbSelectionWeightsResponse,
  PpdbStageStatus,
  PpdbStageType,
  PpdbWeightSummary,
} from '@/types/ppdb';
import type { ApiResponse, PaginationMeta } from '@/types';

/** Nilai total bobot yang wajib dicapai satu grup tahap (PpdbSelectionService::REQUIRED_WEIGHT_TOTAL). */
const REQUIRED_WEIGHT_TOTAL = 100;

/**
 * `GET /ppdb/selection-stages` TIDAK mengembalikan paginasi datar: isinya objek
 * `{stages, weight_groups, pagination, required_weight_total}`
 * (PpdbSelectionController.php:57-85). Lapisan API masih mendeklarasikan
 * `PaginatedResponse`, jadi bentuknya dinormalkan di sini — kedua kemungkinan
 * ditangani tanpa mengarang data.
 */
type StageListEnvelope =
  | { data: PpdbSelectionListResponse; meta?: PaginationMeta }
  | { data: PpdbSelectionStage[]; meta?: PaginationMeta };

interface StageListView {
  stages: PpdbSelectionStage[];
  weightGroups: Record<string, PpdbWeightSummary>;
  pagination: PaginationMeta | null;
}

function readStageList(envelope: StageListEnvelope | undefined): StageListView {
  if (!envelope) return { stages: [], weightGroups: {}, pagination: null };

  if (Array.isArray(envelope.data)) {
    return { stages: envelope.data, weightGroups: {}, pagination: envelope.meta ?? null };
  }

  const payload = envelope.data;

  return {
    stages: payload.stages ?? [],
    weightGroups: payload.weight_groups ?? {},
    pagination: payload.pagination
      ? {
          current_page: payload.pagination.current_page,
          last_page: payload.pagination.last_page,
          per_page: payload.pagination.per_page,
          total: payload.pagination.total,
        }
      : (envelope.meta ?? null),
  };
}

/** Kunci `weight_groups` server: `{periode}:{program_id}` — program kosong jadi `0`. */
function weightGroupKey(periodId: number, programId: number | null): string {
  return `${periodId}:${programId ?? 0}`;
}

function weightTotal(stages: { weight: number }[]): number {
  return Math.round(stages.reduce((sum, stage) => sum + Number(stage.weight || 0), 0) * 100) / 100;
}

/** Label bobot: `75` bukan `75.50` bila tidak perlu desimal. */
function formatWeight(value: number): string {
  return Number.isInteger(value) ? String(value) : String(Math.round(value * 100) / 100);
}

type ScorePayload = Parameters<typeof ppdbSelectionApi.score>[1];

const BLANK_STAGE: PpdbSelectionStagePayload = {
  period_id: '',
  program_id: '',
  type: 'administrative',
  name: '',
  scheduled_at: '',
  location: '',
  quota: '',
  passing_score: '',
  weight: '',
  status: 'draft',
  order: '',
  notes: '',
};

/** Bentuk `PpdbSelectionStage` menjadi payload form (kolom null -> string kosong). */
function stageToPayload(stage: PpdbSelectionStage): PpdbSelectionStagePayload {
  return {
    period_id: stage.period_id,
    program_id: stage.program_id ?? '',
    type: stage.type,
    name: stage.name,
    scheduled_at: stage.scheduled_at ? stage.scheduled_at.slice(0, 16) : '',
    location: stage.location ?? '',
    quota: stage.quota ?? '',
    passing_score: stage.passing_score === null ? '' : toNumber(stage.passing_score),
    weight: stage.weight ?? '',
    status: stage.status,
    order: stage.order ?? '',
    notes: stage.notes ?? '',
  };
}

export function PpdbSelectionPage() {
  const filters = useUrlFilters(['period_id', 'program_id', 'stage_id', 'status']);
  const queryClient = useQueryClient();

  const stageId = filters.numeric('stage_id');
  const [form, setForm] = useState<{ payload: PpdbSelectionStagePayload; stage?: PpdbSelectionStage } | null>(
    null,
  );
  const [deleting, setDeleting] = useState<PpdbSelectionStage | null>(null);
  const [picking, setPicking] = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);

  const [participantPage, setParticipantPage] = useState(1);
  const [participantSearch, setParticipantSearch] = useState('');
  const [participantStatus, setParticipantStatus] = useState<PpdbParticipantStatus | ''>('');

  const stagesQuery = useQuery({
    queryKey: ['ppdb', 'selection-stages', filters.get('period_id'), filters.get('program_id'), filters.get('status')],
    placeholderData: (previous) => previous,
    queryFn: async (): Promise<StageListEnvelope> =>
      ppdbSelectionApi.list({
        page: 1,
        per_page: 50,
        period_id: filters.numeric('period_id') || undefined,
        program_id: filters.numeric('program_id') || undefined,
        status: filters.get('status') || undefined,
      }),
  });

  const stageList = readStageList(stagesQuery.data);
  const stages = stageList.stages;
  const weightGroups = stageList.weightGroups;
  const stageMeta = stageList.pagination;
  const activeStage = stages.find((stage) => stage.id === stageId) ?? null;

  /**
   * Nama program untuk kolom peserta. Baris peserta seleksi hanya mengirim
   * `registration.program_id` — `PpdbSelectionParticipantResource` tidak memuat
   * objek `registration.program` (PpdbSelectionService.php:84-104) — jadi
   * namanya diambil dari sumber opsi PPDB yang sudah ada
   * (`GET /ppdb/programs` per periode), bukan dari relasi master admin yang
   * berujung 403 untuk peran panitia.
   */
  const { options: programOptions } = usePpdbProgramOptions(
    activeStage ? activeStage.period_id : '',
  );
  const programNames = useMemo(() => {
    const map = new Map<number, string>();
    for (const option of programOptions) {
      if (typeof option.value === 'number') map.set(option.value, option.label);
    }
    return map;
  }, [programOptions]);

  const participantsQuery = useQuery({
    queryKey: [
      'ppdb',
      'selection-participants',
      activeStage?.id,
      { page: participantPage, search: participantSearch, status: participantStatus },
    ],
    enabled: Boolean(activeStage),
    placeholderData: (previous) => previous,
    queryFn: () =>
      ppdbSelectionApi.participants(activeStage!.id, {
        page: participantPage,
        per_page: 20,
        search: participantSearch || undefined,
        status: participantStatus || undefined,
      }),
  });

  const participants = participantsQuery.data?.data ?? [];
  const participantMeta = participantsQuery.data?.meta;

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['ppdb'] });
  };

  /**
   * Ringkasan bobot grup tahap yang sedang dibuka (periode + program terpilih).
   * Server mengirimnya di `weight_groups`; bila grup belum ikut terkirim, total
   * dihitung dari tahap pada halaman ini supaya angka tidak pernah dikarang.
   */
  const groupKey = activeStage ? weightGroupKey(activeStage.period_id, activeStage.program_id) : null;
  const activeGroup = groupKey ? (weightGroups[groupKey] ?? null) : null;
  const groupStages = useMemo<{ stage_id: number; name: string; weight: number }[]>(
    () =>
      activeGroup?.stages ??
      stages
        .filter((stage) => weightGroupKey(stage.period_id, stage.program_id) === groupKey)
        .map((stage) => ({ stage_id: stage.id, name: stage.name, weight: stage.weight ?? 0 })),
    [activeGroup, groupKey, stages],
  );
  const groupTotal = weightTotal(groupStages);

  /**
   * Bobot disimpan per grup (`PUT /ppdb/selection-stages/weights`): server
   * menuntut SELURUH tahap grup terkirim dengan total TEPAT 100% dan tidak
   * pernah menormalkan otomatis (PpdbSelectionService.php:448-533). Draf hanya
   * berlaku selama grup yang sama terbuka.
   */
  const [weightDraft, setWeightDraft] = useState<{ key: string | null; rows: Record<number, string> } | null>(
    null,
  );
  const draftRows = weightDraft && weightDraft.key === groupKey ? weightDraft.rows : null;
  const draftTotal = draftRows
    ? weightTotal(groupStages.map((row) => ({ weight: toNumber(draftRows[row.stage_id] ?? row.weight) })))
    : groupTotal;
  const draftDirty = groupStages.some(
    (row) => draftRows ? toNumber(draftRows[row.stage_id] ?? row.weight) !== row.weight : false,
  );
  /** Sisa bobot grup: positif = belum penuh, negatif = melebihi 100%. */
  const groupRemaining = Math.round((REQUIRED_WEIGHT_TOTAL - draftTotal) * 100) / 100;

  const weightsMutation = useMutation({
    mutationFn: async (args: { period_id: number; program_id: number | null; weights: { stage_id: number; weight: number }[] }) => {
      const res = await ppdbHttp.put<ApiResponse<PpdbSelectionWeightsResponse>>(
        '/ppdb/selection-stages/weights',
        args,
      );
      return res.data.data;
    },
    onSuccess: (payload) => {
      toast.success(
        `Bobot tersimpan — total grup ${formatWeight(payload.summary.total)}% dan skor gabungan dapat dihitung.`,
      );
      setWeightDraft(null);
      refresh();
      stagesQuery.refetch();
    },
    onError: (error) => {
      const first = Object.values(getValidationErrors(error) ?? {})[0]?.[0];
      toast.error(first ?? getErrorMessage(error));
    },
  });

  const saveWeights = () => {
    if (!activeStage || draftTotal !== REQUIRED_WEIGHT_TOTAL) return;
    weightsMutation.mutate({
      period_id: activeStage.period_id,
      program_id: activeStage.program_id,
      weights: groupStages.map((row) => ({
        stage_id: row.stage_id,
        weight: toNumber((draftRows?.[row.stage_id] ?? String(row.weight))),
      })),
    });
  };

  const saveMutation = useMutation({
    mutationFn: (args: { id?: number; payload: PpdbSelectionStagePayload }) => {
      const payload: PpdbSelectionStagePayload = { ...args.payload };
      if (payload.weight === '') {
        // `weight` kosong: jangan terkirim sama sekali. Service memakai
        // `array_key_exists('weight', $data)` (PpdbSelectionService.php:812) dan
        // nilai null akan terbaca sebagai bobot 0.
        delete payload.weight;
      }
      return args.id
        ? ppdbSelectionApi.update(args.id, payload)
        : ppdbSelectionApi.create(payload);
    },
    onSuccess: (stage, args) => {
      toast.success(args.id ? `Tahap ${stage.name} diperbarui.` : `Tahap ${stage.name} dibuat.`);
      setForm(null);
      refresh();
      stagesQuery.refetch();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => ppdbSelectionApi.remove(id),
    onSuccess: () => {
      toast.success('Tahap seleksi dihapus.');
      setDeleting(null);
      filters.set({ stage_id: '' });
      refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const registerMutation = useMutation({
    mutationFn: (args: { stageId: number; ids: number[] }) =>
      ppdbSelectionApi.registerParticipants(args.stageId, args.ids),
    onSuccess: (result) => {
      // `created` adalah DAFTAR baris, bukan angka (PpdbSelectionService.php:185).
      toast.success(`${result.created.length} peserta ditambahkan ke tahap ini.`);
      if (result.skipped.length > 0) {
        toast.warning(
          `${result.skipped.length} pendaftaran dilewati: ${result.skipped
            .map((item) => item.reason)
            .filter(Boolean)
            .join(' | ') || 'tidak memenuhi syarat'}`,
        );
      }
      setSelectedIds([]);
      setPicking(false);
      refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const scoreMutation = useMutation({
    mutationFn: (args: { participantId: number; payload: ScorePayload }) =>
      ppdbSelectionApi.score(args.participantId, args.payload),
    onSuccess: (participant) => {
      toast.success(`Catatan peserta #${participant.id} tersimpan.`);
      participantsQuery.refetch();
      stagesQuery.refetch();
    },
    onError: (error) => {
      const first = Object.values(getValidationErrors(error) ?? {})[0]?.[0];
      toast.error(first ?? getErrorMessage(error));
    },
  });

  const serverErrors = getValidationErrors(saveMutation.error);

  return (
    <div className="space-y-4">
      <PageHeader
        title="Seleksi Calon Siswa"
        description="Susun tahap seleksi, daftarkan peserta, catat kehadiran, lalu isi nilai yang dibandingkan dengan ambang lulus."
        badge={activeStage ? <Badge tone="brand">{activeStage.name}</Badge> : undefined}
        actions={
          <Can perm="ppdb.selection.manage">
            <Button
              onClick={() =>
                setForm({
                  payload: {
                    ...BLANK_STAGE,
                    period_id: filters.numeric('period_id'),
                    program_id: filters.numeric('program_id'),
                  },
                })
              }
            >
              <Plus className="h-4 w-4" /> Tahap baru
            </Button>
          </Can>
        }
      />

      <PpdbScopeFilters filters={filters} showSearch={false} showWaves={false}>
        <FilterField label="Status tahap">
          <Select
            value={filters.get('status')}
            onChange={(event) => filters.set({ status: event.target.value })}
          >
            <option value="">Semua status tahap</option>
            {STAGE_STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {STAGE_STATUS_LABELS[option.value]}
              </option>
            ))}
          </Select>
        </FilterField>
      </PpdbScopeFilters>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,340px)_1fr]">
        <Card className="p-3">
          <div className="flex items-center justify-between gap-2 px-1">
            <h2 className="text-sm font-semibold text-slate-900">Tahap seleksi</h2>
            <ReloadButton onClick={() => stagesQuery.refetch()} busy={stagesQuery.isFetching} />
          </div>
          <p className="mt-0.5 px-1 text-xs text-slate-500">
            Klik satu tahap untuk membuka peserta dan nilai tahap tersebut.
            {stageMeta ? ` ${stageMeta.total} tahap tercatat.` : ''}
          </p>

          {stagesQuery.isLoading ? (
            <LoadingLine label="Memuat tahap..." />
          ) : stagesQuery.isError ? (
            <ErrorLine message={getErrorMessage(stagesQuery.error)} onRetry={() => stagesQuery.refetch()} />
          ) : stages.length === 0 ? (
            <EmptyList
              title="Belum ada tahap"
              description="Tentukan periode lebih dulu, lalu buat tahap seperti tes akademik atau wawancara."
            />
          ) : (
            <ul className="mt-2 space-y-1.5">
              {stages.map((stage) => (
                <li key={stage.id}>
                  <button
                    type="button"
                    onClick={() => {
                      filters.set({ stage_id: stage.id });
                      setParticipantPage(1);
                      setPicking(false);
                    }}
                    className={cn(
                      'w-full rounded-lg border px-3 py-2 text-left transition-colors',
                      stage.id === stageId
                        ? 'border-brand-500 bg-brand-50'
                        : 'border-slate-200 hover:border-brand-300 hover:bg-slate-50',
                    )}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-slate-800">{stage.name}</p>
                        <p className="text-xs text-slate-500">
                          {STAGE_TYPE_LABELS[stage.type] ?? stage.type} · {stage.program?.name ?? 'semua program'}
                        </p>
                      </div>
                      <Badge tone={stageStatusTone(stage.status)}>
                        {STAGE_STATUS_LABELS[stage.status] ?? stage.status}
                      </Badge>
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500">
                      <span className="inline-flex items-center gap-1">
                        <CalendarClock className="h-3 w-3" />
                        {stage.scheduled_at ? formatDateTime(stage.scheduled_at) : 'jadwal belum diatur'}
                      </span>
                      <span className="inline-flex items-center gap-1">
                        <Users className="h-3 w-3" />
                        {stage.participants_count ?? 0} peserta ·{' '}
                        {/* `scored_participants_count` adalah withCount beralias;
                            kolom `scored_count` tidak pernah ada di payload. */}
                        {stage.scored_participants_count ?? 0} dinilai
                      </span>
                      <span className="inline-flex items-center gap-1">
                        <Scale className="h-3 w-3" />
                        bobot {formatWeight(stage.weight ?? 0)}%
                      </span>
                      {stage.quota != null && <span>kuota {stage.quota}</span>}
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <div className="space-y-4">
          {!activeStage ? (
            <Card className="p-6">
              <EmptyList
                title="Pilih satu tahap"
                description="Peserta, kehadiran, dan nilai selalu bekerja pada satu tahap yang sedang dibuka."
              />
            </Card>
          ) : (
            <>
              <SectionCard
                title={activeStage.name}
                description={`${STAGE_TYPE_LABELS[activeStage.type] ?? activeStage.type} · ${
                  activeStage.program?.name ?? 'semua program'
                } · ${activeStage.period?.name ?? 'periode terpilih'}`}
                action={
                  <Can perm="ppdb.selection.manage">
                    <div className="flex items-center gap-1.5">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setForm({ payload: stageToPayload(activeStage), stage: activeStage })}
                      >
                        <Pencil className="h-3.5 w-3.5" /> Ubah
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => setDeleting(activeStage)}>
                        <Trash2 className="h-3.5 w-3.5" /> Hapus
                      </Button>
                    </div>
                  </Can>
                }
              >
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                  <StatCard
                    label="Peserta"
                    value={participantMeta?.total ?? activeStage.participants_count ?? 0}
                    hint={
                      activeStage.quota != null
                        ? `Kuota tahap ${activeStage.quota}`
                        : 'Kuota tidak dibatasi pada tahap ini.'
                    }
                    tone="brand"
                  />
                  <StatCard
                    label="Sudah dinilai"
                    value={activeStage.scored_participants_count ?? 0}
                    hint="Dihitung server pada tahap ini."
                    tone="green"
                  />
                  <StatCard
                    label="Ambang lulus"
                    value={activeStage.passing_score ?? '—'}
                    hint="Nilai yang sama atau di atas ambang ditandai lolos tahap."
                    tone="blue"
                  />
                  <StatCard
                    label="Jadwal"
                    value={activeStage.scheduled_at ? formatDate(activeStage.scheduled_at) : '—'}
                    hint={activeStage.location ?? 'lokasi belum diisi'}
                  />
                </div>
                {activeStage.notes && (
                  <p className="mt-3 text-xs text-slate-500">Catatan tahap: {activeStage.notes}</p>
                )}
              </SectionCard>

              <SectionCard
                title="Bobot tahap pada skor gabungan"
                description={`Satu grup tahap (periode + program, termasuk tahap tanpa program) harus berjumlah tepat ${REQUIRED_WEIGHT_TOTAL}%. Bobot tidak pernah dinormalkan otomatis, dan grup yang belum pas menolak dihitung skor gabungannya.`}
                action={
                  <Badge tone={draftTotal === REQUIRED_WEIGHT_TOTAL ? 'green' : draftTotal > REQUIRED_WEIGHT_TOTAL ? 'red' : 'amber'}>
                    Total {formatWeight(draftTotal)}% / {REQUIRED_WEIGHT_TOTAL}%
                  </Badge>
                }
              >
                {groupStages.length === 0 ? (
                  <p className="text-sm text-slate-400">
                    Belum ada tahap pada grup ini. Buat tahap lebih dulu sebelum menyusun bobot.
                  </p>
                ) : (
                  <div className="space-y-2">
                    <ul className="space-y-1.5">
                      {groupStages.map((row) => {
                        const value = draftRows?.[row.stage_id] ?? String(row.weight);
                        return (
                          <li key={row.stage_id} className="flex flex-wrap items-center gap-2">
                            <span className="min-w-40 flex-1 truncate text-sm text-slate-700">{row.name}</span>
                            <Can
                              perm="ppdb.selection.manage"
                              fallback={
                                <span className="text-sm text-slate-600">{formatWeight(row.weight)}%</span>
                              }
                            >
                              <Input
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                className="w-24"
                                value={value}
                                onChange={(event) =>
                                  setWeightDraft({
                                    key: groupKey,
                                    rows: {
                                      ...(draftRows ?? Object.fromEntries(groupStages.map((item) => [item.stage_id, String(item.weight)]))),
                                      [row.stage_id]: event.target.value,
                                    },
                                  })
                                }
                              />
                              <span className="text-xs text-slate-400">%</span>
                            </Can>
                          </li>
                        );
                      })}
                    </ul>

                    <Notice tone={draftTotal === REQUIRED_WEIGHT_TOTAL ? 'green' : draftTotal > REQUIRED_WEIGHT_TOTAL ? 'red' : 'amber'}>
                      {draftTotal === REQUIRED_WEIGHT_TOTAL
                        ? `Total bobot grup ini tepat ${REQUIRED_WEIGHT_TOTAL}%. Skor gabungan dapat dihitung.`
                        : draftTotal > REQUIRED_WEIGHT_TOTAL
                          ? `Total bobot grup ini ${formatWeight(draftTotal)}%, melebihi ${REQUIRED_WEIGHT_TOTAL}%. Simpan ditolak — kurangi ${formatWeight(draftTotal - REQUIRED_WEIGHT_TOTAL)}% lebih dulu.`
                          : `Sisa bobot grup ini ${formatWeight(groupRemaining)}%. Skor gabungan calon pada grup ini belum dapat dihitung sampai totalnya pas ${REQUIRED_WEIGHT_TOTAL}%.`}
                    </Notice>

                    <Can perm="ppdb.selection.manage">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-xs text-slate-500">
                          {draftDirty ? 'Ubahan bobot belum disimpan.' : 'Bobot sesuai catatan server.'}
                        </p>
                        <Button
                          size="sm"
                          disabled={!draftDirty || draftTotal !== REQUIRED_WEIGHT_TOTAL}
                          loading={weightsMutation.isPending}
                          onClick={saveWeights}
                          title={
                            draftTotal === REQUIRED_WEIGHT_TOTAL
                              ? 'Simpan bobot seluruh tahap pada grup ini'
                              : `Total harus tepat ${REQUIRED_WEIGHT_TOTAL}% sebelum dapat disimpan`
                          }
                        >
                          <ListChecks className="h-4 w-4" /> Simpan bobot grup
                        </Button>
                      </div>
                    </Can>

                    {activeGroup?.error && (
                      <p className="text-xs text-red-600">{activeGroup.error}</p>
                    )}
                  </div>
                )}
              </SectionCard>

              <Can perm="ppdb.selection.manage">
                <Card className="p-4">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      <p className="text-sm font-medium text-slate-800">Peserta tahap ini</p>
                      <p className="text-xs text-slate-500">
                        Daftarkan pendaftar yang sudah diverifikasi; pendaftar yang tidak memenuhi syarat
                        dilewati dan alasannya ditampilkan.
                      </p>
                    </div>
                    <Button size="sm" variant="outline" onClick={() => setPicking((current) => !current)}>
                      <UserPlus className="h-4 w-4" />
                      {picking ? 'Tutup daftar peserta' : 'Daftarkan peserta'}
                    </Button>
                  </div>

                  {picking && (
                    <div className="mt-3 space-y-3 border-t border-slate-100 pt-3">
                      <RegistrationMultiPicker
                        periodId={activeStage.period_id}
                        selectedIds={selectedIds}
                        onToggle={(registration) =>
                          setSelectedIds((current) =>
                            current.includes(registration.id)
                              ? current.filter((id) => id !== registration.id)
                              : [...current, registration.id],
                          )
                        }
                        onReplace={setSelectedIds}
                      />
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-xs text-slate-500">{selectedIds.length} pendaftaran dipilih.</p>
                        <Button
                          size="sm"
                          disabled={selectedIds.length === 0}
                          loading={registerMutation.isPending}
                          onClick={() =>
                            registerMutation.mutate({ stageId: activeStage.id, ids: selectedIds })
                          }
                        >
                          <ListChecks className="h-4 w-4" /> Daftarkan ke tahap
                        </Button>
                      </div>
                    </div>
                  )}
                </Card>
              </Can>

              <Card className="p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                  <div className="grid flex-1 grid-cols-1 gap-3 sm:grid-cols-2">
                    <FilterField label="Cari peserta">
                      <Input
                        value={participantSearch}
                        placeholder="Nama calon / nomor pendaftaran"
                        onChange={(event) => {
                          setParticipantSearch(event.target.value);
                          setParticipantPage(1);
                        }}
                      />
                    </FilterField>
                    <FilterField label="Status peserta">
                      <Select
                        value={participantStatus}
                        onChange={(event) => {
                          setParticipantStatus(event.target.value as PpdbParticipantStatus | '');
                          setParticipantPage(1);
                        }}
                      >
                        <option value="">Semua status</option>
                        {PARTICIPANT_STATUS_OPTIONS.map((option) => (
                          <option key={option.value} value={option.value}>
                            {option.label}
                          </option>
                        ))}
                      </Select>
                    </FilterField>
                  </div>
                  <ReloadButton
                    onClick={() => participantsQuery.refetch()}
                    busy={participantsQuery.isFetching}
                  />
                </div>

                <div className="mt-3 overflow-x-auto">
                  {participantsQuery.isLoading ? (
                    <LoadingLine label="Memuat peserta..." />
                  ) : participantsQuery.isError ? (
                    <ErrorLine
                      message={getErrorMessage(participantsQuery.error)}
                      onRetry={() => participantsQuery.refetch()}
                    />
                  ) : participants.length === 0 ? (
                    <EmptyList
                      title="Belum ada peserta pada tahap ini"
                      description="Gunakan tombol Daftarkan peserta, atau pendaftar yang sudah diverifikasi dapat dimasukkan manual."
                    />
                  ) : (
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400">
                          <th className="px-2 py-2 text-left">Calon</th>
                          <th className="px-2 py-2 text-left">Kehadiran</th>
                          <th className="px-2 py-2 text-left">Nilai</th>
                          <th className="px-2 py-2 text-left">Hasil tahap</th>
                          <th className="px-2 py-2 text-left">Catatan penguji</th>
                          <th className="px-2 py-2 text-right">Simpan</th>
                        </tr>
                      </thead>
                      <tbody>
                        {participants.map((participant) => (
                          <ParticipantRow
                            key={participant.id}
                            participant={participant}
                            programNames={programNames}
                            passingScore={
                              activeStage.passing_score === null ? null : toNumber(activeStage.passing_score)
                            }
                            saving={
                              scoreMutation.isPending &&
                              scoreMutation.variables?.participantId === participant.id
                            }
                            onSave={(payload) =>
                              scoreMutation.mutate({ participantId: participant.id, payload })
                            }
                          />
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>

                {participantMeta && participantMeta.last_page > 1 && (
                  <div className="mt-2">
                    <Pagination meta={participantMeta} onPageChange={setParticipantPage} />
                  </div>
                )}

                <div className="mt-3">
                  <Notice tone="gray">
                    Nilai di sini adalah hasil satu tahap. Penetapan hasil akhir pendaftaran
                    (Lulus / Tidak lulus / Cadangan) dilakukan pada berkas pendaftaran atau lewat
                    tautan "Tetapkan hasil" di halaman Pengumuman.
                  </Notice>
                </div>
              </Card>
            </>
          )}
        </div>
      </div>

      {form && (
        <StageFormModal
          payload={form.payload}
          stage={form.stage}
          editing={Boolean(form.stage)}
          stages={stages}
          weightGroups={weightGroups}
          onChange={(payload) => setForm({ ...form, payload })}
          busy={saveMutation.isPending}
          errors={serverErrors}
          onClose={() => setForm(null)}
          onSubmit={() => saveMutation.mutate({ id: form.stage?.id, payload: form.payload })}
        />
      )}

      <ConfirmModal
        open={Boolean(deleting)}
        title={deleting ? `Hapus tahap ${deleting.name}` : 'Hapus tahap'}
        description="Menghapus tahap seleksi ikut melepas daftar peserta dan nilainya pada tahap tersebut."
        warning="Aksi ini tidak dapat dibatalkan. Bila tahap hanya batal dilaksanakan, ubah statusnya menjadi Dibatalkan."
        acknowledgeLabel="Saya paham nilai peserta pada tahap ini ikut terhapus."
        tone="danger"
        confirmLabel="Hapus tahap"
        busy={removeMutation.isPending}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && removeMutation.mutate(deleting.id)}
      />
    </div>
  );
}

/* ============================ BARIS PESERTA & PENILAIAN ============================ */

function ParticipantRow({
  participant,
  programNames,
  passingScore,
  saving,
  onSave,
}: {
  participant: PpdbSelectionParticipant;
  programNames: Map<number, string>;
  passingScore: number | null;
  saving: boolean;
  onSave: (payload: ScorePayload) => void;
}) {
  const originalScore = participant.score === null || participant.score === '' ? null : toNumber(participant.score);
  const [score, setScore] = useState(originalScore === null ? '' : String(originalScore));
  const [result, setResult] = useState<PpdbSelectionResult | ''>(participant.result ?? '');
  const [notes, setNotes] = useState(participant.notes ?? '');

  /**
   * Identitas calon pada baris ini BERSARANG di `registration`
   * (PpdbSelectionParticipantResource.php:38-48) — tidak ada kolom datar
   * `applicant_name` / `registration_number` pada peserta.
   */
  const identity = participant.registration ?? null;
  const programName =
    identity?.program_id != null ? (programNames.get(identity.program_id) ?? null) : null;

  const numeric = score === '' ? null : Number(score);
  const verdict =
    numeric != null && passingScore != null
      ? numeric >= passingScore
        ? { pass: true, label: `Lolos ambang ${passingScore}` }
        : { pass: false, label: `Di bawah ambang ${passingScore}` }
      : null;

  const dirty =
    numeric !== originalScore || result !== (participant.result ?? '') || notes !== (participant.notes ?? '');

  return (
    <tr className="border-b border-slate-50 align-top last:border-0">
      <td className="px-2 py-2">
        <p className="text-sm font-medium text-slate-800">
          {identity?.applicant_name ?? identity?.applicant?.full_name ?? 'Calon tanpa nama'}
        </p>
        <Link
          to={`/ppdb/registrations/${participant.registration_id}`}
          className="font-mono text-xs text-brand-700 hover:underline"
        >
          {identity?.registration_number ?? `#${participant.registration_id}`}
        </Link>
        <p className="text-[11px] text-slate-400">
          {programName ?? 'program tidak tercatat'}
        </p>
      </td>
      <td className="px-2 py-2">
        <Can perm="ppdb.selection.manage">
          <div className="flex flex-wrap items-center gap-1">
            {(['attended', 'absent', 'invited'] as PpdbParticipantStatus[]).map((status) => (
              <button
                key={status}
                type="button"
                onClick={() => onSave({ status })}
                className={cn(
                  'rounded-lg border px-2 py-1 text-xs font-medium transition-colors',
                  participant.status === status
                    ? 'border-brand-500 bg-brand-50 text-brand-700'
                    : 'border-slate-200 text-slate-500 hover:border-slate-300',
                )}
                title={`Tandai ${PARTICIPANT_STATUS_LABELS[status] ?? status}`}
              >
                {PARTICIPANT_STATUS_LABELS[status] ?? status}
              </button>
            ))}
          </div>
        </Can>
        <p className="mt-1 text-[11px] text-slate-400">
          {participant.attended_at
            ? `hadir ${formatDateTime(participant.attended_at)}`
            : 'belum ada catatan kehadiran'}
        </p>
      </td>
      <td className="px-2 py-2">
        <Can perm="ppdb.selection.manage" fallback={<span className="text-sm text-slate-600">{participant.score ?? '—'}</span>}>
          <Input
            type="number"
            step="0.01"
            className="w-24"
            value={score}
            placeholder="—"
            onChange={(event) => setScore(event.target.value)}
          />
        </Can>
        {verdict && (
          <p
            className={cn(
              'mt-1 text-[11px] font-medium',
              verdict.pass ? 'text-emerald-600' : 'text-red-600',
            )}
          >
            {verdict.label}
          </p>
        )}
      </td>
      <td className="px-2 py-2">
        <Can perm="ppdb.selection.manage" fallback={<span className="text-xs text-slate-500">{participant.result ? SELECTION_RESULT_LABELS[participant.result] : '—'}</span>}>
          <Select
            value={result}
            onChange={(event) => setResult(event.target.value as PpdbSelectionResult | '')}
          >
            <option value="">Belum ditetapkan</option>
            {SELECTION_RESULT_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {SELECTION_RESULT_LABELS[option.value]}
              </option>
            ))}
          </Select>
        </Can>
        <div className="mt-1">
          <Badge tone={participantStatusTone(participant.status)}>
            {PARTICIPANT_STATUS_LABELS[participant.status] ?? participant.status}
          </Badge>
        </div>
      </td>
      <td className="px-2 py-2">
        <Can
          perm="ppdb.selection.manage"
          fallback={<span className="text-xs text-slate-500">{participant.notes ?? '—'}</span>}
        >
          <Textarea
            rows={2}
            className="min-w-[180px]"
            value={notes}
            placeholder="Catatan penguji"
            onChange={(event) => setNotes(event.target.value)}
          />
        </Can>
      </td>
      <td className="px-2 py-2 text-right">
        <Can perm="ppdb.selection.manage">
          <Button
            size="sm"
            disabled={!dirty}
            loading={saving}
            onClick={() =>
              onSave({
                score: numeric ?? undefined,
                result: result || undefined,
                status: 'scored',
                notes: notes || undefined,
              })
            }
          >
            Simpan
          </Button>
        </Can>
      </td>
    </tr>
  );
}

/* ============================ MODAL FORM TAHAP ============================ */

function StageFormModal({
  payload,
  stage,
  editing,
  stages,
  weightGroups,
  onChange,
  busy,
  errors,
  onClose,
  onSubmit,
}: {
  payload: PpdbSelectionStagePayload;
  stage?: PpdbSelectionStage;
  editing: boolean;
  stages: PpdbSelectionStage[];
  weightGroups: Record<string, PpdbWeightSummary>;
  onChange: (payload: PpdbSelectionStagePayload) => void;
  busy: boolean;
  errors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: () => void;
}) {
  const { options: periodOptions } = usePpdbPeriodOptions();
  const { options: programOptions } = usePpdbProgramOptions(payload.period_id);
  const patch = (next: Partial<PpdbSelectionStagePayload>) => onChange({ ...payload, ...next });

  /**
   * Total bobot grup yang akan terjadi bila form ini disimpan. Grup = periode +
   * program tahap (tahap tanpa program ikut dihitung), sesuai kunci
   * `weight_groups` dan cakupan `PpdbSelectionService::weightSummary()`.
   */
  const groupKey =
    payload.period_id === ''
      ? null
      : weightGroupKey(
          Number(payload.period_id),
          payload.program_id === '' || payload.program_id === undefined ? null : Number(payload.program_id),
        );
  const otherWeights = (exceptId?: number) => {
    const group = groupKey ? weightGroups[groupKey] : undefined;
    if (group) {
      return group.stages
        .filter((row) => row.stage_id !== exceptId)
        .map((row) => ({ weight: row.weight }));
    }
    return stages
      .filter((row) => row.id !== exceptId && weightGroupKey(row.period_id, row.program_id) === groupKey)
      .map((row) => ({ weight: row.weight ?? 0 }));
  };

  // Tahap baru tanpa bobot diisi => server memakainya sebagai 100 (DEFAULT_WEIGHT);
  // pada perubahan, kolom yang dibiarkan kosong tidak dikirim agar nilainya utuh.
  const effectiveWeight =
    payload.weight === '' ? (editing ? (stage?.weight ?? 0) : REQUIRED_WEIGHT_TOTAL) : toNumber(payload.weight);
  const projectedTotal = weightTotal([...otherWeights(stage?.id), { weight: effectiveWeight }]);
  const overBudget = projectedTotal > REQUIRED_WEIGHT_TOTAL;
  const incompleteBudget = projectedTotal !== REQUIRED_WEIGHT_TOTAL;

  return (
    <Modal
      open
      size="lg"
      onClose={onClose}
      title={editing ? 'Ubah tahap seleksi' : 'Tahap seleksi baru'}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={busy}>
            Batal
          </Button>
          <Button
            loading={busy}
            disabled={!payload.name.trim() || payload.period_id === '' || overBudget}
            title={
              overBudget
                ? `Total bobot grup menjadi ${formatWeight(projectedTotal)}% — melebihi ${REQUIRED_WEIGHT_TOTAL}%`
                : undefined
            }
            onClick={onSubmit}
          >
            {editing ? 'Simpan perubahan' : 'Buat tahap'}
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
          <Label required>Nama tahap</Label>
          <Input
            value={payload.name}
            error={errors?.name?.[0]}
            placeholder="Contoh: Tes akademik gelombang 1"
            onChange={(event) => patch({ name: event.target.value })}
          />
        </div>
        <div>
          <Label required>Periode</Label>
          <SearchSelect
            options={periodOptions}
            value={payload.period_id}
            error={errors?.period_id?.[0]}
            placeholder="-- Pilih periode --"
            onChange={(value) => patch({ period_id: value === '' ? '' : Number(value), program_id: '' })}
          />
        </div>
        <div>
          <Label>Jenis tahap</Label>
          <Select value={payload.type} onChange={(event) => patch({ type: event.target.value as PpdbStageType })}>
            {STAGE_TYPE_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {STAGE_TYPE_LABELS[option.value]}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Program</Label>
          <SearchSelect
            options={programOptions}
            value={payload.program_id ?? ''}
            disabled={payload.period_id === ''}
            error={errors?.program_id?.[0]}
            placeholder={payload.period_id === '' ? 'Pilih periode dulu' : '-- Semua program --'}
            onChange={(value) => patch({ program_id: value === '' ? '' : Number(value) })}
          />
        </div>
        <div>
          <Label>Jadwal pelaksanaan</Label>
          <Input
            type="datetime-local"
            value={payload.scheduled_at ?? ''}
            error={errors?.scheduled_at?.[0]}
            onChange={(event) => patch({ scheduled_at: event.target.value })}
          />
        </div>
        <div>
          <Label>Lokasi</Label>
          <Input
            value={payload.location ?? ''}
            placeholder="Contoh: Gedung A ruang 2"
            onChange={(event) => patch({ location: event.target.value })}
          />
        </div>
        <div>
          <Label>Kuota tahap</Label>
          <Input
            type="number"
            value={payload.quota ?? ''}
            error={errors?.quota?.[0]}
            placeholder="Kosongkan bila tanpa batas"
            onChange={(event) => patch({ quota: event.target.value === '' ? '' : Number(event.target.value) })}
          />
        </div>
        <div>
          <Label>Ambang nilai lulus</Label>
          <Input
            type="number"
            step="0.01"
            value={payload.passing_score ?? ''}
            error={errors?.passing_score?.[0]}
            placeholder="Contoh: 75"
            onChange={(event) =>
              patch({ passing_score: event.target.value === '' ? '' : Number(event.target.value) })
            }
          />
        </div>
        <div>
          <Label>Bobot tahap (%)</Label>
          <Input
            type="number"
            step="0.01"
            min="0"
            max="100"
            value={payload.weight ?? ''}
            error={errors?.weight?.[0]}
            placeholder={editing ? 'tanpa perubahan' : '100'}
            onChange={(event) =>
              patch({ weight: event.target.value === '' ? '' : Number(event.target.value) })
            }
          />
          <p className="mt-1 text-xs text-slate-400">
            Porsi tahap ini pada skor gabungan. Total satu grup wajib tepat {REQUIRED_WEIGHT_TOTAL}%.
          </p>
        </div>
        <div>
          <Label>Status tahap</Label>
          <Select
            value={payload.status ?? 'draft'}
            onChange={(event) => patch({ status: event.target.value as PpdbStageStatus })}
          >
            {STAGE_STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {STAGE_STATUS_LABELS[option.value]}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Urutan</Label>
          <Input
            type="number"
            value={payload.order ?? ''}
            placeholder="0"
            onChange={(event) => patch({ order: event.target.value === '' ? '' : Number(event.target.value) })}
          />
          <p className="mt-1 text-xs text-slate-400">Menentukan urutan tahap pada daftar dan rekap seleksi.</p>
        </div>
        <div className="sm:col-span-2">
          <Label>Catatan tahap</Label>
          <Textarea
            rows={3}
            value={payload.notes ?? ''}
            placeholder="Instruksi untuk penguji atau peserta."
            onChange={(event) => patch({ notes: event.target.value })}
          />
        </div>
      </div>

      <div className="mt-3 space-y-2">
        <Notice tone={overBudget ? 'red' : incompleteBudget ? 'amber' : 'green'}>
          Total bobot grup ini sesudah disimpan: <strong>{formatWeight(projectedTotal)}%</strong> dari{' '}
          {REQUIRED_WEIGHT_TOTAL}%.{' '}
          {overBudget
            ? `Melebihi batas — simpan ditolak server (PpdbSelectionService::assertWeightBudget). Kurangi ${formatWeight(projectedTotal - REQUIRED_WEIGHT_TOTAL)}% lebih dulu.`
            : incompleteBudget
              ? `Belum tepat ${REQUIRED_WEIGHT_TOTAL}%, jadi skor gabungan calon pada grup ini menolak dihitung. Susun bobot sekaligus pada panel "Bobot tahap pada skor gabungan".`
              : `Pas — skor gabungan dapat dihitung.`}
        </Notice>
        <Notice tone="gray">
          Perubahan ini tidak menambah peserta. Gunakan "Daftarkan peserta" pada tahap yang aktif untuk
          memasukkan pendaftar yang sudah diverifikasi.
        </Notice>
      </div>
    </Modal>
  );
}
