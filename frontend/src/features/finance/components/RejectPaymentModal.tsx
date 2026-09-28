import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Textarea } from '@/components/ui/Textarea';
import type { FinancePayment } from '../types';

export function RejectPaymentModal({
  payment,
  open,
  submitting,
  onClose,
  onSubmit,
}: {
  payment: FinancePayment | null;
  open: boolean;
  submitting: boolean;
  onClose: () => void;
  onSubmit: (reason: string) => void;
}) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (open) {
      setReason('');
      setError('');
    }
  }, [open]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!reason.trim()) {
      setError('Alasan penolakan wajib diisi.');
      return;
    }
    onSubmit(reason.trim());
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Tolak Pembayaran"
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Batal</Button>
          <Button type="submit" form="reject-payment-form" variant="danger" loading={submitting}>Tolak Pembayaran</Button>
        </>
      }
    >
      <form id="reject-payment-form" onSubmit={submit} className="space-y-3">
        <p className="text-sm text-slate-600">Pembayaran {payment?.payment_number ?? ''} akan ditandai gagal. Saldo invoice tidak berubah.</p>
        <Textarea value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Tuliskan alasan penolakan" />
        {error && <p role="alert" className="text-xs text-red-600">{error}</p>}
      </form>
    </Modal>
  );
}
