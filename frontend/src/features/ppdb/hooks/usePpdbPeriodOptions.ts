import { useQuery } from '@tanstack/react-query';
import { ppdbPeriodsApi } from '../api/ppdbApi';
import type { SelectOption } from '@/constants/masterData';

/**
 * Opsi periode untuk seluruh halaman konfigurasi.
 *
 * `listAll` memakai `per_page: 100`; kalau sebuah sekolah punya lebih dari 100
 * periode, pemilih periode tetap berfungsi karena periode baru selalu berada di
 * halaman pertama (backend mengurutkan tahun ajaran lebih dulu).
 */
export function usePpdbPeriodOptions() {
  const query = useQuery({
    queryKey: ['ppdb', 'periods', 'options'],
    staleTime: 1000 * 60 * 5,
    queryFn: () => ppdbPeriodsApi.listAll(),
  });

  const periods = query.data ?? [];
  const options = periods.map<SelectOption>((period) => ({
    value: period.id,
    label: period.academic_year?.name ? `${period.name} — ${period.academic_year.name}` : period.name,
  }));

  return {
    periods,
    options,
    firstPeriodId: (periods[0]?.id ?? '') as number | '',
    isInitialLoading: query.isPending && query.isFetching,
    error: query.error,
  };
}
