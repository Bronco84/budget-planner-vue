import { Marked } from 'marked';
import DOMPurify from 'dompurify';

/*
 * Renders assistant chat replies. The model can echo prompt-injected text (a
 * Plaid memo, a merchant name, a note), so the HTML must never fetch anything on
 * its own: images render as their alt text, there are no embedded media or
 * inline styles (CSS url()), and links open only on click, in a new tab, with no
 * referrer.
 */

const escapeHtml = (text) => String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const markdown = new Marked({
    breaks: true, // Convert \n to <br>
    gfm: true, // GitHub Flavored Markdown
    renderer: {
        image({ text }) {
            return escapeHtml(text || '');
        },
    },
});

const ALLOWED_TAGS = [
    'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'del', 's', 'code', 'pre', 'blockquote',
    'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a',
    'table', 'thead', 'tbody', 'tr', 'th', 'td',
];

const ALLOWED_ATTR = ['href', 'title', 'start', 'align'];

let purifier = null;

function getPurifier() {
    if (purifier) {
        return purifier;
    }

    purifier = DOMPurify(window);
    purifier.addHook('afterSanitizeAttributes', (node) => {
        if (node.tagName === 'A') {
            node.setAttribute('target', '_blank');
            node.setAttribute('rel', 'noopener noreferrer nofollow');
        }
    });

    return purifier;
}

export function renderAssistantMarkdown(content) {
    const html = markdown.parse(content || '');

    return getPurifier().sanitize(html, {
        ALLOWED_TAGS,
        ALLOWED_ATTR,
        ALLOWED_URI_REGEXP: /^(?:https?:|mailto:|#)/i,
    });
}
