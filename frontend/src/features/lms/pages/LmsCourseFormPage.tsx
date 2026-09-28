import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { lmsApi } from '../api/lmsApi';
import { CourseFormWizardContainer } from '../components/CourseFormWizard';
import type { CourseFormPayload } from '../types';

/**
 * Halaman pembuatan/penyuntingan course (§7).
 *
 * Mendukung deep-link dari dashboard guru: `?subject=&class=&year=` mengisi
 * kaitan SIAKAD lebih dulu sehingga membuat course untuk mapel yang diampu
 * hanya butuh beberapa klik.
 */
export default function LmsCourseFormPage() {
  const { courseId } = useParams<{ courseId: string }>();
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const isEdit = Boolean(courseId);

  const course = useQuery({
    queryKey: ['lms', 'course', courseId],
    queryFn: () => lmsApi.course(Number(courseId)),
    enabled: isEdit,
  });

  const initialValues: Partial<CourseFormPayload> = {
    academic_course_id: params.get('subject') ? Number(params.get('subject')) : null,
    class_id: params.get('class') ? Number(params.get('class')) : null,
    academic_year_id: params.get('year') ? Number(params.get('year')) : null,
  };

  if (isEdit && course.isLoading) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-sm text-slate-500">
        <Spinner className="h-6 w-6" /> Memuat course…
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <header className="flex items-start gap-3">
        <Button variant="ghost" size="icon" aria-label="Kembali" onClick={() => navigate(-1)}>
          <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        </Button>
        <div>
          <h1 className="text-lg font-semibold text-slate-900">
            {isEdit ? 'Sunting course' : 'Course baru'}
          </h1>
          <p className="text-sm text-slate-500">
            {isEdit
              ? 'Perubahan tersimpan langsung; penerbitan diatur terpisah dari halaman kelola.'
              : 'Ikuti langkah berikut: identitas → kaitan SIAKAD → pembelajaran → publikasi.'}
          </p>
        </div>
      </header>

      <CourseFormWizardContainer
        course={isEdit ? course.data?.course : undefined}
        initialValues={initialValues}
        onSaved={(saved) => navigate(`/lms/courses/${saved.id}/edit`)}
        onCancel={() => navigate(-1)}
      />
    </div>
  );
}
