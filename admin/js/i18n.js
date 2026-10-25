/* Admin translator.
   The admin is written in English. In Arabic, a MutationObserver translates every text node and the
   placeholder / title / aria-label attributes as screens render. Dictionary priority:
     1. the owner's edits (window.ADMIN_I18N_OVERRIDES, from /api/admin/i18n.js)
     2. the generated phrase dictionary (window.ADMIN_I18N, from admin/js/i18n-dict.js)
   Numbers inside a phrase are placeholders ("{0} orders") put back wrapped in LRI/PDI so they keep their order.
   Data is never translated: anything inside [data-noi18n], textarea, code, inputs or contenteditable. */
(function () {
  'use strict';
  var KEY = 'tz.admin.lang';
  var lang = 'en';
  try { lang = localStorage.getItem(KEY) === 'ar' ? 'ar' : 'en'; } catch (e) { /* private mode */ }
  var html = document.documentElement;
  html.lang = lang;
  html.dir = lang === 'ar' ? 'rtl' : 'ltr';

  var built = window.ADMIN_I18N || { phrases: {}, patterns: [] };
  var over = window.ADMIN_I18N_OVERRIDES || { phrases: {}, keep: [] };
  var dict = Object.assign({}, built.phrases || {});
  Object.keys(over.phrases || {}).forEach(function (en) { if (over.phrases[en].ar) dict[en] = over.phrases[en].ar; });
  var keep = {};
  (over.keep || []).forEach(function (en) { keep[en] = 1; });
  var patterns = (built.patterns || []).map(function (p) { return [new RegExp('^' + p[0] + '$'), p[1]]; });

  var LRI = '⁦', PDI = '⁩';
  var missing = {};
  var reported = {};
  var timer = null;

  function lookup(text) {
    if (keep[text]) return text;
    if (Object.prototype.hasOwnProperty.call(dict, text)) return dict[text];
    // numbers -> {0}, {1}
    var nums = [];
    var tpl = text.replace(/\d[\d,.]*(?:\.\d+)?/g, function (m) { nums.push(m); return '{' + (nums.length - 1) + '}'; });
    if (nums.length && Object.prototype.hasOwnProperty.call(dict, tpl)) {
      return dict[tpl].replace(/\{(\d+)\}/g, function (m, i) { return nums[+i] != null ? LRI + nums[+i] + PDI : m; });
    }
    for (var i = 0; i < patterns.length; i++) {
      var m = text.match(patterns[i][0]);
      if (m) {
        return patterns[i][1].replace(/\$(\d)/g, function (x, n) {
          var v = m[+n] || '';
          var t = Object.prototype.hasOwnProperty.call(dict, v) ? dict[v] : v;
          return /[\d@.]/.test(t) && !/[؀-ۿ]/.test(t) ? LRI + t + PDI : t;
        });
      }
    }
    return null;
  }

  function translate(str) {
    if (lang !== 'ar' || !str) return str;
    var lead = str.match(/^[\s\u2013\u2014\u00b7\u2022|:]*/)[0], trail = str.match(/\s*$/)[0];
    var core = str.slice(lead.length, str.length - trail.length).trim();
    if (!core || !/[A-Za-z]{2}/.test(core)) return str;
    var t = lookup(core);
    if (t == null) { note(core); return str; }
    return lead + t + trail;
  }

  function note(core) {
    if (core.length > 300 || !/[A-Za-z].*[A-Za-z].*[A-Za-z]/.test(core) || /https?:|@|^[A-Z0-9_-]+$/.test(core)) return;
    if (reported[core]) return;
    missing[core] = 1;
    clearTimeout(timer);
    timer = setTimeout(flush, 4000);
  }

  function flush() {
    var list = Object.keys(missing);
    missing = {};
    if (!list.length || !document.body || !document.body.hasAttribute('data-signed-in')) return;
    list.forEach(function (s) { reported[s] = 1; });
    try {
      fetch('/api/admin/i18n/missing', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({ page: location.hash.split('?')[0] || '#/', strings: list.slice(0, 60) }) }).catch(function () {});
    } catch (e) { /* ignore */ }
  }

  var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, CODE: 1, PRE: 1, INPUT: 1, SELECT: 0, OPTION: 0, NOSCRIPT: 1 };
  function skipped(el) {
    for (var n = el; n && n !== document; n = n.parentNode) {
      if (n.nodeType !== 1) continue;
      if (SKIP[n.nodeName] === 1 || n.isContentEditable || n.hasAttribute('data-noi18n')) return true;
    }
    return false;
  }

  var done = new WeakMap();
  function doText(node) {
    var v = node.nodeValue;
    if (done.get(node) === v) return;
    if (!node.parentNode || skipped(node.parentNode)) return;
    var t = translate(v);
    done.set(node, t);
    if (t !== v) node.nodeValue = t;
  }
  var ATTRS = ['placeholder', 'title', 'aria-label', 'data-label'];
  function doEl(el) {
    // Attributes are translated on inputs too (placeholder, aria-label); only data-noi18n stops it.
    if (el.hasAttribute('data-noi18n') || (el.parentNode && el.parentNode !== document && skipped(el.parentNode))) return;
    for (var i = 0; i < ATTRS.length; i++) {
      var a = el.getAttribute(ATTRS[i]);
      if (a && /[A-Za-z]{2}/.test(a)) {
        var t = translate(a);
        if (t !== a) el.setAttribute(ATTRS[i], t);
      }
    }
  }
  function walk(root) {
    if (root.nodeType === 3) { doText(root); return; }
    if (root.nodeType !== 1 && root.nodeType !== 11) return;
    if (root.nodeType === 1) { if (skipped(root)) return; doEl(root); }
    var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, null);
    var n;
    while ((n = w.nextNode())) { if (n.nodeType === 3) doText(n); else doEl(n); }
  }

  if (lang === 'ar') {
    var obs = new MutationObserver(function (list) {
      list.forEach(function (m) {
        if (m.type === 'characterData') doText(m.target);
        else if (m.type === 'attributes') doEl(m.target);
        else m.addedNodes.forEach(walk);
      });
    });
    var start = function () {
      walk(document.body);
      obs.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
    };
    if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
    window.addEventListener('pagehide', flush);
  }

  window.AdminI18n = {
    lang: function () { return lang; },
    t: function (s) { return translate(s); },
    set: function (l) { try { localStorage.setItem(KEY, l); } catch (e) { /* ignore */ } location.reload(); }
  };
})();
