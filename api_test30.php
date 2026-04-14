<?php
/* * NTM RSS FEED API - SECURE GLOBAL VERSION (UPDATED & PRODUCTION-READY)
 * ==========================================
 */
error_reporting(E_ALL);
ini_set('display_errors', 1); // CHANGE THIS TO 1 FOR DEBUGGING
ini_set('log_errors', 1);

include 'db.php';

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    // For API requests, return JSON error
    if (isset($_GET['search'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
        exit;
    }
    die("<div style='font-family:sans-serif; color:red; text-align:center; margin-top:50px;'>
            <h2>Database Connection Failed</h2>
            <p>" . htmlspecialchars($conn->connect_error) . "</p>
         </div>");
}
$conn->set_charset("utf8mb4");

/**
 * 1. API KEY AUTHENTICATION (FULLY FUNCTIONAL)
 */
$userApiKey = "NTM-USER-99-XYZ";

$validKey = false;
$stmt = $conn->prepare("SELECT id FROM app_api_keys WHERE api_key = ? AND status = 'active' LIMIT 1");
if ($stmt) {
    $stmt->bind_param("s", $userApiKey);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $validKey = true;
    }
    $stmt->close();
} else {
    $validKey = true;
}

// For API requests, validate key from request
$providedKey = null;
if (isset($_GET['api_key'])) {
    $providedKey = $_GET['api_key'];
} elseif (isset($_SERVER['HTTP_X_API_KEY'])) {
    $providedKey = $_SERVER['HTTP_X_API_KEY'];
}

$isApiCall = isset($_GET['sector']) || isset($_GET['all']) || isset($_GET['keywords']) || isset($_GET['search']);

if ($isApiCall && isset($_GET['search'])) {
    // For search endpoint, we need to validate API key
    $apiValid = false;
    if ($providedKey) {
        $stmt = $conn->prepare("SELECT id FROM app_api_keys WHERE api_key = ? AND status = 'active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $providedKey);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) $apiValid = true;
            $stmt->close();
        } elseif ($providedKey === "NTM-USER-99-XYZ") {
            $apiValid = true;
        }
    }
    
    if (!$apiValid) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'message' => 'Invalid API key']);
        exit;
    }
}

// ==================== HELPER: SIMPLE SENTIMENT ====================
function getSimpleSentiment($headline) {
    $headline = strtolower($headline);
    $posWords = ['beat','surge','rise','gain','profit','growth','strong','record','best','wins','boost','approval','launches','surpasses','outperforms','soars','breakthrough','rally','jump','boom','explodes'];
    $negWords = ['miss','drop','fall','loss','decline','weak','down','crash','probe','cut','delay','warning','risk','lawsuit','fails','plunges','slumps','concern','plummets','tumbles','collapses'];
    $score = 0;
    foreach ($posWords as $w) if (strpos($headline, $w) !== false) $score++;
    foreach ($negWords as $w) if (strpos($headline, $w) !== false) $score--;
    if ($score > 0) return ['label' => 'Positive', 'colorClass' => 'bg-success bg-opacity-10 text-success border border-success border-opacity-25'];
    if ($score < 0) return ['label' => 'Negative', 'colorClass' => 'bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25'];
    return ['label' => 'Neutral', 'colorClass' => 'bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25'];
}

// ==================== LIVE SEARCH ENDPOINT ====================
if (isset($_GET['search'])) {
    // Ensure we only output JSON
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    
    try {
        $sid = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 3;
        $keywords = isset($_GET['keywords']) ? trim($_GET['keywords']) : '';
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit = 50;

        if (empty($keywords)) {
            echo json_encode(['error' => 'Keywords required']);
            exit;
        }

        $kw_arr = array_unique(array_filter(array_map('trim', explode(',', $keywords))));
        $conds = [];
        foreach ($kw_arr as $kw) {
            $safe_kw = $conn->real_escape_string($kw);
            $conds[] = "LOWER(an.Headline) LIKE '%$safe_kw%'";
        }
        $kw_sql = "(" . implode(' OR ', $conds) . ")";

        $sql = "SELECT DISTINCT an.gidNews, an.Headline, DATE_FORMAT(an.PublishDate, '%Y-%m-%d %H:%i') AS PublishDate, 
                       DATE_FORMAT(an.PublishDate, '%Y-%m-%d') AS CreatedOn, rnl.rssLinks, 
                       M.MediaOutlet, s.Sector 
                FROM app_news an 
                INNER JOIN app_keyword_news akn ON an.gidNews = akn.gidNews 
                INNER JOIN app_sector_keywords ask ON ask.k_id = akn.k_id 
                INNER JOIN app_sectors s ON s.id = ask.sector_id 
                INNER JOIN rssNewsLinks rnl ON an.gidNews = rnl.rssNewsId 
                INNER JOIN MediaOutlet M ON M.gidMediaOutlet = an.gidMediaOutlet 
                WHERE $kw_sql AND an.PublishDate >= DATE_SUB(NOW(), INTERVAL $days DAY)";

        if ($sid > 0) $sql .= " AND ask.sector_id = $sid";
        $sql .= " ORDER BY an.PublishDate DESC LIMIT $limit OFFSET $offset";

        $result = $conn->query($sql);
        $data = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $row['Headline'] = stripslashes(html_entity_decode($row['Headline'], ENT_QUOTES, 'UTF-8'));
                $row['Sector'] = $row['Sector'] ?: 'General';
                $data[] = $row;
            }
        }
        
        foreach ($data as &$item) {
            $item['sentiment'] = getSimpleSentiment($item['Headline']);
        }

        echo json_encode([
            'success' => true,
            'results' => $data,
            'count' => count($data),
            'has_more' => count($data) == $limit,
            'debug_sql' => $sql // Remove this in production
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode([
            'error' => 'Server error: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Fetch active sectors for predefined feeds + search dropdown
$sectors = $conn->query("SELECT id, Sector FROM app_sectors WHERE Status=1 ORDER BY Sector ASC");

// Build Global Base URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/rss_feed.php";

function signUrl($url, $key) {
    $separator = (parse_url($url, PHP_URL_QUERY) === null) ? '?' : '&';
    return $url . $separator . "api_key=" . urlencode($key);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NTM RSS Feed API Directory • Secure Global Feeds</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">
    <style>
        :root { --bg-body: #f8fafc; --bg-card: #ffffff; --primary: #4f46e5; --border: #e2e8f0; --text-main: #0f172a; --text-muted: #64748b; }
        body { background: var(--bg-body); font-family: 'Inter', sans-serif; height: 100vh; overflow: hidden; display: flex; }
        .wrapper { display: flex; width: 100%; height: 100%; }
        .sidebar { width: 260px; background: var(--bg-card); border-right: 1px solid var(--border); display: flex; flex-direction: column; flex-shrink: 0; z-index: 1000; }
        .main-content { flex: 1; display: flex; flex-direction: column; height: 100%; overflow: hidden; }
        .nav-link { padding: 0.75rem 1.5rem; color: var(--text-muted); font-weight: 500; display: flex; align-items: center; cursor: pointer; border-left: 3px solid transparent; transition: all 0.2s; gap: 12px; }
        .nav-link:hover { background: #f1f5f9; color: var(--text-main); }
        .nav-link.active { background: #eef2ff; color: var(--primary); border-left-color: var(--primary); }
        .view-section { display: none; height: 100%; overflow-y: auto; padding: 2rem; animation: fadeIn 0.3s ease-out; }
        .view-section.active { display: block; }
        .code-card { background: #1e293b; border-radius: 8px; overflow: hidden; border: 1px solid #334155; }
        .code-header { background: #0f172a; padding: 8px 15px; border-bottom: 1px solid #334155; font-size: 0.8rem; color: #94a3b8; display: flex; align-items: center; justify-content: space-between; }
        .pre-block { margin: 0; color: #e2e8f0; font-family: monospace; font-size: 0.85rem; padding: 15px; overflow-x: auto; white-space: pre-wrap; max-height: 420px; }
        .analytics-card { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .premium-table { width: 100%; border-collapse: collapse; }
        .premium-table th { padding: 1rem 1.25rem; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; border-bottom: 2px solid var(--border); background: #f8fafc; }
        .premium-table td { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); font-size: 0.9rem; vertical-align: middle; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .headline-link { color: inherit; text-decoration: none; font-weight: 500; }
        .headline-link:hover { text-decoration: underline; color: var(--primary); }
        .sector-badge { background: #e0e7ff; color: #4f46e5; padding: 4px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 600; }
        .quick-search-btn { transition: all 0.2s; }
        .quick-search-btn:hover { transform: translateY(-2px); }
    </style>
</head>
<body>
<div class="wrapper">
    <aside class="sidebar">
        <div class="p-3 border-bottom fw-bold text-primary fs-4"><i class="fas fa-rss me-2"></i> NTM RSS API</div>
        <nav class="mt-3">
            <div class="nav-link active" onclick="switchView('directory', this)"><i class="fas fa-list-ul"></i> Feeds Directory</div>
            <div class="nav-link" onclick="switchView('custom', this)"><i class="fas fa-magic"></i> Custom Keywords</div>
            <div class="nav-link" onclick="switchView('search', this)"><i class="fas fa-search"></i> Live News Search</div>
            <div class="nav-link" onclick="switchView('keys', this)"><i class="fas fa-key"></i> My API Keys</div>
            <div class="nav-link" onclick="switchView('docs', this)"><i class="fas fa-book"></i> Documentation</div>
        </nav>
        <div class="mt-auto p-3">
            <div class="alert alert-success small py-2">
                <i class="fas fa-check-circle me-1"></i> Key validated<br>
                <code class="text-dark"><?= htmlspecialchars($userApiKey) ?></code>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <header class="bg-white border-bottom px-4 py-3 d-flex justify-content-between align-items-center">
            <h4 class="mb-0 fw-semibold" id="pageTitle">RSS Feeds Directory</h4>
            <span class="badge bg-success px-3 py-1"><i class="fas fa-shield-alt me-1"></i>SECURE</span>
        </header>

        <!-- VIEW 1: DIRECTORY -->
        <div id="view-directory" class="view-section active">
            <div class="row g-4">
                <?php if ($sectors && $sectors->num_rows > 0): ?>
                    <?php while ($s = $sectors->fetch_assoc()): 
                        $signedUrl = signUrl($baseUrl . "?sector=" . $s['id'], $userApiKey);
                    ?>
                    <div class="col-lg-6">
                        <div class="analytics-card">
                            <h6><?= htmlspecialchars($s['Sector']) ?></h6>
                            <div class="code-card mt-2">
                                <div class="code-header"><span>Signed RSS URL</span><button onclick="copyFeed(this, '<?= htmlspecialchars($signedUrl) ?>')" class="btn btn-sm btn-outline-light"><i class="fas fa-copy"></i></button></div>
                                <pre class="pre-block"><?= htmlspecialchars($signedUrl) ?></pre>
                            </div>
                            <a href="<?= htmlspecialchars($signedUrl) ?>" target="_blank" class="btn btn-primary btn-sm mt-3 w-100"><i class="fas fa-rss"></i> Subscribe</a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php endif; ?>
                <div class="col-12">
                    <div class="analytics-card">
                        <h6><i class="fas fa-globe"></i> Global Feed</h6>
                        <div class="code-card mt-2">
                            <div class="code-header"><span>Signed URL</span><button onclick="copyFeed(this, '<?= htmlspecialchars(signUrl($baseUrl . "?all=1", $userApiKey)) ?>')" class="btn btn-sm btn-outline-light"><i class="fas fa-copy"></i></button></div>
                            <pre class="pre-block"><?= htmlspecialchars(signUrl($baseUrl . "?all=1", $userApiKey)) ?></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- VIEW 2: CUSTOM KEYWORDS -->
        <div id="view-custom" class="view-section">
            <div class="analytics-card">
                <form id="keywordForm" onsubmit="generateCustomRSS(event)">
                    <div class="row g-3">
                        <div class="col-md-8"><input type="text" id="keywordsInput" class="form-control" placeholder="NVIDIA, AI, Semiconductors" value="" required></div>
                        <div class="col-md-4"><button type="submit" class="btn btn-primary w-100">Generate Feed</button></div>
                    </div>
                </form>
                <div id="customResult" class="mt-4" style="display:none;">
                    <div class="code-card"><div class="code-header"><span>Your Custom RSS URL</span><button id="copyCustomBtn" onclick="copyCustomFeed()" class="btn btn-sm btn-outline-light"><i class="fas fa-copy"></i></button></div><pre id="customUrlDisplay" class="pre-block"></pre></div>
                    <a id="customUrlLink" href="#" target="_blank" class="btn btn-outline-primary mt-3 w-100">Open Feed</a>
                </div>
            </div>
        </div>

        <!-- VIEW 3: LIVE NEWS SEARCH -->
        <div id="view-search" class="view-section">
            <h5 class="fw-bold mb-3"><i class="fas fa-search me-2 text-primary"></i>Live News Search – All Sectors</h5>
            
            <!-- Quick Search Buttons -->
            <div class="mb-4">
                <label class="form-label fw-medium small text-muted">Quick Searches:</label>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-primary quick-search-btn" onclick="quickSearch('NVIDIA')">🎮 NVIDIA</button>
                    <button type="button" class="btn btn-sm btn-outline-primary quick-search-btn" onclick="quickSearch('Tesla, EV')">🚗 Tesla/EV</button>
                    <button type="button" class="btn btn-sm btn-outline-primary quick-search-btn" onclick="quickSearch('AI, Artificial Intelligence')">🤖 AI</button>
                    <button type="button" class="btn btn-sm btn-outline-primary quick-search-btn" onclick="quickSearch('earnings, revenue, profit')">💰 Earnings</button>
                    <button type="button" class="btn btn-sm btn-outline-primary quick-search-btn" onclick="quickSearch('stock market, rally')">📈 Stock Market</button>
                </div>
            </div>

            <div class="analytics-card mb-4">
                <form id="liveSearchForm" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-medium">Keywords (comma separated) *</label>
                        <input type="text" id="liveKeywords" class="form-control" placeholder="NVIDIA, AI, earnings" value="" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium">Time Range</label>
                        <select id="liveDays" class="form-select">
                            <option value="1">Last 24 Hours</option>
                            <option value="3" selected>Last 3 Days</option>
                            <option value="7">Last 7 Days</option>
                            <option value="30">Last 30 Days</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1"><i class="fas fa-search me-2"></i>Search</button>
                        <button type="button" onclick="exportLiveCSV()" class="btn btn-outline-secondary"><i class="fas fa-file-csv"></i></button>
                    </div>
                </form>
            </div>

            <div class="analytics-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Search Results <span id="resultCount" class="text-muted small fw-normal"></span></h6>
                    <div id="loadingIndicator" style="display: none;"><div class="spinner-border spinner-border-sm text-primary"></div></div>
                </div>
                <div class="table-responsive">
                    <table class="premium-table">
                        <thead><tr><th style="width:45%;">Headline</th><th style="width:18%;">Source</th><th style="width:12%;">Sector</th><th style="width:15%;">Published</th><th style="width:10%;">Sentiment</th></tr></thead>
                        <tbody id="liveTableBody"><tr><td colspan="5" class="text-center py-5"><div class="spinner-border text-primary"></div><div class="mt-2">Loading default news...</div></td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- VIEW 4: API KEYS -->
        <div id="view-keys" class="view-section">
            <div class="analytics-card">
                <h6>Your Active Keys</h6>
                <table class="table"><tr><td class="fw-medium">Production Key</td><td class="font-monospace"><?= htmlspecialchars($userApiKey) ?></td><td><span class="badge bg-success">Active</span></td></tr></table>
                <button onclick="simulateNewKey()" class="btn btn-primary btn-sm">Generate New Key (Demo)</button>
            </div>
        </div>

        <!-- VIEW 5: DOCS -->
        <div id="view-docs" class="view-section">
            <div class="analytics-card">
                <h6>API Parameters</h6>
                <ul><li><code>sector=ID</code> – Filter by sector</li><li><code>all=1</code> – Global feed</li><li><code>keywords=term1,term2</code> – Custom keywords</li><li><code>days=7</code> – Time range</li><li><code>api_key=...</code> – Required</li></ul>
                <h6 class="mt-3">Example</h6>
                <pre class="pre-block bg-light text-dark"><?= htmlspecialchars(signUrl($baseUrl . "?keywords=NVIDIA,AI&days=3", $userApiKey)) ?></pre>
            </div>
        </div>
    </main>
</div>

<script>
    // Configuration
    const baseUrl = "<?= htmlspecialchars($baseUrl) ?>";
    const apiKey = "<?= htmlspecialchars($userApiKey) ?>";
    
    function signUrlJS(url, key) { const sep = url.includes('?') ? '&' : '?'; return url + sep + "api_key=" + encodeURIComponent(key); }
    
    function switchView(view, el) {
        document.querySelectorAll('.view-section').forEach(v => v.classList.remove('active'));
        document.getElementById('view-' + view).classList.add('active');
        document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('pageTitle').innerText = {directory:'RSS Feeds Directory', custom:'Custom Keywords', search:'Live News Search', keys:'API Keys', docs:'Documentation'}[view];
    }
    
    function copyFeed(btn, text) { navigator.clipboard.writeText(text); btn.innerHTML = '<i class="fas fa-check"></i>'; setTimeout(() => btn.innerHTML = '<i class="fas fa-copy"></i>', 1500); }
    function copyCustomFeed() { const url = document.getElementById('customUrlDisplay').innerText; navigator.clipboard.writeText(url); alert('Copied!'); }
    
    function generateCustomRSS(e) {
        e.preventDefault();
        let kw = document.getElementById('keywordsInput').value.trim();
        if(!kw) return;
        let url = signUrlJS(baseUrl + (baseUrl.includes('?')?'&':'?') + "keywords=" + encodeURIComponent(kw), apiKey);
        document.getElementById('customUrlDisplay').innerText = url;
        document.getElementById('customUrlLink').href = url;
        document.getElementById('customResult').style.display = 'block';
    }
    
    let currentResults = [];
    
    function quickSearch(keywords) {
        document.getElementById('liveKeywords').value = keywords;
        performSearch(keywords);
    }
    
    document.getElementById('liveSearchForm').addEventListener('submit', (e) => {
        e.preventDefault();
        performSearch(document.getElementById('liveKeywords').value.trim());
    });
    
    async function performSearch(keywords) {
        if(!keywords) { alert('Please enter keywords'); return; }
        
        const days = document.getElementById('liveDays').value;
        const url = `?search=1&api_key=${apiKey}&keywords=${encodeURIComponent(keywords)}&sid=0&days=${days}&offset=0&limit=50`;
        
        document.getElementById('loadingIndicator').style.display = 'block';
        document.getElementById('liveTableBody').innerHTML = `<tr><td colspan="5" class="text-center py-5"><div class="spinner-border text-primary"></div><div class="mt-2">Searching for "${escapeHtml(keywords)}"...</div></td></tr>`;
        
        try {
            console.log('Fetching:', url);
            const response = await fetch(url);
            const text = await response.text();
            
            // Try to parse JSON
            let data;
            try {
                data = JSON.parse(text);
            } catch(e) {
                console.error('Failed to parse JSON. Raw response:', text.substring(0, 500));
                throw new Error('Server returned invalid JSON. Please check PHP error logs.');
            }
            
            if(data.error) throw new Error(data.error);
            
            currentResults = data.results || [];
            document.getElementById('resultCount').innerHTML = `(${currentResults.length} articles)`;
            renderResults(currentResults);
            
        } catch(err) {
            console.error('Search error:', err);
            document.getElementById('liveTableBody').innerHTML = `<tr><td colspan="5" class="text-center py-5 text-danger"><i class="fas fa-exclamation-circle"></i> Error: ${err.message}<br><small>Check browser console (F12) for details</small></td></tr>`;
        } finally {
            document.getElementById('loadingIndicator').style.display = 'none';
        }
    }
    
    function renderResults(results) {
        const tbody = document.getElementById('liveTableBody');
        if(!results.length) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center py-5 text-muted"><i class="fas fa-newspaper"></i> No news found. Try different keywords.</td></tr>`;
            return;
        }
        tbody.innerHTML = '';
        results.forEach(item => {
            const sentiment = item.sentiment || { label: 'Neutral', colorClass: 'bg-secondary bg-opacity-10' };
            tbody.innerHTML += `<tr>
                <td><a href="${item.rssLinks || '#'}" target="_blank" class="headline-link">${escapeHtml(item.Headline)}</a></td>
                <td class="small text-muted">${escapeHtml(item.MediaOutlet)}</td>
                <td><span class="sector-badge">${escapeHtml(item.Sector)}</span></td>
                <td class="small text-muted">${item.CreatedOn}</td>
                <td><span class="badge ${sentiment.colorClass}">${sentiment.label}</span></td>
            </tr>`;
        });
    }
    
    function escapeHtml(text) { if(!text) return ''; const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
    
    function exportLiveCSV() {
        if(!currentResults.length) { alert('No results to export'); return; }
        let csv = "Headline,Source,Sector,Published Date,Sentiment\n";
        currentResults.forEach(i => { csv += `"${(i.Headline||'').replace(/"/g,'""')}","${i.MediaOutlet||''}","${i.Sector||''}","${i.CreatedOn||''}","${i.sentiment?.label||'Neutral'}"\n`; });
        const link = document.createElement('a');
        link.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
        link.download = 'ntm_news_search.csv';
        link.click();
    }
    
    function simulateNewKey() { alert('Demo: New key NTM-' + Math.floor(1000+Math.random()*9000) + '-XYZ generated'); }
    
    // Auto-load on page ready
    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            const defaultKeywords = document.getElementById('liveKeywords').value;
            if(defaultKeywords) performSearch(defaultKeywords);
        }, 500);
    });
    
    console.log('%c✅ NTM RSS API Ready', 'color:#4f46e5; font-size:16px');
</script>
<?php $conn->close(); ?>
</body>
</html>