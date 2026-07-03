<?php
/**
 * ShortDrama izleme uygulaması — bağımsız, tek dosya (PHP + cURL).
 *
 * Kaynak: shortdrama.st (dramaflix'in de beslendiği üst kaynak). Avantajı:
 * video (HLS) CORS'a açık ve imzasız sunuluyor → tarayıcı videoyu DOĞRUDAN
 * oynatır, sunucun bant genişliği HARCAMAZ (dramaflix sürümündeki gibi
 * segment proxy'ye gerek yok).
 *
 * PHP yalnızca katalog HTML'ini kazıyıp JSON'a çevirir:
 *   short.php?r=genres                 -> tür (kategori) listesi
 *   short.php?r=browse&page=1&q=&genre= -> dizi kartları
 *   short.php?r=detail&slug=...         -> dizi + tüm bölüm m3u8 linkleri
 *   short.php                           -> mobil arayüz (HTML)
 *
 * Bölüm URL'leri tek /series/{slug} isteğinden türetilir: sayfadaki ilk
 * bölümün yolu (ep_1 / ep_0_hls gibi) "tohum" alınıp kalıp çıkarılır ve
 * tüm bölümler kurulur (imza gerekmez).
 *
 * hPanel/Hostinger: PHP 7.4+ ve cURL eklentisi (varsayılan açık).
 */

const SD_BASE = 'https://shortdrama.st';
const SD_UA   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

/* ============================ HTTP ============================ */

function sd_get(string $path): string
{
    $url = (strpos($path, 'http') === 0) ? $path : SD_BASE . $path;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . SD_UA,
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: en,tr;q=0.8',
            'Referer: ' . SD_BASE . '/',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException("cURL: $err");
    if ($code >= 400)    throw new RuntimeException("HTTP $code");
    return $body;
}

/** Göreli/mutlak URL'i mutlak yap. */
function sd_abs(string $u): string
{
    if ($u === '') return '';
    if (preg_match('#^https?://#i', $u)) return $u;
    return SD_BASE . '/' . ltrim($u, '/');
}

/* ============================ Ayrıştırıcılar ============================ */

/** /browse HTML'inden dizi kartları. */
function sd_parse_cards(string $htmlDoc): array
{
    $htmlDoc = html_entity_decode($htmlDoc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $out = [];
    $seen = [];
    // <a href="/series/{slug}" ...> ... <img src="{cover}" alt="{title}"
    if (preg_match_all(
        '#href="/series/([a-z0-9-]+)"[^>]*>.*?<img\s+src="([^"]+)"\s+alt="([^"]*)"#s',
        $htmlDoc, $m, PREG_SET_ORDER
    )) {
        foreach ($m as $c) {
            $slug = $c[1];
            if (isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $cover = sd_abs($c[2]);
            $title = trim($c[3]);
            $platform = ''; $lang = '';
            if (preg_match('#/media/([^/]+)/([^/]+)/#', $c[2], $mm)) {
                $platform = $mm[1];
                $lang = $mm[2];
            }
            $out[] = [
                'slug' => $slug, 'title' => $title, 'cover' => $cover,
                'platform' => $platform, 'language' => $lang,
            ];
        }
    }
    return $out;
}

/** /browse HTML'inden tür (kategori) listesi. */
function sd_parse_genres(string $htmlDoc): array
{
    $g = [];
    if (preg_match_all('#href="/browse\?genre=([^"&]+)"#', $htmlDoc, $m)) {
        foreach ($m[1] as $x) {
            $name = html_entity_decode(urldecode($x), ENT_QUOTES, 'UTF-8');
            $g[$name] = true;
        }
    }
    return array_keys($g);
}

/** /series/{slug} HTML'inden detay + tüm bölüm linkleri. */
function sd_parse_detail(string $htmlDoc, string $slug): array
{
    $h = html_entity_decode($htmlDoc, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $get = function ($attr) use ($h) {
        return preg_match('#' . preg_quote($attr, '#') . '="([^"]*)"#', $h, $m) ? $m[1] : '';
    };

    $title    = $get('data-series-title');
    $total    = (int)$get('data-total-episodes');
    $platform = $get('data-platform');
    $poster   = sd_abs($get('data-poster'));
    $seed     = $get('data-src');           // ilk bölümün m3u8 yolu (tohum)

    $desc = '';
    if (preg_match('#<meta name="description" content="([^"]*)"#', $h, $m)) {
        $desc = $m[1];
    }

    // Tohumdan kalıp çıkar: .../{slug}/ep_{num}(_hls)?/playlist.m3u8
    $episodes = [];
    if ($seed && preg_match('#(.*/)ep_(\d+)(_hls)?/playlist\.m3u8#', $seed, $m)) {
        $baseDir = sd_abs($m[1]);           // https://shortdrama.st/media-proxy/media/.../{slug}/
        $firstNum = (int)$m[2];             // 1-indeksli (0) veya 0-indeksli
        $suffix   = $m[3] ?? '';            // '' veya '_hls'
        if ($total < 1) $total = 1;
        for ($k = 1; $k <= $total; $k++) {
            $num = $firstNum + ($k - 1);
            $episodes[] = [
                'n'   => $k,
                'url' => $baseDir . 'ep_' . $num . $suffix . '/playlist.m3u8',
            ];
        }
        // dil, yol içinden
        if (!isset($lang) && preg_match('#/media/[^/]+/([^/]+)/#', $seed, $mm)) {
            $language = $mm[1];
        }
    }
    $language = $language ?? '';
    if ($language === '' && preg_match('#/media/[^/]+/([^/]+)/#', $seed, $mm)) $language = $mm[1];

    if ($title === '') $title = ucwords(str_replace('-', ' ', $slug));
    if ($poster === '') $poster = $episodes ? '' : '';

    return [
        'slug' => $slug, 'title' => $title, 'platform' => $platform,
        'language' => $language, 'cover' => $poster, 'description' => $desc,
        'total' => count($episodes), 'episodes' => $episodes,
    ];
}

/* ============================ Yönlendirici ============================ */

$r = $_GET['r'] ?? '';

if ($r === 'genres') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    try {
        $html = sd_get('/browse');
        echo json_encode(['genres' => sd_parse_genres($html)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($r === 'browse') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=120');
    try {
        $q = [];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $q['page'] = $page;
        if (isset($_GET['q']) && $_GET['q'] !== '')       $q['q'] = $_GET['q'];
        if (isset($_GET['genre']) && $_GET['genre'] !== '') $q['genre'] = $_GET['genre'];
        $html = sd_get('/browse?' . http_build_query($q));
        $cards = sd_parse_cards($html);
        echo json_encode(['page' => $page, 'series' => $cards, 'hasMore' => count($cards) > 0],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($r === 'detail') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    try {
        $slug = preg_replace('#[^a-z0-9-]#', '', strtolower($_GET['slug'] ?? ''));
        if ($slug === '') throw new InvalidArgumentException('slug gerekli');
        $html = sd_get('/series/' . $slug);
        echo json_encode(sd_parse_detail($html, $slug),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* ============================ Mobil Arayüz ============================ */
?><!DOCTYPE html>
<html lang="tr" class="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>ShortDrama — Kısa Dramalar</title>
<style>
  :root{--bg:#0b0b0f;--bg2:#14141c;--card:#181822;--line:#26263340;--txt:#f2f2f5;--mut:#9a9aab;--accent:#E50914}
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
  html,body{margin:0;background:var(--bg);color:var(--txt);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;overscroll-behavior-y:none}
  img{display:block;max-width:100%}
  header{position:sticky;top:0;z-index:20;background:linear-gradient(180deg,var(--bg) 60%,#0b0b0fcc);
    padding:calc(env(safe-area-inset-top) + 10px) 12px 8px;backdrop-filter:blur(8px)}
  .brand{font-weight:800;font-size:20px}.brand b{color:var(--accent)}
  .searchrow{display:flex;gap:8px;margin-top:10px}
  .searchrow input{flex:1;background:var(--card);border:1px solid var(--line);border-radius:12px;color:var(--txt);
    padding:11px 14px;font-size:15px;outline:none}
  .chips{display:flex;gap:8px;overflow-x:auto;padding:10px 12px 4px;scrollbar-width:none}
  .chips::-webkit-scrollbar{display:none}
  .chip{flex:0 0 auto;background:var(--card);border:1px solid var(--line);color:var(--mut);
    padding:8px 14px;border-radius:999px;font-size:13px;font-weight:600;white-space:nowrap}
  .chip.active{background:var(--accent);border-color:var(--accent);color:#fff}
  .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:12px}
  @media(max-width:380px){.grid{grid-template-columns:repeat(2,1fr)}}
  @media(min-width:620px){.grid{grid-template-columns:repeat(4,1fr)}}
  @media(min-width:820px){.grid{grid-template-columns:repeat(5,1fr)}}
  .card{background:var(--card);border-radius:12px;overflow:hidden;position:relative}
  .card .poster{width:100%;aspect-ratio:2/3;object-fit:cover;background:var(--bg2)}
  .card .meta{padding:6px 8px 9px}
  .card .t{font-size:12.5px;font-weight:700;line-height:1.25;display:-webkit-box;-webkit-line-clamp:2;
    -webkit-box-orient:vertical;overflow:hidden;min-height:32px}
  .card .s{font-size:10.5px;color:var(--mut);margin-top:3px}
  .badge{position:absolute;top:6px;left:6px;background:#000000aa;color:#fff;font-size:9px;font-weight:700;
    padding:3px 6px;border-radius:6px}
  .loader{text-align:center;color:var(--mut);padding:18px;font-size:13px}
  .empty{text-align:center;color:var(--mut);padding:40px 20px}
  .sheet{position:fixed;inset:0;z-index:40;background:var(--bg);transform:translateY(100%);
    transition:transform .28s ease;overflow-y:auto;-webkit-overflow-scrolling:touch}
  .sheet.open{transform:none}
  .sheet .hero{position:relative}
  .sheet .hero img{width:100%;aspect-ratio:16/10;object-fit:cover;filter:brightness(.55)}
  .sheet .hero .grad{position:absolute;inset:0;background:linear-gradient(180deg,#0b0b0f33,#0b0b0f)}
  .sheet .close{position:absolute;top:calc(env(safe-area-inset-top) + 8px);right:12px;z-index:2;
    width:38px;height:38px;border-radius:50%;background:#000000aa;border:0;color:#fff;font-size:20px}
  .sheet .info{padding:0 16px 20px;margin-top:-60px;position:relative}
  .sheet h2{font-size:21px;margin:0 0 6px}
  .sheet .subline{color:var(--mut);font-size:13px;display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
  .sheet .desc{color:#cfcfd8;font-size:14px;line-height:1.5}
  .playbtn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;margin:14px 0 6px;
    background:var(--accent);color:#fff;border:0;border-radius:12px;padding:14px;font-size:16px;font-weight:800}
  .epttl{margin:18px 0 8px;font-size:15px;font-weight:800}
  .eps{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
  @media(min-width:520px){.eps{grid-template-columns:repeat(8,1fr)}}
  .ep{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px 0;text-align:center;
    font-size:14px;font-weight:700}
  .ep:active{background:var(--accent);border-color:var(--accent)}
  .player{position:fixed;inset:0;z-index:60;background:#000;display:none;flex-direction:column}
  .player.open{display:flex}
  .player .stage{flex:1;position:relative;display:flex;align-items:center;justify-content:center}
  .player video{width:100%;height:100%;object-fit:contain;background:#000}
  .player .pclose{position:absolute;top:calc(env(safe-area-inset-top) + 10px);left:12px;z-index:3;
    width:40px;height:40px;border-radius:50%;background:#000000aa;border:0;color:#fff;font-size:22px}
  .player .ptitle{position:absolute;top:calc(env(safe-area-inset-top) + 14px);left:60px;right:60px;z-index:3;
    color:#fff;font-size:14px;font-weight:700;text-shadow:0 1px 3px #000;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .player .epwrap{position:absolute;bottom:0;left:0;right:0;z-index:3;
    background:linear-gradient(0deg,#000000dd,#0000);padding:16px 12px calc(env(safe-area-inset-bottom) + 14px)}
  .player .nav{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
  .player .nav button{background:#ffffff1a;border:1px solid #ffffff33;color:#fff;border-radius:10px;padding:9px 14px;font-size:14px;font-weight:700}
  .player .nav button:disabled{opacity:.35}
  .player .epinfo{color:#fff;font-size:14px;font-weight:800;text-align:center;flex:1}
  .player .eprow{display:flex;gap:7px;overflow-x:auto;scrollbar-width:none}
  .player .eprow::-webkit-scrollbar{display:none}
  .player .epchip{flex:0 0 auto;min-width:40px;text-align:center;background:#ffffff1a;border:1px solid #ffffff33;
    color:#fff;border-radius:8px;padding:7px 0;font-size:13px;font-weight:700}
  .player .epchip.active{background:var(--accent);border-color:var(--accent)}
  .player .spin{position:absolute;z-index:2;color:#fff;font-size:14px}
  .player .autobar{position:absolute;bottom:0;left:0;height:3px;background:var(--accent);width:0;z-index:4}
</style>
</head>
<body>
<header>
  <div class="brand">Short<b>Drama</b></div>
  <div class="searchrow"><input id="q" type="search" placeholder="Dizi ara…" autocomplete="off"></div>
</header>
<div class="chips" id="chips"></div>
<div class="grid" id="grid"></div>
<div class="loader" id="loader" style="display:none">Yükleniyor…</div>
<div class="empty" id="empty" style="display:none">Sonuç bulunamadı.</div>

<div class="sheet" id="sheet">
  <div class="hero"><img id="d_hero" alt=""><div class="grad"></div><button class="close" onclick="closeSheet()">×</button></div>
  <div class="info">
    <h2 id="d_title"></h2>
    <div class="subline" id="d_sub"></div>
    <button class="playbtn" onclick="playFrom(0)">▶ İlk Bölümden Oynat</button>
    <div class="desc" id="d_desc"></div>
    <div class="epttl">Bölümler</div>
    <div class="eps" id="d_eps"></div>
  </div>
</div>

<div class="player" id="player">
  <div class="stage">
    <button class="pclose" onclick="closePlayer()">×</button>
    <div class="ptitle" id="p_title"></div>
    <div class="spin" id="p_spin" style="display:none">Yükleniyor…</div>
    <video id="video" playsinline webkit-playsinline preload="auto"></video>
    <div class="autobar" id="p_autobar"></div>
    <div class="epwrap">
      <div class="nav">
        <button id="p_prev" onclick="playFrom(cur-1)">‹ Önceki</button>
        <div class="epinfo" id="p_epinfo"></div>
        <button id="p_next" onclick="playFrom(cur+1)">Sonraki ›</button>
      </div>
      <div class="eprow" id="p_eprow"></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js"></script>
<script>
const SELF = location.pathname;
const api = (p) => SELF + '?' + p;
let state = { genre:'', q:'', page:1, loading:false, done:false };
let curSeries = null, cur = 0;

async function loadGenres(){
  const chips=document.getElementById('chips');
  let genres=[];
  try{ const j=await (await fetch(api('r=genres'))).json(); genres=j.genres||[]; }catch(e){}
  const list=[{id:'',label:'Tümü'}].concat(genres.slice(0,40).map(g=>({id:g,label:g})));
  chips.innerHTML='';
  list.forEach(c=>{
    const el=document.createElement('div');
    el.className='chip'+(c.id===state.genre?' active':'');
    el.textContent=c.label;
    el.onclick=()=>{ state.genre=c.id; document.querySelectorAll('.chip').forEach(x=>x.classList.remove('active')); el.classList.add('active'); reload(); };
    chips.appendChild(el);
  });
}

function buildQuery(){
  let p='r=browse&page='+state.page;
  if(state.q) p+='&q='+encodeURIComponent(state.q);
  if(state.genre) p+='&genre='+encodeURIComponent(state.genre);
  return p;
}
function reload(){
  state.page=1; state.done=false;
  document.getElementById('grid').innerHTML='';
  document.getElementById('empty').style.display='none';
  loadMore();
}
async function loadMore(){
  if(state.loading||state.done) return;
  state.loading=true; document.getElementById('loader').style.display='block';
  try{
    const j=await (await fetch(api(buildQuery()))).json();
    const items=j.series||[];
    if(items.length===0 && state.page===1) document.getElementById('empty').style.display='block';
    renderCards(items);
    if(!j.hasMore || items.length===0) state.done=true; else state.page++;
  }catch(e){ document.getElementById('loader').textContent='Hata: '+e.message; }
  finally{ state.loading=false; document.getElementById('loader').style.display=state.done?'none':'block'; }
}
function renderCards(items){
  const g=document.getElementById('grid');
  items.forEach(s=>{
    const a=document.createElement('div'); a.className='card';
    a.innerHTML=
      (s.platform?'<div class="badge">'+esc(s.platform)+'</div>':'')+
      '<img class="poster" loading="lazy" src="'+esc(s.cover)+'" alt="" onerror="this.style.opacity=.15">'+
      '<div class="meta"><div class="t">'+esc(s.title)+'</div><div class="s">'+esc((s.language||'').toUpperCase())+'</div></div>';
    a.onclick=()=>openDetail(s.slug);
    g.appendChild(a);
  });
}
window.addEventListener('scroll', ()=>{
  if(document.getElementById('sheet').classList.contains('open')) return;
  if(window.innerHeight+window.scrollY >= document.body.offsetHeight-600) loadMore();
});
let qt;
document.getElementById('q').addEventListener('input', e=>{ clearTimeout(qt); qt=setTimeout(()=>{ state.q=e.target.value.trim(); reload(); },400); });

async function openDetail(slug){
  const sheet=document.getElementById('sheet'); sheet.classList.add('open'); sheet.scrollTop=0;
  document.getElementById('d_title').textContent='Yükleniyor…';
  document.getElementById('d_eps').innerHTML=''; document.getElementById('d_desc').textContent='';
  document.getElementById('d_sub').innerHTML='';
  try{
    const j=await (await fetch(api('r=detail&slug='+encodeURIComponent(slug)))).json();
    curSeries=j;
    document.getElementById('d_hero').src=j.cover||'';
    document.getElementById('d_title').textContent=j.title||'';
    document.getElementById('d_desc').textContent=j.description||'';
    document.getElementById('d_sub').innerHTML=
      '<span>🎬 '+esc(j.platform||'')+'</span><span>🌐 '+esc((j.language||'').toUpperCase())+'</span><span>▦ '+(j.total||0)+' bölüm</span>';
    const box=document.getElementById('d_eps'); box.innerHTML='';
    (j.episodes||[]).forEach((ep,i)=>{ const b=document.createElement('div'); b.className='ep'; b.textContent=ep.n; b.onclick=()=>playFrom(i); box.appendChild(b); });
  }catch(e){ document.getElementById('d_title').textContent='Hata: '+e.message; }
}
function closeSheet(){ document.getElementById('sheet').classList.remove('open'); }

let hls=null; const video=document.getElementById('video');
function playFrom(i){
  if(!curSeries||!curSeries.episodes) return;
  const eps=curSeries.episodes; if(i<0||i>=eps.length) return;
  cur=i; document.getElementById('player').classList.add('open');
  document.getElementById('p_title').textContent=curSeries.title||'';
  updatePlayerNav(); loadEpisode(eps[i]);
}
function loadEpisode(ep){
  const spin=document.getElementById('p_spin'); spin.style.display='block'; spin.textContent='Yükleniyor…';
  const src=ep.url; // shortdrama.st CORS'a açık → doğrudan oynatılır (proxy yok)
  if(hls){ hls.destroy(); hls=null; }
  if(window.Hls && Hls.isSupported()){
    hls=new Hls({maxBufferLength:20, enableWorker:true});
    hls.loadSource(src); hls.attachMedia(video);
    hls.on(Hls.Events.MANIFEST_PARSED, ()=>{ spin.style.display='none'; video.play().catch(()=>{}); });
    hls.on(Hls.Events.ERROR, (e,d)=>{ if(d.fatal) spin.textContent='Oynatma hatası'; });
  } else {
    video.src=src;
    video.addEventListener('loadedmetadata', ()=>{ spin.style.display='none'; video.play().catch(()=>{}); }, {once:true});
  }
}
video.addEventListener('ended', ()=>{ if(curSeries && cur<curSeries.episodes.length-1) playFrom(cur+1); });
video.addEventListener('timeupdate', ()=>{ const bar=document.getElementById('p_autobar'); if(video.duration && video.duration-video.currentTime<5) bar.style.width=(100*(video.currentTime/video.duration))+'%'; else bar.style.width='0'; });
video.addEventListener('click', ()=>{ video.paused?video.play():video.pause(); });
function updatePlayerNav(){
  const eps=curSeries.episodes;
  document.getElementById('p_prev').disabled=cur<=0;
  document.getElementById('p_next').disabled=cur>=eps.length-1;
  document.getElementById('p_epinfo').textContent='Bölüm '+eps[cur].n+' / '+eps.length;
  const row=document.getElementById('p_eprow'); row.innerHTML='';
  eps.forEach((ep,i)=>{ const c=document.createElement('div'); c.className='epchip'+(i===cur?' active':''); c.textContent=ep.n; c.onclick=()=>playFrom(i); row.appendChild(c); });
  const act=row.querySelector('.active'); if(act) act.scrollIntoView({inline:'center',block:'nearest'});
}
function closePlayer(){ document.getElementById('player').classList.remove('open'); video.pause(); if(hls){hls.destroy();hls=null;} video.removeAttribute('src'); video.load(); }
function esc(s){ return (s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

loadGenres(); reload();
</script>
</body>
</html>
