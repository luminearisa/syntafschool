import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ArrowLeft, Plus, Trash2, X } from 'lucide-react';
import { toast } from 'sonner';
import { libraryCatalogApi, libraryMastersApi } from '../api/libraryApi';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import {
  BIBLIOGRAPHY_STATUS_HINTS,
  BIBLIOGRAPHY_STATUS_LABELS,
  FILE_TYPE_LABELS,
  IDENTIFIER_TYPE_LABELS,
} from '../lib/catalogLabels';
import { toOptions } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { cn } from '@/lib/utils';
import type {
  BibliographyCreatorInput,
  BibliographyFile,
  BibliographyFileType,
  BibliographyIdentifier,
  BibliographyPayload,
  BibliographyStatus,
  DuplicateCheckResult,
  IdentifierType,
  LibraryBibliography,
} from '@/types/libraryCatalog';

const statusOptions = toOptions(BIBLIOGRAPHY_STATUS_LABELS);
const identifierOptions = toOptions(IDENTIFIER_TYPE_LABELS);
const fileTypeOptions = toOptions(FILE_TYPE_LABELS);

type TabKey = 'basic' | 'publication' | 'creators' | 'classification' | 'identifiers' | 'description' | 'media';

const TABS: { key: TabKey; label: string }[] = [
  { key: 'basic', label: 'Dasar' },
  { key: 'publication', label: 'Publikasi' },
  { key: 'creators', label: 'Kreator' },
  { key: 'classification', label: 'Klasifikasi' },
  { key: 'identifiers', label: 'Identifier' },
  { key: 'description', label: 'Deskripsi' },
  { key: 'media', label: 'Media' },
];

interface FormState {
  library_id: number | '';
  title: string;
  subtitle: string;
  uniform_title: string;
  collection_type_id: number | '';
  material_type_id: number | '';
  category_id: number | '';
  language_id: number | '';
  publisher_id: number | '';
  publication_place: string;
  publication_year: number | '';
  edition_statement: string;
  edition_number: number | '';
  edition_year: number | '';
  publication_statement: string;
  series_id: number | '';
  classification_id: number | '';
  call_number: string;
  abstract: string;
  description: string;
  table_of_contents: string;
  physical_description: string;
  notes: string;
  cover_image: string;
  thumbnail: string;
  digital_available: boolean;
  physical_available: boolean;
  status: BibliographyStatus;
}

const EMPTY_FORM: FormState = {
  library_id: '',
  title: '',
  subtitle: '',
  uniform_title: '',
  collection_type_id: '',
  material_type_id: '',
  category_id: '',
  language_id: '',
  publisher_id: '',
  publication_place: '',
  publication_year: '',
  edition_statement: '',
  edition_number: '',
  edition_year: '',
  publication_statement: '',
  series_id: '',
  classification_id: '',
  call_number: '',
  abstract: '',
  description: '',
  table_of_contents: '',
  physical_description: '',
  notes: '',
  cover_image: '',
  thumbnail: '',
  digital_available: true,
  physical_available: true,
  status: 'draft',
};

const toNumber = (v: number | null | undefined): number | '' => (v === null || v === undefined ? '' : v);
const toText = (v: string | null | undefined): string => v ?? '';

/** Field dengan label + pesan error server. */
function Field({
  label,
  required,
  hint,
  error,
  children,
}: {
  label: string;
  required?: boolean;
  hint?: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <Label required={required}>{label}</Label>
      {children}
      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  );
}

/** Pemilih master data dengan nilai tetap (dipakai di dalam form). */
function MasterSelect({
  masterKey,
  value,
  error,
  placeholder = '-- Pilih --',
  onChange,
}: {
  masterKey: string;
  value: number | '';
  error?: string;
  placeholder?: string;
  onChange: (value: number | '') => void;
}) {
  const { data } = useQuery({
    queryKey: ['library', 'masters', masterKey, 'options'],
    staleTime: 1000 * 60 * 5,
    queryFn: () => libraryMastersApi.list(masterKey, { all: true }),
  });

  const options = useMemo(
    () =>
      (data?.data ?? []).map((row) => ({
        value: row.id,
        label: row.code ? `${row.code} — ${row.name}` : row.name,
      })),
    [data],
  );

  return (
    <SearchSelect
      options={options}
      value={value}
      error={error}
      placeholder={placeholder}
      onChange={(v) => onChange(v === '' ? '' : Number(v))}
    />
  );
}

/** Multi-tambah master data (subjects / genres / tags) sebagai chip. */
function MultiAdd({
  masterKey,
  values,
  error,
  onChange,
}: {
  masterKey: string;
  values: number[];
  error?: string;
  onChange: (value: number[]) => void;
}) {
  const [pick, setPick] = useState<number | ''>('');

  const { data } = useQuery({
    queryKey: ['library', 'masters', masterKey, 'options'],
    staleTime: 1000 * 60 * 5,
    queryFn: () => libraryMastersApi.list(masterKey, { all: true }),
  });

  const options = useMemo(
    () =>
      (data?.data ?? []).map((row) => ({
        value: row.id,
        label: row.code ? `${row.code} — ${row.name}` : row.name,
      })),
    [data],
  );

  const labels = useMemo(
    () => new Map(options.map((o) => [Number(o.value), o.label])),
    [options],
  );
  const available = options.filter((o) => !values.includes(Number(o.value)));

  const add = () => {
    if (pick !== '' && !values.includes(Number(pick))) {
      onChange([...values, Number(pick)]);
      setPick('');
    }
  };

  return (
    <div className="space-y-2">
      <div className="flex gap-2">
        <SearchSelect
          options={available}
          value={pick}
          error={error}
          className="flex-1"
          onChange={(v) => setPick(v === '' ? '' : Number(v))}
        />
        <Button type="button" variant="outline" size="sm" className="shrink-0" onClick={add}>
          <Plus className="h-3.5 w-3.5" /> Tambah
        </Button>
      </div>
      {values.length > 0 && (
        <div className="flex flex-wrap gap-1">
          {values.map((v) => (
            <Badge key={v} tone="blue" className="gap-1">
              {labels.get(v) ?? v}
              <button
                type="button"
                className="text-blue-600 hover:text-blue-800"
                onClick={() => onChange(values.filter((x) => x !== v))}
              >
                <X className="h-3 w-3" />
              </button>
            </Badge>
          ))}
        </div>
      )}
    </div>
  );
}

/**
 * Form katalog (tambah / ubah) dalam bentuk tab bertahap (§53).
 *
 * Deteksi duplikat (§18) berjalan setelah judul / identifier berubah dengan
 * debounce — murni peringatan, tidak pernah memblokir penyimpanan.
 */
export function LibraryCatalogFormPage() {
  const { id: idParam } = useParams<{ id: string }>();
  const id = idParam ? Number(idParam) : null;
  const isEdit = id !== null;
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [tab, setTab] = useState<TabKey>('basic');
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [creators, setCreators] = useState<BibliographyCreatorInput[]>([]);
  const [identifiers, setIdentifiers] = useState<BibliographyIdentifier[]>([]);
  const [files, setFiles] = useState<BibliographyFile[]>([]);
  const [subjectIds, setSubjectIds] = useState<number[]>([]);
  const [genreIds, setGenreIds] = useState<number[]>([]);
  const [tagIds, setTagIds] = useState<number[]>([]);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [duplicates, setDuplicates] = useState<DuplicateCheckResult | null>(null);

  const { options: libraryOptions } = useLibraryOptions();

  const detailQuery = useQuery({
    queryKey: ['library', 'catalog', id],
    enabled: isEdit,
    queryFn: () => libraryCatalogApi.show(id as number),
  });

  useEffect(() => {
    const rec: LibraryBibliography | undefined = detailQuery.data;
    if (!rec) return;
    setForm({
      library_id: toNumber(rec.library_id),
      title: rec.title,
      subtitle: toText(rec.subtitle),
      uniform_title: toText(rec.uniform_title),
      collection_type_id: toNumber(rec.collection_type_id),
      material_type_id: toNumber(rec.material_type_id),
      category_id: toNumber(rec.category_id),
      language_id: toNumber(rec.language_id),
      publisher_id: toNumber(rec.publisher_id),
      publication_place: toText(rec.publication_place),
      publication_year: toNumber(rec.publication_year),
      edition_statement: toText(rec.edition_statement),
      edition_number: toNumber(rec.edition_number),
      edition_year: toNumber(rec.edition_year),
      publication_statement: toText(rec.publication_statement),
      series_id: toNumber(rec.series_id),
      classification_id: toNumber(rec.classification_id),
      call_number: toText(rec.call_number),
      abstract: toText(rec.abstract),
      description: toText(rec.description),
      table_of_contents: toText(rec.table_of_contents),
      physical_description: toText(rec.physical_description),
      notes: toText(rec.notes),
      cover_image: toText(rec.cover_image),
      thumbnail: toText(rec.thumbnail),
      digital_available: rec.digital_available,
      physical_available: rec.physical_available,
      status: rec.status,
    });
    setCreators(
      (rec.creators ?? []).map((c) => ({
        creator_id: c.creator_id,
        creator_role_id: c.creator_role_id ?? '',
        sequence: c.sequence,
        is_primary: c.is_primary,
        display_name: c.name ?? '',
      })),
    );
    setIdentifiers(rec.identifiers ?? []);
    setFiles(rec.files ?? []);
    setSubjectIds((rec.subjects ?? []).map((s) => s.id));
    setGenreIds((rec.genres ?? []).map((g) => g.id));
    setTagIds((rec.tags ?? []).map((t) => t.id));
  }, [detailQuery.data]);

  function setField<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
  }

  const err = (key: string): string | undefined => serverErrors[key]?.[0];

  /* ------------------------- Deteksi duplikat (§18) ------------------------- */

  const identifiersSignature = JSON.stringify(
    identifiers.map((i) => `${i.identifier_type}:${i.value}`),
  );

  useEffect(() => {
    const title = form.title.trim();
    const filledIdentifiers = identifiers.filter((i) => i.value.trim() !== '');
    if (title.length < 3 && filledIdentifiers.length === 0) {
      setDuplicates(null);
      return;
    }
    const handle = window.setTimeout(() => {
      libraryCatalogApi
        .duplicateCheck({
          title,
          publisher_id: form.publisher_id,
          publication_year: form.publication_year,
          identifiers: filledIdentifiers,
          ignore_id: id ?? undefined,
        })
        .then((res) => setDuplicates(res))
        .catch(() => setDuplicates(null));
    }, 600);
    return () => window.clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form.title, form.publisher_id, form.publication_year, identifiersSignature, id]);

  /* ------------------------------- Kreator -------------------------------- */

  const addCreator = () =>
    setCreators((prev) => [
      ...prev,
      {
        creator_id: '',
        creator_role_id: '',
        sequence: prev.length + 1,
        is_primary: prev.length === 0,
        display_name: '',
      },
    ]);

  const updateCreator = (index: number, patch: Partial<BibliographyCreatorInput>) =>
    setCreators((prev) => prev.map((c, i) => (i === index ? { ...c, ...patch } : c)));

  const removeCreator = (index: number) =>
    setCreators((prev) => {
      const next = prev.filter((_, i) => i !== index);
      if (next.length > 0 && !next.some((c) => c.is_primary)) {
        return next.map((c, i) => ({ ...c, is_primary: i === 0 }));
      }
      return next;
    });

  const setPrimaryCreator = (index: number) =>
    setCreators((prev) => prev.map((c, i) => ({ ...c, is_primary: i === index })));

  /* ------------------------------ Identifier ------------------------------ */

  const addIdentifier = () =>
    setIdentifiers((prev) => [
      ...prev,
      { identifier_type: 'isbn13', value: '', is_primary: prev.length === 0 },
    ]);

  const updateIdentifier = (index: number, patch: Partial<BibliographyIdentifier>) =>
    setIdentifiers((prev) => prev.map((r, i) => (i === index ? { ...r, ...patch } : r)));

  const removeIdentifier = (index: number) =>
    setIdentifiers((prev) => prev.filter((_, i) => i !== index));

  /* -------------------------------- Media --------------------------------- */

  const addFile = () =>
    setFiles((prev) => [...prev, { file_type: 'additional', file_path: '', caption: '' }]);

  const updateFile = (index: number, patch: Partial<BibliographyFile>) =>
    setFiles((prev) => prev.map((r, i) => (i === index ? { ...r, ...patch } : r)));

  const removeFile = (index: number) => setFiles((prev) => prev.filter((_, i) => i !== index));

  /* -------------------------------- Submit -------------------------------- */

  const saveMutation = useMutation({
    mutationFn: (payload: BibliographyPayload) =>
      isEdit
        ? libraryCatalogApi.update(id as number, payload)
        : libraryCatalogApi.create(payload),
    onSuccess: (rec) => {
      toast.success(isEdit ? 'Katalog diperbarui' : 'Katalog dibuat');
      queryClient.invalidateQueries({ queryKey: ['library', 'catalog'] });
      navigate(`/library/catalog/${rec.id}`);
    },
    onError: (errValue) => {
      setServerErrors(getValidationErrors(errValue) ?? {});
      toast.error(getErrorMessage(errValue));
    },
  });

  const buildPayload = (): BibliographyPayload => ({
    library_id: form.library_id,
    title: form.title.trim(),
    subtitle: form.subtitle,
    uniform_title: form.uniform_title,
    collection_type_id: form.collection_type_id,
    material_type_id: form.material_type_id,
    category_id: form.category_id,
    language_id: form.language_id,
    publisher_id: form.publisher_id,
    publication_place: form.publication_place,
    publication_year: form.publication_year,
    edition_statement: form.edition_statement,
    edition_number: form.edition_number,
    edition_year: form.edition_year,
    publication_statement: form.publication_statement,
    series_id: form.series_id,
    classification_id: form.classification_id,
    call_number: form.call_number,
    abstract: form.abstract,
    description: form.description,
    table_of_contents: form.table_of_contents,
    physical_description: form.physical_description,
    notes: form.notes,
    cover_image: form.cover_image,
    thumbnail: form.thumbnail,
    digital_available: form.digital_available,
    physical_available: form.physical_available,
    status: form.status,
    creators: creators
      .filter((c) => c.creator_id !== '')
      .map((c, i) => ({
        creator_id: Number(c.creator_id),
        creator_role_id:
          c.creator_role_id === '' || c.creator_role_id === undefined
            ? undefined
            : Number(c.creator_role_id),
        sequence: c.sequence === '' || c.sequence === undefined ? i + 1 : Number(c.sequence),
        is_primary: Boolean(c.is_primary),
        display_name: c.display_name || undefined,
      })),
    identifiers: identifiers
      .filter((i) => i.value.trim() !== '')
      .map((i) => ({
        ...i,
        value: i.value.trim(),
        is_primary: Boolean(i.is_primary),
      })),
    files: files
      .filter((f) => f.file_path.trim() !== '')
      .map((f, i) => ({ ...f, file_path: f.file_path.trim(), sort_order: i + 1 })),
    subjects: subjectIds,
    genres: genreIds,
    tags: tagIds,
  });

  const goPrevTab = () => {
    const index = TABS.findIndex((t) => t.key === tab);
    const prev = TABS[index - 1];
    if (prev) setTab(prev.key);
  };

  const goNextTab = () => {
    const index = TABS.findIndex((t) => t.key === tab);
    const next = TABS[index + 1];
    if (next) setTab(next.key);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (form.title.trim() === '') {
      setTab('basic');
      setServerErrors((prev) => ({ ...prev, title: ['Judul wajib diisi.'] }));
      return;
    }
    saveMutation.mutate(buildPayload());
  };

  if (isEdit && detailQuery.isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
          <Spinner /> Memuat katalog...
        </div>
      </Card>
    );
  }

  if (isEdit && detailQuery.isError) {
    return (
      <Card>
        <EmptyState
          title="Gagal memuat katalog"
          description={getErrorMessage(detailQuery.error)}
          action={
            <Button size="sm" variant="outline" className="mt-2" onClick={() => detailQuery.refetch()}>
              Coba lagi
            </Button>
          }
        />
      </Card>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">
            {isEdit ? 'Ubah Katalog' : 'Tambah Katalog'}
          </h1>
          <p className="text-sm text-slate-500">
            Record bibliografi untuk satu judul. Eksemplar fisik ditambahkan terpisah.
          </p>
        </div>
        <Link
          to={isEdit && id !== null ? `/library/catalog/${id}` : '/library/catalog'}
          className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700"
        >
          <ArrowLeft className="h-4 w-4" /> Kembali
        </Link>
      </div>

      {/* Peringatan duplikat — non-blocking */}
      {duplicates?.has_candidates && (
        <Card className="border-amber-200 bg-amber-50 p-4">
          <div className="flex items-start gap-2">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
            <div className="space-y-1">
              <p className="text-sm font-medium text-amber-800">
                {duplicates.message ?? 'Kemungkinan duplikat ditemukan'}
              </p>
              <ul className="space-y-1">
                {duplicates.candidates.map((c) => (
                  <li key={c.id} className="text-xs text-amber-800">
                    <Link
                      to={`/library/catalog/${c.id}`}
                      className="font-mono hover:underline"
                    >
                      {c.control_number}
                    </Link>{' '}
                    — {c.title}
                    {c.publisher ? ` · ${c.publisher}` : ''}
                    {c.publication_year ? ` · ${c.publication_year}` : ''}
                  </li>
                ))}
              </ul>
              <p className="text-xs text-amber-700">
                Anda tetap dapat menyimpan; periksa dahulu apakah ini memang judul berbeda.
              </p>
            </div>
          </div>
        </Card>
      )}

      {/* Informasi umum */}
      <Card className="p-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          {!isEdit && (
            <Field label="Perpustakaan" required error={err('library_id')}>
              <SearchSelect
                options={libraryOptions}
                value={form.library_id}
                error={err('library_id')}
                onChange={(v) => setField('library_id', v === '' ? '' : Number(v))}
              />
            </Field>
          )}
          <Field label="Status" hint={BIBLIOGRAPHY_STATUS_HINTS[form.status]} error={err('status')}>
            <Select
              value={form.status}
              error={err('status')}
              onChange={(e) => setField('status', e.target.value as BibliographyStatus)}
            >
              {statusOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </Field>
          <div>
            <Label>Ketersediaan</Label>
            <div className="flex flex-wrap items-center gap-4 pt-2">
              <label className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-slate-300"
                  checked={form.digital_available}
                  onChange={(e) => setField('digital_available', e.target.checked)}
                />
                Digital
              </label>
              <label className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-slate-300"
                  checked={form.physical_available}
                  onChange={(e) => setField('physical_available', e.target.checked)}
                />
                Fisik
              </label>
            </div>
          </div>
        </div>
      </Card>

      {/* Navigasi tab */}
      <Card className="p-1">
        <div className="flex flex-wrap gap-1">
          {TABS.map((t) => (
            <button
              key={t.key}
              type="button"
              onClick={() => setTab(t.key)}
              className={cn(
                'rounded-lg px-3 py-1.5 text-sm transition-colors',
                tab === t.key
                  ? 'bg-brand-50 font-medium text-brand-700'
                  : 'text-slate-600 hover:bg-slate-50',
              )}
            >
              {t.label}
            </button>
          ))}
        </div>
      </Card>

      <Card className="p-4">
        {tab === 'basic' && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <Field label="Judul" required error={err('title')}>
                <Input
                  value={form.title}
                  error={err('title')}
                  onChange={(e) => setField('title', e.target.value)}
                />
              </Field>
            </div>
            <Field label="Subjudul" error={err('subtitle')}>
              <Input
                value={form.subtitle}
                error={err('subtitle')}
                onChange={(e) => setField('subtitle', e.target.value)}
              />
            </Field>
            <Field label="Judul Seragam" error={err('uniform_title')}>
              <Input
                value={form.uniform_title}
                error={err('uniform_title')}
                onChange={(e) => setField('uniform_title', e.target.value)}
              />
            </Field>
            <Field label="Jenis Koleksi" error={err('collection_type_id')}>
              <MasterSelect
                masterKey="collection-types"
                value={form.collection_type_id}
                error={err('collection_type_id')}
                onChange={(v) => setField('collection_type_id', v)}
              />
            </Field>
            <Field label="Jenis Materi" error={err('material_type_id')}>
              <MasterSelect
                masterKey="material-types"
                value={form.material_type_id}
                error={err('material_type_id')}
                onChange={(v) => setField('material_type_id', v)}
              />
            </Field>
            <Field label="Kategori" error={err('category_id')}>
              <MasterSelect
                masterKey="categories"
                value={form.category_id}
                error={err('category_id')}
                onChange={(v) => setField('category_id', v)}
              />
            </Field>
            <Field label="Bahasa" error={err('language_id')}>
              <MasterSelect
                masterKey="languages"
                value={form.language_id}
                error={err('language_id')}
                onChange={(v) => setField('language_id', v)}
              />
            </Field>
          </div>
        )}

        {tab === 'publication' && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="Penerbit" error={err('publisher_id')}>
              <MasterSelect
                masterKey="publishers"
                value={form.publisher_id}
                error={err('publisher_id')}
                onChange={(v) => setField('publisher_id', v)}
              />
            </Field>
            <Field label="Tempat Terbit" error={err('publication_place')}>
              <Input
                value={form.publication_place}
                error={err('publication_place')}
                onChange={(e) => setField('publication_place', e.target.value)}
              />
            </Field>
            <Field label="Tahun Terbit" error={err('publication_year')}>
              <Input
                type="number"
                value={form.publication_year === '' ? '' : String(form.publication_year)}
                error={err('publication_year')}
                onChange={(e) =>
                  setField('publication_year', e.target.value === '' ? '' : Number(e.target.value))
                }
              />
            </Field>
            <Field label="Seri" error={err('series_id')}>
              <MasterSelect
                masterKey="series"
                value={form.series_id}
                error={err('series_id')}
                onChange={(v) => setField('series_id', v)}
              />
            </Field>
            <Field label="Pernyataan Edisi" error={err('edition_statement')}>
              <Input
                value={form.edition_statement}
                error={err('edition_statement')}
                onChange={(e) => setField('edition_statement', e.target.value)}
              />
            </Field>
            <Field label="Nomor Edisi" error={err('edition_number')}>
              <Input
                type="number"
                value={form.edition_number === '' ? '' : String(form.edition_number)}
                error={err('edition_number')}
                onChange={(e) =>
                  setField('edition_number', e.target.value === '' ? '' : Number(e.target.value))
                }
              />
            </Field>
            <Field label="Tahun Edisi" error={err('edition_year')}>
              <Input
                type="number"
                value={form.edition_year === '' ? '' : String(form.edition_year)}
                error={err('edition_year')}
                onChange={(e) =>
                  setField('edition_year', e.target.value === '' ? '' : Number(e.target.value))
                }
              />
            </Field>
            <div className="sm:col-span-2">
              <Field label="Pernyataan Publikasi" error={err('publication_statement')}>
                <Textarea
                  value={form.publication_statement}
                  error={err('publication_statement')}
                  onChange={(e) => setField('publication_statement', e.target.value)}
                />
              </Field>
            </div>
          </div>
        )}

        {tab === 'creators' && (
          <div className="space-y-3">
            {err('creators') && <p className="text-xs text-red-500">{err('creators')}</p>}
            {creators.length === 0 && (
              <p className="text-sm text-slate-400">Belum ada kreator. Tambahkan minimal satu.</p>
            )}
            {creators.map((c, index) => (
              <div
                key={index}
                className="grid grid-cols-1 gap-3 rounded-lg border border-slate-100 p-3 sm:grid-cols-2 lg:grid-cols-3"
              >
                <Field label="Kreator" error={err(`creators.${index}.creator_id`)}>
                  <MasterSelect
                    masterKey="creators"
                    value={c.creator_id}
                    error={err(`creators.${index}.creator_id`)}
                    onChange={(v) => updateCreator(index, { creator_id: v })}
                  />
                </Field>
                <Field label="Peran" error={err(`creators.${index}.creator_role_id`)}>
                  <MasterSelect
                    masterKey="creator-roles"
                    value={c.creator_role_id ?? ''}
                    error={err(`creators.${index}.creator_role_id`)}
                    onChange={(v) => updateCreator(index, { creator_role_id: v })}
                  />
                </Field>
                <Field label="Urutan" error={err(`creators.${index}.sequence`)}>
                  <Input
                    type="number"
                    value={c.sequence === '' || c.sequence === undefined ? '' : String(c.sequence)}
                    onChange={(e) =>
                      updateCreator(index, {
                        sequence: e.target.value === '' ? '' : Number(e.target.value),
                      })
                    }
                  />
                </Field>
                <Field label="Nama Tampil" error={err(`creators.${index}.display_name`)}>
                  <Input
                    value={c.display_name ?? ''}
                    onChange={(e) => updateCreator(index, { display_name: e.target.value })}
                  />
                </Field>
                <div className="flex items-end gap-3">
                  <label className="flex items-center gap-2 pt-2 text-sm text-slate-700">
                    <input
                      type="radio"
                      name="bibliography-primary-creator"
                      className="h-4 w-4 border-slate-300"
                      checked={Boolean(c.is_primary)}
                      onChange={() => setPrimaryCreator(index)}
                    />
                    Kreator utama
                  </label>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="text-red-500"
                    onClick={() => removeCreator(index)}
                  >
                    <Trash2 className="h-3.5 w-3.5" /> Hapus
                  </Button>
                </div>
              </div>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={addCreator}>
              <Plus className="h-3.5 w-3.5" /> Tambah Kreator
            </Button>
          </div>
        )}

        {tab === 'classification' && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="Klasifikasi" error={err('classification_id')}>
              <MasterSelect
                masterKey="classifications"
                value={form.classification_id}
                error={err('classification_id')}
                onChange={(v) => setField('classification_id', v)}
              />
            </Field>
            <Field
              label="Nomor Panggil"
              hint="Biarkan kosong agar disusun otomatis mengikuti pola penomoran perpustakaan."
              error={err('call_number')}
            >
              <Input
                value={form.call_number}
                error={err('call_number')}
                onChange={(e) => setField('call_number', e.target.value)}
              />
            </Field>
            <Field label="Subjek" error={err('subjects')}>
              <MultiAdd
                masterKey="subjects"
                values={subjectIds}
                error={err('subjects')}
                onChange={setSubjectIds}
              />
            </Field>
            <Field label="Genre" error={err('genres')}>
              <MultiAdd
                masterKey="genres"
                values={genreIds}
                error={err('genres')}
                onChange={setGenreIds}
              />
            </Field>
            <div className="sm:col-span-2">
              <Field label="Tag" error={err('tags')}>
                <MultiAdd
                  masterKey="tags"
                  values={tagIds}
                  error={err('tags')}
                  onChange={setTagIds}
                />
              </Field>
            </div>
          </div>
        )}

        {tab === 'identifiers' && (
          <div className="space-y-3">
            {err('identifiers') && <p className="text-xs text-red-500">{err('identifiers')}</p>}
            {identifiers.length === 0 && (
              <p className="text-sm text-slate-400">Belum ada identifier (ISBN/ISSN dsb.).</p>
            )}
            {identifiers.map((r, index) => (
              <div
                key={index}
                className="grid grid-cols-1 gap-3 rounded-lg border border-slate-100 p-3 sm:grid-cols-3"
              >
                <Field label="Jenis" error={err(`identifiers.${index}.identifier_type`)}>
                  <Select
                    value={r.identifier_type}
                    error={err(`identifiers.${index}.identifier_type`)}
                    onChange={(e) =>
                      updateIdentifier(index, { identifier_type: e.target.value as IdentifierType })
                    }
                  >
                    {identifierOptions.map((opt) => (
                      <option key={opt.value} value={opt.value}>
                        {opt.label}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label="Nilai" error={err(`identifiers.${index}.value`)}>
                  <Input
                    value={r.value}
                    error={err(`identifiers.${index}.value`)}
                    onChange={(e) => updateIdentifier(index, { value: e.target.value })}
                  />
                </Field>
                <div className="flex items-end gap-3">
                  <label className="flex items-center gap-2 pt-2 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-slate-300"
                      checked={Boolean(r.is_primary)}
                      onChange={(e) => updateIdentifier(index, { is_primary: e.target.checked })}
                    />
                    Utama
                  </label>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="text-red-500"
                    onClick={() => removeIdentifier(index)}
                  >
                    <Trash2 className="h-3.5 w-3.5" /> Hapus
                  </Button>
                </div>
              </div>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={addIdentifier}>
              <Plus className="h-3.5 w-3.5" /> Tambah Identifier
            </Button>
          </div>
        )}

        {tab === 'description' && (
          <div className="space-y-3">
            <Field label="Abstrak" error={err('abstract')}>
              <Textarea
                value={form.abstract}
                error={err('abstract')}
                onChange={(e) => setField('abstract', e.target.value)}
              />
            </Field>
            <Field label="Deskripsi" error={err('description')}>
              <Textarea
                value={form.description}
                error={err('description')}
                onChange={(e) => setField('description', e.target.value)}
              />
            </Field>
            <Field label="Daftar Isi" error={err('table_of_contents')}>
              <Textarea
                value={form.table_of_contents}
                error={err('table_of_contents')}
                onChange={(e) => setField('table_of_contents', e.target.value)}
              />
            </Field>
            <Field label="Deskripsi Fisik" error={err('physical_description')}>
              <Textarea
                value={form.physical_description}
                error={err('physical_description')}
                onChange={(e) => setField('physical_description', e.target.value)}
              />
            </Field>
            <Field label="Catatan" error={err('notes')}>
              <Textarea
                value={form.notes}
                error={err('notes')}
                onChange={(e) => setField('notes', e.target.value)}
              />
            </Field>
          </div>
        )}

        {tab === 'media' && (
          <div className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field
                label="Gambar Cover"
                hint="Path/URL gambar — unggah berkas belum tersedia di API."
                error={err('cover_image')}
              >
                <Input
                  value={form.cover_image}
                  error={err('cover_image')}
                  onChange={(e) => setField('cover_image', e.target.value)}
                />
              </Field>
              <Field
                label="Thumbnail"
                hint="Path/URL thumbnail."
                error={err('thumbnail')}
              >
                <Input
                  value={form.thumbnail}
                  error={err('thumbnail')}
                  onChange={(e) => setField('thumbnail', e.target.value)}
                />
              </Field>
            </div>
            <div className="border-t border-slate-100 pt-3">
              <p className="mb-2 text-sm font-medium text-slate-700">Berkas Tambahan</p>
              <p className="mb-3 text-xs text-slate-400">
                Belum ada API unggah berkas — isi path/URL berkas secara manual.
              </p>
              {err('files') && <p className="mb-2 text-xs text-red-500">{err('files')}</p>}
              <div className="space-y-3">
                {files.map((f, index) => (
                  <div
                    key={index}
                    className="grid grid-cols-1 gap-3 rounded-lg border border-slate-100 p-3 sm:grid-cols-3"
                  >
                    <Field label="Jenis Berkas" error={err(`files.${index}.file_type`)}>
                      <Select
                        value={f.file_type}
                        error={err(`files.${index}.file_type`)}
                        onChange={(e) =>
                          updateFile(index, { file_type: e.target.value as BibliographyFileType })
                        }
                      >
                        {fileTypeOptions.map((opt) => (
                          <option key={opt.value} value={opt.value}>
                            {opt.label}
                          </option>
                        ))}
                      </Select>
                    </Field>
                    <Field label="Path Berkas" error={err(`files.${index}.file_path`)}>
                      <Input
                        value={f.file_path}
                        error={err(`files.${index}.file_path`)}
                        onChange={(e) => updateFile(index, { file_path: e.target.value })}
                      />
                    </Field>
                    <Field label="Keterangan" error={err(`files.${index}.caption`)}>
                      <Input
                        value={f.caption ?? ''}
                        error={err(`files.${index}.caption`)}
                        onChange={(e) => updateFile(index, { caption: e.target.value })}
                      />
                    </Field>
                    <div>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-red-500"
                        onClick={() => removeFile(index)}
                      >
                        <Trash2 className="h-3.5 w-3.5" /> Hapus Berkas
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
              <Button type="button" variant="outline" size="sm" className="mt-3" onClick={addFile}>
                <Plus className="h-3.5 w-3.5" /> Tambah Berkas
              </Button>
            </div>
          </div>
        )}
      </Card>

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex gap-2">
          <Button
            type="button"
            variant="outline"
            disabled={tab === 'basic'}
            onClick={goPrevTab}
          >
            Sebelumnya
          </Button>
          <Button
            type="button"
            variant="outline"
            disabled={tab === 'media'}
            onClick={goNextTab}
          >
            Berikutnya
          </Button>
        </div>
        <div className="flex gap-2">
          <Link to={isEdit && id !== null ? `/library/catalog/${id}` : '/library/catalog'}>
            <Button type="button" variant="outline">
              Batal
            </Button>
          </Link>
          <Button type="submit" loading={saveMutation.isPending}>
            Simpan
          </Button>
        </div>
      </div>
    </form>
  );
}