<?php
session_start();

// Force UTF-8
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

define('DATA_DIR', __DIR__ . '/data/');
define('CACHE_DIR', DATA_DIR . 'cache/');

if (!file_exists(CACHE_DIR)) mkdir(CACHE_DIR, 0775, true);

/**
 * Utility: Fetch GeoIP info with cache
 */
function getGeoInfo($ip) {
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
 * Utility: Generate a unique ID
 */
function generateId($length = 16) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Utility: Load session from JSON file
 */
function loadSession($id) {
    $path = DATA_DIR . $id . '.json';
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true);
}

/**
 * Utility: Save session to JSON file
 */
function saveSession($data) {
    $path = DATA_DIR . $data['id'] . '.json';
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// --- ROUTING & ACTIONS ---

$error = '';
$currentSession = null;
$sessionId = $_GET['id'] ?? '';
$isAuthenticated = false;

if ($sessionId) {
    $currentSession = loadSession($sessionId);
    if (!$currentSession) {
        $error = "Session nicht gefunden.";
    } else {
        if (isset($_SESSION['auth_' . $sessionId])) {
            $isAuthenticated = true;
        } elseif (empty($currentSession['password_hash'])) {
            if ($currentSession['method'] !== 'poll' || isset($_GET['admin'])) {
                $isAuthenticated = true;
                $_SESSION['auth_' . $sessionId] = true;
            }
        }

        if (!$isAuthenticated && isset($_POST['action']) && $_POST['action'] === 'login') {
            if (password_verify($_POST['password'], $currentSession['password_hash'])) {
                $_SESSION['auth_' . $sessionId] = true;
                $isAuthenticated = true;
            } else {
                $error = "Falsches Passwort.";
            }
        }
    }
}

// Create Session
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $id = generateId();
    $method = $_POST['method'] ?: 'random';
    if (!in_array($method, ['random', 'even', 'weighted', 'poll', 'brainstorm'])) $method = 'random';
    
    $newSession = [
        'id' => $id,
        'title' => htmlspecialchars($_POST['title'] ?: 'Neue Auswahl'),
        'method' => $method,
        'password_hash' => $_POST['password'] ? password_hash($_POST['password'], PASSWORD_DEFAULT) : '',
        'options' => [],
        'ideas' => [],
        'created_at' => time(),
        'settings' => [
            'poll_allow_multiple' => isset($_POST['poll_allow_multiple']) ? (bool)$_POST['poll_allow_multiple'] : false
        ],
        'votes' => []
    ];
    saveSession($newSession);
    $_SESSION['auth_' . $id] = true;
    header("Location: ?id=" . $id . "&admin=1");
    exit;
}

// Add/Vote
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
        $ip = $_SERVER['REMOTE_ADDR'];
        
        if (empty($voterName) || empty($selected)) {
            $error = "Name und Wahl sind erforderlich.";
        } else {
            $geo = getGeoInfo($ip);
            $voteData = ['name' => $voterName, 'options' => array_map('intval', $selected), 'ip' => $ip, 'geo' => $geo, 'time' => time()];
            $currentSession['votes'][] = $voteData;
            foreach ($voteData['options'] as $idx) if (isset($currentSession['options'][$idx])) $currentSession['options'][$idx]['votes']++;
            saveSession($currentSession);
            $_SESSION['voted_' . $sessionId] = true;
            header("Location: ?id=" . $sessionId . "&voted=1");
            exit;
        }
    }

    if ($_POST['action'] === 'submit_idea' && $sessionId && $currentSession['method'] === 'brainstorm' && !empty($_POST['idea_text'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $geo = getGeoInfo($ip);
        $currentSession['ideas'][] = ['text' => htmlspecialchars(trim($_POST['idea_text'])), 'time' => time(), 'ip' => $ip, 'geo' => $geo];
        saveSession($currentSession);
        header("Location: ?id=" . $sessionId . "&submitted=1");
        exit;
    }
}

// Dashboard View
$viewMode = 'dashboard';
if ($sessionId && $currentSession) {
    if (isset($_GET['admin'])) $viewMode = $isAuthenticated ? 'dashboard' : 'login';
    elseif ($currentSession['method'] === 'poll') $viewMode = 'poll_vote';
    elseif ($currentSession['method'] === 'brainstorm') $viewMode = 'brainstorm';
    else $viewMode = 'login'; // Default to login if session exists but not poll/brainstorm
} else { $viewMode = 'landing'; }

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>s3l3ct0r</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 p-4">
    <div class="max-w-2xl mx-auto space-y-6">
        <?php if ($viewMode === 'poll_vote'): ?>
            <div class="bg-slate-800 p-6 rounded-xl">
                <h2 class="text-2xl font-bold"><?= htmlspecialchars($currentSession['title']) ?></h2>
                <?php if (isset($_GET['voted'])): ?>
                    <p class="text-green-400">Danke für deine Stimme!</p>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="vote">
                        <input type="text" name="voter_name" required placeholder="Dein Name" class="w-full bg-slate-900 border border-slate-700 p-2 mb-4">
                        <?php foreach($currentSession['options'] as $idx => $opt): ?>
                            <label class="block"><input type="<?= $currentSession['settings']['poll_allow_multiple']?'checkbox':'radio' ?>" name="vote_options[]" value="<?= $idx ?>"> <?= htmlspecialchars($opt['text']) ?></label>
                        <?php endforeach; ?>
                        <button type="submit" class="bg-cyan-600 text-white p-2 mt-4">Abstimmen</button>
                    </form>
                <?php endif; ?>
                <a href="?id=<?= $sessionId ?>&admin=1" class="text-xs text-slate-500 underline mt-4 block">Admin-Login</a>
            </div>

        <?php elseif ($viewMode === 'brainstorm'): ?>
            <div class="bg-slate-800 p-6 rounded-xl">
                <h2 class="text-2xl font-bold"><?= htmlspecialchars($currentSession['title']) ?></h2>
                <p class="text-slate-400 mb-6">Teile deine Ideen für dieses Event!</p>
                
                <form method="POST" class="mb-8">
                    <input type="hidden" name="action" value="submit_idea">
                    <textarea name="idea_text" required placeholder="Deine Idee..." class="w-full bg-slate-900 border border-slate-700 p-3 rounded-lg mb-4 h-32 focus:ring-2 focus:ring-cyan-500 outline-none text-slate-100"></textarea>
                    <button type="submit" class="w-full bg-cyan-600 hover:bg-cyan-500 text-white font-bold py-3 rounded-lg transition-colors">Idee absenden</button>
                </form>

                <div class="space-y-4">
                    <h3 class="font-bold border-b border-slate-700 pb-2">Bisherige Vorschläge</h3>
                    <?php if (empty($currentSession['ideas'])): ?>
                        <p class="text-slate-500 italic text-sm">Noch keine Ideen eingereicht. Sei der Erste!</p>
                    <?php else: ?>
                        <?php foreach(array_reverse($currentSession['ideas']) as $idea): ?>
                            <div class="bg-slate-900 p-4 rounded-lg border border-slate-700/50">
                                <p class="text-slate-200"><?= nl2br(htmlspecialchars($idea['text'])) ?></p>
                                <div class="text-[10px] text-slate-500 mt-2 flex justify-between">
                                    <span><?= date('d.m.Y H:i', $idea['time']) ?></span>
                                    <span><?= $idea['geo']['city'] ?? 'Unbekannt' ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <a href="?id=<?= $sessionId ?>&admin=1" class="text-xs text-slate-500 underline mt-8 block text-center">Admin-Login</a>
            </div>

        <?php elseif ($viewMode === 'dashboard'): ?>
            <!-- Admin Dashboard -->
            <div class="bg-slate-800 p-6 rounded-xl">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold">Verwaltung: <?= htmlspecialchars($currentSession['title']) ?></h2>
                    <a href="index.php" class="text-xs bg-slate-700 hover:bg-slate-600 px-3 py-1 rounded">Zurück</a>
                </div>

                <?php if($currentSession['method'] === 'poll'): ?>
                    <div class="space-y-4">
                        <h3 class="font-bold border-b border-slate-700 pb-2">Stimmenübersicht</h3>
                        <?php foreach($currentSession['votes'] as $vote): ?>
                            <div class="bg-slate-900 p-3 rounded text-sm">
                                <strong><?= htmlspecialchars($vote['name']) ?></strong> 
                                <span class="text-slate-400">(<?= $vote['geo']['city'] ?>, <?= $vote['geo']['country'] ?>)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif($currentSession['method'] === 'brainstorm'): ?>
                    <div class="space-y-4">
                        <h3 class="font-bold border-b border-slate-700 pb-2">Eingereichte Ideen (Admin)</h3>
                        <?php if (empty($currentSession['ideas'])): ?>
                            <p class="text-slate-500 italic text-sm">Noch keine Ideen vorhanden.</p>
                        <?php else: ?>
                            <?php foreach($currentSession['ideas'] as $idea): ?>
                                <div class="bg-slate-900 p-3 rounded text-sm">
                                    <p class="text-slate-200"><?= nl2br(htmlspecialchars($idea['text'])) ?></p>
                                    <div class="text-slate-500 text-[10px] mt-1">
                                        <?= date('d.m.Y H:i', $idea['time']) ?> - IP: <?= $idea['ip'] ?> (<?= $idea['geo']['city'] ?>, <?= $idea['geo']['country'] ?>)
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="text-slate-400 italic">Für diese Methode gibt es noch keine spezielle Admin-Ansicht.</p>
                <?php endif; ?>

                <div class="mt-8 pt-4 border-t border-slate-700">
                    <p class="text-xs text-slate-500">Teilnahme-Link: <code class="bg-slate-900 p-1 rounded">?id=<?= $sessionId ?></code></p>
                </div>
            </div>

        <?php elseif ($viewMode === 'login'): ?>
            <div class="bg-slate-800 p-6 rounded-xl max-w-sm mx-auto">
                <h2 class="text-xl font-bold mb-4">Admin Login</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <input type="password" name="password" required placeholder="Passwort" class="w-full bg-slate-900 border border-slate-700 p-2 mb-4 rounded">
                    <button type="submit" class="w-full bg-cyan-600 hover:bg-cyan-500 text-white font-bold py-2 rounded transition-colors">Einloggen</button>
                </form>
                <?php if ($error): ?><p class="text-red-500 text-sm mt-2"><?= $error ?></p><?php endif; ?>
            </div>

        <?php else: ?>
            <!-- Landing / Create -->
            <div class="bg-slate-800 p-6 rounded-xl">
                <h1 class="text-3xl font-bold mb-2 text-cyan-400">s3l3ct0r</h1>
                <p class="text-slate-400 mb-6 text-sm">Die smarte Art Entscheidungen zu treffen.</p>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Titel</label>
                        <input type="text" name="title" required placeholder="z.B. Team-Event" class="w-full bg-slate-900 border border-slate-700 p-2 rounded text-slate-100">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Methode</label>
                        <select name="method" class="w-full bg-slate-900 border border-slate-700 p-2 rounded text-slate-100">
                            <option value="random">Zufall</option>
                            <option value="even">Gleichmäßige Verteilung</option>
                            <option value="weighted">Gewichtung</option>
                            <option value="poll">Umfrage</option>
                            <option value="brainstorm">Brainstorm (Ideensammlung)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Passwort (optional)</label>
                        <input type="password" name="password" placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 p-2 rounded text-slate-100">
                    </div>
                    <button type="submit" class="w-full bg-cyan-600 hover:bg-cyan-500 text-white font-bold py-3 rounded-lg transition-all mt-4">Session erstellen</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
