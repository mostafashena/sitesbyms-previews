/* Sites By MS — client portal demo.
   Everything runs in the browser with example data saved in localStorage.
   Routes live in the hash (#dashboard, #proposal …). "Act as the team" plays the studio's side. */
(function () {
  'use strict';
  var KEY = 'sbm_demo_v1';
  var app = document.getElementById('app');
  var PRICE = { base: 1450, booking: 180, turkish: 260 };

  /* ---------- state ---------- */
  function seed() {
    return {
      joined: false, loggedIn: false, onboarded: false,
      user: { name: 'Berivan', email: 'info@liman.co.uk', phone: '', role: 'Owner', contact: 'WhatsApp' },
      biz: { name: 'Liman Restaurant', type: 'Restaurant', address: '60 Penton Street, London N1 9PZ', site: 'liman.co.uk', phone: '020 3583 6442', billing: '' },
      team: [{ name: 'Berivan', email: 'info@liman.co.uk', role: 'Owner' }],
      proposal: { status: 'sent', extras: { booking: true, turkish: false }, signedName: '', signedAt: '' },
      stage: 1, // 0 Concept,1 Proposal,2 Design,3 Build,4 Review,5 Launch,6 Live
      approval: null, // {title, version, status}
      invoices: [],
      features: [
        { id: 'F1', title: 'Show the weekday lunch deal on the homepage', detail: 'From our call on 3 Nov.', status: 'included', price: 0 }
      ],
      tickets: [],
      meetings: [{
        id: 'M1', date: 'Tue 3 Nov · 11:30', title: 'Discovery call', confirmed: false,
        summary: 'Berivan wants more direct bookings and a site that always shows the right hours, menu and lunch deal.',
        decisions: ['Go with the Full website, based on the approved concept', 'Add a booking widget (optional extra in the proposal)', 'Keep the dark harbour colours and the Liman logo'],
        actions: ['Sites By MS: send the proposal (done)', 'Berivan: send 8–10 photos of food and the dining room', 'Berivan: confirm Sunday opening hours']
      }],
      chat: [
        { from: 'team', text: 'Hi Berivan, welcome to your portal! Your proposal is ready. Ask me anything here, any time.', at: 'Today 09:05' }
      ],
      files: [{ name: 'Liman-concept-homepage.pdf', size: '1.2 MB', by: 'Sites By MS', at: '4 Nov' }],
      wallet: { balance: 0, tx: [], topups: [] },
      activity: [
        { who: 'team', text: 'Sent your proposal', at: '4 Nov' },
        { who: 'team', text: 'Added meeting notes from the discovery call', at: '3 Nov' }
      ],
      seen: { chat: 1 },
      careOffered: false
    };
  }
  var S;
  function load() { try { S = JSON.parse(localStorage.getItem(KEY)) || seed(); } catch (e) { S = seed(); } }
  function save() { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) {} }
  load();

  /* ---------- helpers ---------- */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function gbp(n) { return '£' + Number(n).toLocaleString('en-GB', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }); }
  function now() { var d = new Date(); return 'Today ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); }
  function first(n) { return String(n || '').trim().split(/\s+/)[0] || 'there'; }
  function total() { var t = PRICE.base; if (S.proposal.extras.booking) t += PRICE.booking; if (S.proposal.extras.turkish) t += PRICE.turkish; return t; }
  function log(who, text) { S.activity.unshift({ who: who, text: text, at: now() }); S.activity = S.activity.slice(0, 30); }
  function toast(msg) {
    var old = document.querySelector('.toast'); if (old) old.remove();
    var t = document.createElement('div'); t.className = 'toast'; t.setAttribute('role', 'status'); t.textContent = msg;
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 3200);
  }
  function go(r) { if (location.hash === '#' + r) render(); else location.hash = r; }
  var STAGES = ['Concept', 'Proposal', 'Design', 'Build', 'Review', 'Launch'];
  var ICON = {
    home: 'M3 11l9-7 9 7v9a1 1 0 01-1 1h-5v-6h-6v6H4a1 1 0 01-1-1z',
    timeline: 'M4 6h16M4 12h10M4 18h6',
    doc: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
    spark: 'M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M6 18l2.5-2.5M15.5 8.5L18 6',
    ticket: 'M4 7h16v4a2 2 0 000 4v4H4v-4a2 2 0 000-4zM12 7v12',
    notes: 'M5 4h14v16H5zM9 8h6M9 12h6M9 16h4',
    chat: 'M4 5h16v11H9l-5 4z',
    folder: 'M3 6h6l2 2h10v11H3z',
    wallet: 'M3 7h18v12H3zM16 13h2M3 7l3-3h12v3',
    user: 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0',
    out: 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10',
    eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM12 15a3 3 0 100-6 3 3 0 000 6z',
    up: 'M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3',
    check: 'M5 12.5l4.5 4.5L19 7.5',
    pen: 'M4 20l4-1 11-11-3-3L5 16zM14 6l3 3',
    menu: 'M4 7h16M4 12h16M4 17h16',
    card: 'M3 6h18v12H3zM3 10h18'
  };
  function ic(n, s) { s = s || 20; return '<svg width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + ICON[n] + '"/></svg>'; }

  /* ---------- derived "to do" for the client ---------- */
  function todos() {
    var t = [];
    if (S.proposal.status !== 'signed') t.push({ icon: 'doc', title: 'Review and sign your proposal', sub: 'Sent 4 Nov · valid until 18 Nov', go: 'proposal', cta: 'Review', primary: true });
    S.invoices.forEach(function (i) { if (i.status === 'unpaid') t.push({ icon: 'card', title: 'Pay ' + i.title.toLowerCase(), sub: gbp(i.amount) + ' · bank transfer', go: 'wallet', cta: 'Pay', primary: true }); });
    if (S.approval && S.approval.status === 'waiting') t.push({ icon: 'eye', title: 'Approve: ' + S.approval.title, sub: 'Version ' + S.approval.version + ' · ready for you', go: 'timeline', cta: 'Review', primary: true });
    if (S.proposal.status === 'signed' && !S.files.some(function (f) { return f.by !== 'Sites By MS'; })) t.push({ icon: 'up', title: 'Upload food & dining-room photos', sub: 'At least 8 · we pick the best', go: 'files', cta: 'Upload' });
    S.features.forEach(function (f) { if (f.status === 'quoted') t.push({ icon: 'spark', title: 'Approve quote: ' + f.title, sub: gbp(f.price) + ' · feature request', go: 'features', cta: 'View' }); });
    S.meetings.forEach(function (m) { if (!m.confirmed) t.push({ icon: 'notes', title: 'Check the notes from your ' + m.title.toLowerCase(), sub: m.date, go: 'meetings', cta: 'Read' }); });
    return t;
  }
  function unreadChat() { return Math.max(0, S.chat.filter(function (m) { return m.from === 'team'; }).length - (S.seen.chat || 0)); }
  function teamTasks() {
    var t = [];
    S.invoices.forEach(function (i, n) { if (i.status === 'pending') t.push({ label: 'Confirm payment received: ' + i.title + ' (' + gbp(i.amount) + ')', act: 'tConfirmInv', arg: n }); });
    S.wallet.topups.forEach(function (w, n) { if (w.status === 'pending') t.push({ label: 'Confirm wallet top-up of ' + gbp(w.amount), act: 'tConfirmTop', arg: n }); });
    S.features.forEach(function (f, n) { if (f.status === 'requested') t.push({ label: 'Send a quote for "' + f.title + '"', act: 'tQuote', arg: n }); });
    S.tickets.forEach(function (k, n) { if (k.status === 'open') t.push({ label: 'Reply to ticket "' + k.subject + '"', act: 'tTicket', arg: n }); });
    if (S.approval && S.approval.status === 'changes') t.push({ label: 'Send updated version of ' + S.approval.title, act: 'tRevise', arg: 0 });
    return t;
  }

  /* ---------- shell ---------- */
  var NAV = [
    ['dashboard', 'Dashboard', 'home'], ['timeline', 'Project timeline', 'timeline'], ['proposal', 'Proposal & contract', 'doc'],
    ['features', 'Feature requests', 'spark'], ['tickets', 'Tickets', 'ticket'], ['meetings', 'Meeting notes', 'notes'],
    ['chat', 'Chat', 'chat'], ['files', 'Files', 'folder'], ['wallet', 'Wallet & invoices', 'wallet']
  ];
  function sideHTML(cur) {
    var badge = {
      proposal: S.proposal.status !== 'signed' ? '<span class="count">1</span>' : '',
      tickets: S.tickets.filter(function (k) { return k.status === 'answered'; }).length ? '<span class="count">' + S.tickets.filter(function (k) { return k.status === 'answered'; }).length + '</span>' : '',
      chat: unreadChat() ? '<span class="dot" aria-label="New messages"></span>' : '',
      wallet: S.invoices.some(function (i) { return i.status === 'unpaid'; }) ? '<span class="count">1</span>' : '',
      timeline: S.approval && S.approval.status === 'waiting' ? '<span class="count">1</span>' : ''
    };
    return '<aside class="side"><a class="brand" href="/" title="sitesbyms.com"><img src="/assets/brand/mark-sm-dark.png" alt=""><span>Sites By <b>MS</b></span></a>'
      + '<div class="proj"><small>Project</small><b>Liman · Full website</b></div><nav class="nav" aria-label="Portal">'
      + NAV.map(function (n) { return '<a href="#' + n[0] + '"' + (cur === n[0] ? ' aria-current="page"' : '') + '>' + ic(n[2], 19) + '<span>' + n[1] + '</span>' + (badge[n[0]] || '') + '</a>'; }).join('')
      + '<hr><a href="#profile"' + (cur === 'profile' ? ' aria-current="page"' : '') + '>' + ic('user', 19) + '<span>Profile &amp; business</span></a>'
      + '<a href="#" data-act="logout">' + ic('out', 19) + '<span>Log out</span></a></nav>'
      + '<div class="help">Need help? <a href="#chat">Chat with us</a> or WhatsApp +44 7405 760048</div></aside>';
  }
  function frame(cur, body) {
    var dot = unreadChat() || todos().length;
    var tabs = [['dashboard', 'Home', 'home'], ['timeline', 'Project', 'timeline'], ['chat', 'Chat', 'chat'], ['wallet', 'Wallet', 'wallet']];
    app.innerHTML = '<div class="mtop"><a class="brand" href="/"><img src="/assets/brand/mark-sm-dark.png" alt=""><span>Sites By <b>MS</b></span></a>'
      + '<button type="button" data-act="drawer" aria-label="Open menu">' + ic('menu', 22) + (dot ? '<span class="badge"></span>' : '') + '</button></div>'
      + '<div class="shell">' + sideHTML(cur) + '<main class="main" id="main" tabindex="-1">' + body + '</main></div>'
      + '<nav class="tabbar" aria-label="Quick">' + tabs.map(function (t) {
        var d = (t[0] === 'chat' && unreadChat()) || (t[0] === 'dashboard' && todos().length) ? '<span class="tb-dot"></span>' : '';
        return '<a href="#' + t[0] + '"' + (cur === t[0] ? ' aria-current="page"' : '') + '>' + ic(t[2], 22) + t[1] + d + '</a>';
      }).join('') + '</nav>';
    updateTeam();
  }
  function updateTeam() { var c = document.getElementById('teamCount'); if (c) c.textContent = teamTasks().length; }
  function pageHead(title, sub, right) { return '<div class="ph"><div><h1>' + title + '</h1>' + (sub ? '<p>' + sub + '</p>' : '') + '</div>' + (right || '') + '</div>'; }

  /* ---------- views: before login ---------- */
  function vInvite() {
    app.innerHTML = '<div class="center-card"><div class="mail" role="article" aria-label="Example invite email">'
      + '<div class="meta"><div><span>From</span> <b>Sites By MS</b> &lt;hello@sitesbyms.com&gt;</div><div><span>To</span> Berivan &lt;info@liman.co.uk&gt;</div><div style="font-size:17px;font-weight:800;margin-top:4px">Your proposal for Liman is ready</div></div>'
      + '<div class="brandbar"><img src="/assets/brand/mark-sm-dark.png" alt=""><span>Sites By <b>MS</b></span></div>'
      + '<h1 style="font-size:24px;font-weight:800">Thanks for the call, Berivan.</h1>'
      + '<p style="color:#353B5C">Your proposal is waiting in your project portal, with everything we discussed: pages, features, timeline and the fixed price. Create your account to read it, ask questions and approve.</p>'
      + '<div style="border:1px solid var(--line-3);border-radius:12px;padding:14px 16px;display:grid;gap:6px;font-size:14px;color:var(--muted)"><span>✓ Proposal &amp; contract to sign online</span><span>✓ Meeting notes from today\'s call</span><span>✓ Chat with the team any time</span></div>'
      + '<a class="btn btn-primary" href="#join">Open my portal</a>'
      + '<p style="font-size:13px;color:var(--muted-2)">This is the email a client gets after the call. In the demo, the button takes you straight in.</p></div>'
      + '<p style="color:#A9AECB;font-size:14px">Already set up? <a href="#login" style="color:#C9B8FF;font-weight:700">Log in</a></p></div>';
  }
  function vJoin() {
    app.innerHTML = '<div class="auth"><aside class="left"><div style="display:flex;align-items:center;gap:11px"><img src="/assets/brand/mark-sm-dark.png" alt="" style="height:34px"><b style="font-size:18px">Sites By <span style="color:var(--violet-on-navy)">MS</span> <span style="color:#A9AECB;font-weight:500">· Portal</span></b></div>'
      + '<div><p style="font-size:13px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--violet-on-navy);margin-bottom:10px">You\'re invited</p><h1 style="font-size:clamp(28px,3.4vw,38px);font-weight:800">Welcome, Berivan. Your Liman project is ready.</h1>'
      + '<p style="color:#C9CCE0;margin-top:14px">Set up your account to read your proposal, sign it, and follow every step of the build.</p></div>'
      + '<div class="waiting"><small style="color:#A9AECB;font-weight:600">Waiting for you</small><div><span>Proposal · Full website</span><span>New</span></div><div><span>Meeting notes · 3 Nov call</span><span>New</span></div><div><span>Your concept</span><span style="color:#A9AECB!important;font-weight:500!important">sitesbyms.com/liman</span></div></div>'
      + '<p class="fine" style="margin-top:auto;font-size:13px;color:#A9AECB">Questions? WhatsApp +44 7405 760048</p></aside>'
      + '<div class="right"><form id="joinForm" novalidate><div class="chips"><span class="on">1 Account</span><i></i><span>2 Business</span><i></i><span>3 Done</span></div>'
      + '<h2 style="font-size:28px;font-weight:800">Create your account</h2>'
      + '<label class="field" for="jEmail">Email<input id="jEmail" type="email" value="' + esc(S.user.email) + '" readonly></label>'
      + '<label class="field" for="jName">Your full name<input id="jName" autocomplete="name" value="' + esc(S.user.name) + '" required></label>'
      + '<label class="field" for="jPass">Password <span class="hint" id="pHint">At least 10 characters</span><input id="jPass" type="password" autocomplete="new-password" required></label>'
      + '<label class="check" for="jTerms"><input id="jTerms" type="checkbox"><span>I agree to the <a href="/privacy/" target="_blank" rel="noopener">privacy notice and project terms</a>.</span></label>'
      + '<p class="err" id="jErr" role="alert" hidden></p>'
      + '<button class="btn btn-primary btn-block" type="submit">Continue</button>'
      + '<p style="text-align:center;font-size:14px;color:var(--muted-2)">Already have an account? <a href="#login" style="font-weight:700">Log in</a></p></form></div></div>';
    var p = document.getElementById('jPass'), h = document.getElementById('pHint');
    p.addEventListener('input', function () { var ok = p.value.length >= 10; h.textContent = ok ? '✓ Strong enough' : (10 - p.value.length) + ' more characters'; h.style.color = ok ? 'var(--ok)' : ''; });
    p.focus();
    document.getElementById('joinForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var err = document.getElementById('jErr'), n = document.getElementById('jName').value.trim(), m = '';
      if (!n) m = 'Please add your name.'; else if (p.value.length < 10) m = 'Your password needs at least 10 characters.'; else if (!document.getElementById('jTerms').checked) m = 'Please tick the box to accept the terms.';
      if (m) { err.textContent = m; err.hidden = false; return; }
      S.user.name = n; S.joined = true; S.loggedIn = true; save(); go('welcome');
    });
  }
  function vWelcome() {
    app.innerHTML = '<div class="auth"><aside class="left"><div style="display:flex;align-items:center;gap:11px"><img src="/assets/brand/mark-sm-dark.png" alt="" style="height:34px"><b style="font-size:18px">Sites By <span style="color:var(--violet-on-navy)">MS</span></b></div>'
      + '<div><h1 style="font-size:clamp(28px,3.4vw,38px);font-weight:800">Nearly there, ' + esc(first(S.user.name)) + '.</h1><p style="color:#C9CCE0;margin-top:14px">We filled this in from your Google profile. Just check it\'s right. These details go on your website and your invoices.</p></div></aside>'
      + '<div class="right"><form id="wForm" novalidate><div class="chips"><span>1 Account ✓</span><i></i><span class="on">2 Business</span><i></i><span>3 Done</span></div>'
      + '<h2 style="font-size:28px;font-weight:800">Your business</h2>'
      + '<label class="field" for="wBiz">Business name<input id="wBiz" value="' + esc(S.biz.name) + '"></label>'
      + '<label class="field" for="wAddr">Address<input id="wAddr" value="' + esc(S.biz.address) + '"></label>'
      + '<div class="f2"><label class="field" for="wPhone">Business phone<input id="wPhone" type="tel" value="' + esc(S.biz.phone) + '"></label>'
      + '<label class="field" for="wMob">Your mobile / WhatsApp <span class="hint">optional</span><input id="wMob" type="tel" value="' + esc(S.user.phone) + '"></label></div>'
      + '<label class="field" for="wContact">Best way to reach you<select id="wContact"><option>WhatsApp</option><option>Email</option><option>Phone</option></select></label>'
      + '<button class="btn btn-primary btn-block" type="submit">Go to my portal</button>'
      + '<button class="link-btn" type="button" data-act="skipWelcome" style="justify-self:center">Skip for now</button></form></div></div>';
    document.getElementById('wContact').value = S.user.contact;
    document.getElementById('wForm').addEventListener('submit', function (e) {
      e.preventDefault();
      S.biz.name = document.getElementById('wBiz').value.trim() || S.biz.name;
      S.biz.address = document.getElementById('wAddr').value.trim(); S.biz.phone = document.getElementById('wPhone').value.trim();
      S.user.phone = document.getElementById('wMob').value.trim(); S.user.contact = document.getElementById('wContact').value;
      S.onboarded = true; log('me', 'Set up your account'); save(); go('dashboard'); toast('Welcome in! Your proposal is the first thing to look at.');
    });
  }
  function vLogin() {
    app.innerHTML = '<div class="center-card"><img src="/assets/brand/logo-on-dark.png" alt="Sites By MS" style="height:100px;width:auto">'
      + '<form id="lForm" novalidate><div><h1 style="font-size:26px;font-weight:800">Log in to your portal</h1><p style="color:var(--muted);font-size:15px;margin-top:4px">Your projects, messages and invoices.</p></div>'
      + '<label class="field" for="lEmail">Email<input id="lEmail" type="email" autocomplete="email" value="' + esc(S.user.email) + '"></label>'
      + '<label class="field" for="lPass"><span style="display:flex;justify-content:space-between"><span>Password</span><button class="link-btn" type="button" data-act="magic" style="font-size:13px">Forgot it?</button></span><input id="lPass" type="password" autocomplete="current-password" placeholder="Any password works in the demo"></label>'
      + '<button class="btn btn-primary btn-block" type="submit">Log in</button><div class="or">or</div>'
      + '<button class="btn btn-line btn-block" type="button" data-act="magic">Email me a login link</button>'
      + '<p style="font-size:13px;color:var(--muted-2);text-align:center">New here? Accounts are created when you receive a proposal. <a href="#invite">See the invite email</a></p></form></div>';
    document.getElementById('lForm').addEventListener('submit', function (e) { e.preventDefault(); S.loggedIn = true; S.joined = true; S.onboarded = true; save(); go('dashboard'); });
  }

  /* ---------- views: portal ---------- */
  function stagesHTML() {
    return '<div class="stages">' + STAGES.map(function (n, i) {
      var c = i < S.stage ? 'done' : i === S.stage ? 'now' : 'todo';
      return '<div class="' + c + '"><i></i><span>' + n + (c === 'done' ? ' ✓' : c === 'now' ? ' · now' : '') + '</span></div>';
    }).join('') + '</div>';
  }
  function statusLine() {
    if (S.stage >= 6) return ['Your website is live', 'ok', 'Live'];
    if (S.stage === 1) return ['Proposal stage · waiting for your signature', 'warn', 'Your turn'];
    var dep = S.invoices[0];
    if (S.stage === 2 && dep && dep.status !== 'paid') return ['Design starts when the deposit is confirmed', 'warn', dep.status === 'pending' ? 'Checking payment' : 'Deposit due'];
    if (S.stage === 5) { var fin = S.invoices[1]; return ['Ready to launch' + (fin && fin.status !== 'paid' ? ' after the final payment' : ''), 'warn', 'Almost there']; }
    return [STAGES[S.stage] + ' stage · launch planned for 24 November', '', 'On track'];
  }
  function vDashboard() {
    var t = todos(), st = statusLine(), dep = S.invoices[0], fin = S.invoices[1];
    var todoHTML = t.length ? t.map(function (x) {
      return '<div class="row"><span class="ico">' + ic(x.icon) + '</span><div class="grow"><b>' + esc(x.title) + '</b><small>' + esc(x.sub) + '</small></div><a class="btn btn-sm ' + (x.primary ? 'btn-primary' : 'btn-line') + '" href="#' + x.go + '">' + x.cta + '</a></div>';
    }).join('') : '<div class="empty"><b>You\'re all caught up</b>We\'ll let you know when something needs you.</div>';
    var lastTeam = S.chat.filter(function (m) { return m.from === 'team'; }).slice(-1)[0];
    var body = pageHead('Good to see you, ' + esc(first(S.user.name)), esc(S.biz.name) + ' · Full website', '<a class="btn btn-line" href="/liman/" target="_blank" rel="noopener">' + ic('eye', 18) + 'Open your concept</a>')
      + '<div class="grid">'
      + '<section class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><div><div class="kicker">Where things are</div><h2 style="font-size:21px">' + st[0] + '</h2></div><span class="pill ' + st[1] + '">' + st[2] + '</span></div>' + stagesHTML()
      + (S.stage >= 6 ? '<div class="signed">' + ic('check', 22) + '<div><b>liman.co.uk is live.</b> Congratulations! Share it with your guests.</div></div>' : '') + '</section>'
      + '<div class="g2"><section class="card"><div style="display:flex;justify-content:space-between;align-items:center"><h2>Needs you</h2>' + (t.length ? '<span class="pill warn">' + t.length + ' to do</span>' : '<span class="pill ok">All done</span>') + '</div><div>' + todoHTML + '</div></section>'
      + '<div class="grid">'
      + '<section class="card dark"><div class="kicker">Next meeting</div><div style="font-size:19px;font-weight:800">' + (S.proposal.status === 'signed' ? 'Design review · Mon 16 Nov, 14:00' : 'Not booked yet') + '</div>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap">' + (S.proposal.status === 'signed' ? '<button class="btn btn-sm btn-primary" type="button" data-act="joinCall">Join</button>' : '<a class="btn btn-sm btn-primary" href="#chat">Ask for a call</a>') + '<a class="btn btn-sm btn-ghost-dark" href="#meetings">Last notes</a></div></section>'
      + '<section class="card"><div style="display:flex;justify-content:space-between;align-items:center"><h2 style="font-size:17px">Money</h2><a href="#wallet" style="font-weight:700;font-size:14px;text-decoration:none">Wallet →</a></div><div style="display:grid;gap:10px;font-size:15px">'
      + '<div style="display:flex;justify-content:space-between;gap:10px"><span>Deposit</span>' + invState(dep, 'After signing') + '</div>'
      + '<div style="display:flex;justify-content:space-between;gap:10px"><span>Final payment</span>' + invState(fin, 'Before launch') + '</div>'
      + '<div style="display:flex;justify-content:space-between;gap:10px;border-top:1px solid var(--line-3);padding-top:10px"><span>Wallet balance</span><b class="num">' + gbp(S.wallet.balance) + '</b></div></div></section>'
      + '</div></div>'
      + '<div class="g2"><section class="card"><h2>Latest activity</h2><div>' + S.activity.slice(0, 5).map(function (a) {
        return '<div class="row"><span class="ava ' + (a.who === 'team' ? 'team">MS' : 'me">' + esc(first(S.user.name).charAt(0))) + '</span><div class="grow">' + (a.who === 'team' ? '<b>Sites By MS</b> ' : 'You ') + esc(a.text.charAt(0).toLowerCase() + a.text.slice(1)) + '<small>' + esc(a.at) + '</small></div></div>';
      }).join('') + '</div></section>'
      + '<section class="card"><div style="display:flex;justify-content:space-between;align-items:center"><h2 style="font-size:17px">Chat</h2><span style="font-size:13px;color:var(--ok);font-weight:700">● Team online</span></div>'
      + (lastTeam ? '<div class="bubble team" style="max-width:100%">' + esc(lastTeam.text) + '<small>Sites By MS · ' + esc(lastTeam.at) + '</small></div>' : '')
      + '<a class="btn btn-line" href="#chat">' + (unreadChat() ? 'Read new message' : 'Send a message') + '</a></section></div>'
      + '</div>';
    frame('dashboard', body);
  }
  function invState(i, none) {
    if (!i) return '<span class="sub">' + none + '</span>';
    if (i.status === 'paid') return '<b style="color:var(--ok)">Paid ✓</b>';
    if (i.status === 'pending') return '<span class="pill grey">Checking</span>';
    return '<a href="#wallet" style="font-weight:700">' + gbp(i.amount) + ' due</a>';
  }

  function vTimeline() {
    var items = [
      ['Concept', 'Your free homepage concept', 'Approved on the call'],
      ['Proposal', 'Proposal & contract', S.proposal.status === 'signed' ? 'Signed ' + S.proposal.signedAt : 'Waiting for your signature'],
      ['Design', 'Design of the homepage and inner pages', 'You approve the look'],
      ['Build', 'All pages built with your content', 'Menu, about, gallery, contact, booking'],
      ['Review', 'Your two rounds of changes', 'Check everything on your phone'],
      ['Launch', 'Go live on ' + S.biz.site, 'After the final payment']
    ];
    var appr = '';
    if (S.approval) {
      var a = S.approval;
      appr = '<section class="card tint"><div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><div><div class="kicker">Ready for you · version ' + a.version + '</div><h2 style="font-size:21px">' + esc(a.title) + '</h2></div>'
        + (a.status === 'waiting' ? '<span class="pill warn">Waiting for approval</span>' : a.status === 'changes' ? '<span class="pill grey">Changes requested</span>' : '<span class="pill ok">Approved</span>') + '</div>'
        + '<div class="preview-frame"><div class="bar"><i></i><i></i><i></i><span>sitesbyms.com/liman · v' + a.version + '</span></div><div class="shot"><span>Turkish Mediterranean · Angel</span><b>LIMAN</b></div></div>'
        + '<div style="display:flex;gap:10px;flex-wrap:wrap"><a class="btn btn-line" href="/liman/" target="_blank" rel="noopener">' + ic('eye', 18) + 'Open full preview</a>'
        + (a.status === 'waiting' ? '<button class="btn btn-primary" type="button" data-act="approve">' + ic('check', 18) + 'Approve</button><button class="btn btn-line" type="button" data-act="changes">Request changes</button>' : '')
        + '</div>' + (a.status === 'changes' ? '<p class="sub">We\'re working on your changes. You\'ll get a new version here, usually within a day.</p>' : '')
        + '<p class="sub">Approving moves the project to the next stage. You still get two rounds of changes in the review week.</p></section>';
    } else if (S.stage === 2 && S.invoices[0] && S.invoices[0].status !== 'paid') {
      appr = '<section class="card tint"><h2>Design starts after the deposit</h2><p class="sub">' + (S.invoices[0].status === 'pending' ? 'Thanks! We\'re checking your payment and will confirm it shortly.' : 'Pay the deposit by bank transfer and we start the same day.') + '</p>' + (S.invoices[0].status === 'unpaid' ? '<a class="btn btn-primary" href="#wallet" style="justify-self:start">Pay deposit</a>' : '') + '</section>';
    } else if (S.stage === 5 && S.invoices[1] && S.invoices[1].status !== 'paid') {
      appr = '<section class="card tint"><h2>Your site is ready to go live</h2><p class="sub">' + (S.invoices[1].status === 'pending' ? 'We\'re checking your final payment. Once confirmed, we put the site live.' : 'Pay the final invoice and we launch it on ' + esc(S.biz.site) + '.') + '</p>' + (S.invoices[1].status === 'unpaid' ? '<a class="btn btn-primary" href="#wallet" style="justify-self:start">Pay final invoice</a>' : '') + '</section>';
    }
    var body = pageHead('Project timeline', 'Every stage of your website, and what we need from you.')
      + '<div class="g2"><div class="grid">' + appr + '<section class="card"><ol class="tl">' + items.map(function (it, i) {
        var c = i < S.stage ? 'done' : i === S.stage ? 'now' : 'todo';
        return '<li class="' + c + '"><span class="mk">' + (c === 'done' ? ic('check', 18) : i + 1) + '</span><div style="padding-top:2px"><b>' + it[0] + ' · ' + esc(it[1]) + '</b><small class="sub">' + esc(c === 'todo' ? it[2] : c === 'now' ? 'Now · ' + it[2] : it[2]) + '</small></div></li>';
      }).join('') + '</ol></section></div>'
      + '<div class="grid"><section class="card"><h2 style="font-size:17px">Planned dates</h2><div style="display:grid;gap:8px;font-size:15px"><div class="line" style="padding:6px 0"><span>Design</span><span>9 – 13 Nov</span></div><div class="line" style="padding:6px 0"><span>Build</span><span>16 – 20 Nov</span></div><div class="line" style="padding:6px 0"><span>Review</span><span>23 – 24 Nov</span></div><div class="line" style="padding:6px 0;border:0"><span><b>Launch</b></span><b>24 Nov</b></div></div><p class="sub">Dates move only if something on your side is late, and we tell you first.</p></section>'
      + '<section class="card"><h2 style="font-size:17px">Want something extra?</h2><p class="sub">Ideas for new features are welcome at any stage.</p><a class="btn btn-line" href="#features">Request a feature</a></section></div></div>';
    frame('timeline', body);
  }

  function vProposal() {
    var signed = S.proposal.status === 'signed', ex = S.proposal.extras, t = total();
    var body = pageHead('Your website proposal', 'Proposal SBM-2026-014 · sent 4 November · valid until 18 November', signed ? '<span class="pill ok">Signed ✓</span>' : '<span class="pill warn">Waiting for your signature</span>')
      + '<div class="g2"><article class="doc"><div style="display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;align-items:flex-start"><img src="/assets/brand/logo-on-light.png" alt="Sites By MS" style="height:70px;width:auto"><div style="text-align:right;font-size:14px;color:var(--muted)"><b style="color:var(--ink)">Prepared for</b><br>' + esc(S.user.name) + ', ' + esc(S.biz.name) + '<br>' + esc(S.biz.address) + '</div></div>'
      + '<div style="display:grid;gap:8px"><h2 style="font-size:26px;font-weight:800">Full website for Liman</h2><p style="color:#353B5C">Based on our call on 3 November. Goal: more direct table bookings, and a site that always shows the right hours, menu and lunch deal.</p></div>'
      + '<section class="sec"><h2>What you get</h2><div class="incl"><span>✓ Homepage, from your approved concept</span><span>✓ Menu page with lunch deal</span><span>✓ About: Berivan &amp; Niyazi\'s story</span><span>✓ Gallery</span><span>✓ Contact, map &amp; opening hours</span><span>✓ Booking button on every page</span><span>✓ Google Business profile check-up</span><span>✓ Local SEO basics</span></div></section>'
      + '<section class="sec"><h2>Optional extras</h2>' + (signed ? '' : '<p class="sub">Tick what you\'d like added. The total updates straight away.</p>')
      + '<label class="opt" for="exB"><input id="exB" type="checkbox" data-extra="booking"' + (ex.booking ? ' checked' : '') + (signed ? ' disabled' : '') + '><span class="grow">Online booking widget, connected to your calendar</span><b class="num">' + gbp(PRICE.booking) + '</b></label>'
      + '<label class="opt" for="exT"><input id="exT" type="checkbox" data-extra="turkish"' + (ex.turkish ? ' checked' : '') + (signed ? ' disabled' : '') + '><span class="grow">Turkish version of the site</span><b class="num">' + gbp(PRICE.turkish) + '</b></label></section>'
      + '<section class="sec"><h2>Timeline</h2><div class="weeks"><div><b>Week 1</b><span style="font-weight:700">Design</span><small class="sub">You approve the look</small></div><div><b>Week 2</b><span style="font-weight:700">Build</span><small class="sub">All pages built</small></div><div><b>Week 3</b><span style="font-weight:700">Review</span><small class="sub">Two rounds of changes</small></div><div class="last"><b>Week 3</b><span style="font-weight:700">Launch</span><small style="display:block;font-size:13px;color:#A9AECB">Live on ' + esc(S.biz.site) + '</small></div></div><p class="sub">The timeline starts once the deposit is confirmed and we have your photos.</p></section>'
      + '<section class="sec"><h2>Price</h2><div><div class="line"><span>Full website</span><span class="num">' + gbp(PRICE.base) + '</span></div>'
      + (ex.booking ? '<div class="line"><span>Online booking widget</span><span class="num">' + gbp(PRICE.booking) + '</span></div>' : '')
      + (ex.turkish ? '<div class="line"><span>Turkish version</span><span class="num">' + gbp(PRICE.turkish) + '</span></div>' : '')
      + '<div class="line total"><span>Total</span><span class="num">' + gbp(t) + '</span></div></div>'
      + '<div class="g2e" style="gap:10px"><div style="border:1px solid var(--line-3);border-radius:12px;padding:14px"><small class="sub">Deposit · to start</small><b class="num" style="font-size:20px">' + gbp(t / 2) + '</b></div><div style="border:1px solid var(--line-3);border-radius:12px;padding:14px"><small class="sub">Final · before launch</small><b class="num" style="font-size:20px">' + gbp(t / 2) + '</b></div></div>'
      + '<p class="sub">Paid by bank transfer. We confirm each payment by hand and send you a receipt.</p></section>'
      + '<section class="sec"><h2>Terms in short</h2><div style="display:grid;gap:6px;font-size:15px;color:#353B5C"><span>• You own your domain, content and photos, and the site once it\'s paid in full.</span><span>• Two rounds of changes are included in the review week.</span><span>• Anything new is quoted first and only charged if you approve it.</span></div><a href="/privacy/#terms" target="_blank" rel="noopener" style="font-weight:700;font-size:14px">Read the full project terms</a></section>'
      + (signed
        ? '<div class="signed">' + ic('check', 24) + '<div><b>Signed by ' + esc(S.proposal.signedName) + '</b> · ' + esc(S.proposal.signedAt) + '<br><span style="font-size:14px">A copy is in your Files. Your deposit invoice is in your <a href="#wallet">wallet</a>.</span></div></div>'
        : '<form class="sign" id="signForm" novalidate><h2 style="font-size:22px;font-weight:800">Accept &amp; sign</h2><label class="field" for="sName">Type your full name to sign<input id="sName" autocomplete="name" value="" placeholder="' + esc(S.user.name) + '" style="font-size:19px;font-weight:600"></label>'
          + '<label class="check" for="sOk"><input id="sOk" type="checkbox"><span>I accept this proposal and the project terms on behalf of ' + esc(S.biz.name) + '.</span></label><p class="err" id="sErr" role="alert" hidden style="color:#FFB4A8"></p>'
          + '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between"><span style="font-size:13px;color:#A9AECB">Signed online · the date and time are recorded</span><button class="btn btn-primary" type="submit">Sign for ' + gbp(t) + '</button></div></form>')
      + '</article><aside class="grid"><section class="card"><b>Questions about this proposal?</b><p class="sub">Ask in chat. If anything changes, we update the proposal here.</p><a class="btn btn-line" href="#chat">Ask in chat</a></section>'
      + '<section class="card"><b>History</b><div style="display:grid;gap:6px;font-size:14px;color:var(--muted)"><span>4 Nov · Proposal sent</span><span>Today · You opened it</span>' + (signed ? '<span>' + esc(S.proposal.signedAt) + ' · Signed</span>' : '') + '</div></section>'
      + '<button class="btn btn-line" type="button" data-act="pdf">Download PDF</button><p class="sub" style="font-size:12px">Prices in this demo are examples.</p></aside></div>';
    frame('proposal', body);
    Array.prototype.forEach.call(document.querySelectorAll('[data-extra]'), function (c) {
      c.addEventListener('change', function () { S.proposal.extras[c.getAttribute('data-extra')] = c.checked; save(); vProposal(); var el = document.getElementById(c.id); if (el) el.focus(); });
    });
    var f = document.getElementById('signForm');
    if (f) f.addEventListener('submit', function (e) {
      e.preventDefault();
      var n = document.getElementById('sName').value.trim(), err = document.getElementById('sErr');
      if (n.length < 2) { err.textContent = 'Please type your full name to sign.'; err.hidden = false; return; }
      if (!document.getElementById('sOk').checked) { err.textContent = 'Please tick the box to accept the proposal.'; err.hidden = false; return; }
      var d = new Date(); S.proposal.status = 'signed'; S.proposal.signedName = n;
      S.proposal.signedAt = d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ', ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
      S.stage = 2;
      S.invoices = [{ id: 'INV-001', title: 'Deposit (50%)', amount: t / 2, status: 'unpaid' }, { id: 'INV-002', title: 'Final payment (50%)', amount: t / 2, status: 'later' }];
      S.files.unshift({ name: 'Liman-proposal-signed.pdf', size: '220 KB', by: 'Sites By MS', at: 'Today' });
      log('me', 'Signed the proposal'); S.chat.push({ from: 'team', text: 'Thank you for signing, ' + first(S.user.name) + '! Your deposit invoice is in your wallet. As soon as it\'s confirmed we start the design.', at: now() });
      save(); go('wallet'); toast('Signed. Next step: the deposit.');
    });
  }

  function vFeatures() {
    var lab = { included: ['Included', 'ok'], requested: ['We\'re pricing it', 'grey'], quoted: ['Quote ready', 'warn'], approved: ['Approved · added', 'ok'], declined: ['Not now', 'grey'] };
    var body = pageHead('Feature requests', 'Ask for anything new. We reply with a price and time, and nothing is added until you approve it.')
      + '<div class="g2"><section class="card"><h2>Your requests</h2><div>' + S.features.slice().reverse().map(function (f) {
        var i = S.features.indexOf(f), l = lab[f.status];
        return '<div class="row" style="align-items:flex-start"><span class="ico">' + ic('spark') + '</span><div class="grow"><b>' + esc(f.title) + '</b><small>' + esc(f.detail || '') + '</small>'
          + (f.status === 'quoted' ? '<div style="margin-top:10px;background:var(--violet-tint);border-radius:12px;padding:12px 14px;display:grid;gap:10px"><div style="display:flex;justify-content:space-between;gap:10px"><span>Our quote</span><b class="num">' + gbp(f.price) + ' · ' + esc(f.days) + '</b></div><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-sm btn-primary" type="button" data-act="fApprove" data-i="' + i + '">Approve &amp; add to wallet bill</button><button class="btn btn-sm btn-line" type="button" data-act="fDecline" data-i="' + i + '">Not now</button></div></div>' : '')
          + '</div><span class="pill ' + l[1] + '">' + l[0] + '</span></div>';
      }).join('') + '</div></section>'
      + '<form class="card" id="fForm" novalidate><h2>Request a feature</h2><label class="field" for="fTitle">What would you like?<input id="fTitle" placeholder="e.g. A Christmas menu page"></label>'
      + '<label class="field" for="fDetail">Tell us a bit more <span class="hint">optional</span><textarea id="fDetail" placeholder="What should guests be able to do?"></textarea></label>'
      + '<p class="err" id="fErr" role="alert" hidden></p><button class="btn btn-primary" type="submit">Send request</button><p class="sub">We usually reply with a quote within one working day.</p></form></div>';
    frame('features', body);
    document.getElementById('fForm').addEventListener('submit', function (e) {
      e.preventDefault(); var t = document.getElementById('fTitle').value.trim();
      if (!t) { var er = document.getElementById('fErr'); er.textContent = 'Please tell us what you\'d like.'; er.hidden = false; return; }
      S.features.push({ id: 'F' + (S.features.length + 1), title: t, detail: document.getElementById('fDetail').value.trim(), status: 'requested' });
      log('me', 'Requested "' + t + '"'); save(); vFeatures(); toast('Request sent. You\'ll get a quote here.');
    });
  }

  function vTickets() {
    var open = S._ticket;
    var lab = { open: ['Waiting for us', 'grey'], answered: ['We replied', 'warn'], closed: ['Solved', 'ok'] };
    var list = S.tickets.length ? S.tickets.slice().reverse().map(function (k) {
      var i = S.tickets.indexOf(k), l = lab[k.status], isOpen = open === i;
      return '<div class="row" style="align-items:flex-start;flex-wrap:wrap"><span class="ico">' + ic('ticket') + '</span><div class="grow"><button class="link-btn" type="button" data-act="tOpen" data-i="' + i + '" style="text-decoration:none;color:var(--ink);font-size:15.5px;text-align:left">#' + (101 + i) + ' · ' + esc(k.subject) + '</button><small>' + esc(k.cat) + ' · ' + esc(k.at) + '</small>'
        + (isOpen ? '<div style="display:grid;gap:10px;margin-top:12px">' + k.messages.map(function (m) { return '<div class="bubble ' + m.from + '" style="max-width:100%">' + esc(m.text) + '<small>' + (m.from === 'team' ? 'Sites By MS' : 'You') + ' · ' + esc(m.at) + '</small></div>'; }).join('')
          + (k.status !== 'closed' ? '<form data-reply="' + i + '" style="display:grid;gap:8px"><label class="sr-only" for="tr' + i + '">Reply</label><textarea id="tr' + i + '" placeholder="Write a reply…" style="min-height:70px"></textarea><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-sm btn-primary" type="submit">Reply</button>' + (k.status === 'answered' ? '<button class="btn btn-sm btn-line" type="button" data-act="tClose" data-i="' + i + '">Mark as solved</button>' : '') + '</div></form>' : '') + '</div>' : '')
        + '</div><span class="pill ' + l[1] + '">' + l[0] + '</span></div>';
    }).join('') : '<div class="empty"><b>No tickets yet</b>Something not working, or want a change after launch? Open a ticket.</div>';
    var body = pageHead('Tickets', 'For problems and changes. Each one is tracked until it\'s solved.')
      + '<div class="g2"><section class="card"><h2>Your tickets</h2><div>' + list + '</div></section>'
      + '<form class="card" id="kForm" novalidate><h2>Open a ticket</h2><label class="field" for="kSub">Subject<input id="kSub" placeholder="e.g. Sunday hours are wrong"></label>'
      + '<label class="field" for="kCat">Type<select id="kCat"><option>Change to content</option><option>Something isn\'t working</option><option>Question</option></select></label>'
      + '<label class="field" for="kMsg">Details<textarea id="kMsg" placeholder="What happened, and on which page?"></textarea></label>'
      + '<label class="check" for="kUrg"><input id="kUrg" type="checkbox"><span>It\'s urgent: guests can\'t book or see the site</span></label>'
      + '<p class="err" id="kErr" role="alert" hidden></p><button class="btn btn-primary" type="submit">Open ticket</button></form></div>';
    frame('tickets', body);
    document.getElementById('kForm').addEventListener('submit', function (e) {
      e.preventDefault(); var s = document.getElementById('kSub').value.trim(), m = document.getElementById('kMsg').value.trim();
      if (!s || !m) { var er = document.getElementById('kErr'); er.textContent = 'Please add a subject and the details.'; er.hidden = false; return; }
      S.tickets.push({ subject: s, cat: document.getElementById('kCat').value + (document.getElementById('kUrg').checked ? ' · urgent' : ''), at: now(), status: 'open', messages: [{ from: 'me', text: m, at: now() }] });
      S._ticket = S.tickets.length - 1; log('me', 'Opened ticket "' + s + '"'); save(); vTickets(); toast('Ticket #' + (100 + S.tickets.length) + ' opened.');
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-reply]'), function (f) {
      f.addEventListener('submit', function (e) {
        e.preventDefault(); var i = +f.getAttribute('data-reply'), ta = f.querySelector('textarea'), v = ta.value.trim(); if (!v) return;
        S.tickets[i].messages.push({ from: 'me', text: v, at: now() }); S.tickets[i].status = 'open'; save(); vTickets();
      });
    });
  }

  function vMeetings() {
    var body = pageHead('Meeting notes', 'A short record of every call: what we decided and who does what.', '<a class="btn btn-line" href="#chat">Ask for a call</a>')
      + '<div class="grid">' + S.meetings.map(function (m, i) {
        return '<section class="card note-meeting"><div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center"><div><div class="kicker">' + esc(m.date) + '</div><h3>' + esc(m.title) + '</h3></div>' + (m.confirmed ? '<span class="pill ok">Confirmed by you</span>' : '<span class="pill warn">Please check</span>') + '</div>'
          + '<p style="color:#353B5C">' + esc(m.summary) + '</p><div class="g2e"><div><b>Decided</b><ul>' + m.decisions.map(function (d) { return '<li>' + esc(d) + '</li>'; }).join('') + '</ul></div><div><b>Next steps</b><ul>' + m.actions.map(function (d) { return '<li>' + esc(d) + '</li>'; }).join('') + '</ul></div></div>'
          + (m.confirmed ? '' : '<div style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn btn-primary btn-sm" type="button" data-act="mOk" data-i="' + i + '">' + ic('check', 18) + 'Looks right</button><a class="btn btn-line btn-sm" href="#chat">Something\'s missing</a></div>') + '</section>';
      }).join('') + '</div>';
    frame('meetings', body);
  }

  function vChat() {
    S.seen.chat = S.chat.filter(function (m) { return m.from === 'team'; }).length; save();
    var body = pageHead('Chat', 'Message the team directly. We usually reply within an hour in working time.')
      + '<div class="chat"><div class="head"><span class="ava team">MS</span><div class="grow"><b>Sites By MS team</b><small class="sub" style="color:var(--ok);font-weight:700">● Online</small></div></div>'
      + '<div class="msgs" id="msgs">' + S.chat.map(function (m) { return '<div class="bubble ' + m.from + '">' + esc(m.text) + '<small>' + (m.from === 'team' ? 'Sites By MS' : 'You') + ' · ' + esc(m.at) + '</small></div>'; }).join('') + '<div class="typing" id="typing" hidden>Sites By MS is typing…</div></div>'
      + '<form id="cForm"><label class="sr-only" for="cMsg">Message</label><textarea id="cMsg" placeholder="Write a message…" rows="1"></textarea><button class="btn btn-primary" type="submit">Send</button></form></div>';
    frame('chat', body);
    var box = document.getElementById('msgs'); box.scrollTop = box.scrollHeight;
    var ta = document.getElementById('cMsg');
    ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); document.getElementById('cForm').requestSubmit(); } });
    document.getElementById('cForm').addEventListener('submit', function (e) {
      e.preventDefault(); var v = ta.value.trim(); if (!v) return;
      S.chat.push({ from: 'me', text: v, at: now() }); save(); vChat();
      var ty = document.getElementById('typing'); ty.hidden = false; document.getElementById('msgs').scrollTop = 1e9;
      setTimeout(function () {
        S.chat.push({ from: 'team', text: reply(v), at: now() }); save();
        if ((location.hash || '').replace('#', '') === 'chat') vChat(); else { render(); toast('New message from Sites By MS'); }
      }, 1800);
    });
    ta.focus();
  }
  function reply(v) {
    v = v.toLowerCase();
    if (/price|cost|how much|£/.test(v)) return 'Good question! Everything is fixed in your proposal, and anything new gets its own quote before we do it. Nothing is charged without your OK.';
    if (/photo|picture|image/.test(v)) return 'Perfect, you can drop photos straight into Files. Phone photos are fine; we\'ll pick and edit the best ones.';
    if (/call|meet|talk/.test(v)) return 'Happy to! I\'ve pencilled in Mon 16 Nov, 14:00 for the design review. Does that work, or shall I send other times?';
    if (/menu|hour|time/.test(v)) return 'Noted. We\'ll make sure the menu and hours match your Google profile everywhere. Send the latest menu as a PDF or photo if you have it.';
    return 'Thanks ' + first(S.user.name) + ', got it. I\'ll look into this today and update you here.';
  }

  function vFiles() {
    var need = [['Logo file', /logo/i], ['8+ food & dining-room photos', /jpe?g|png|heic|webp|photo/i], ['Latest menu', /menu/i]];
    var mine = S.files.filter(function (f) { return f.by !== 'Sites By MS'; });
    var body = pageHead('Files', 'Everything for your site in one place: your photos and logo, and what we deliver.')
      + '<div class="g2"><div class="grid"><section class="card"><h2>Upload</h2><label class="drop" id="drop" for="fileIn">' + ic('up', 28) + '<b style="color:var(--ink)">Drop files here or tap to choose</b><span style="font-size:14px">Photos, logo, menu · JPG, PNG, PDF</span></label><input id="fileIn" type="file" multiple class="sr-only"></section>'
      + '<section class="card filelist"><h2>All files</h2><div>' + S.files.map(function (f) {
        var ext = (f.name.split('.').pop() || '').toUpperCase().slice(0, 4);
        return '<div class="row"><span class="ico">' + esc(ext) + '</span><div class="grow"><b style="word-break:break-all">' + esc(f.name) + '</b><small>' + esc(f.size) + ' · ' + (f.by === 'Sites By MS' ? 'from Sites By MS' : 'uploaded by you') + ' · ' + esc(f.at) + '</small></div></div>';
      }).join('') + '</div></section></div>'
      + '<section class="card"><h2 style="font-size:17px">What we need from you</h2><div style="display:grid;gap:10px">' + need.map(function (n) {
        var ok = mine.some(function (f) { return n[1].test(f.name); });
        return '<div style="display:flex;gap:10px;align-items:center"><span class="pill ' + (ok ? 'ok">✓' : 'grey">–') + '</span><span>' + n[0] + '</span></div>';
      }).join('') + '</div><p class="sub">No logo file? No problem, we can redraw it from a clear photo of your sign.</p></section></div>';
    frame('files', body);
    var inp = document.getElementById('fileIn'), drop = document.getElementById('drop');
    function add(list) {
      Array.prototype.forEach.call(list, function (f) { S.files.unshift({ name: f.name, size: f.size > 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB', by: 'me', at: 'Today' }); });
      if (list.length) { log('me', 'Uploaded ' + list.length + ' file' + (list.length > 1 ? 's' : '')); save(); vFiles(); toast(list.length + ' file' + (list.length > 1 ? 's' : '') + ' uploaded. (Demo: only the names are kept.)'); }
    }
    inp.addEventListener('change', function () { add(inp.files); });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
    drop.addEventListener('drop', function (e) { add(e.dataTransfer.files); });
  }

  function vWallet() {
    var lab = { unpaid: ['Due', 'warn'], pending: ['We\'re checking', 'grey'], paid: ['Paid', 'ok'], later: ['Due before launch', 'grey'] };
    var inv = S.invoices.length ? S.invoices.map(function (i, n) {
      var l = lab[i.status];
      return '<div class="row"><span class="ico">' + ic('doc') + '</span><div class="grow"><b>' + esc(i.title) + '</b><small>' + esc(i.id) + (i.ref ? ' · ref ' + esc(i.ref) : '') + '</small></div><b class="num">' + gbp(i.amount) + '</b><span class="pill ' + l[1] + '">' + l[0] + '</span>'
        + (i.status === 'unpaid' ? '<button class="btn btn-sm btn-primary" type="button" data-act="pay" data-i="' + n + '">Pay</button>' : i.status === 'paid' ? '<button class="btn btn-sm btn-line" type="button" data-act="pdf">Receipt</button>' : '') + '</div>';
    }).join('') : '<div class="empty"><b>No invoices yet</b>Your deposit invoice appears here when you sign the proposal.</div>';
    var tx = S.wallet.tx.length ? S.wallet.tx.map(function (t) { return '<div class="line"><span>' + esc(t.text) + '<small class="sub">' + esc(t.at) + '</small></span><b class="num" style="color:' + (t.amount > 0 ? 'var(--ok)' : 'var(--ink)') + '">' + (t.amount > 0 ? '+' : '−') + gbp(Math.abs(t.amount)) + '</b></div>'; }).join('') : '<p class="sub">No wallet movements yet.</p>';
    var body = pageHead('Wallet &amp; invoices', 'Pay invoices by bank transfer and keep credit for your care plan and small extras.')
      + '<div class="g2"><section class="card"><h2>Invoices</h2><div>' + inv + '</div><p class="sub">We confirm each payment by hand, usually within a few hours on working days, and email you a receipt.</p></section>'
      + '<div class="grid"><section class="card dark"><div class="kicker">Wallet balance</div><div class="balance">' + gbp(S.wallet.balance) + '</div><p style="color:#C9CCE0;font-size:14px">Used for your care plan and small extras you approve.</p>'
      + (S.wallet.topups.some(function (w) { return w.status === 'pending'; }) ? '<span class="pill grey" style="justify-self:start">Top-up being checked</span>' : '<button class="btn btn-primary" type="button" data-act="topup" style="justify-self:start">Add credit</button>') + '</section>'
      + '<section class="card"><h2 style="font-size:17px">Wallet history</h2>' + tx + '</section></div></div>';
    frame('wallet', body);
  }

  function vProfile() {
    var body = pageHead('Profile &amp; business', 'These details go on your website, proposals and invoices.', '<button class="btn btn-primary" type="button" data-act="saveProfile">Save changes</button>')
      + '<div class="grid" style="max-width:900px"><section class="card"><h2>Business</h2><div class="f2"><label class="field" for="pBiz">Business name<input id="pBiz" value="' + esc(S.biz.name) + '"></label><label class="field" for="pType">Type of business<input id="pType" value="' + esc(S.biz.type) + '"></label></div>'
      + '<label class="field" for="pAddr">Address<input id="pAddr" value="' + esc(S.biz.address) + '"></label><div class="f2"><label class="field" for="pSite">Website<input id="pSite" value="' + esc(S.biz.site) + '"></label><label class="field" for="pPh">Business phone<input id="pPh" type="tel" value="' + esc(S.biz.phone) + '"></label></div>'
      + '<label class="field" for="pBill">Billing details for invoices <span class="hint">optional</span><input id="pBill" value="' + esc(S.biz.billing) + '" placeholder="Company name, VAT number"></label></section>'
      + '<section class="card"><h2>You</h2><div class="f2"><label class="field" for="uName">Full name<input id="uName" value="' + esc(S.user.name) + '"></label><label class="field" for="uRole">Role<input id="uRole" value="' + esc(S.user.role) + '"></label></div>'
      + '<div class="f2"><label class="field" for="uEmail">Email<input id="uEmail" type="email" value="' + esc(S.user.email) + '"></label><label class="field" for="uPh">Mobile / WhatsApp<input id="uPh" type="tel" value="' + esc(S.user.phone) + '"></label></div>'
      + '<label class="field" for="uC">Best way to reach you<select id="uC"><option>WhatsApp</option><option>Email</option><option>Phone</option></select></label></section>'
      + '<section class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><div><h2>Team</h2><p class="sub">Invite a partner or manager to see the project too.</p></div><button class="btn btn-line btn-sm" type="button" data-act="invite">Invite someone</button></div><div>'
      + S.team.map(function (m) { return '<div class="row"><span class="ava me">' + esc(m.name.charAt(0)) + '</span><div class="grow"><b>' + esc(m.name) + '</b><small>' + esc(m.email) + '</small></div><span class="pill ' + (m.role === 'Owner' ? 'dark' : 'grey') + '">' + esc(m.role) + '</span></div>'; }).join('') + '</div></section>'
      + '<div class="g2e"><section class="card"><h2>Security</h2><div style="display:flex;justify-content:space-between"><span>Password</span><button class="link-btn" type="button" data-act="pw">Change</button></div><div style="display:flex;justify-content:space-between"><span>Login code by email</span><span class="pill ok">On</span></div><small class="sub">Last login: today · London</small></section>'
      + '<section class="card"><h2>Notifications</h2><label class="check" for="n1"><input id="n1" type="checkbox" checked>Something is ready for my approval</label><label class="check" for="n2"><input id="n2" type="checkbox" checked>New chat message</label><label class="check" for="n3"><input id="n3" type="checkbox" checked>Invoices and payment confirmations</label></section></div></div>';
    frame('profile', body);
    document.getElementById('uC').value = S.user.contact;
  }

  /* ---------- modals ---------- */
  function modal(html, onMount) {
    var bg = document.createElement('div'); bg.className = 'modal-bg';
    bg.innerHTML = '<div class="modal" role="dialog" aria-modal="true">' + html + '</div>';
    document.body.appendChild(bg);
    var prev = document.activeElement;
    function close() { bg.remove(); document.removeEventListener('keydown', key); if (prev && prev.focus) prev.focus(); }
    function key(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', key);
    bg.addEventListener('click', function (e) { if (e.target === bg || e.target.closest('[data-close]')) close(); });
    var f = bg.querySelector('input,button,textarea,select'); if (f) f.focus();
    if (onMount) onMount(bg, close);
    return close;
  }
  function payModal(n) {
    var i = S.invoices[n];
    modal('<h2>Pay ' + esc(i.title.toLowerCase()) + '</h2><p class="sub">Send <b style="color:var(--ink)">' + gbp(i.amount) + '</b> by bank transfer to:</p>'
      + '<div class="bank"><div><span>Account name</span><b>Sites By MS</b></div><div><span>Sort code</span><b>[SORT CODE]</b></div><div><span>Account no.</span><b>[ACCOUNT]</b></div><div><span>Reference</span><b>' + esc(i.id) + '</b></div></div>'
      + '<button class="btn btn-line btn-sm" type="button" data-copy="' + esc(i.id) + '" style="justify-self:start">Copy reference</button>'
      + '<label class="field" for="payRef">Payment reference or note <span class="hint">optional</span><input id="payRef" placeholder="e.g. paid from Liman Ltd, 6 Nov"></label>'
      + '<label class="field" for="payFile">Receipt or screenshot <span class="hint">optional, helps us confirm faster</span><input id="payFile" type="file" accept="image/*,.pdf"></label>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end"><button class="btn btn-line" type="button" data-close>Later</button><button class="btn btn-primary" type="button" id="paid">I\'ve paid</button></div>',
      function (bg, close) {
        bg.querySelector('#paid').onclick = function () {
          i.status = 'pending'; i.ref = bg.querySelector('#payRef').value.trim().slice(0, 60);
          log('me', 'Marked ' + i.title.toLowerCase() + ' as paid'); save(); close(); render();
          toast('Thanks! We\'ll confirm your payment shortly. (Demo: use "Act as the team".)');
        };
        bg.querySelector('[data-copy]').onclick = function (e) { var b = e.currentTarget; try { navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () { b.textContent = 'Copied ✓'; }, function () { b.textContent = 'Reference: ' + b.getAttribute('data-copy'); }); } catch (x) { b.textContent = 'Reference: ' + b.getAttribute('data-copy'); } };
      });
  }
  function topupModal() {
    modal('<h2>Add credit to your wallet</h2><p class="sub">Credit pays for your care plan and small extras. Transfer the amount, then tell us here.</p>'
      + '<div class="g3" style="gap:8px">' + [50, 100, 250].map(function (a) { return '<label class="opt" for="tu' + a + '"><input id="tu' + a + '" type="radio" name="tu" value="' + a + '"' + (a === 100 ? ' checked' : '') + '><b class="num">' + gbp(a) + '</b></label>'; }).join('') + '</div>'
      + '<div class="bank"><div><span>Account name</span><b>Sites By MS</b></div><div><span>Sort code</span><b>[SORT CODE]</b></div><div><span>Account no.</span><b>[ACCOUNT]</b></div><div><span>Reference</span><b>WALLET-LIMAN</b></div></div>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end"><button class="btn btn-line" type="button" data-close>Cancel</button><button class="btn btn-primary" type="button" id="tuOk">I\'ve sent it</button></div>',
      function (bg, close) {
        bg.querySelector('#tuOk').onclick = function () {
          var a = +bg.querySelector('input[name="tu"]:checked').value;
          S.wallet.topups.push({ amount: a, status: 'pending' }); log('me', 'Asked to add ' + gbp(a) + ' credit'); save(); close(); render(); toast('Top-up sent for checking.');
        };
      });
  }
  function changesModal() {
    modal('<h2>What should we change?</h2><p class="sub">Be as specific as you like. Point to the section, and tell us what feels off.</p>'
      + '<label class="field" for="chTxt">Your changes<textarea id="chTxt" placeholder="e.g. Make the lunch deal bigger on phones, and use the photo of the grill at the top."></textarea></label>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap"><button class="btn btn-line" type="button" data-close>Cancel</button><button class="btn btn-primary" type="button" id="chOk">Send changes</button></div>',
      function (bg, close) {
        bg.querySelector('#chOk').onclick = function () {
          var v = bg.querySelector('#chTxt').value.trim(); if (!v) { bg.querySelector('#chTxt').focus(); return; }
          S.approval.status = 'changes'; S.chat.push({ from: 'me', text: 'Changes for ' + S.approval.title + ': ' + v, at: now() });
          log('me', 'Asked for changes to ' + S.approval.title); save(); close(); render(); toast('Changes sent. We\'ll post a new version here.');
        };
      });
  }
  function inviteModal() {
    modal('<h2>Invite someone</h2><div class="f2"><label class="field" for="iName">Name<input id="iName"></label><label class="field" for="iEmail">Email<input id="iEmail" type="email"></label></div>'
      + '<label class="field" for="iRole">Access<select id="iRole"><option>Can view &amp; comment</option><option>Can approve &amp; pay</option></select></label>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end"><button class="btn btn-line" type="button" data-close>Cancel</button><button class="btn btn-primary" type="button" id="iOk">Send invite</button></div>',
      function (bg, close) {
        bg.querySelector('#iOk').onclick = function () {
          var n = bg.querySelector('#iName').value.trim(), e = bg.querySelector('#iEmail').value.trim();
          if (!n || !/@/.test(e)) { bg.querySelector(n ? '#iEmail' : '#iName').focus(); return; }
          S.team.push({ name: n, email: e + ' · invite sent', role: bg.querySelector('#iRole').value }); save(); close(); render(); toast('Invite sent to ' + n + '.');
        };
      });
  }
  function teamModal() {
    var t = teamTasks();
    modal('<h2>Act as the team</h2><p class="sub">In the real system, you and your sister do these in the admin panel. Here you can play that side to see what the client experiences.</p>'
      + (t.length ? '<div style="display:grid;gap:8px">' + t.map(function (x, n) { return '<button class="btn btn-line" type="button" data-t="' + n + '" style="justify-content:flex-start;white-space:normal;text-align:left;height:auto;padding:12px 14px;line-height:1.4">' + esc(x.label) + '</button>'; }).join('') + '</div>' : '<div class="empty"><b>Nothing waiting for the team</b>Do something as the client first: sign, pay, request a feature, open a ticket…</div>')
      + '<button class="btn btn-dark" type="button" data-close>Close</button>',
      function (bg, close) {
        Array.prototype.forEach.call(bg.querySelectorAll('[data-t]'), function (b) { b.onclick = function () { var x = t[+b.getAttribute('data-t')]; TEAM[x.act](x.arg); save(); close(); render(); }; });
      });
  }
  var TEAM = {
    tConfirmInv: function (n) {
      var i = S.invoices[n]; i.status = 'paid'; log('team', 'Confirmed your payment for ' + i.title.toLowerCase());
      S.files.unshift({ name: 'Receipt-' + i.id + '.pdf', size: '90 KB', by: 'Sites By MS', at: 'Today' });
      if (n === 0 && S.stage === 2 && !S.approval) { S.approval = { title: 'Homepage design', version: 1, status: 'waiting' }; S.chat.push({ from: 'team', text: 'Deposit received, thank you! 🎉 Your homepage design is ready for you in Project timeline.', at: now() }); }
      if (n === 1 && S.stage === 5) { S.stage = 6; S.chat.push({ from: 'team', text: 'Final payment received. ' + S.biz.site + ' is now live! Congratulations. Want us to keep it updated with a care plan?', at: now() }); }
      toast('Client sees: payment confirmed, receipt in Files.');
    },
    tConfirmTop: function (n) { var w = S.wallet.topups[n]; w.status = 'paid'; S.wallet.balance += w.amount; S.wallet.tx.unshift({ text: 'Credit added (bank transfer)', amount: w.amount, at: now() }); log('team', 'Added ' + gbp(w.amount) + ' to your wallet'); toast('Client sees: wallet credited.'); },
    tQuote: function (n) { var f = S.features[n]; f.status = 'quoted'; f.price = 120; f.days = '2 working days'; log('team', 'Sent a quote for "' + f.title + '"'); toast('Client sees: quote ready.'); },
    tTicket: function (n) { var k = S.tickets[n]; k.status = 'answered'; k.messages.push({ from: 'team', text: 'Thanks for flagging this. It\'s fixed now. Can you check on your phone and mark it solved?', at: now() }); log('team', 'Replied to ticket "' + k.subject + '"'); toast('Client sees: ticket answered.'); },
    tRevise: function () { S.approval.version += 1; S.approval.status = 'waiting'; log('team', 'Sent version ' + S.approval.version + ' of ' + S.approval.title); S.chat.push({ from: 'team', text: 'Version ' + S.approval.version + ' is ready with your changes. Have a look in Project timeline.', at: now() }); toast('Client sees: new version to approve.'); }
  };

  /* ---------- actions ---------- */
  var ACT = {
    reset: function () { try { localStorage.removeItem(KEY); } catch (e) {} S = seed(); go('invite'); toast('Demo restarted.'); },
    team: function () { teamModal(); },
    logout: function () { S.loggedIn = false; save(); go('login'); },
    drawer: function () {
      var d = document.createElement('div'); d.className = 'drawer'; d.innerHTML = sideHTML((location.hash || '').slice(1));
      document.body.appendChild(d);
      d.addEventListener('click', function (e) { if (e.target === d || e.target.closest('a')) d.remove(); });
      var a = d.querySelector('a[aria-current]') || d.querySelector('a'); if (a) a.focus();
    },
    skipWelcome: function () { S.onboarded = true; save(); go('dashboard'); },
    magic: function () { toast('Demo: in the real portal, a one-tap login link is emailed to you.'); },
    joinCall: function () { toast('Demo: this opens the video call link.'); },
    pdf: function () { toast('Demo: this downloads the PDF.'); },
    pw: function () { toast('Demo: this opens a password change form.'); },
    approve: function () {
      var a = S.approval; a.status = 'approved'; log('me', 'Approved ' + a.title + ' v' + a.version);
      if (S.stage === 2) { S.stage = 3; S.approval = { title: 'All pages built', version: 1, status: 'waiting' }; S.chat.push({ from: 'team', text: 'Thanks for approving the design! We\'ve built all the pages. Have a look and approve, or ask for changes.', at: now() }); }
      else if (S.stage === 3) { S.stage = 4; S.approval = { title: 'Final review on your phone', version: 1, status: 'waiting' }; S.chat.push({ from: 'team', text: 'Great! This is the review round. Check everything on your phone, and tell us anything you\'d change.', at: now() }); }
      else if (S.stage === 4) { S.stage = 5; S.approval = null; S.invoices[1].status = 'unpaid'; S.chat.push({ from: 'team', text: 'All approved! Your final invoice is in your wallet. Once it\'s confirmed we put the site live.', at: now() }); }
      save(); render(); toast('Approved. Moving to the next stage.');
    },
    changes: function () { changesModal(); },
    pay: function (el) { payModal(+el.getAttribute('data-i')); },
    topup: function () { topupModal(); },
    fApprove: function (el) {
      var f = S.features[+el.getAttribute('data-i')]; f.status = 'approved';
      if (S.wallet.balance >= f.price) { S.wallet.balance -= f.price; S.wallet.tx.unshift({ text: f.title, amount: -f.price, at: now() }); toast('Approved and paid from your wallet.'); }
      else { S.invoices.push({ id: 'INV-00' + (S.invoices.length + 1), title: f.title, amount: f.price, status: 'unpaid' }); toast('Approved. An invoice is in your wallet.'); }
      log('me', 'Approved "' + f.title + '"'); save(); render();
    },
    fDecline: function (el) { var f = S.features[+el.getAttribute('data-i')]; f.status = 'declined'; save(); render(); },
    tOpen: function (el) { var i = +el.getAttribute('data-i'); S._ticket = S._ticket === i ? null : i; render(); },
    tClose: function (el) { var k = S.tickets[+el.getAttribute('data-i')]; k.status = 'closed'; log('me', 'Marked ticket "' + k.subject + '" as solved'); save(); render(); toast('Ticket closed. Thanks!'); },
    mOk: function (el) { S.meetings[+el.getAttribute('data-i')].confirmed = true; log('me', 'Confirmed the meeting notes'); save(); render(); toast('Thanks for checking.'); },
    invite: function () { inviteModal(); },
    saveProfile: function () {
      function v(id) { return document.getElementById(id).value.trim(); }
      S.biz.name = v('pBiz') || S.biz.name; S.biz.type = v('pType'); S.biz.address = v('pAddr'); S.biz.site = v('pSite') || S.biz.site; S.biz.phone = v('pPh'); S.biz.billing = v('pBill');
      S.user.name = v('uName') || S.user.name; S.user.role = v('uRole'); S.user.email = v('uEmail') || S.user.email; S.user.phone = v('uPh'); S.user.contact = document.getElementById('uC').value;
      save(); toast('Saved.');
    }
  };
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-act]'); if (!el) return;
    var a = el.getAttribute('data-act'); if (!ACT[a]) return;
    e.preventDefault(); ACT[a](el);
  });

  /* ---------- router ---------- */
  var VIEWS = { invite: vInvite, join: vJoin, welcome: vWelcome, login: vLogin, dashboard: vDashboard, timeline: vTimeline, proposal: vProposal, features: vFeatures, tickets: vTickets, meetings: vMeetings, chat: vChat, files: vFiles, wallet: vWallet, profile: vProfile };
  var PUBLIC = { invite: 1, join: 1, login: 1 };
  function render() {
    var r = (location.hash || '').replace('#', '');
    if (!VIEWS[r]) r = S.loggedIn ? 'dashboard' : (S.joined ? 'login' : 'invite');
    if (!PUBLIC[r] && !S.loggedIn) r = S.joined ? 'login' : 'invite';
    if (r === 'welcome' && !S.joined) r = 'invite';
    if (PUBLIC[r] && S.loggedIn) r = 'dashboard';
    VIEWS[r]();
    var titles = { invite: 'Your invite', join: 'Create your account', welcome: 'Your business', login: 'Log in' };
    document.title = (titles[r] || (NAV.filter(function (n) { return n[0] === r; })[0] || ['', 'Profile'])[1]) + ' · Sites By MS portal (demo)';
    updateTeam();
  }
  var lastRoute = null;
  window.addEventListener('hashchange', function () {
    render();
    var r = (location.hash || '').slice(1);
    if (r !== lastRoute) { window.scrollTo(0, 0); var m = document.getElementById('main'); if (m) m.focus({ preventScroll: true }); }
    lastRoute = r;
  });
  render();
})();
