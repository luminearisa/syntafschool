import { useQuery } from '@tanstack/react-query';
import type { SelectOption } from '@/constants/masterData';
import {
  halaqahsApi,
  instructorsApi,
  levelsApi,
  mistakeTypesApi,
  mutabaahItemsApi,
  participantsApi,
  programsApi,
  rubricsApi,
  surahsApi,
} from '../api/tahfizApi';

/** Opsi dropdown modul Tahfiz (semua di-cache agar tidak berulang). */
const STALE = 1000 * 60 * 10;

export function useProgramOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['tahfiz', 'options', 'programs'],
    staleTime: STALE,
    queryFn: async () => {
      const res = await programsApi.list({ all: true });
      return res.data.map<SelectOption>((p) => ({
        value: p.id,
        label: `${p.code} — ${p.name}`,
      }));
    },
  });

  return { options: data ?? [], isLoading };
}

export function useLevelOptions(programId?: number | '') {
  const { data, isLoading } = useQuery({
    queryKey: ['tahfiz', 'options', 'levels', programId ?? null],
    staleTime: STALE,
    queryFn: async () => {
      const res = await levelsApi.list({
        all: true,
        filter: programId ? { program_id: programId } : undefined,
      });
      return res.data.map<SelectOption>((l) => ({ value: l.id, label: l.name }));
    },
  });

  return { options: data ?? [], isLoading };
}

export function useHalaqahOptions(programId?: number | '') {
  const { data, isLoading } = useQuery({
    queryKey: ['tahfiz', 'options', 'halaqahs', programId ?? null],
    staleTime: STALE,
    queryFn: async () => {
      const res = await halaqahsApi.list({
        all: true,
        filter: programId ? { program_id: programId } : undefined,
      });
      return res.data.map<SelectOption>((h) => ({
        value: h.id,
        label: `${h.code} — ${h.name}`,
      }));
    },
  });

  return { options: data ?? [], isLoading };
}

export function useParticipantOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['tahfiz', 'options', 'participants'],
    staleTime: STALE,
    queryFn: async () => {
      const res = await participantsApi.list({ all: true });
      return res.data.map<SelectOption>((p) => ({
        value: p.id,
        label: p.name,
      }));
    },
  });

  return { options: data ?? [], isLoading };
}

export function useInstructorOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['tahfiz', 'options', 'instructors'],
    staleTime: STALE,
    queryFn: async () => {
      const res = await instructorsApi.list({ all: true });
      return res.data.map<SelectOption>((i) => ({
        value: i.id,
        label: i.employee?.full_name ?? `Instruktur #${i.id}`,
      }));
    },
  });

  return { options: data ?? [], isLoading };
}

export function useRubricOptions(scope?: string) {
  const { data, isLoading } = useQuery({
    queryKey: ['tahfiz', 'options', 'rubrics', scope ?? null],
    staleTime: STALE,
    queryFn: async () => {
      const res = await rubricsApi.list({
        all: true,
        filter: scope ? { scope } : undefined,
      });
      return res.data.map<SelectOption>((r) => ({ value: r.id, label: r.name }));
    },
  });

  return { options: data ?? [], isLoading };
}

export function useMistakeTypeOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['tahfiz', 'options', 'mistake-types'],
    staleTime: STALE,
    queryFn: async () => {
      const res = await mistakeTypesApi.list({ all: true, filter: { is_active: 1 } });
      return res.data.map<SelectOption>((m) => ({ value: m.id, label: m.label }));
    },
  });

  return { options: data ?? [], isLoading };
}

export function useMutabaahItemOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['tahfiz', 'options', 'mutabaah-items'],
    staleTime: STALE,
    queryFn: async () => {
      const res = await mutabaahItemsApi.list({ all: true, filter: { is_active: 1 } });
      return res.data;
    },
  });

  return { items: data ?? [], isLoading };
}

export function useSurahOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['tahfiz', 'options', 'surahs'],
    staleTime: STALE,
    queryFn: async () => {
      const rows = await surahsApi.list();
      return rows.map<SelectOption>((s) => ({
        value: s.id,
        label: `${s.number}. ${s.name}`,
      }));
    },
  });

  return { options: data ?? [], isLoading };
}

/** Surah lengkap (bukan hanya opsi) — untuk validasi jumlah ayat di klien. */
export function useSurahs() {
  const { data, isLoading } = useQuery({
    queryKey: ['tahfiz', 'surahs'],
    staleTime: STALE,
    queryFn: () => surahsApi.list(),
  });

  return { surahs: data ?? [], isLoading };
}