import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Printer, RefreshCw } from 'lucide-react';
import { reportsApi } from '../api/tahfizApi';
import type { TahfizPeriodParams } from '../api/tahfizApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { FieldRow, ProgressBar, SectionTitle, StatCard } from '../components/TahfizUi';
import {
  useHalaqahOptions,
  useParticipantOptions,
  useProgramOptions,
} from '../hooks/useTahfizOptions';
import {
  ENROLLMENT_STATUS_LABELS,
  INTERVENTION_STATUS_LABELS,
  PARTICIPANT_TYPE_LABELS,
  RAPOR_PREDICATE_TONE,
  enrollmentStatusTone,
  interventionStatusTone,
} from '../lib/labels';
import type {
  TahfizHalaqahReport,
  TahfizIndividualReport,
  TahfizIntervention,
  TahfizMissingReport,
  TahfizParticipantStatus,
  TahfizProgramReport,
  TahfizRapor,
  TahfizSetoranStats,
  TahfizTeacherReport,
} from '@/types/tahfiz';

type ReportTab = 'individual' | 'halaqah' | 'program' | 'teacher' | 'rapor';

type ReportData =
  | TahfizIndividualReport
  | TahfizHalaqahReport
  | TahfizProgramReport
  | TahfizTeacherReport
  | TahfizRapor
  | TahfizMissingReport;

const TABS: { key: ReportTab; label: string }[] = [
  { key: 'individual', label: 'Individual' },
  { key: 'halaqah', label: 'Halaqah' },
  { key: 'program', label: 'Program' },
  { key: 'teacher', label: 'Guru' },
  { key: 'rapor', label: 'Rapor' },
];

const participantStatusTone = (status: TahfizParticipantStatus): 'green' | 'gray' | 'red' => {
  if (status === 'active') return 'green';
  if (status === 'blacklisted') return 'red';
  return 'gray';
};

/* ============================ BAGIAN BERSAMA ============================ */

function SetoranStats({ stats }: { stats: TahfizSetoranStats }) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      <StatCard label="Total Setoran" value={stats.total} />
      <StatCard label="Diterima" value={stats.accepted} tone="green" />
      <StatCard label="Menunggu" value={stats.pending} tone="amber" />
      <StatCard label="Ditolak" value={stats.rejected} tone="red" />
      <StatCard label="Rata-rata Nilai" value={stats.avg_score ?? '—'} />
      <StatCard label="Total Ayat" value={stats.ayahs} />
    </div>
  );
}

function ProgressPair({ memorized, mutqin }: { memorized: number; mutqin: number }) {
  return (
    <div className="mt-3 space-y-3">
      <div>
        <div className="mb-1 flex items-center justify-between text-xs text-slate-500">
          <span>Sudah Dihafal</span>
          <span className="font-medium text-slate-700">{memorized}%</span>
        </div>
        <ProgressBar percent={memorized} tone="brand" />
      </div>
      <div>
        <div className="mb-1 flex items-center justify-between text-xs text-slate-500">
          <span>Mutqin</span>
          <span className="font-medium text-slate-700">{mutqin}%</span>
        </div>
        <ProgressBar percent={mutqin} tone="green" />
      </div>
    </div>
  );
}

function InterventionList({ rows }: { rows: TahfizIntervention[] }) {
  if (rows.length === 0) {
    return <p className="text-sm text-slate-400">Tidak ada intervensi pada periode ini.</p>;
  }
  return (
    <ul className="divide-y divide-slate-50">
      {rows.map((row) => (
        <li key={row.id} className="flex items-start justify-between gap-3 py-2">
          <div className="min-w-0">
            <p className="text-sm font-medium text-slate-800">{row.type_label}</p>
            <p className="truncate text-xs text-slate-500">{row.reason}</p>
            {row.result && <p className="text-xs text-slate-400">Hasil: {row.result}</p>}
          </div>
          <div className="shrink-0 text-right">
            <Badge tone={interventionStatusTone(row.status)}>
              {INTERVENTION_STATUS_LABELS[row.status]}
            </Badge>
            {row.due_date && <p className="mt-1 text-xs text-slate-400">{row.due_date}</p>}
          </div>
        </li>
      ))}
    </ul>
  );
}

/* ============================ LAPORAN INDIVIDUAL ============================ */

function IndividualReportView({ report }: { report: TahfizIndividualReport }) {
  const patterns = [...report.mistake_patterns].sort((a, b) => b.occurrences - a.occurrences);
  const maxOccurrence = patterns.reduce((m, p) => Math.max(m, p.occurrences), 0) || 1;

  return (
    <div className="space-y-4">
      <Card className="p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-base font-semibold text-slate-900">{report.participant.name}</h2>
            <p className="text-xs text-slate-500">
              {PARTICIPANT_TYPE_LABELS[report.participant.participant_type]}
            </p>
          </div>
          <Badge tone={participantStatusTone(report.participant.status)}>
            {report.participant.status === 'active'
              ? 'Aktif'
              : report.participant.status === 'blacklisted'
                ? 'Blacklist'
                : 'Tidak Aktif'}
          </Badge>
        </div>
        {report.enrollment && (
          <div className="mt-3">
            <FieldRow label="Kode Pendaftaran">{report.enrollment.code}</FieldRow>
            <FieldRow label="Program">{report.enrollment.program?.name ?? '—'}</FieldRow>
            <FieldRow label="Halaqah">{report.enrollment.halaqah?.name ?? '—'}</FieldRow>
            <FieldRow label="Status">
              <Badge tone={enrollmentStatusTone(report.enrollment.status)}>
                {ENROLLMENT_STATUS_LABELS[report.enrollment.status]}
              </Badge>
            </FieldRow>
          </div>
        )}
      </Card>

      <Card className="p-4">
        <SectionTitle>Progress Hafalan</SectionTitle>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <StatCard label="Ayat Dihafal" value={report.progress.ayahs_memorized} />
          <StatCard label="Ayat Mutqin" value={report.progress.ayahs_mutqin} tone="green" />
          <StatCard label="Juz Khatam" value={report.progress.juz_memorized} tone="brand" />
          <StatCard label="Surah Dihafal" value={report.progress.surahs_memorized} />
          <StatCard label="Halaman" value={report.progress.pages_memorized} />
          <StatCard label="Mutqin (%)" value={`${report.progress.mutqin_percent}%`} tone="green" />
        </div>
        <ProgressPair
          memorized={report.progress.memorized_percent}
          mutqin={report.progress.mutqin_percent}
        />
      </Card>

      <Card className="p-4">
        <SectionTitle>Statistik Setoran</SectionTitle>
        <SetoranStats stats={report.setoran} />
      </Card>

      <Card className="p-4">
        <SectionTitle>Pola Kesalahan (Taksonomi)</SectionTitle>
        {patterns.length === 0 ? (
          <p className="text-sm text-slate-400">Tidak ada kesalahan tercatat.</p>
        ) : (
          <div className="space-y-2">
            {patterns.map((p) => (
              <div key={String(p.key)}>
                <div className="mb-1 flex items-center justify-between text-xs">
                  <span className="font-medium text-slate-700">{p.label}</span>
                  <span className="text-slate-500">
                    {p.count} jenis · {p.occurrences} kejadian
                  </span>
                </div>
                <ProgressBar percent={(p.occurrences / maxOccurrence) * 100} tone="red" />
              </div>
            ))}
          </div>
        )}
      </Card>

      <Card className="p-4">
        <SectionTitle>Aktivitas & Kehadiran</SectionTitle>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <StatCard
            label="Murojaah"
            value={report.murojaah.total}
            hint={`Rata-rata ${report.murojaah.avg_score ?? '—'}`}
          />
          <StatCard
            label="Tilawah"
            value={report.tilawah.total}
            hint={`Rata-rata ${report.tilawah.avg_score ?? '—'}`}
          />
          <StatCard
            label="Tasmi'"
            value={report.tasmi.total}
            hint={`Rata-rata ${report.tasmi.avg_score ?? '—'}`}
          />
          <StatCard
            label="Tahsin"
            value={report.tahsin.total}
            hint={`Rata-rata ${report.tahsin.avg_score ?? '—'}`}
          />
        </div>
        <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <StatCard label="Total Sesi" value={report.attendance.total_sessions} />
          <StatCard label="Hadir" value={report.attendance.present} tone="green" />
          <StatCard label="Kehadiran" value={`${report.attendance.percent}%`} tone="brand" />
        </div>
        {report.attendance.total_sessions > 0 && (
          <div className="mt-3">
            <ProgressBar percent={report.attendance.percent} tone="green" />
          </div>
        )}
      </Card>

      <Card className="p-4">
        <SectionTitle>Ujian</SectionTitle>
        {report.exams.length === 0 ? (
          <p className="text-sm text-slate-400">Belum ada ujian pada periode ini.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-3 py-2 font-medium">Ujian</th>
                  <th className="px-3 py-2 font-medium">Nilai</th>
                  <th className="px-3 py-2 font-medium">Grade</th>
                  <th className="px-3 py-2 font-medium">Hasil</th>
                </tr>
              </thead>
              <tbody>
                {report.exams.map((ex) => (
                  <tr key={ex.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-3 py-2 text-slate-700">
                      {ex.exam?.name ?? `Ujian #${ex.exam_id}`}
                    </td>
                    <td className="px-3 py-2 text-slate-700">{ex.score ?? '—'}</td>
                    <td className="px-3 py-2 text-slate-700">{ex.grade ?? '—'}</td>
                    <td className="px-3 py-2 text-slate-700">{ex.result_label ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card className="p-4">
        <SectionTitle>Intervensi</SectionTitle>
        <InterventionList rows={report.interventions} />
      </Card>
    </div>
  );
}

/* ============================ LAPORAN HALAQAH ============================ */

function HalaqahReportView({ report }: { report: TahfizHalaqahReport }) {
  return (
    <div className="space-y-4">
      <Card className="p-4">
        <h2 className="text-base font-semibold text-slate-900">{report.halaqah.name}</h2>
        <div className="mt-2">
          <FieldRow label="Kode">{report.halaqah.code}</FieldRow>
          <FieldRow label="Program">{report.halaqah.program?.name ?? '—'}</FieldRow>
          <FieldRow label="Level">{report.halaqah.level?.name ?? '—'}</FieldRow>
          <FieldRow label="Instruktur">{report.halaqah.instructor_name ?? '—'}</FieldRow>
          <FieldRow label="Musyrif">{report.halaqah.musyrif_name ?? '—'}</FieldRow>
          <FieldRow label="Kapasitas">{report.halaqah.capacity ?? '—'}</FieldRow>
        </div>
      </Card>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <StatCard label="Jumlah Anggota" value={report.members_count} tone="brand" />
        <StatCard label="Total Setoran" value={report.setoran.total} />
      </div>

      <Card className="p-4">
        <SectionTitle>Statistik Setoran</SectionTitle>
        <SetoranStats stats={report.setoran} />
      </Card>

      <Card>
        <div className="border-b border-slate-100 px-4 py-3">
          <SectionTitle>Daftar Peserta</SectionTitle>
        </div>
        {report.participants.length === 0 ? (
          <EmptyState title="Belum ada peserta" description="Halaqah ini belum memiliki anggota." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Dihafal</th>
                  <th className="px-4 py-2 font-medium">Mutqin</th>
                </tr>
              </thead>
              <tbody>
                {report.participants.map((p) => (
                  <tr key={p.enrollment_id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 font-medium text-slate-800">{p.name ?? '—'}</td>
                    <td className="px-4 py-2">
                      <Badge tone={enrollmentStatusTone(p.status)}>
                        {ENROLLMENT_STATUS_LABELS[p.status]}
                      </Badge>
                    </td>
                    <td className="px-4 py-2">
                      <div className="w-40">
                        <div className="mb-1 text-xs text-slate-500">
                          {p.progress.memorized_percent}% · {p.progress.ayahs_memorized} ayat
                        </div>
                        <ProgressBar percent={p.progress.memorized_percent} tone="brand" />
                      </div>
                    </td>
                    <td className="px-4 py-2">
                      <div className="w-40">
                        <div className="mb-1 text-xs text-slate-500">
                          {p.progress.mutqin_percent}% · {p.progress.ayahs_mutqin} ayat
                        </div>
                        <ProgressBar percent={p.progress.mutqin_percent} tone="green" />
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

/* ============================ LAPORAN PROGRAM ============================ */

function ProgramReportView({ report }: { report: TahfizProgramReport }) {
  return (
    <div className="space-y-4">
      <Card className="p-4">
        <h2 className="text-base font-semibold text-slate-900">{report.program.name}</h2>
        <div className="mt-2">
          <FieldRow label="Kode">{report.program.code}</FieldRow>
          <FieldRow label="Status">{report.program.status}</FieldRow>
          <FieldRow label="Tipe Peserta">
            {report.program.participant_type
              ? PARTICIPANT_TYPE_LABELS[report.program.participant_type]
              : '—'}
          </FieldRow>
        </div>
      </Card>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label="Pendaftaran" value={report.counts.enrollments} tone="brand" />
        <StatCard label="Halaqah" value={report.counts.halaqahs} />
        <StatCard label="Level" value={report.counts.levels} />
      </div>

      <Card className="p-4">
        <SectionTitle>Sebaran Status Pendaftaran</SectionTitle>
        {Object.keys(report.enrollment_breakdown).length === 0 ? (
          <p className="text-sm text-slate-400">Belum ada pendaftaran.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {Object.entries(report.enrollment_breakdown).map(([status, count]) => (
              <Badge key={status} tone="gray">
                {ENROLLMENT_STATUS_LABELS[status as keyof typeof ENROLLMENT_STATUS_LABELS] ?? status}
                : {count}
              </Badge>
            ))}
          </div>
        )}
      </Card>

      <Card>
        <div className="border-b border-slate-100 px-4 py-3">
          <SectionTitle>Halaqah</SectionTitle>
        </div>
        {report.halaqahs.length === 0 ? (
          <EmptyState title="Belum ada halaqah" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 text-center font-medium">Anggota Aktif</th>
                  <th className="px-4 py-2 text-center font-medium">Kapasitas</th>
                </tr>
              </thead>
              <tbody>
                {report.halaqahs.map((h) => (
                  <tr key={h.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{h.code}</td>
                    <td className="px-4 py-2 font-medium text-slate-800">{h.name}</td>
                    <td className="px-4 py-2 text-center text-slate-700">{h.active_members}</td>
                    <td className="px-4 py-2 text-center text-slate-700">{h.capacity ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card className="p-4">
        <SectionTitle>Statistik Setoran</SectionTitle>
        <SetoranStats stats={report.setoran} />
      </Card>

      <Card className="p-4">
        <SectionTitle>Rata-rata Progress</SectionTitle>
        <ProgressPair
          memorized={report.avg_progress.avg_memorized_percent}
          mutqin={report.avg_progress.avg_mutqin_percent}
        />
      </Card>
    </div>
  );
}

/* ============================ LAPORAN GURU ============================ */

function TeacherReportView({ report }: { report: TahfizTeacherReport }) {
  return (
    <div className="space-y-4">
      <Card className="p-4">
        <h2 className="text-base font-semibold text-slate-900">{report.teacher.name}</h2>
        <p className="text-xs text-slate-500">Laporan pengajaran guru</p>
      </Card>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label="Peserta" value={report.participants_count} tone="brand" />
        <StatCard label="Setoran Dinilai" value={report.setoran_reviewed} tone="green" />
        <StatCard label="Total Setoran" value={report.setoran.total} />
      </div>

      <Card>
        <div className="border-b border-slate-100 px-4 py-3">
          <SectionTitle>Halaqah Diampu</SectionTitle>
        </div>
        {report.halaqahs.length === 0 ? (
          <EmptyState title="Belum ada halaqah" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 font-medium">Peran</th>
                  <th className="px-4 py-2 text-center font-medium">Anggota Aktif</th>
                </tr>
              </thead>
              <tbody>
                {report.halaqahs.map((h) => (
                  <tr key={h.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{h.code}</td>
                    <td className="px-4 py-2 font-medium text-slate-800">{h.name}</td>
                    <td className="px-4 py-2 text-slate-600">{h.role}</td>
                    <td className="px-4 py-2 text-center text-slate-700">{h.active_members}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card className="p-4">
        <SectionTitle>Statistik Setoran</SectionTitle>
        <SetoranStats stats={report.setoran} />
      </Card>

      <Card className="p-4">
        <SectionTitle>Rata-rata Progress</SectionTitle>
        <ProgressPair
          memorized={report.avg_progress.avg_memorized_percent}
          mutqin={report.avg_progress.avg_mutqin_percent}
        />
      </Card>
    </div>
  );
}

/* ============================ RAPOR ============================ */

function RaporView({ report }: { report: TahfizRapor }) {
  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button variant="outline" onClick={() => window.print()}>
          <Printer className="h-4 w-4" /> Cetak
        </Button>
      </div>

      <Card className="p-6">
        <div className="border-b border-slate-200 pb-4 text-center">
          <h2 className="text-lg font-semibold uppercase text-slate-900">
            Rapor Tahfiz / Al-Qur'an
          </h2>
          <p className="mt-1 text-xs text-slate-500">
            Periode {report.period.from ?? '—'} s.d. {report.period.to ?? '—'}
          </p>
        </div>

        <div className="mt-4 grid grid-cols-1 gap-x-8 gap-y-0 sm:grid-cols-2">
          <FieldRow label="Nama">{report.participant.name}</FieldRow>
          <FieldRow label="Tipe">
            {PARTICIPANT_TYPE_LABELS[report.participant.participant_type]}
          </FieldRow>
          <FieldRow label="Program">{report.enrollment?.program?.name ?? '—'}</FieldRow>
          <FieldRow label="Halaqah">{report.enrollment?.halaqah?.name ?? '—'}</FieldRow>
        </div>
      </Card>

      <Card className="p-4">
        <SectionTitle>Hafalan</SectionTitle>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <StatCard label="Ayat Dihafal" value={report.hafalan.ayahs_memorized} />
          <StatCard label="Ayat Mutqin" value={report.hafalan.ayahs_mutqin} tone="green" />
          <StatCard label="Juz Selesai" value={report.hafalan.juz_completed} tone="brand" />
          <StatCard label="Surah" value={report.hafalan.surahs_memorized} />
          <StatCard label="Dihafal (%)" value={`${report.hafalan.memorized_percent}%`} />
          <StatCard label="Mutqin (%)" value={`${report.hafalan.mutqin_percent}%`} tone="green" />
        </div>
        <ProgressPair
          memorized={report.hafalan.memorized_percent}
          mutqin={report.hafalan.mutqin_percent}
        />
      </Card>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Card className="p-4">
          <SectionTitle>Setoran</SectionTitle>
          <FieldRow label="Total Setoran">{report.setoran.total}</FieldRow>
          <FieldRow label="Diterima">{report.setoran.accepted}</FieldRow>
          <FieldRow label="Rata-rata Nilai">{report.setoran.avg_score ?? '—'}</FieldRow>
        </Card>
        <Card className="p-4">
          <SectionTitle>Kehadiran & Mutabaah</SectionTitle>
          <FieldRow label="Total Sesi">{report.attendance.total_sessions}</FieldRow>
          <FieldRow label="Hadir">{report.attendance.present}</FieldRow>
          <FieldRow label="Persentase">{report.attendance.percent}%</FieldRow>
          <FieldRow label="Hari Mutabaah">{report.mutabaah_days}</FieldRow>
          <FieldRow label="Murojaah">
            {report.murojaah.total} (rata-rata {report.murojaah.avg_score ?? '—'})
          </FieldRow>
        </Card>
      </div>

      <Card className="p-4">
        <SectionTitle>Pencapaian Target</SectionTitle>
        {report.target.value === null ? (
          <p className="text-sm text-slate-400">Belum ada target pada periode ini.</p>
        ) : (
          <div>
            <div className="mb-1 flex items-center justify-between text-sm">
              <span className="text-slate-600">
                Target {report.target.value} ({report.target.period ?? '—'})
              </span>
              <Badge tone={report.target.is_achieved ? 'green' : 'amber'}>
                {report.target.is_achieved ? 'Tercapai' : 'Belum Tercapai'}
              </Badge>
            </div>
            <ProgressBar
              percent={report.target.achievement_percent ?? 0}
              tone={report.target.is_achieved ? 'green' : 'amber'}
            />
            <p className="mt-1 text-xs text-slate-500">
              Capaian {report.target.achievement_percent ?? 0}%
            </p>
          </div>
        )}
      </Card>

      <Card className="p-4">
        <SectionTitle>Ujian</SectionTitle>
        {report.exams.length === 0 ? (
          <p className="text-sm text-slate-400">Belum ada ujian.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-3 py-2 font-medium">Ujian</th>
                  <th className="px-3 py-2 font-medium">Nilai</th>
                  <th className="px-3 py-2 font-medium">Grade</th>
                  <th className="px-3 py-2 font-medium">Hasil</th>
                </tr>
              </thead>
              <tbody>
                {report.exams.map((ex) => (
                  <tr key={ex.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-3 py-2 text-slate-700">
                      {ex.exam?.name ?? `Ujian #${ex.exam_id}`}
                    </td>
                    <td className="px-3 py-2 text-slate-700">{ex.score ?? '—'}</td>
                    <td className="px-3 py-2 text-slate-700">{ex.grade ?? '—'}</td>
                    <td className="px-3 py-2 text-slate-700">{ex.result_label ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card className="p-4">
        <SectionTitle>Intervensi</SectionTitle>
        <InterventionList rows={report.interventions} />
      </Card>

      <Card className="p-4">
        <SectionTitle>Catatan & Predikat</SectionTitle>
        <div className="mb-3">
          <Badge tone={RAPOR_PREDICATE_TONE[report.notes.predicate] ?? 'gray'}>
            {report.notes.predicate_label}
          </Badge>
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-green-600">
              Kelebihan
            </p>
            {report.notes.strengths.length === 0 ? (
              <p className="text-sm text-slate-400">—</p>
            ) : (
              <ul className="list-disc space-y-1 pl-4 text-sm text-slate-600">
                {report.notes.strengths.map((s, i) => (
                  <li key={i}>{s}</li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-amber-600">
              Perlu Perbaikan
            </p>
            {report.notes.improvements.length === 0 ? (
              <p className="text-sm text-slate-400">—</p>
            ) : (
              <ul className="list-disc space-y-1 pl-4 text-sm text-slate-600">
                {report.notes.improvements.map((s, i) => (
                  <li key={i}>{s}</li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </Card>
    </div>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Laporan & rapor Tahfiz.
 *
 * Laporan dihitung ulang di server dari data mentah; entitas yang tidak
 * ditemukan mengembalikan `{ exists: false }` dan ditampilkan sebagai
 * EmptyState.
 */
export function TahfizReportsPage() {
  const [tab, setTab] = useState<ReportTab>('individual');
  const [entityId, setEntityId] = useState<number | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const { options: participantOptions } = useParticipantOptions();
  const { options: halaqahOptions } = useHalaqahOptions();
  const { options: programOptions } = useProgramOptions();
  const { options: employeeOptions } = useEmployeeOptions(false);

  const selectorOptions =
    tab === 'individual' || tab === 'rapor'
      ? participantOptions
      : tab === 'halaqah'
        ? halaqahOptions
        : tab === 'program'
          ? programOptions
          : employeeOptions;

  const reportQuery = useQuery<ReportData>({
    queryKey: ['tahfiz', 'reports', tab, entityId, from, to],
    enabled: entityId !== '',
    queryFn: async () => {
      const params: TahfizPeriodParams = {};
      if (from) params.from = from;
      if (to) params.to = to;
      const id = Number(entityId);
      if (tab === 'individual') return reportsApi.individual(id, params);
      if (tab === 'halaqah') return reportsApi.halaqah(id, params);
      if (tab === 'program') return reportsApi.program(id, params);
      if (tab === 'teacher') return reportsApi.teacher(id, params);
      return reportsApi.rapor(id, params);
    },
  });

  const report = reportQuery.data;
  const missing = !!report && (report as TahfizMissingReport).exists === false;

  const changeTab = (next: ReportTab) => {
    setTab(next);
    setEntityId('');
  };

  return (
    <Can
      perm="tahfiz.report"
      fallback={
        <Card>
          <EmptyState
            title="Akses ditolak"
            description="Anda tidak memiliki izin untuk melihat laporan Tahfiz."
          />
        </Card>
      }
    >
      <div className="space-y-4">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Laporan & Rapor Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Laporan dihitung ulang dari data mentah. Pilih entitas dan rentang tanggal opsional.
          </p>
        </div>

        <div className="flex flex-wrap gap-1 border-b border-slate-200">
          {TABS.map((t) => (
            <button
              key={t.key}
              type="button"
              onClick={() => changeTab(t.key)}
              className={
                tab === t.key
                  ? 'border-b-2 border-brand-600 px-4 py-2 text-sm font-medium text-brand-700'
                  : 'border-b-2 border-transparent px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-700'
              }
            >
              {t.label}
            </button>
          ))}
        </div>

        <Card className="p-3">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600">Entitas</label>
              <SearchSelect
                options={selectorOptions}
                value={entityId}
                onChange={(v) => setEntityId(v === '' ? '' : Number(v))}
                placeholder="-- Pilih --"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600">Dari Tanggal</label>
              <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600">Sampai Tanggal</label>
              <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </div>
            <div className="flex items-end">
              <Button
                variant="ghost"
                size="icon"
                title="Muat ulang"
                onClick={() => reportQuery.refetch()}
              >
                <RefreshCw className={reportQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
              </Button>
            </div>
          </div>
        </Card>

        {entityId === '' ? (
          <Card>
            <EmptyState
              title="Pilih entitas"
              description="Pilih salah satu entitas untuk menampilkan laporan."
            />
          </Card>
        ) : reportQuery.isLoading ? (
          <Card>
            <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
              <Spinner /> Memuat laporan...
            </div>
          </Card>
        ) : reportQuery.isError ? (
          <Card>
            <EmptyState
              title="Gagal memuat laporan"
              description={reportQuery.error instanceof Error ? reportQuery.error.message : undefined}
              action={
                <Button size="sm" variant="outline" className="mt-2" onClick={() => reportQuery.refetch()}>
                  Coba lagi
                </Button>
              }
            />
          </Card>
        ) : missing || !report ? (
          <Card>
            <EmptyState
              title="Data tidak ditemukan"
              description="Entitas yang dipilih tidak memiliki laporan pada periode ini."
            />
          </Card>
        ) : (
          <>
            {tab === 'individual' && <IndividualReportView report={report as TahfizIndividualReport} />}
            {tab === 'halaqah' && <HalaqahReportView report={report as TahfizHalaqahReport} />}
            {tab === 'program' && <ProgramReportView report={report as TahfizProgramReport} />}
            {tab === 'teacher' && <TeacherReportView report={report as TahfizTeacherReport} />}
            {tab === 'rapor' && <RaporView report={report as TahfizRapor} />}
          </>
        )}
      </div>
    </Can>
  );
}