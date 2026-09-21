import { useMemo, useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Search } from 'lucide-react';
import {
  ppdbPeriodsApi,
  ppdbProgramsApi,
  ppdbRegistrationsApi,
  ppdbWavesApi,
} from '../../api/ppdbApi';
import {
  FilterField,
  PaymentStatusBadge,
  RegistrationStatusBadge,
  SearchInput,
  useDebouncedValue,
  type UrlFilters,
} from './PpdbOpsUi';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { REGISTRATION_STATUS_OPTIONS } from './labels';
import { getErrorMessage } from '@/lib/axios';
import type { SelectOption } from '@/constants/masterData';
import type { PpdbRegistration, PpdbRegistrationStatus } from '@/types/ppdb';

/* ============================ OPSI LINGKUP ============================ */

export function usePpdbPeriodOptions() {
  const query = useQuery({
    queryKey: ['ppdb', 'options', 'periods'],
    staleTime: 1000 * 60 * 10,
    queryFn: () => ppdbPeriodsApi.listAll(),
  });
  const options = useMemo<SelectOption[]>(
    () => (query.data ?? []).map((period) => ({ value: period.id, label: period.name })),
    [query.data],
  );
  return { options, isLoading: query.isLoading, error: query.error, periods: query.data ?? [] };
}

export function usePpdbWaveOptions(periodId: number | '') {
  const query = useQuery({
    queryKey: ['ppdb', 'options', 'waves', periodId],
    enabled: periodId !== '',
    staleTime: 1000 * 60 * 10,
    queryFn: () => ppdbWavesApi.listAll({ period_id: periodId }),
  });
  const options = useMemo<SelectOption[]>(
    () => (query.data ?? []).map((wave) => ({ value: wave.id, label: wave.name })),
    [query.data],
  );
  return { options, isLoading: query.isLoading };
}

export function usePpdbProgramOptions(periodId: number | '') {
  const query = useQuery({
    queryKey: ['ppdb', 'options', 'programs', periodId],
    enabled: periodId !== '',
    staleTime: 1000 * 60 * 10,
    queryFn: () => ppdbProgramsApi.listAll({ period_id: periodId }),
  });
  const options = useMemo<SelectOption[]>(
    () => (query.data ?? []).map((program) => ({ value: program.id, label: program.name })),
    [query.data],
  );
  return { options, isLoading: query.isLoading };
}

/**
 * Baris filter yang sama di semua halaman operasi: pencarian + periode +
 * gelombang + program (+ rentang tanggal). Nilainya hidup di query string.
 */
export function PpdbScopeFilters({
  filters,
  showSearch = true,
  showDates = false,
  showWaves = true,
  showPrograms = true,
  searchPlaceholder = 'Nomor pendaftaran, nama calon, NISN...',
  children,
}: {
  filters: UrlFilters;
  showSearch?: boolean;
  showDates?: boolean;
  showWaves?: boolean;
  showPrograms?: boolean;
  searchPlaceholder?: string;
  children?: ReactNode;
}) {
  const { options: periodOptions, isLoading: periodsLoading } = usePpdbPeriodOptions();
  const periodId = filters.numeric('period_id');
  const { options: waveOptions } = usePpdbWaveOptions(periodId);
  const { options: programOptions } = usePpdbProgramOptions(periodId);

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {showSearch && (
          <SearchInput
            value={filters.get('search')}
            placeholder={searchPlaceholder}
            onChange={(value) => filters.set({ search: value })}
          />
        )}
        <FilterField label="Periode">
          <SearchSelect
            options={periodOptions}
            value={periodId}
            disabled={periodsLoading}
            placeholder={periodsLoading ? 'Memuat periode...' : '-- Semua periode --'}
            onChange={(value) => filters.set({ period_id: value, wave_id: '', program_id: '' })}
          />
        </FilterField>
        {showWaves && (
          <FilterField label="Gelombang">
            <SearchSelect
              options={waveOptions}
              value={filters.numeric('wave_id')}
              disabled={periodId === ''}
              placeholder={periodId === '' ? 'Pilih periode dulu' : '-- Semua gelombang --'}
              onChange={(value) => filters.set({ wave_id: value })}
            />
          </FilterField>
        )}
        {showPrograms && (
          <FilterField label="Program">
            <SearchSelect
              options={programOptions}
              value={filters.numeric('program_id')}
              disabled={periodId === ''}
              placeholder={periodId === '' ? 'Pilih periode dulu' : '-- Semua program --'}
              onChange={(value) => filters.set({ program_id: value })}
            />
          </FilterField>
        )}
        {children}
      </div>

      {showDates && (
        <div className="mt-3 grid grid-cols-1 gap-3 border-t border-slate-100 pt-3 sm:grid-cols-2 lg:grid-cols-4">
          <FilterField label="Dari tanggal">
            <Input
              type="date"
              value={filters.get('date_from')}
              onChange={(event) => filters.set({ date_from: event.target.value })}
            />
          </FilterField>
          <FilterField label="Sampai tanggal">
            <Input
              type="date"
              value={filters.get('date_to')}
              onChange={(event) => filters.set({ date_to: event.target.value })}
            />
          </FilterField>
        </div>
      )}
    </div>
  );
}

/* ============================ PEMILIH PENDAFTARAN ============================ */

export function registrationLabel(registration: PpdbRegistration): string {
  const name = registration.applicant?.full_name ?? 'Tanpa nama';
  const program = registration.program?.name;
  const wave = registration.wave?.name;
  return [
    registration.registration_number,
    '—',
    name,
    program ? `(${program}${wave ? `, ${wave}` : ''})` : '',
  ]
    .filter(Boolean)
    .join(' ')
    .trim();
}

interface RegistrationSearchArgs {
  search: string;
  status?: PpdbRegistrationStatus | '';
  periodId?: number | '';
  waveId?: number | '';
  programId?: number | '';
  page: number;
  perPage?: number;
  enabled?: boolean;
}

export function useRegistrationSearch({
  search,
  status,
  periodId,
  waveId,
  programId,
  page,
  perPage = 20,
  enabled = true,
}: RegistrationSearchArgs) {
  const debounced = useDebouncedValue(search);
  return useQuery({
    queryKey: [
      'ppdb',
      'registration-options',
      { search: debounced, status, periodId, waveId, programId, page, perPage },
    ],
    enabled,
    placeholderData: (previous) => previous,
    queryFn: () =>
      ppdbRegistrationsApi.list({
        page,
        per_page: perPage,
        search: debounced || undefined,
        status: status || undefined,
        period_id: periodId || undefined,
        wave_id: waveId || undefined,
        program_id: programId || undefined,
      }),
  });
}

/** Pilih satu pendaftaran (cari + dropdown) untuk aksi manual petugas. */
export function RegistrationPicker({
  value,
  onChange,
  status = '',
  periodId = '',
  error,
  label = 'Pendaftaran',
  required,
}: {
  value: number | '';
  onChange: (value: number | '') => void;
  status?: PpdbRegistrationStatus | '';
  periodId?: number | '';
  error?: string;
  label?: string;
  required?: boolean;
}) {
  const [search, setSearch] = useState('');
  const query = useRegistrationSearch({ search, status, periodId, page: 1, perPage: 25 });
  const options = useMemo<SelectOption[]>(
    () => (query.data?.data ?? []).map((row) => ({ value: row.id, label: registrationLabel(row) })),
    [query.data],
  );

  return (
    <div>
      <Label required={required}>{label}</Label>
      <div className="mb-1.5 relative">
        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
        <Input
          value={search}
          placeholder="Ketik nomor pendaftaran atau nama calon..."
          className="pl-8"
          onChange={(event) => setSearch(event.target.value)}
        />
      </div>
      <SearchSelect
        options={options}
        value={value}
        error={error}
        placeholder={query.isFetching ? 'Memuat pendaftaran...' : '-- Pilih pendaftaran --'}
        onChange={(next) => onChange(next === '' ? '' : Number(next))}
      />
      {query.isError && (
        <p className="mt-1 text-xs text-red-500">{getErrorMessage(query.error)}</p>
      )}
    </div>
  );
}

/** Pilih banyak pendaftaran untuk didaftarkan ke satu tahap seleksi. */
export function RegistrationMultiPicker({
  periodId,
  selectedIds,
  onToggle,
  onReplace,
}: {
  periodId: number | '';
  selectedIds: number[];
  onToggle: (registration: PpdbRegistration) => void;
  onReplace: (ids: number[]) => void;
}) {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<PpdbRegistrationStatus | ''>('verified');
  const [page, setPage] = useState(1);
  const query = useRegistrationSearch({ search, status, periodId, page, perPage: 10 });
  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <div className="space-y-2">
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_auto]">
        <SearchInput
          value={search}
          label="Cari pendaftaran"
          placeholder="Nomor / nama calon..."
          onChange={(value) => {
            setSearch(value);
            setPage(1);
          }}
        />
        <FilterField label="Status">
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as PpdbRegistrationStatus | '');
              setPage(1);
            }}
          >
            <option value="">Semua status</option>
            {REGISTRATION_STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </FilterField>
      </div>

      {selectedIds.length > 0 && (
        <p className="text-xs text-slate-500">
          {selectedIds.length} pendaftaran dipilih.{' '}
          <button type="button" className="text-brand-700 hover:underline" onClick={() => onReplace([])}>
            Kosongkan pilihan
          </button>
        </p>
      )}

      <div className="max-h-64 overflow-y-auto rounded-lg border border-slate-200">
        {query.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-8 text-slate-400">
            <Spinner /> Memuat...
          </div>
        ) : rows.length === 0 ? (
          <p className="py-8 text-center text-sm text-slate-400">
            Tidak ada pendaftaran yang cocok. Ubah status atau kata kunci.
          </p>
        ) : (
          <ul className="divide-y divide-slate-50">
            {rows.map((row) => (
              <li key={row.id} className="flex items-start gap-2 px-3 py-2">
                <input
                  type="checkbox"
                  className="mt-1 h-4 w-4 rounded border-slate-300"
                  checked={selectedIds.includes(row.id)}
                  onChange={() => onToggle(row)}
                />
                <div className="min-w-0">
                  <p className="truncate text-sm text-slate-700">
                    {row.applicant?.full_name ?? 'Tanpa nama'}
                  </p>
                  <p className="font-mono text-xs text-slate-400">{row.registration_number}</p>
                  <div className="mt-1 flex flex-wrap items-center gap-1.5">
                    <RegistrationStatusBadge status={row.status} />
                    <PaymentStatusBadge status={row.payment_status} />
                    <span className="text-xs text-slate-400">
                      {row.program?.name ?? '—'}
                      {row.wave?.name ? ` · ${row.wave.name}` : ''}
                    </span>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-xs text-slate-500">
          <span>
            Halaman {meta.current_page} dari {meta.last_page} · total {meta.total}
          </span>
          <div className="flex gap-1">
            <button
              type="button"
              className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-40"
              disabled={page <= 1}
              onClick={() => setPage((current) => Math.max(1, current - 1))}
            >
              Sebelumnya
            </button>
            <button
              type="button"
              className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-40"
              disabled={page >= meta.last_page}
              onClick={() => setPage((current) => current + 1)}
            >
              Berikutnya
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
