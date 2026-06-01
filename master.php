<?php
session_start();

/**
 * Robust Simple YAML Parser / Dumper for s3l3ct0r
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

    public static function save($file, $data) {
        $content = "# s3l3ct0r Configuration\n";
        foreach ($data as $key => $val) {
            if (is_bool($val)) {
                $v = $val ? 'true' : 'false';
            } elseif (is_string($val)) {
                $v = '"' . str_replace(["\\", "\"", "\n", "\r", "\t"], ["\\\\", "\\\"", "\\n", "\\r", "\\t"], $val) . '"';
            } elseif (is_null($val)) {
                $v = 'null';
            } else {
                $v = $val;
            }
            $content .= "{$key}: {$v}\n";
        }
        file_put_contents($file, $content);
    }
}

/**
 * Utility: Generate a unique ID
 */
function generateId($length = 16) {
    return bin2hex(random_bytes($length / 2));
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
    $cacheDir = __DIR__ . '/data/cache/';
    if (!file_exists($cacheDir)) mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . md5($ip) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) return json_decode(file_get_contents($cacheFile), true);
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
 * Security: Log failed master login attempt
 */
function logSecurityEvent($ip, $geo, $passwordAttempt) {
    $logFile = __DIR__ . '/data/security.log';
    $timestamp = date('Y-m-d H:i:s');
    $location = "{$geo['city']}, {$geo['country']}";
    $entry = "[{$timestamp}] FAILED LOGIN | IP: {$ip} | Loc: {$location} | PWD: {$passwordAttempt}\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}

$configPath = __DIR__ . '/config.yaml';
$config = SimpleYaml::load($configPath);

// IP Whitelist Check
$clientIp = getIp($config);
if (!empty($config['master_allowed_ips'])) {
    $allowed = array_map('trim', explode(',', $config['master_allowed_ips']));
    if (!in_array($clientIp, $allowed)) {
        http_response_code(403);
        die("Zugriff verweigert (IP nicht auf Whitelist).");
    }
}

// Timezone from Client (Cookie)
if (isset($_COOKIE['client_timezone'])) {
    @date_default_timezone_set($_COOKIE['client_timezone']);
}

$masterPw = isset($config['master_password']) ? trim((string)$config['master_password']) : '';
$failureFile = __DIR__ . '/data/.failed_master_login';

if ($masterPw === '' && !isset($_POST['set_master_pw'])) {
    $view = 'setup';
} elseif ($masterPw === '' && isset($_POST['set_master_pw'])) {
    $config['master_password'] = trim($_POST['new_pw']);
    SimpleYaml::save($configPath, $config);
    if (file_exists($failureFile)) unlink($failureFile);
    $_SESSION['master_auth'] = true;
    header("Location: master.php");
    exit;
} else {
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        if ($_POST['password'] === $masterPw) {
            $_SESSION['master_auth'] = true;
            if (file_exists($failureFile)) unlink($failureFile);
        } else {
            $geo = getGeoInfo($clientIp, $config);
            logSecurityEvent($clientIp, $geo, $_POST['password']);
            $fails = file_exists($failureFile) ? (int)file_get_contents($failureFile) : 0;
            $fails++;
            if ($fails >= 3) {
                $newPw = bin2hex(random_bytes(6));
                $config['master_password'] = $newPw;
                SimpleYaml::save($configPath, $config);
                if (file_exists($failureFile)) unlink($failureFile);
                $error = "Sicherheits-Reset: 3 Fehlversuche. Ein neues Kennwort wurde generiert.";
            } else {
                file_put_contents($failureFile, $fails);
                $error = "Falsches Master-Kennwort. Versuch {$fails} von 3.";
            }
        }
    }
    $view = !isset($_SESSION['master_auth']) ? 'login' : 'dashboard';
}

if (isset($_GET['logout'])) { unset($_SESSION['master_auth']); header("Location: master.php"); exit; }

// --- ACTIONS ---
if (isset($_SESSION['master_auth'])) {
    // Auto Cleanup
    if ($config['auto_cleanup_days'] > 0) {
        $threshold = time() - ($config['auto_cleanup_days'] * 86400);
        foreach (glob(__DIR__ . '/data/*.json') as $file) { if (filemtime($file) < $threshold) unlink($file); }
    }

    if (isset($_GET['copy'])) {
        $sourceFile = __DIR__ . '/data/' . basename($_GET['copy']) . '.json';
        if (file_exists($sourceFile)) {
            $data = json_decode(file_get_contents($sourceFile), true);
            $newId = generateId();
            $data['id'] = $newId; $data['title'] .= ' (Kopie)';
            $data['created_at'] = time(); $data['last_activity'] = time();
            if (isset($data['options'])) { foreach ($data['options'] as &$opt) { $opt['hits'] = 0; $opt['votes'] = 0; } }
            $data['votes'] = []; $data['ideas'] = [];
            file_put_contents(__DIR__ . '/data/' . $newId . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        header("Location: master.php?msg=copied"); exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_raw') {
        $id = basename($_POST['session_id']); $file = __DIR__ . '/data/' . $id . '.json';
        if (file_exists($file)) {
            $data = json_decode($_POST['raw_json'], true);
            if ($data) { $data['id'] = $id; file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); header("Location: master.php?msg=saved"); exit; }
            else { $error = "Ungültiges JSON."; }
        }
    }

    if (isset($_GET['delete'])) { unlink(__DIR__ . '/data/' . basename($_GET['delete']) . '.json'); header("Location: master.php"); exit; }

    if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {
        $days = (int)$_POST['days']; $threshold = time() - ($days * 86400);
        foreach (glob(__DIR__ . '/data/*.json') as $file) { if (filemtime($file) < $threshold) unlink($file); }
        header("Location: master.php?msg=deleted"); exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_config') {
        foreach(['app_name','background_style','logo_svg','primary_color','secondary_color','font_family','footer_links','password_algo','master_password','master_allowed_ips','honeypot_field','default_method','custom_css'] as $f) $config[$f] = $_POST[$f];
        $config['use_x_real_ip'] = isset($_POST['use_x_real_ip']);
        $config['enable_geoip'] = isset($_POST['enable_geoip']);
        $config['debug_mode'] = isset($_POST['debug_mode']);
        $config['session_creation_rate_limit'] = (int)$_POST['session_creation_rate_limit'];
        $config['auto_cleanup_days'] = (int)$_POST['auto_cleanup_days'];
        $config['max_ideas_per_session'] = (int)$_POST['max_ideas_per_session'];
        SimpleYaml::save($configPath, $config);
        header("Location: master.php?msg=updated"); exit;
    }

    if (isset($_GET['login_to'])) { $_SESSION['auth_' . $_GET['login_to']] = true; header("Location: index.php?id=" . $_GET['login_to'] . "&admin=1"); exit; }
}

if (isset($_SESSION['master_auth']) && isset($_GET['edit_raw'])) {
    $view = 'edit_raw'; $editId = basename($_GET['edit_raw']);
    $editFile = __DIR__ . '/data/' . $editId . '.json'; $rawJson = file_exists($editFile) ? file_get_contents($editFile) : '';
}

$sessions = [];
foreach (glob(__DIR__ . '/data/*.json') as $file) {
    $data = json_decode(file_get_contents($file), true);
    if ($data && isset($data['id'])) {
        $la = $data['last_activity'] ?? filemtime($file);
        if (isset($data['votes']) && !empty($data['votes'])) { $lv = end($data['votes'])['time']; if ($lv > $la) $la = $lv; }
        if (isset($data['ideas']) && !empty($data['ideas'])) { $li = end($data['ideas'])['time']; if ($li > $la) $la = $li; }
        $sessions[] = ['id' => $data['id'], 'title' => $data['title'] ?? 'Kein Titel', 'method' => $data['method'] ?? '?', 'created' => $data['created_at'] ?? filemtime($file), 'last_activity' => $la];
    }
}
usort($sessions, function($a, $b) { return $b['last_activity'] <=> $a['last_activity']; });

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Master Dashboard | <?= htmlspecialchars($config['app_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: Arial, sans-serif; }
        .bg-gradient { background: <?= $config['background_style'] ?>; }
        :root { --primary: <?= $config['primary_color'] ?>; --secondary: <?= $config['secondary_color'] ?>; }
        .bg-btn-gradient { background: linear-gradient(to right, var(--primary), var(--secondary)); }
        <?= $config['custom_css'] ?>
    </style>
    <script>
        if (!document.cookie.includes('client_timezone=')) {
            document.cookie = "client_timezone=" + Intl.DateTimeFormat().resolvedOptions().timeZone + "; path=/; max-age=31536000";
            location.reload();
        }
    </script>
</head>
<body class="bg-gradient min-h-screen text-slate-200 p-4 md:p-8">
    <div class="max-w-6xl mx-auto">
        <header class="flex justify-between items-center mb-8 pb-4 border-b border-slate-800 backdrop-blur-md bg-slate-900/50 p-4 rounded-2xl border border-slate-700">
            <h1 class="text-3xl font-bold" style="color: var(--primary);">Master Dashboard</h1>
            <?php if (isset($_SESSION['master_auth'])): ?>
                <div class="flex items-center gap-4"><a href="master.php" class="text-sm text-slate-400 hover:text-white">Übersicht</a><a href="?logout=1" class="text-sm bg-red-900/30 text-red-400 px-4 py-2 rounded-lg border border-red-500/30 hover:bg-red-900/50">Logout</a></div>
            <?php endif; ?>
        </header>

        <?php if ($view === 'setup'): ?>
            <div class="max-w-md mx-auto bg-slate-800/80 backdrop-blur-md p-8 rounded-2xl border border-slate-700 shadow-2xl">
                <h2 class="text-xl font-bold mb-4">Setup</h2>
                <form method="POST"><input type="password" name="new_pw" required placeholder="Master-Kennwort" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-xl mb-4 text-white"><button type="submit" name="set_master_pw" class="w-full bg-btn-gradient text-white font-bold py-3 rounded-xl">Setzen & Login</button></form>
            </div>
        <?php elseif ($view === 'login'): ?>
            <div class="max-w-md mx-auto bg-slate-800/80 backdrop-blur-md p-8 rounded-2xl border border-slate-700 shadow-2xl">
                <h2 class="text-xl font-bold mb-6 text-center">Master Login</h2>
                <form method="POST"><input type="hidden" name="action" value="login"><input type="password" name="password" autofocus placeholder="Kennwort" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-xl mb-4 text-white text-center text-2xl tracking-widest outline-none focus:ring-2 focus:ring-cyan-500"><button type="submit" class="w-full bg-btn-gradient text-white font-bold py-3 rounded-xl shadow-lg shadow-cyan-900/20">Einloggen</button></form>
                <?php if (isset($error)): ?><p class="text-red-500 text-sm mt-4 text-center"><?= $error ?></p><?php endif; ?>
            </div>
        <?php elseif ($view === 'edit_raw'): ?>
            <div class="bg-slate-800/80 backdrop-blur-md p-6 rounded-2xl border border-slate-700 shadow-2xl">
                <div class="flex justify-between items-center mb-6"><h2 class="text-xl font-bold">Roh-Editor: <?= htmlspecialchars($editId) ?></h2><a href="master.php" class="text-xs bg-slate-700 px-3 py-1 rounded">Abbrechen</a></div>
                <form method="POST"><input type="hidden" name="action" value="save_raw"><input type="hidden" name="session_id" value="<?= htmlspecialchars($editId) ?>"><textarea name="raw_json" class="w-full h-[500px] bg-slate-900 border border-slate-700 p-4 rounded-xl font-mono text-xs text-cyan-400 focus:ring-2 focus:ring-cyan-500 outline-none mb-4"><?= htmlspecialchars($rawJson) ?></textarea><button type="submit" class="w-full bg-btn-gradient text-white font-bold py-3 rounded-xl">JSON Speichern</button></form>
            </div>
        <?php elseif ($view === 'dashboard'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-slate-800/80 backdrop-blur-md rounded-2xl border border-slate-700 overflow-hidden shadow-xl">
                        <div class="p-4 bg-slate-700/30 border-b border-slate-700 flex justify-between items-center"><h3 class="font-bold">Sessions (<?= count($sessions) ?>)</h3></div>
                        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-xs uppercase text-slate-500 bg-slate-900/50"><tr><th class="p-4">Titel / ID</th><th class="p-4">Methode</th><th class="p-4">Letzte Aktivität</th><th class="p-4 text-right">Aktionen</th></tr></thead>
                        <tbody class="divide-y divide-slate-700"><?php foreach ($sessions as $s): ?>
                            <tr class="hover:bg-slate-700/30">
                                <td class="p-4"><div class="font-bold text-slate-100"><?= htmlspecialchars($s['title']) ?></div><div class="text-[10px] text-slate-500 font-mono"><?= $s['id'] ?></div></td>
                                <td class="p-4"><span class="px-2 py-0.5 bg-slate-900 rounded border border-slate-700 text-[10px] uppercase font-bold text-slate-400"><?= $s['method'] ?></span></td>
                                <td class="p-4"><div class="text-slate-100 font-medium"><?= date('d.m.Y H:i', $s['last_activity']) ?></div><div class="text-[10px] text-slate-500 uppercase font-bold">Erstellt: <?= date('d.m.y', $s['created']) ?></div></td>
                                <td class="p-4 text-right space-x-1"><div class="flex flex-wrap justify-end gap-1"><a href="?login_to=<?= $s['id'] ?>" class="text-[10px] bg-cyan-900/30 text-cyan-400 border border-cyan-500/30 px-2 py-1 rounded">Login</a><a href="?copy=<?= $s['id'] ?>" class="text-[10px] bg-slate-700/50 text-slate-300 px-2 py-1 rounded">Kopie</a><a href="?edit_raw=<?= $s['id'] ?>" class="text-[10px] bg-slate-700/50 text-slate-300 px-2 py-1 rounded">Roh</a><a href="?delete=<?= $s['id'] ?>" onclick="return confirm('Löschen?')" class="text-[10px] bg-red-900/30 text-red-400 px-2 py-1 rounded">Del</a></div></td>
                            </tr><?php endforeach; ?></tbody></table></div>
                    </div>
                    <div class="bg-slate-800/80 backdrop-blur-md p-6 rounded-2xl border border-slate-700 shadow-xl"><h3 class="font-bold mb-4 text-red-400">Aufräum-Werkzeuge</h3><form method="POST" class="flex flex-wrap items-center gap-4"><input type="hidden" name="action" value="bulk_delete"><span class="text-sm">Lösche Sessions älter als</span><input type="number" name="days" value="30" class="w-20 bg-slate-900 border border-slate-700 p-2 rounded text-center text-white text-sm"><span class="text-sm">Tage.</span><button type="submit" class="bg-red-900/30 text-red-400 px-4 py-2 rounded-lg border border-red-500/30">Ausführen</button></form></div>
                </div>
                <div class="space-y-6"><div class="bg-slate-800/80 backdrop-blur-md p-6 rounded-2xl border border-slate-700 shadow-xl"><h3 class="font-bold mb-6 flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-cyan-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /></svg>Config</h3>
                <form method="POST" class="space-y-4"><input type="hidden" name="action" value="update_config">
                <?php foreach(['app_name'=>'Name','background_style'=>'BG CSS','font_family'=>'Font','footer_links'=>'Links','master_password'=>'Master PW','master_allowed_ips'=>'IP Whitelist (CSV)','honeypot_field'=>'Honeypot Field'] as $k=>$l): ?>
                    <div><label class="block text-[10px] uppercase text-slate-500 mb-1 ml-1"><?= $l ?></label><input type="text" name="<?= $k ?>" value="<?= htmlspecialchars($config[$k] ?? '') ?>" class="w-full bg-slate-900 border border-slate-700 p-2 rounded text-sm text-white outline-none focus:ring-1 focus:ring-cyan-500"></div>
                <?php endforeach; ?>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-[10px] uppercase text-slate-500 mb-1 ml-1">Primary Color</label>
                        <div class="flex gap-1"><input type="color" value="<?= htmlspecialchars($config['primary_color'] ?? '#06b6d4') ?>" oninput="document.getElementById('p_hex').value=this.value" class="w-8 h-8 bg-slate-900 border border-slate-700 rounded p-1 cursor-pointer"><input type="text" name="primary_color" id="p_hex" value="<?= htmlspecialchars($config['primary_color'] ?? '#06b6d4') ?>" class="flex-1 bg-slate-900 border border-slate-700 p-1 rounded text-[10px] text-white"></div>
                    </div>
                    <div><label class="block text-[10px] uppercase text-slate-500 mb-1 ml-1">Secondary Color</label>
                        <div class="flex gap-1"><input type="color" value="<?= htmlspecialchars($config['secondary_color'] ?? '#3b82f6') ?>" oninput="document.getElementById('s_hex').value=this.value" class="w-8 h-8 bg-slate-900 border border-slate-700 rounded p-1 cursor-pointer"><input type="text" name="secondary_color" id="s_hex" value="<?= htmlspecialchars($config['secondary_color'] ?? '#3b82f6') ?>" class="flex-1 bg-slate-900 border border-slate-700 p-1 rounded text-[10px] text-white"></div>
                    </div>
                </div>
                <div><label class="block text-[10px] uppercase text-slate-500 mb-1 ml-1">Logo SVG</label><textarea name="logo_svg" class="w-full bg-slate-900 border border-slate-700 p-2 rounded text-[10px] font-mono h-24 text-white outline-none focus:ring-1 focus:ring-cyan-500"><?= htmlspecialchars($config['logo_svg'] ?? '') ?></textarea></div>
                <div><label class="block text-[10px] uppercase text-slate-500 mb-1 ml-1">Custom CSS</label><textarea name="custom_css" class="w-full bg-slate-900 border border-slate-700 p-2 rounded text-[10px] font-mono h-24 text-white outline-none focus:ring-1 focus:ring-cyan-500"><?= htmlspecialchars($config['custom_css'] ?? '') ?></textarea></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-[10px] uppercase text-slate-500 mb-1">Method</label><select name="default_method" class="w-full bg-slate-900 border border-slate-700 p-2 rounded text-xs text-white"><?php foreach(['random','even','weighted','poll','brainstorm'] as $m): ?><option value="<?= $m ?>" <?= ($config['default_method']??'')===$m?'selected':'' ?>><?= ucfirst($m) ?></option><?php endforeach; ?></select></div>
                    <div><label class="block text-[10px] uppercase text-slate-500 mb-1">PW Algo</label><select name="password_algo" class="w-full bg-slate-900 border border-slate-700 p-2 rounded text-xs text-white"><option value="bcrypt" <?= ($config['password_algo']??'')==='bcrypt'?'selected':'' ?>>bcrypt</option><option value="plaintext" <?= ($config['password_algo']??'')==='plaintext'?'selected':'' ?>>plaintext</option></select></div>
                </div>
                <div class="space-y-1">
                    <label class="flex items-center gap-2 text-[10px] uppercase text-slate-400"><input type="checkbox" name="use_x_real_ip" <?= ($config['use_x_real_ip']??false)?'checked':'' ?>> Use X-Real-IP</label>
                    <label class="flex items-center gap-2 text-[10px] uppercase text-slate-400"><input type="checkbox" name="enable_geoip" <?= ($config['enable_geoip']??true)?'checked':'' ?>> Enable GeoIP</label>
                    <label class="flex items-center gap-2 text-[10px] uppercase text-slate-400"><input type="checkbox" name="debug_mode" <?= ($config['debug_mode']??false)?'checked':'' ?>> Debug Mode</label>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div><label class="block text-[8px] uppercase text-slate-500">Rate Lim</label><input type="number" name="session_creation_rate_limit" value="<?= (int)($config['session_creation_rate_limit']??0) ?>" class="w-full bg-slate-900 border border-slate-700 p-1 rounded text-xs text-white"></div>
                    <div><label class="block text-[8px] uppercase text-slate-500">Auto Clean</label><input type="number" name="auto_cleanup_days" value="<?= (int)($config['auto_cleanup_days']??0) ?>" class="w-full bg-slate-900 border border-slate-700 p-1 rounded text-xs text-white"></div>
                    <div><label class="block text-[8px] uppercase text-slate-500">Max Ideas</label><input type="number" name="max_ideas_per_session" value="<?= (int)($config['max_ideas_per_session']??0) ?>" class="w-full bg-slate-900 border border-slate-700 p-1 rounded text-xs text-white"></div>
                </div>
                <button type="submit" class="w-full bg-btn-gradient text-white font-bold py-3 rounded-xl mt-4 transition-all">Speichern</button>
                </form></div></div>
            </div>
        <?php endif; ?>
        <footer class="mt-12 text-center text-slate-600 text-[10px] uppercase font-bold tracking-widest">&copy; <?= date('Y') ?> s3l3ct0r</footer>
    </div>
</body>
</html>