/*
 * Tegh Sidebar: a collapsible, keyboard-friendly navigation sidebar with no dependencies.
 *
 *   const sidebar = TeghSidebar.mount('#sidebar', {
 *     sections: [{ label: 'Workspace', items: [{ id: 'dashboard', label: 'Dashboard', icon: 'dashboard', href: '#dashboard' }] }],
 *     active: 'dashboard',
 *     onNavigate: (id, item, event) => {},     // return false to stop the sidebar marking it active
 *     onCollapse: collapsed => {}              // save the choice (Tegh: agent/interface-preferences)
 *   });
 *
 * Desktop: full width or icon rail ([ key or the chevron button). Phones (below `breakpoint`): slide-in drawer.
 * Needs tegh-sidebar.css.
 */
(function (global) {
  'use strict';

  // Line icons, 24×24, drawn with a 1.8 stroke.
  const ICONS = {
    dashboard: 'M4 13h7V4H4zM13 20h7v-9h-7zM13 4v5h7V4zM4 20h7v-5H4z',
    banking: 'M3 10h18M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18M12 3l9 5H3z',
    receivables: 'M12 19V5M5 12l7-7 7 7',
    payables: 'M12 5v14M5 12l7 7 7-7',
    ledger: 'M5 4h11l3 3v13H5zM9 10h6M9 14h6',
    payroll: 'M16 20v-2a4 4 0 0 0-8 0v2M12 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM20 20v-1.5a3 3 0 0 0-2.2-2.9M16.5 5.2a3 3 0 0 1 0 5.6',
    reports: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
    tax: 'M19 5 5 19M7.5 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM16.5 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
    assist: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z',
    documents: 'M6 3h9l3 3v15H6zM9 12h6M9 16h4',
    settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19 12a7 7 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7 7 0 0 0-2-1.2L14 3h-4l-.5 2.6a7 7 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.6 2 3.4 2.4-1a7 7 0 0 0 2 1.2L10 21h4l.5-2.6a7 7 0 0 0 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2z',
    help: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01',
    search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4',
    chevron: 'm9 6 6 6-6 6',
    collapse: 'm11 17-5-5 5-5M18 17l-5-5 5-5',
    close: 'M18 6 6 18M6 6l12 12',
    updown: 'm7 15 5 5 5-5M7 9l5-5 5 5'
  };
  const NS = 'http://www.w3.org/2000/svg';
  const icon = (name, size) => {
    const s = document.createElementNS(NS, 'svg');
    s.setAttribute('width', size || 20); s.setAttribute('height', size || 20); s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '1.8');
    s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
    const p = document.createElementNS(NS, 'path'); p.setAttribute('d', ICONS[name] || name || ''); s.appendChild(p);
    return s;
  };
  const el = (tag, cls, parent, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    if (parent) parent.appendChild(n);
    return n;
  };
  const store = {
    get(k) { try { const v = localStorage.getItem(k); return v === null ? null : v === '1'; } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v ? '1' : '0'); } catch (e) { /* storage blocked: keep it for this visit only */ } }
  };
  const reducedMotion = () => !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);
  let uid = 0;

  class Sidebar {
    constructor(target, options) {
      this.root = typeof target === 'string' ? document.querySelector(target) : target;
      if (!this.root) throw new Error('TeghSidebar: container not found: ' + target);
      this.o = Object.assign({
        brand: 'TEGH', tagline: 'General ledger hub', logo: 'T', breakpoint: 900, storageKey: 'tegh.sidebar.collapsed',
        sections: [], active: '', company: null, user: null, footerCard: null, search: true, main: null,
        onNavigate: null, onCollapse: null, onSearch: null, onCompany: null, onUser: null
      }, options);
      this.id = 'ts-' + (++uid);
      const saved = store.get(this.o.storageKey);
      this.collapsed = this.o.collapsed != null ? !!this.o.collapsed : !!saved;
      this.isOpen = false;
      this.items = new Map();
      this.render();
      this.bind();
      this.mq = global.matchMedia('(max-width: ' + this.o.breakpoint + 'px)');
      this._onMq = () => this.applyMode();
      this.mq.addEventListener ? this.mq.addEventListener('change', this._onMq) : this.mq.addListener(this._onMq);
      this.applyMode(true);
      this.setActive(this.o.active, true);
    }

    // ---------- Building ----------
    render() {
      const o = this.o, root = this.root;
      root.textContent = '';
      root.classList.add('ts');
      root.id = root.id || this.id;
      root.setAttribute('aria-label', 'Main menu');

      const top = el('div', 'ts-top', root);
      const brand = el('a', 'ts-brand', top);
      brand.href = o.brandHref || '#';
      brand.setAttribute('aria-label', o.brand + ' home');
      el('span', 'ts-logo', brand, o.logo).setAttribute('aria-hidden', 'true');
      const bt = el('span', 'ts-brand-text', brand);
      el('span', 'ts-brand-name', bt, o.brand);
      el('span', 'ts-brand-tag', bt, o.tagline);
      this.collapseBtn = el('button', 'ts-icon-btn ts-collapse', top);
      this.collapseBtn.type = 'button';
      this.collapseBtn.setAttribute('aria-controls', root.id);
      this.collapseBtn.appendChild(icon('collapse', 18));
      this.closeBtn = el('button', 'ts-icon-btn ts-close', top);
      this.closeBtn.type = 'button';
      this.closeBtn.setAttribute('aria-label', 'Close menu');
      this.closeBtn.appendChild(icon('close', 18));

      if (o.company) {
        const c = this.companyBtn = el('button', 'ts-company', root);
        c.type = 'button';
        c.dataset.tip = o.company.name;
        el('span', 'ts-avatar', c, o.company.initials || o.company.name.slice(0, 2).toUpperCase()).setAttribute('aria-hidden', 'true');
        el('span', 'ts-label', c, o.company.name);
        const ud = icon('updown', 16); ud.classList.add('ts-chev'); c.appendChild(ud);
        c.setAttribute('aria-label', 'Switch company. Current: ' + o.company.name);
      }
      if (o.search) {
        const s = this.searchBtn = el('button', 'ts-search', root);
        s.type = 'button';
        s.dataset.tip = 'Search (Ctrl K)';
        s.appendChild(icon('search', 18));
        el('span', 'ts-label', s, 'Search');
        el('span', 'ts-kbd', s, /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘K' : 'Ctrl K').setAttribute('aria-hidden', 'true');
      }

      this.nav = el('nav', 'ts-nav', root);
      this.nav.setAttribute('aria-label', 'Main');
      this.inner = el('div', 'ts-nav-inner', this.nav);
      this.indicator = el('span', 'ts-indicator', this.inner);
      this.indicator.setAttribute('aria-hidden', 'true');
      o.sections.forEach(sec => {
        const section = el('div', 'ts-section', this.inner);
        if (sec.label) el('p', 'ts-section-label', section, sec.label);
        const ul = el('ul', 'ts-list', section);
        sec.items.forEach(item => ul.appendChild(this.renderItem(item)));
      });

      const foot = el('div', 'ts-foot', root);
      if (o.footerCard) {
        const card = el('div', 'ts-card', foot);
        el('div', 'ts-card-eyebrow', card, o.footerCard.eyebrow);
        el('div', 'ts-card-title', card, o.footerCard.title);
        if (o.footerCard.progress != null) {
          const m = el('div', 'ts-meter', card);
          m.setAttribute('role', 'progressbar');
          m.setAttribute('aria-valuemin', '0'); m.setAttribute('aria-valuemax', '100');
          m.setAttribute('aria-valuenow', String(Math.round(o.footerCard.progress)));
          m.setAttribute('aria-label', o.footerCard.title);
          el('span', '', m).style.width = Math.max(0, Math.min(100, o.footerCard.progress)) + '%';
        }
      }
      if (o.user) {
        const u = this.userBtn = el('button', 'ts-user', foot);
        u.type = 'button';
        u.dataset.tip = o.user.name;
        el('span', 'ts-avatar', u, o.user.initials || o.user.name.split(' ').map(w => w[0]).join('').slice(0, 2)).setAttribute('aria-hidden', 'true');
        const ut = el('span', 'ts-user-text', u);
        el('span', '', ut, o.user.name);
        el('small', '', ut, o.user.role || '');
        u.setAttribute('aria-label', 'Account: ' + o.user.name);
      }

      this.tip = el('div', 'ts-tip', document.body);
      this.tip.setAttribute('aria-hidden', 'true');
      this.scrim = el('div', 'ts-scrim', document.body);
      this.scrim.hidden = true;
    }

    renderItem(item) {
      const li = el('li', '');
      const kids = item.children && item.children.length;
      const btn = el(kids ? 'button' : 'a', 'ts-item', li);
      btn.dataset.id = item.id;
      btn.dataset.tip = item.label;
      if (kids) {
        const subId = this.id + '-sub-' + item.id;
        btn.type = 'button';
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-controls', subId);
        li.className = 'ts-group';
        btn.appendChild(icon(item.icon));
        el('span', 'ts-label', btn, item.label);
        if (item.badge) this.badgeEl(btn, item.badge);
        const ch = icon('chevron', 16); ch.classList.add('ts-chev'); btn.appendChild(ch);
        const sub = el('div', 'ts-sub', li); sub.id = subId;
        const ul = el('ul', 'ts-list', el('div', 'ts-sub-inner', sub));
        item.children.forEach(child => {
          const cli = el('li', '', ul), a = el('a', 'ts-subitem', cli);
          a.href = child.href || '#' + child.id;
          a.dataset.id = child.id;
          el('span', 'ts-label', a, child.label);
          if (child.badge) this.badgeEl(a, child.badge);
          this.items.set(child.id, { item: child, el: a, parent: item.id });
        });
        this.items.set(item.id, { item, el: btn, group: li });
        sub.inert = true;
        if (item.open) this.setGroup(li, true, true);
      } else {
        btn.href = item.href || '#' + item.id;
        btn.appendChild(icon(item.icon));
        el('span', 'ts-label', btn, item.label);
        if (item.badge) this.badgeEl(btn, item.badge);
        this.items.set(item.id, { item, el: btn });
      }
      return li;
    }

    badgeEl(host, value) {
      const b = el('span', 'ts-badge', host, String(value));
      b.setAttribute('aria-label', value + ' to review');
      return b;
    }

    // ---------- Behaviour ----------
    bind() {
      this._click = e => {
        const t = e.target.closest('.ts-item, .ts-subitem');
        if (t && this.root.contains(t)) {
          const rec = this.items.get(t.dataset.id);
          if (rec && rec.group) { e.preventDefault(); this.collapsed && !this.mobile ? this.toggleFlyout(rec, t) : this.setGroup(rec.group, !rec.group.classList.contains('is-open')); return; }
          if (rec) this.navigate(rec, e);
          return;
        }
        if (e.target.closest('.ts-collapse')) this.collapse(!this.collapsed);
        else if (e.target.closest('.ts-close')) this.closeDrawer();
        else if (e.target.closest('.ts-search') && this.o.onSearch) this.o.onSearch();
        else if (e.target.closest('.ts-company') && this.o.onCompany) this.o.onCompany();
        else if (e.target.closest('.ts-user') && this.o.onUser) this.o.onUser();
      };
      this.root.addEventListener('click', this._click);

      // Rail tooltips: only when collapsed, on hover and keyboard focus.
      this._over = e => {
        const t = e.target.closest('[data-tip]');
        if (!t || !this.collapsed || this.mobile || this.flyout) return this.hideTip();
        const r = t.getBoundingClientRect();
        this.tip.textContent = t.dataset.tip;
        this.tip.style.left = (r.right + 12) + 'px';
        this.tip.style.top = (r.top + r.height / 2 - 15) + 'px';
        this.tip.classList.add('is-on');
      };
      this.root.addEventListener('pointerover', this._over);
      this.root.addEventListener('focusin', e => { if (e.target.matches(':focus-visible')) this._over(e); });
      this.root.addEventListener('pointerleave', () => this.hideTip());
      this.root.addEventListener('focusout', () => this.hideTip());
      this.nav.addEventListener('scroll', () => { this.hideTip(); this.closeFlyout(); }, { passive: true });

      // Arrow keys move between visible menu items; Right and Left open and close groups.
      this._navKey = e => {
        const subsShown = !this.collapsed || this.mobile;
        const list = [...this.nav.querySelectorAll('.ts-item, .ts-subitem')].filter(n => n.classList.contains('ts-item') || (subsShown && n.closest('.ts-group').classList.contains('is-open')));
        const i = list.indexOf(document.activeElement);
        const rec = document.activeElement && this.items.get(document.activeElement.dataset.id);
        let next = null;
        if (e.key === 'ArrowDown') next = list[Math.min(list.length - 1, i + 1)];
        else if (e.key === 'ArrowUp') next = list[Math.max(0, i - 1)];
        else if (e.key === 'Home') next = list[0];
        else if (e.key === 'End') next = list[list.length - 1];
        else if (e.key === 'ArrowRight' && rec && rec.group) { this.collapsed && !this.mobile ? this.toggleFlyout(rec, rec.el, true) : this.setGroup(rec.group, true); }
        else if (e.key === 'ArrowLeft' && rec) { const g = rec.group || (rec.parent && this.items.get(rec.parent).group); if (g) { this.setGroup(g, false); g.querySelector('.ts-item').focus(); } }
        else return;
        e.preventDefault();
        if (next) next.focus();
      };
      this.nav.addEventListener('keydown', this._navKey);

      // Global keys: [ toggles the rail, Ctrl/⌘ K opens search, Esc closes the drawer or flyout.
      this._docKey = e => {
        const typing = /INPUT|TEXTAREA|SELECT/.test(e.target.tagName) || e.target.isContentEditable;
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && this.o.search) { e.preventDefault(); this.o.onSearch ? this.o.onSearch() : this.searchBtn.focus(); }
        else if (e.key === '[' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey && !this.mobile) { e.preventDefault(); this.collapse(!this.collapsed); }
        else if (e.key === 'Escape') { if (this.flyout) { const back = this.flyoutFor; this.closeFlyout(); back && back.focus(); } else if (this.isOpen) this.closeDrawer(); }
        else if (e.key === 'Tab' && this.isOpen) this.trapFocus(e);
      };
      document.addEventListener('keydown', this._docKey);

      this._outside = e => { if (this.flyout && !this.flyout.contains(e.target) && !e.target.closest('.ts-group')) this.closeFlyout(); };
      document.addEventListener('pointerdown', this._outside);
      this.scrim.addEventListener('click', () => this.closeDrawer());

      // Swipe the drawer to the left to close it.
      let x0 = null;
      this.root.addEventListener('touchstart', e => { if (this.isOpen) x0 = e.touches[0].clientX; }, { passive: true });
      this.root.addEventListener('touchmove', e => { if (x0 != null && e.touches[0].clientX - x0 < -70) { x0 = null; this.closeDrawer(); } }, { passive: true });
      this.root.addEventListener('touchend', () => { x0 = null; });

      this._resize = () => this.moveIndicator(true);
      global.addEventListener('resize', this._resize);
      this.root.addEventListener('transitionend', e => { if (e.target === this.root || e.target.classList.contains('ts-sub')) this.moveIndicator(); });
    }

    navigate(rec, event) {
      const res = this.o.onNavigate ? this.o.onNavigate(rec.item.id, rec.item, event) : undefined;
      if (res === false) return;
      this.setActive(rec.item.id);
      this.closeFlyout();
      if (this.mobile) this.closeDrawer();
    }

    setActive(id, instant) {
      this.active = id;
      this.items.forEach(r => { r.el.removeAttribute('aria-current'); r.el.classList.remove('has-current'); });
      const rec = this.items.get(id);
      if (rec) {
        rec.el.setAttribute('aria-current', 'page');
        if (rec.parent) {
          const parent = this.items.get(rec.parent);
          parent.el.classList.add('has-current');
          if (!this.collapsed || this.mobile) this.setGroup(parent.group, true, instant);
        }
      }
      this.moveIndicator(instant);
      return this;
    }

    setGroup(li, open, instant) {
      li.classList.toggle('is-open', open);
      li.querySelector('.ts-item').setAttribute('aria-expanded', open ? 'true' : 'false');
      li.querySelector('.ts-sub').inert = !open;   // closed submenus are skipped by Tab
      this.trackIndicator(instant ? 0 : 320);
    }

    // The active pill slides to the current item; it follows the parent group when the submenu is hidden.
    moveIndicator(instant) {
      const rec = this.items.get(this.active);
      if (!rec) { this.indicator.style.opacity = '0'; return; }
      let target = rec.el;
      if (rec.parent) {
        const parent = this.items.get(rec.parent);
        if (!parent.group.classList.contains('is-open') || (this.collapsed && !this.mobile)) target = parent.el;
      }
      const base = this.inner.getBoundingClientRect(), r = target.getBoundingClientRect();
      if (!r.height) return;
      if (instant || reducedMotion()) this.indicator.style.transition = 'none';
      this.indicator.style.transform = 'translateY(' + (r.top - base.top) + 'px)';
      this.indicator.style.height = r.height + 'px';
      this.indicator.style.left = (r.left - base.left) + 'px';
      this.indicator.style.right = (base.right - r.right) + 'px';
      this.indicator.style.opacity = '1';
      if (instant || reducedMotion()) { void this.indicator.offsetWidth; this.indicator.style.transition = ''; }
    }
    trackIndicator(ms) {
      cancelAnimationFrame(this._track);
      const end = performance.now() + ms;
      const step = () => { this.moveIndicator(true); if (performance.now() < end) this._track = requestAnimationFrame(step); };
      step();
    }

    collapse(on) {
      if (this.mobile) return this;
      this.collapsed = !!on;
      this.closeFlyout(); this.hideTip();
      this.root.classList.toggle('is-collapsed', this.collapsed);
      this.collapseBtn.setAttribute('aria-expanded', this.collapsed ? 'false' : 'true');
      this.collapseBtn.setAttribute('aria-label', this.collapsed ? 'Expand sidebar' : 'Collapse sidebar');
      this.collapseBtn.dataset.tip = this.collapsed ? 'Expand ( [ )' : '';
      store.set(this.o.storageKey, this.collapsed);
      if (!this.collapsed) this.setActive(this.active, true);
      this.trackIndicator(340);
      if (this.o.onCollapse) this.o.onCollapse(this.collapsed);
      return this;
    }
    toggle() { return this.mobile ? (this.isOpen ? this.closeDrawer() : this.openDrawer()) : this.collapse(!this.collapsed); }

    // Collapsed rail: a group opens as a flyout beside the rail.
    toggleFlyout(rec, anchor, focusFirst) {
      if (this.flyout && this.flyoutFor === anchor) return this.closeFlyout();
      this.closeFlyout(); this.hideTip();
      const f = this.flyout = el('div', 'ts-flyout', document.body);
      f.setAttribute('role', 'group');
      f.setAttribute('aria-label', rec.item.label);
      el('p', 'ts-flyout-title', f, rec.item.label);
      const ul = el('ul', 'ts-list', f);
      rec.item.children.forEach(child => {
        const a = el('a', 'ts-subitem', el('li', '', ul), child.label);
        a.href = child.href || '#' + child.id;
        if (child.id === this.active) a.setAttribute('aria-current', 'page');
        a.addEventListener('click', e => this.navigate(this.items.get(child.id), e));
      });
      f.addEventListener('keydown', e => {
        const links = [...f.querySelectorAll('a')], i = links.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); links[Math.min(links.length - 1, i + 1)].focus(); }
        if (e.key === 'ArrowUp') { e.preventDefault(); links[Math.max(0, i - 1)].focus(); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); this.closeFlyout(); anchor.focus(); }
      });
      const r = anchor.getBoundingClientRect();
      f.style.left = (r.right + 12) + 'px';
      f.style.top = Math.max(8, Math.min(r.top - 8, innerHeight - f.offsetHeight - 8)) + 'px';
      this.flyoutFor = anchor;
      anchor.setAttribute('aria-expanded', 'true');
      if (focusFirst) f.querySelector('a').focus();
    }
    closeFlyout() {
      if (!this.flyout) return this;
      this.flyout.remove(); this.flyout = null;
      if (this.flyoutFor) this.flyoutFor.setAttribute('aria-expanded', 'false');
      this.flyoutFor = null;
      return this;
    }
    hideTip() { this.tip.classList.remove('is-on'); }

    // ---------- Phone drawer ----------
    applyMode(first) {
      this.mobile = this.mq.matches;
      this.root.classList.toggle('is-mobile', this.mobile);
      this.root.classList.toggle('is-collapsed', this.collapsed && !this.mobile);
      this.collapseBtn.setAttribute('aria-expanded', this.collapsed ? 'false' : 'true');
      this.collapseBtn.setAttribute('aria-label', this.collapsed ? 'Expand sidebar' : 'Collapse sidebar');
      if (!this.mobile && this.isOpen) this.closeDrawer(true);
      if (this.mobile) { this.root.setAttribute('aria-hidden', this.isOpen ? 'false' : 'true'); this.root.inert = !this.isOpen; }
      else { this.root.removeAttribute('aria-hidden'); this.root.inert = false; this.root.removeAttribute('role'); this.root.removeAttribute('aria-modal'); }
      this.closeFlyout();
      if (!first) this.trackIndicator(340);
    }
    openDrawer(opener) {
      if (!this.mobile) return this;
      this.returnFocus = opener || document.activeElement;
      this.isOpen = true;
      this.root.inert = false;
      this.root.setAttribute('aria-hidden', 'false');
      this.root.setAttribute('role', 'dialog');
      this.root.setAttribute('aria-modal', 'true');
      this.root.classList.add('is-open');
      this.scrim.hidden = false;
      requestAnimationFrame(() => this.scrim.classList.add('is-on'));
      const main = this.o.main && document.querySelector(this.o.main);
      if (main) main.inert = true;
      document.documentElement.style.overflow = 'hidden';
      setTimeout(() => { (this.root.querySelector('[aria-current="page"]') || this.root.querySelector('.ts-item')).focus(); this.moveIndicator(true); }, reducedMotion() ? 0 : 60);
      if (this.o.onDrawer) this.o.onDrawer(true);
      return this;
    }
    closeDrawer(silent) {
      if (!this.isOpen) return this;
      this.isOpen = false;
      this.root.classList.remove('is-open');
      this.root.removeAttribute('role');
      this.root.removeAttribute('aria-modal');
      if (this.mobile) { this.root.setAttribute('aria-hidden', 'true'); this.root.inert = true; }
      this.scrim.classList.remove('is-on');
      setTimeout(() => { if (!this.isOpen) this.scrim.hidden = true; }, reducedMotion() ? 0 : 300);
      const main = this.o.main && document.querySelector(this.o.main);
      if (main) main.inert = false;
      document.documentElement.style.overflow = '';
      if (!silent && this.returnFocus && this.returnFocus.focus) this.returnFocus.focus();
      if (this.o.onDrawer) this.o.onDrawer(false);
      return this;
    }
    trapFocus(e) {
      const f = [...this.root.querySelectorAll('a[href], button:not([disabled])')].filter(n => n.offsetParent);
      if (!f.length) return;
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    setBadge(id, value) {
      const rec = this.items.get(id);
      if (!rec) return this;
      const old = rec.el.querySelector('.ts-badge');
      if (old) old.remove();
      if (value) {
        const b = this.badgeEl(rec.el, value), chev = rec.el.querySelector('.ts-chev');
        if (chev) rec.el.insertBefore(b, chev);
      }
      return this;
    }

    destroy() {
      document.removeEventListener('keydown', this._docKey);
      document.removeEventListener('pointerdown', this._outside);
      global.removeEventListener('resize', this._resize);
      this.mq.removeEventListener ? this.mq.removeEventListener('change', this._onMq) : this.mq.removeListener(this._onMq);
      this.closeFlyout(); this.closeDrawer(true);
      this.tip.remove(); this.scrim.remove();
      this.root.textContent = '';
      this.root.className = this.root.className.replace(/\bts\b|\bis-\S+/g, '').trim();
    }
  }

  global.TeghSidebar = { mount: (target, options) => new Sidebar(target, options || {}), icons: ICONS };
})(window);
