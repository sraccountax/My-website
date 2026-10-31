/* R174: applies the platform owner's Site & Release Settings to the public pages.
   - wording that mentions the beta (elements with data-site-text) is replaced or hidden;
   - a policy page (main[data-policy]) shows the published version when there is one; otherwise the page's own text
     stays as it is.
   Policy text is a small Markdown subset rendered with textContent only: owner text is never treated as HTML. */
(() => {
  'use strict';
  const SAFE_URL = /^(?:https:\/\/|mailto:|\/(?!\/))/i;

  function inline(target, text) {
    // **bold** and [label](url); everything else is plain text.
    const pattern = /\*\*([^*]+)\*\*|\[([^\]]+)\]\(([^)\s]+)\)/g;
    let last = 0, m;
    while ((m = pattern.exec(text))) {
      if (m.index > last) target.append(text.slice(last, m.index));
      if (m[1] !== undefined) {
        const b = document.createElement('b'); b.textContent = m[1]; target.append(b);
      } else if (SAFE_URL.test(m[3])) {
        const a = document.createElement('a'); a.href = m[3]; a.textContent = m[2];
        if (/^https:/i.test(m[3])) { a.rel = 'noopener noreferrer'; a.target = '_blank'; }
        target.append(a);
      } else {
        target.append(m[0]);
      }
      last = pattern.lastIndex;
    }
    if (last < text.length) target.append(text.slice(last));
  }

  /** Markdown subset → DocumentFragment: "## " sections, "### " subheadings, "- " / "1. " lists, paragraphs. */
  function render(markdown, {sections = true} = {}) {
    const root = document.createDocumentFragment();
    let block = sections ? null : root, para = null, list = null;
    const container = () => {
      if (!block) { block = document.createElement('section'); root.append(block); }
      return block;
    };
    const endPara = () => { para = null; };
    const endList = () => { list = null; };
    for (const raw of String(markdown || '').split('\n')) {
      const line = raw.replace(/\s+$/, '');
      let m;
      if (!line.trim()) { endPara(); endList(); continue; }
      if ((m = /^##\s+(.*)$/.exec(line)) && !line.startsWith('###')) {
        endPara(); endList();
        if (sections) { block = document.createElement('section'); root.append(block); }
        const h = document.createElement('h2'); inline(h, m[1]); (sections ? block : root).append(h);
        continue;
      }
      if ((m = /^###\s+(.*)$/.exec(line))) {
        endPara(); endList();
        const h = document.createElement('h3'); inline(h, m[1]); container().append(h);
        continue;
      }
      if ((m = /^\s*(?:[-*]|(\d+)[.)])\s+(.*)$/.exec(line))) {
        endPara();
        const kind = m[1] ? 'ol' : 'ul';
        if (!list || list.tagName.toLowerCase() !== kind) { list = document.createElement(kind); container().append(list); }
        const li = document.createElement('li'); inline(li, m[2]); list.append(li);
        continue;
      }
      endList();
      if (!para) { para = document.createElement('p'); container().append(para); }
      else para.append(' ');
      inline(para, line.trim());
    }
    return root;
  }

  function setText(el, text) {
    // Keep decorative children such as the arrow in a button; replace the first text node.
    const node = [...el.childNodes].find(n => n.nodeType === 3 && n.textContent.trim());
    if (node && el.children.length) node.textContent = text;
    else el.textContent = text;
  }

  function applySiteText(siteText) {
    for (const [key, item] of Object.entries(siteText || {})) {
      const els = document.querySelectorAll(`[data-site-text="${CSS.escape(key)}"]`);
      els.forEach(el => {
        if (item.hidden) { el.hidden = true; el.style.display = 'none'; return; }
        if (!item.text) return;
        if (key === 'pricing_body') {
          document.querySelectorAll('[data-site-text-part="pricing_body"]').forEach(p => p.remove());
          const lines = item.text.split('\n').map(s => s.trim()).filter(Boolean);
          el.textContent = lines[0] || '';
          let after = el;
          lines.slice(1).forEach(line => { const p = document.createElement('p'); p.textContent = line; after.after(p); after = p; });
          return;
        }
        setText(el, item.text);
      });
      if (item.hidden && key === 'pricing_body') document.querySelectorAll('[data-site-text-part="pricing_body"]').forEach(p => p.remove());
    }
  }

  function formatDate(iso) {
    const d = new Date(iso + 'T12:00:00');
    return isNaN(d) ? iso : d.toLocaleDateString('en-CA', {year: 'numeric', month: 'long', day: 'numeric'});
  }

  function showPolicy(main, policy) {
    const contact = main.querySelector('.legal-meta a[href^="mailto:"]')?.cloneNode(true);
    const eyebrowText = main.querySelector('.page-intro .eyebrow')?.textContent.trim() || 'Policy';
    const nodes = [];
    if (policy.reviewBanner) {
      const banner = document.createElement('aside'); banner.className = 'draft-banner'; banner.setAttribute('role', 'note');
      const b = document.createElement('b'); b.textContent = `Review draft · version ${policy.version}`;
      const span = document.createElement('span'); span.textContent = policy.bannerText || 'Draft for review. Not yet final.';
      banner.append(b, span); nodes.push(banner);
    }
    const intro = document.createElement('section'); intro.className = 'page-intro wrap';
    const eyebrow = document.createElement('span'); eyebrow.className = 'eyebrow';
    eyebrow.textContent = policy.reviewBanner ? eyebrowText : 'Policy';
    const h1 = document.createElement('h1'); h1.textContent = policy.title;
    intro.append(eyebrow, h1);
    if (policy.lead) { const lead = document.createElement('p'); lead.className = 'lead'; lead.textContent = policy.lead; intro.append(lead); }
    nodes.push(intro);
    const article = document.createElement('article'); article.className = 'legal wrap';
    const meta = document.createElement('p'); meta.className = 'legal-meta';
    const label = t => { const b = document.createElement('b'); b.textContent = t; return b; };
    meta.append(label('Effective date:'), ` ${formatDate(policy.effectiveDate)} · `, label('Version:'), ` ${policy.version}`);
    if (contact) meta.append(' · ', label('Contact:'), ' ', contact);
    article.append(meta, render(policy.body));
    nodes.push(article);
    main.replaceChildren(...nodes);
    main.dataset.policyVersion = policy.version;
    if (policy.title) document.title = `${policy.title} — TEGH`;
  }

  async function getJson(route) {
    const r = await fetch(`/api/index.php?route=${route}`, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
    if (!r.ok) throw new Error(String(r.status));
    return r.json();
  }

  async function start() {
    const main = document.querySelector('main[data-policy]');
    const tasks = [getJson('site/public').then(s => applySiteText(s.siteText)).catch(() => {})];
    if (main) {
      tasks.push(getJson(`site/policy&key=${encodeURIComponent(main.dataset.policy)}`)
        .then(r => { if (r.policy) showPolicy(main, r.policy); })
        .catch(() => {}));
    }
    await Promise.all(tasks);
    document.documentElement.dataset.siteSettings = 'applied';
  }

  window.TeghSiteText = Object.freeze({render});
  if (document.querySelector('[data-site-text], main[data-policy]')) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
  }
})();
