import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';

export function ConfirmDialog({
  open,
  title = 'Konfirmasi',
  message,
  loading,
  confirmLabel = 'Hapus',
  confirmVariant = 'danger',
  onConfirm,
  onCancel,
}: {
  open: boolean;
  title?: string;
  message: string;
  loading?: boolean;
  confirmLabel?: string;
  confirmVariant?: 'primary' | 'danger' | 'outline';
  onConfirm: () => void;
  onCancel: () => void;
}) {
  return (
    <Modal
      open={open}
      onClose={onCancel}
      title={title}
      size="sm"
      footer={
        <>
          <Button variant="outline" onClick={onCancel} disabled={loading}>
            Batal
          </Button>
          <Button variant={confirmVariant} onClick={onConfirm} loading={loading}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <p className="text-sm text-slate-600">{message}</p>
    </Modal>
  );
}
