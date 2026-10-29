/* Sites By MS concept bar.
   Add to any concept page, just before </body>:
   <script src="/assets/concept-bar.js" data-name="Liman" data-slug="liman" defer></script>
   - Shows a floating bar with "Ask a question" and "Book a 15-min call".
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
  if (/[?&]team=1/.test(location.search)) store('sbm_team', '1');
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
    + '<div class="sbm-txt"><b></b><span>Built only from your public information. Like where it\'s going?</span></div>'
    + '<div class="sbm-acts"><a class="sbm-btn sbm-ask" href="/ask/' + q + '">Ask a question</a>'
    + '<a class="sbm-btn sbm-book" href="/book/' + q + '">Book a 15-min call</a></div>'
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
  bar.querySelector('.sbm-book').onclick = function (e) { e.preventDefault(); openBooking(); };
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
