<?php
/**
 * DramaFlix scraper — bağımsız (standalone) PHP + cURL.
 *
 * dramaflix.cc bir React SPA'dır; HTML'de içerik yoktur. Arkasında açık bir
 * JSON API vardır, bu script doğrudan onu çeker:
 *
 *   GET /api/series?limit=&offset=&language=   -> dizi listesi (+ total)
 *   GET /api/series/{slug}                      -> dizi detayı + tüm bölümler
 *   GET /api/home?language=                     -> ana sayfa blokları
 *   GET /api/platforms?language=                -> platform listesi
 *
 * Her bölüm nesnesinde HLS video linki bulunur: episode.url (.m3u8).
 *
 * Kullanım (CLI):
 *   php dramaflix_scraper.php list [--limit=60] [--offset=0] [--lang=TR]
 *   php dramaflix_scraper.php detail <slug>
 *   php dramaflix_scraper.php episodes <slug>          # sadece bölüm+video linkleri
 *   php dramaflix_scraper.php all [--lang=TR] [--max=500]   # sayfalı tüm liste
 *   php dramaflix_scraper.php home [--lang=TR]
 *   php dramaflix_scraper.php platforms [--lang=TR]
 *
 * Çıktı: JSON (stdout). Örn:  php dramaflix_scraper.php list --limit=5
 */

class DramaFlixScraper
{
    private string $base;
    private int $timeout;
    private array $headers;

    public function __construct(string $base = 'https://dramaflix.cc', int $timeout = 30)
    {
        $this->base = rtrim($base, '/');
        $this->timeout = $timeout;
        $this->headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: tr,en;q=0.8',
            'Referer: ' . $this->base . '/',
        ];
    }

    /** Ham cURL GET. Başarısızlıkta RuntimeException fırlatır. */
    private function get(string $path, array $query = []): string
    {
        $url = $this->base . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $this->headers,
            CURLOPT_ENCODING       => '',      // gzip/deflate otomatik çöz
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL hatası ($url): $err");
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            throw new RuntimeException("HTTP $code alındı: $url");
        }
        return $body;
    }

    /** GET + JSON decode. */
    private function getJson(string $path, array $query = []): array
    {
        $raw = $this->get($path, $query);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Geçersiz JSON yanıtı: $path");
        }
        return $data;
    }

    /** Tek sayfa dizi listesi. */
    public function listSeries(int $limit = 60, int $offset = 0, ?string $lang = null): array
    {
        $q = ['limit' => $limit, 'offset' => $offset];
        if ($lang) {
            $q['language'] = strtoupper($lang);
        }
        return $this->getJson('/api/series', $q);
    }

    /** Dizi detayı + bölümler (slug ile). */
    public function seriesDetail(string $slug): array
    {
        return $this->getJson('/api/series/' . rawurlencode($slug));
    }

    /** Sadece bölüm listesi (video linkleriyle). */
    public function episodes(string $slug): array
    {
        $d = $this->seriesDetail($slug);
        return $d['episodes'] ?? [];
    }

    /** Ana sayfa blokları. */
    public function home(?string $lang = null): array
    {
        return $this->getJson('/api/home', $lang ? ['language' => strtolower($lang)] : []);
    }

    /** Platform listesi. */
    public function platforms(?string $lang = null): array
    {
        return $this->getJson('/api/platforms', $lang ? ['language' => strtolower($lang)] : []);
    }

    /**
     * Tüm dizileri sayfalayarak çeker (istekler arası kısa bekleme ile nazik davranır).
     * $max: en fazla kaç dizi çekileceği (sunucuyu yormamak için sınır).
     */
    public function allSeries(?string $lang = null, int $max = 500, int $pageSize = 60, float $delay = 0.3): array
    {
        $out = [];
        $offset = 0;
        $total = PHP_INT_MAX;

        while (count($out) < $max && $offset < $total) {
            $page = $this->listSeries($pageSize, $offset, $lang);
            $total = (int)($page['total'] ?? 0);
            $items = $page['series'] ?? [];
            if (!$items) {
                break;
            }
            foreach ($items as $s) {
                $out[] = $s;
                if (count($out) >= $max) {
                    break;
                }
            }
            $offset += $pageSize;
            if ($offset < $total && count($out) < $max) {
                usleep((int)($delay * 1000000));
            }
        }
        return ['fetched' => count($out), 'total' => $total, 'series' => $out];
    }
}

/* ----------------------------- CLI ----------------------------- */

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $args = array_slice($argv, 1);
    $cmd = $args[0] ?? 'list';

    // --flag=value ayrıştır
    $opts = [];
    $pos = [];
    foreach (array_slice($args, 1) as $a) {
        if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) {
            $opts[$m[1]] = $m[2];
        } else {
            $pos[] = $a;
        }
    }

    $scraper = new DramaFlixScraper();
    $lang = $opts['lang'] ?? null;

    try {
        switch ($cmd) {
            case 'list':
                $res = $scraper->listSeries(
                    (int)($opts['limit'] ?? 60),
                    (int)($opts['offset'] ?? 0),
                    $lang
                );
                break;

            case 'detail':
                if (empty($pos[0])) {
                    fwrite(STDERR, "Kullanım: detail <slug>\n");
                    exit(1);
                }
                $res = $scraper->seriesDetail($pos[0]);
                break;

            case 'episodes':
                if (empty($pos[0])) {
                    fwrite(STDERR, "Kullanım: episodes <slug>\n");
                    exit(1);
                }
                $res = $scraper->episodes($pos[0]);
                break;

            case 'all':
                $res = $scraper->allSeries($lang, (int)($opts['max'] ?? 500));
                break;

            case 'home':
                $res = $scraper->home($lang);
                break;

            case 'platforms':
                $res = $scraper->platforms($lang);
                break;

            default:
                fwrite(STDERR, "Bilinmeyen komut: $cmd\n");
                fwrite(STDERR, "Komutlar: list | detail <slug> | episodes <slug> | all | home | platforms\n");
                exit(1);
        }

        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, 'Hata: ' . $e->getMessage() . PHP_EOL);
        exit(2);
    }
}

/* ----------------------------- WEB ----------------------------- */
/*
 * Tarayıcıdan / hPanel'den erişim (CLI değilken). Örnekler:
 *   dramaflix_scraper.php?action=list&limit=10&lang=TR
 *   dramaflix_scraper.php?action=detail&slug=dunyaya-donus
 *   dramaflix_scraper.php?action=episodes&slug=dunyaya-donus
 *   dramaflix_scraper.php?action=all&lang=TR&max=120
 *   dramaflix_scraper.php?action=home&lang=TR
 *   dramaflix_scraper.php?action=platforms
 * Çıktı: JSON (Content-Type: application/json).
 */
elseif (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'] ?? 'list';
    $lang   = isset($_GET['lang']) && $_GET['lang'] !== '' ? $_GET['lang'] : null;
    $slug   = $_GET['slug'] ?? '';

    $scraper = new DramaFlixScraper();

    try {
        switch ($action) {
            case 'list':
                $res = $scraper->listSeries(
                    (int)($_GET['limit'] ?? 60),
                    (int)($_GET['offset'] ?? 0),
                    $lang
                );
                break;

            case 'detail':
                if ($slug === '') {
                    throw new InvalidArgumentException('slug parametresi gerekli (?action=detail&slug=...)');
                }
                $res = $scraper->seriesDetail($slug);
                break;

            case 'episodes':
                if ($slug === '') {
                    throw new InvalidArgumentException('slug parametresi gerekli (?action=episodes&slug=...)');
                }
                $res = $scraper->episodes($slug);
                break;

            case 'all':
                $res = $scraper->allSeries($lang, (int)($_GET['max'] ?? 500));
                break;

            case 'home':
                $res = $scraper->home($lang);
                break;

            case 'platforms':
                $res = $scraper->platforms($lang);
                break;

            default:
                http_response_code(400);
                $res = ['error' => "Bilinmeyen action: $action",
                        'actions' => ['list', 'detail', 'episodes', 'all', 'home', 'platforms']];
        }

        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
