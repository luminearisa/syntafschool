/** Tipe data modul Transportation Management (fase 2 School Operations). */

export interface ApiEnvelope<T> {
  data: T;
}

export interface TripCounts {
  planned: number;
  boarded: number;
  dropped_off: number;
  no_show: number;
  occupancy_percent: number;
}

export interface TripStopRow {
  id: number;
  sequence: number;
  name: string;
  status: string;
  planned_arrival_at: string | null;
  actual_arrival_at: string | null;
  actual_departure_at: string | null;
  arrival_delay_minutes: number | null;
  planned_passengers: number;
  boarded_count: number;
  dropped_off_count: number;
  no_show_count: number;
}

export interface Trip {
  id: number;
  trip_number: string;
  trip_date: string;
  direction: string;
  trip_type: string;
  status: string;
  is_ad_hoc: boolean;
  route: { id: number; code: string; name: string; origin?: string | null; destination?: string | null } | null;
  vehicle: { id: number; vehicle_code: string; registration_number?: string | null; capacity?: number } | null;
  driver: { id: number; driver_code: string; full_name: string | null; phone?: string | null } | null;
  planned: { departure: string | null; arrival: string | null; start_at?: string | null; end_at?: string | null };
  actual: {
    start_at: string | null; end_at: string | null; start_odometer: number | null; end_odometer: number | null;
    distance_km: number | null; delay_minutes: number; delay_reason: string | null;
  };
  counts: TripCounts;
  stops: TripStopRow[];
  inspection: {
    pre_trip: { id: number; result: string; critical_failure: boolean; blocks_dispatch: boolean; items_failed: number } | null;
    post_trip: { id: number; condition: string; damage_found: boolean; maintenance_required: boolean } | null;
    dispatch_blocked: boolean;
  };
  cancellation: { reason: string | null; at: string | null; notification_status: string } | null;
}

export interface ManifestPassenger {
  id: number;
  student_id: number | null;
  employee_id: number | null;
  passenger_type: string;
  seat_number: number | null;
  pickup_stop_id: number | null;
  dropoff_stop_id: number | null;
  boarding_status: string;
  dropoff_status: string;
  boarded_at: string | null;
  dropped_off_at: string | null;
  special_instruction: string | null;
  special_needs: boolean;
}

export interface TripManifestView {
  trip: { id: number; trip_number: string; trip_date: string; direction: string; status: string; route_id: number; vehicle_id: number | null; driver_profile_id: number | null };
  manifest: {
    id: number; status: string; expected_passengers: number; boarded_passengers: number;
    dropped_off_passengers: number; absent_passengers: number; no_show_passengers: number;
    capacity: number; occupancy_percent: number;
  } | null;
  passengers: ManifestPassenger[];
  stops: TripStopRow[];
}

export interface DispatchBoard {
  date: string;
  generated_at: string;
  summary: {
    trips: number; on_time: number; delayed: number; incidents: number; cancelled: number; completed: number;
    expected_passengers: number; boarded: number; no_show: number; pending: number;
  };
  rows: Array<{
    trip_id: number; trip_number: string; departure: string | null; route: string; route_code: string;
    driver: string; vehicle: string; status: string; status_label: string; delay_minutes: number; passengers: TripCounts;
  }>;
}

export interface Vehicle {
  id: number;
  vehicle_code: string;
  asset_id: number | null;
  assigned_driver?: string | null;
  vehicle_type: string | null;
  registration_number: string | null;
  brand: string | null;
  model: string | null;
  status: string;
  ownership_type: string;
  odometer: number;
  capacity: {
    total_capacity: number; student_capacity: number; adult_capacity: number; standing_capacity: number;
    wheelchair_capacity: number; reserved_capacity: number; usable_capacity: number;
  };
  documents: { total: number; expired: number; expiring_soon: number };
  equipment: { total: number; problem: number; critical_problem: number };
  maintenance: { in_maintenance: boolean; open_work_order_count: number; next_maintenance_at: string | null };
  active_trips?: number;
}

export interface Driver {
  id: number;
  driver_code: string;
  full_name: string | null;
  driver_status: string;
  license_type: string | null;
  license_expiry: string | null;
  license_valid: boolean;
  qualifications: string[];
  trips_today: number;
}

export interface Route {
  id: number;
  code: string;
  name: string;
  route_type: string;
  campus_id: number | null;
  origin: string | null;
  destination: string | null;
  distance_km: number | null;
  estimated_duration_minutes: number | null;
  version_number: number;
  status: string;
  stop_count: number;
}

export interface RouteStop {
  id: number;
  sequence: number;
  name: string;
  stop_type: string;
  address: string | null;
  latitude: number | null;
  longitude: number | null;
  geofence_radius_meters: number;
  planned_arrival: string | null;
  planned_departure: string | null;
  pickup_allowed: boolean;
  dropoff_allowed: boolean;
  capacity: number | null;
}

export interface RouteCapacity {
  route_id: number;
  route_code: string;
  route_name: string;
  date: string;
  vehicle_code: string | null;
  capacity: number;
  assigned: number;
  available_seats: number;
  occupancy_percent: number;
  is_full: boolean;
  waitlist_count: number;
}

export interface TransportationRequestRow {
  id: number;
  request_number: string;
  request_type: string;
  student_id: number | null;
  route_id: number | null;
  status: string;
  priority_level: string;
  created_at: string;
  capacity_check: { capacity?: number; assigned?: number; available_seats?: number; is_full?: boolean } | null;
  workflow_reference: string | null;
}

export interface AssignmentRow {
  id: number;
  assignment_code: string;
  student_id: number;
  route: { id: number; code: string; name: string } | null;
  pickup_stop: { id: number; name: string } | null;
  dropoff_stop: { id: number; name: string } | null;
  status: string;
  transport_mode: string;
  effective_from: string | null;
  effective_until: string | null;
  billable: boolean;
}

export interface WaitlistRow {
  id: number;
  student_id: number;
  route_id: number | null;
  priority_score: number;
  priority_class: string;
  special_need: boolean;
  boarding_status: boolean;
  status: string;
  requested_at: string;
  offer_expires_at: string | null;
}

export interface IncidentRow {
  id: number;
  incident_number: string;
  incident_type: string;
  severity: string;
  status: string;
  trip_id: number | null;
  vehicle_id: number | null;
  driver_profile_id: number | null;
  student_id: number | null;
  occurred_at: string;
  description: string;
  injured_count: number;
  passengers_involved: boolean;
  escalated: boolean;
  parent_notified: boolean;
  resolution: string | null;
  emergency_actions: { steps?: string[]; contacts?: Array<{ key: string; label: string; phone: string | null }> } | null;
  actions?: Array<{ id: number; action_type: string; description: string | null; performed_at: string }>;
}

export interface LostFoundRow {
  id: number;
  item_code: string;
  item_name: string;
  status: string;
  found_date: string | null;
  trip_id: number | null;
  vehicle_id: number | null;
  student_id: number | null;
  owner_notified: boolean;
  returned_at: string | null;
}

export interface DashboardPayload {
  date: string;
  trips: { total: number; completed: number; active: number; delayed: number; cancelled: number; unassigned: number };
  vehicles: { total: number; available: number; on_trip: number; maintenance: number; inspection: number };
  drivers: { total: number; on_duty: number; available: number; unavailable: number };
  passengers: { expected: number; transported: number; boarded: number; not_boarded: number; no_show: number };
  incidents: { open: number; today: number; critical: number };
  documents: { expiring_soon: number; expired: number };
  cost: { today_total: number; cost_per_passenger: number | null; cost_per_trip: number | null; by_category: Record<string, { entries: number; amount: number }> };
  route_capacity: { routes: RouteCapacity[]; total_capacity: number; total_assigned: number; total_available: number; waitlist_total: number };
  generated_at: string;
}

export interface TripMetrics {
  period: { from: string; to: string };
  trips: { total: number; completed: number; cancelled: number; delayed: number; on_time: number };
  passengers: { manifested: number; boarded: number; no_show: number; no_show_rate: number };
  occupancy_percent: number;
  on_time_rate: number;
  average_delay_minutes: number;
  cancellation_rate: number;
  incident_rate: number;
  distance_km: number;
  fuel: { quantity: number; efficiency_km_per_liter: number | null };
  cost: { total: number; cost_per_trip: number | null; cost_per_passenger: number | null; cost_per_km: number | null };
}

export interface RouteAnalyticsRow {
  route_id: number;
  route_code: string;
  route_name: string;
  capacity: number;
  trips: number;
  passengers_boarded: number;
  average_passengers_per_trip: number;
  occupancy_percent: number;
  distance_km: number;
  average_delay_minutes: number;
  fuel_liters: number;
  cost: number;
  incidents: number;
}

export interface ScheduledTrip {
  id: number;
  trip_number: string;
  planned_departure: string | null;
  direction: string;
  status: string;
  route: string | null;
  vehicle: string | null;
  passengers: number;
  delay_minutes: number;
}

export interface DriverAppTrip {
  id: number;
  trip_number: string;
  planned_departure: string | null;
  direction: string;
  status: string;
  route: string | null;
  vehicle: string | null;
  passengers: number;
  delay_minutes: number;
}

export interface PortalChild {
  student_id: number;
  assignment: Array<{
    id: number; assignment_code: string;
    route: { id: number; code: string; name: string } | null;
    pickup_stop: { id: number; name: string; planned_arrival?: string | null } | null;
    dropoff_stop: { id: number; name: string; planned_arrival?: string | null } | null;
    effective_from: string | null; effective_until: string | null;
  }>;
}

export interface StudentTodayStatus {
  date: string;
  trips: Array<{
    trip_id: number; trip_number: string | null; direction: string | null; status: string | null;
    boarded_at: string | null; dropped_off_at: string | null; boarding_status: string; dropoff_status: string;
    pickup_stop_id: number | null; dropoff_stop_id: number | null;
    vehicle: { id: number; vehicle_code: string; registration_number?: string | null } | null;
    driver: { id: number; driver_code: string; full_name: string | null } | null;
  }>;
}
