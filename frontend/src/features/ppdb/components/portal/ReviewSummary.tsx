import { AlertCircle, CheckCircle2 } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { ApplicantIdentity, type ApplicantFormValues } from './ApplicantForm';
import { StatusBadges } from './StatusBadges';
import { formatRupiah } from './format';
import { FEE_TYPE_LABELS, SECTION_LABELS } from './labels';
import { answerDisplayText, toFieldSpec, toQuestionSpec } from './DynamicFieldInput';
import type { PpdbAnswerValue, PpdbPortalRegistrationDetail } from '@/types/ppdbPortal';
import type { PpdbFieldSection } from '@/types/ppdb';

interface Props {
  detail: PpdbPortalRegistrationDetail;
  applicant: ApplicantFormValues;
  answers: {
    fields: Record<string, PpdbAnswerValue>;
    questions: Record<string, PpdbAnswerValue>;
  };
}

/** Ringkasan baca-saja seluruh isian sebelum pendaftaran dikirim. */
export function ReviewSummary({ detail, applicant, answers }: Props) {
  const { schema, documents, blockers } = detail;

  const sectionsOrdered: PpdbFieldSection[] = schema.sections_ordered.length
    ? schema.sections_ordered.map((section) => section.key)
    : Array.from(new Set(schema.fields.map((field) => field.section)));

  // Penghambat kirim disusun server (`blockers()`): field & pertanyaan sudah
  // membawa labelnya sendiri, dokumen hanya `{id, name}`, dan sumbu keempatnya
  // adalah identitas calon yang labelnya berupa teks bebas.
  const missingEntries = [
    ...blockers.applicant.map((label) => `Data calon: ${label}`),
    ...blockers.fields.map((item) => `Isian: ${item.label}`),
    ...blockers.questions.map((item) => `Pertanyaan: ${item.label}`),
    ...blockers.documents.map((item) => `Dokumen: ${item.name}`),
  ];

  return (
    <div className="space-y-3">
      {missingEntries.length > 0 ? (
        <Card className="border-amber-300 bg-amber-50/50 p-4">
          <p className="flex items-center gap-1.5 text-sm font-semibold text-amber-900">
            <AlertCircle className="h-4 w-4" />
            Masih ada {missingEntries.length} bagian yang belum lengkap
          </p>
          <ul className="mt-2 space-y-1 text-xs text-amber-800">
            {missingEntries.map((entry) => (
              <li key={entry}>- {entry}</li>
            ))}
          </ul>
          <p className="mt-2 text-xs text-amber-800">
            Lengkapi terlebih dahulu melalui langkah pada daftar di atas. Pendaftaran tidak dapat dikirim
            sebelum seluruh bagian wajib terisi.
          </p>
        </Card>
      ) : (
        <Card className="border-green-200 bg-green-50/60 p-4">
          <p className="flex items-center gap-1.5 text-sm font-semibold text-green-800">
            <CheckCircle2 className="h-4 w-4" />
            Seluruh bagian wajib sudah terisi
          </p>
          <p className="mt-1 text-xs text-green-800/90">
            Periksa sekali lagi isian di bawah. Setelah dikirim, data tidak dapat diubah bebas dan harus
            melalui panitia bila ada koreksi.
          </p>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Calon siswa & pilihan</CardTitle>
        </CardHeader>
        <CardBody className="space-y-3">
          <ApplicantIdentity applicant={detail.applicant} subtitle={applicant.previous_school || undefined} />
          <div className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
            <div>
              <p className="text-xs text-slate-400">Nomor pendaftaran</p>
              <p className="font-medium text-slate-700">{detail.registration_number}</p>
            </div>
            <div>
              <p className="text-xs text-slate-400">Periode</p>
              <p className="font-medium text-slate-700">{detail.period?.name ?? '-'}</p>
            </div>
            <div>
              <p className="text-xs text-slate-400">Program pilihan</p>
              <p className="font-medium text-slate-700">{detail.program?.name ?? 'Belum dipilih'}</p>
            </div>
            <div>
              <p className="text-xs text-slate-400">Gelombang</p>
              <p className="font-medium text-slate-700">{detail.wave?.name ?? 'Tanpa gelombang'}</p>
            </div>
          </div>
          <StatusBadges
            status={detail.status}
            paymentStatus={detail.payment_status}
            documentStatus={detail.document_status}
          />
        </CardBody>
      </Card>

      {sectionsOrdered.map((section) => {
        const fields = schema.fields
          .filter((field) => field.section === section)
          .sort((a, b) => a.order - b.order);
        if (!fields.length) return null;
        return (
          <Card key={section}>
            <CardHeader>
              <CardTitle>{fields[0].section_label || SECTION_LABELS[section] || section}</CardTitle>
            </CardHeader>
            <CardBody className="space-y-2">
              {fields.map((field) => {
                const spec = toFieldSpec(field);
                return (
                  <div key={field.id} className="flex flex-col gap-0.5 border-b border-slate-50 pb-2 last:border-0">
                    <p className="text-xs text-slate-500">
                      {spec.label}
                      {spec.required && <span className="ml-0.5 text-red-500">*</span>}
                    </p>
                    <p className="text-sm text-slate-800">{answerDisplayText(spec, answers.fields[field.key])}</p>
                  </div>
                );
              })}
            </CardBody>
          </Card>
        );
      })}

      {schema.questions.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Pertanyaan tambahan</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            {schema.questions
              .slice()
              .sort((a, b) => a.order - b.order)
              .map((question) => {
                const spec = toQuestionSpec(question);
                return (
                  <div key={question.id} className="border-b border-slate-50 pb-2 last:border-0">
                    <p className="text-xs text-slate-500">
                      {spec.label}
                      {spec.required && <span className="ml-0.5 text-red-500">*</span>}
                    </p>
                    <p className="text-sm text-slate-800">{answerDisplayText(spec, answers.questions[spec.key])}</p>
                  </div>
                );
              })}
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Dokumen</CardTitle>
        </CardHeader>
        <CardBody>
          {documents.requirements.length === 0 ? (
            <p className="text-sm text-slate-500">Periode ini tidak meminta dokumen unggahan.</p>
          ) : (
            <>
              <ul className="divide-y divide-slate-100 text-sm">
                {documents.requirements.map((slot) => (
                  <li key={slot.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span className="min-w-0 text-slate-700">
                      {slot.name}
                      {slot.required && <span className="ml-0.5 text-red-500">*</span>}
                      {slot.document && (
                        <span className="ml-1 text-xs text-slate-400">
                          v{slot.document.version} · {slot.document.original_filename}
                        </span>
                      )}
                    </span>
                    <Badge
                      tone={slot.status === 'approved' ? 'green' : slot.status === 'rejected' ? 'red' : slot.status === 'under_review' ? 'amber' : 'gray'}
                    >
                      {slot.status === 'approved'
                        ? 'Disetujui'
                        : slot.status === 'rejected'
                          ? 'Perlu diperbaiki'
                          : slot.status === 'under_review'
                            ? 'Ditinjau'
                            : slot.status === 'uploaded'
                              ? 'Terkirim'
                              : 'Belum diunggah'}
                    </Badge>
                  </li>
                ))}
              </ul>
              <p className="mt-2 text-[11px] text-slate-400">
                {documents.summary.uploaded} dari {documents.summary.total} berkas terunggah
                {documents.summary.required_total > 0
                  ? ` · ${documents.summary.required_total} berkas wajib`
                  : ''}
                {documents.unlinked_documents.length > 0
                  ? ` · ${documents.unlinked_documents.length} berkas di luar syarat aktif`
                  : ''}
              </p>
            </>
          )}
        </CardBody>
      </Card>

      {schema.fees.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Rincian biaya</CardTitle>
          </CardHeader>
          <CardBody>
            <ul className="divide-y divide-slate-100 text-sm">
              {schema.fees.map((fee) => (
                <li key={fee.id} className="flex items-center justify-between gap-3 py-2">
                  <span className="min-w-0">
                    <span className="text-slate-700">{fee.name}</span>
                    <span className="ml-1 text-xs text-slate-400">{FEE_TYPE_LABELS[fee.type] ?? fee.type}</span>
                    {!fee.required && <span className="ml-1 text-xs text-slate-400">(opsional)</span>}
                  </span>
                  <span className="shrink-0 font-medium text-slate-800">{formatRupiah(fee.amount)}</span>
                </li>
              ))}
              <li className="flex items-center justify-between gap-3 py-2 font-semibold text-slate-900">
                <span>Total</span>
                <span>{formatRupiah(schema.fee_total)}</span>
              </li>
            </ul>
          </CardBody>
        </Card>
      )}
    </div>
  );
}
