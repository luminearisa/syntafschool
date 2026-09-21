import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  CalendarDays,
  CheckCircle2,
  ChevronRight,
  FileText,
  ImageOff,
  Mail,
  MapPin,
  Phone,
  Sparkles,
  User,
} from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { DataState } from '../../components/portal/DataState';
import { BackLink, LoginLinkButton, PublicShell } from '../../components/portal/PublicShell';
import { ppdbPublicApi } from '../../api/ppdbApi';
import { useAuthStore } from '@/stores/authStore';
import {
  countdownLabel,
  formatDate,
  formatDateTime,
  formatRupiah,
  percentOf,
  quotaLine,
  remainingLabel,
  toNumber,
} from '../../components/portal/format';
import {
  PERIOD_STATUS_LABELS,
  PERIOD_STATUS_TONES,
  STAGE_TYPE_LABELS,
  type Tone,
} from '../../components/portal/labels';
import type { PpdbPublicFee, PpdbPublicStage } from '@/types/ppdbPortal';
import type { PpdbPeriodStatus, PpdbStageType } from '@/types/ppdb';

function SectionTitle({ icon: Icon, children }: { icon: typeof FileText; children: string }) {
  return (
    <h2 className="mb-2 flex items-center gap-1.5 text-sm font-semibold uppercase tracking-wide text-slate-500">
      <Icon className="h-4 w-4 text-brand-600" />
      {children}
    </h2>
  );
}

function QuotaBar({ quota, taken }: { quota: number | null; taken: number | null }) {
  const percent = percentOf(quota, taken);
  return (
    <div className="mt-2">
      <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div
          className={percent >= 100 ? 'h-full bg-red-500' : 'h-full bg-brand-600'}
          style={{ width: `${percent}%` }}
        />
      </div>
      <p className="mt-1 text-xs text-slate-500">{quotaLine(quota, taken)}</p>
    </div>
  );
}

function DateRow({ label, start, end, open }: { label: string; start: string | null; end: string | null; open?: boolean }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-1 border-b border-slate-100 py-2 text-sm last:border-0">
      <span className="text-slate-600">{label}</span>
      <span className="text-right">
        <span className="font-medium text-slate-800">
          {start || end ? `${formatDate(start)} - ${formatDate(end)}` : 'Belum dijadwalkan'}
        </span>
        {end && open && (
          <span className="ml-2 inline-block rounded bg-brand-50 px-1.5 py-0.5 text-[11px] font-medium text-brand-700">
            {countdownLabel(end)} lagi
          </span>
        )}
      </span>
    </div>
  );
}

/**
 * Status periode dibaca dari kolom `status` ringkasan publik; nilai di luar peta
 * enum tetap tampil apa adanya agar halaman tidak kosong.
 */
function periodStatusTone(status: PpdbPeriodStatus): Tone {
  return PERIOD_STATUS_TONES[status] ?? 'gray';
}

function periodStatusLabel(status: PpdbPeriodStatus): string {
  return PERIOD_STATUS_LABELS[status] ?? status;
}

/**
 * Nama tahap seleksi. `PpdbPublicStage.type` adalah string bebas dari backend
 * (resource mengirim `type` + `type_label`), jadi peta enum dipakai sebagai
 * penerjemah dengan fallback ke label backend lalu kode mentahnya.
 */
function stageTypeLabel(stage: PpdbPublicStage): string {
  if (stage.type_label) return stage.type_label;
  if (Object.prototype.hasOwnProperty.call(STAGE_TYPE_LABELS, stage.type)) {
    return STAGE_TYPE_LABELS[stage.type as PpdbStageType];
  }
  return stage.type;
}

/**
 * Kisaran biaya sebuah program.
 *
 * Endpoint publik tidak mengirim `fee_from`/`fee_to` — keduanya diturunkan dari
 * array `fees[]` milik program (`PpdbPublicPeriodDetail.programs[].fees`).
 */
function feeRange(fees: PpdbPublicFee[]): { min: number; max: number } | null {
  const amounts = fees.map((fee) => toNumber(fee.amount)).filter((amount) => amount > 0);
  if (amounts.length === 0) return null;
  return { min: Math.min(...amounts), max: Math.max(...amounts) };
}

/** Halaman informasi publik satu periode PPDB (`/ppdb/{slug}`). */
export function PpdbPublicPeriodPage() {
  const { slug = '' } = useParams();
  const token = useAuthStore((state) => state.token);
  const detailQuery = useQuery({
    queryKey: ['ppdb', 'public', 'period', slug],
    queryFn: () => ppdbPublicApi.period(slug),
    enabled: slug.length > 0,
    staleTime: 60_000,
  });

  // `GET /ppdb/public/{slug}` mengembalikan respons DATAR: kolom periode dari
  // `PpdbPublicPeriodResource::summary()` digabung dengan
  // `PpdbRegistrationQueryService::publicPeriodPayload()`. Tidak ada pembungkus
  // `period` dan tidak ada kunci `schedule` — jadwal adalah kolom periode.
  const detail = detailQuery.data;
  // Semua cabang akses dibuat aman terhadap respons kosong: halaman harus tetap ter-render
  // walaupun panitia baru mengisi sebagian konfigurasi.
  const contact = detail?.contact ?? { name: null, phone: null, email: null, address: null };
  const waves = detail?.waves ?? [];
  const programs = detail?.programs ?? [];
  const documents = detail?.documents ?? [];
  const fees = detail?.fees ?? [];
  const faq = detail?.faq ?? [];
  const stages = detail?.selection_stages ?? [];
  const banner = detail?.banner_url || null;
  const logo = detail?.logo_url || null;
  const academicYearName = detail?.academic_year?.name ?? null;
  const feeTotal = detail?.fee_total ?? 0;
  // `program_id`/`wave_id` pada persyaratan diterjemahkan lewat daftar program &
  // gelombang se-payload — kolom `program_name` tidak dikirim endpoint publik.
  const programNames = new Map(programs.map((program) => [program.id, program.name]));
  const waveNames = new Map(waves.map((wave) => [wave.id, wave.name]));
  /** Label cakupan satu berkas/biaya: "khusus <program>" + "gelombang <wave>". */
  const scopeOf = (programId: number | null, waveId: number | null): string => {
    const parts: string[] = [];
    const program = programId === null ? null : programNames.get(programId);
    const wave = waveId === null ? null : waveNames.get(waveId);
    if (program) parts.push(`khusus ${program}`);
    if (wave) parts.push(`gelombang ${wave}`);
    return parts.join(' · ');
  };
  const registerTarget = token ? '/ppdb/portal' : '/ppdb/register';

  return (
    <PublicShell schoolName={detail?.name ?? 'PPDB Online'} periodName={academicYearName} wide>
      <DataState
        isLoading={detailQuery.isLoading}
        isError={detailQuery.isError}
        error={detailQuery.error}
        onRetry={() => detailQuery.refetch()}
        isEmpty={!detail}
        loadingLabel="Memuat informasi pendaftaran..."
        errorTitle="Informasi periode tidak dapat dimuat"
        emptyTitle="Periode tidak ditemukan"
        emptyDescription="Tautan yang Anda buka mungkin sudah kedaluwarsa atau salah diketik."
        emptyAction={
          <BackLink to="/ppdb" label="Kembali ke daftar periode" />
        }
      >
        <BackLink to="/ppdb" label="Semua periode pendaftaran" />

        {banner ? (
          <img
            src={banner}
            alt={detail?.public_title || detail?.name || 'Banner pendaftaran'}
            className="mb-3 max-h-64 w-full rounded-2xl border border-slate-200 object-cover"
          />
        ) : (
          <div className="mb-3 flex h-32 w-full items-center justify-center gap-2 rounded-2xl bg-slate-100 text-sm text-slate-400">
            <ImageOff className="h-5 w-5" />
            Banner belum tersedia
          </div>
        )}

        <Card className="p-4 sm:p-5">
          <div className="flex items-start gap-3">
            {logo && (
              <img src={logo} alt="" className="h-12 w-12 shrink-0 rounded-lg border border-slate-200 object-contain p-1" />
            )}
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="text-xl font-bold text-slate-900">
                  {detail?.public_title || detail?.name || 'Informasi pendaftaran'}
                </h1>
                {detail && (
                  <Badge tone={periodStatusTone(detail.status)}>
                    {periodStatusLabel(detail.status)}
                  </Badge>
                )}
              </div>
              {academicYearName && (
                <p className="mt-0.5 text-xs text-slate-500">Tahun ajaran {academicYearName}</p>
              )}
            </div>
          </div>

          <p className="mt-3 whitespace-pre-line text-sm leading-relaxed text-slate-700">
            {detail?.public_description || 'Panitia belum menuliskan keterangan umum untuk periode ini.'}
          </p>

          <div className="mt-4 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-700">
            {detail?.registration_open ? (
              <p className="flex items-start gap-1.5">
                <Sparkles className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                <span>
                  Pendaftaran sedang dibuka dan berakhir {formatDate(detail?.registration_end)} ({' '}
                  {remainingLabel(detail?.registration_end)}).
                </span>
              </p>
            ) : (
              <p className="flex items-start gap-1.5">
                <CalendarDays className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                <span>
                  Pendaftaran pada periode ini belum dibuka atau sudah ditutup. Cek jadwal di bawah agar tidak
                  terlewat.
                </span>
              </p>
            )}
          </div>

          {/* Ringkasan kuota periode — kolom `quota` pada payload publik. */}
          <dl className="mt-2 grid grid-cols-2 gap-2 text-xs text-slate-600 sm:grid-cols-4">
            <div className="rounded-lg bg-white px-3 py-2 ring-1 ring-slate-100">
              <dt className="text-slate-400">Daya tampung</dt>
              <dd className="font-semibold text-slate-800">
                {detail?.quota.total_quota ?? 'Belum ditetapkan'}
              </dd>
            </div>
            <div className="rounded-lg bg-white px-3 py-2 ring-1 ring-slate-100">
              <dt className="text-slate-400">Sudah mendaftar</dt>
              <dd className="font-semibold text-slate-800">{detail?.quota.registered ?? 0} orang</dd>
            </div>
            <div className="rounded-lg bg-white px-3 py-2 ring-1 ring-slate-100">
              <dt className="text-slate-400">Sisa kuota</dt>
              <dd className="font-semibold text-slate-800">{detail?.quota.remaining ?? '-'}</dd>
            </div>
            <div className="rounded-lg bg-white px-3 py-2 ring-1 ring-slate-100">
              <dt className="text-slate-400">Program tersedia</dt>
              <dd className="font-semibold text-slate-800">{detail?.quota.open_program_count ?? 0} program</dd>
            </div>
          </dl>

          <div className="mt-4 flex flex-col gap-2 sm:flex-row">
            <Link to={registerTarget} className="flex-1">
              <Button size="lg" disabled={!detail?.registration_open} className="w-full sm:w-auto">
                Daftar sekarang
                <ChevronRight className="h-4 w-4" />
              </Button>
            </Link>
            <LoginLinkButton next={`/ppdb/${slug}`} />
          </div>
          {!detail?.registration_open && (
            <p className="mt-2 text-xs text-slate-500">
              Tombol daftar aktif hanya selama periode pendaftaran dibuka. Anda tetap dapat menyiapkan akun
              orang tua lebih awal.
            </p>
          )}
        </Card>

        <section className="mt-6">
          <SectionTitle icon={CalendarDays}>Jadwal penting</SectionTitle>
          <Card className="px-4 py-1">
            <DateRow
              label="Pendaftaran"
              start={detail?.registration_start ?? null}
              end={detail?.registration_end ?? null}
              open={detail?.registration_open}
            />
            <DateRow
              label="Pengumuman hasil seleksi"
              start={detail?.selection_announcement_date ?? null}
              end={detail?.selection_announcement_date ?? null}
            />
            <DateRow
              label="Daftar ulang"
              start={detail?.re_registration_start ?? null}
              end={detail?.re_registration_end ?? null}
            />
          </Card>
        </section>

        <section className="mt-6">
          <SectionTitle icon={CheckCircle2}>Gelombang pendaftaran</SectionTitle>
          {waves.length === 0 ? (
            <Card className="p-4 text-sm text-slate-500">
              Periode ini menjalankan pendaftaran tanpa pembagian gelombang.
            </Card>
          ) : (
            <div className="space-y-2">
              {waves.map((wave) => (
                <Card key={wave.id} className="p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-slate-900">{wave.name}</p>
                      <p className="mt-0.5 text-xs text-slate-500">
                        {formatDate(wave.registration_start)} - {formatDate(wave.registration_end)}
                        {wave.priority ? ` · prioritas ke-${wave.priority}` : ''}
                      </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-1">
                      {wave.is_open && <Badge tone="green">sedang dibuka</Badge>}
                      {!wave.is_open && <Badge tone="gray">di luar jadwal</Badge>}
                      {wave.remaining === 0 && <Badge tone="red">kuota habis</Badge>}
                      {wave.code && <Badge tone="gray">{wave.code}</Badge>}
                    </div>
                  </div>
                  {wave.description && <p className="mt-1.5 text-xs text-slate-600">{wave.description}</p>}
                  {/* Baris gelombang mengirim `quota` + `registered` + `remaining` langsung. */}
                  <QuotaBar quota={wave.quota} taken={wave.registered} />
                </Card>
              ))}
            </div>
          )}
        </section>

        <section className="mt-6">
          <SectionTitle icon={User}>Program yang dibuka</SectionTitle>
          {programs.length === 0 ? (
            <Card className="p-4 text-sm text-slate-500">
              Daftar program belum dipublikasikan untuk periode ini.
            </Card>
          ) : (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {programs.map((program) => {
                const estimate = feeRange(program.fees);
                return (
                  <Card key={program.id} className="flex flex-col p-4">
                    <div className="flex items-start justify-between gap-2">
                      <p className="text-sm font-semibold text-slate-900">{program.name}</p>
                      <div className="flex shrink-0 items-center gap-1">
                        {program.code && <Badge tone="blue">{program.code}</Badge>}
                        <Badge tone={program.is_full ? 'red' : 'green'}>
                          {program.is_full ? 'kuota penuh' : 'kuota tersedia'}
                        </Badge>
                      </div>
                    </div>
                    {program.description && (
                      <p className="mt-1.5 flex-1 text-xs text-slate-600">{program.description}</p>
                    )}
                    {/* Kisaran biaya diturunkan dari `program.fees[]`; kolom
                        `fee_from`/`fee_to` tidak ada di respons publik. */}
                    {estimate && (
                      <p className="mt-2 text-xs font-medium text-slate-700">
                        Estimasi biaya:{' '}
                        {estimate.min === estimate.max
                          ? formatRupiah(estimate.min)
                          : `${formatRupiah(estimate.min)} - ${formatRupiah(estimate.max)}`}
                        {program.fees.length > 1 && (
                          <span className="ml-1 text-slate-500">
                            ({program.fees.length} pos, total {formatRupiah(program.fee_total)})
                          </span>
                        )}
                      </p>
                    )}
                    {program.documents.length > 0 && (
                      <p className="mt-1 text-xs text-slate-500">
                        {program.documents.length} berkas disiapkan khusus program ini
                      </p>
                    )}
                    <QuotaBar quota={program.quota} taken={program.registered} />
                  </Card>
                );
              })}
            </div>
          )}
        </section>

        <section className="mt-6">
          <SectionTitle icon={FileText}>Berkas yang perlu disiapkan</SectionTitle>
          {documents.length === 0 ? (
            <Card className="p-4 text-sm text-slate-500">
              Tidak ada berkas unggahan yang diminta pada periode ini.
            </Card>
          ) : (
            <Card className="divide-y divide-slate-100">
              <ul className="p-0">
                {documents.map((doc) => {
                  // Persyaratan hanya mengirim `program_id`/`wave_id`; namanya dicari
                  // pada daftar program & gelombang se-payload (`program_name` tidak ada).
                  const scope = scopeOf(doc.program_id, doc.wave_id);
                  return (
                    <li key={doc.id} className="flex flex-wrap items-start justify-between gap-2 p-4">
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium text-slate-800">
                          {doc.name}
                          {doc.required && <span className="ml-1 text-xs text-red-500">wajib</span>}
                        </p>
                        {doc.description && <p className="mt-0.5 text-xs text-slate-500">{doc.description}</p>}
                        <p className="mt-1 text-[11px] text-slate-400">
                          {doc.file_types ? `Format ${doc.file_types}` : 'Format bebas'}
                          {doc.max_size_kb ? ` · maks ${doc.max_size_kb} KB` : ''}
                          {scope ? ` · ${scope}` : ' · berlaku umum'}
                        </p>
                      </div>
                      {!doc.required && <Badge tone="gray">pendukung</Badge>}
                    </li>
                  );
                })}
              </ul>
            </Card>
          )}
        </section>

        <section className="mt-6">
          <SectionTitle icon={CalendarDays}>Rincian biaya</SectionTitle>
          {fees.length === 0 ? (
            <Card className="p-4 text-sm text-slate-500">
              Biaya pendaftaran periode ini diumumkan kemudian oleh panitia.
            </Card>
          ) : (
            <Card className="p-4">
              <ul className="divide-y divide-slate-100 text-sm">
                {fees.map((fee) => {
                  const scope = scopeOf(fee.program_id, fee.wave_id);
                  return (
                    <li key={fee.id} className="flex items-start justify-between gap-3 py-2">
                      <span className="min-w-0">
                        <span className="text-slate-700">{fee.name}</span>
                        <span className="ml-1 text-xs text-slate-400">{scope || 'berlaku umum'}</span>
                      </span>
                      <span className="shrink-0 font-medium text-slate-800">{formatRupiah(fee.amount)}</span>
                    </li>
                  );
                })}
                <li className="flex items-center justify-between gap-3 py-2.5 font-semibold text-slate-900">
                  <span>Total perkiraan</span>
                  <span>{formatRupiah(feeTotal)}</span>
                </li>
              </ul>
              <p className="mt-1 text-xs text-slate-500">
                Total adalah penjumlahan seluruh biaya yang dipublikasikan. Biaya yang benar ditagihkan
                setelah program dan gelombang Anda dipilih.
              </p>
            </Card>
          )}
        </section>

        {stages.length > 0 && (
          <section className="mt-6">
            <SectionTitle icon={CheckCircle2}>Tahapan seleksi</SectionTitle>
            <Card className="overflow-x-auto">
              <table className="w-full min-w-[420px] text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-2 font-medium">Tahapan</th>
                    <th className="px-4 py-2 font-medium">Waktu</th>
                    <th className="px-4 py-2 font-medium">Tempat</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {stages.map((stage) => {
                    const stageProgram = stage.program_id === null ? null : programNames.get(stage.program_id) ?? null;
                    return (
                      <tr key={stage.id}>
                        <td className="px-4 py-2 text-slate-700">
                          {stage.name}
                          <span className="ml-1 text-xs text-slate-400">{stageTypeLabel(stage)}</span>
                          {stageProgram && (
                            <span className="block text-xs text-slate-400">khusus {stageProgram}</span>
                          )}
                        </td>
                        <td className="px-4 py-2 text-slate-600">{formatDateTime(stage.scheduled_at)}</td>
                        <td className="px-4 py-2 text-slate-600">{stage.location ?? 'Menunggu info'}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </Card>
          </section>
        )}

        <section className="mt-6">
          <SectionTitle icon={FileText}>Pertanyaan yang sering muncul</SectionTitle>
          {faq.length === 0 ? (
            <Card className="p-4 text-sm text-slate-500">
              Belum ada pertanyaan yang dipublikasikan. Hubungi panitia melalui kontak di bawah.
            </Card>
          ) : (
            <div className="space-y-2">
              {faq.map((item) => (
                <Card key={item.question} className="overflow-hidden">
                  <details>
                    <summary className="flex min-h-12 cursor-pointer items-center justify-between gap-2 px-4 py-3 text-sm font-medium text-slate-800">
                      {item.question}
                      <ChevronRight className="h-4 w-4 shrink-0 text-slate-400" />
                    </summary>
                    <p className="whitespace-pre-line border-t border-slate-100 px-4 py-3 text-sm text-slate-600">
                      {item.answer}
                    </p>
                  </details>
                </Card>
              ))}
            </div>
          )}
        </section>

        <section className="mt-6">
          <SectionTitle icon={MapPin}>Kontak panitia</SectionTitle>
          <Card className="p-4">
            {(contact.name ||
              contact.phone ||
              contact.email ||
              contact.address) ? (
              <ul className="space-y-2 text-sm text-slate-700">
                {contact.name && (
                  <li className="flex items-start gap-2">
                    <User className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                    {contact.name}
                  </li>
                )}
                {contact.phone && (
                  <li className="flex items-start gap-2">
                    <Phone className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                    <a href={`tel:${contact.phone}`} className="hover:underline">
                      {contact.phone}
                    </a>
                  </li>
                )}
                {contact.email && (
                  <li className="flex items-start gap-2">
                    <Mail className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                    <a href={`mailto:${contact.email}`} className="break-all hover:underline">
                      {contact.email}
                    </a>
                  </li>
                )}
                {contact.address && (
                  <li className="flex items-start gap-2">
                    <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                    <span className="whitespace-pre-line">{contact.address}</span>
                  </li>
                )}
              </ul>
            ) : (
              <p className="text-sm text-slate-500">
                Kontak panitia belum dipublikasikan untuk periode ini. Silakan hubungi sekolah secara
                langsung.
              </p>
            )}
          </Card>
        </section>
      </DataState>
    </PublicShell>
  );
}

export default PpdbPublicPeriodPage;
