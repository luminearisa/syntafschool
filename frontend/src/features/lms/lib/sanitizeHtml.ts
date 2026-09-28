/**
 * Pembersih HTML sisi klien untuk konten guru (§40).
 *
 * Backend sudah membersihkan konten sebelum menyimpannya (allow-list tag,
 * atribut, dan skema URL). Fungsi ini adalah lapisan kedua: mencegah HTML yang
 * tak terduga — misalnya dari data lama — dieksekusi di browser siswa. Tidak
 * memakai `dangerouslySetInnerHTML` dengan string mentah.
 */

const ALLOWED_TAGS = new Set([
  'P', 'BR', 'HR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'DEL', 'MARK', 'SMALL', 'SUB', 'SUP',
  'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
  'UL', 'OL', 'LI', 'BLOCKQUOTE', 'PRE', 'CODE', 'FIGURE', 'FIGCAPTION', 'DIV', 'SPAN',
  'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TH', 'TD', 'CAPTION', 'COLGROUP', 'COL',
  'A', 'IMG', 'IFRAME', 'VIDEO', 'AUDIO', 'SOURCE', 'DETAILS', 'SUMMARY', 'ASIDE', 'SECTION',
]);

const ALLOWED_ATTRIBUTES = new Set([
  'href', 'title', 'target', 'rel',
  'src', 'alt', 'width', 'height', 'loading', 'allow', 'allowfullscreen', 'frameborder', 'controls',
  'colspan', 'rowspan', 'scope', 'class', 'style', 'id', 'data-callout', 'data-align',
]);

const ALLOWED_EMBED_HOSTS = [
  'www.youtube.com', 'youtube.com', 'youtu.be', 'player.vimeo.com', 'vimeo.com',
  'www.google.com', 'docs.google.com', 'drive.google.com',
];

export function sanitizeHtml(html: string | null | undefined): string {
  if (!html) return '';
  if (typeof window === 'undefined' || typeof DOMParser === 'undefined') return '';

  const document_ = new DOMParser().parseFromString(html, 'text/html');
  const body = document_.body;
  sanitizeNode(body);
  return body.innerHTML;
}

function sanitizeNode(node: Element): void {
  Array.from(node.children).forEach((child) => {
    const tag = child.tagName.toUpperCase();

    if (!ALLOWED_TAGS.has(tag)) {
      // Tag terlarang dibuang, isinya dipertahankan agar teks tidak hilang.
      unwrap(child);
      return;
    }

    sanitizeAttributes(child, tag);
    sanitizeNode(child);
  });
}

function sanitizeAttributes(element: Element, tag: string): void {
  Array.from(element.attributes).forEach((attribute) => {
    const name = attribute.name.toLowerCase();
    const value = attribute.value;

    if (name.startsWith('on') || !ALLOWED_ATTRIBUTES.has(name)) {
      element.removeAttribute(attribute.name);
      return;
    }

    if (name === 'style') {
      const safe = value
        .split(';')
        .filter((declaration) => !/expression|url\(|javascript:/i.test(declaration))
        .join(';');
      element.setAttribute('style', safe);
      return;
    }

    if ((name === 'href' || name === 'src') && !isSafeUrl(value)) {
      element.removeAttribute(name);
    }
  });

  if (tag === 'A' && element.getAttribute('target') === '_blank') {
    element.setAttribute('rel', 'noopener noreferrer');
  }

  if (tag === 'IFRAME') {
    const host = safeHost(element.getAttribute('src'));
    if (!host || !ALLOWED_EMBED_HOSTS.includes(host)) {
      unwrap(element);
    }
  }
}

function isSafeUrl(url: string): boolean {
  const trimmed = url.trim();
  if (trimmed === '' || trimmed.startsWith('#')) return true;
  if (/^https?:\/\//i.test(trimmed)) return true;
  return /^(mailto:|tel:|\/)/i.test(trimmed);
}

function safeHost(url: string | null): string | null {
  if (!url) return null;
  try {
    return new URL(url, window.location.origin).host.toLowerCase();
  } catch {
    return null;
  }
}

function unwrap(element: Element): void {
  const parent = element.parentNode;
  if (!parent) return;
  while (element.firstChild) parent.insertBefore(element.firstChild, element);
  parent.removeChild(element);
}

/** Ringkasan teks untuk kartu/kutipan pengumuman. */
export function htmlToText(html: string | null | undefined, limit = 180): string {
  if (!html) return '';
  const text = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  return text.length > limit ? `${text.slice(0, limit)}…` : text;
}
