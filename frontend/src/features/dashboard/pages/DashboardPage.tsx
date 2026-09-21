import { useQuery } from '@tanstack/react-query';
import { Database, GraduationCap, Users, Wallet } from 'lucide-react';
import { authApi } from '@/features/auth/api/authApi';
import { MASTER_RESOURCES } from '@/constants/masterData';
import { Card, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { useAuthStore } from '@/stores/authStore';

function StatCard({
  icon: Icon,
  label,
  value,
  tone,
}: {
  icon: typeof Database;
  label: string;
  value: string | number;
  tone: string;
}) {
  return (
    <Card>
      <CardBody className="flex items-center gap-4">
        <div className={`flex h-12 w-12 items-center justify-center rounded-xl ${tone}`}>
          <Icon className="h-6 w-6" />
        </div>
        <div>
          <p className="text-2xl font-bold text-slate-900">{value}</p>
          <p className="text-sm text-slate-500">{label}</p>
        </div>
      </CardBody>
    </Card>
  );
}

export default function DashboardPage() {
  const user = useAuthStore((s) => s.user);

  const { data: profile } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: authApi.me,
    staleTime: 1000 * 60 * 5,
  });

  const masterCount = MASTER_RESOURCES.length;

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-slate-900">Selamat datang, {user?.name} 👋</h2>
        <p className="text-sm text-slate-500">
          Ringkasan sistem Syntaf School ERP.
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard icon={Database} label="Master Data" value={masterCount} tone="bg-brand-100 text-brand-700" />
        <StatCard icon={Users} label="Pengguna" value="- " tone="bg-blue-100 text-blue-700" />
        <StatCard icon={GraduationCap} label="Siswa" value="- " tone="bg-amber-100 text-amber-700" />
        <StatCard icon={Wallet} label="Keuangan" value="- " tone="bg-green-100 text-green-700" />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card>
          <CardBody>
            <h3 className="mb-3 text-base font-semibold text-slate-800">Profil Anda</h3>
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between border-b border-slate-50 pb-2">
                <dt className="text-slate-500">Nama</dt>
                <dd className="font-medium text-slate-700">{profile?.name ?? user?.name}</dd>
              </div>
              <div className="flex justify-between border-b border-slate-50 pb-2">
                <dt className="text-slate-500">Email</dt>
                <dd className="font-medium text-slate-700">{profile?.email ?? user?.email}</dd>
              </div>
              <div className="flex justify-between border-b border-slate-50 pb-2">
                <dt className="text-slate-500">Status</dt>
                <dd>
                  {(profile?.is_active ?? user?.is_active)
                    ? <Badge tone="green">Aktif</Badge>
                    : <Badge tone="red">Nonaktif</Badge>}
                </dd>
              </div>
            </dl>
          </CardBody>
        </Card>

        <Card>
          <CardBody>
            <h3 className="mb-3 text-base font-semibold text-slate-800">Role / Hak Akses</h3>
            <div className="flex flex-wrap gap-2">
              {(profile?.roles ?? user?.roles ?? []).map((r) => (
                <Badge key={r} tone="brand">{r}</Badge>
              ))}
              {(profile?.roles ?? user?.roles ?? []).length === 0 && (
                <p className="text-sm text-slate-400">Tidak ada role.</p>
              )}
            </div>
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
