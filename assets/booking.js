/* Sites By MS booking widget.
   SBMBooking.mount(element, { business: 'Liman', slug: 'liman', compact: false })
   Loads real availability from /api/slots.php and books through /api/book.php. */
(function () {
  if (window.SBMBooking) return;
  var CSS = ''
    + '.sbk{font-family:"Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",sans-serif;color:#0E1430;-webkit-font-smoothing:antialiased;line-height:1.5}'
    + '.sbk *{box-sizing:border-box}'
    + '.sbk h3{margin:0;font-size:20px;font-weight:800;letter-spacing:-.01em}'
    + '.sbk .sbk-sub{font-size:14px;color:#5B6180;margin:4px 0 0}'
    + '.sbk-days{display:flex;gap:8px;overflow-x:auto;padding:4px 2px 10px;margin:14px -2px 4px;scroll-snap-type:x mandatory;scrollbar-width:thin}'
    + '.sbk-day{flex:0 0 auto;scroll-snap-align:start;min-width:84px;border:1.5px solid #DCD8E8;background:#fff;border-radius:14px;padding:10px 10px 9px;text-align:center;cursor:pointer;font:inherit;color:inherit}'
    + '.sbk-day b{display:block;font-size:13px;font-weight:700;color:#5B6180}'
    + '.sbk-day strong{display:block;font-size:22px;font-weight:800;line-height:1.15}'
    + '.sbk-day small{display:block;font-size:12px;font-weight:700;color:#14653F}'
    + '.sbk-day.full small{color:#8A8FA8}.sbk-day.full{opacity:.6}'
    + '.sbk-day[aria-pressed="true"]{border-color:#8557FC;background:#F6F3FF;box-shadow:0 0 0 3px rgba(133,87,252,.18)}'
    + '.sbk-day[aria-pressed="true"] b{color:#4B22C9}'
    + '.sbk-times{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:10px}'
    + '@media (max-width:520px){.sbk-times{grid-template-columns:repeat(3,minmax(0,1fr))}}'
    + '.sbk-t{min-height:46px;border-radius:10px;border:1.5px solid #DCD8E8;background:#fff;font-weight:700;font-size:15px;line-height:1;font-family:inherit;color:#0E1430;cursor:pointer;font-variant-numeric:tabular-nums}'
    + '.sbk-t:hover{border-color:#8557FC}'
    + '.sbk-t[disabled]{background:repeating-linear-gradient(135deg,#F4F2F8 0 6px,#ECE9F2 6px 12px);border-color:#ECE9F2;color:#A3A7BC;cursor:not-allowed;text-decoration:line-through}'
    + '.sbk-t[aria-pressed="true"]{background:#8557FC;border-color:#8557FC;color:#fff}'
    + '.sbk-legend{display:flex;gap:16px;font-size:12.5px;color:#5B6180;margin-top:10px;flex-wrap:wrap}'
    + '.sbk-legend i{display:inline-block;width:14px;height:14px;border-radius:4px;vertical-align:-2px;margin-right:6px;border:1.5px solid #DCD8E8;background:#fff}'
    + '.sbk-legend i.b{background:repeating-linear-gradient(135deg,#F4F2F8 0 3px,#ECE9F2 3px 6px);border-color:#ECE9F2}'
    + '.sbk-form{display:grid;gap:12px;margin-top:18px;border-top:1px solid #EEEAF4;padding-top:18px}'
    + '.sbk-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}'
    + '@media (max-width:560px){.sbk-2{grid-template-columns:minmax(0,1fr)}}'
    + '.sbk label{display:grid;gap:6px;font-weight:600;font-size:14px}'
    + '.sbk input,.sbk select{font-weight:400;font-size:16px;line-height:1.4;font-family:inherit;padding:12px 13px;border:1.5px solid #DCD8E8;border-radius:10px;background:#fff;color:#0E1430;width:100%}'
    + '.sbk input:focus,.sbk select:focus{border-color:#8557FC;outline:none;box-shadow:0 0 0 3px rgba(133,87,252,.18)}'
    + '.sbk-how{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}'
    + '.sbk-how button{min-height:46px;border-radius:10px;border:1.5px solid #DCD8E8;background:#fff;font-weight:600;font-size:14px;font-family:inherit;cursor:pointer;color:#0E1430;padding:6px}'
    + '.sbk-how button[aria-pressed="true"]{border-color:#8557FC;background:#F6F3FF;color:#4B22C9;font-weight:800}'
    + '.sbk-go{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:4px}'
    + '.sbk-sum{font-size:14px;color:#5B6180}.sbk-sum b{color:#0E1430}'
    + '.sbk-btn{min-height:50px;padding:0 24px;border-radius:12px;border:0;background:#8557FC;color:#fff;font-weight:800;font-size:16px;font-family:inherit;cursor:pointer}'
    + '.sbk-btn:hover{background:#7444F5}.sbk-btn[disabled]{opacity:.55;cursor:wait}'
    + '.sbk-err{color:#B42318;font-size:14px;font-weight:600;margin:0}'
    + '.sbk-load{padding:30px 0;text-align:center;color:#5B6180}'
    + '.sbk-done{text-align:center;padding:10px 0}'
    + '.sbk-done .ok{width:64px;height:64px;border-radius:50%;background:#8557FC;color:#fff;display:grid;place-items:center;margin:0 auto 16px}'
    + '.sbk-done p{color:#4A5070;margin:8px 0 0}'
    + '.sbk-card{background:#F6F3FF;border-radius:14px;padding:14px 16px;margin:18px 0 4px;text-align:left;display:grid;gap:2px}'
    + '.sbk-hp{position:absolute;left:-9999px}'
    + '.sbk [hidden]{display:none!important}'
    + '.sbk :focus-visible{outline:3px solid #8557FC;outline-offset:2px}';

  function el(tag, attrs, html) { var e = document.createElement(tag); if (attrs) for (var k in attrs) e.setAttribute(k, attrs[k]); if (html != null) e.innerHTML = html; return e; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function mount(root, opt) {
    opt = opt || {};
    if (!document.getElementById('sbk-css')) { var st = el('style', { id: 'sbk-css' }); st.textContent = CSS; document.head.appendChild(st); }
    var S = { days: [], day: 0, ts: null, t: '', how: 'Video call (Google Meet)' };
    var box = el('div', { 'class': 'sbk' });
    root.innerHTML = ''; root.appendChild(box);
    box.innerHTML = '<div class="sbk-load">Loading free times…</div>';

    fetch('/api/slots.php', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      S.days = j.days || [];
      var firstFree = S.days.findIndex(function (d) { return d.slots.some(function (s) { return s.free; }); });
      S.day = firstFree < 0 ? 0 : firstFree;
      draw();
    }).catch(function () {
      box.innerHTML = '<p class="sbk-err">We couldn\'t load the calendar. Please try again, or message us on WhatsApp +44 7405 760048.</p>';
    });

    function draw() {
      var d = S.days[S.day] || { slots: [] };
      var free = function (x) { return x.slots.filter(function (s) { return s.free; }).length; };
      box.innerHTML = '<h3>Pick a time for your 15-minute call</h3><p class="sbk-sub">Times are in London (UK) time. Grey times are already taken.</p>'
        + '<div class="sbk-days" role="group" aria-label="Choose a day">' + S.days.map(function (x, i) {
          var p = x.label.split(' '), n = free(x);
          return '<button type="button" class="sbk-day' + (n ? '' : ' full') + '" data-day="' + i + '" aria-pressed="' + (i === S.day) + '"' + (n ? '' : ' aria-disabled="true"') + '><b>' + esc(p[0]) + '</b><strong>' + esc(p[1]) + '</strong><b>' + esc(p[2]) + '</b><small>' + (n ? n + ' free' : 'Full') + '</small></button>';
        }).join('') + '</div>'
        + '<div class="sbk-times" role="group" aria-label="Choose a time">' + d.slots.map(function (s) {
          return '<button type="button" class="sbk-t" data-ts="' + s.ts + '" data-t="' + s.t + '"' + (s.free ? '' : ' disabled aria-label="' + s.t + ' taken"') + ' aria-pressed="' + (s.ts === S.ts) + '">' + s.t + '</button>';
        }).join('') + '</div>'
        + '<div class="sbk-legend"><span><i></i>Free</span><span><i class="b"></i>Taken</span></div>'
        + '<form class="sbk-form" novalidate' + (S.ts ? '' : ' hidden') + '>'
        + '<div class="sbk-hp" aria-hidden="true"><label>Leave empty<input name="website_url" tabindex="-1" autocomplete="off"></label></div>'
        + '<div class="sbk-2"><label>Your name<input name="name" autocomplete="name" required></label><label>Business<input name="business" autocomplete="organization" value="' + esc(opt.business || '') + '" required></label></div>'
        + '<label>Email<input name="email" type="email" autocomplete="email" placeholder="you@business.com" required></label>'
        + '<div><span style="display:block;font-weight:600;font-size:14px;margin-bottom:6px">How should we talk?</span><div class="sbk-how" role="group" aria-label="How should we talk?">'
        + ['Video call (Google Meet)', 'WhatsApp call', 'Phone call'].map(function (h) { return '<button type="button" data-how="' + h + '" aria-pressed="' + (h === S.how) + '">' + h.replace(' (Google Meet)', '') + '</button>'; }).join('') + '</div></div>'
        + '<label' + (S.how === 'Video call (Google Meet)' ? ' hidden' : '') + ' id="sbkPhoneL">Phone or WhatsApp number<input name="phone" type="tel" autocomplete="tel"></label>'
        + '<p class="sbk-err" role="alert" hidden></p>'
        + '<div class="sbk-go"><span class="sbk-sum"><b>' + esc(d.label || '') + ' · ' + esc(S.t) + '</b> · 15 min</span><button class="sbk-btn" type="submit">Book this time</button></div>'
        + '</form>';
      wire();
    }
    function keep(f) { return f ? { name: f.name.value, business: f.business.value, email: f.email.value, phone: f.phone.value } : null; }
    function restore(f, v) { if (f && v) { f.name.value = v.name; f.business.value = v.business; f.email.value = v.email; f.phone.value = v.phone; } }
    function wire() {
      box.querySelectorAll('[data-day]').forEach(function (b) { b.onclick = function () { if (b.getAttribute('aria-disabled')) return; var v = keep(box.querySelector('form')); S.day = +b.getAttribute('data-day'); S.ts = null; S.t = ''; draw(); restore(box.querySelector('form'), v); }; });
      box.querySelectorAll('[data-ts]').forEach(function (b) { b.onclick = function () { var v = keep(box.querySelector('form')); S.ts = +b.getAttribute('data-ts'); S.t = b.getAttribute('data-t'); draw(); var f = box.querySelector('form'); restore(f, v); (f.name.value ? f.email : f.name).focus({ preventScroll: false }); }; });
      box.querySelectorAll('[data-how]').forEach(function (b) { b.onclick = function () { var v = keep(box.querySelector('form')); S.how = b.getAttribute('data-how'); draw(); restore(box.querySelector('form'), v); }; });
      var f = box.querySelector('form'); if (!f) return;
      f.onsubmit = function (e) {
        e.preventDefault();
        var err = f.querySelector('.sbk-err'), m = '';
        if (!f.name.value.trim()) m = 'Please add your name.';
        else if (!f.business.value.trim()) m = 'Please add your business name.';
        else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.email.value.trim())) m = 'Please check your email address.';
        else if (S.how !== 'Video call (Google Meet)' && !f.phone.value.trim()) m = 'Please add the number we should call.';
        if (m) { err.textContent = m; err.hidden = false; return; }
        var btn = f.querySelector('.sbk-btn'); btn.disabled = true; btn.textContent = 'Booking…';
        fetch('/api/book.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
          ts: S.ts, name: f.name.value, business: f.business.value, email: f.email.value, phone: f.phone.value, how: S.how, slug: opt.slug || '', website_url: f.website_url.value
        }) }).then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); }).then(function (r) {
          if (!r.j.ok) {
            err.textContent = r.j.error || 'Something went wrong. Please try again.'; err.hidden = false; btn.disabled = false; btn.textContent = 'Book this time';
            if (r.s === 409) fetch('/api/slots.php', { cache: 'no-store' }).then(function (x) { return x.json(); }).then(function (j) { var v = keep(f); S.days = j.days; S.ts = null; draw(); restore(box.querySelector('form'), v); var e2 = box.querySelector('.sbk-err'); if (e2) { e2.textContent = 'Sorry, that time was just taken. Please pick another one.'; e2.hidden = false; } });
            return;
          }
          var first = f.name.value.trim().split(/\s+/)[0];
          box.innerHTML = '<div class="sbk-done"><div class="ok"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>'
            + '<h3>You\'re booked, ' + esc(first) + '.</h3><p>A calendar invite is on its way to ' + esc(f.email.value.trim()) + '.</p>'
            + '<div class="sbk-card"><b style="font-size:18px">' + esc(r.j.when) + '</b><span style="color:#4A5070;font-size:14px">15 minutes · London time · ' + esc(r.j.how) + '</span></div>'
            + '<p style="font-size:14px">Need to change it? Just reply to the confirmation email.</p></div>';
          if (opt.onDone) opt.onDone(r.j);
        }).catch(function () { err.textContent = 'No connection. Please try again.'; err.hidden = false; btn.disabled = false; btn.textContent = 'Book this time'; });
      };
    }
  }
  window.SBMBooking = { mount: mount };
})();
