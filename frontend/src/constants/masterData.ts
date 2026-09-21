/**
 * Registri master data (mirror dari backend/config/master_data.php).
 * Satu definisi dipakai untuk semua halaman CRUD generik.
 */

export type FieldType =
  | 'text'
  | 'number'
  | 'textarea'
  | 'select'
  | 'date'
  | 'checkbox';

export interface SelectOption {
  value: string | number;
  label: string;
}

/** Sumber opsi untuk field select: statis atau dari resource lain */
export interface OptionSource {
  resource?: string; // ambil dari /master/{resource}?all=1
  options?: SelectOption[]; // opsi statis
  valueKey?: string;
  labelKey?: string;
}

export interface FieldDef {
  name: string;
  label: string;
  type: FieldType;
  required?: boolean;
  options?: SelectOption[];
  optionSource?: OptionSource;
  placeholder?: string;
  help?: string;
  /** tampilkan di tabel list */
  inTable?: boolean;
  /** grup kolom tabel (device badge) */
  align?: 'left' | 'center' | 'right';
}

export type MasterGroup =
  | 'institusi'
  | 'wilayah'
  | 'akademik'
  | 'kesiswaan'
  | 'keuangan'
  | 'perpustakaan'
  | 'inventaris'
  | 'absensi'
  | 'referensi'
  | 'sistem';

export interface MasterResourceDef {
  key: string; // segmen URL: /master/{key}
  label: string; // nama tunggal
  labelPlural: string;
  group: MasterGroup;
  icon?: string;
  readOnly?: boolean;
  softDelete?: boolean;
  searchable?: boolean;
  columns: FieldDef[];
  fields: FieldDef[];
}

export const JENJANG_OPTIONS: SelectOption[] = [
  { value: 'SD', label: 'SD' },
  { value: 'SMP', label: 'SMP' },
  { value: 'SMA', label: 'SMA' },
  { value: 'SMK', label: 'SMK' },
];

export const MASTER_GROUPS: { key: MasterGroup; label: string }[] = [
  { key: 'institusi', label: 'Institusi' },
  { key: 'wilayah', label: 'Wilayah' },
  { key: 'akademik', label: 'Akademik' },
  { key: 'kesiswaan', label: 'Kesiswaan' },
  { key: 'keuangan', label: 'Keuangan' },
  { key: 'perpustakaan', label: 'Perpustakaan' },
  { key: 'inventaris', label: 'Inventaris' },
  { key: 'absensi', label: 'Absensi' },
  { key: 'referensi', label: 'Referensi Umum' },
  { key: 'sistem', label: 'Sistem' },
];

/** Helper singkat membuat field */
const f = (
  name: string,
  label: string,
  type: FieldType = 'text',
  extra: Partial<FieldDef> = {},
): FieldDef => ({ name, label, type, ...extra });

/** Kolom umum */
const ID_COLUMN: FieldDef = { name: 'id', label: 'ID', type: 'text', inTable: true, align: 'center' };
const CODE_COLUMN = (label = 'Kode'): FieldDef => ({ name: 'code', label, type: 'text', inTable: true });
const NAME_COLUMN = (label = 'Nama'): FieldDef => ({ name: 'name', label, type: 'text', inTable: true });
const ACTIVE_COLUMN: FieldDef = { name: 'is_active', label: 'Aktif', type: 'checkbox', inTable: true, align: 'center' };
const ACTIVE_FIELD: FieldDef = { name: 'is_active', label: 'Aktif', type: 'checkbox' };

export const MASTER_RESOURCES: MasterResourceDef[] = [
  // ================= INSTITUSI =================
  {
    key: 'schools',
    label: 'Sekolah',
    labelPlural: 'Sekolah',
    group: 'institusi',
    searchable: true,
    softDelete: true,
    columns: [
      ID_COLUMN,
      NAME_COLUMN('Nama Sekolah'),
      f('npsn', 'NPSN', 'text', { inTable: true }),
      f('jenjang', 'Jenjang', 'select', { inTable: true, align: 'center', options: JENJANG_OPTIONS }),
      f('status', 'Status', 'text', { inTable: true }),
      ACTIVE_COLUMN,
    ],
    fields: [
      f('name', 'Nama Sekolah', 'text', { required: true }),
      f('npsn', 'NPSN'),
      f('jenjang', 'Jenjang', 'select', { required: true, options: JENJANG_OPTIONS }),
      f('status', 'Status', 'select', {
        required: true,
        options: [
          { value: 'Negeri', label: 'Negeri' },
          { value: 'Swasta', label: 'Swasta' },
        ],
      }),
      f('accreditation', 'Akreditasi'),
      f('foundation_id', 'Yayasan', 'select', { optionSource: { resource: 'foundations' } }),
      f('address', 'Alamat', 'textarea'),
      f('province_id', 'Provinsi', 'select', { optionSource: { resource: 'provinces' } }),
      f('city_id', 'Kab./Kota', 'select', { optionSource: { resource: 'cities' } }),
      f('district_id', 'Kecamatan', 'select', { optionSource: { resource: 'districts' } }),
      f('village_id', 'Kelurahan', 'select', { optionSource: { resource: 'villages' } }),
      f('postal_code', 'Kode Pos'),
      f('phone', 'Telepon'),
      f('email', 'Email'),
      f('website', 'Website'),
      f('headmaster_name', 'Nama Kepala Sekolah'),
      ACTIVE_FIELD,
    ],
  },
  {
    key: 'foundations',
    label: 'Yayasan',
    labelPlural: 'Yayasan',
    group: 'institusi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN(), f('phone', 'Telepon', 'text', { inTable: true }), f('email', 'Email', 'text', { inTable: true }), ACTIVE_COLUMN],
    fields: [
      f('name', 'Nama Yayasan', 'text', { required: true }),
      f('address', 'Alamat', 'textarea'),
      f('phone', 'Telepon'),
      f('email', 'Email'),
      ACTIVE_FIELD,
    ],
  },

  // ================= WILAYAH (read-only) =================
  {
    key: 'provinces',
    label: 'Provinsi',
    labelPlural: 'Provinsi',
    group: 'wilayah',
    readOnly: true,
    searchable: true,
    columns: [f('id', 'ID', 'text', { inTable: true, align: 'center' }), NAME_COLUMN()],
    fields: [f('id', 'ID'), f('name', 'Nama')],
  },
  {
    key: 'cities',
    label: 'Kabupaten/Kota',
    labelPlural: 'Kabupaten/Kota',
    group: 'wilayah',
    readOnly: true,
    searchable: true,
    columns: [f('id', 'ID', 'text', { inTable: true, align: 'center' }), NAME_COLUMN(), f('province_id', 'Provinsi', 'text', { inTable: true })],
    fields: [f('id', 'ID'), f('name', 'Nama'), f('province_id', 'Provinsi')],
  },
  {
    key: 'districts',
    label: 'Kecamatan',
    labelPlural: 'Kecamatan',
    group: 'wilayah',
    readOnly: true,
    searchable: true,
    columns: [f('id', 'ID', 'text', { inTable: true, align: 'center' }), NAME_COLUMN(), f('city_id', 'Kab./Kota', 'text', { inTable: true })],
    fields: [f('id', 'ID'), f('name', 'Nama'), f('city_id', 'Kab./Kota')],
  },
  {
    key: 'villages',
    label: 'Kelurahan',
    labelPlural: 'Kelurahan',
    group: 'wilayah',
    readOnly: true,
    searchable: true,
    columns: [f('id', 'ID', 'text', { inTable: true, align: 'center' }), NAME_COLUMN(), f('postal_code', 'Kode Pos', 'text', { inTable: true })],
    fields: [f('id', 'ID'), f('name', 'Nama'), f('postal_code', 'Kode Pos')],
  },

  // ================= AKADEMIK =================
  {
    key: 'curricula',
    label: 'Kurikulum',
    labelPlural: 'Kurikulum',
    group: 'akademik',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, CODE_COLUMN(), NAME_COLUMN(), ACTIVE_COLUMN],
    fields: [
      f('name', 'Nama Kurikulum', 'text', { required: true }),
      f('code', 'Kode', 'text', { required: true }),
      f('description', 'Deskripsi', 'textarea'),
      ACTIVE_FIELD,
    ],
  },
  {
    key: 'education-levels',
    label: 'Tingkat',
    labelPlural: 'Tingkat Pendidikan',
    group: 'akademik',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN(), f('level_number', 'Urutan', 'number', { inTable: true, align: 'center' }), f('jenjang', 'Jenjang', 'text', { inTable: true, align: 'center' }), ACTIVE_COLUMN],
    fields: [
      f('name', 'Nama Tingkat', 'text', { required: true }),
      f('level_number', 'Urutan Level', 'number', { required: true }),
      f('jenjang', 'Jenjang', 'select', { required: true, options: JENJANG_OPTIONS }),
      ACTIVE_FIELD,
    ],
  },
  {
    key: 'academic-years',
    label: 'Tahun Ajaran',
    labelPlural: 'Tahun Ajaran',
    group: 'akademik',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN(), f('start_date', 'Mulai', 'date', { inTable: true }), f('end_date', 'Selesai', 'date', { inTable: true }), ACTIVE_COLUMN],
    fields: [
      f('name', 'Nama Tahun Ajaran', 'text', { required: true, placeholder: '2026/2027' }),
      f('start_date', 'Tanggal Mulai', 'date', { required: true }),
      f('end_date', 'Tanggal Selesai', 'date', { required: true }),
      ACTIVE_FIELD,
    ],
  },
  {
    key: 'semesters',
    label: 'Semester',
    labelPlural: 'Semester',
    group: 'akademik',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, f('name', 'Semester', 'text', { inTable: true }), f('academic_year_id', 'Tahun Ajaran', 'text', { inTable: true }), f('start_date', 'Mulai', 'date', { inTable: true }), f('end_date', 'Selesai', 'date', { inTable: true }), ACTIVE_COLUMN],
    fields: [
      f('academic_year_id', 'Tahun Ajaran', 'select', { required: true, optionSource: { resource: 'academic-years' } }),
      f('name', 'Semester', 'select', {
        required: true,
        options: [
          { value: 'Ganjil', label: 'Ganjil' },
          { value: 'Genap', label: 'Genap' },
        ],
      }),
      f('start_date', 'Tanggal Mulai', 'date', { required: true }),
      f('end_date', 'Tanggal Selesai', 'date', { required: true }),
      ACTIVE_FIELD,
    ],
  },
  {
    key: 'subjects',
    label: 'Mata Pelajaran',
    labelPlural: 'Mata Pelajaran',
    group: 'akademik',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, CODE_COLUMN(), NAME_COLUMN('Nama Mapel'), f('category', 'Kategori', 'text', { inTable: true }), f('credit', 'Kredit', 'number', { inTable: true, align: 'center' }), ACTIVE_COLUMN],
    fields: [
      f('code', 'Kode Mapel', 'text', { required: true }),
      f('name', 'Nama Mapel', 'text', { required: true }),
      f('curriculum_id', 'Kurikulum', 'select', { optionSource: { resource: 'curricula' } }),
      f('education_level_id', 'Tingkat', 'select', { optionSource: { resource: 'education-levels' } }),
      f('category', 'Kategori', 'select', {
        required: true,
        options: [
          { value: 'Wajib', label: 'Wajib' },
          { value: 'Muatan Lokal', label: 'Muatan Lokal' },
          { value: 'Pilihan', label: 'Pilihan' },
        ],
      }),
      f('credit', 'Jumlah Kredit', 'number'),
      ACTIVE_FIELD,
    ],
  },
  {
    key: 'rooms',
    label: 'Ruangan',
    labelPlural: 'Ruangan',
    group: 'akademik',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, CODE_COLUMN(), NAME_COLUMN(), f('type', 'Tipe', 'text', { inTable: true }), f('capacity', 'Kapasitas', 'number', { inTable: true, align: 'center' }), ACTIVE_COLUMN],
    fields: [
      f('code', 'Kode Ruangan', 'text', { required: true }),
      f('name', 'Nama Ruangan', 'text', { required: true }),
      f('type', 'Tipe', 'select', {
        required: true,
        options: [
          { value: 'Kelas', label: 'Kelas' },
          { value: 'Lab', label: 'Lab' },
          { value: 'Perpustakaan', label: 'Perpustakaan' },
          { value: 'Kantor', label: 'Kantor' },
          { value: 'Lainnya', label: 'Lainnya' },
        ],
      }),
      f('capacity', 'Kapasitas', 'number'),
      f('building', 'Gedung'),
      ACTIVE_FIELD,
    ],
  },

  // ================= KESISWAAN =================
  {
    key: 'violation-types',
    label: 'Jenis Pelanggaran',
    labelPlural: 'Jenis Pelanggaran',
    group: 'kesiswaan',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN('Nama Pelanggaran'), f('category', 'Kategori', 'text', { inTable: true }), f('point', 'Poin', 'number', { inTable: true, align: 'center' }), ACTIVE_COLUMN],
    fields: [
      f('name', 'Nama Pelanggaran', 'text', { required: true }),
      f('category', 'Kategori', 'select', {
        required: true,
        options: [
          { value: 'Ringan', label: 'Ringan' },
          { value: 'Sedang', label: 'Sedang' },
          { value: 'Berat', label: 'Berat' },
        ],
      }),
      f('point', 'Poin', 'number', { required: true }),
      ACTIVE_FIELD,
    ],
  },
  {
    key: 'achievement-types',
    label: 'Jenis Prestasi',
    labelPlural: 'Jenis Prestasi',
    group: 'kesiswaan',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN('Nama Prestasi'), f('level', 'Tingkat', 'text', { inTable: true })],
    fields: [
      f('name', 'Nama Prestasi', 'text', { required: true }),
      f('level', 'Tingkat', 'select', {
        required: true,
        options: ['Sekolah', 'Kecamatan', 'Kabupaten', 'Provinsi', 'Nasional', 'Internasional'].map((v) => ({ value: v, label: v })),
      }),
    ],
  },
  {
    key: 'extracurriculars',
    label: 'Ekstrakurikuler',
    labelPlural: 'Ekstrakurikuler',
    group: 'kesiswaan',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN(), f('category', 'Kategori', 'text', { inTable: true }), f('instructor', 'Pembina', 'text', { inTable: true }), ACTIVE_COLUMN],
    fields: [
      f('name', 'Nama Ekstrakurikuler', 'text', { required: true }),
      f('category', 'Kategori'),
      f('instructor', 'Pembina'),
      ACTIVE_FIELD,
    ],
  },

  // ================= KEUANGAN =================
  {
    key: 'fee-components',
    label: 'Komponen Biaya',
    labelPlural: 'Komponen Biaya',
    group: 'keuangan',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, CODE_COLUMN(), NAME_COLUMN(), f('type', 'Tipe', 'text', { inTable: true }), f('default_amount', 'Nominal', 'number', { inTable: true, align: 'right' }), ACTIVE_COLUMN],
    fields: [
      f('code', 'Kode', 'text', { required: true }),
      f('name', 'Nama Komponen', 'text', { required: true }),
      f('type', 'Tipe', 'select', {
        required: true,
        options: [
          { value: 'Bulanan', label: 'Bulanan' },
          { value: 'Tahunan', label: 'Tahunan' },
          { value: 'Sekali', label: 'Sekali' },
        ],
      }),
      f('default_amount', 'Nominal Default', 'number', { required: true }),
      ACTIVE_FIELD,
    ],
  },
  {
    key: 'payment-methods',
    label: 'Metode Pembayaran',
    labelPlural: 'Metode Pembayaran',
    group: 'keuangan',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, CODE_COLUMN(), NAME_COLUMN(), ACTIVE_COLUMN],
    fields: [f('name', 'Nama Metode', 'text', { required: true }), f('code', 'Kode', 'text', { required: true }), ACTIVE_FIELD],
  },
  {
    key: 'banks',
    label: 'Bank',
    labelPlural: 'Bank',
    group: 'keuangan',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, CODE_COLUMN(), NAME_COLUMN(), ACTIVE_COLUMN],
    fields: [f('name', 'Nama Bank', 'text', { required: true }), f('code', 'Kode Bank', 'text', { required: true }), ACTIVE_FIELD],
  },
  {
    key: 'discount-types',
    label: 'Jenis Diskon',
    labelPlural: 'Jenis Diskon',
    group: 'keuangan',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN(), f('type', 'Tipe', 'text', { inTable: true }), f('value', 'Nilai', 'number', { inTable: true, align: 'right' }), ACTIVE_COLUMN],
    fields: [
      f('name', 'Nama Diskon', 'text', { required: true }),
      f('type', 'Tipe', 'select', {
        required: true,
        options: [
          { value: 'Persen', label: 'Persen' },
          { value: 'Nominal', label: 'Nominal' },
        ],
      }),
      f('value', 'Nilai', 'number', { required: true }),
      ACTIVE_FIELD,
    ],
  },
  {
    key: 'fiscal-years',
    label: 'Tahun Anggaran',
    labelPlural: 'Tahun Anggaran',
    group: 'keuangan',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN(), f('start_date', 'Mulai', 'date', { inTable: true }), f('end_date', 'Selesai', 'date', { inTable: true }), ACTIVE_COLUMN],
    fields: [
      f('name', 'Nama Tahun Anggaran', 'text', { required: true }),
      f('start_date', 'Tanggal Mulai', 'date', { required: true }),
      f('end_date', 'Tanggal Selesai', 'date', { required: true }),
      ACTIVE_FIELD,
    ],
  },

  // ================= PERPUSTAKAAN =================
  // Kategori, penerbit, dan pengarang/kreator kini dikelola di modul
  // Perpustakaan → Master Data (satu jalur tulis dengan permission `library.*`).
  {
    key: 'shelves',
    label: 'Rak',
    labelPlural: 'Rak Buku',
    group: 'perpustakaan',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, CODE_COLUMN(), NAME_COLUMN()],
    fields: [f('code', 'Kode Rak', 'text', { required: true }), f('name', 'Nama Rak', 'text', { required: true })],
  },

  // ================= INVENTARIS =================
  {
    key: 'item-categories',
    label: 'Kategori Barang',
    labelPlural: 'Kategori Barang',
    group: 'inventaris',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN(), f('type', 'Tipe', 'text', { inTable: true })],
    fields: [
      f('name', 'Nama Kategori', 'text', { required: true }),
      f('type', 'Tipe', 'select', {
        required: true,
        options: [
          { value: 'Aset', label: 'Aset' },
          { value: 'Habis Pakai', label: 'Habis Pakai' },
        ],
      }),
    ],
  },
  {
    key: 'units',
    label: 'Satuan',
    labelPlural: 'Satuan',
    group: 'inventaris',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN(), f('symbol', 'Simbol', 'text', { inTable: true })],
    fields: [f('name', 'Nama Satuan', 'text', { required: true }), f('symbol', 'Simbol')],
  },
  {
    key: 'storage-locations',
    label: 'Lokasi Simpan',
    labelPlural: 'Lokasi Penyimpanan',
    group: 'inventaris',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN('Nama Lokasi'), f('room_id', 'Ruangan', 'text', { inTable: true })],
    fields: [f('name', 'Nama Lokasi', 'text', { required: true }), f('room_id', 'Ruangan', 'select', { optionSource: { resource: 'rooms' } })],
  },

  // ================= ABSENSI =================
  {
    key: 'attendance-statuses',
    label: 'Status Absensi',
    labelPlural: 'Status Absensi',
    group: 'absensi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, CODE_COLUMN(), NAME_COLUMN(), f('color', 'Warna', 'text', { inTable: true })],
    fields: [f('code', 'Kode', 'text', { required: true }), f('name', 'Nama Status', 'text', { required: true }), f('color', 'Warna', 'text', { placeholder: '#22c55e' })],
  },
  {
    key: 'leave-types',
    label: 'Jenis Izin',
    labelPlural: 'Jenis Izin',
    group: 'absensi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN('Nama Izin'), f('requires_attachment', 'Perlu Lampiran', 'checkbox', { inTable: true, align: 'center' })],
    fields: [f('name', 'Nama Izin', 'text', { required: true }), f('requires_attachment', 'Wajib Lampiran', 'checkbox')],
  },

  // ================= REFERENSI =================
  {
    key: 'religions',
    label: 'Agama',
    labelPlural: 'Agama',
    group: 'referensi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN(), ACTIVE_COLUMN],
    fields: [f('name', 'Nama Agama', 'text', { required: true }), ACTIVE_FIELD],
  },
  {
    key: 'genders',
    label: 'Jenis Kelamin',
    labelPlural: 'Jenis Kelamin',
    group: 'referensi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, CODE_COLUMN('Kode'), NAME_COLUMN('Jenis Kelamin')],
    fields: [f('code', 'Kode (L/P)', 'text', { required: true }), f('name', 'Jenis Kelamin', 'text', { required: true })],
  },
  {
    key: 'occupations',
    label: 'Pekerjaan',
    labelPlural: 'Pekerjaan',
    group: 'referensi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN()],
    fields: [f('name', 'Nama Pekerjaan', 'text', { required: true })],
  },
  {
    key: 'education-backgrounds',
    label: 'Pendidikan',
    labelPlural: 'Latar Belakang Pendidikan',
    group: 'referensi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN(), f('level_number', 'Urutan', 'number', { inTable: true, align: 'center' })],
    fields: [f('name', 'Nama Pendidikan', 'text', { required: true }), f('level_number', 'Urutan', 'number')],
  },
  {
    key: 'income-ranges',
    label: 'Rentang Penghasilan',
    labelPlural: 'Rentang Penghasilan',
    group: 'referensi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, f('label', 'Label', 'text', { inTable: true }), f('min_value', 'Min', 'number', { inTable: true, align: 'right' }), f('max_value', 'Max', 'number', { inTable: true, align: 'right' })],
    fields: [f('label', 'Label', 'text', { required: true }), f('min_value', 'Nilai Minimal', 'number'), f('max_value', 'Nilai Maksimal', 'number')],
  },
  {
    key: 'guardian-relations',
    label: 'Hubungan Wali',
    labelPlural: 'Hubungan Wali',
    group: 'referensi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN()],
    fields: [f('name', 'Nama Hubungan', 'text', { required: true })],
  },
  {
    key: 'residence-types',
    label: 'Jenis Tinggal',
    labelPlural: 'Jenis Tinggal',
    group: 'referensi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN()],
    fields: [f('name', 'Nama Jenis Tinggal', 'text', { required: true })],
  },
  {
    key: 'transportations',
    label: 'Transportasi',
    labelPlural: 'Transportasi',
    group: 'referensi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN()],
    fields: [f('name', 'Nama Transportasi', 'text', { required: true })],
  },
  {
    key: 'student-statuses',
    label: 'Status Siswa',
    labelPlural: 'Status Siswa',
    group: 'referensi',
    searchable: true,
    softDelete: true,
    columns: [ID_COLUMN, NAME_COLUMN(), ACTIVE_COLUMN],
    fields: [f('name', 'Nama Status', 'text', { required: true }), ACTIVE_FIELD],
  },

  // ================= SISTEM =================
  {
    key: 'days',
    label: 'Hari',
    labelPlural: 'Hari',
    group: 'sistem',
    readOnly: true,
    searchable: true,
    columns: [f('id', 'ID', 'text', { inTable: true, align: 'center' }), NAME_COLUMN()],
    fields: [f('id', 'ID'), f('name', 'Nama Hari')],
  },
  {
    key: 'document-sequences',
    label: 'Kode Otomatis',
    labelPlural: 'Kode Otomatis (Penomoran)',
    group: 'sistem',
    searchable: true,
    columns: [ID_COLUMN, CODE_COLUMN(), f('prefix', 'Prefix', 'text', { inTable: true }), f('format', 'Format', 'text', { inTable: true }), f('reset_period', 'Reset', 'text', { inTable: true })],
    fields: [
      f('code', 'Kode Dokumen', 'text', { required: true }),
      f('prefix', 'Prefix'),
      f('format', 'Format', 'text', { placeholder: '{prefix}/{seq}/{year}' }),
      f('reset_period', 'Periode Reset', 'select', {
        required: true,
        options: [
          { value: 'never', label: 'Tidak Pernah' },
          { value: 'year', label: 'Tahunan' },
          { value: 'month', label: 'Bulanan' },
        ],
      }),
    ],
  },
];

/** Cari definisi resource berdasarkan key */
export function getResourceDef(key: string): MasterResourceDef | undefined {
  return MASTER_RESOURCES.find((r) => r.key === key);
}

export function getResourcesByGroup(group: MasterGroup): MasterResourceDef[] {
  return MASTER_RESOURCES.filter((r) => r.group === group);
}
