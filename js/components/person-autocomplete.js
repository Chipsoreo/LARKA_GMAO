/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms.
 */

/**
 * Larka — Composant partagé « PersonPicker »
 *
 * Fournit :
 *   - PersonPicker.avatarHtml(user, size)   → cercle photo (si PhotoUrl) sinon initiales
 *   - PersonPicker.pinHtml(user, opts)      → contenu HTML d'un marqueur de plan (Leaflet divIcon)
 *   - PersonPicker.mount(el, options)       → autocomplétion (barre de recherche + suggestions
 *                                              clavier/souris) écrivant l'Id choisi dans un input caché
 *
 * Utilisé côté gestionnaire (rattacher une personne à un point) ET côté demandeur
 * (rechercher / localiser une personne). Aucune dépendance externe.
 */
(function (global) {
  'use strict';

  const PALETTE = ['#2b7be6', '#1d9e75', '#d4537e', '#7f77dd', '#ba7517', '#0f9e9e', '#c0563f', '#5b8def'];

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function escAttr(s) { return esc(s).replace(/`/g, '&#96;'); }

  function fullName(u) { return ((u.Prenom || '') + ' ' + (u.Nom || '')).trim() || (u.Login || '?'); }
  function subLabel(u) {
    const bits = [];
    if (u.Poste) bits.push(u.Poste);
    if (u.Service) bits.push(u.Service);
    return bits.join(' · ');
  }
  function initials(u) {
    const a = (u.Prenom || '').charAt(0), b = (u.Nom || '').charAt(0);
    const s = (a + b) || (u.Nom || u.Login || '?').charAt(0);
    return s.toUpperCase().replace(/[^A-Z0-9À-Ý]/g, '') || '?';
  }
  function colorFor(u, override) {
    if (override) return override;
    const id = Number(u.Id) || 0;
    return PALETTE[id % PALETTE.length];
  }
  function haystack(u) { return (fullName(u) + ' ' + (u.Service || '') + ' ' + (u.Poste || '') + ' ' + (u.Email || '')).toLowerCase(); }

  // ── Avatar rond : photo (avec repli initiales) sinon initiales ───────────────
  function avatarHtml(u, size, colorOverride) {
    const s = size || 32, color = colorFor(u, colorOverride), ini = initials(u);
    const wrap = 'display:inline-flex;width:' + s + 'px;height:' + s + 'px;border-radius:50%;flex-shrink:0;'
      + 'align-items:center;justify-content:center;overflow:hidden;background:' + color
      + ';color:#fff;font-weight:500;font-size:' + Math.round(s * 0.4) + 'px';
    if (u.PhotoUrl) {
      const fb = "this.style.display='none';this.parentNode.textContent='" + ini + "'";
      return '<span style="' + wrap + '"><img src="' + escAttr(u.PhotoUrl) + '" alt="" '
        + 'style="width:100%;height:100%;object-fit:cover" onerror="' + fb + '"></span>';
    }
    return '<span style="' + wrap + '">' + ini + '</span>';
  }

  // ── Contenu d'un marqueur de plan (à passer à L.divIcon) ─────────────────────
  // opts: { selected:bool, color:string }. Anchoring: iconAnchor=[size/2, size+7].
  function pinHtml(u, opts) {
    opts = opts || {};
    const sel = !!opts.selected;
    const color = colorFor(u, opts.color);
    const scale = (opts.scale && opts.scale > 0) ? opts.scale : 1;
    const s = Math.round((sel ? 40 : 34) * scale);
    const ring = sel ? ('0 0 0 3px ' + color + ',0 2px 6px rgba(0,0,0,.35)') : ('0 0 0 2px #fff,0 1px 4px rgba(0,0,0,.3)');
    const label = sel
      ? '<div style="position:absolute;left:50%;top:-22px;transform:translateX(-50%);white-space:nowrap;'
        + 'background:#1f2937;color:#fff;font:500 11px/1 sans-serif;padding:3px 8px;border-radius:5px">' + esc(fullName(u)) + '</div>'
      : '';
    return '<div style="position:relative;width:' + s + 'px;height:' + s + 'px">'
      + label
      + '<div style="width:' + s + 'px;height:' + s + 'px;border-radius:50%;box-shadow:' + ring + ';overflow:hidden;'
      + 'background:' + color + ';color:#fff;display:flex;align-items:center;justify-content:center;'
      + 'font-weight:500;font-size:' + Math.round(s * 0.4) + 'px">'
      + (u.PhotoUrl
        ? '<img src="' + escAttr(u.PhotoUrl) + '" alt="" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display=\'none\';this.parentNode.textContent=\'' + initials(u) + '\'">'
        : initials(u))
      + '</div>'
      + '<div style="position:absolute;left:50%;bottom:-6px;transform:translateX(-50%);width:0;height:0;'
      + 'border-left:5px solid transparent;border-right:5px solid transparent;border-top:7px solid ' + color + '"></div>'
      + '</div>';
  }

  // ── Autocomplétion ───────────────────────────────────────────────────────────
  // options: { users:[], selectedId, hiddenInputId, placeholder, onSelect(user|null) }
  function mount(el, options) {
    if (!el) return;
    const users = (options.users || []).slice();
    const placeholder = options.placeholder || 'Rechercher une personne…';
    const hiddenId = options.hiddenInputId;
    let sel = options.selectedId ? users.find(u => String(u.Id) === String(options.selectedId)) : null;
    let matches = [], active = -1, open = false;

    el.innerHTML =
      '<div class="pp-wrap" style="position:relative">'
      + '<div class="pp-field" style="display:flex;align-items:center;gap:8px;border:1px solid var(--gray-border);border-radius:8px;background:var(--input-bg,#fff);padding:0 10px;min-height:38px">'
      + '<span class="pp-lead" style="display:flex;align-items:center;color:var(--gray-text)"></span>'
      + '<input class="pp-input" autocomplete="off" placeholder="' + escAttr(placeholder) + '" '
      + 'style="flex:1;border:none;outline:none;background:transparent;font-size:14px;min-height:36px;color:inherit">'
      + '<button type="button" class="pp-clear" title="Effacer" style="border:none;background:transparent;cursor:pointer;color:var(--gray-text);display:none;padding:2px 4px">✕</button>'
      + '</div>'
      + '<div class="pp-drop" role="listbox" style="position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:60;'
      + 'background:var(--card-bg,#fff);border:1px solid var(--gray-border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);'
      + 'max-height:260px;overflow:auto;display:none"></div>'
      + '</div>';

    const input = el.querySelector('.pp-input');
    const drop = el.querySelector('.pp-drop');
    const lead = el.querySelector('.pp-lead');
    const clearBtn = el.querySelector('.pp-clear');

    function setHidden(id) { if (hiddenId) { const h = document.getElementById(hiddenId); if (h) h.value = id || ''; } }
    function hl(txt, q) {
      if (!q) return esc(txt);
      const i = txt.toLowerCase().indexOf(q);
      if (i < 0) return esc(txt);
      return esc(txt.slice(0, i)) + '<mark style="background:#fde68a;color:#7c2d12;border-radius:2px">' + esc(txt.slice(i, i + q.length)) + '</mark>' + esc(txt.slice(i + q.length));
    }
    function renderLead() {
      lead.innerHTML = sel ? avatarHtml(sel, 24) : '<span style="font-size:16px">🔍</span>';
    }
    function applySelected() {
      renderLead();
      if (sel) { input.value = fullName(sel); clearBtn.style.display = 'block'; }
      else { input.value = ''; clearBtn.style.display = 'none'; }
      setHidden(sel ? sel.Id : '');
    }
    function close() { open = false; drop.style.display = 'none'; active = -1; }
    function renderDrop(q) {
      matches = q ? users.filter(u => haystack(u).includes(q)).slice(0, 8) : [];
      if (!matches.length) { drop.style.display = 'none'; open = false; return; }
      open = true; drop.style.display = 'block';
      drop.innerHTML = matches.map((u, i) =>
        '<div class="pp-opt" data-i="' + i + '" role="option" style="display:flex;gap:10px;align-items:center;padding:7px 10px;cursor:pointer;'
        + (i ? 'border-top:1px solid var(--gray-border);' : '') + (i === active ? 'background:var(--gray-bg);' : '') + '">'
        + avatarHtml(u, 28)
        + '<div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:500">' + hl(fullName(u), q) + '</div>'
        + '<div style="font-size:12px;color:var(--gray-text)">' + hl(subLabel(u) || '', q) + '</div></div>'
        + '</div>'
      ).join('');
      Array.from(drop.querySelectorAll('.pp-opt')).forEach(node => {
        node.addEventListener('mouseenter', () => { active = +node.dataset.i; paintActive(); });
        node.addEventListener('mousedown', e => { e.preventDefault(); choose(matches[+node.dataset.i]); });
      });
    }
    function paintActive() {
      Array.from(drop.querySelectorAll('.pp-opt')).forEach(n =>
        n.style.background = (+n.dataset.i === active) ? 'var(--gray-bg)' : 'transparent');
    }
    function choose(u) {
      sel = u || null; applySelected(); close();
      if (typeof options.onSelect === 'function') options.onSelect(sel);
    }

    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      active = -1;
      if (!input.value) { clearBtn.style.display = 'none'; } else { clearBtn.style.display = 'block'; }
      renderDrop(q);
    });
    input.addEventListener('focus', () => { if (input.value.trim()) renderDrop(input.value.trim().toLowerCase()); });
    input.addEventListener('keydown', e => {
      if (!open) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, matches.length - 1); paintActive(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); paintActive(); }
      else if (e.key === 'Enter') { e.preventDefault(); const m = matches[active < 0 ? 0 : active]; if (m) choose(m); }
      else if (e.key === 'Escape') { close(); }
    });
    clearBtn.addEventListener('click', () => { choose(null); input.focus(); });
    document.addEventListener('mousedown', e => { if (!el.contains(e.target)) close(); });

    applySelected();
    return { get: () => sel, set: (id) => { sel = users.find(u => String(u.Id) === String(id)) || null; applySelected(); } };
  }

  global.PersonPicker = { avatarHtml, pinHtml, mount, colorFor, initials, fullName, subLabel };
})(window);
