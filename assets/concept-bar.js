/* Sites By MS concept bar.
   Add to any concept page, just before </body>:
   <script src="/assets/concept-bar.js" data-name="Liman" data-slug="liman" defer></script>
   - Shows a floating bar with "Book a call" and "Get my free report" (competitor report request), plus one gentle nudge card.
   - Lifts the concept's own fixed bottom bars so nothing overlaps.
   - Lets the visitor hide it (a small pill brings it back).
   - Tells the team the concept was opened (skipped for the team: open any concept once with ?team=1). */
(function () {
  var me = document.currentScript || document.querySelector('script[src*="concept-bar.js"]');
  if (!me) return;
  var name = me.getAttribute('data-name') || 'your business';
  var slug = (me.getAttribute('data-slug') || '').replace(/[^a-z0-9-]/g, '');
  var q = '?b=' + encodeURIComponent(name) + (slug ? '&s=' + slug : '');

  function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }
  if (/[?&]team=1/.test(location.search) || /\/admin/.test(document.referrer)) store('sbm_team', '1');
  if (/[?&]team=0/.test(location.search)) { try { localStorage.removeItem('sbm_team'); } catch (e) {} }

  // View alert (one beacon per page load, never for the team or bots).
  if (slug && store('sbm_team') !== '1' && !/bot|crawl|spider|preview|fetch/i.test(navigator.userAgent)) {
    var src = (location.search.match(/[?&]from=([a-z0-9-]{1,20})/) || [])[1] || '';
    var url = '/api/view.php?s=' + slug + '&f=' + src + '&r=' + encodeURIComponent(document.referrer.slice(0, 200));
    if (navigator.sendBeacon) navigator.sendBeacon(url); else { var i = new Image(); i.src = url; }
  }

  var css = ''
    + '.sbm-bar,.sbm-pill{font-family:"Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased;box-sizing:border-box}'
    + '.sbm-bar *{box-sizing:border-box}'
    + '.sbm-bar{position:fixed;z-index:2147483000;left:50%;bottom:calc(20px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);width:min(1080px,calc(100% - 32px));background:#070F37;color:#FBF5ED;border-radius:18px;padding:12px 12px 12px 18px;display:flex;align-items:center;gap:16px;box-shadow:0 24px 60px rgba(7,15,55,.45);animation:sbmIn .35s ease-out}'
    + '@keyframes sbmIn{from{opacity:0;transform:translate(-50%,16px)}to{opacity:1;transform:translate(-50%,0)}}'
    + '@media (prefers-reduced-motion:reduce){.sbm-bar{animation:none}}'
    + '.sbm-bar img{height:32px;width:auto;display:block;flex:0 0 auto}'
    + '.sbm-txt{flex:1;min-width:0;line-height:1.35}'
    + '.sbm-txt b{display:block;font-size:15px;font-weight:800}'
    + '.sbm-txt span{display:block;font-size:13.5px;color:#A9AECB}'
    + '.sbm-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 18px;border-radius:12px;font-weight:700;font-size:15px;text-decoration:none;white-space:nowrap;line-height:1}'
    + '.sbm-ask{color:#FBF5ED;border:1.5px solid #3A4378}.sbm-ask:hover{border-color:#FBF5ED;color:#fff}'
    + '.sbm-book{background:#8557FC;color:#fff}.sbm-book:hover{background:#7444F5;color:#fff}'
    + '.sbm-x{background:transparent;border:0;color:#A9AECB;width:44px;height:44px;border-radius:10px;cursor:pointer;display:grid;place-items:center;flex:0 0 auto}'
    + '.sbm-x:hover{color:#fff;background:#141C4A}'
    + '.sbm-bar a:focus-visible,.sbm-bar button:focus-visible,.sbm-pill:focus-visible{outline:3px solid #9B7BFF;outline-offset:2px}'
    + '.sbm-acts{display:flex;gap:8px}'
    + '@media (max-width:760px){.sbm-bar{flex-wrap:wrap;gap:10px;padding:12px;bottom:calc(10px + env(safe-area-inset-bottom,0px));width:calc(100% - 20px)}.sbm-bar img{height:26px}.sbm-txt span{display:none}.sbm-txt b{font-size:14px}.sbm-acts{order:3;width:100%;display:grid;grid-template-columns:1fr 1fr}.sbm-btn{min-height:46px;padding:0 8px;font-size:14px}}'
    + '.sbm-pill{position:fixed;z-index:2147483000;right:calc(16px + env(safe-area-inset-right,0px));bottom:calc(16px + env(safe-area-inset-bottom,0px));background:#070F37;color:#FBF5ED;border:0;border-radius:999px;padding:10px 16px 10px 12px;display:flex;align-items:center;gap:8px;font-weight:700;font-size:14px;cursor:pointer;box-shadow:0 12px 30px rgba(7,15,55,.4)}'
    + '.sbm-pill img{height:20px;width:auto}'
    + '.sbm-bar[hidden],.sbm-pill[hidden]{display:none!important}'
    + '.sbm-sheet-bg{position:fixed;inset:0;z-index:2147483600;background:rgba(7,15,55,.55);display:flex;align-items:center;justify-content:center;padding:16px;animation:sbmFade .2s ease-out}'
    + '@keyframes sbmFade{from{opacity:0}to{opacity:1}}'
    + '.sbm-sheet{background:#fff;border-radius:22px;width:100%;max-width:720px;max-height:calc(100vh - 32px);overflow:auto;box-shadow:0 30px 80px rgba(7,15,55,.45);font-family:"Plus Jakarta Sans",system-ui,sans-serif}'
    + '.sbm-sheet-head{position:sticky;top:0;z-index:2;background:#FBF5ED;display:flex;align-items:center;gap:12px;padding:16px 16px 16px 22px;border-bottom:1px solid #EEEAF4}'
    + '.sbm-sheet-head img{height:30px;width:auto}'
    + '.sbm-sheet-head div{flex:1;min-width:0;line-height:1.35;color:#0E1430}'
    + '.sbm-sheet-head b{display:block;font-size:16px;font-weight:800}'
    + '.sbm-sheet-head span{display:block;font-size:13px;color:#5B6180}'
    + '.sbm-close{background:#fff;border:1px solid #DCD8E8;width:44px;height:44px;border-radius:12px;display:grid;place-items:center;cursor:pointer;color:#0E1430;flex:0 0 auto}'
    + '.sbm-sheet-body{padding:22px}'
    + '.sbm-lead{margin:0 0 14px;font-size:16px;line-height:1.55;color:#1B2140}'
    + '.sbm-list{list-style:none;margin:0 0 18px;padding:0;display:grid;gap:10px}'
    + '.sbm-list li{display:flex;gap:12px;align-items:flex-start;font-size:15px;line-height:1.45;color:#2A3050}'
    + '.sbm-list i{font-style:normal;flex:0 0 30px;height:30px;border-radius:9px;background:#F1ECFF;color:#5B2FE0;display:grid;place-items:center;font-weight:800}'
    + '.sbm-form label{display:block;font-weight:700;font-size:14px;color:#0E1430}'
    + '.sbm-form label em{font-style:normal;font-weight:500;color:#7A7F99;margin-left:4px}'
    + '.sbm-form input{display:block;width:100%;margin-top:6px;min-height:48px;border:1.5px solid #DCD8E8;border-radius:12px;padding:0 14px;font:500 16px/1 inherit;font-family:inherit;color:#0E1430;background:#fff}'
    + '.sbm-form input:focus{outline:none;border-color:#8557FC;box-shadow:0 0 0 3px #E6DDFF}'
    + '.sbm-hp{position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important}'
    + '.sbm-note{margin:8px 0 14px;font-size:13px;color:#6B7090}'
    + '.sbm-err{margin:0 0 12px;color:#B42318;font-size:14px;font-weight:600}.sbm-err[hidden]{display:none}'
    + '.sbm-go{width:100%;border:0;cursor:pointer;min-height:52px;font-size:16px}.sbm-go:disabled{opacity:.6;cursor:default}'
    + '.sbm-done b{display:block;font-size:19px;color:#0E1430;margin-bottom:6px}.sbm-done p{margin:0 0 10px;color:#3B4160;line-height:1.55}.sbm-done a{color:#5B2FE0;font-weight:700}'
    + '.sbm-nudge{position:fixed;z-index:2147483001;right:calc(20px + env(safe-area-inset-right,0px));width:min(360px,calc(100% - 20px));background:#fff;color:#0E1430;border-radius:18px;padding:18px 18px 16px;box-shadow:0 24px 60px rgba(7,15,55,.35);font-family:"Plus Jakarta Sans",system-ui,sans-serif;animation:sbmUp .35s ease-out;border:1px solid #ECE8F5}'
    + '@keyframes sbmUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}'
    + '.sbm-nudge b{display:block;font-size:16px;font-weight:800;padding-right:28px;line-height:1.35}'
    + '.sbm-nudge p{margin:6px 0 14px;font-size:14px;line-height:1.5;color:#4A5070}'
    + '.sbm-nacts{display:flex;gap:8px}.sbm-nacts .sbm-btn{border:0;cursor:pointer;min-height:44px;font-size:14px;font-family:inherit}'
    + '.sbm-later{background:#F3F1F8;color:#2A3050}'
    + '.sbm-nx{position:absolute;top:8px;right:8px;width:36px;height:36px;border:0;background:transparent;font-size:22px;color:#7A7F99;cursor:pointer;border-radius:10px}.sbm-nx:hover{background:#F3F1F8}'
    + '@media (max-width:640px){.sbm-nudge{right:10px;left:10px;width:auto}}'
    + '@media (max-width:640px){.sbm-sheet-bg{align-items:flex-end;padding:0}.sbm-sheet{border-radius:22px 22px 0 0;max-height:92vh}.sbm-sheet-body{padding:18px 16px calc(22px + env(safe-area-inset-bottom,0px))}}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  if (!document.querySelector('link[href*="Plus+Jakarta+Sans"]')) {
    var lf = document.createElement('link'); lf.rel = 'stylesheet';
    lf.href = 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800&display=swap';
    document.head.appendChild(lf);
  }

  var bar = document.createElement('div');
  bar.className = 'sbm-bar'; bar.setAttribute('role', 'region'); bar.setAttribute('aria-label', 'About this concept');
  bar.innerHTML = '<img src="/assets/brand/mark-sm-dark.png" alt="Sites By MS">'
    + '<div class="sbm-txt"><b></b><span>We\'ve also compared you with similar businesses nearby. The report is free.</span></div>'
    + '<div class="sbm-acts"><a class="sbm-btn sbm-ask" href="/book/' + q + '">Book a call</a>'
    + '<a class="sbm-btn sbm-book sbm-rep" href="#report">Get my free report</a></div>'
    + '<button class="sbm-x" type="button" aria-label="Hide this bar"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>';
  bar.querySelector('.sbm-txt b').textContent = 'This concept was made for ' + name + ' by Sites By MS';

  var pill = document.createElement('button');
  pill.className = 'sbm-pill'; pill.type = 'button'; pill.hidden = true;
  pill.innerHTML = '<img src="/assets/brand/mark-sm-dark.png" alt="">Like this concept?';

  var lifted = [];
  function liftOthers(h) {
    lifted.forEach(function (el) { el.style.bottom = el.dataset.sbmOrig || ''; });
    lifted = [];
    if (!h) return;
    Array.prototype.forEach.call(document.body.querySelectorAll('nav,div,footer,aside,a'), function (el) {
      if (el === bar || bar.contains(el) || el === pill) return;
      var cs = getComputedStyle(el);
      if (cs.position === 'fixed' && cs.display !== 'none' && parseFloat(cs.bottom) < 40 && el.offsetHeight < 200) {
        if (el.dataset.sbmOrig === undefined) el.dataset.sbmOrig = el.style.bottom || '';
        el.style.bottom = (parseFloat(cs.bottom) + h) + 'px'; lifted.push(el);
      }
    });
  }
  function show(open) {
    bar.hidden = !open; pill.hidden = open;
    try { sessionStorage.setItem('sbm_hide', open ? '' : '1'); } catch (e) {}
    requestAnimationFrame(function () { liftOthers(open ? bar.offsetHeight + 14 : 0); });
  }
  bar.querySelector('.sbm-x').onclick = function () { show(false); pill.focus(); };
  bar.querySelector('.sbm-ask').onclick = function (e) { e.preventDefault(); openBooking(); };
  bar.querySelector('.sbm-rep').onclick = function (e) { e.preventDefault(); openReport(); };
  if (/[?&#]report\b/.test(location.search + location.hash)) setTimeout(openReport, 600);
  if (/[?&#]book\b/.test(location.search + location.hash)) setTimeout(openBooking, 600);

  function openBooking() {
    var prev = document.activeElement;
    var bg = document.createElement('div'); bg.className = 'sbm-sheet-bg';
    bg.innerHTML = '<div class="sbm-sheet" role="dialog" aria-modal="true" aria-label="Book a 15-minute call">'
      + '<div class="sbm-sheet-head"><img src="/assets/brand/mark-sm-light.png" alt=""><div><b></b><span>Free, no obligation · video, WhatsApp or phone</span></div>'
      + '<button type="button" class="sbm-close" aria-label="Close"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>'
      + '<div class="sbm-sheet-body"></div></div>';
    bg.querySelector('.sbm-sheet-head b').textContent = 'A 15-minute call about ' + name + "'s website";
    document.body.appendChild(bg); document.documentElement.style.overflow = 'hidden';
    function close() { bg.remove(); document.documentElement.style.overflow = ''; document.removeEventListener('keydown', key); if (prev && prev.focus) prev.focus(); }
    function key(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', key);
    bg.addEventListener('click', function (e) { if (e.target === bg) close(); });
    bg.querySelector('.sbm-close').onclick = close;
    bg.querySelector('.sbm-close').focus();
    var body = bg.querySelector('.sbm-sheet-body');
    function go() { window.SBMBooking.mount(body, { business: name, slug: slug }); }
    if (window.SBMBooking) go();
    else { var s = document.createElement('script'); s.src = '/assets/booking.js'; s.onload = go; s.onerror = function () { location.href = '/book/' + q; }; document.head.appendChild(s); }
  }
  function sheet(title, sub) {
    var prev = document.activeElement;
    var bg = document.createElement('div'); bg.className = 'sbm-sheet-bg';
    bg.innerHTML = '<div class="sbm-sheet" role="dialog" aria-modal="true">'
      + '<div class="sbm-sheet-head"><img src="/assets/brand/mark-sm-light.png" alt=""><div><b></b><span></span></div>'
      + '<button type="button" class="sbm-close" aria-label="Close"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>'
      + '<div class="sbm-sheet-body"></div></div>';
    bg.querySelector('.sbm-sheet-head b').textContent = title;
    bg.querySelector('.sbm-sheet-head span').textContent = sub;
    bg.querySelector('.sbm-sheet').setAttribute('aria-label', title);
    document.body.appendChild(bg); document.documentElement.style.overflow = 'hidden';
    function close() { bg.remove(); document.documentElement.style.overflow = ''; document.removeEventListener('keydown', key); if (prev && prev.focus) prev.focus(); }
    function key(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', key);
    bg.addEventListener('click', function (e) { if (e.target === bg) close(); });
    bg.querySelector('.sbm-close').onclick = close;
    bg.querySelector('.sbm-close').focus();
    return { body: bg.querySelector('.sbm-sheet-body'), close: close };
  }

  var asked = store('sbm_rep_' + slug) === '1';
  function openReport() {
    hideNudge();
    var sh = sheet('Free competitor report for ' + name, 'Ready within 24 hours · no cost, no obligation');
    if (asked) { sh.body.innerHTML = '<div class="sbm-done"><b>You\'ve already asked for it 👍</b><p>We\'re preparing your report and will send it within 24 hours.</p></div>'; return; }
    sh.body.innerHTML = '<p class="sbm-lead">We look at <b>4–5 similar businesses near you</b> and show, side by side, where you are ahead and where customers may pick someone else.</p>'
      + '<ul class="sbm-list">'
      + '<li><i>★</i><span><b>Google rating and reviews</b> compared with your neighbours</span></li>'
      + '<li><i>⌕</i><span><b>Who shows up first on Google Maps</b> when people search nearby</span></li>'
      + '<li><i>▣</i><span><b>Websites side by side</b>: mobile, booking, menu or prices, hours</span></li>'
      + '<li><i>✓</i><span><b>3 quick wins</b> you can act on, with or without us</span></li></ul>'
      + '<form class="sbm-form" novalidate><label>Where should we send it? <em>optional</em><input name="contact" autocomplete="email" placeholder="Email or WhatsApp number"></label>'
      + '<input name="website_url" tabindex="-1" autocomplete="off" class="sbm-hp" aria-hidden="true">'
      + '<p class="sbm-note">Leave it empty and we\'ll reply where we first contacted you.</p>'
      + '<p class="sbm-err" role="alert" hidden></p>'
      + '<button class="sbm-btn sbm-book sbm-go" type="submit">Yes, send me the report</button></form>';
    var f = sh.body.querySelector('form'), err = f.querySelector('.sbm-err'), btn = f.querySelector('.sbm-go');
    f.onsubmit = function (e) {
      e.preventDefault(); err.hidden = true; btn.disabled = true; btn.textContent = 'Sending…';
      fetch('/api/report-request.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ slug: slug, business: name, contact: f.contact.value, website_url: f.website_url.value }) })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (j) {
          if (!j.ok) throw new Error(j.error || 'Something went wrong. Please try again.');
          asked = true; store('sbm_rep_' + slug, '1');
          sh.body.innerHTML = '<div class="sbm-done"><b>Thank you! Your report is on its way.</b><p>We\'ll send it within 24 hours' + (f.contact.value ? ' to <span></span>' : '') + '. It opens on a private page together with this concept.</p>'
            + '<p>Questions in the meantime? <a href="/ask/' + q + '">Ask us anything</a>.</p></div>';
          var sp = sh.body.querySelector('.sbm-done span'); if (sp) sp.textContent = f.contact.value;
        })
        .catch(function (x) { err.textContent = x.message; err.hidden = false; btn.disabled = false; btn.textContent = 'Yes, send me the report'; });
    };
  }

  // One gentle nudge per visit: after 12 seconds or when the visitor has scrolled half the page.
  var nudge = null;
  function hideNudge() { if (nudge) { nudge.remove(); nudge = null; } }
  function showNudge() {
    if (asked || nudge || document.querySelector('.sbm-sheet-bg') || bar.hidden) return;
    try { if (sessionStorage.getItem('sbm_nudged')) return; sessionStorage.setItem('sbm_nudged', '1'); } catch (e) {}
    nudge = document.createElement('div'); nudge.className = 'sbm-nudge'; nudge.setAttribute('role', 'dialog'); nudge.setAttribute('aria-label', 'Free competitor report');
    nudge.innerHTML = '<button type="button" class="sbm-nx" aria-label="Close">×</button><b></b><p>How does it compare with similar businesses nearby? We\'ll show you on Google, reviews and websites.</p>'
      + '<div class="sbm-nacts"><button type="button" class="sbm-btn sbm-book">Yes, send it</button><button type="button" class="sbm-btn sbm-later">Not now</button></div>';
    nudge.querySelector('b').textContent = 'Free report: ' + name + ' vs. the neighbours';
    nudge.querySelector('.sbm-book').onclick = openReport;
    nudge.querySelector('.sbm-later').onclick = hideNudge; nudge.querySelector('.sbm-nx').onclick = hideNudge;
    nudge.style.bottom = (bar.offsetHeight + 34) + 'px';
    document.body.appendChild(nudge);
  }
  setTimeout(showNudge, 12000);
  window.addEventListener('scroll', function onS() {
    if (window.scrollY > (document.documentElement.scrollHeight - innerHeight) * 0.5) { window.removeEventListener('scroll', onS); showNudge(); }
  }, { passive: true });

  pill.onclick = function () { show(true); };

  function mount() {
    document.body.appendChild(bar); document.body.appendChild(pill);
    var hidden = false; try { hidden = sessionStorage.getItem('sbm_hide') === '1'; } catch (e) {}
    var pad = document.createElement('div'); pad.style.height = '120px'; pad.setAttribute('aria-hidden', 'true');
    document.body.appendChild(pad);
    show(!hidden);
    window.addEventListener('resize', function () { if (!bar.hidden) liftOthers(bar.offsetHeight + 14); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
