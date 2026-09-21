import { Construction } from 'lucide-react';
import { Card, CardBody } from '@/components/ui/Card';

export default function ModulePlaceholderPage({ title }: { title: string }) {
  return (
    <Card>
      <CardBody className="flex flex-col items-center gap-3 py-16 text-center">
        <div className="rounded-full bg-amber-100 p-4 text-amber-600">
          <Construction className="h-8 w-8" />
        </div>
        <h2 className="text-lg font-semibold text-slate-800">{title}</h2>
        <p className="max-w-md text-sm text-slate-500">
          Modul ini sedang dalam pengembangan. Fondasi master data telah siap
          dan akan segera dilanjutkan pada fase berikutnya.
        </p>
      </CardBody>
    </Card>
  );
}
