import { api } from '@/lib/axios';
import type {
  ApiEnvelope, AssignmentRow, DashboardPayload, DispatchBoard, Driver, DriverAppTrip, IncidentRow, LostFoundRow,
  PortalChild, Route, RouteAnalyticsRow, RouteCapacity, RouteStop, StudentTodayStatus, Trip,
  TripManifestView, TripMetrics, TransportationRequestRow, Vehicle, WaitlistRow,
} from '../types';

const unwrap = <T>(promise: Promise<{ data: ApiEnvelope<T> }>) => promise.then((response) => response.data.data);

export const transportationApi = {
  /* ── Dashboard & operasi ─────────────────────────────────────────────── */
  dashboard: (params: { date?: string; campus_id?: number } = {}) =>
    unwrap<DashboardPayload>(api.get('/transportation/dashboard', { params })),

  dispatchBoard: (params: { date?: string; route_id?: number; status?: string } = {}) =>
    unwrap<DispatchBoard>(api.get('/transportation/dispatch-board', { params })),

  alerts: () => unwrap<Record<string, unknown>>(api.get('/transportation/alerts')),

  /* ── Armada ──────────────────────────────────────────────────────────── */
  vehicles: (params: { status?: string; vehicle_type?: string } = {}) =>
    unwrap<Vehicle[]>(api.get('/transportation/vehicles', { params })),

  vehicle: (id: number) => unwrap<Vehicle & Record<string, unknown>>(api.get(`/transportation/vehicles/${id}`)),

  createVehicle: (payload: Record<string, unknown>) =>
    unwrap<Vehicle>(api.post('/transportation/vehicles', payload)),

  setVehicleStatus: (id: number, payload: { status: string; reason?: string }) =>
    unwrap<Vehicle>(api.post(`/transportation/vehicles/${id}/status`, payload)),

  vehicleFuelMetrics: (id: number) =>
    unwrap<{ entries: number; total_quantity: number; total_cost: number; distance_km: number | null; efficiency_km_per_liter: number | null; cost_per_km: number | null }>(
      api.get(`/transportation/vehicles/${id}/fuel-metrics`),
    ),

  addVehicleDocument: (id: number, payload: Record<string, unknown>) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/vehicles/${id}/documents`, payload)),

  expiringDocuments: (params: { days?: number } = {}) =>
    unwrap<Array<{ id: number; vehicle_id: number; document_type: string; expiry_date: string; days_left: number; expired: boolean; blocks_dispatch: boolean }>>(
      api.get('/transportation/vehicles/expiring-documents', { params }),
    ),

  logFuel: (payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post('/transportation/fuel-logs', payload)),

  /* ── Pengemudi ───────────────────────────────────────────────────────── */
  drivers: (params: { date?: string; status?: string } = {}) => unwrap<Driver[]>(api.get('/transportation/drivers', { params })),

  driver: (id: number) => unwrap<Record<string, unknown>>(api.get(`/transportation/drivers/${id}`)),

  setDriverStatus: (id: number, payload: { driver_status: string; reason?: string }) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/drivers/${id}/status`, payload)),

  addLicense: (id: number, payload: Record<string, unknown>) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/drivers/${id}/licenses`, payload)),

  syncQualifications: (id: number, items: Array<Record<string, unknown>>) =>
    unwrap<Record<string, unknown>[]>(api.post(`/transportation/drivers/${id}/qualifications`, { items })),

  addAvailability: (id: number, payload: Record<string, unknown>) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/drivers/${id}/availability`, payload)),

  assignDriverToTrip: (tripId: number, payload: { driver_profile_id: number; role?: string; allow_override?: boolean; reason?: string }) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${tripId}/assign-driver`, payload)),

  /* ── Rute ────────────────────────────────────────────────────────────── */
  routes: (params: { campus_id?: number; route_type?: string } = {}) => unwrap<Route[]>(api.get('/transportation/routes', { params })),

  route: (id: number) => unwrap<Record<string, unknown>>(api.get(`/transportation/routes/${id}`)),

  createRoute: (payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post('/transportation/routes', payload)),

  updateRoute: (id: number, payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.put(`/transportation/routes/${id}`, payload)),

  routeStops: (id: number) => unwrap<RouteStop[]>(api.get(`/transportation/routes/${id}/stops`)),

  addStop: (routeId: number, payload: Record<string, unknown>) =>
    unwrap<RouteStop>(api.post(`/transportation/routes/${routeId}/stops`, payload)),

  routeCapacity: (id: number) => unwrap<RouteCapacity>(api.get(`/transportation/routes/${id}/capacity`)),

  routeCapacityOverview: () => unwrap<{ routes: RouteCapacity[]; total_capacity: number; total_assigned: number; total_available: number; waitlist_total: number }>(
    api.get('/transportation/route-capacity'),
  ),

  /* ── Jadwal & trip ───────────────────────────────────────────────────── */
  schedules: (params: { route_id?: number } = {}) => unwrap<Record<string, unknown>[]>(api.get('/transportation/schedules', { params })),

  createSchedule: (payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post('/transportation/schedules', payload)),

  generateTrips: (payload: { date: string; route_id?: number }) => unwrap<Record<string, unknown>>(api.post('/transportation/schedules/generate-trips', payload)),

  tripCalendar: (params: { from: string; to: string }) => unwrap<Record<string, unknown>>(api.get('/transportation/trips/calendar', { params })),

  trips: (params: { date?: string; status?: string; route_id?: number } = {}) => unwrap<Trip[]>(api.get('/transportation/trips', { params })),

  tripHistory: (params: { from?: string; to?: string; status?: string } = {}) =>
    unwrap<Array<Record<string, unknown>>>(api.get('/transportation/trips/history', { params })),

  trip: (id: number) => unwrap<Trip>(api.get(`/transportation/trips/${id}`)),

  createAdHocTrip: (payload: Record<string, unknown>) => unwrap<Trip>(api.post('/transportation/trips', payload)),

  manifest: (id: number) => unwrap<TripManifestView>(api.get(`/transportation/trips/${id}/manifest`)),

  dispatchValidation: (id: number) => unwrap<{ dispatchable: boolean; blockers: Array<{ code: string; message: string; severity: string }>; warnings: Array<{ code: string; message: string }> }>(
    api.get(`/transportation/trips/${id}/dispatch-validation`),
  ),

  assignVehicle: (id: number, payload: { vehicle_id: number; allow_override?: boolean; reason?: string }) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/assign-vehicle`, payload)),

  replaceVehicle: (id: number, payload: { vehicle_id: number; reason: string }) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/replace-vehicle`, payload)),

  dispatchTrip: (id: number, payload: { allow_override?: boolean; reason?: string } = {}) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/dispatch`, payload)),

  startTrip: (id: number, payload: { start_odometer?: number } = {}) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/start`, payload)),

  arriveStop: (id: number, payload: { stop_id: number }) => unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/arrive-stop`, payload)),

  departStop: (id: number, payload: { stop_id: number }) => unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/depart-stop`, payload)),

  board: (id: number, payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/board`, payload)),

  dropoff: (id: number, payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/dropoff`, payload)),

  noShow: (id: number, payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/no-show`, payload)),

  reportDelay: (id: number, payload: { minutes: number; reason: string; notes?: string }) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/delay`, payload)),

  completeTrip: (id: number, payload: { end_odometer?: number }) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/complete`, payload)),

  cancelTrip: (id: number, payload: { reason: string }) => unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${id}/cancel`, payload)),

  /* ── Siswa: permintaan, penugasan, daftar tunggu ─────────────────────── */
  requests: (params: { status?: string; student_id?: number } = {}) =>
    unwrap<TransportationRequestRow[]>(api.get('/transportation/requests', { params })),

  submitRequest: (payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post('/transportation/requests', payload)),

  approveRequest: (id: number, payload: { allow_override?: boolean } = {}) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/requests/${id}/approve`, payload)),

  rejectRequest: (id: number, payload: { reason: string }) => unwrap<Record<string, unknown>>(api.post(`/transportation/requests/${id}/reject`, payload)),

  assignments: (params: { student_id?: number; route_id?: number; status?: string } = {}) =>
    unwrap<AssignmentRow[]>(api.get('/transportation/assignments', { params })),

  createAssignment: (payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post('/transportation/assignments', payload)),

  suspendAssignment: (id: number, payload: { reason: string }) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/assignments/${id}/suspend`, payload)),

  resumeAssignment: (id: number) => unwrap<Record<string, unknown>>(api.post(`/transportation/assignments/${id}/resume`, {})),

  waitlist: (params: { route_id?: number; status?: string } = {}) => unwrap<WaitlistRow[]>(api.get('/transportation/waitlist', { params })),

  evaluateWaitlist: (payload: { route_id?: number; seats?: number } = {}) =>
    unwrap<Record<string, unknown>>(api.post('/transportation/waitlist/evaluate', payload)),

  acceptOffer: (id: number) => unwrap<Record<string, unknown>>(api.post(`/transportation/waitlist/${id}/accept`, {})),

  /* ── Insiden & inspeksi ──────────────────────────────────────────────── */
  incidents: (params: { status?: string; severity?: string } = {}) => unwrap<IncidentRow[]>(api.get('/transportation/incidents', { params })),

  reportIncident: (payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post('/transportation/incidents', payload)),

  acknowledgeIncident: (id: number) => unwrap<Record<string, unknown>>(api.post(`/transportation/incidents/${id}/acknowledge`, {})),

  resolveIncident: (id: number, payload: { resolution: string }) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/incidents/${id}/resolve`, payload)),

  closeIncident: (id: number) => unwrap<Record<string, unknown>>(api.post(`/transportation/incidents/${id}/close`, {})),

  breakdown: (tripId: number, payload: Record<string, unknown>) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/trips/${tripId}/breakdown`, payload)),

  preTrip: (payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post('/transportation/pre-trip-inspections', payload)),

  postTrip: (payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post('/transportation/post-trip-inspections', payload)),

  inspectionTemplate: () => unwrap<{ pre_trip: Array<{ code: string; label: string; category: string; critical: boolean; mandatory: boolean }> }>(
    api.get('/transportation/inspection-template'),
  ),

  /* ── Barang hilang ───────────────────────────────────────────────────── */
  lostFound: (params: { status?: string } = {}) => unwrap<LostFoundRow[]>(api.get('/transportation/lost-found', { params })),

  recordLostFound: (payload: Record<string, unknown>) => unwrap<LostFoundRow>(api.post('/transportation/lost-found', payload)),

  returnLostFound: (id: number, payload: { returned_to_name: string }) =>
    unwrap<Record<string, unknown>>(api.post(`/transportation/lost-found/${id}/return`, payload)),

  /* ── Analitik, biaya, penutupan ──────────────────────────────────────── */
  metrics: (params: { from?: string; to?: string } = {}) => unwrap<TripMetrics>(api.get('/transportation/analytics/metrics', { params })),

  routeAnalytics: (params: { from?: string; to?: string } = {}) =>
    unwrap<{ period: { from: string; to: string }; routes: RouteAnalyticsRow[] }>(api.get('/transportation/analytics/routes', { params })),

  vehicleAnalytics: (params: { from?: string; to?: string } = {}) =>
    unwrap<Array<{ vehicle_id: number; vehicle_code: string; status: string; trips: number; distance_km: number; maintenance_cost: number; incidents: number; cost: number; fuel: { efficiency_km_per_liter: number | null; total_cost: number } }>>(
      api.get('/transportation/analytics/vehicles', { params }),
    ),

  driverAnalytics: (params: { from?: string; to?: string } = {}) =>
    unwrap<{ disclaimer: string; drivers: Array<{ driver_profile_id: number; driver_code: string; trips: number; distance_km: number; delays: number; incidents: number; checklist_completion: number; training_status: { valid: number; required: number; complete: boolean } }> }>(
      api.get('/transportation/analytics/drivers', { params }),
    ),

  dailyReport: (params: { date: string }) => unwrap<Record<string, unknown>>(api.get('/transportation/reports/daily', { params })),

  monthlyReport: (params: { month?: string } = {}) => unwrap<Record<string, unknown>>(api.get('/transportation/reports/monthly', { params })),

  managementReport: (params: { from?: string; to?: string } = {}) => unwrap<Record<string, unknown>>(api.get('/transportation/reports/management', { params })),

  costs: (params: { from?: string; to?: string } = {}) =>
    unwrap<{ period: { from: string; to: string }; total_cost: number; by_category: Record<string, { entries: number; amount: number }>; cost_per_trip: number | null; cost_per_passenger: number | null; cost_per_km: number | null; unposted: number }>(
      api.get('/transportation/costs', { params }),
    ),

  storeCost: (payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post('/transportation/costs', payload)),

  closings: (params: { from?: string; to?: string } = {}) => unwrap<Array<Record<string, unknown>>>(api.get('/transportation/daily-closings', { params })),

  closeDay: (payload: { date: string; force?: boolean }) => unwrap<Record<string, unknown>>(api.post('/transportation/daily-closings', payload)),

  preflight: (params: { date: string }) => unwrap<Record<string, unknown>>(api.get('/transportation/daily-closings/preflight', { params })),

  planCapacity: (payload: { passengers: number; vehicle_capacity?: number }) =>
    unwrap<Record<string, unknown>>(api.get('/transportation/analytics/plan-capacity', { params: payload })),

  /* ── Aplikasi pengemudi & portal wali ────────────────────────────────── */
  driverToday: (params: { date?: string } = {}) =>
    unwrap<{ date: string; driver: { id: number; driver_code: string; full_name: string | null; driver_status: string } | null; trips: DriverAppTrip[]; current_trip: DriverAppTrip | null }>(
      api.get('/transportation/driver/today', { params }),
    ),

  driverCurrentTrip: () => unwrap<Trip & { manifest: TripManifestView; stops: TripStopLike[] } | null>(api.get('/transportation/driver/current-trip')),

  driverStops: (tripId: number) => unwrap<{ trip_id: number; trip_number: string; status: string; stops: TripStopLike[] }>(
    api.get(`/transportation/driver/trips/${tripId}/stops`),
  ),

  offlineManifest: (tripId: number) => unwrap<Record<string, unknown>>(api.get(`/transportation/driver/trips/${tripId}/offline-manifest`)),

  portalChildren: () => unwrap<PortalChild[]>(api.get('/transportation/portal/children')),

  portalChild: (studentId: number) => unwrap<{ student_id: number; assignments: PortalChild['assignment']; profile: Record<string, unknown> | null; today: StudentTodayStatus }>(
    api.get(`/transportation/portal/children/${studentId}`),
  ),

  portalToday: (studentId: number) => unwrap<StudentTodayStatus>(api.get(`/transportation/portal/children/${studentId}/today`)),

  portalRequest: (payload: Record<string, unknown>) => unwrap<Record<string, unknown>>(api.post('/transportation/portal/requests', payload)),
};

export interface TripStopLike {
  id: number;
  sequence: number;
  name: string;
  status: string;
  planned_arrival_at: string | null;
  actual_arrival_at: string | null;
  planned_passengers: number;
  boarded_count: number;
  dropped_off_count: number;
  no_show_count: number;
}
