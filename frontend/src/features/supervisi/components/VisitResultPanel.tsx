import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { CheckCircle2, History, RotateCcw, Signature, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { supervisiVisitApi } from '../api/supervisiVisitApi';
import type { VisitDetail } from '../types';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage } from '@/lib/axios';

const DISPUTE_DECISIONS = [
  { value: 'REJECTED', label: 'Ditolak — hasil lama berlaku' },
  { value: 'PARTIALLY_ACCEPTED', label: 'Diterima sebagian — nilai dihitung ulang' },
  { value: 'ACCEPTED', label: 'Diterima — nilai dihitung ulang' },
  { value: 'WITHDRAWN', label: 'Ditarik oleh pengaju' },
];

/**
 * Tab hasil: ringkasan nilai, temuan, wawancara, tanda tangan penerimaan,
 * keberatan, dan riwayat revisi.
 */
export function VisitResultPanel({
  detail,
  isSupervisee,
  canReopen,
  onReopen,
  onChanged,
}: {
  detail: VisitDetail;
  isSupervisee: boolean;
  canReopen: boolean;
  onReopen: () => void;
  onChanged: () => void;
}) {
  const [note, setNote] = useState('');
  const [reason, setReason] = useState('');
  const [decision, setDecision] = useState<{ id: number | null; status: string; note: string }>({ id: null, status: 'REJECTED', note: '' });

  const acknowledgeMutation = useMutation({
    mutationFn: () => supervisiVisitApi.acknowledge(detail.visit.id, note || undefined),
    onSuccess: () => {
      toast.success('Hasil sudah Anda tandai');
      setNote('');
      onChanged();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const disputeMutation = useMutation({
    mutationFn: () => supervisiVisitApi.dispute(detail.visit.id, { reason }),
    onSuccess: () => {
      toast.success('Keberatan terkirim ke moderator');
      setReason('');
      onChanged();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const resolveMutation = useMutation({
    mutationFn: () => supervisiVisitApi.resolveDispute(decision.id ?? 0, { status: decision.status, decision_note: decision.note }),
    onSuccess: () => {
      toast.success('Keputusan keberatan tersimpan');
      setDecision({ id: null, status: 'REJECTED', note: '' });
      onChanged();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const summary = detail.summary;
  const sectionTitle = (sectionId: number) => detail.sections.find((section) => section.id === sectionId)?.title ?? `Bagian ${sectionId}`;

  if (!detail.visit.is_finalized) {
    return (
      <Card>
        <CardBody>
          <div className="flex items-start gap-3 py-6 text-slate-500">
            <TriangleAlert className="mt-0.5 h-5 w-5 text-amber-500" />
            <div>
              <p className="font-medium text-slate-700">Hasil belum difinalkan.</p>
              <p className="text-sm">
                Nilai akhir, tanda tangan, dan keberatan muncul setelah supervisor memfinalkan kegiatan ini.
              </p>
            </div>
          </div>
        </CardBody>
      </Card>
    );
  }

  return (
    <div className="space-y-3">
      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <CardTitle>Ringkasan Nilai</CardTitle>
            <div className="flex items-center gap-2">
              {detail.visit.final_score !== null && <Badge tone="brand">Nilai akhir {detail.visit.final_score}</Badge>}
              {detail.visit.grade_label && <Badge tone="green">{detail.visit.grade_label}</Badge>}
              {detail.visit.revision > 1 && <Badge tone="amber">revisi ke-{detail.visit.revision}</Badge>}
            </div>
          </div>
        </CardHeader>
        <CardBody className="space-y-3">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div className="rounded-lg bg-slate-50 p-3">
              <div className="text-xs text-slate-500">Skor mentah</div>
              <div className="text-lg font-semibold text-slate-800">{detail.visit.final_score === null ? '—' : summary?.raw ?? '—'}</div>
            </div>
            <div className="rounded-lg bg-slate-50 p-3">
              <div className="text-xs text-slate-500">Skor maksimum</div>
              <div className="text-lg font-semibold text-slate-800">{summary?.max ?? '—'}</div>
            </div>
            <div className="rounded-lg bg-slate-50 p-3">
              <div className="text-xs text-slate-500">Terjawab</div>
              <div className="text-lg font-semibold text-slate-800">{summary?.answered ?? 0}</div>
            </div>
            <div className="rounded-lg bg-slate-50 p-3">
              <div className="text-xs text-slate-500">Dikecualikan (N/A)</div>
              <div className="text-lg font-semibold text-slate-800">{summary?.excluded ?? 0}</div>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-3 py-2 font-medium">Bagian</th>
                  <th className="px-3 py-2 font-medium">Perolehan</th>
                  <th className="px-3 py-2 font-medium">Maksimum</th>
                  <th className="px-3 py-2 font-medium">Pencapaian</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(summary?.sections ?? []).map((row) => (
                  <tr key={row.section_id}>
                    <td className="px-3 py-2 text-slate-700">{sectionTitle(row.section_id)}</td>
                    <td className="px-3 py-2">{row.raw}</td>
                    <td className="px-3 py-2">{row.max}</td>
                    <td className="px-3 py-2">{row.percent === null ? '—' : `${row.percent}%`}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {detail.visit.summary && <p className="text-sm text-slate-700">{detail.visit.summary}</p>}
          {detail.visit.strengths && <p className="text-sm text-green-700">Kekuatan: {detail.visit.strengths}</p>}
          {detail.visit.improvements && <p className="text-sm text-amber-700">Perlu ditingkatkan: {detail.visit.improvements}</p>}
          {detail.visit.snapshot_checksum && (
            <p className="text-xs text-slate-400">
              Sidik jari hasil: {detail.visit.snapshot_checksum.slice(0, 16)}… (isi hasil dibekukan dan tidak berubah walau instrumen direvisi)
            </p>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader><CardTitle>Temuan & Rekomendasi</CardTitle></CardHeader>
        <CardBody className="space-y-2">
          {detail.findings.length === 0 && <p className="text-sm text-slate-500">Tidak ada temuan tercatat.</p>}
          {detail.findings.map((finding, index) => (
            <div key={finding.id ?? index} className="rounded-lg bg-slate-50 p-3 text-sm">
              <div className="flex items-center gap-2">
                <Badge tone="gray">{finding.component}</Badge>
                <Badge tone={finding.priority === 'URGENT' || finding.priority === 'HIGH' ? 'red' : 'amber'}>{finding.priority}</Badge>
              </div>
              {finding.weakness && <p className="mt-1 text-slate-700">Kelemahan: {finding.weakness}</p>}
              {finding.cause && <p className="text-slate-600">Penyebab: {finding.cause}</p>}
              {finding.recommendation && <p className="text-brand-700">Rekomendasi: {finding.recommendation}</p>}
            </div>
          ))}
        </CardBody>
      </Card>

      <Card>
        <CardHeader><CardTitle>Tanda Tangan & Keberatan</CardTitle></CardHeader>
        <CardBody className="space-y-4">
          {detail.acknowledgment === null ? (
            <p className="text-sm text-slate-500">Belum ada catatan tanda tangan.</p>
          ) : detail.acknowledgment.acknowledged_at ? (
            <div className="flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">
              <CheckCircle2 className="mt-0.5 h-4 w-4" />
              <div>
                <div className="font-medium">
                  Ditandai pada {new Date(detail.acknowledgment.acknowledged_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}
                </div>
                {detail.acknowledgment.note && <p>{detail.acknowledgment.note}</p>}
              </div>
            </div>
          ) : (
            <p className="text-sm text-slate-500">Menunggu tanda tangan yang disupervisi.</p>
          )}

          {isSupervisee && !detail.acknowledgment?.acknowledged_at && (
            <div className="space-y-2">
              <Label>Catatan (opsional)</Label>
              <Textarea rows={2} value={note} onChange={(event) => setNote(event.target.value)} placeholder="mis. terima kasih, akan saya tindak lanjuti" />
              <Button onClick={() => acknowledgeMutation.mutate()} disabled={acknowledgeMutation.isPending}>
                {acknowledgeMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <Signature className="mr-2 h-4 w-4" />} Tandai sudah dibaca
              </Button>
            </div>
          )}

          {isSupervisee && detail.acknowledgment?.acknowledged_at && (
            <div className="space-y-2 border-t border-slate-100 pt-3">
              <Label>Ajukan keberatan</Label>
              <Textarea
                rows={3}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                placeholder="Jelaskan bagian yang tidak Anda sepakati beserta buktinya"
              />
              <Button
                variant="outline"
                onClick={() => {
                  if (reason.trim().length < 5) {
                    toast.error('Tuliskan alasan keberatan.');
                    return;
                  }
                  disputeMutation.mutate();
                }}
                disabled={disputeMutation.isPending}
              >
                {disputeMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Kirim keberatan
              </Button>
            </div>
          )}

          {detail.disputes.length > 0 && (
            <div className="space-y-2 border-t border-slate-100 pt-3">
              {detail.disputes.map((dispute) => (
                <div key={dispute.id} className="rounded-lg border border-slate-200 p-3 text-sm">
                  <div className="flex items-center justify-between gap-2">
                    <Badge tone={dispute.status === 'OPEN' ? 'amber' : dispute.status === 'REJECTED' ? 'gray' : 'green'}>
                      {dispute.status}
                    </Badge>
                    <span className="text-xs text-slate-500">
                      {dispute.created_at ? new Date(dispute.created_at).toLocaleDateString('id-ID') : ''}
                    </span>
                  </div>
                  <p className="mt-1 text-slate-700">{dispute.reason}</p>
                  {dispute.decision_note && <p className="mt-1 text-slate-600">Keputusan: {dispute.decision_note}</p>}

                  {detail.abilities.resolve_dispute && (dispute.status === 'OPEN' || dispute.status === 'UNDER_REVIEW') && (
                    <div className="mt-2 space-y-2 border-t border-slate-100 pt-2">
                      <Select
                        value={decision.id === dispute.id ? decision.status : 'REJECTED'}
                        onChange={(event) => setDecision({ id: dispute.id, status: event.target.value, note: decision.id === dispute.id ? decision.note : '' })}
                      >
                        {DISPUTE_DECISIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                      </Select>
                      <Input
                        placeholder="Catatan keputusan"
                        value={decision.id === dispute.id ? decision.note : ''}
                        onChange={(event) => setDecision({ id: dispute.id, status: decision.status, note: event.target.value })}
                      />
                      <Button
                        size="sm"
                        onClick={() => {
                          if (decision.id !== dispute.id || decision.note.trim() === '') {
                            toast.error('Pilih keputusan dan isi catatannya.');
                            return;
                          }
                          resolveMutation.mutate();
                        }}
                        disabled={resolveMutation.isPending}
                      >
                        Simpan keputusan
                      </Button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <div className="flex items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <History className="h-4 w-4 text-slate-400" />
              <CardTitle>Riwayat</CardTitle>
            </div>
            {canReopen && (
              <Button size="sm" variant="outline" onClick={onReopen}>
                <RotateCcw className="mr-2 h-4 w-4" /> Buka ulang untuk koreksi
              </Button>
            )}
          </div>
        </CardHeader>
        <CardBody>
          <ul className="space-y-2 text-sm">
            {detail.history.map((row) => (
              <li key={`${row.revision}-${row.action}`} className="flex items-start justify-between gap-3 border-b border-slate-100 pb-2 last:border-0">
                <div>
                  <div className="font-medium text-slate-700">Revisi {row.revision} — {row.action}</div>
                  {row.reason && <p className="text-slate-500">{row.reason}</p>}
                </div>
                <div className="text-right text-xs text-slate-500">
                  <div>{row.at ? new Date(row.at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : ''}</div>
                  {row.actor && <div>{row.actor}</div>}
                </div>
              </li>
            ))}
            {detail.history.length === 0 && <li className="text-slate-500">Belum ada riwayat.</li>}
          </ul>
        </CardBody>
      </Card>
    </div>
  );
}
