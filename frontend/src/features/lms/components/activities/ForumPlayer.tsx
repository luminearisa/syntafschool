import { useCallback, useEffect, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { activityApi } from '@/features/lms/api/activityApi';
import { formatDate, relativeTime } from '@/features/lms/lib/lmsLabels';
import { sanitizeHtml } from '@/features/lms/lib/sanitizeHtml';
import type { ForumDiscussionView, ForumPostNode, ForumView } from '@/features/lms/types.phase2';
import { ArrowLeft, Bell, BellOff, Lock, MessageSquare, Pin, Plus, Reply, ShieldCheck } from 'lucide-react';

/** Forum diskusi (§32–§36). */
export function ForumPlayer({
  activityId,
  view,
  canManage,
  onChanged,
}: {
  activityId: number;
  view: ForumView;
  canManage: boolean;
  onChanged: () => void;
}) {
  const [selected, setSelected] = useState<number | null>(null);
  const [thread, setThread] = useState<ForumDiscussionView | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [title, setTitle] = useState('');
  const [content, setContent] = useState('');
  const [replyTo, setReplyTo] = useState<number | null>(null);
  const [replyContent, setReplyContent] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const loadThread = useCallback(async (discussionId: number) => {
    const payload = await activityApi.discussion(discussionId);
    setThread(payload);
  }, []);

  useEffect(() => {
    if (selected !== null) void loadThread(selected).catch(() => undefined);
  }, [selected, loadThread]);

  async function run(action: () => Promise<unknown>) {
    setBusy(true);
    setError(null);
    try {
      await action();
      onChanged();
      if (selected !== null) await loadThread(selected);
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Terjadi kesalahan.');
    } finally {
      setBusy(false);
    }
  }

  async function submitDiscussion() {
    await run(async () => {
      const created = await activityApi.createDiscussion(activityId, { title, content });
      setTitle('');
      setContent('');
      setShowForm(false);
      setSelected(created.id);
    });
  }

  async function submitReply() {
    if (selected === null) return;
    await run(async () => {
      await activityApi.createPost(selected, { content: replyContent, parent_id: replyTo });
      setReplyContent('');
      setReplyTo(null);
    });
  }

  // ------------------------------------------------------------- detail diskusi
  if (selected !== null && thread) {
    return (
      <div className="space-y-4">
        <button type="button" className="inline-flex items-center gap-1 text-sm text-brand-700" onClick={() => setSelected(null)}>
          <ArrowLeft className="h-4 w-4" /> Kembali ke daftar diskusi
        </button>

        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-base font-semibold text-slate-900">{thread.discussion.title}</h2>
            {thread.discussion.is_pinned && <Badge tone="amber">Ditempel</Badge>}
            {thread.discussion.is_locked && <Badge tone="red">Dikunci</Badge>}
            {thread.discussion.status === 'PENDING' && <Badge tone="amber">Menunggu moderasi</Badge>}
          </div>
          <p className="mt-1 text-xs text-slate-500">
            {thread.discussion.author} · {formatDate(thread.discussion.created_at, true)}
          </p>
          <div
            className="lms-prose mt-3 text-sm text-slate-700"
            dangerouslySetInnerHTML={{ __html: sanitizeHtml(thread.discussion.content) }}
          />
        </div>

        <div className="space-y-3">
          {thread.posts.length === 0 && <p className="text-sm text-slate-500">Belum ada balasan.</p>}
          {thread.posts.map((post) => (
            <PostItem
              key={post.id}
              post={post}
              canModerate={canManage}
              onReply={(postId) => setReplyTo(postId)}
              onModerate={(action) => void run(() => activityApi.moderateForum('post', post.id, action))}
            />
          ))}
        </div>

        {!thread.discussion.is_locked && (
          <div className="space-y-2 rounded-lg border border-slate-200 bg-white p-4">
            <p className="text-sm font-medium text-slate-800">
              {replyTo === null ? 'Tulis balasan' : `Membalas kiriman #${replyTo}`}
            </p>
            <Textarea rows={3} value={replyContent} onChange={(event) => setReplyContent(event.target.value)} placeholder="Tulis balasan…" />
            <div className="flex items-center gap-2">
              <Button type="button" size="sm" onClick={submitReply} disabled={busy || replyContent.trim() === ''}>
                <Reply className="mr-1 h-4 w-4" /> Kirim balasan
              </Button>
              {replyTo !== null && (
                <Button type="button" variant="ghost" size="sm" onClick={() => setReplyTo(null)}>
                  Batal
                </Button>
              )}
            </div>
          </div>
        )}

        {error && <p className="text-sm text-red-600">{error}</p>}
      </div>
    );
  }

  // -------------------------------------------------------------- daftar diskusi
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2 text-sm text-slate-600">
          <Badge tone="brand">{view.forum.forum_type_label}</Badge>
          {view.forum.moderation_enabled && <Badge tone="amber">Moderasi aktif</Badge>}
          {view.participation && (
            <span>
              Kontribusi Anda: <strong>{view.participation.contributions ?? view.participation.posts}</strong> (syarat{' '}
              {view.participation.required_posts})
            </span>
          )}
        </div>

        <div className="flex items-center gap-2">
          {view.forum.subscription_enabled && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={busy}
              onClick={() =>
                void run(() =>
                  view.subscription?.subscribed ? activityApi.unsubscribeForum(activityId) : activityApi.subscribeForum(activityId),
                )
              }
            >
              {view.subscription?.subscribed ? <BellOff className="mr-1 h-4 w-4" /> : <Bell className="mr-1 h-4 w-4" />}
              {view.subscription?.subscribed ? 'Berhenti berlangganan' : 'Berlangganan'}
            </Button>
          )}
          {view.can_start_discussion && (
            <Button type="button" size="sm" onClick={() => setShowForm((value) => !value)}>
              <Plus className="mr-1 h-4 w-4" /> Diskusi baru
            </Button>
          )}
        </div>
      </div>

      {showForm && (
        <div className="space-y-2 rounded-lg border border-slate-200 bg-white p-4">
          <Input value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Judul diskusi" />
          <Textarea rows={3} value={content} onChange={(event) => setContent(event.target.value)} placeholder="Isi diskusi…" />
          <Button type="button" size="sm" onClick={submitDiscussion} disabled={busy || title.trim() === '' || content.trim() === ''}>
            Kirim diskusi
          </Button>
          {view.forum.moderation_enabled && (
            <p className="text-xs text-amber-700">Diskusi akan tampil setelah disetujui guru.</p>
          )}
        </div>
      )}

      <div className="space-y-2">
        {view.discussions.length === 0 && <p className="text-sm text-slate-500">Belum ada diskusi pada forum ini.</p>}
        {view.discussions.map((discussion) => (
          <button
            key={discussion.id}
            type="button"
            onClick={() => setSelected(discussion.id)}
            className="flex w-full items-start justify-between gap-3 rounded-lg border border-slate-200 bg-white p-4 text-left hover:border-brand-300"
          >
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                {discussion.is_pinned && <Pin className="h-3.5 w-3.5 text-amber-500" />}
                {discussion.is_locked && <Lock className="h-3.5 w-3.5 text-red-500" />}
                <span className="truncate text-sm font-medium text-slate-900">{discussion.title}</span>
                {discussion.status === 'PENDING' && <Badge tone="amber">Menunggu moderasi</Badge>}
              </div>
              <p className="mt-1 line-clamp-2 text-xs text-slate-600">{sanitizeHtml(discussion.content)}</p>
              <p className="mt-1 text-xs text-slate-500">
                {discussion.author} · {relativeTime(discussion.last_post_at ?? discussion.created_at)}
              </p>
            </div>
            <div className="flex shrink-0 items-center gap-1 text-xs text-slate-500">
              <MessageSquare className="h-4 w-4" /> {discussion.posts_count}
            </div>
          </button>
        ))}
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}
    </div>
  );
}

function PostItem({
  post,
  canModerate,
  onReply,
  onModerate,
}: {
  post: ForumPostNode;
  canModerate: boolean;
  onReply: (postId: number) => void;
  onModerate: (action: 'approve' | 'hide' | 'delete') => void;
}) {
  return (
    <div className={post.depth > 1 ? 'ml-6 border-l border-slate-200 pl-4' : ''}>
      <div className="rounded-lg border border-slate-200 bg-white p-3">
        <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
          <span className="font-medium text-slate-700">{post.author}</span>
          {post.is_teacher_post && <Badge tone="brand">Guru</Badge>}
          {post.status === 'PENDING' && <Badge tone="amber">Menunggu moderasi</Badge>}
          {post.status === 'HIDDEN' && <Badge tone="red">Disembunyikan</Badge>}
          <span>· {formatDate(post.created_at, true)}</span>
          {post.edited_at && <span>· diedit</span>}
        </div>

        <div className="lms-prose mt-2 text-sm text-slate-700" dangerouslySetInnerHTML={{ __html: sanitizeHtml(post.content) }} />

        <div className="mt-2 flex items-center gap-3 text-xs">
          <button type="button" className="inline-flex items-center gap-1 text-brand-700" onClick={() => onReply(post.id)}>
            <Reply className="h-3.5 w-3.5" /> Balas
          </button>
          {canModerate && (
            <>
              <button type="button" className="inline-flex items-center gap-1 text-slate-600" onClick={() => onModerate('approve')}>
                <ShieldCheck className="h-3.5 w-3.5" /> Setujui
              </button>
              <button type="button" className="text-slate-600" onClick={() => onModerate('hide')}>
                Sembunyikan
              </button>
              <button type="button" className="text-red-600" onClick={() => onModerate('delete')}>
                Hapus
              </button>
            </>
          )}
        </div>
      </div>

      {post.replies.length > 0 && (
        <div className="mt-2 space-y-2">
          {post.replies.map((reply) => (
            <PostItem key={reply.id} post={reply} canModerate={canModerate} onReply={onReply} onModerate={onModerate} />
          ))}
        </div>
      )}
    </div>
  );
}
