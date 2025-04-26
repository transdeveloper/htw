<?php
/**
 * Note: Current implementation is temporary and should be improved.
 * 
 * Instead of saving screenshots locally, we should upload them to S3.
 * 
 * Future goal:
 * - Collect detailed metadata (body content, human-readable format, search-optimized format for vector search).
 * - Capture screenshots alongside metadata.
 * - Build a processing pipeline where servers perform deeper scans to gather as much metadata as possible.
 * 
 * Data storage strategy:
 * - Store complete raw metadata and screenshots in Cassandra (optimized for large-scale storage).
 * - Store search-optimized metadata in PostgreSQL (for fast indexing and querying).
 */


$prod = 1;
session_name("trustfinna");
session_start();
header_remove("X-Powered-By");
ini_set('memory_limit', $prod < 1 ? '-1' : '1024M');

if(explode('/', $_SERVER['REQUEST_URI'])[1] == "add_task"){
    return include 'add_task.php';
}

switch(explode('/', $_SERVER['REQUEST_URI'])[1]){
    case "add_task":
        return include 'add_task.php';
        break;
    case "phpinfo":
        if (!$prod) return phpinfo();
        break;
    default:
}

$screenshotDir  = "./screenshots/";
$tree           = [];
$ia             = 0;

define('SCREENSHOT_LIMIT', 100); // max screenshots per session
if (!isset($_SESSION['allowed_files'])) {
    $allFiles = glob($screenshotDir . "*.png");
    shuffle($allFiles);

    $_SESSION['allowed_files'] = array_filter($allFiles, function ($file) {
        global $screenshotDir;
        $file = explode($screenshotDir,$file)[1];
        return preg_match('/([\d\.]+)_(\d+)_([\d]{8}-[\d]{6})\.png$/', basename($file));
    });

    $_SESSION['allowed_files'] = array_slice($_SESSION['allowed_files'], 0, SCREENSHOT_LIMIT);
}
$files          = $prod < 1 ? glob($screenshotDir."*.png") : $_SESSION['allowed_files'];

foreach ($files as $file) {
    $file = explode($screenshotDir,$file)[1];
    if (preg_match('/^([\d\.]+)_(\d+)_([\d]{8}-[\d]{6})\.png$/', $file, $m)) {
        // if (rand(0, 1) === 0) continue;
        $ip = $m[1];
        $port = $m[2];
        $date = $m[3];
        $key = "$ip:$port";
        $tree[$key][] = ['file' => 'data:image/png;base64,' . base64_encode(file_get_contents($screenshotDir.$file)), 'timestamp' => $date];
        // $tree[$key][] = ['file' => 'data:image/png;base64,' . base64_encode(file_get_contents($file)), 'timestamp' => $date];
    }
}

// Handle filters
// var_dump(explode('/', $_SERVER['REQUEST_URI'])[1] ?? 0);
$filterIp = trim($_GET['ip'] ?? '');
$filterDate = trim($_GET['date'] ?? '');
$filterPort = trim($_GET['port'] ?? '');

$filteredTree = array_filter($tree, function ($revisions, $key) use ($filterIp, $filterPort, $filterDate) {
    [$ip, $port] = explode(':', $key);
    $matchIp = !$filterIp || strpos($ip, $filterIp) !== false;
    $matchPort = !$filterPort || strpos($port, $filterPort) !== false;
    $matchDate = !$filterDate || array_filter($revisions, fn($r) => strpos($r['timestamp'], $filterDate) !== false);
    return $matchIp && $matchPort && $matchDate;
}, ARRAY_FILTER_USE_BOTH);

// Sort by IP/port
ksort($filteredTree);

$targets = array_keys($filteredTree);
$totalTargets = count($targets);
$perPage = 5;

$page = max(1, (int)(explode('/', $_SERVER['REQUEST_URI'])[1] ?? 1));
// $page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = ceil($totalTargets / $perPage);
$start = ($page - 1) * $perPage;
$targetsPage = array_slice($targets, $start, $perPage);

$isFilter = (isset($_GET['ip']) && !empty($_GET['ip'])) || (isset($_GET['date']) && !empty($_GET['date'])) || (isset($_GET['port']) && !empty($_GET['port']));
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Make The Internet Great Again! (p<?= $page ?>)</title>

<meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <style>
body {
    background: #111;
    color: #7fff7f;
    font-family: monospace;
    padding: 1em;
    filter: contrast(1.2) brightness(1.1) saturate(1.2);
    position: relative;
    overflow-x: hidden;
    max-width:500px;
}
.mt-4{
    margin-top: 12px;
}

.g{
    display:grid;
    gap:0;
}

/* CRT scanlines & flicker effect */
body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 99vw;
    height: 99vh;
    z-index: 9998;
    pointer-events: none;
    background: repeating-linear-gradient(
        0deg,
        rgba(0, 255, 0, 0.05),
        rgba(0, 255, 0, 0.05) 0.15px,
        transparent 1px,
        transparent 3px
    );
    mix-blend-mode: overlay;
    animation: flicker 0.15s infinite;
}

/* Flicker animation */
@keyframes flicker {
    0%   { opacity: 0.95; }
    50%  { opacity: 1; }
    100% { opacity: 0.97; }
}


body {
    background-color: #111;
    color: #7fff7f;
    font-family: monospace;
    padding: 1em;
    filter: contrast(1.2) brightness(1.1) saturate(1.2);
}

        /* body {
            background: #111;
            color: #7fff7f;
            font-family: monospace;
            padding: 1em;
        } */
        a {
            color: #7fcfff;
            text-decoration: none;
        }
        .tree-line {
            margin-left: 1.5em;
        }
        .screenshot {
            display: none;
            position: absolute;
            border: 1px solid #555;
            z-index: 1000;
            max-width: 400px;
        }
        .pagination {
            margin-top: 2em;
        }
        .pagination a {
            margin-right: 1em;
            color: #fff;
        }
        .filter-form {
            margin-bottom: 1em;
        }
        .filter-form input {
            background: #222;
            color: #7fff7f;
            border: 1px solid #555;
            padding: 2px 5px;
            margin-right: 0.5em;
        }
        .filter-form button {
            background: #333;
            color: #7fff7f;
            border: 1px solid #555;
            padding: 2px 8px;
        }
        .finna{
            background: #fff;
            color: #000;
        }
    </style>

<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-295C9L9G2T"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-295C9L9G2T');
</script>

</head>
<body>
<?php
include 'inc/top.php';
?>
<h3>Tree view of <?= $totalTargets ?> scanned targets (total: <?= count($files)-1; ?>)</h3>
<h4>Showing <?= $start + 1 ?> to <?= min($start + $perPage, $totalTargets) ?> (page <?= $page ?> of <?= $totalPages ?>)</h4>

<form method="GET" class="filter-form">
    <input type="text" name="ip" placeholder="IP" value="<?= htmlspecialchars($filterIp) ?>">
    <input type="text" name="port" placeholder="Port" value="<?= htmlspecialchars($filterPort) ?>">
    <input type="text" name="date" placeholder="Date (YYYYMMDD)" value="<?= htmlspecialchars($filterDate) ?>">
    <button type="submit">Filter</button>
</form>

<pre class="g">
<?php if($isFilter && count($targetsPage) == 0){ ?> 
We've returned 0 results.
<?php } ?>
<?php foreach ($targetsPage as $ipport): ?>
<div>|</div>
<div>├── <a href="http://<?= htmlspecialchars($ipport) ?>" target="_blank"><?= $ipport ?> (open)</a>
<?php foreach ($filteredTree[$ipport] as $i => $entry): ?>
<?php $last = $i === array_key_last($filteredTree[$ipport]); ?>
<a><span><?= $last ? "│   └── " : "│   ├── " ?><a href="#" class="screenshot-link" data-file="<?= htmlspecialchars($entry['file']) ?>"><?= htmlspecialchars($entry['timestamp']) ?></a></span></div>
<?php endforeach; ?>
<?php endforeach; ?>
</pre>

<div class="pagination">
<?php if ($page > 1): ?>
    <a href="/<?= $page - 1; ?><?= $isFilter ? "?ip=$filterIp&port=$filterPort&date=$filterDate" : ""?>">← Prev</a>
<?php //<a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?\>">← Prev</a> ?>
<?php endif; ?>
<?php if ($page < $totalPages): ?>
    <a href="/<?= $page + 1; ?><?= $isFilter ? "?ip=$filterIp&port=$filterPort&date=$filterDate" : ""?>">Next →</a>
<?php // <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?\>">Next →</a> ?>
<?php endif; ?></div>
<img id="preview" class="screenshot">
<script>
const preview = document.getElementById('preview');
document.querySelectorAll('.screenshot-link').forEach(link => {
    link.addEventListener('mouseover', (e) => {
        const file = link.dataset.file;
        preview.src = file;
        preview.style.display = 'block';
        preview.style.left = (e.pageX + 20) + 'px';
        preview.style.top = (e.pageY + 10) + 'px';
    });
    link.addEventListener('mousemove', (e) => {
        preview.style.left = (e.pageX + 20) + 'px';
        preview.style.top = (e.pageY + 10) + 'px';
    });
    link.addEventListener('mouseout', () => {
        preview.style.display = 'none';
    });
});
</script>
<?php
include 'inc/footer.php';
?>
</body>
</html>