export interface SchoolProfile {
  id?: number;
  npsn: string | null;
  name: string;
  jenjang: 'SD' | 'SMP' | 'SMA' | 'SMK' | string;
  status: 'Negeri' | 'Swasta' | string;
  accreditation: string | null;
  foundation_id?: number | null;
  address: string | null;
  phone: string | null;
  email: string | null;
  website: string | null;
  headmaster_name: string | null;
  is_active?: boolean;
}

export interface GeneralSettingsResponse {
  school: SchoolProfile | null;
}

export interface GeneralSettingsPayload {
  school: Omit<SchoolProfile, 'id' | 'is_active'> & { id?: number };
}
