import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { AlertCircle, Banknote, CreditCard, ExternalLink, FileCheck2, Landmark } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { ppdbPortalPaymentApi } from '../../api/ppdbApi';
import { getErrorMessage } from '@/lib/axios';
import { acceptAttribute, formatDateTime, formatFileSize, formatRupiah, remainingLabel, toNumber } from './format';
import { INVOICE_TYPE_LABELS, PAYMENT_METHOD_LABELS, paymentStatusLabel, paymentStatusTone } from './labels';
import type { PpdbInvoice, PpdbPaymentStatus } from '@/types/ppdb';
import type { PpdbPortalPayment } from '@/types/ppdbPortal';

interface Props {
  payment: PpdbPortalPayment;
  disabled?: boolean;
}

/** Batas `proof` pada `PpdbPaymentStoreRequest`: jpg/jpeg/png/pdf, maks 2 MB. */
const PROOF_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];
const PROOF_MAX_BYTES = 2 * 1024 * 1024;

/**
 * Status tagihan yang masih boleh dibayar — kebalikan dari daftar larangan
 * `PpdbPaymentService::assertPayable()`.
 */
const PAYABLE_INVOICE_STATUSES: PpdbPaymentStatus[] = ['unpaid', 'pending', 'failed', 'expired'];

/** Sisa bayar satu tagihan (`outstanding_amount` selalu dikirim resource). */
function outstandingOf(invoice: PpdbInvoice): number {
  if (invoice.outstanding_amount !== undefined && invoice.outstanding_amount !== null) {
    return toNumber(invoice.outstanding_amount);
  }
  return Math.max(0, toNumber(invoice.total_amount) - toNumber(invoice.paid_amount));
}

function isPayable(invoice: PpdbInvoice): boolean {
  return PAYABLE_INVOICE_STATUSES.includes(invoice.status);
}

/**
 * Tagihan, instruksi transfer, laporan "sudah bayar", dan riwayat pembayaran.
 *
 * `GET .../payment` mengirim TAGIHAN JAMAK (`invoices[]`) plus
 * `outstanding_amount`, `currency`, `instructions` (master `payment_methods`),
 * dan `online_available`; tidak ada satu `invoice`, `items`, atau `payments` di
 * tingkat atas — rincian dan riwayat melekat pada tiap tagihan.
 */
export function PaymentPanel({ payment, disabled }: Props) {
  const queryClient = useQueryClient();
  const { registration, invoices, outstanding_amount, currency, instructions, online_available } = payment;
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [amountDraft, setAmountDraft] = useState('');
  const [methodId, setMethodId] = useState('');
  const [reference, setReference] = useState('');
  const [proof, setProof] = useState<File | null>(null);
  const [proofError, setProofError] = useState<string | null>(null);
  const [payError, setPayError] = useState<string | null>(null);

  const payableInvoices = invoices.filter(isPayable);
  const selectedInvoice =
    invoices.find((invoice) => invoice.id === selectedId) ?? payableInvoices[0] ?? null;
  const amount = amountDraft === '' ? String(selectedInvoice ? outstandingOf(selectedInvoice) : 0) : amountDraft;
  const matchedInstruction = instructions.find((instruction) => String(instruction.payment_method_id) === methodId);

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'payment'] });
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'registration'] });
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'dashboard'] });
  };

  const resetForm = () => {
    setAmountDraft('');
    setReference('');
    setProof(null);
    setProofError(null);
  };

  /**
   * Laporan "sudah membayar": manual selalu membawa bukti (wajib di server).
   *
   * CATATAN LAPISAN API: `ppdbPortalPaymentApi.payWithProof` masih mengirim ke
   * `/ppdb/invoices/{id}/pay`, padahal satu-satunya rute bayar adalah
   * `/ppdb/portal/invoices/{id}/pay`; perbaikan itu berada di `api/ppdbApi.ts`
   * yang tidak boleh disentuh gelombang ini.
   */
  const payMutation = useMutation({
    mutationFn: (invoice: PpdbInvoice) =>
      ppdbPortalPaymentApi.payWithProof(
        invoice.id,
        {
          method: 'manual',
          amount: toNumber(amount) || outstandingOf(invoice),
          payment_method_id: matchedInstruction ? matchedInstruction.payment_method_id : '',
          reference: reference.trim() || undefined,
        },
        proof,
      ),
    onSuccess: (result) => {
      setPayError(null);
      const verified = result.invoice.status === 'paid' || result.payment.status === 'paid';
      toast.success(
        verified
          ? 'Pembayaran Anda sudah terverifikasi. Terima kasih.'
          : 'Bukti pembayaran terkirim. Panitia akan memverifikasi pembayaran Anda.',
      );
      resetForm();
      refresh();
    },
    onError: (error) => {
      const message = getErrorMessage(error);
      setPayError(message);
      toast.error(message);
    },
  });

  const onlineMutation = useMutation({
    mutationFn: (invoice: PpdbInvoice) =>
      ppdbPortalPaymentApi.pay(invoice.id, {
        method: 'midtrans',
        amount: toNumber(amount) || outstandingOf(invoice),
      }),
    onSuccess: (result) => {
      setPayError(null);
      const redirectUrl = result.gateway ? result.gateway.redirect_url : null;
      if (result.next_action === 'redirect_to_gateway' && redirectUrl) {
        window.open(redirectUrl, '_blank', 'noopener');
        toast.success('Halaman pembayaran online dibuka di tab baru.');
      } else {
        toast.info(
          'Instruksi pembayaran online sudah dibuat. Lanjutkan pembayaran memakai kode yang dikirim ke email Anda.',
        );
      }
      refresh();
    },
    // Midtrans belum dikonfigurasi dibalas 422 berisi pesan yang menjelaskan
    // (sandbox/default = bukan bug), jadi pesan itu ditampilkan inline.
    onError: (error) => {
      const message = getErrorMessage(error);
      setPayError(message);
      toast.error(message);
    },
  });

  const handleProof = (file: File | null) => {
    setProofError(null);
    if (!file) {
      setProof(null);
      return;
    }
    const ext = (file.name.split('.').pop() ?? '').toLowerCase();
    if (!PROOF_EXTENSIONS.includes(ext)) {
      setProofError(`Bukti pembayaran harus berformat ${PROOF_EXTENSIONS.join(', ')}.`);
      return;
    }
    if (file.size > PROOF_MAX_BYTES) {
      setProofError('Ukuran bukti pembayaran maksimal 2 MB agar mudah diperiksa.');
      return;
    }
    setProof(file);
  };

  if (invoices.length === 0) {
    return (
      <Card className="p-4">
        <div className="flex items-start gap-2">
          <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
          <div>
            <p className="text-sm font-medium text-slate-800">Tagihan Anda belum diterbitkan</p>
            <p className="mt-0.5 text-xs text-slate-500">
              Biasanya tagihan muncul otomatis setelah pendaftaran dikirim. Cek berkala pada halaman
              ini, atau lanjutkan dulu langkah lain sementara menunggu.
            </p>
          </div>
        </div>
      </Card>
    );
  }

  const canReport = selectedInvoice !== null && !disabled && isPayable(selectedInvoice);
  const paymentHistory = invoices.flatMap((invoice) =>
    (invoice.payments ?? []).map((payment) => ({ invoice, payment })),
  );

  return (
    <div className="space-y-3">
      <Card className="p-4">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p className="text-xs uppercase tracking-wide text-slate-400">Total yang harus dibayar</p>
            <p className="mt-0.5 text-lg font-semibold text-slate-900">
              {formatRupiah(outstanding_amount)}
            </p>
            <p className="text-xs text-slate-500">
              {registration.applicant_name ?? 'Calon siswa'} · {registration.registration_number} ·{' '}
              {invoices.length} tagihan ({currency})
            </p>
          </div>
          <Badge tone={paymentStatusTone(registration.payment_status)} className="shrink-0">
            {paymentStatusLabel(registration.payment_status)}
          </Badge>
        </div>
        {online_available && (
          <p className="mt-2 text-xs text-slate-500">
            Pembayaran online tersedia untuk tagihan Anda selain jalur transfer manual.
          </p>
        )}
      </Card>

      {invoices.map((invoice) => {
        const items = invoice.items ?? [];
        const selected = selectedInvoice !== null && selectedInvoice.id === invoice.id;

        return (
          <Card key={invoice.id} className="p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className="text-xs uppercase tracking-wide text-slate-400">
                  {INVOICE_TYPE_LABELS[invoice.type] ?? 'Tagihan'}
                </p>
                <p className="mt-0.5 text-lg font-semibold text-slate-900">
                  {formatRupiah(toNumber(invoice.total_amount))}
                </p>
                <p className="text-xs text-slate-500">Nomor tagihan {invoice.number}</p>
              </div>
              {/* Label dari `paymentStatusLabel`: tipe `PpdbInvoice` belum
                  menampung `status_label`/`type_label` yang dikirim resource. */}
              <Badge tone={paymentStatusTone(invoice.status)} className="shrink-0">
                {paymentStatusLabel(invoice.status)}
              </Badge>
            </div>

            {items.length > 0 && (
              <ul className="mt-3 divide-y divide-slate-100 border-t border-slate-100 text-sm">
                {items.map((item) => (
                  <li key={item.id} className="flex items-center justify-between gap-3 py-2">
                    <span className="min-w-0 text-slate-600">{item.name}</span>
                    <span className="shrink-0 font-medium text-slate-800">{formatRupiah(toNumber(item.amount))}</span>
                  </li>
                ))}
              </ul>
            )}

            <dl className="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
              <div>
                <dt className="text-slate-400">Dibayar</dt>
                <dd className="font-medium text-slate-700">{formatRupiah(toNumber(invoice.paid_amount))}</dd>
              </div>
              <div>
                <dt className="text-slate-400">Kurang bayar</dt>
                <dd className="font-medium text-slate-700">{formatRupiah(outstandingOf(invoice))}</dd>
              </div>
              <div className="col-span-2">
                <dt className="text-slate-400">Batas bayar</dt>
                <dd className="font-medium text-slate-700">
                  {formatDateTime(invoice.due_date)} · {remainingLabel(invoice.due_date)}
                </dd>
              </div>
            </dl>
            {invoice.notes && (
              <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">{invoice.notes}</p>
            )}

            {isPayable(invoice) && payableInvoices.length > 1 && (
              <div className="mt-3">
                <Button
                  size="sm"
                  variant={selected ? 'primary' : 'outline'}
                  disabled={disabled}
                  onClick={() => setSelectedId(invoice.id)}
                >
                  {selected ? 'Tagihan yang dipilih' : 'Bayar tagihan ini'}
                </Button>
              </div>
            )}
          </Card>
        );
      })}

      {instructions.length > 0 && (
        <Card className="p-4">
          <p className="flex items-center gap-1.5 text-sm font-semibold text-slate-900">
            <Landmark className="h-4 w-4 text-brand-600" />
            Rekening tujuan transfer
          </p>
          <ul className="mt-2 space-y-2">
            {instructions.map((instruction) => (
              <li
                key={instruction.payment_method_id}
                className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
              >
                <p className="font-medium text-slate-800">
                  {instruction.name}
                  {instruction.code && (
                    <span className="ml-1 text-xs font-normal text-slate-400">{instruction.code}</span>
                  )}
                </p>
                {instruction.instructions && (
                  <p className="mt-0.5 text-xs text-slate-500">{instruction.instructions}</p>
                )}
              </li>
            ))}
          </ul>
          <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Transfer sesuai jumlah tagihan lalu unggah bukti pembayaran. Pembayaran diverifikasi manual
            oleh panitia pada jam kerja.
          </p>
        </Card>
      )}

      {!disabled && selectedInvoice && (
        <Card className="p-4">
          <p className="flex items-center gap-1.5 text-sm font-semibold text-slate-900">
            <Banknote className="h-4 w-4 text-brand-600" />
            Saya sudah membayar
          </p>
          <p className="mt-0.5 text-xs text-slate-500">
            Tagihan {selectedInvoice.number} · sisa {formatRupiah(outstandingOf(selectedInvoice))}
          </p>
          <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <Label htmlFor="pay-amount" required>
                Jumlah dibayar
              </Label>
              <Input
                id="pay-amount"
                type="number"
                inputMode="numeric"
                value={amount}
                disabled={!canReport}
                onChange={(e) => setAmountDraft(e.target.value)}
              />
              <p className="mt-1 text-xs text-slate-500">
                Boleh sebagian, tidak melebihi sisa tagihan ({currency}).
              </p>
            </div>
            <div>
              <Label htmlFor="pay-method">Metode / rekening yang dipakai</Label>
              <Select
                id="pay-method"
                value={methodId}
                disabled={!canReport}
                onChange={(e) => setMethodId(e.target.value)}
              >
                <option value="">-- Pilih rekening --</option>
                {instructions.map((instruction) => (
                  <option
                    key={instruction.payment_method_id}
                    value={String(instruction.payment_method_id)}
                  >
                    {instruction.name}
                  </option>
                ))}
                <option value="other">Lainnya / tunai ke panitia</option>
              </Select>
            </div>
            <div>
              <Label htmlFor="pay-ref">Nomor referensi transfer</Label>
              <Input
                id="pay-ref"
                value={reference}
                placeholder="mis. 20260201/8812"
                disabled={!canReport}
                onChange={(e) => setReference(e.target.value)}
              />
              <p className="mt-1 text-xs text-slate-500">Opsional, memudahkan panitia mencocokkan.</p>
            </div>
            <div>
              <Label htmlFor="pay-proof" required>
                Bukti pembayaran
              </Label>
              <input
                id="pay-proof"
                type="file"
                accept={acceptAttribute(PROOF_EXTENSIONS)}
                disabled={!canReport}
                onChange={(e) => handleProof(e.target.files?.[0] ?? null)}
                className="block w-full text-xs text-slate-600 file:mr-2 file:min-h-9 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:text-slate-700"
              />
              {proof && (
                <p className="mt-1 flex items-center gap-1 text-xs text-green-700">
                  <FileCheck2 className="h-3.5 w-3.5" />
                  {proof.name} ({formatFileSize(proof.size)})
                </p>
              )}
              {proofError && <p className="mt-1 text-xs text-red-500">{proofError}</p>}
              {!proof && !proofError && (
                <p className="mt-1 text-xs text-slate-500">Wajib untuk transfer manual (maks 2 MB).</p>
              )}
            </div>
          </div>

          {payError && (
            <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{payError}</p>
          )}

          <div className="mt-3 flex flex-col gap-2 sm:flex-row">
            <Button
              loading={payMutation.isPending}
              disabled={!canReport || !amount || toNumber(amount) <= 0 || !proof}
              onClick={() => payMutation.mutate(selectedInvoice)}
              className="w-full sm:w-auto"
            >
              Kirim bukti pembayaran
            </Button>
            {online_available ? (
              <Button
                variant="outline"
                loading={onlineMutation.isPending}
                disabled={!canReport}
                onClick={() => onlineMutation.mutate(selectedInvoice)}
                className="w-full sm:w-auto"
              >
                <CreditCard className="h-4 w-4" />
                Bayar online
              </Button>
            ) : (
              <p className="text-xs text-slate-500">
                Pembayaran online belum tersedia pada sekolah ini. Silakan transfer manual dan unggah
                bukti pembayarannya.
              </p>
            )}
          </div>
          {!canReport && (
            <p className="mt-2 text-xs text-slate-500">
              Tagihan ini tidak dapat dibayar pada status sekarang. Silakan hubungi panitia bila menurut
              Anda ini keliru.
            </p>
          )}
        </Card>
      )}

      <Card className="p-4">
        <p className="text-sm font-semibold text-slate-900">Riwayat pembayaran</p>
        {paymentHistory.length === 0 ? (
          <p className="mt-1 text-xs text-slate-500">Belum ada pembayaran yang tercatat.</p>
        ) : (
          <ul className="mt-2 divide-y divide-slate-100">
            {paymentHistory.map(({ invoice, payment }) => (
              <li key={payment.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span className="min-w-0">
                  <span className="font-medium text-slate-800">{formatRupiah(toNumber(payment.amount))}</span>
                  <span className="ml-2 text-xs text-slate-500">
                    {PAYMENT_METHOD_LABELS[payment.method] ?? payment.method} ·{' '}
                    {payment.payment_method?.name ? `${payment.payment_method.name} · ` : ''}
                    {invoice.number} · {formatDateTime(payment.created_at ?? payment.paid_at)}
                  </span>
                </span>
                <span className="flex items-center gap-2">
                  <Badge tone={paymentStatusTone(payment.status)}>
                    {paymentStatusLabel(payment.status)}
                  </Badge>
                  {payment.proof_url && (
                    <a
                      href={payment.proof_url}
                      target="_blank"
                      rel="noreferrer"
                      className="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline"
                    >
                      bukti <ExternalLink className="h-3 w-3" />
                    </a>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
