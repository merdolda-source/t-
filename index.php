<?php
/**
 * DramaFlix mobil izleme uygulaması — bağımsız, tek dosya (PHP + cURL).
 *
 * dramaflix.cc'nin açık JSON API'sini kullanır. Video (HLS) akışı CDN'de
 * cookie (dfexp/dfsig, /api/cdn-ticket) + Referer ile korunduğundan, bu
 * dosya HLS'i sunucu tarafında proxy'ler; böylece kendi domaininde de
 * "orijinal player" sorunsuz oynar.
 *
 * Yönlendirme (tek dosya):
 *   index.php                       -> mobil arayüz (HTML)
 *   index.php?r=api&action=series&... -> dizi listesi (JSON)
 *   index.php?r=api&action=detail&slug=...  -> dizi + bölümler (JSON)
 *   index.php?r=api&action=platforms  -> platformlar (JSON)
 *   index.php?r=hls&u=<m3u8 url>     -> proxy'li + yeniden yazılmış playlist
 *   index.php?r=seg&u=<segment url>  -> proxy'li video segmenti
 *
 * hPanel/Hostinger: PHP 7.4+ ve cURL eklentisi gerekir (varsayılan açık).
 */

const DFX_BASE   = 'https://dramaflix.cc';
const DFX_CDN    = 'cdn.dramaflix.cc';               // proxy izin verilen tek medya host'u
const DFX_UA     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
const DFX_TICKET_CACHE = 'dfx_cdn_ticket.json';       // sys temp içinde cookie önbelleği

/* ============================ Çekirdek ============================ */

class DramaFlix
{
    /**
     * Genel cURL GET. Dönüş: [http_code, body, content_type].
     * (Başlıkları ham gövdeye karıştırmayız; content-type'ı curl_getinfo'dan
     *  alırız — proxy tüneli/redirect durumlarında güvenli.)
     */
    private static function req(string $url, array $extraHeaders = [], ?array &$setCookies = null): array
    {
        $cookies = [];
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
            CURLOPT_HTTPHEADER     => array_merge([
                'User-Agent: ' . DFX_UA,
                'Accept: */*',
                'Accept-Language: tr,en;q=0.8',
                'Referer: ' . DFX_BASE . '/',
                'Origin: ' . DFX_BASE,
            ], $extraHeaders),
            // Başlıkları ayrı topla (gövdeye karışmaz; proxy tüneli/redirect güvenli).
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$cookies) {
                if (stripos($header, 'set-cookie:') === 0) {
                    $cookies[] = trim(substr($header, 11));
                }
                return strlen($header);
            },
        ]);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err   = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException("cURL: $err");
        }
        $setCookies = $cookies;
        return [$code, $body, $ctype ?: ''];
    }

    /** JSON API çağrısı. */
    public static function api(string $path, array $query = []): array
    {
        $url = DFX_BASE . $path . ($query ? ('?' . http_build_query($query)) : '');
        [$code, $body] = self::req($url);
        if ($code >= 400) {
            throw new RuntimeException("API HTTP $code");
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Geçersiz JSON');
        }
        return $data;
    }

    /**
     * CDN cookie biletini (dfexp/dfsig) getirir. Sonucu sys temp'te ~55 dk
     * önbelleğe alır; süresi dolunca yeniler. Dönüş: "dfexp=..; dfsig=.." string.
     */
    public static function cdnCookie(): string
    {
        $cacheFile = rtrim(sys_get_temp_dir(), '/') . '/' . DFX_TICKET_CACHE;

        if (is_readable($cacheFile)) {
            $c = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($c) && ($c['exp'] ?? 0) > time() + 60 && !empty($c['cookie'])) {
                return $c['cookie'];
            }
        }

        // Yeni bilet al (Set-Cookie başlıklarını yakala).
        $setCookies = [];
        [$code, $_body] = self::req(DFX_BASE . '/api/cdn-ticket', [], $setCookies);
        if ($code >= 400) {
            throw new RuntimeException("cdn-ticket HTTP $code");
        }
        $pairs = [];
        foreach ($setCookies as $sc) {
            if (preg_match('/^([^=]+)=([^;]+)/', $sc, $mm)) {
                $name = trim($mm[1]);
                if ($name === 'dfexp' || $name === 'dfsig') {
                    $pairs[$name] = trim($mm[2]);
                }
            }
        }
        if (empty($pairs['dfexp']) || empty($pairs['dfsig'])) {
            throw new RuntimeException('cdn-ticket cookie alınamadı');
        }
        $cookie = 'dfexp=' . $pairs['dfexp'] . '; dfsig=' . $pairs['dfsig'];

        // ~55 dk önbelle (ttl 3600 idi).
        @file_put_contents($cacheFile, json_encode(['cookie' => $cookie, 'exp' => time() + 3300]));
        return $cookie;
    }

    /** CDN medyayı (m3u8 veya segment) cookie+referer ile getirir. [code, body, ctype] */
    public static function cdnFetch(string $url): array
    {
        $cookie = self::cdnCookie();
        return self::req($url, ['Cookie: ' . $cookie]);
    }
}

/* ============================ Yönlendirici ============================ */

$r = $_GET['r'] ?? '';

/* ---- 1) JSON API proxy ---- */
if ($r === 'api') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    try {
        $action = $_GET['action'] ?? 'series';
        $lang   = isset($_GET['lang']) && $_GET['lang'] !== '' ? $_GET['lang'] : null;

        if ($action === 'platforms') {
            $out = DramaFlix::api('/api/platforms', $lang ? ['language' => strtolower($lang)] : []);
        } elseif ($action === 'detail') {
            $slug = $_GET['slug'] ?? '';
            if ($slug === '') throw new InvalidArgumentException('slug gerekli');
            $out = DramaFlix::api('/api/series/' . rawurlencode($slug));
        } else { // series
            $q = ['limit' => (int)($_GET['limit'] ?? 24), 'offset' => (int)($_GET['offset'] ?? 0)];
            if ($lang) $q['language'] = strtoupper($lang);
            foreach (['platform', 'search', 'sort', 'genre'] as $k) {
                if (isset($_GET[$k]) && $_GET[$k] !== '') $q[$k] = $_GET[$k];
            }
            if (isset($_GET['popular']) && $_GET['popular'] === '1') $q['is_popular'] = 'true';
            if (isset($_GET['new']) && $_GET['new'] === '1')         $q['is_new'] = 'true';
            $out = DramaFlix::api('/api/series', $q);
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* ---- 2) HLS playlist proxy (segment/alt-playlist linklerini kendine yeniden yazar) ---- */
if ($r === 'hls') {
    $u = $_GET['u'] ?? '';
    $host = parse_url($u, PHP_URL_HOST);
    if ($host !== DFX_CDN) { http_response_code(400); exit('bad host'); }
    try {
        [$code, $body, $ctype] = DramaFlix::cdnFetch($u);
        if ($code >= 400) { http_response_code($code); exit('upstream ' . $code); }

        $baseDir = preg_replace('#/[^/?]*(\?.*)?$#', '/', $u); // m3u8'in bulunduğu dizin
        $self = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');
        $lines = preg_split('/\r\n|\n|\r/', $body);
        $out = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') { $out[] = $line; continue; }

            if ($t[0] === '#') {
                // #EXT-X-KEY / MAP gibi satırlardaki URI="..." değerlerini de proxy'le
                if (stripos($t, 'URI="') !== false) {
                    $line = preg_replace_callback('/URI="([^"]+)"/i', function ($m) use ($baseDir, $self) {
                        return 'URI="' . proxify_url($m[1], $baseDir, $self) . '"';
                    }, $line);
                }
                $out[] = $line;
                continue;
            }
            // içerik satırı: alt-playlist (.m3u8) veya segment
            $out[] = proxify_url($t, $baseDir, $self);
        }
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: no-store');
        echo implode("\n", $out);
    } catch (Throwable $e) {
        http_response_code(502);
        echo 'hls error: ' . $e->getMessage();
    }
    exit;
}

/** Playlist içindeki bir URL'i (mutlak/göreli) kendi proxy'mize çevir. */
function proxify_url(string $ref, string $baseDir, string $self): string
{
    if (preg_match('#^https?://#i', $ref)) {
        $abs = $ref;
    } elseif ($ref[0] === '/') {
        $abs = 'https://' . DFX_CDN . $ref;
    } else {
        $abs = $baseDir . $ref;
    }
    $mode = (stripos($abs, '.m3u8') !== false) ? 'hls' : 'seg';
    return $self . '?r=' . $mode . '&u=' . rawurlencode($abs);
}

/* ---- 3) Segment proxy (video parçalarını akıtır) ---- */
if ($r === 'seg') {
    $u = $_GET['u'] ?? '';
    $host = parse_url($u, PHP_URL_HOST);
    if ($host !== DFX_CDN) { http_response_code(400); exit('bad host'); }
    try {
        [$code, $body, $ctype] = DramaFlix::cdnFetch($u);
        if ($code >= 400) { http_response_code($code); exit(); }
        if ($ctype === '') $ctype = 'video/MP2T';
        header('Content-Type: ' . $ctype);
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . strlen($body));
        echo $body;
    } catch (Throwable $e) {
        http_response_code(502);
    }
    exit;
}

/* ============================ Mobil Arayüz (HTML) ============================ */
?><!DOCTYPE html>
<html lang="tr" class="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<!-- Kapak CDN'i (cdn.dramaflix.cc) yabancı Referer'da 403 veriyor (hotlink koruması).
     Referer'ı hiç göndermeyerek 200 alırız — resimler böylece yüklenir, proxy'ye gerek yok. -->
<meta name="referrer" content="no-referrer">
<title>DramaFlix — Kısa Dramalar</title>
<style>
  :root{
    --bg:#0b0b0f; --bg2:#14141c; --card:#181822; --line:#26263340;
    --txt:#f2f2f5; --mut:#9a9aab; --accent:#E50914; --accent2:#ff3b47;
  }
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--txt);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
    overscroll-behavior-y:none}
  a{color:inherit;text-decoration:none}
  img{display:block;max-width:100%}

  /* Üst bar */
  header{position:sticky;top:0;z-index:20;background:linear-gradient(180deg,var(--bg) 60%,#0b0b0fcc);
    padding:calc(env(safe-area-inset-top) + 10px) 12px 8px;backdrop-filter:blur(8px)}
  .brand{display:flex;align-items:center;gap:8px;font-weight:800;font-size:20px;letter-spacing:.3px}
  .brand b{color:var(--accent)}
  .searchrow{display:flex;gap:8px;margin-top:10px}
  .searchrow input{flex:1;background:var(--card);border:1px solid var(--line);border-radius:12px;
    color:var(--txt);padding:11px 14px;font-size:15px;outline:none}
  .searchrow select{background:var(--card);border:1px solid var(--line);border-radius:12px;color:var(--txt);
    padding:0 8px;font-size:14px}

  /* Kategori çipleri */
  .chips{display:flex;gap:8px;overflow-x:auto;padding:10px 12px 4px;scrollbar-width:none}
  .chips::-webkit-scrollbar{display:none}
  .chip{flex:0 0 auto;display:flex;align-items:center;gap:6px;background:var(--card);border:1px solid var(--line);
    color:var(--mut);padding:7px 13px;border-radius:999px;font-size:13px;font-weight:600;white-space:nowrap}
  .chip.active{background:var(--accent);border-color:var(--accent);color:#fff}
  .chip-logo{width:18px;height:18px;border-radius:5px;object-fit:cover;background:#fff2}
  .chip-cnt{font-size:10.5px;opacity:.7;font-weight:700}

  /* Izgara */
  .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:12px}
  @media(max-width:380px){.grid{grid-template-columns:repeat(2,1fr)}}
  @media(min-width:620px){.grid{grid-template-columns:repeat(4,1fr)}}
  @media(min-width:820px){.grid{grid-template-columns:repeat(5,1fr)}}
  .card{background:var(--card);border-radius:12px;overflow:hidden;position:relative}
  .card .poster{width:100%;aspect-ratio:2/3;object-fit:cover;background:var(--bg2)}
  .card .meta{padding:6px 8px 9px}
  .card .t{font-size:12.5px;font-weight:700;line-height:1.25;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:32px}
  .card .s{font-size:10.5px;color:var(--mut);margin-top:3px;display:flex;justify-content:space-between}
  .badge{position:absolute;top:6px;left:6px;background:#000000aa;color:#fff;font-size:9px;font-weight:700;
    padding:3px 6px;border-radius:6px;text-transform:uppercase;letter-spacing:.4px}
  .badge.new{background:var(--accent)}

  .loader{text-align:center;color:var(--mut);padding:18px;font-size:13px}
  .empty{text-align:center;color:var(--mut);padding:40px 20px}

  /* Detay sayfası (alt sheet) */
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

  /* Player (dikey short) */
  .player{position:fixed;inset:0;z-index:60;background:#000;display:none;flex-direction:column}
  .player.open{display:flex}
  .player .stage{flex:1;position:relative;display:flex;align-items:center;justify-content:center}
  .player video{width:100%;height:100%;object-fit:contain;background:#000}
  .player .pclose{position:absolute;top:calc(env(safe-area-inset-top) + 10px);left:12px;z-index:3;
    width:40px;height:40px;border-radius:50%;background:#000000aa;border:0;color:#fff;font-size:22px}
  .player .ptitle{position:absolute;top:calc(env(safe-area-inset-top) + 14px);left:60px;right:60px;z-index:3;
    color:#fff;font-size:14px;font-weight:700;text-shadow:0 1px 3px #000;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .player .epwrap{position:absolute;bottom:0;left:0;right:0;z-index:3;
    background:linear-gradient(0deg,#000000dd,#0000);padding:16px 12px calc(env(safe-area-inset-bottom) + 14px)}
  .player .nav{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
  .player .nav button{background:#ffffff1a;border:1px solid #ffffff33;color:#fff;border-radius:10px;
    padding:9px 14px;font-size:14px;font-weight:700}
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
  <div class="brand">Drama<b>Flix</b></div>
  <div class="searchrow">
    <input id="q" type="search" placeholder="Dizi ara…" autocomplete="off">
    <select id="lang">
      <option value="TR">TR</option>
      <option value="EN">EN</option>
    </select>
  </div>
</header>

<div class="chips" id="chips"></div>
<div class="grid" id="grid"></div>
<div class="loader" id="loader" style="display:none">Yükleniyor…</div>
<div class="empty" id="empty" style="display:none">Sonuç bulunamadı.</div>

<!-- Detay -->
<div class="sheet" id="sheet">
  <div class="hero">
    <img id="d_hero" alt="" referrerpolicy="no-referrer">
    <div class="grad"></div>
    <button class="close" onclick="closeSheet()">×</button>
  </div>
  <div class="info">
    <h2 id="d_title"></h2>
    <div class="subline" id="d_sub"></div>
    <button class="playbtn" onclick="playFrom(0)">▶ İlk Bölümden Oynat</button>
    <div class="desc" id="d_desc"></div>
    <div class="epttl">Bölümler</div>
    <div class="eps" id="d_eps"></div>
  </div>
</div>

<!-- Player -->
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
const api = (p) => SELF + '?r=api&' + p;

let state = {
  cat: 'popular',      // popular | new | all | platform:<name>
  lang: 'TR',
  q: '',
  offset: 0,
  limit: 24,
  loading: false,
  done: false,
};
let curSeries = null;   // {series, episodes}
let cur = 0;            // aktif bölüm index

/* ---------- Kategoriler ---------- */
const DFX_ORIGIN = 'https://dramaflix.cc';
function platLogo(u){ if(!u) return ''; return /^https?:\/\//.test(u) ? u : DFX_ORIGIN + u; }

async function loadPlatforms(){
  const chips = document.getElementById('chips');
  let plats = [];
  try{
    const r = await fetch(api('action=platforms&lang='+state.lang));
    const j = await r.json();
    plats = (j.platforms||[])
      .filter(p => (p.count||0) > 0)                    // boş platformları ele
      .sort((a,b)=> (b.count||0)-(a.count||0))          // çok içerikli önde
      .map(p => ({id:'platform:'+p.name, label:p.name, count:p.count, logo:platLogo(p.logo_url)}));
  }catch(e){}

  // Sıra: Popüler, Yeni — sonra TÜM platformlar (NetShort, ReelShort…) önde — en sonda Tümü
  const items = [
    {id:'popular', label:'🔥 Popüler'},
    {id:'new',     label:'🆕 Yeni'},
    ...plats,
    {id:'all',     label:'Tümü'},
  ];

  chips.innerHTML='';
  items.forEach(c=>{
    const el=document.createElement('div');
    el.className='chip'+(c.id===state.cat?' active':'');
    if(c.logo){
      el.innerHTML = '<img class="chip-logo" src="'+c.logo+'" alt="" onerror="this.remove()">'
                   + '<span>'+c.label+'</span>'
                   + (c.count?'<span class="chip-cnt">'+c.count+'</span>':'');
    } else {
      el.textContent = c.label;
    }
    el.onclick=()=>{ state.cat=c.id; document.querySelectorAll('.chip').forEach(x=>x.classList.remove('active')); el.classList.add('active'); reload(); };
    chips.appendChild(el);
  });
}

/* ---------- Liste ---------- */
function buildQuery(){
  let p = 'action=series&lang='+encodeURIComponent(state.lang)
        + '&limit='+state.limit+'&offset='+state.offset;
  if(state.q) p += '&search='+encodeURIComponent(state.q);
  if(state.cat==='popular') p += '&popular=1&sort=top';
  else if(state.cat==='new') p += '&new=1';
  else if(state.cat.startsWith('platform:')) p += '&platform='+encodeURIComponent(state.cat.slice(9));
  return p;
}

function reload(){
  state.offset=0; state.done=false;
  document.getElementById('grid').innerHTML='';
  document.getElementById('empty').style.display='none';
  loadMore();
}

async function loadMore(){
  if(state.loading || state.done) return;
  state.loading=true;
  document.getElementById('loader').style.display='block';
  try{
    const r = await fetch(api(buildQuery()));
    const j = await r.json();
    const items = j.series||[];
    if(items.length===0 && state.offset===0){
      document.getElementById('empty').style.display='block';
    }
    renderCards(items);
    state.offset += state.limit;
    if(items.length < state.limit) state.done=true;
  }catch(e){
    document.getElementById('loader').textContent='Hata: '+e.message;
  }finally{
    state.loading=false;
    document.getElementById('loader').style.display= state.done?'none':'block';
    if(state.done) document.getElementById('loader').style.display='none';
  }
}

function renderCards(items){
  const g=document.getElementById('grid');
  items.forEach(s=>{
    const a=document.createElement('div');
    a.className='card';
    const badge = s.is_new ? '<div class="badge new">Yeni</div>' : (s.is_popular?'<div class="badge">Popüler</div>':'');
    a.innerHTML =
      badge +
      '<img class="poster" loading="lazy" referrerpolicy="no-referrer" src="'+ (s.cover_image||'') +'" alt="" onerror="this.style.opacity=.15">'+
      '<div class="meta"><div class="t">'+ esc(s.title) +'</div>'+
      '<div class="s"><span>'+ esc(s.platform||'') +'</span><span>'+ (s.total_episodes||0) +' bl</span></div></div>';
    a.onclick=()=>openDetail(s.slug);
    g.appendChild(a);
  });
}

/* sonsuz kaydırma */
window.addEventListener('scroll', ()=>{
  if(document.getElementById('sheet').classList.contains('open')) return;
  if(window.innerHeight + window.scrollY >= document.body.offsetHeight - 600) loadMore();
});

/* arama (debounce) */
let qt;
document.getElementById('q').addEventListener('input', e=>{
  clearTimeout(qt);
  qt=setTimeout(()=>{ state.q=e.target.value.trim(); reload(); }, 400);
});
document.getElementById('lang').addEventListener('change', e=>{
  state.lang=e.target.value; loadPlatforms(); reload();
});

/* ---------- Detay ---------- */
async function openDetail(slug){
  const sheet=document.getElementById('sheet');
  sheet.classList.add('open'); sheet.scrollTop=0;
  document.getElementById('d_title').textContent='Yükleniyor…';
  document.getElementById('d_eps').innerHTML='';
  document.getElementById('d_desc').textContent='';
  document.getElementById('d_sub').innerHTML='';
  try{
    const r=await fetch(api('action=detail&slug='+encodeURIComponent(slug)));
    const j=await r.json();
    curSeries=j;
    const s=j.series||{};
    document.getElementById('d_hero').src = s.backdrop_image||s.cover_image||'';
    document.getElementById('d_title').textContent = s.title||'';
    document.getElementById('d_desc').textContent = s.description||'';
    document.getElementById('d_sub').innerHTML =
      '<span>🎬 '+esc(s.platform||'')+'</span>'+
      '<span>🌐 '+esc((s.language||'').toUpperCase())+'</span>'+
      '<span>▦ '+ (j.episodes?j.episodes.length:0) +' bölüm</span>';
    const eps=j.episodes||[];
    const box=document.getElementById('d_eps');
    box.innerHTML='';
    eps.forEach((ep,i)=>{
      const b=document.createElement('div');
      b.className='ep'; b.textContent=ep.episode_number||(i+1);
      b.onclick=()=>playFrom(i);
      box.appendChild(b);
    });
  }catch(e){
    document.getElementById('d_title').textContent='Hata: '+e.message;
  }
}
function closeSheet(){ document.getElementById('sheet').classList.remove('open'); }

/* ---------- Player + otomatik sıradaki bölüm ---------- */
let hls=null;
const video=document.getElementById('video');

function playFrom(i){
  if(!curSeries||!curSeries.episodes) return;
  const eps=curSeries.episodes;
  if(i<0||i>=eps.length) return;
  cur=i;
  document.getElementById('player').classList.add('open');
  document.getElementById('p_title').textContent = (curSeries.series?curSeries.series.title:'');
  updatePlayerNav();
  loadEpisode(eps[i]);
}

function loadEpisode(ep){
  const spin=document.getElementById('p_spin');
  spin.style.display='block';
  const src = SELF + '?r=hls&u=' + encodeURIComponent(ep.url);

  if(hls){ hls.destroy(); hls=null; }

  if(window.Hls && Hls.isSupported()){
    hls=new Hls({maxBufferLength:20, enableWorker:true});
    hls.loadSource(src);
    hls.attachMedia(video);
    hls.on(Hls.Events.MANIFEST_PARSED, ()=>{ spin.style.display='none'; video.play().catch(()=>{}); });
    hls.on(Hls.Events.ERROR, (evt,data)=>{ if(data.fatal){ spin.textContent='Oynatma hatası'; } });
  } else {
    // iOS Safari — yerel HLS
    video.src=src;
    video.addEventListener('loadedmetadata', ()=>{ spin.style.display='none'; video.play().catch(()=>{}); }, {once:true});
  }
}

/* bittiğinde otomatik sonraki bölüm */
video.addEventListener('ended', ()=>{
  if(curSeries && cur < curSeries.episodes.length-1){
    playFrom(cur+1);
  }
});
/* son saniyelerde ilerleme çubuğu göster (otomatik geçiş ipucu) */
video.addEventListener('timeupdate', ()=>{
  const bar=document.getElementById('p_autobar');
  if(video.duration && video.duration-video.currentTime < 5){
    bar.style.width = (100*(video.currentTime/video.duration))+'%';
  } else { bar.style.width='0'; }
});
/* videoya dokun → oynat/duraklat */
video.addEventListener('click', ()=>{ video.paused?video.play():video.pause(); });

function updatePlayerNav(){
  const eps=curSeries.episodes;
  document.getElementById('p_prev').disabled = cur<=0;
  document.getElementById('p_next').disabled = cur>=eps.length-1;
  document.getElementById('p_epinfo').textContent = 'Bölüm '+(eps[cur].episode_number||cur+1)+' / '+eps.length;
  const row=document.getElementById('p_eprow');
  row.innerHTML='';
  eps.forEach((ep,i)=>{
    const c=document.createElement('div');
    c.className='epchip'+(i===cur?' active':'');
    c.textContent=ep.episode_number||(i+1);
    c.onclick=()=>playFrom(i);
    row.appendChild(c);
  });
  // aktif çipi görünür yap
  const act=row.querySelector('.active'); if(act) act.scrollIntoView({inline:'center',block:'nearest'});
}

function closePlayer(){
  document.getElementById('player').classList.remove('open');
  video.pause();
  if(hls){ hls.destroy(); hls=null; }
  video.removeAttribute('src'); video.load();
}

function esc(s){ return (s||'').replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

/* ---------- Başlat ---------- */
loadPlatforms();
reload();
</script>
</body>
</html>
