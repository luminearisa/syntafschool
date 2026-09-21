import { Badge } from '@/components/ui/Badge';
import { Select } from '@/components/ui/Select';
import { PpdbField } from './PpdbConfigUi';
import { usePpdbProgramOptions, usePpdbWaveOptions } from '../../hooks/usePpdbScopeOptions';

/**
 * Dua pemilih cakupan (program & gelombang) dengan opsi "semua".
 *
 * `program_id`/`wave_id` nullable berarti "berlaku untuk semua" — karena itu
 * opsi kosong wajib ada. Di payload dikirim `''` supaya `ConvertEmptyStringsToNull`
 * backend mengubahnya menjadi `null`.
 */
function ScopeSelects({
  periodId,
  programId,
  waveId,
  onProgramChange,
  onWaveChange,
  programError,
  waveError,
  programLabel = 'Program',
  waveLabel = 'Gelombang',
  programHint = 'Kosong = berlaku untuk semua program.',
  waveHint = 'Kosong = berlaku untuk semua gelombang.',
}: {
  periodId: number | '';
  programId: number | '';
  waveId: number | '';
  onProgramChange: (value: number | '') => void;
  onWaveChange: (value: number | '') => void;
  programError?: string;
  waveError?: string;
  programLabel?: string;
  waveLabel?: string;
  programHint?: string;
  waveHint?: string;
}) {
  const programs = usePpdbProgramOptions(periodId);
  const waves = usePpdbWaveOptions(periodId);

  return (
    <>
      <PpdbField label={programLabel} error={programError} hint={programHint}>
        <Select
          value={String(programId)}
          disabled={periodId === ''}
          error={programError}
          onChange={(e) => onProgramChange(e.target.value === '' ? '' : Number(e.target.value))}
        >
          <option value="">Semua program</option>
          {programs.options.map((option) => (
            <option key={String(option.value)} value={String(option.value)}>
              {option.label}
            </option>
          ))}
        </Select>
      </PpdbField>
      <PpdbField label={waveLabel} error={waveError} hint={waveHint}>
        <Select
          value={String(waveId)}
          disabled={periodId === ''}
          error={waveError}
          onChange={(e) => onWaveChange(e.target.value === '' ? '' : Number(e.target.value))}
        >
          <option value="">Semua gelombang</option>
          {waves.options.map((option) => (
            <option key={String(option.value)} value={String(option.value)}>
              {option.label}
            </option>
          ))}
        </Select>
      </PpdbField>
    </>
  );
}

/** Versi form: nilai boleh kosong artinya "berlaku untuk semua". */
export function PpdbScopeControls(props: {
  periodId: number | '';
  programId: number | '';
  waveId: number | '';
  onProgramChange: (value: number | '') => void;
  onWaveChange: (value: number | '') => void;
  programError?: string;
  waveError?: string;
}) {
  return <ScopeSelects {...props} />;
}

/** Versi kartu filter: kosong artinya jangan batasi hasilnya. */
export function PpdbScopeFilters(props: {
  periodId: number | '';
  programId: number | '';
  waveId: number | '';
  onProgramChange: (value: number | '') => void;
  onWaveChange: (value: number | '') => void;
}) {
  return (
    <ScopeSelects
      {...props}
      programLabel="Filter program"
      waveLabel="Filter gelombang"
      programHint="Hanya baris untuk program ini."
      waveHint="Hanya baris untuk gelombang ini."
    />
  );
}

/** Cakupan satu baris: nama program/gelombang atau tanda "berlaku untuk semua". */
export function PpdbScopeBadge({
  programId,
  programName,
  waveId,
  waveName,
}: {
  programId: number | null | undefined;
  programName?: string | null;
  waveId: number | null | undefined;
  waveName?: string | null;
}) {
  return (
    <div className="flex flex-wrap gap-1">
      <Badge tone={programId ? 'brand' : 'gray'}>
        {programId ? programName ?? 'Program terpilih' : 'Semua program'}
      </Badge>
      <Badge tone={waveId ? 'blue' : 'gray'}>
        {waveId ? waveName ?? 'Gelombang terpilih' : 'Semua gelombang'}
      </Badge>
    </div>
  );
}
