/**
 * Tipe katalog, koleksi, keanggotaan, dan OPAC (Fase 3).
 *
 * Konsep penting: **bibliographic record ≠ eksemplar**. Satu record mewakili
 * satu judul; eksemplar fisik/digitalnya ada di `LibraryItem`.
 */

export type BibliographyStatus = 'draft' | 'active' | 'inactive' | 'archived';

export type IdentifierType = 'isbn10' | 'isbn13' | 'issn' | 'doi' | 'oclc' | 'local' | 'other';

export type BibliographyFileType = 'cover' | 'thumbnail' | 'back' | 'additional';

export interface BibliographyCreatorInput {
  creator_id: number | '';
  creator_role_id?: number | '';
  sequence?: number | '';
  is_primary?: boolean;
  display_name?: string;
}

export interface BibliographyCreator {
  id: number;
  creator_id: number;
  name: string | null;
  creator_role_id: number | null;
  role: string | null;
  sequence: number;
  is_primary: boolean;
}

export interface BibliographyIdentifier {
  id?: number;
  identifier_type: IdentifierType;
  value: string;
  normalized_value?: string;
  is_primary?: boolean;
}

export interface BibliographyFile {
  id?: number;
  file_type: BibliographyFileType;
  file_path: string;
  caption?: string | null;
  sort_order?: number | '';
}

export interface SimpleRef {
  id: number;
  name: string;
}

export interface BibliographyClassificationRef {
  id: number;
  code: string;
  name: string;
}

export interface LibraryBibliography {
  id: number;
  library_id: number | null;
  control_number: string;
  slug: string;

  title: string;
  subtitle: string | null;
  uniform_title: string | null;

  edition_statement: string | null;
  edition_number: number | null;
  edition_year: number | null;

  publisher_id: number | null;
  publication_place: string | null;
  publication_year: number | null;
  publication_statement: string | null;

  language_id: number | null;
  collection_type_id: number | null;
  category_id: number | null;
  material_type_id: number | null;
  series_id: number | null;
  classification_id: number | null;
  call_number: string | null;

  description: string | null;
  abstract: string | null;
  table_of_contents: string | null;
  physical_description: string | null;
  notes: string | null;

  cover_image: string | null;
  thumbnail: string | null;
  digital_available: boolean;
  physical_available: boolean;

  status: BibliographyStatus;
  is_public: boolean;
  items_count: number;
  available_items_count: number;

  publisher?: SimpleRef | null;
  language?: SimpleRef | null;
  collection_type?: SimpleRef | null;
  category?: SimpleRef | null;
  material_type?: SimpleRef | null;
  series?: SimpleRef | null;
  classification?: BibliographyClassificationRef | null;

  creators?: BibliographyCreator[];
  subjects?: SimpleRef[];
  genres?: SimpleRef[];
  tags?: SimpleRef[];
  identifiers?: BibliographyIdentifier[];
  files?: BibliographyFile[];
  previous_slugs?: string[];

  created_at?: string | null;
  updated_at?: string | null;
}

export interface BibliographyPayload {
  library_id?: number | '';
  title: string;
  subtitle?: string;
  uniform_title?: string;
  edition_statement?: string;
  edition_number?: number | '';
  edition_year?: number | '';
  publisher_id?: number | '';
  publication_place?: string;
  publication_year?: number | '';
  publication_statement?: string;
  language_id?: number | '';
  collection_type_id?: number | '';
  category_id?: number | '';
  material_type_id?: number | '';
  series_id?: number | '';
  classification_id?: number | '';
  call_number?: string;
  description?: string;
  abstract?: string;
  table_of_contents?: string;
  physical_description?: string;
  notes?: string;
  cover_image?: string;
  thumbnail?: string;
  digital_available?: boolean;
  physical_available?: boolean;
  status?: BibliographyStatus;
  regenerate_slug?: boolean;
  creators?: BibliographyCreatorInput[];
  subjects?: number[];
  genres?: number[];
  tags?: number[];
  identifiers?: BibliographyIdentifier[];
  files?: BibliographyFile[];
}

export interface DuplicateCandidate {
  id: number;
  control_number: string;
  title: string;
  publisher: string | null;
  publication_year: number | null;
}

export interface DuplicateCheckResult {
  has_candidates: boolean;
  message: string | null;
  candidates: DuplicateCandidate[];
}

/* ============================ EKSEMPLAR ============================ */

export type ItemStatus =
  | 'available'
  | 'on_loan'
  | 'reserved'
  | 'processing'
  | 'reference_only'
  | 'lost'
  | 'damaged'
  | 'withdrawn'
  | 'inactive';

export type RfidStatus = 'unassigned' | 'assigned' | 'active' | 'lost' | 'disabled';

export interface LibraryItem {
  id: number;
  library_id: number | null;
  bibliography_id: number;

  item_code: string;
  accession_number: string | null;
  barcode: string | null;
  rfid_tag: string | null;
  rfid_status: RfidStatus;

  location_id: number | null;
  condition_id: number | null;
  status_id: number | null;
  status: ItemStatus;
  is_available: boolean;
  is_circulation_controlled: boolean;

  acquisition_date: string | null;
  acquisition_source_id: number | null;
  acquisition_price: number | null;
  funding_source: string | null;
  notes: string | null;

  bibliography?: {
    id: number;
    title: string;
    slug: string;
    control_number: string;
  } | null;
  library?: { id: number; code: string; name: string } | null;
  location?: { id: number; code: string; name: string; type: string } | null;
  condition?: SimpleRef | null;
  collection_status?: SimpleRef | null;
  acquisition_source?: SimpleRef | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface LibraryItemPayload {
  item_code?: string;
  accession_number?: string;
  barcode?: string;
  rfid_tag?: string;
  rfid_status?: RfidStatus | '';
  location_id?: number | '';
  condition_id?: number | '';
  status_id?: number | '';
  status?: ItemStatus | '';
  acquisition_date?: string;
  acquisition_source_id?: number | '';
  acquisition_price?: number | '';
  funding_source?: string;
  notes?: string;
}

export interface LibraryItemBulkPayload {
  quantity: number | '';
  location_id?: number | '';
  condition_id?: number | '';
  status_id?: number | '';
  status?: ItemStatus | '';
  acquisition_date?: string;
  acquisition_source_id?: number | '';
  acquisition_price?: number | '';
  funding_source?: string;
  notes?: string;
  barcodes?: string[];
}

/* ============================ KEANGGOTAAN ============================ */

export type MemberStatus = 'pending' | 'active' | 'expired' | 'suspended' | 'blocked' | 'inactive';
export type MemberLinkedType = 'student' | 'employee' | 'guardian' | 'user' | 'public';

export interface LibraryMember {
  id: number;
  library_id: number | null;
  member_number: string;
  display_name: string | null;
  linked_type: MemberLinkedType;

  name: string | null;
  gender_id: number | null;
  birth_date: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  identity_number: string | null;
  photo_path: string | null;

  membership_type_id: number | null;
  registered_at: string | null;
  valid_from: string | null;
  valid_until: string | null;
  status: MemberStatus;
  is_expired: boolean;
  is_within_validity: boolean;
  notes: string | null;

  library?: { id: number; code: string; name: string } | null;
  membership_type?: {
    id: number;
    code: string;
    name: string;
    max_loan: number;
    loan_duration_days: number;
  } | null;

  student?: { id: number; nis: string; full_name: string } | null;
  employee?: { id: number; nip: string | null; full_name: string } | null;
  guardian?: { id: number; full_name: string } | null;
  user?: { id: number; name: string } | null;

  cards?: LibraryMemberCard[];
  active_card?: LibraryMemberCard | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface LibraryMemberPayload {
  library_id?: number | '';
  membership_type_id?: number | '';
  user_id?: number | '';
  student_id?: number | '';
  employee_id?: number | '';
  guardian_id?: number | '';
  name?: string;
  gender_id?: number | '';
  birth_date?: string;
  phone?: string;
  email?: string;
  address?: string;
  identity_number?: string;
  photo_path?: string;
  registered_at?: string;
  valid_from?: string;
  valid_until?: string;
  status?: MemberStatus | '';
  notes?: string;
}

export interface LibraryMemberSummary {
  total: number;
  active: number;
  expired: number;
  pending: number;
  suspended: number;
  due_expired: number;
}

export interface LibraryMemberCard {
  id: number;
  member_id: number;
  card_number: string;
  barcode: string | null;
  qr_token: string;
  issued_at: string | null;
  valid_until: string | null;
  status: 'active' | 'revoked' | 'expired';
  is_valid: boolean;
  printed_count: number;
  last_printed_at: string | null;
  member?: {
    id: number;
    member_number: string;
    display_name: string | null;
    photo_path: string | null;
    status: MemberStatus;
    valid_until: string | null;
    membership_type: string | null;
  } | null;
  library?: { id: number; code: string; name: string; logo_path: string | null } | null;
  created_at?: string | null;
}

/* ============================ OPAC ============================ */

export interface OpacCreator {
  name: string | null;
  role: string | null;
  is_primary: boolean;
}

export interface OpacAvailability {
  total_copies: number;
  available: number;
  unavailable: number;
  on_loan: number;
  reference_only: number;
  is_available: boolean;
  locations: { name: string; type: string; copies: number }[];
}

export interface OpacRecord {
  slug: string;
  title: string;
  subtitle: string | null;
  uniform_title: string | null;
  edition: string | number | null;
  publisher: string | null;
  publication_place: string | null;
  publication_year: number | null;
  language: string | null;
  collection_type: string | null;
  category: string | null;
  material_type: string | null;
  series: string | null;
  classification: string | null;
  call_number: string | null;
  creators: OpacCreator[];
  subjects: string[];
  genres: string[];
  tags: string[];
  identifiers: { type: string; value: string }[];
  abstract: string | null;
  description: string | null;
  table_of_contents: string | null;
  cover_image: string | null;
  thumbnail: string | null;
  digital_available: boolean;
  physical_available: boolean;
  availability: OpacAvailability | null;
  locations: { name: string; type: string; copies: number }[];
}

/** Hasil OPAC menampilkan jumlah ketersediaan bila backend menyertakannya. */
export interface OpacListRow extends OpacRecord {
  items_count?: number;
  available_items_count?: number;
}