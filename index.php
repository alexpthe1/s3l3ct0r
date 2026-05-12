<?php
session_start();

define('DATA_DIR', __DIR__ . '/data/');

/**
 * Utility: Generate a unique ID for the session
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
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

/**
 * Selection Logic
 */
function performSelection(&$session) {
    if (empty($session['options'])) return null;

    $index = 0;
    if ($session['method'] === 'random') {
        $index = array_rand($session['options']);
    } elseif ($session['method'] === 'weighted') {
        $weights = array_column($session['options'], 'weight');
        $totalWeight = array_sum($weights);
        $random = mt_rand(1, $totalWeight);
        $current = 0;
        foreach ($session['options'] as $idx => $opt) {
            $current += $opt['weight'];
            if ($random <= $current) {
                $index = $idx;
                break;
            }
        }
    } else {
        // Even Distribution
        $hits = array_column($session['options'], 'hits');
        $minHits = min($hits);
        $candidates = [];
        foreach ($session['options'] as $idx => $opt) {
            if ($opt['hits'] == $minHits) {
                $candidates[] = $idx;
            }
        }
        $index = $candidates[array_rand($candidates)];
    }

    $session['options'][$index]['hits']++;
    saveSession($session);
    return $session['options'][$index]['text'];
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
        // Check Auth
        if (empty($currentSession['password_hash'])) {
            $isAuthenticated = true;
        } elseif (isset($_SESSION['auth_' . $sessionId])) {
            $isAuthenticated = true;
        }

        // Handle Login
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
    if (!in_array($method, ['random', 'even', 'weighted'])) $method = 'random';
    
    $newSession = [
        'id' => $id,
        'title' => htmlspecialchars($_POST['title'] ?: 'Neue Auswahl'),
        'method' => $method,
        'password_hash' => $_POST['password'] ? password_hash($_POST['password'], PASSWORD_DEFAULT) : '',
        'options' => [],
        'created_at' => time()
    ];
    saveSession($newSession);
    header("Location: ?id=" . $id);
    exit;
}

// Add Option
if ($isAuthenticated && isset($_POST['action']) && $_POST['action'] === 'add_option' && !empty($_POST['option_text'])) {
    $weight = isset($_POST['weight']) ? max(1, (int)$_POST['weight']) : 1;
    $currentSession['options'][] = [
        'text' => htmlspecialchars($_POST['option_text']), 
        'hits' => 0,
        'weight' => $weight
    ];
    saveSession($currentSession);
    header("Location: ?id=" . $sessionId);
    exit;
}

// Remove Option
if ($isAuthenticated && isset($_GET['remove']) && isset($currentSession['options'][$_GET['remove']])) {
    array_splice($currentSession['options'], $_GET['remove'], 1);
    saveSession($currentSession);
    header("Location: ?id=" . $sessionId);
    exit;
}

// Update Option (Weight/Text)
if ($isAuthenticated && isset($_POST['action']) && $_POST['action'] === 'update_option') {
    $idx = (int)$_POST['option_idx'];
    if (isset($currentSession['options'][$idx])) {
        $currentSession['options'][$idx]['text'] = htmlspecialchars($_POST['option_text']);
        if ($currentSession['method'] === 'weighted') {
            $currentSession['options'][$idx]['weight'] = max(1, (int)$_POST['weight']);
        }
        saveSession($currentSession);
    }
    header("Location: ?id=" . $sessionId);
    exit;
}

// Perform Selection
$selectionResult = '';
if ($isAuthenticated && isset($_POST['action']) && $_POST['action'] === 'select') {
    $selectionResult = performSelection($currentSession);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>s3l3ct0r</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;700&display=swap');
        body { font-family: 'Space+Grotesk', sans-serif; }
        .bg-gradient { background: radial-gradient(circle at top right, #1a1a2e, #16213e, #0f3460); }
    </style>
</head>
<body class="bg-gradient min-h-screen text-slate-100 p-4">

    <div class="max-w-md mx-auto">
        <header class="text-center py-8">
            <a href="index.php" class="inline-block group">
                <div class="flex items-center justify-center mb-2">
                    <svg class="w-16 h-16 text-cyan-500 transform group-hover:rotate-12 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                        <circle cx="12" cy="12" r="3" fill="currentColor" class="animate-pulse" />
                    </svg>
                </div>
                <h1 class="text-5xl font-bold tracking-tighter text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-500">
                    s3l3ct0r
                </h1>
            </a>
            <p class="text-slate-400 mt-2">Die smarte Art zu wählen.</p>
        </header>

        <?php if ($error): ?>
            <div class="bg-red-900/50 border border-red-500 text-red-200 p-4 rounded-xl mb-6 text-sm">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <?php if (!$sessionId): ?>
            <!-- Landing / Create -->
            <section class="bg-slate-800/50 backdrop-blur-md p-6 rounded-2xl border border-slate-700 shadow-xl">
                <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-cyan-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Neue Session
                </h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1 ml-1">Titel</label>
                        <input type="text" name="title" required placeholder="z.B. Team-Event" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:outline-none focus:ring-2 focus:ring-cyan-500/50 transition-all">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1 ml-1">Methode</label>
                        <select name="method" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:outline-none focus:ring-2 focus:ring-cyan-500/50 appearance-none transition-all cursor-pointer">
                            <option value="random">Zufall</option>
                            <option value="even">Gleichmäßige Verteilung</option>
                            <option value="weighted">Gewichtungbasiert</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1 ml-1">Passwort (optional)</label>
                        <input type="password" name="password" placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:outline-none focus:ring-2 focus:ring-cyan-500/50 transition-all">
                    </div>
                    <button type="submit" class="w-full bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white font-bold py-4 rounded-xl transition-all transform active:scale-[0.98] shadow-lg shadow-cyan-900/20 mt-2">
                        Session starten
                    </button>
                </form>
            </section>

        <?php elseif ($sessionId && !$isAuthenticated): ?>
            <!-- Login -->
            <section class="bg-slate-800/50 backdrop-blur-md p-6 rounded-2xl border border-slate-700 shadow-xl">
                <div class="text-center mb-6">
                    <div class="bg-slate-700/50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <h2 class="text-xl font-bold">Geschützte Session</h2>
                    <p class="text-slate-400 text-sm mt-1">Bitte gib das Passwort ein.</p>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="login">
                    <input type="password" name="password" autofocus placeholder="Passwort" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 focus:outline-none focus:ring-2 focus:ring-cyan-500/50 text-center text-xl tracking-widest">
                    <button type="submit" class="w-full bg-gradient-to-r from-cyan-500 to-blue-600 text-white font-bold py-4 rounded-xl transition-all transform active:scale-[0.98]">
                        Freischalten
                    </button>
                    <a href="index.php" class="block text-center text-xs text-slate-500 hover:text-slate-300 transition-colors pt-2">Abbrechen</a>
                </form>
            </section>

        <?php elseif ($isAuthenticated): ?>
            <!-- Dashboard -->
            <main class="space-y-6">
                <div class="flex items-center gap-3 bg-slate-800/50 p-4 rounded-2xl border border-slate-700">
                    <a href="index.php" class="p-2 bg-slate-700/50 hover:bg-slate-700 rounded-xl transition-colors" title="Zur Startseite">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                    </a>
                    <h2 class="font-bold text-lg truncate flex-1"><?= htmlspecialchars($currentSession['title']) ?></h2>
                    <button onclick="copyLink()" class="p-2 bg-cyan-500/10 text-cyan-400 hover:bg-cyan-500/20 rounded-xl transition-colors" title="Link kopieren">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
                        </svg>
                    </button>
                </div>

                <!-- Result Display -->
                <?php if ($selectionResult): ?>
                    <div id="result-box" class="bg-gradient-to-br from-cyan-500/20 to-blue-600/20 border-2 border-cyan-500 p-8 rounded-[2rem] text-center animate-bounce shadow-2xl shadow-cyan-500/20 relative overflow-hidden">
                        <div class="absolute inset-0 bg-white/5 opacity-20 pointer-events-none"></div>
                        <p class="text-[10px] uppercase font-black tracking-[0.2em] text-cyan-400 mb-2">Die Wahl ist gefallen</p>
                        <h3 class="text-4xl font-black text-white"><?= htmlspecialchars($selectionResult) ?></h3>
                    </div>
                <?php endif; ?>

                <!-- Selection Button -->
                <?php if (!empty($currentSession['options'])): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="select">
                        <button type="submit" class="w-full bg-white text-slate-900 font-black py-5 rounded-2xl text-xl shadow-xl hover:scale-[1.02] active:scale-95 transition-all">
                            JETZT WÄHLEN
                        </button>
                    </form>
                <?php endif; ?>

                <!-- Options List -->
                <section class="space-y-4">
                    <div class="flex items-center justify-between px-1">
                        <h3 class="text-xs font-black uppercase tracking-widest text-slate-500">Optionen (<?= count($currentSession['options']) ?>)</h3>
                        <span class="text-[10px] bg-slate-800 text-slate-400 px-2 py-1 rounded-full border border-slate-700">
                            Methode: <?= $currentSession['method'] === 'random' ? 'Zufall' : ($currentSession['method'] === 'even' ? 'Verteilung' : 'Gewichtet') ?>
                        </span>
                    </div>

                    <div class="space-y-3">
                        <?php foreach ($currentSession['options'] as $idx => $opt): ?>
                            <div class="bg-slate-800/40 rounded-2xl border border-slate-700/50 overflow-hidden group">
                                <!-- View Mode -->
                                <div id="opt-view-<?= $idx ?>" class="flex items-center p-4 gap-4">
                                    <div class="flex-1 min-w-0">
                                        <span class="block font-bold text-slate-100 truncate"><?= htmlspecialchars($opt['text']) ?></span>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-tighter bg-slate-900/50 px-2 py-0.5 rounded border border-slate-700/50">
                                                <?= $opt['hits'] ?>x gewählt
                                            </span>
                                            <?php if ($currentSession['method'] === 'weighted'): ?>
                                                <span class="text-[10px] font-bold text-cyan-500 uppercase tracking-tighter bg-cyan-500/10 px-2 py-0.5 rounded border border-cyan-500/20">
                                                    Gewicht: <?= $opt['weight'] ?? 1 ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <button onclick="toggleEdit(<?= $idx ?>)" class="p-2 text-slate-500 hover:text-cyan-400 hover:bg-cyan-500/10 rounded-xl transition-all">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </button>
                                        <a href="?id=<?= $sessionId ?>&remove=<?= $idx ?>" class="p-2 text-slate-500 hover:text-red-400 hover:bg-red-500/10 rounded-xl transition-all" onclick="return confirm('Option löschen?')">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </a>
                                    </div>
                                </div>
                                <!-- Edit Mode -->
                                <div id="opt-edit-<?= $idx ?>" class="hidden p-4 bg-slate-900/50 border-t border-slate-700/30">
                                    <form method="POST" class="space-y-3">
                                        <input type="hidden" name="action" value="update_option">
                                        <input type="hidden" name="option_idx" value="<?= $idx ?>">
                                        <div>
                                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Bezeichnung</label>
                                            <input type="text" name="option_text" value="<?= htmlspecialchars($opt['text']) ?>" required class="w-full bg-slate-800 border border-slate-700 rounded-xl p-2 text-sm focus:outline-none focus:ring-1 focus:ring-cyan-500">
                                        </div>
                                        <?php if ($currentSession['method'] === 'weighted'): ?>
                                            <div>
                                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Gewichtung (1-100)</label>
                                                <input type="number" name="weight" value="<?= $opt['weight'] ?? 1 ?>" min="1" max="100" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-2 text-sm focus:outline-none focus:ring-1 focus:ring-cyan-500">
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex gap-2 pt-1">
                                            <button type="submit" class="flex-1 bg-cyan-500 hover:bg-cyan-400 text-slate-900 font-bold py-2 rounded-xl text-sm transition-colors">Speichern</button>
                                            <button type="button" onclick="toggleEdit(<?= $idx ?>)" class="flex-1 bg-slate-700 hover:bg-slate-600 text-white font-bold py-2 rounded-xl text-sm transition-colors">Abbrechen</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Add Option Form -->
                    <form method="POST" class="bg-slate-900/50 p-4 rounded-2xl border border-slate-700/50 space-y-3 mt-4">
                        <input type="hidden" name="action" value="add_option">
                        <h4 class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-1">Neue Option hinzufügen</h4>
                        <div class="flex gap-2">
                            <input type="text" name="option_text" required placeholder="Name der Option..." class="flex-1 bg-slate-800 border border-slate-700 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cyan-500/30 transition-all">
                            <?php if ($currentSession['method'] === 'weighted'): ?>
                                <input type="number" name="weight" value="1" min="1" max="100" class="w-20 bg-slate-800 border border-slate-700 rounded-xl px-2 py-3 text-center focus:outline-none focus:ring-2 focus:ring-cyan-500/30 font-bold" title="Gewichtung">
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="w-full bg-slate-700 hover:bg-slate-600 text-white font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            Option hinzufügen
                        </button>
                    </form>
                </section>
            </main>
        <?php endif; ?>

        <footer class="text-center mt-12 pb-8 space-y-4">
            <div class="flex justify-center gap-4 text-xs">
                <a href="index.php" class="text-slate-500 hover:text-cyan-400 transition-colors">Startseite</a>
                <span class="text-slate-700">&bull;</span>
                <a href="https://github.com/alexpthe1/s3l3ct0r" target="_blank" class="text-slate-500 hover:text-cyan-400 transition-colors">GitHub</a>
            </div>
            <p class="text-[10px] text-slate-600 uppercase tracking-widest font-bold">
                &copy; <?= date('Y') ?> s3l3ct0r &bull; Alexander Peter
            </p>
        </footer>
    </div>

    <script>
        function copyLink() {
            navigator.clipboard.writeText(window.location.href);
            const btn = event.currentTarget;
            const originalIcon = btn.innerHTML;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>';
            setTimeout(() => btn.innerHTML = originalIcon, 2000);
        }

        function toggleEdit(idx) {
            const view = document.getElementById('opt-view-' + idx);
            const edit = document.getElementById('opt-edit-' + idx);
            if (view.classList.contains('hidden')) {
                view.classList.remove('hidden');
                edit.classList.add('hidden');
            } else {
                view.classList.add('hidden');
                edit.classList.remove('hidden');
            }
        }

        const resBox = document.getElementById('result-box');
        if (resBox) {
            setTimeout(() => {
                resBox.classList.remove('animate-bounce');
            }, 3000);
        }
    </script>
</body>
</html>
