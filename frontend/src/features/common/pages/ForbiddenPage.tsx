import { Link } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import { Button } from '@/components/ui/Button';

export default function ForbiddenPage() {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-20 text-center">
      <div className="rounded-full bg-red-100 p-4 text-red-600">
        <ShieldAlert className="h-8 w-8" />
      </div>
      <h2 className="text-xl font-bold text-slate-800">Akses Ditolak</h2>
      <p className="max-w-sm text-sm text-slate-500">
        Anda tidak memiliki hak akses untuk membuka halaman ini.
      </p>
      <Link to="/">
        <Button variant="outline">Kembali ke Dashboard</Button>
      </Link>
    </div>
  );
}
