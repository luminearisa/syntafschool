import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, get, post } from './client';
import { useAuth } from '@/store/auth';

const useRole = () => useAuth((s) => s.active ?? 'none');

export interface Facility {
  id: number;
  facility_code: string;
  name: string;
  status?: string;
  capacity_total?: number | null;
  location?: { id: number; name: string } | null;
  type?: { id: number; name: string } | null;
}

export interface Booking {
  id: number;
  booking_number: string;
  title: string;
  status: string;
  start_at: string;
  end_at: string;
  requires_approval?: boolean;
  facility?: { id: number; facility_code: string; name: string } | null;
}

export interface Availability {
  facility_id: number;
  date: string;
  bookable: boolean;
  status: string;
  bookings: { id: number; booking_number: string; title: string; start_at: string; end_at: string; status: string }[];
}

export const useFacilities = () => {
  const role = useRole();
  return useQuery({
    queryKey: [role, 'facilities'],
    queryFn: async () => (await api.get<{ data: Facility[] }>('/facilities', { params: { per_page: 100 } })).data.data,
    enabled: role === 'employee',
    staleTime: 10 * 60_000,
  });
};

export const useAvailability = (facilityId: number | null, date: string) =>
  useQuery({ queryKey: ['facility-availability', facilityId, date], queryFn: () => get<Availability>(`/facilities/${facilityId}/availability`, { date }), enabled: facilityId !== null });

export const useMyBookings = () => {
  const role = useRole();
  return useQuery({
    queryKey: [role, 'my-bookings'],
    queryFn: async () => (await api.get<{ data: Booking[] }>('/facility-bookings', { params: { per_page: 50 } })).data.data,
    enabled: role === 'employee',
  });
};

export function useBook() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { facilityId: number; title: string; start_at: string; end_at: string; attendee_count?: number; purpose?: string }) =>
      post<Booking>(`/facilities/${v.facilityId}/book`, { title: v.title, start_at: v.start_at, end_at: v.end_at, attendee_count: v.attendee_count, purpose: v.purpose, source: 'STAFF' }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['employee', 'my-bookings'] }); qc.invalidateQueries({ queryKey: ['facility-availability'] }); },
  });
}

export function useCancelBooking() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { id: number; reason: string }) => post(`/facility-bookings/${v.id}/cancel`, { reason: v.reason }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['employee', 'my-bookings'] }); qc.invalidateQueries({ queryKey: ['facility-availability'] }); },
  });
}

export const lookupAsset = (code: string) =>
  get<{ asset_code: string; name: string; status: string; condition: string; location_name?: string | null }>(`/assets/lookup/${encodeURIComponent(code.trim())}`);

export const reportDamage = (b: { code: string; severity: string; description: string; damage_type?: string }) =>
  post<{ report_number: string; asset: { asset_code: string; name: string } }>('/mobile/employee/damage-report', b);
