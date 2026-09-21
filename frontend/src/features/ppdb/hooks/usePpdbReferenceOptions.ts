import { useQuery } from '@tanstack/react-query';
import { ppdbLookupsApi } from '../api/ppdbApi';
import type { PpdbLookupGroup } from '@/types/ppdb';
import type { SelectOption } from '@/constants/masterData';

/**
 * Opsi referensi master data untuk halaman PPDB.
 *
 * Sumbernya `GET /ppdb/lookups`, bukan `useOptions()` yang menunjuk `/master/*`:
 * peran `panitia-ppdb` hanya memegang `ppdb.*`, jadi setiap dropdown yang dibaca
 * dari endpoint master menjawab 403 dan tampil kosong tanpa pesan apa pun.
 */
export function usePpdbLookups() {
  return useQuery({
    queryKey: ['ppdb', 'lookups'],
    staleTime: 1000 * 60 * 10,
    queryFn: () => ppdbLookupsApi.get(),
  });
}

/** Satu kelompok referensi, sudah berbentuk opsi dropdown. */
export function usePpdbReferenceOptions(group: PpdbLookupGroup) {
  const query = usePpdbLookups();
  const rows = query.data?.[group] ?? [];

  return {
    rows,
    options: rows.map<SelectOption>((row) => ({ value: row.id, label: row.name })),
    isLoading: query.isLoading,
  };
}
