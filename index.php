<?php
session_start();

// Force UTF-8 for everything
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

define('DATA_DIR', __DIR__ . '/data/');
define('CACHE_DIR', DATA_DIR . 'cache/');

/**
 * Robust Simple YAML Parser / Dumper
 */
class SimpleYaml {
    public static function load($file) {
        if (!file_exists($file)) return [];
        $lines = file($file);
        $data = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            $parts = explode(': ', $line, 2);
            if (count($parts) < 2) $parts = explode(':', $line, 2);
            if (count($parts) < 2) continue;
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            if ((substr($val, 0, 1) === '"' && substr($val, -1) === '"') || (substr($val, 0, 1) === "'" && substr($val, -1) === "'")) {
                $quote = substr($val, 0, 1);
                $val = substr($val, 1, -1);
                if ($quote === '"') $val = str_replace(['\\"', '\\\\', '\\n', '\\r', '\\t'], ['"', "\\", "\n", "\r", "\t"], $val);
            } else {
                if (preg_match('/^(.*)\s+#.*$/', $val, $matches)) $val = trim($matches[1]);
                if ($val === 'true') $val = true;
                elseif ($val === 'false') $val = false;
                elseif ($val === 'null') $val = null;
                elseif (is_numeric($val)) $val = (strpos($val, '.') !== false) ? (float)$val : (int)$val;
            }
            $data[$key] = $val;
        }
        return $data;
    }
}

// Load Config
$configPath = __DIR__ . '/config.yaml';
$defaultConfig = [
    'app_name' => 's3l3ct0r',
    'logo_svg' => '<svg class="w-16 h-16 text-cyan-500 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
    'background_style' => 'radial-gradient(circle at top right, #1a1a2e, #16213e, #0f3460)',
    'primary_color' => '#06b6d4',
    'secondary_color' => '#3b82f6',
    'font_family' => "Space Grotesk",
    'footer_links' => 'GitHub:https://github.com/alexpthe1/s3l3ct0r',
    'use_x_real_ip' => false,
    'enable_geoip' => true,
    'password_algo' => 'bcrypt',
    'session_creation_rate_limit' => 0,
    'max_ideas_per_session' => 0,
    'honeypot_field' => '',
    'default_method' => 'random',
    'custom_css' => '',
    'debug_mode' => false
];
$config = array_merge($defaultConfig, SimpleYaml::load($configPath));

// Auto-generate Font Import URL
$fontFamily = trim(str_replace([';', '"', "'"], '', $config['font_family']));
$fontImport = "https://fonts.googleapis.com/css2?family=" . str_replace(' ', '+', $fontFamily) . ":wght@300;400;700&display=swap";

// Timezone from Client (Cookie)
if (isset($_COOKIE['client_timezone'])) {
    @date_default_timezone_set($_COOKIE['client_timezone']);
}

// Debug Mode
if ($config['debug_mode']) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

/**
 * Utility: Get Client IP
 */
function getIp($config) {
    if (isset($config['use_x_real_ip']) && $config['use_x_real_ip'] && isset($_SERVER['HTTP_X_REAL_IP'])) return $_SERVER['HTTP_X_REAL_IP'];
    return $_SERVER['REMOTE_ADDR'];
}

/**
 * Utility: Fetch GeoIP info with cache
 */
function getGeoInfo($ip, $config) {
    if (!$config['enable_geoip']) return ['country' => 'Hidden', 'city' => 'Hidden'];
    if ($ip === '127.0.0.1' || $ip === '::1') return ['country' => 'Local', 'city' => 'Localhost'];
    
    $cacheFile = CACHE_DIR . md5($ip) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        return json_decode(file_get_contents($cacheFile), true);
    }
    
    $data = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city,query");
    if ($data) {
        $json = json_decode($data, true);
        if ($json && $json['status'] === 'success') {
            $info = ['country' => $json['country'], 'city' => $json['city']];
            file_put_contents($cacheFile, json_encode($info));
            return $info;
        }
    }
    return ['country' => 'Unknown', 'city' => 'Unknown'];
}

/**
 * Rate Limiting
 */
function checkRateLimit($ip, $limit) {
    if ($limit <= 0) return true;
    $file = DATA_DIR . '.rate_limits.json';
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $now = time();
    $hourAgo = $now - 3600;
    
    if (!isset($data[$ip])) $data[$ip] = [];
    $data[$ip] = array_filter($data[$ip], function($ts) use ($hourAgo) { return $ts > $hourAgo; });
    
    if (count($data[$ip]) >= $limit) return false;
    
    $data[$ip][] = $now;
    file_put_contents($file, json_encode($data));
    return true;
}

/**
 * Utility: Generate a unique ID
 */
function generateId($length = 16) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Utility: Load/Save Session
 */
function loadSession($id) {
    $path = DATA_DIR . $id . '.json';
    return file_exists($path) ? json_decode(file_get_contents($path), true) : null;
}
function saveSession($data) {
    file_put_contents(DATA_DIR . $data['id'] . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function hashPassword($password, $config) {
    return ($config['password_algo'] === 'plaintext') ? $password : password_hash($password, PASSWORD_DEFAULT);
}
function verifyPassword($password, $hash, $config) {
    return ($config['password_algo'] === 'plaintext') ? ($password === $hash) : password_verify($password, $hash);
}

/**
 * Selection Logic
 */
function performSelection(&$session) {
    if (empty($session['options'])) return null;
    $index = 0;
    if ($session['method'] === 'random') { $index = array_rand($session['options']); }
    elseif ($session['method'] === 'weighted') {
        $weights = array_column($session['options'], 'weight');
        $totalWeight = array_sum($weights);
        $random = mt_rand(1, $totalWeight);
        $current = 0;
        foreach ($session['options'] as $idx => $opt) {
            $current += $opt['weight'];
            if ($random <= $current) { $index = $idx; break; }
        }
    } else {
        $hits = array_column($session['options'], 'hits');
        $minHits = min($hits);
        $candidates = [];
        foreach ($session['options'] as $idx => $opt) if ($opt['hits'] == $minHits) $candidates[] = $idx;
        $index = $candidates[array_rand($candidates)];
    }
    $session['options'][$index]['hits']++;
    $session['last_activity'] = time();
    saveSession($session);
    return $session['options'][$index]['text'];
}

if (!file_exists(CACHE_DIR)) mkdir(CACHE_DIR, 0775, true);

// --- ROUTING & ACTIONS ---
$error = '';
$currentSession = null;
$sessionId = $_GET['id'] ?? '';
$isAuthenticated = false;

if ($sessionId) {
    $currentSession = loadSession($sessionId);
    if (!$currentSession) { $error = "Session nicht gefunden."; }
    else {
        if (isset($_SESSION['auth_' . $sessionId])) $isAuthenticated = true;
        elseif (empty($currentSession['password_hash'])) {
            if (!in_array($currentSession['method'], ['poll', 'brainstorm']) || isset($_GET['admin'])) {
                $isAuthenticated = true;
                $_SESSION['auth_' . $sessionId] = true;
            }
        }
        if (!$isAuthenticated && isset($_POST['action']) && $_POST['action'] === 'login') {
            if (verifyPassword($_POST['password'], $currentSession['password_hash'], $config)) {
                $_SESSION['auth_' . $sessionId] = true;
                $isAuthenticated = true;
            } else { $error = "Falsches Passwort."; }
        }
    }
}

// Create Session
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    if (!checkRateLimit(getIp($config), $config['session_creation_rate_limit'])) {
        $error = "Rate-Limit erreicht. Bitte warte eine Stunde.";
    } else {
        $id = generateId();
        $method = $_POST['method'] ?: 'random';
        if (!in_array($method, ['random', 'even', 'weighted', 'poll', 'brainstorm'])) $method = 'random';
        $newSession = [
            'id' => $id, 'title' => htmlspecialchars($_POST['title'] ?: 'Neue Auswahl'),
            'method' => $method, 'password_hash' => $_POST['password'] ? hashPassword($_POST['password'], $config) : '', 
            'options' => [], 'ideas' => [], 'created_at' => time(), 'last_activity' => time(),
            'settings' => ['poll_allow_multiple' => isset($_POST['poll_allow_multiple'])], 'votes' => []
        ];
        saveSession($newSession);
        $_SESSION['auth_' . $id] = true;
        header("Location: ?id=" . $id . "&admin=1");
        exit;
    }
}

// Actions
if (isset($_POST['action'])) {
    if ($isAuthenticated && $_POST['action'] === 'add_option' && !empty($_POST['option_text'])) {
        $currentSession['options'][] = ['text' => htmlspecialchars($_POST['option_text']), 'hits' => 0, 'weight' => (int)($_POST['weight']?:1), 'votes' => 0];
        saveSession($currentSession);
        header("Location: ?id=" . $sessionId . "&admin=1");
        exit;
    }
    if ($_POST['action'] === 'vote' && $sessionId && $currentSession['method'] === 'poll') {
        $voterName = htmlspecialchars(trim($_POST['voter_name']));
        $selected = isset($_POST['vote_options']) ? (array)$_POST['vote_options'] : [];
        $votedCookie = 'voted_' . $sessionId;
        if (empty($voterName) || empty($selected)) { $error = "Name und Wahl erforderlich."; }
        elseif (isset($_SESSION[$votedCookie]) || isset($_COOKIE[$votedCookie])) { $error = "Bereits abgestimmt."; }
        else {
            $ip = getIp($config); $geo = getGeoInfo($ip, $config);
            $voteData = ['name' => $voterName, 'options' => array_map('intval', $selected), 'ip' => $ip, 'geo' => $geo, 'time' => time()];
            $currentSession['votes'][] = $voteData;
            $currentSession['last_activity'] = time();
            foreach ($voteData['options'] as $idx) if (isset($currentSession['options'][$idx])) $currentSession['options'][$idx]['votes']++;
            saveSession($currentSession);
            $_SESSION[$votedCookie] = true;
            setcookie($votedCookie, '1', time() + (86400 * 30), "/");
            header("Location: ?id=" . $sessionId . "&voted=1");
            exit;
        }
    }
    if ($_POST['action'] === 'submit_idea' && $sessionId && $currentSession['method'] === 'brainstorm' && !empty($_POST['idea_text'])) {
        // Honeypot check
        if (!empty($config['honeypot_field']) && !empty($_POST[$config['honeypot_field']])) {
            header("Location: ?id=" . $sessionId . "&submitted=1"); exit;
        }
        // Max ideas check
        if ($config['max_ideas_per_session'] > 0 && count($currentSession['ideas'] ?? []) >= $config['max_ideas_per_session']) {
            $error = "Limit für Ideen in dieser Session erreicht.";
        } else {
            $ip = getIp($config); $geo = getGeoInfo($ip, $config);
            $currentSession['ideas'][] = ['text' => htmlspecialchars(trim($_POST['idea_text'])), 'time' => time(), 'ip' => $ip, 'geo' => $geo];
            $currentSession['last_activity'] = time();
            saveSession($currentSession);
            header("Location: ?id=" . $sessionId . "&submitted=1");
            exit;
        }
    }
    if ($isAuthenticated && $_POST['action'] === 'select') { $selectionResult = performSelection($currentSession); }

    if ($isAuthenticated && $_POST['action'] === 'update_option' && isset($_POST['option_idx'])) {
        $idx = (int)$_POST['option_idx'];
        if (isset($currentSession['options'][$idx])) {
            $currentSession['options'][$idx]['text'] = htmlspecialchars($_POST['option_text']);
            if (isset($_POST['weight'])) $currentSession['options'][$idx]['weight'] = (int)$_POST['weight'];
            saveSession($currentSession);
            header("Location: ?id=" . $sessionId . "&admin=1");
            exit;
        }
    }
}

// Handle GET-based actions (Remove Option)
if ($isAuthenticated && isset($_GET['remove']) && $sessionId) {
    $idx = (int)$_GET['remove'];
    if (isset($currentSession['options'][$idx])) {
        unset($currentSession['options'][$idx]);
        $currentSession['options'] = array_values($currentSession['options']);
        
        // Update existing votes to match new indices
        if (!empty($currentSession['votes'])) {
            foreach ($currentSession['votes'] as &$vote) {
                $newSelected = [];
                foreach ($vote['options'] as $oldIdx) {
                    if ($oldIdx < $idx) $newSelected[] = $oldIdx;
                    elseif ($oldIdx > $idx) $newSelected[] = $oldIdx - 1;
                }
                $vote['options'] = $newSelected;
            }
        }
        
        saveSession($currentSession);
        header("Location: ?id=" . $sessionId . "&admin=1");
        exit;
    }
}

$viewMode = 'dashboard';
if ($sessionId && $currentSession) {
    if (isset($_GET['admin'])) $viewMode = $isAuthenticated ? 'dashboard' : 'login';
    elseif ($currentSession['method'] === 'poll') $viewMode = 'poll_vote';
    elseif ($currentSession['method'] === 'brainstorm') $viewMode = 'brainstorm';
    else $viewMode = $isAuthenticated ? 'dashboard' : 'login';
} else { $viewMode = 'landing'; }

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['app_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= $fontImport ?>">
    <style>
        body { font-family: '<?= $fontFamily ?>', sans-serif; }
        .bg-gradient { background: <?= $config['background_style'] ?>; }
        :root { --primary: <?= $config['primary_color'] ?>; --secondary: <?= $config['secondary_color'] ?>; }
        .text-primary { color: var(--primary); }
        .bg-primary { background: linear-gradient(to right, var(--primary), var(--secondary)); }
        .border-primary { border-color: var(--primary); }
        <?= $config['custom_css'] ?>
    </style>
    <script>
        // Set Client Timezone Cookie
        if (!document.cookie.includes('client_timezone=')) {
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            document.cookie = "client_timezone=" + tz + "; path=/; max-age=31536000";
            location.reload();
        }
    </script>
</head>
<body class="bg-gradient min-h-screen text-slate-100 p-4">
    <div class="max-w-md mx-auto">
        <header class="text-center py-8">
            <a href="index.php" class="inline-block">
                <div class="flex items-center justify-center mb-2"><?= $config['logo_svg'] ?></div>
                <h1 class="text-5xl font-bold tracking-tighter text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-500" style="background-image: linear-gradient(to right, var(--primary), var(--secondary));"><?= htmlspecialchars($config['app_name']) ?></h1>
            </a>
            <p class="text-slate-400 mt-2">Die smarte Art zu wählen.</p>
        </header>

        <?php if ($error): ?><div class="bg-red-900/50 border border-red-500 text-red-200 p-4 rounded-xl mb-6 text-sm"><?= $error ?></div><?php endif; ?>

        <?php if ($viewMode === 'landing'): ?>
            <section class="bg-slate-800/50 backdrop-blur-md p-6 rounded-2xl border border-slate-700 shadow-xl">
                <h2 class="text-xl font-bold mb-4 flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>Neue Session</h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create">
                    <div><label class="block text-xs font-bold uppercase text-slate-500 mb-1 ml-1">Titel</label><input type="text" name="title" required placeholder="z.B. Team-Event" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:ring-2 focus:ring-primary/50 outline-none"></div>
                    <div><label class="block text-xs font-bold uppercase text-slate-500 mb-1 ml-1">Methode</label>
                        <select name="method" onchange="togglePollSettings(this.value)" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:ring-2 focus:ring-primary/50 appearance-none text-white cursor-pointer">
                            <option value="random" <?= $config['default_method']==='random'?'selected':'' ?>>Zufall</option>
                            <option value="even" <?= $config['default_method']==='even'?'selected':'' ?>>Gleichmäßige Verteilung</option>
                            <option value="weighted" <?= $config['default_method']==='weighted'?'selected':'' ?>>Gewichtungsbasiert</option>
                            <option value="poll" <?= $config['default_method']==='poll'?'selected':'' ?>>Umfrage (Votings)</option>
                            <option value="brainstorm" <?= $config['default_method']==='brainstorm'?'selected':'' ?>>Brainstorm (Ideensammlung)</option>
                        </select>
                    </div>
                    <div id="poll-settings" class="hidden bg-slate-900/50 p-4 rounded-xl border border-slate-700/50 space-y-3">
                        <label class="flex items-center gap-3 cursor-pointer group"><input type="checkbox" name="poll_allow_multiple" value="1" class="sr-only peer"><div class="w-10 h-6 bg-slate-700 rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-primary after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all relative"></div><span class="text-sm font-medium text-slate-300 group-hover:text-white transition-colors">Mehrfachauswahl erlauben</span></label>
                    </div>
                    <div><label class="block text-xs font-bold uppercase text-slate-500 mb-1 ml-1">Admin-Passwort (optional)</label><input type="password" name="password" placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:ring-2 focus:ring-primary/50 outline-none"></div>
                    <button type="submit" class="w-full bg-primary hover:opacity-90 text-white font-bold py-4 rounded-xl transition-all transform active:scale-[0.98] shadow-lg mt-2">Session starten</button>
                </form>
            </section>

        <?php elseif ($viewMode === 'login'): ?>
            <section class="bg-slate-800/50 backdrop-blur-md p-6 rounded-2xl border border-slate-700 shadow-xl">
                <div class="text-center mb-6"><h2 class="text-xl font-bold">Admin-Bereich</h2><p class="text-slate-400 text-sm mt-1">Bitte gib das Admin-Passwort ein.</p></div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="login"><input type="password" name="password" autofocus placeholder="Passwort" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:ring-2 focus:ring-primary/50 text-center text-xl tracking-widest outline-none">
                    <button type="submit" class="w-full bg-primary hover:opacity-90 text-white font-bold py-4 rounded-xl transition-all transform active:scale-[0.98]">Einloggen</button>
                    <a href="index.php" class="block text-center text-xs text-slate-500 hover:text-slate-300 transition-colors pt-2">Abbrechen</a>
                </form>
            </section>

        <?php elseif ($viewMode === 'poll_vote'): ?>
            <section class="bg-slate-800/50 backdrop-blur-md p-6 rounded-2xl border border-slate-700 shadow-xl text-center">
                <h2 class="text-2xl font-bold mb-6"><?= htmlspecialchars($currentSession['title']) ?></h2>
                <?php if (isset($_GET['voted']) || isset($_SESSION['voted_'.$sessionId]) || isset($_COOKIE['voted_'.$sessionId])): ?>
                    <div class="bg-primary/20 border border-primary/50 text-primary p-4 rounded-xl mb-6"><p class="font-bold">Vielen Dank!</p></div>
                    <div class="space-y-4 text-left">
                        <?php 
                        $max = 1;
                        if (!empty($currentSession['options'])) {
                            $votes = array_column($currentSession['options'], 'votes');
                            $max = !empty($votes) ? max($votes) : 1;
                        }
                        if ($max <= 0) $max = 1;
                        ?>
                        <?php foreach ($currentSession['options'] as $opt): $p = round(($opt['votes']??0)/$max*100); ?>
                            <div class="space-y-1"><div class="flex justify-between text-sm"><span class="font-bold"><?= htmlspecialchars($opt['text']) ?></span><span class="text-primary font-bold"><?= $opt['votes']??0 ?></span></div>
                            <div class="w-full bg-slate-900 rounded-full h-2 overflow-hidden border border-slate-700"><div class="bg-primary h-full transition-all" style="width:<?= $p ?>%"></div></div></div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <form method="POST" class="space-y-6 text-left">
                        <input type="hidden" name="action" value="vote">
                        <div><label class="block text-xs font-bold uppercase text-slate-500 mb-2 ml-1">Dein Name</label><input type="text" name="voter_name" required placeholder="Name" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:ring-2 focus:ring-primary/50 outline-none"></div>
                        <div class="space-y-2">
                            <?php foreach ($currentSession['options'] as $idx => $opt): ?>
                                <label class="flex items-center p-4 bg-slate-900/50 border border-slate-700 rounded-xl cursor-pointer hover:border-primary/50 group transition-all">
                                    <input type="<?= $currentSession['settings']['poll_allow_multiple']?'checkbox':'radio' ?>" name="vote_options[]" value="<?= $idx ?>" class="w-5 h-5 text-primary bg-slate-900 border-slate-700 focus:ring-primary">
                                    <span class="ml-3 font-medium text-slate-200 group-hover:text-white"><?= htmlspecialchars($opt['text']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="w-full bg-primary hover:opacity-90 text-white font-bold py-4 rounded-xl transition-all">Abstimmen</button>
                    </form>
                <?php endif; ?>
                <a href="?id=<?= $sessionId ?>&admin=1" class="text-[10px] text-slate-500 hover:text-primary uppercase tracking-widest font-bold block mt-8">Admin-Login</a>
            </section>

        <?php elseif ($viewMode === 'brainstorm'): ?>
            <section class="bg-slate-800/50 backdrop-blur-md p-6 rounded-2xl border border-slate-700 shadow-xl">
                <div class="text-center mb-6"><h2 class="text-2xl font-bold"><?= htmlspecialchars($currentSession['title']) ?></h2><p class="text-slate-400 text-sm mt-1">Teile deine Ideen!</p></div>
                <form method="POST" class="space-y-4 mb-8">
                    <input type="hidden" name="action" value="submit_idea">
                    <?php if(!empty($config['honeypot_field'])): ?><input type="text" name="<?= $config['honeypot_field'] ?>" class="hidden" style="display:none"><?php endif; ?>
                    <textarea name="idea_text" required placeholder="Deine Idee..." class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:ring-2 focus:ring-primary/50 outline-none min-h-[100px] text-slate-100"></textarea>
                    <button type="submit" class="w-full bg-primary hover:opacity-90 text-white font-bold py-3 rounded-xl transition-all">Idee einreichen</button>
                </form>
                <div class="space-y-4">
                    <h3 class="text-xs font-black uppercase text-slate-500">Bisherige Ideen</h3>
                    <?php if (empty($currentSession['ideas'])): ?><p class="text-slate-500 text-sm italic">Noch keine Ideen vorhanden.</p>
                    <?php else: foreach (array_reverse($currentSession['ideas']) as $idea): ?>
                        <div class="bg-slate-900/50 p-4 rounded-xl border border-slate-700/50"><p class="text-slate-200"><?= nl2br(htmlspecialchars($idea['text'])) ?></p><div class="flex justify-between mt-2 text-[10px] font-bold text-slate-500"><span><?= date('d.m.Y H:i', $idea['time']) ?></span><span><?= $idea['geo']['city'] ?? '' ?></span></div></div>
                    <?php endforeach; endif; ?>
                </div>
                <a href="?id=<?= $sessionId ?>&admin=1" class="text-[10px] text-slate-500 hover:text-primary uppercase tracking-widest font-bold block mt-8 text-center">Admin-Login</a>
            </section>

        <?php elseif ($viewMode === 'dashboard'): ?>
            <main class="space-y-6">
                <div class="flex items-center gap-3 bg-slate-800/50 p-4 rounded-2xl border border-slate-700 shadow-lg"><a href="index.php" class="p-2 bg-slate-700/50 hover:bg-slate-700 rounded-xl"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg></a><h2 class="font-bold text-lg truncate flex-1"><?= htmlspecialchars($currentSession['title']) ?></h2><button onclick="copyLink()" class="p-2 bg-primary/10 text-primary hover:bg-primary/20 rounded-xl transition-all"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" /></svg></button></div>
                <?php if ($currentSession['method'] === 'brainstorm'): ?>
                    <section class="bg-slate-800/30 p-6 rounded-2xl border border-slate-700/50 space-y-4"><h3 class="text-xs font-black uppercase text-slate-500">Admin Ansicht</h3><div class="space-y-4"><?php if(empty($currentSession['ideas'])): ?><p class="text-slate-500 text-sm italic">Keine Ideen.</p><?php else: foreach($currentSession['ideas'] as $idea): ?><div class="bg-slate-900/50 p-4 rounded-xl border border-slate-700/50"><p class="text-slate-200"><?= nl2br(htmlspecialchars($idea['text'])) ?></p><div class="text-[9px] text-slate-600 mt-2 font-mono"><?= date('d.m.Y H:i:s', $idea['time']) ?> | IP: <?= $idea['ip'] ?> | Loc: <?= $idea['geo']['city'] ?></div></div><?php endforeach; endif; ?></div></section>
                <?php elseif ($currentSession['method'] === 'poll'): ?>
                    <section class="bg-slate-800/30 p-6 rounded-2xl border border-slate-700/50 space-y-4">
                        <h3 class="text-xs font-black uppercase text-slate-500">Auswertung</h3>
                        <div class="space-y-4">
                            <?php 
                            $max = 1;
                            if (!empty($currentSession['options'])) {
                                $votes = array_column($currentSession['options'], 'votes');
                                $max = !empty($votes) ? max($votes) : 1;
                            }
                            if ($max <= 0) $max = 1;
                            foreach ($currentSession['options'] as $opt): $p = round(($opt['votes']??0)/$max*100); 
                            ?>
                                <div class="space-y-1">
                                    <div class="flex justify-between text-sm">
                                        <span class="font-bold"><?= htmlspecialchars($opt['text']) ?></span>
                                        <span class="text-primary font-bold"><?= $opt['votes']??0 ?> (<?= count($currentSession['votes']) > 0 ? round(($opt['votes']??0)/count($currentSession['votes'])*100) : 0 ?>%)</span>
                                    </div>
                                    <div class="w-full bg-slate-900 rounded-full h-2 overflow-hidden border border-slate-700">
                                        <div class="bg-primary h-full transition-all" style="width:<?= $p ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-xs text-slate-500 italic mt-4">Teilnehmer: <?= count($currentSession['votes']) ?></p>
                    </section>
                <?php endif; ?>
                <?php if (!empty($currentSession['options']) && !in_array($currentSession['method'], ['poll', 'brainstorm'])): ?>
                    <?php if (isset($selectionResult)): ?><div id="result-box" class="bg-primary/20 border-2 border-primary p-8 rounded-[2rem] text-center animate-bounce shadow-2xl relative overflow-hidden"><p class="text-[10px] uppercase font-black tracking-widest text-primary mb-2">Die Wahl ist gefallen</p><h3 class="text-4xl font-black text-white"><?= htmlspecialchars($selectionResult) ?></h3></div><?php endif; ?>
                    <form method="POST"><input type="hidden" name="action" value="select"><button type="submit" class="w-full bg-white text-slate-900 font-black py-5 rounded-2xl text-xl shadow-xl hover:scale-[1.02] active:scale-95 transition-all">JETZT WÄHLEN</button></form>
                <?php endif; ?>
                <?php if ($currentSession['method'] !== 'brainstorm'): ?>
                    <section class="space-y-4">
                        <div class="flex items-center justify-between"><h3 class="text-xs font-black uppercase text-slate-500">Optionen (<?= count($currentSession['options']) ?>)</h3><span class="text-[10px] bg-slate-800 text-slate-400 px-2 py-1 rounded-full border border-slate-700">Methode: <?= $currentSession['method'] ?></span></div>
                        <div class="space-y-3"><?php foreach ($currentSession['options'] as $idx => $opt): ?>
                            <div class="bg-slate-800/40 rounded-2xl border border-slate-700/50 overflow-hidden group">
                                <div id="opt-view-<?= $idx ?>" class="flex items-center p-4 gap-4"><div class="flex-1 truncate"><span class="block font-bold text-slate-100 truncate"><?= htmlspecialchars($opt['text']) ?></span><div class="flex gap-2 mt-1"><?php if($currentSession['method']==='poll'): ?><span class="text-[10px] text-primary"><?= $opt['votes']??0 ?> Stimmen</span><?php else: ?><span class="text-[10px] text-slate-500"><?= $opt['hits'] ?>x gewählt</span><?php endif; ?><?php if($currentSession['method']==='weighted'): ?><span class="text-[10px] text-primary">Gewicht: <?= $opt['weight'] ?></span><?php endif; ?></div></div><div class="flex gap-1"><button onclick="toggleEdit(<?= $idx ?>)" class="p-2 text-slate-500 hover:text-primary"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg></button><a href="?id=<?= $sessionId ?>&remove=<?= $idx ?>&admin=1" class="p-2 text-slate-500 hover:text-red-400" onclick="return confirm('Löschen?')"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></a></div></div>
                                <div id="opt-edit-<?= $idx ?>" class="hidden p-4 bg-slate-900/50 border-t border-slate-700/30"><form method="POST" class="space-y-3"><input type="hidden" name="action" value="update_option"><input type="hidden" name="option_idx" value="<?= $idx ?>"><div><input type="text" name="option_text" value="<?= htmlspecialchars($opt['text']) ?>" required class="w-full bg-slate-800 border border-slate-700 rounded-xl p-2 text-sm text-white"></div><?php if($currentSession['method']==='weighted'): ?><input type="number" name="weight" value="<?= $opt['weight'] ?>" min="1" max="100" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-2 text-sm text-white"><?php endif; ?><div class="flex gap-2"><button type="submit" class="flex-1 bg-primary py-2 rounded-xl text-sm font-bold text-white">Speichern</button><button type="button" onclick="toggleEdit(<?= $idx ?>)" class="flex-1 bg-slate-700 py-2 rounded-xl text-sm font-bold text-white">Abbrechen</button></div></form></div>
                            </div>
                        <?php endforeach; ?></div>
                        <form method="POST" class="bg-slate-900/50 p-4 rounded-2xl border border-slate-700/50 space-y-3 mt-4"><input type="hidden" name="action" value="add_option"><h4 class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Option hinzufügen</h4><div class="flex gap-2"><input type="text" name="option_text" required placeholder="Name..." class="flex-1 bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 text-white"><?php if($currentSession['method']==='weighted'): ?><input type="number" name="weight" value="1" min="1" max="100" class="w-20 bg-slate-800 border border-slate-700 rounded-xl px-2 py-3 text-center text-white font-bold"><?php endif; ?></div><button type="submit" class="w-full bg-slate-700 hover:bg-slate-600 text-white font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>Option hinzufügen</button></form>
                    </section>
                <?php endif; ?>
                <div class="mt-8 pt-4 border-t border-slate-700/50 text-center"><a href="?id=<?= $sessionId ?>" class="text-[10px] text-slate-500 hover:text-primary uppercase font-bold tracking-widest">Zur Teilnehmer-Ansicht</a></div>
            </main>
        <?php endif; ?>

        <footer class="text-center mt-12 pb-8 space-y-4">
            <div class="flex justify-center gap-4 text-xs">
                <a href="index.php" class="text-slate-500 hover:text-primary transition-colors">Startseite</a>
                <?php foreach(explode(',', $config['footer_links']) as $link): if(strpos($link, ':')!==false): list($name, $url) = explode(':', $link, 2); ?>
                    <span class="text-slate-700">&bull;</span><a href="<?= htmlspecialchars($url) ?>" target="_blank" class="text-slate-500 hover:text-primary transition-colors"><?= htmlspecialchars($name) ?></a>
                <?php endif; endforeach; ?>
            </div>
            <p class="text-[10px] text-slate-600 uppercase tracking-widest font-bold">&copy; <?= date('Y') ?> <?= htmlspecialchars($config['app_name']) ?></p>
        </footer>
    </div>
    <script>
        function copyLink() { const url = new URL(window.location.href); url.searchParams.delete('admin'); navigator.clipboard.writeText(url.toString()); const btn = event.currentTarget; const orig = btn.innerHTML; btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>'; setTimeout(() => btn.innerHTML = orig, 2000); }
        function toggleEdit(idx) { const v = document.getElementById('opt-view-' + idx), e = document.getElementById('opt-edit-' + idx); if (v && e) { v.classList.toggle('hidden'); e.classList.toggle('hidden'); } }
        function togglePollSettings(val) { document.getElementById('poll-settings').classList.toggle('hidden', val !== 'poll'); }
        const resBox = document.getElementById('result-box'); if (resBox) { setTimeout(() => { resBox.classList.remove('animate-bounce'); }, 3000); }
    </script>
</body>
</html>