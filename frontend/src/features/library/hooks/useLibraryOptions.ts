import { useQuery } from '@tanstack/react-query';
import type { SelectOption } from '@/constants/masterData';
import { librariesApi } from '../api/libraryApi';

const STALE = 1000 * 60 * 10;

/** Opsi perpustakaan untuk pemilih di seluruh halaman modul Library. */
export function useLibraryOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['library', 'options', 'libraries'],
    staleTime: STALE,
    queryFn: async () => {
      const res = await librariesApi.list({ all: true });
      return res.data.map<SelectOption>((l) => ({
        value: l.id,
        label: `${l.code} — ${l.name}`,
      }));
    },
  });

  return { options: data ?? [], isLoading };
}