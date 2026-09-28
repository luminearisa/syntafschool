import { useState } from 'react';
import { Bold, Code2, Heading2, Image as ImageIcon, Italic, Link2, List, ListOrdered, Quote, Table2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { sanitizeHtml } from '../lib/sanitizeHtml';

/**
 * Editor konten guru (§40).
 *
 * Textarea + toolbar penyisip yang bekerja pada mode teks: cukup untuk
 * heading, daftar, tabel, tautan, gambar, video embed, dan callout tanpa
 * membebani bundel dengan editor WYSIWYG penuh. Pratinjau di bawahnya memakai
 * pembersih HTML yang sama dengan halaman siswa, jadi guru melihat persis apa
 * yang akan dilihat siswa.
 */
export function ContentEditor({
  value,
  onChange,
  label = 'Isi materi',
  rows = 12,
  hint,
}: {
  value: string;
  onChange: (value: string) => void;
  label?: string;
  rows?: number;
  hint?: string;
}) {
  const [preview, setPreview] = useState(false);
  const [element, setElement] = useState<HTMLTextAreaElement | null>(null);

  const surround = (before: string, after = before, placeholder = '') => {
    if (!element) return;
    const start = element.selectionStart ?? value.length;
    const end = element.selectionEnd ?? value.length;
    const selected = value.slice(start, end) || placeholder;
    const next = `${value.slice(0, start)}${before}${selected}${after}${value.slice(end)}`;
    onChange(next);
    requestAnimationFrame(() => {
      element.focus();
      element.setSelectionRange(start + before.length, start + before.length + selected.length);
    });
  };

  const tools = [
    { icon: Heading2, title: 'Sub-judul', action: () => surround('<h2>', '</h2>', 'Sub-judul') },
    { icon: Bold, title: 'Tebal', action: () => surround('<strong>', '</strong>', 'teks tebal') },
    { icon: Italic, title: 'Miring', action: () => surround('<em>', '</em>', 'teks miring') },
    { icon: ListOrdered, title: 'Daftar bernomor', action: () => surround('<ol>\n  <li>', '</li>\n</ol>', 'item') },
    { icon: List, title: 'Daftar butir', action: () => surround('<ul>\n  <li>', '</li>\n</ul>', 'item') },
    { icon: Quote, title: 'Kutipan/callout', action: () => surround('<blockquote data-callout="info">', '</blockquote>', 'catatan penting') },
    { icon: Table2, title: 'Tabel', action: () => surround('<table>\n  <thead><tr><th>', '</th></tr></thead>\n  <tbody>\n    <tr><td>isi</td></tr>\n  </tbody>\n</table>', 'Judul') },
    { icon: Link2, title: 'Tautan', action: () => surround('<a href="https://">', '</a>', 'tautan') },
    { icon: ImageIcon, title: 'Gambar', action: () => surround('<img src="https://" alt="', '" />', 'deskripsi') },
    { icon: Code2, title: 'Blok kode', action: () => surround('<pre><code>', '</code></pre>', 'kode') },
  ];

  return (
    <div className="lms-content-editor space-y-2">
      <div className="flex items-center justify-between gap-2">
        <label className="text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="lms-content-editor">
          {label}
        </label>
        <Button type="button" size="sm" variant="ghost" onClick={() => setPreview((current) => !current)}>
          {preview ? 'Sunting' : 'Pratinjau'}
        </Button>
      </div>

      {preview ? (
        <div
          className="lms-rich-text min-h-[200px] rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700"
          // Konten sudah dibersihkan `sanitizeHtml` sebelum dirender (§40).
          dangerouslySetInnerHTML={{ __html: sanitizeHtml(value) }}
        />
      ) : (
        <>
          <div className="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1.5">
            {tools.map((tool) => (
              <button
                key={tool.title}
                type="button"
                title={tool.title}
                aria-label={tool.title}
                onClick={tool.action}
                className="rounded-md p-1.5 text-slate-600 transition hover:bg-white hover:text-brand-700"
              >
                <tool.icon className="h-4 w-4" aria-hidden="true" />
              </button>
            ))}
          </div>
          <textarea
            id="lms-content-editor"
            ref={setElement}
            value={value}
            rows={rows}
            onChange={(event) => onChange(event.target.value)}
            className="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono text-xs leading-relaxed text-slate-800 focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-100"
            placeholder="<h2>Judul materi</h2>&#10;<p>Tulis penjelasan di sini…</p>"
          />
          {hint && <p className="text-[11px] text-slate-500">{hint}</p>}
        </>
      )}
    </div>
  );
}
