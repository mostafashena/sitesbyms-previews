<?php
// Analysis report page: /report/?k=<token>. Reads reports/<token>.json (not web-readable) and shows the report with the concept and contact options.
declare(strict_types=1);
require __DIR__ . '/../api/_lib.php';
header('X-Robots-Tag: noindex, nofollow');
$k = (string)($_GET['k'] ?? '');
$f = dirname(__DIR__) . '/reports/' . $k . '.json';
$R = preg_match('/^[a-z0-9]{16}$/', $k) && is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (!is_array($R) || empty($R['business'])) { http_response_code(404); echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Report not found</title><body style="font-family:system-ui;max-width:520px;margin:80px auto;padding:0 20px;line-height:1.6"><h1>This report link is not valid</h1><p>Please check the link we sent you, or write to <a href="mailto:hello@sitesbyms.com">hello@sitesbyms.com</a>.</p></body>'; exit; }
$name = (string)$R['business']; $slug = preg_replace('/[^a-z0-9-]/', '', (string)($R['slug'] ?? ''));
$you = (array)($R['you'] ?? []); $comps = array_slice((array)($R['competitors'] ?? []), 0, 6);
$all = array_merge([$you + ['name' => $name, '_you' => true]], $comps);
$date = !empty($R['created']) ? date('j F Y', strtotime((string)$R['created'])) : '';
$yn = function ($v) { $v = strtolower(trim((string)$v)); if ($v === '') return '<span class="na">–</span>'; if (in_array($v, ['yes', 'y', 'true', 'good'], true)) return '<span class="yes">✓</span>'; if (in_array($v, ['no', 'n', 'false', 'none'], true)) return '<span class="no">✕</span>'; return '<span class="mid">' . e(ucfirst($v)) . '</span>'; };
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow"><title>Market analysis · <?= e($name) ?> · Sites By MS</title>
<link rel="icon" href="/assets/brand/favicon.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;700;800&display=swap">
<style>
:root{--navy:#070F37;--violet:#8557FC;--violet-d:#5B2FE0;--cream:#FBF5ED;--ink:#0E1430;--muted:#5B6180;--line:#E8E3F1;--ok:#127A4B;--bad:#B42318}
*{box-sizing:border-box}body{margin:0;background:var(--cream);color:var(--ink);font:400 16px/1.6 "Plus Jakarta Sans",system-ui,sans-serif;-webkit-font-smoothing:antialiased}
.wrap{max-width:980px;margin:0 auto;padding:0 max(18px,env(safe-area-inset-left)) 0 max(18px,env(safe-area-inset-right))}
header.top{background:var(--navy);color:var(--cream)}header.top .wrap{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-block:16px}
header.top img{height:44px;width:auto}header.top a.cta{color:#fff;background:var(--violet);padding:10px 16px;border-radius:12px;text-decoration:none;font-weight:700;font-size:14px}
.hero{background:var(--navy);color:var(--cream);padding:34px 0 56px}.hero small{display:block;color:#A9AECB;font-weight:600;letter-spacing:.06em;text-transform:uppercase;font-size:12px;margin-bottom:10px}
.hero h1{margin:0 0 12px;font-size:clamp(28px,5vw,44px);line-height:1.12;font-weight:800;text-wrap:balance}.hero p{margin:0;max-width:62ch;color:#D8DAEA;font-size:17px}
main{margin-top:-28px;padding-bottom:120px}section.card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:26px;margin-bottom:18px;box-shadow:0 10px 30px rgba(7,15,55,.06)}
h2{margin:0 0 6px;font-size:21px;font-weight:800}.lead{color:var(--muted);margin:0 0 18px}
.tbl{overflow-x:auto;margin:0 -6px}table{width:100%;border-collapse:collapse;font-size:14.5px;min-width:620px}
th,td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700}
tr.you td{background:#F5F0FF;font-weight:700}tr.you td:first-child{border-left:4px solid var(--violet)}td.num{font-variant-numeric:tabular-nums;white-space:nowrap}
.yes{color:var(--ok);font-weight:800}.no{color:var(--bad);font-weight:800}.mid{color:#8A5A00;font-weight:700}.na{color:#A0A4B8}
.two{display:grid;grid-template-columns:1fr 1fr;gap:18px}@media(max-width:760px){.two{grid-template-columns:1fr}}
.two ul{margin:12px 0 0;padding-left:20px;display:grid;gap:10px}.good h2{color:var(--ok)}.bad h2{color:var(--bad)}
ol.acts{list-style:none;margin:16px 0 0;padding:0;display:grid;gap:14px;counter-reset:a}
ol.acts li{counter-increment:a;display:grid;grid-template-columns:44px 1fr;gap:14px;padding:18px;border:1px solid var(--line);border-radius:16px}
ol.acts li::before{content:counter(a);width:44px;height:44px;border-radius:50%;background:var(--navy);color:#fff;display:grid;place-items:center;font-weight:800;font-size:18px}
ol.acts b{display:block;font-size:17px;margin-bottom:4px}ol.acts p{margin:0;color:#3B4160}
.concept{display:grid;grid-template-columns:1.2fr 1fr;gap:22px;align-items:center}@media(max-width:760px){.concept{grid-template-columns:1fr}}
.frame{border-radius:16px;overflow:hidden;border:1px solid var(--line);aspect-ratio:4/3;background:#F3F1F8;position:relative}
.frame iframe{position:absolute;inset:0;width:250%;height:250%;transform:scale(.4);transform-origin:0 0;border:0;pointer-events:none}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 20px;border-radius:12px;font-weight:700;text-decoration:none;font-size:15px;border:0;cursor:pointer;font-family:inherit}
.btn.p{background:var(--violet);color:#fff}.btn.p:hover{background:#7444F5}.btn.l{background:#fff;color:var(--ink);border:1.5px solid #DCD8E8}.row{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
section.card.next{background:var(--navy);color:var(--cream);border:0}.next h2{color:#fff}.next p{color:#D8DAEA;margin:0}
.who{display:flex;align-items:center;gap:14px;margin:18px 0 0;padding-top:18px;border-top:1px solid rgba(255,255,255,.14)}.who img{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.6);flex:none}.who b{display:block;color:#fff}.who span{font-size:14px;color:#D8DAEA}.who a{color:#C9B6FF;font-weight:700}
.foot{color:var(--muted);font-size:13px;text-align:center;margin-top:24px}
</style></head><body>
<header class="top"><div class="wrap"><img src="/assets/brand/logo-on-dark.png" alt="Sites By MS"><a class="cta" href="#next">Talk to our team</a></div></header>
<div class="hero"><div class="wrap"><small>Market analysis<?= $date ? ' · ' . e($date) : '' ?></small><h1><?= e($name) ?> and its local competitors</h1><p><?= e($R['summary'] ?? '') ?></p></div></div>
<main class="wrap">
<section class="card" id="position"><h2>Market position</h2><p class="lead">How <?= e($name) ?> compares with <?= count($comps) ?> similar businesses nearby<?= !empty($R['area']) ? ' in ' . e($R['area']) : '' ?>.</p>
<div class="tbl"><table><thead><tr><th>Business</th><th>Rating</th><th>Reviews</th><th>Website</th><th>Works on mobile</th><th>Online booking / ordering</th><th>Notes</th></tr></thead><tbody>
<?php foreach ($all as $c): ?><tr<?= !empty($c['_you']) ? ' class="you"' : '' ?>><td><?= e($c['name'] ?? '') ?><?= !empty($c['_you']) ? ' (you)' : '' ?></td><td class="num"><?= e($c['rating'] ?? '–') ?></td><td class="num"><?= e($c['reviews'] ?? '–') ?></td><td><?= $yn($c['website'] ?? '') ?></td><td><?= $yn($c['mobile'] ?? '') ?></td><td><?= $yn($c['booking'] ?? '') ?></td><td><?= e($c['notes'] ?? '') ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if (!empty($R['visibility'])): ?><h2 style="margin-top:24px">Online visibility</h2><p class="lead" style="margin:0"><?= e($R['visibility']) ?></p><?php endif; ?></section>
<div class="two"><section class="card good"><h2>Where you lead</h2><ul><?php foreach ((array)($R['lead'] ?? []) as $x) echo '<li>' . e($x) . '</li>'; ?></ul></section>
<section class="card bad"><h2>Where you lose customers</h2><ul><?php foreach ((array)($R['lose'] ?? []) as $x) echo '<li>' . e($x) . '</li>'; ?></ul></section></div>
<section class="card" id="actions"><h2>Priority actions</h2><p class="lead" style="margin:0">Three steps, ranked by impact. You can act on them with or without us.</p><ol class="acts">
<?php foreach (array_slice((array)($R['actions'] ?? []), 0, 3) as $x): ?><li><div><b><?= e($x['title'] ?? '') ?></b><p><?= e($x['why'] ?? '') ?></p></div></li><?php endforeach; ?></ol></section>
<?php if ($slug && is_dir(dirname(__DIR__) . '/' . $slug)): ?><section class="card concept" id="concept"><div><h2>Your new homepage concept</h2><p class="lead">We designed this for <?= e($name) ?> to fix the points above: clear on a phone, easy to book or call, and true to your brand.</p><a class="btn p" href="/<?= e($slug) ?>/?from=report" data-ev="concept">Open the full concept</a></div>
<div class="frame"><iframe src="/<?= e($slug) ?>/?from=report&amp;embed=1" title="Concept preview" loading="lazy" tabindex="-1"></iframe></div></section><?php endif; ?>
<section class="card next" id="next"><h2>Next step</h2><p>A short call with our team: we walk you through the report and what a new website would do for <?= e($name) ?>. Free, no obligation.</p>
<div class="row"><a class="btn p" href="/book/?b=<?= rawurlencode($name) ?>&amp;s=<?= e($slug) ?>" data-ev="book">Book a 15-min call</a><a class="btn l" href="https://wa.me/447405760048?text=<?= rawurlencode('Hi, about the analysis report for ' . $name) ?>" data-ev="whatsapp">WhatsApp us</a><a class="btn l" href="/ask/?b=<?= rawurlencode($name) ?>&amp;s=<?= e($slug) ?>" data-ev="ask">Ask a question</a></div>
<div class="who"><img src="/assets/team/mostafa-avatar-3.jpg" alt="Mostafa, founder of Sites By MS" width="56" height="56"><div><b>Mostafa &amp; the Sites By MS team</b><span>You talk to the founder; our team researched this report and designed your concept. <a href="/about/" data-ev="about">Meet the team</a></span></div></div></section>
<p class="foot">Prepared for <?= e($name) ?> by the Sites By MS team · hello@sitesbyms.com · sitesbyms.com</p>
</main>
<script>
(function(){var slug=<?= json_encode($slug) ?>;if(!slug)return;function st(k,v){try{if(v===undefined)return localStorage.getItem(k);localStorage.setItem(k,v)}catch(e){return null}}
if(/[?&]team=1/.test(location.search)||/\/admin/.test(document.referrer))st('sbm_team','1');if(st('sbm_team')==='1'||/bot|crawl|spider|preview|headless/i.test(navigator.userAgent))return;
function rid(){var a='',c='abcdefghijklmnopqrstuvwxyz0123456789';for(var i=0;i<16;i++)a+=c.charAt(Math.floor(Math.random()*36));return a}
var vid=st('sbm_vid')||rid();st('sbm_vid',vid);var sid=rid(),t0=Date.now(),q=[];
function flush(){if(!q.length)return;var b=JSON.stringify({sid:sid,vid:vid,slug:slug,page:'report',src:'report',e:q.splice(0,40)});try{navigator.sendBeacon('/api/event.php',new Blob([b],{type:'application/json'}))}catch(e){}}
function ev(n,v,now){q.push([n,String(v||'').slice(0,160),Date.now()-t0]);if(now||q.length>=20)flush()}
ev('open','report',true);try{navigator.sendBeacon('/api/view.php?s='+slug+'&f=report&r=')}catch(e){}
var act=0,sent=0,last=Date.now();['scroll','mousemove','touchstart','keydown','click'].forEach(function(n){addEventListener(n,function(){last=Date.now()},{passive:true})});
setInterval(function(){if(document.visibilityState==='visible'&&Date.now()-last<30000)act++;if(act-sent>=15){sent=act;ev('time',act,true)}},1000);
var hit={};addEventListener('scroll',function(){var h=document.documentElement.scrollHeight-innerHeight;if(h<=0)return;var p=Math.round(scrollY/h*100);[25,50,75,100].forEach(function(m){if(p>=m-2&&!hit[m]){hit[m]=1;ev('scroll',m)}})},{passive:true});
if('IntersectionObserver'in window){var io=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){var h=x.target.querySelector('h2');ev('section',h?h.textContent:'');io.unobserve(x.target)}})},{threshold:.35});document.querySelectorAll('section.card').forEach(function(s){io.observe(s)})}
document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('[data-ev]');if(a){var n=a.getAttribute('data-ev');ev(n==='book'?'book_open':n==='ask'?'ask_click':'click',a.textContent.trim(),true)}},true);
document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden'){if(act!==sent){sent=act;ev('time',act)}flush()}});setInterval(flush,10000)})();
</script></body></html>
