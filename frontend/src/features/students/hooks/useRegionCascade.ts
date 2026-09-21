import { useQuery } from '@tanstack/react-query';
import { masterDataApi } from '@/features/master-data/api/masterDataApi';
import type { MasterRecord } from '@/types';
import type { SelectOption } from '@/constants/masterData';

const TEN_MINUTES = 1000 * 60 * 10;

function toOptions(rows: MasterRecord[] | undefined): SelectOption[] {
  return (rows ?? []).map((row) => ({
    value: String(row.id),
    label: String(row.name ?? row.id),
  }));
}

interface CascadeInput {
  province_id?: string | null;
  city_id?: string | null;
  district_id?: string | null;
}

/**
 * Dropdown wilayah berjenjang: provinsi → kabupaten/kota → kecamatan → desa.
 *
 * Tiap tingkat hanya mengambil data setelah tingkat di atasnya dipilih,
 * memakai filter yang dideklarasikan di config/master_data.php.
 */
export function useRegionCascade(selected: CascadeInput) {
  const provinces = useQuery({
    queryKey: ['region', 'provinces'],
    staleTime: TEN_MINUTES,
    queryFn: () => masterDataApi.all('provinces'),
  });

  const cities = useQuery({
    queryKey: ['region', 'cities', selected.province_id],
    enabled: !!selected.province_id,
    staleTime: TEN_MINUTES,
    queryFn: () => masterDataApi.all('cities', { province_id: selected.province_id! }),
  });

  const districts = useQuery({
    queryKey: ['region', 'districts', selected.city_id],
    enabled: !!selected.city_id,
    staleTime: TEN_MINUTES,
    queryFn: () => masterDataApi.all('districts', { city_id: selected.city_id! }),
  });

  const villages = useQuery({
    queryKey: ['region', 'villages', selected.district_id],
    enabled: !!selected.district_id,
    staleTime: TEN_MINUTES,
    queryFn: () => masterDataApi.all('villages', { district_id: selected.district_id! }),
  });

  return {
    provinceOptions: toOptions(provinces.data),
    cityOptions: toOptions(cities.data),
    districtOptions: toOptions(districts.data),
    villageOptions: toOptions(villages.data),
    isLoading: provinces.isLoading,
  };
}
