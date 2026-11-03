/* Visitor behaviour on the studio pages (home, about, book, ask, thanks, privacy, demo).
   Same events and visitor id as the concept pages, so a visitor can be followed from a concept to these pages.
   Never runs for the team (panel cookie / ?team=1) or bots. Usage: <script src="/assets/track.js" data-page="about" defer></script> */
(function () {
  'use strict';
  var me = document.currentScript, pageName = (me && me.getAttribute('data-page')) || location.pathname.replace(/\//g, '') || 'home';
  function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }
  if (/[?&]team=1/.test(location.search) || /\/admin/.test(document.referrer)) store('sbm_team', '1');
  var off = store('sbm_team') === '1' || /bot|crawl|spider|preview|fetch|headless/i.test(navigator.userAgent);
  function rid() { var a = '', c = 'abcdefghijklmnopqrstuvwxyz0123456789'; for (var i = 0; i < 16; i++) a += c.charAt(Math.floor(Math.random() * 36)); return a; }
  var vid = store('sbm_vid') || rid(); store('sbm_vid', vid);
  // one session per browser tab across all studio pages
  var sid, isNew = false;
  try { sid = sessionStorage.getItem('sbm_sid_site'); if (!sid) { sid = rid(); isNew = true; sessionStorage.setItem('sbm_sid_site', sid); } } catch (e) { sid = rid(); isNew = true; }
  var qs = location.search, src = (qs.match(/[?&]from=([a-z0-9-]{1,20})/) || [])[1] || '', forSlug = (qs.match(/[?&]s=([a-z0-9-]{1,60})/) || [])[1] || '';
  var t0 = Date.now(), q = [];
  function flush() {
    if (off || !q.length) return;
    var body = JSON.stringify({ sid: sid, vid: vid, slug: 'site', page: 'site', src: src, e: q.splice(0, 40) }), sent = false;
    try { sent = navigator.sendBeacon && navigator.sendBeacon('/api/event.php', new Blob([body], { type: 'application/json' })); } catch (e) {}
    if (!sent) { try { fetch('/api/event.php', { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true }); } catch (e) {} }
    if (q.length) flush();
  }
  function ev(name, val, now) { if (off) return; q.push([name, String(val == null ? '' : val).slice(0, 160), Date.now() - t0]); if (now || q.length >= 20) flush(); }
  window.SBMTrack = ev;
  if (off) return;

  var ref = ''; try { ref = document.referrer ? new URL(document.referrer).hostname : ''; } catch (e) {}
  if (ref === location.hostname) { try { ref = 'our ' + (new URL(document.referrer).pathname.replace(/\//g, '') || 'home'); } catch (e) {} }
  if (isNew) { var vn = (+store('sbm_n_site') || 0) + 1; store('sbm_n_site', String(vn)); ev('visit', vn + ' · ' + innerWidth + 'x' + innerHeight); }
  ev('page', pageName + (forSlug ? ' (for ' + forSlug + ')' : '') + (ref ? ' ← ' + ref : ''), true);

  // active time, added up over all studio pages in this session
  var base = 0; try { base = +sessionStorage.getItem('sbm_act_site') || 0; } catch (e) {}
  var active = 0, lastAct = Date.now(), sentActive = 0;
  ['scroll', 'mousemove', 'touchstart', 'keydown', 'click'].forEach(function (n) { window.addEventListener(n, function () { lastAct = Date.now(); }, { passive: true }); });
  function secLabel(el) { var hd = el.querySelector('h1,h2,h3'); return (hd ? hd.textContent : (el.id || el.tagName.toLowerCase())).replace(/\s+/g, ' ').trim().slice(0, 60); }
  function describe(el) {
    if (!el || !el.closest) return '';
    if (el.tagName === 'IMG') return 'image: ' + (el.alt || '').slice(0, 50);
    var sec = el.closest('section,header,footer'), t = (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40);
    return (sec ? secLabel(sec).slice(0, 30) + ' › ' : '') + (t || el.tagName.toLowerCase());
  }
  var dwell = {}, secEls = [], dwellSent = '';
  function countDwell() {
    var mid = innerHeight / 2, best = null, bh = 1e9;
    for (var i = 0; i < secEls.length; i++) { var r = secEls[i].getBoundingClientRect(); if (r.top <= mid && r.bottom >= mid && r.height < bh) { best = secEls[i]; bh = r.height; } }
    if (best) { var l = secLabel(best); if (l) dwell[l] = (dwell[l] || 0) + 1; }
  }
  setInterval(function () {
    if (document.visibilityState === 'visible' && Date.now() - lastAct < 30000) { active++; countDwell(); try { sessionStorage.setItem('sbm_act_site', String(base + active)); } catch (e) {} }
    if (active - sentActive >= 15) { sentActive = active; ev('time', base + active); flush(); }
  }, 1000);

  var marks = [25, 50, 75, 100], hit = {};
  window.addEventListener('scroll', function () {
    var h = document.documentElement.scrollHeight - innerHeight; if (h <= 0) return;
    var pct = Math.round(window.scrollY / h * 100);
    marks.forEach(function (m) { if (pct >= m - 2 && !hit[m]) { hit[m] = 1; ev('scroll', m); } });
  }, { passive: true });
  if ('IntersectionObserver' in window) {
    var seen = {}, io = new IntersectionObserver(function (es) {
      es.forEach(function (x) { if (!x.isIntersecting) return; var l = secLabel(x.target); if (l && !seen[l]) { seen[l] = 1; ev('section', pageName + ': ' + l); } io.unobserve(x.target); });
    }, { threshold: 0.35 });
    setTimeout(function () { secEls = Array.prototype.slice.call(document.querySelectorAll('main > section, section, .hero2, footer')).slice(0, 20); secEls.forEach(function (el) { io.observe(el); }); }, 500);
  }

  var taps = 0, recent = [];
  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var now = Date.now(); recent = recent.filter(function (c) { return now - c.t < 1200 && Math.abs(c.x - e.clientX) < 40 && Math.abs(c.y - e.clientY) < 40; }); recent.push({ t: now, x: e.clientX, y: e.clientY });
    if (recent.length === 3) ev('rage', describe(e.target), true);
    var a = e.target.closest('a,button,summary,label,input,select,textarea');
    if (!a) { if (taps++ < 40) ev('tap', describe(e.target)); return; }
    if (!/^(A|BUTTON|SUMMARY)$/.test(a.tagName)) return;
    var href = a.getAttribute('href') || '', txt = (a.textContent || a.getAttribute('aria-label') || href).replace(/\s+/g, ' ').trim().slice(0, 60), dest = '';
    try { if (/^https?:|^\//i.test(href)) { var u = new URL(href, location.href); dest = u.hostname === location.hostname ? u.pathname : u.hostname; } } catch (x) {}
    if (/^tel:/i.test(href)) ev('call', txt, true);
    else if (/^mailto:/i.test(href)) ev('email', txt, true);
    else ev('click', pageName + ': ' + txt + (dest ? ' → ' + dest : ''), !!dest);
  }, true);
  document.addEventListener('copy', function () { ev('copy', String(window.getSelection ? getSelection() : '').replace(/\s+/g, ' ').slice(0, 60), true); });

  var forms = {};
  function formKey(f) { return pageName + ' form'; }
  document.addEventListener('focusin', function (e) {
    var el = e.target; if (!el.form || el.tabIndex < 0 || /hp/.test(el.className)) return;
    var k = formKey(el.form), st = forms[k] || (forms[k] = {});
    if (!st.started) { st.started = 1; ev('form_start', k); }
    st.last = el.name || el.type || el.tagName.toLowerCase();
  }, true);
  document.addEventListener('submit', function (e) { var k = formKey(e.target); (forms[k] || (forms[k] = {})).done = 1; ev('form_submit', k, true); }, true);

  var hiddenAt = 0;
  function leave() {
    if (active !== sentActive) { sentActive = active; ev('time', base + active); }
    var d = Object.keys(dwell).sort(function (a, b) { return dwell[b] - dwell[a]; }).slice(0, 5).map(function (k) { return k.slice(0, 22) + ' ' + dwell[k] + 's'; }).join('; ');
    if (d && d !== dwellSent) { dwellSent = d; ev('dwell', pageName + ': ' + d); }
    Object.keys(forms).forEach(function (k) { var st = forms[k]; if (st.started && !st.done && st.lastSent !== st.last) { st.lastSent = st.last; ev('form_abandon', k + ' · last field: ' + st.last); } });
    flush();
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') { hiddenAt = Date.now(); leave(); }
    else if (hiddenAt && Date.now() - hiddenAt > 15000) { ev('back', Math.round((Date.now() - hiddenAt) / 1000) + 's away'); hiddenAt = 0; }
  });
  window.addEventListener('pagehide', leave);
  setInterval(flush, 10000);
})();
