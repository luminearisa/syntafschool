import { useQuery } from '@tanstack/react-query';
import { ppdbFeesApi, ppdbProgramsApi, ppdbWavesApi } from '../api/ppdbApi';
import { usePpdbLookups } from './usePpdbReferenceOptions';
import type { SelectOption } from '@/constants/masterData';
import type { PpdbProgram, PpdbRegistrationFee, PpdbWave } from '@/types/ppdb';

/** Opsi gelombang milik satu periode (kosong bila periode belum dipilih). */
export function usePpdbWaveOptions(periodId: number | '', withTotalLabel = false) {
  const query = useQuery({
    queryKey: ['ppdb', 'waves', 'options', periodId],
    enabled: periodId !== '',
    staleTime: 1000 * 60 * 5,
    queryFn: () => ppdbWavesApi.listAll({ period_id: periodId }),
  });

  const rows = query.data ?? [];
  const options = rows.map<SelectOption>((wave) => ({
    value: wave.id,
    label: withTotalLabel ? waveLabel(wave, true) : wave.name,
  }));

  return { rows, options, isLoading: query.isLoading };
}

/** Opsi penawaran program PPDB milik satu periode. */
export function usePpdbProgramOptions(periodId: number | '') {
  const query = useQuery({
    queryKey: ['ppdb', 'programs', 'options', periodId],
    enabled: periodId !== '',
    staleTime: 1000 * 60 * 5,
    queryFn: () => ppdbProgramsApi.listAll({ period_id: periodId }),
  });

  const rows = query.data ?? [];
  const options = rows.map<SelectOption>((program) => ({
    value: program.id,
    label: program.code ? `${program.code} — ${program.name}` : program.name,
  }));

  return { rows, options, isLoading: query.isLoading };
}

/**
 * Master `programs` milik modul Kurikulum.
 *
 * `programs` sengaja TIDAK ada di registri master data (`constants/masterData.ts`),
 * jadi `useOptions('programs')` tidak akan pernah mengembalikan apa pun — dan
 * `GET /programs` butuh `academic.view` yang tidak dipegang panitia penerimaan.
 * Tabel `ppdb_programs.program_id` menunjuk ke master yang sama dengan halaman
 * Kurikulum → Program, jadi opsi ini dibaca lewat whitelist `GET /ppdb/lookups`:
 * baris yang sama, jalan baca yang memang boleh.
 */
export function usePpdbMasterProgramOptions() {
  const lookups = usePpdbLookups();
  const jenjangById = new Map(
    (lookups.data?.education_levels ?? []).map((level) => [level.id, level.jenjang]),
  );
  const rows = lookups.data?.programs ?? [];

  const options = rows.map<SelectOption>((program) => {
    const jenjang = program.education_level_id ? jenjangById.get(program.education_level_id) : undefined;

    return {
      value: program.id,
      label: jenjang ? `${program.name} (${jenjang})` : program.name,
    };
  });

  return { rows, options, isLoading: lookups.isLoading };
}

/** Biaya satu periode — dipakai untuk ringkasan total dan kolom override gelombang. */
export function usePpdbPeriodFees(periodId: number | '') {
  const query = useQuery({
    queryKey: ['ppdb', 'fees', 'all', periodId],
    enabled: periodId !== '',
    staleTime: 1000 * 30,
    queryFn: () => ppdbFeesApi.listAll({ period_id: periodId }),
  });

  const rows = query.data ?? [];
  return {
    rows: rows as PpdbRegistrationFee[],
    isLoading: query.isLoading,
    totalRequired: rows.reduce((sum, fee) => (fee.required ? sum + Number(fee.amount ?? 0) : sum), 0),
  };
}

export function waveLabel(wave: PpdbWave, withCode = false): string {
  const quota = wave.quota === null ? '' : ` — kuota ${wave.quota}`;
  return `${withCode && wave.code ? `${wave.code} — ` : ''}${wave.name}${quota}`;
}

export function programLabel(program: PpdbProgram): string {
  return program.code ? `${program.code} — ${program.name}` : program.name;
}
