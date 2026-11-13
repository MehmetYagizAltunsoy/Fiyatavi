<?php
declare(strict_types=1);
/* Veri tabanı: SQLite (PDO). Tablolar Node sürümüyle aynı; PHP'de bellekte tutulamayan şeyler için
   üç yeni tablo eklendi: cache (önbellek), attempts (deneme sınırı), queue (öncelikli fiyat kuyruğu). */

const USD_RATE = ['USD' => 1, 'EUR' => 1.16, 'GBP' => 1.33, 'JPY' => 0.0067, 'BRL' => 0.185, 'CAD' => 0.72, 'AUD' => 0.66, 'MXN' => 0.054, 'CHF' => 1.25, 'TRY' => 0.024];

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $file = (string) env('DB_PATH', data_dir() . '/ganimet.db');
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fresh = !is_file($file);
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000; PRAGMA foreign_keys = ON;');
    try { $pdo->exec('PRAGMA journal_mode = WAL;'); } catch (Throwable $e) { /* bazı barındırmalarda WAL kapalı olabilir */ }
    migrate($pdo);
    return $pdo;
}

/** Sorgu: tüm satırlar */
function q(string $sql, array $args = []): array { $st = db()->prepare($sql); $st->execute($args); return $st->fetchAll(); }
/** Sorgu: ilk satır ya da null */
function q1(string $sql, array $args = []): ?array { $st = db()->prepare($sql); $st->execute($args); $r = $st->fetch(); return $r === false ? null : $r; }
/** Sorgu: ilk satırın ilk sütunu */
function qv(string $sql, array $args = [], $default = null) { $r = q1($sql, $args); return $r === null ? $default : array_values($r)[0]; }
/** INSERT/UPDATE/DELETE: etkilenen satır sayısı */
function ex(string $sql, array $args = []): int { $st = db()->prepare($sql); $st->execute($args); return $st->rowCount(); }
function last_id(): int { return (int) db()->lastInsertId(); }
function tx(callable $fn) {
    $pdo = db();
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function meta_get(string $key): ?string { $v = qv('SELECT value FROM meta WHERE key = ?', [$key]); return $v === null ? null : (string) $v; }
function meta_set(string $key, string $value): void { ex('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)', [$key, $value]); }

/** Basit önbellek (APCu garantisi olmadığı için veri tabanında): süre dolunca null döner */
function cache_get(string $key, int $ttlSeconds) {
    $r = q1('SELECT value, at FROM cache WHERE key = ?', [$key]);
    if (!$r || time() - (int) $r['at'] > $ttlSeconds) return null;
    return json_decode($r['value'], true);
}
function cache_set(string $key, $value, ?int $at = null): void {
    ex('INSERT OR REPLACE INTO cache (key, value, at) VALUES (?, ?, ?)', [$key, jenc($value), $at ?? time()]);
}

/** Kaba kuvvet koruması: $windowSec içinde $max denemeden fazlası true döner (bu çağrı da sayılır) */
function too_many_attempts(string $key, int $max = 10, int $windowSec = 900): bool {
    $now = time();
    ex('DELETE FROM attempts WHERE at < ?', [$now - $windowSec]);
    ex('INSERT INTO attempts (k, at) VALUES (?, ?)', [$key, $now]);
    return (int) qv('SELECT COUNT(*) FROM attempts WHERE k = ?', [$key], 0) > $max;
}

function add_column(PDO $pdo, string $table, string $col, string $def): bool {
    $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    if (in_array($col, $cols, true)) return false;
    $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
    return true;
}

function migrate(PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS games (
  id TEXT PRIMARY KEY, slug TEXT, title TEXT NOT NULL, release_date TEXT, developers TEXT, tags TEXT, boxart TEXT,
  steam_appid INTEGER UNIQUE, popular_rank INTEGER, history_done INTEGER DEFAULT 0
);
CREATE TABLE IF NOT EXISTS prices (
  game_id TEXT NOT NULL REFERENCES games(id), country TEXT NOT NULL, shop_id TEXT NOT NULL, shop_name TEXT NOT NULL,
  price REAL NOT NULL, regular REAL NOT NULL, currency TEXT NOT NULL, cut INTEGER DEFAULT 0, expiry TEXT, url TEXT, updated_at TEXT,
  PRIMARY KEY (game_id, country, shop_id)
);
CREATE TABLE IF NOT EXISTS price_history (
  game_id TEXT NOT NULL, country TEXT NOT NULL, day TEXT NOT NULL, lowest REAL NOT NULL, currency TEXT NOT NULL,
  PRIMARY KEY (game_id, country, day)
);
CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE IF NOT EXISTS history_fetched (game_id TEXT NOT NULL, country TEXT NOT NULL, PRIMARY KEY (game_id, country));
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL, pass_hash TEXT NOT NULL,
  country TEXT DEFAULT 'TR', lang TEXT DEFAULT 'tr', created_at TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS sessions (
  token TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, expires_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS wishlist (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, game_id TEXT NOT NULL REFERENCES games(id),
  added_at TEXT DEFAULT (datetime('now')), last_price REAL, PRIMARY KEY (user_id, game_id)
);
CREATE TABLE IF NOT EXISTS notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  game_id TEXT, kind TEXT, text TEXT, sent_at TEXT DEFAULT (datetime('now'))
);
SQL);
    add_column($db, 'games', 'type', 'TEXT');
    add_column($db, 'games', 'appid', 'INTEGER');
    add_column($db, 'games', 'free', 'INTEGER DEFAULT 0');
    add_column($db, 'games', 'last_priced', 'TEXT');
    add_column($db, 'games', 'viewed_at', 'TEXT');
    $db->exec('UPDATE games SET appid = steam_appid WHERE appid IS NULL AND steam_appid IS NOT NULL');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lows (
  game_id TEXT NOT NULL, country TEXT NOT NULL, shop TEXT, price REAL, regular REAL, currency TEXT, cut INTEGER, expiry TEXT,
  PRIMARY KEY (game_id, country)
);
CREATE TABLE IF NOT EXISTS steam_apps (appid INTEGER PRIMARY KEY, name TEXT, mapped INTEGER DEFAULT 0);
CREATE INDEX IF NOT EXISTS games_rank ON games (popular_rank);
CREATE INDEX IF NOT EXISTS games_title ON games (title COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS games_priced ON games (last_priced);
CREATE INDEX IF NOT EXISTS steam_unmapped ON steam_apps (mapped);
SQL);
    add_column($db, 'lows', 'usd', 'REAL');
    add_column($db, 'lows', 'low_m3', 'REAL');
    add_column($db, 'lows', 'low_all', 'REAL');
    add_column($db, 'users', 'notify_drops', 'INTEGER DEFAULT 1');
    add_column($db, 'games', 'rating_pos', 'INTEGER');
    add_column($db, 'games', 'rating_neg', 'INTEGER');
    add_column($db, 'games', 'rating_at', 'TEXT');
    add_column($db, 'games', 'owners', 'INTEGER');
    add_column($db, 'games', 'play_min', 'INTEGER');   // oyuncuların ortanca oynama süresi, dakika (SteamSpy)
    // Ortak listeler: üyelerin hepsi aynı oyunları görür; eklenen oyun her üyenin istek listesine de düşer
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lists (
  id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT UNIQUE NOT NULL, name TEXT NOT NULL, owner_id INTEGER NOT NULL, created_at TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS list_members (
  list_id INTEGER NOT NULL REFERENCES lists(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  joined_at TEXT DEFAULT (datetime('now')), PRIMARY KEY (list_id, user_id)
);
CREATE TABLE IF NOT EXISTS list_games (
  list_id INTEGER NOT NULL REFERENCES lists(id) ON DELETE CASCADE, game_id TEXT NOT NULL, added_by INTEGER, added_at TEXT DEFAULT (datetime('now')),
  PRIMARY KEY (list_id, game_id)
);
CREATE INDEX IF NOT EXISTS list_members_user ON list_members (user_id);
SQL);
    add_column($db, 'games', 'waitlist_rank', 'INTEGER');
    add_column($db, 'games', 'collected_rank', 'INTEGER');
    add_column($db, 'prices', 'usd', 'REAL');
    add_column($db, 'games', 'appid_checked', 'INTEGER DEFAULT 0');
    if (add_column($db, 'users', 'email_verified', 'INTEGER DEFAULT 0')) $db->exec('UPDATE users SET email_verified = 1');
    add_column($db, 'users', 'consent_at', 'TEXT');
    add_column($db, 'users', 'avatar', 'TEXT');
    add_column($db, 'wishlist', 'target_price', 'REAL');
    add_column($db, 'wishlist', 'target_currency', 'TEXT');
    add_column($db, 'wishlist', 'target_hit', 'INTEGER DEFAULT 0');
    $db->exec('CREATE TABLE IF NOT EXISTS media (game_id TEXT PRIMARY KEY, video TEXT, poster TEXT, checked_at TEXT)');
    add_column($db, 'media', 'req_min', 'TEXT');
    add_column($db, 'media', 'req_rec', 'TEXT');
    add_column($db, 'media', 'details_v', 'INTEGER DEFAULT 0');
    add_column($db, 'media', 'shots', 'TEXT');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS email_tokens (
  token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, kind TEXT NOT NULL, expires_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS game_tags (game_id TEXT NOT NULL, tag TEXT NOT NULL, PRIMARY KEY (game_id, tag));
CREATE INDEX IF NOT EXISTS game_tags_tag ON game_tags (tag, game_id);
CREATE INDEX IF NOT EXISTS games_owners ON games (owners);
CREATE INDEX IF NOT EXISTS games_appid ON games (appid);
SQL);
    add_column($db, 'users', 'min_cut', 'INTEGER DEFAULT 0');
    add_column($db, 'users', 'weekly_digest', 'INTEGER DEFAULT 1');
    add_column($db, 'users', 'last_digest_at', 'TEXT');
    if (!$db->query('SELECT 1 FROM lows LIMIT 1')->fetch()) {
        $db->exec('INSERT OR IGNORE INTO lows (game_id, country, shop, price, regular, currency, cut, expiry)
          SELECT game_id, country, shop_name, price, regular, currency, cut, expiry FROM prices p
          WHERE price = (SELECT MIN(price) FROM prices p2 WHERE p2.game_id = p.game_id AND p2.country = p.country)');
    }
    add_column($db, 'games', 'hidden', 'INTEGER DEFAULT 0');
    if (add_column($db, 'games', 'info_done', 'INTEGER DEFAULT 0')) $db->exec('UPDATE games SET info_done = 1 WHERE boxart IS NOT NULL OR tags IS NOT NULL');
    if (add_column($db, 'games', 'mature', 'INTEGER DEFAULT 0')) {
        // önceden "mature" oyunlar da hidden=1 yapılıyordu; type='game' olup hidden olanlar mature yüzünden gizlenmiştir
        $db->exec("UPDATE games SET mature = 1, hidden = 0 WHERE hidden = 1 AND type = 'game'");
    }
    $db->exec("UPDATE games SET free = 1 WHERE free = 0 AND tags LIKE '%\"Free to Play\"%'");

    $case = 'CASE currency';
    foreach (USD_RATE as $c => $r) $case .= " WHEN '$c' THEN $r";
    $case .= ' ELSE 1 END';
    $db->exec("UPDATE lows SET usd = price * $case WHERE usd IS NULL");
    $db->exec("UPDATE prices SET usd = price * $case WHERE usd IS NULL");

    if (!$db->query('SELECT 1 FROM game_tags LIMIT 1')->fetch()) {
        $ins = $db->prepare('INSERT OR IGNORE INTO game_tags (game_id, tag) VALUES (?, ?)');
        $db->beginTransaction();
        foreach ($db->query('SELECT id, tags FROM games WHERE tags IS NOT NULL')->fetchAll() as $g) {
            foreach (json_decode_arr($g['tags']) as $t) $ins->execute([$g['id'], $t]);
        }
        $db->commit();
    }
    // Site sadece Türkiye fiyatlarını gösterir: diğer bölgelerden kalan kayıtları bir kez temizle
    if (!$db->query("SELECT 1 FROM meta WHERE key = 'only_tr'")->fetch()) {
        $db->exec("BEGIN;
          DELETE FROM prices WHERE country <> 'TR'; DELETE FROM lows WHERE country <> 'TR';
          DELETE FROM price_history WHERE country <> 'TR'; DELETE FROM history_fetched WHERE country <> 'TR';
          UPDATE users SET country = 'TR' WHERE country IS NULL OR country <> 'TR';
          INSERT OR REPLACE INTO meta (key, value) VALUES ('only_tr', '1');
          COMMIT;");
    }
    // PHP sürümüne özel tablolar
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cache (key TEXT PRIMARY KEY, value TEXT, at INTEGER);
CREATE TABLE IF NOT EXISTS attempts (k TEXT NOT NULL, at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS attempts_k ON attempts (k, at);
CREATE TABLE IF NOT EXISTS queue (game_id TEXT PRIMARY KEY, added_at INTEGER NOT NULL);
SQL);
}
