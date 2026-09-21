import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Save } from 'lucide-react';
import { toast } from 'sonner';
import { librariesApi, librarySettingsApi } from '../api/libraryApi';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { LIBRARY_TIMEZONES } from '../components/LibraryFormModal';
import {
  LIBRARY_STATUS_LABELS,
  SETTING_GROUP_LABELS,
  SETTING_TYPE_LABELS,
} from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Can } from '@/components/common/Can';
import { cn } from '@/lib/utils';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { LibrarySettingEntry, LibraryPayload } from '@/types/library';

type TabKey = 'general' | 'loan' | 'catalog' | 'member' | 'others';
type FormValue = string | boolean;

const TABS: { key: TabKey; label: string }[] = [
  { key: 'general', label: 'Umum' },
  { key: 'loan', label: 'Peminjaman' },
  { key: 'catalog', label: 'Katalog' },
  { key: 'member', label: 'Keanggotaan' },
  { key: 'others', label: 'Lainnya' },
];

/** Grup pengaturan yang sudah punya tab sendiri. */
const KNOWN_GROUPS = ['general', 'loan', 'fine', 'member', 'catalog', 'reservation'];

interface GeneralForm {
  name: string;
  code: string;
  description: string;
  phone: string;
  email: string;
  address: string;
  timezone: string;
  logo_path: string;
}

const emptyGeneral: GeneralForm = {
  name: '',
  code: '',
  description: '',
  phone: '',
  email: '',
  address: '',
  timezone: 'Asia/Jakarta',
  logo_path: '',
};

function toFormValue(entry: LibrarySettingEntry): FormValue {
  if (entry.type === 'boolean') {
    return entry.value === true || entry.value === 1 || entry.value === '1';
  }
  if (entry.value === null || entry.value === undefined) return '';
  if (entry.type === 'json') {
    try {
      return JSON.stringify(entry.value, null, 2);
    } catch {
      return '';
    }
  }
  return String(entry.value);
}

/** Field untuk satu entri konfigurasi, menyesuaikan tipe nilainya. */
function SettingField({
  entry,
  value,
  error,
  onChange,
}: {
  entry: LibrarySettingEntry;
  value: FormValue;
  error?: string;
  onChange: (value: FormValue) => void;
}) {
  const label = entry.description?.trim() || entry.key;

  return (
    <div>
      <Label>{label}</Label>
      {entry.type === 'boolean' ? (
        <Select
          value={value ? 'true' : 'false'}
          error={error}
          onChange={(e) => onChange(e.target.value === 'true')}
        >
          <option value="true">Ya</option>
          <option value="false">Tidak</option>
        </Select>
      ) : entry.type === 'json' ? (
        <Textarea
          value={typeof value === 'string' ? value : ''}
          error={error}
          rows={4}
          className="font-mono text-xs"
          onChange={(e) => onChange(e.target.value)}
        />
      ) : entry.type === 'integer' || entry.type === 'decimal' ? (
        <Input
          type="number"
          step={entry.type === 'decimal' ? '0.01' : '1'}
          value={typeof value === 'string' ? value : ''}
          error={error}
          onChange={(e) => onChange(e.target.value)}
        />
      ) : (
        <Input
          value={typeof value === 'string' ? value : ''}
          error={error}
          onChange={(e) => onChange(e.target.value)}
        />
      )}
      <p className="mt-1 font-mono text-[11px] text-slate-400">
        {entry.key} · {SETTING_TYPE_LABELS[entry.type] ?? entry.type}
      </p>
      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  );
}

/**
 * Pengaturan perpustakaan.
 *
 * Tab "Umum" menyunting entitas perpustakaan (via `librariesApi.update`),
 * sedangkan tab lain menyunting konfigurasi key-value per grup.
 */
export function LibrarySettingsPage() {
  const queryClient = useQueryClient();

  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [tab, setTab] = useState<TabKey>('general');
  const [general, setGeneral] = useState<GeneralForm>(emptyGeneral);
  const [generalErrors, setGeneralErrors] = useState<Record<string, string>>({});
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [settingValues, setSettingValues] = useState<Record<string, FormValue>>({});

  const { options: libraryOptions, isLoading: optionsLoading } = useLibraryOptions();

  const libraryQuery = useQuery({
    queryKey: ['library', 'libraries', libraryId],
    enabled: libraryId !== '',
    queryFn: () => librariesApi.show(Number(libraryId)),
  });

  const settingsQuery = useQuery({
    queryKey: ['library', 'settings', libraryId],
    enabled: libraryId !== '',
    queryFn: () => librarySettingsApi.show(Number(libraryId)),
  });

  const library = libraryQuery.data;

  useEffect(() => {
    if (!library) return;
    setGeneral({
      name: library.name,
      code: library.code,
      description: library.description ?? '',
      phone: library.phone ?? '',
      email: library.email ?? '',
      address: library.address ?? '',
      timezone: library.timezone || 'Asia/Jakarta',
      logo_path: library.logo_path ?? '',
    });
    setGeneralErrors({});
  }, [library]);

  useEffect(() => {
    const grouped = settingsQuery.data;
    if (!grouped) return;
    const next: Record<string, FormValue> = {};
    Object.values(grouped)
      .flat()
      .forEach((entry) => {
        next[entry.key] = toFormValue(entry);
      });
    setSettingValues(next);
    setServerErrors({});
  }, [settingsQuery.data]);

  const grouped = settingsQuery.data ?? {};

  const unknownGroups = useMemo(
    () => Object.keys(grouped).filter((g) => !KNOWN_GROUPS.includes(g)),
    [grouped],
  );

  const entriesFor = (groups: string[]): LibrarySettingEntry[] =>
    groups.flatMap((g) => grouped[g] ?? []);

  const activeEntries = useMemo<LibrarySettingEntry[]>(() => {
    switch (tab) {
      case 'loan':
        return entriesFor(['loan', 'fine']);
      case 'catalog':
        return entriesFor(['catalog']);
      case 'member':
        return entriesFor(['member']);
      case 'others':
        return entriesFor(['reservation', ...unknownGroups]);
      default:
        return [];
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, grouped, unknownGroups]);

  const invalidateLibraries = () => {
    queryClient.invalidateQueries({ queryKey: ['library', 'libraries'] });
    queryClient.invalidateQueries({ queryKey: ['library', 'dashboard'] });
    queryClient.invalidateQueries({ queryKey: ['library', 'options'] });
  };

  const saveGeneralMutation = useMutation({
    mutationFn: (payload: LibraryPayload) => librariesApi.update(Number(libraryId), payload),
    onSuccess: () => {
      toast.success('Perpustakaan diperbarui');
      setGeneralErrors({});
      setServerErrors({});
      invalidateLibraries();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const saveSettingsMutation = useMutation({
    mutationFn: (values: Record<string, unknown>) =>
      librarySettingsApi.update(Number(libraryId), values),
    onSuccess: (res) => {
      toast.success(res.message);
      queryClient.invalidateQueries({ queryKey: ['library', 'settings', libraryId] });
      queryClient.invalidateQueries({ queryKey: ['library', 'dashboard'] });
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const handleGeneralSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!general.name.trim()) local.name = 'Nama perpustakaan wajib diisi';
    if (!general.code.trim()) local.code = 'Kode perpustakaan wajib diisi';
    if (!general.timezone.trim()) local.timezone = 'Zona waktu wajib diisi';
    if (Object.keys(local).length) {
      setGeneralErrors(local);
      return;
    }

    saveGeneralMutation.mutate({
      name: general.name,
      code: general.code,
      description: general.description,
      phone: general.phone,
      email: general.email,
      address: general.address,
      timezone: general.timezone,
      logo_path: general.logo_path,
    });
  };

  const handleSettingsSave = () => {
    const payload: Record<string, unknown> = {};
    for (const entry of activeEntries) {
      const value = settingValues[entry.key];
      if (entry.type === 'boolean') {
        payload[entry.key] = !!value;
        continue;
      }
      const text = typeof value === 'string' ? value.trim() : '';
      if (text === '') continue;
      if (entry.type === 'integer' || entry.type === 'decimal') {
        payload[entry.key] = Number(text);
      } else if (entry.type === 'json') {
        try {
          payload[entry.key] = JSON.parse(text);
        } catch {
          toast.error(`JSON tidak valid untuk "${entry.key}"`);
          return;
        }
      } else {
        payload[entry.key] = text;
      }
    }
    saveSettingsMutation.mutate(payload);
  };

  const codeLocked = !!library && library.status !== 'draft';

  const err = (key: string) => generalErrors[key] || serverErrors?.[key]?.[0];

  const settingError = (entry: LibrarySettingEntry) =>
    serverErrors?.[entry.key]?.[0] ?? serverErrors?.[`settings.${entry.key}`]?.[0];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Pengaturan Perpustakaan</h1>
          <p className="text-sm text-slate-500">
            Data dasar perpustakaan dan konfigurasi operasional per grup.
          </p>
        </div>
        <div className="w-full sm:w-72">
          <SearchSelect
            options={libraryOptions}
            value={libraryId}
            disabled={optionsLoading}
            onChange={(v) => {
              setLibraryId(v === '' ? '' : Number(v));
              setTab('general');
            }}
            placeholder="-- Pilih perpustakaan --"
          />
        </div>
      </div>

      {libraryId === '' ? (
        <Card>
          <EmptyState
            title="Pilih perpustakaan"
            description="Pilih perpustakaan untuk mengatur data dasar dan konfigurasinya."
          />
        </Card>
      ) : libraryQuery.isLoading || settingsQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat pengaturan...
          </div>
        </Card>
      ) : libraryQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat perpustakaan"
            description={getErrorMessage(libraryQuery.error)}
            action={
              <Button
                size="sm"
                variant="outline"
                className="mt-2"
                onClick={() => libraryQuery.refetch()}
              >
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : (
        <div className="space-y-4">
          <div className="flex flex-wrap gap-1 border-b border-slate-200">
            {TABS.map((t) => (
              <button
                key={t.key}
                type="button"
                onClick={() => setTab(t.key)}
                className={cn(
                  '-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors',
                  tab === t.key
                    ? 'border-brand-600 text-brand-700'
                    : 'border-transparent text-slate-500 hover:text-slate-700',
                )}
              >
                {t.label}
              </button>
            ))}
          </div>

          {tab === 'general' ? (
            <Card className="p-5">
              <form onSubmit={handleGeneralSubmit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div>
                    <Label required>Nama Perpustakaan</Label>
                    <Input
                      value={general.name}
                      error={err('name')}
                      onChange={(e) => setGeneral((p) => ({ ...p, name: e.target.value }))}
                    />
                  </div>
                  <div>
                    <Label required>Kode</Label>
                    <Input
                      value={general.code}
                      error={err('code')}
                      disabled={codeLocked}
                      onChange={(e) => setGeneral((p) => ({ ...p, code: e.target.value }))}
                    />
                    {codeLocked && (
                      <p className="mt-1 text-xs text-slate-500">
                        Kode hanya dapat diubah saat status masih draf.
                      </p>
                    )}
                  </div>

                  <div>
                    <Label required>Zona Waktu</Label>
                    <Input
                      list="library-settings-timezones"
                      value={general.timezone}
                      error={err('timezone')}
                      onChange={(e) => setGeneral((p) => ({ ...p, timezone: e.target.value }))}
                    />
                    <datalist id="library-settings-timezones">
                      {LIBRARY_TIMEZONES.map((tz) => (
                        <option key={tz} value={tz} />
                      ))}
                    </datalist>
                  </div>
                  <div>
                    <Label>Telepon</Label>
                    <Input
                      value={general.phone}
                      error={err('phone')}
                      onChange={(e) => setGeneral((p) => ({ ...p, phone: e.target.value }))}
                    />
                  </div>

                  <div>
                    <Label>Email</Label>
                    <Input
                      type="email"
                      value={general.email}
                      error={err('email')}
                      onChange={(e) => setGeneral((p) => ({ ...p, email: e.target.value }))}
                    />
                  </div>
                  <div>
                    <Label>Logo (path)</Label>
                    <Input
                      value={general.logo_path}
                      error={err('logo_path')}
                      placeholder="Opsional"
                      onChange={(e) => setGeneral((p) => ({ ...p, logo_path: e.target.value }))}
                    />
                  </div>

                  <div className="sm:col-span-2">
                    <Label>Deskripsi</Label>
                    <Textarea
                      value={general.description}
                      error={err('description')}
                      onChange={(e) => setGeneral((p) => ({ ...p, description: e.target.value }))}
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <Label>Alamat</Label>
                    <Textarea
                      value={general.address}
                      error={err('address')}
                      onChange={(e) => setGeneral((p) => ({ ...p, address: e.target.value }))}
                    />
                  </div>
                </div>

                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2 text-xs text-slate-500">
                    Status saat ini:{' '}
                    {library && (
                      <Badge tone={library.status === 'active' ? 'green' : 'blue'}>
                        {LIBRARY_STATUS_LABELS[library.status] ?? library.status}
                      </Badge>
                    )}
                  </div>
                  <Can
                    perm="library.update"
                    fallback={
                      <span className="text-xs text-slate-400">
                        Tidak berwenang menyimpan perubahan.
                      </span>
                    }
                  >
                    <Button type="submit" loading={saveGeneralMutation.isPending}>
                      <Save className="h-4 w-4" /> Simpan
                    </Button>
                  </Can>
                </div>
              </form>
            </Card>
          ) : (
            <Card className="p-5">
              {settingsQuery.isError ? (
                <EmptyState
                  title="Gagal memuat konfigurasi"
                  description={getErrorMessage(settingsQuery.error)}
                />
              ) : activeEntries.length === 0 ? (
                <EmptyState
                  title="Belum ada konfigurasi"
                  description={`Tidak ada pengaturan pada grup "${SETTING_GROUP_LABELS[tab] ?? tab}".`}
                />
              ) : (
                <div className="space-y-4">
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {activeEntries.map((entry) => (
                      <SettingField
                        key={entry.key}
                        entry={entry}
                        value={settingValues[entry.key] ?? ''}
                        error={settingError(entry)}
                        onChange={(v) =>
                          setSettingValues((prev) => ({ ...prev, [entry.key]: v }))
                        }
                      />
                    ))}
                  </div>

                  <div className="flex justify-end">
                    <Can
                      perm="library.settings.update"
                      fallback={
                        <span className="text-xs text-slate-400">
                          Tidak berwenang menyimpan konfigurasi.
                        </span>
                      }
                    >
                      <Button
                        onClick={handleSettingsSave}
                        loading={saveSettingsMutation.isPending}
                      >
                        <Save className="h-4 w-4" /> Simpan
                      </Button>
                    </Can>
                  </div>
                </div>
              )}
            </Card>
          )}
        </div>
      )}
    </div>
  );
}