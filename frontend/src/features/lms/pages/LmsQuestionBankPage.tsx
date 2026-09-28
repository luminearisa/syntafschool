import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { QuestionEditor } from '@/features/lms/components/activities/manage/QuestionEditor';
import { activityApi } from '@/features/lms/api/activityApi';
import { lmsApi } from '@/features/lms/api/lmsApi';
import type { QuestionBank, QuestionBankTree, QuestionPayload } from '@/features/lms/types.phase2';
import { Copy, FolderPlus, Plus, Trash2 } from 'lucide-react';

/**
 * Bank soal: kategori bertingkat, soal reusable, dan versioning (§14–§22).
 *
 * Bank dapat dimiliki sebuah course atau institusi (lintas course) sehingga
 * guru tidak menulis ulang soal yang sama untuk setiap kelas.
 */
export function LmsQuestionBankPage() {
  const [params] = useSearchParams();
  const courseId = params.get('course_id') ? Number(params.get('course_id')) : undefined;

  const [banks, setBanks] = useState<QuestionBank[]>([]);
  const [activeBank, setActiveBank] = useState<number | null>(null);
  const [tree, setTree] = useState<QuestionBankTree | null>(null);
  const [questions, setQuestions] = useState<QuestionPayload[]>([]);
  const [categoryFilter, setCategoryFilter] = useState<number | ''>('');
  const [editing, setEditing] = useState<QuestionPayload | null>(null);
  const [showEditor, setShowEditor] = useState(false);
  const [showBankModal, setShowBankModal] = useState(false);
  const [showCategoryModal, setShowCategoryModal] = useState(false);
  const [bankForm, setBankForm] = useState({ name: '', description: '', course_id: courseId ?? '', visibility: 'COURSE' });
  const [categoryForm, setCategoryForm] = useState({ name: '', parent_id: '' });
  const [courses, setCourses] = useState<Array<{ id: number; name: string }>>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadBanks = useCallback(async () => {
    const payload = await activityApi.questionBanks(courseId ? { course_id: courseId } : {});
    setBanks(payload);
    if (payload.length > 0 && activeBank === null) {
      setActiveBank(payload[0].id);
    }
  }, [courseId, activeBank]);

  const loadBank = useCallback(async (bankId: number) => {
    const [treePayload, questionPage] = await Promise.all([
      activityApi.questionBank(bankId),
      activityApi.questions({
        bank_id: bankId,
        category_id: categoryFilter === '' ? undefined : categoryFilter,
      }),
    ]);
    setTree(treePayload);
    setQuestions(questionPage.data);
  }, [categoryFilter]);

  useEffect(() => {
    setLoading(true);
    void loadBanks()
      .catch(() => undefined)
      .finally(() => setLoading(false));

    // Daftar course guru untuk memilih pemilik bank soal.
    void lmsApi
      .courses({ per_page: 100, mine: true })
      .then((page) => setCourses(page.data.map((course) => ({ id: course.id, name: course.name }))))
      .catch(() => undefined);
  }, [loadBanks]);

  useEffect(() => {
    if (activeBank !== null) {
      void loadBank(activeBank).catch(() => undefined);
    }
  }, [activeBank, loadBank]);

  async function refresh() {
    if (activeBank !== null) await loadBank(activeBank);
    await loadBanks();
  }

  function flattenCategories(): Array<{ id: number; name: string; depth: number }> {
    const output: Array<{ id: number; name: string; depth: number }> = [];

    const walk = (nodes: QuestionBankTree['categories'], depth: number) => {
      nodes.forEach((node) => {
        output.push({ id: node.id, name: node.name, depth });
        walk(node.children, depth + 1);
      });
    };

    if (tree) walk(tree.categories, 0);

    return output;
  }

  if (loading) {
    return (
      <div className="flex justify-center py-16">
        <Spinner />
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Bank Soal</h1>
          <p className="text-sm text-slate-600">Soal dapat dipakai ulang lintas kuis; setiap perubahan menyimpan versi baru.</p>
        </div>
        <Button type="button" size="sm" onClick={() => setShowBankModal(true)}>
          <Plus className="mr-1 h-4 w-4" /> Bank soal baru
        </Button>
      </div>

      {banks.length === 0 ? (
        <EmptyState title="Belum ada bank soal" description="Buat bank soal untuk mulai menyusun pertanyaan yang dapat dipakai berulang." />
      ) : (
        <div className="grid gap-4 lg:grid-cols-[280px_1fr]">
          <Card className="space-y-2 p-3">
            {banks.map((bank) => (
              <button
                key={bank.id}
                type="button"
                onClick={() => setActiveBank(bank.id)}
                className={
                  activeBank === bank.id
                    ? 'w-full rounded-md border border-brand-300 bg-brand-50 p-2 text-left text-sm text-brand-800'
                    : 'w-full rounded-md border border-slate-200 p-2 text-left text-sm text-slate-700 hover:border-brand-300'
                }
              >
                <p className="font-medium">{bank.name}</p>
                <p className="text-xs text-slate-500">
                  {bank.questions_count} soal · {bank.categories_count} kategori
                  {bank.course_id === null && ' · institusi'}
                </p>
              </button>
            ))}
          </Card>

          <div className="space-y-4">
            {tree && (
              <Card className="space-y-3 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <h2 className="text-base font-semibold text-slate-900">{tree.bank.name}</h2>
                    <p className="text-xs text-slate-500">{tree.bank.questions_count} soal pada bank ini</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={() => setShowCategoryModal(true)}>
                      <FolderPlus className="mr-1 h-4 w-4" /> Kategori
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      onClick={() => {
                        setEditing(null);
                        setShowEditor(true);
                      }}
                    >
                      <Plus className="mr-1 h-4 w-4" /> Soal baru
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => void activityApi.deleteQuestionBank(tree.bank.id).then(refresh).catch(() => setError('Bank soal tidak dapat dihapus.'))}
                    >
                      <Trash2 className="h-4 w-4 text-red-500" />
                    </Button>
                  </div>
                </div>

                <div className="flex flex-wrap gap-1">
                  <button
                    type="button"
                    onClick={() => setCategoryFilter('')}
                    className={categoryFilter === '' ? 'rounded-full bg-brand-100 px-2 py-1 text-xs text-brand-800' : 'rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600'}
                  >
                    Semua kategori
                  </button>
                  {flattenCategories().map((category) => (
                    <button
                      key={category.id}
                      type="button"
                      onClick={() => setCategoryFilter(category.id)}
                      className={
                        categoryFilter === category.id
                          ? 'rounded-full bg-brand-100 px-2 py-1 text-xs text-brand-800'
                          : 'rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600'
                      }
                    >
                      {category.depth > 0 ? '↳ ' : ''}
                      {category.name}
                    </button>
                  ))}
                </div>
              </Card>
            )}

            {showEditor && (
              <QuestionEditor
                bankId={activeBank}
                categoryId={categoryFilter === '' ? null : categoryFilter}
                courseId={courseId ?? tree?.bank.course_id ?? null}
                existing={editing}
                onSaved={() => {
                  setShowEditor(false);
                  setEditing(null);
                  void refresh();
                }}
                onCancel={() => {
                  setShowEditor(false);
                  setEditing(null);
                }}
              />
            )}

            <div className="space-y-2">
              {questions.length === 0 && <p className="text-sm text-slate-500">Belum ada soal pada filter ini.</p>}
              {questions.map((question) => (
                <Card key={question.id} className="space-y-2 p-3">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="text-sm font-medium text-slate-800">{question.name}</p>
                      <p className="mt-1 text-sm text-slate-600">{question.question_text}</p>
                      <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <Badge tone="gray">{question.question_type_label}</Badge>
                        <span>{question.default_mark} mark</span>
                        <span>v{question.version}</span>
                        {question.category_id && <span>kategori #{question.category_id}</span>}
                      </div>
                    </div>
                    <div className="flex items-center gap-1">
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => {
                          setEditing(question);
                          setShowEditor(true);
                        }}
                      >
                        Ubah
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => void activityApi.duplicateQuestion(question.id).then(refresh)}
                        title="Duplikasi soal"
                      >
                        <Copy className="h-4 w-4" />
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => void activityApi.deleteQuestion(question.id).then(refresh)}
                        title="Hapus / arsipkan soal"
                      >
                        <Trash2 className="h-4 w-4 text-red-500" />
                      </Button>
                    </div>
                  </div>
                </Card>
              ))}
            </div>

            {error && <p className="text-sm text-red-600">{error}</p>}
          </div>
        </div>
      )}

      <Modal open={showBankModal} onClose={() => setShowBankModal(false)} title="Bank soal baru">
        <div className="space-y-3">
          <Input value={bankForm.name} onChange={(event) => setBankForm((prev) => ({ ...prev, name: event.target.value }))} placeholder="Nama bank soal" />
          <Input
            value={bankForm.description}
            onChange={(event) => setBankForm((prev) => ({ ...prev, description: event.target.value }))}
            placeholder="Deskripsi (opsional)"
          />
          <Select value={String(bankForm.course_id)} onChange={(event) => setBankForm((prev) => ({ ...prev, course_id: event.target.value }))}>
            <option value="">Bank institusi (lintas course)</option>
            {courses.map((course) => (
              <option key={course.id} value={course.id}>
                {course.name}
              </option>
            ))}
          </Select>
          <Button
            type="button"
            size="sm"
            disabled={bankForm.name.trim() === ''}
            onClick={() =>
              void activityApi
                .createQuestionBank({
                  name: bankForm.name,
                  description: bankForm.description || undefined,
                  course_id: bankForm.course_id === '' ? null : Number(bankForm.course_id),
                  visibility: bankForm.course_id === '' ? 'INSTITUTION' : 'COURSE',
                })
                .then(async (created) => {
                  setShowBankModal(false);
                  setBankForm({ name: '', description: '', course_id: courseId ?? '', visibility: 'COURSE' });
                  setActiveBank(created.id);
                  await loadBanks();
                })
                .catch(() => setError('Bank soal gagal dibuat.'))
            }
          >
            Simpan bank soal
          </Button>
        </div>
      </Modal>

      <Modal open={showCategoryModal} onClose={() => setShowCategoryModal(false)} title="Kategori baru">
        <div className="space-y-3">
          <Input value={categoryForm.name} onChange={(event) => setCategoryForm((prev) => ({ ...prev, name: event.target.value }))} placeholder="Nama kategori" />
          <Select value={String(categoryForm.parent_id)} onChange={(event) => setCategoryForm((prev) => ({ ...prev, parent_id: event.target.value }))}>
            <option value="">Kategori utama</option>
            {flattenCategories().map((category) => (
              <option key={category.id} value={category.id}>
                {category.depth > 0 ? '↳ ' : ''}
                {category.name}
              </option>
            ))}
          </Select>
          <Button
            type="button"
            size="sm"
            disabled={categoryForm.name.trim() === '' || activeBank === null}
            onClick={() =>
              void activityApi
                .createQuestionCategory(activeBank as number, {
                  name: categoryForm.name,
                  parent_id: categoryForm.parent_id === '' ? null : Number(categoryForm.parent_id),
                })
                .then(async () => {
                  setShowCategoryModal(false);
                  setCategoryForm({ name: '', parent_id: '' });
                  await refresh();
                })
                .catch(() => setError('Kategori gagal dibuat.'))
            }
          >
            Simpan kategori
          </Button>
        </div>
      </Modal>

    </div>
  );
}
