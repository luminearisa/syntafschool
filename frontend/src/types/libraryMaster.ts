/**
 * Tipe master data Library (Step 2).
 *
 * Bentuk baris master seragam, sehingga satu tabel & satu form dapat melayani
 * seluruh master. Definisi field datang dari backend (`GET library/master-meta`)
 * supaya menambah master baru tidak perlu mengubah frontend.
 */

export type LibraryMasterGroup = 'core' | 'supporting';

export type LibraryMasterFieldType =
  | 'text'
  | 'textarea'
  | 'number'
  | 'boolean'
  | 'status'
  | 'relation';

export interface LibraryMasterField {
  name: string;
  label: string;
  type: LibraryMasterFieldType;
  /** Untuk `type: 'relation'` — path master tujuan (mis. `countries`). */
  relation?: string;
  required?: boolean;
  help?: string;
}

export interface LibraryMasterMeta {
  key: string;
  label: string;
  group: LibraryMasterGroup;
  scoped: boolean;
  hierarchical: boolean;
  /** Kunci permission tanpa awalan, mis. `collection_type`. */
  permission: string;
  fields: LibraryMasterField[];
}

/** Satu baris master — kolomnya dinamis, ditambah field turunan. */
export interface LibraryMasterRecord {
  id: number;
  code: string;
  name: string;
  description?: string | null;
  status: 'active' | 'inactive';
  is_active?: boolean;
  sort_order?: number | null;
  library_id?: number | null;
  parent_id?: number | null;
  [key: string]: unknown;

  parent?: { id: number; code: string; name: string } | null;
  library?: { id: number; code: string; name: string } | null;
  relations?: Record<string, { id: number; code: string | null; name: string } | null>;
  children_count?: number;
  usage_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
  deleted_at?: string | null;
}

export interface LibraryMasterMetaResponse {
  masters: LibraryMasterMeta[];
}

export interface LibraryMasterPayload {
  code: string;
  name: string;
  description?: string;
  status?: 'active' | 'inactive';
  sort_order?: number | '';
  library_id?: number | '';
  parent_id?: number | '';
  [key: string]: unknown;
}

export interface LibraryMasterBulkStatusPayload {
  ids: number[];
  status: 'active' | 'inactive';
  reason?: string;
}