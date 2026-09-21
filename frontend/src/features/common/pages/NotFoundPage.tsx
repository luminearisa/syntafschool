import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/Button';

export default function NotFoundPage() {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-20 text-center">
      <p className="text-6xl font-bold text-slate-200">404</p>
      <h2 className="text-xl font-bold text-slate-800">Halaman tidak ditemukan</h2>
      <p className="max-w-sm text-sm text-slate-500">
        Halaman yang Anda cari tidak tersedia atau telah dipindahkan.
      </p>
      <Link to="/">
        <Button variant="outline">Kembali ke Dashboard</Button>
      </Link>
    </div>
  );
}
