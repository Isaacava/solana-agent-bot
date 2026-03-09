<?php
namespace SolanaAgent\Storage;

/**
 * SQLite Database wrapper
 * Handles all persistence: users, wallets, tasks, alerts, logs
 */
class Database
{
    private \PDO $pdo;
    private static ?Database $instance = null;

    private function __construct(string $dbFile)
    {
        $dir = dirname($dbFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new \PDO('sqlite:' . $dbFile);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode=WAL;');
        $this->pdo->exec('PRAGMA foreign_keys=ON;');
        $this->migrate();
    }

    public static function getInstance(string $dbFile = ''): self
    {
        if (self::$instance === null) {
            if (empty($dbFile)) {
                throw new \RuntimeException('DB file path required for first init');
            }
            self::$instance = new self($dbFile);
        }
        return self::$instance;
    }

    // ─── Migrations ───────────────────────────────────────────────────────────

    private function migrate(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id TEXT UNIQUE NOT NULL,
                username    TEXT,
                first_name  TEXT,
                language    TEXT DEFAULT 'english',
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_seen   DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS wallets (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER NOT NULL,
                label         TEXT DEFAULT 'Main Wallet',
                public_key    TEXT NOT NULL,
                encrypted_sk  TEXT NOT NULL,
                network       TEXT DEFAULT 'devnet',
                is_active     INTEGER DEFAULT 1,
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS transactions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL,
                wallet_id   INTEGER NOT NULL,
                type        TEXT NOT NULL,
                amount_sol  REAL,
                from_addr   TEXT,
                to_addr     TEXT,
                signature   TEXT,
                status      TEXT DEFAULT 'pending',
                network     TEXT DEFAULT 'devnet',
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS price_alerts (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER NOT NULL,
                telegram_id   TEXT NOT NULL,
                target_price  REAL NOT NULL,
                direction     TEXT NOT NULL,
                triggered     INTEGER DEFAULT 0,
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS scheduled_tasks (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL,
                telegram_id TEXT NOT NULL,
                type        TEXT NOT NULL,
                payload     TEXT NOT NULL,
                execute_at  DATETIME NOT NULL,
                executed    INTEGER DEFAULT 0,
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS chat_history (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL,
                role        TEXT NOT NULL,
                content     TEXT NOT NULL,
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS bot_logs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                level       TEXT DEFAULT 'info',
                message     TEXT NOT NULL,
                context     TEXT,
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS settings (
                key_name    TEXT PRIMARY KEY,
                value       TEXT,
                updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS conditional_tasks (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id         INTEGER NOT NULL,
                telegram_id     TEXT NOT NULL,
                condition_type  TEXT NOT NULL,
                condition_value REAL NOT NULL,
                action_type     TEXT NOT NULL,
                action_payload  TEXT NOT NULL,
                label           TEXT,
                triggered       INTEGER DEFAULT 0,
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS trading_strategies (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id         INTEGER NOT NULL,
                telegram_id     TEXT NOT NULL,
                label           TEXT,
                status          TEXT DEFAULT 'active',
                buy_price       REAL NOT NULL,
                sell_price      REAL NOT NULL,
                stop_loss       REAL,
                amount_sol      REAL NOT NULL,
                phase           TEXT DEFAULT 'waiting_buy',
                buy_tx          TEXT,
                sell_tx         TEXT,
                est_profit_pct  REAL,
                triggered_at    DATETIME,
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );
        ");

        // ── Add meta column to transactions if missing (swap details) ──────────
        try {
            $this->pdo->exec("ALTER TABLE transactions ADD COLUMN meta TEXT");
        } catch (\Throwable $ignored) {} // column already exists

        // ── Add key column alias for settings table ───────────────────────────
        try {
            $this->pdo->exec("ALTER TABLE settings ADD COLUMN key TEXT");
        } catch (\Throwable $ignored) {}

        // ── Add strategy_type column to trading_strategies ────────────────────
        try {
            $this->pdo->exec("ALTER TABLE trading_strategies ADD COLUMN strategy_type TEXT DEFAULT 'CONSERVATIVE'");
        } catch (\Throwable $ignored) {}

        // ── DCA tasks table ───────────────────────────────────────────────────
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS dca_tasks (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id        INTEGER NOT NULL,
                telegram_id    TEXT NOT NULL,
                amount_usd     REAL NOT NULL,
                interval_hours INTEGER NOT NULL DEFAULT 24,
                next_run       DATETIME NOT NULL,
                runs_count     INTEGER DEFAULT 0,
                status         TEXT DEFAULT 'active',
                label          TEXT,
                created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );
        ");

        // ── Add trailing_pct column to trading_strategies ─────────────────────
        try {
            $this->pdo->exec("ALTER TABLE trading_strategies ADD COLUMN trailing_pct REAL DEFAULT 0");
        } catch (\Throwable $ignored) {}

        // ── Data repair: ensure each user has exactly ONE active wallet ─────────
        // If a user somehow has multiple is_active=1 wallets (e.g. from old versions),
        // keep only the most recently created one as active.
        $this->pdo->exec("
            UPDATE wallets SET is_active = 0
            WHERE is_active = 1
              AND id NOT IN (
                  SELECT MAX(id) FROM wallets
                  WHERE is_active = 1
                  GROUP BY user_id
              )
        ");
    }

    // ─── Generic helpers ──────────────────────────────────────────────────────

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        return $this->query($sql, $params)->fetch() ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert(string $table, array $data): int
    {
        $cols = implode(', ', array_keys($data));
        $ph   = implode(', ', array_fill(0, count($data), '?'));
        $this->query("INSERT INTO {$table} ({$cols}) VALUES ({$ph})", array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $set  = implode(', ', array_map(fn($k) => "{$k}=?", array_keys($data)));
        $whr  = implode(' AND ', array_map(fn($k) => "{$k}=?", array_keys($where)));
        $stmt = $this->query(
            "UPDATE {$table} SET {$set} WHERE {$whr}",
            [...array_values($data), ...array_values($where)]
        );
        return $stmt->rowCount();
    }

    public function lastId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    // ─── User methods ─────────────────────────────────────────────────────────

    public function upsertUser(array $data): int
    {
        $existing = $this->fetch(
            'SELECT id FROM users WHERE telegram_id=?',
            [$data['telegram_id']]
        );
        if ($existing) {
            $this->update('users', [
                'username'   => $data['username'] ?? null,
                'first_name' => $data['first_name'] ?? null,
                'last_seen'  => date('Y-m-d H:i:s'),
            ], ['telegram_id' => $data['telegram_id']]);
            return (int)$existing['id'];
        }
        return $this->insert('users', $data);
    }

    public function getUserByTelegramId(string $telegramId): ?array
    {
        return $this->fetch('SELECT * FROM users WHERE telegram_id=?', [$telegramId]);
    }

    // ─── Wallet methods ───────────────────────────────────────────────────────

    public function getActiveWallet(int $userId): ?array
    {
        // First try: wallet explicitly marked active
        $wallet = $this->fetch(
            'SELECT * FROM wallets WHERE user_id=? AND is_active=1 LIMIT 1',
            [$userId]
        );
        if ($wallet) return $wallet;

        // Fallback: if no wallet is marked active (e.g. old data), use the first wallet
        // and mark it active so future queries are fast
        $first = $this->fetch(
            'SELECT * FROM wallets WHERE user_id=? ORDER BY id ASC LIMIT 1',
            [$userId]
        );
        if ($first) {
            $this->query('UPDATE wallets SET is_active=1 WHERE id=?', [$first['id']]);
            $first['is_active'] = 1;
        }
        return $first;
    }

    public function getUserWallets(int $userId): array
    {
        return $this->fetchAll('SELECT * FROM wallets WHERE user_id=? ORDER BY id DESC', [$userId]);
    }

    // ─── Chat history (last N messages for AI context) ────────────────────────

    public function addChatMessage(int $userId, string $role, string $content): void
    {
        $this->insert('chat_history', compact('user_id', 'role', 'content') + ['user_id' => $userId]);
        // Keep only last 20 messages per user
        $this->query(
            'DELETE FROM chat_history WHERE user_id=? AND id NOT IN
             (SELECT id FROM chat_history WHERE user_id=? ORDER BY id DESC LIMIT 20)',
            [$userId, $userId]
        );
    }

    public function getChatHistory(int $userId, int $limit = 10): array
    {
        return $this->fetchAll(
            'SELECT role, content FROM chat_history WHERE user_id=? ORDER BY id DESC LIMIT ?',
            [$userId, $limit]
        );
    }

    // ─── Settings ─────────────────────────────────────────────────────────────

    public function getSetting(string $key, $default = null)
    {
        $row = $this->fetch('SELECT value FROM settings WHERE key_name=?', [$key]);
        return $row ? $row['value'] : $default;
    }

    public function setSetting(string $key, $value): void
    {
        $this->query(
            'INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?,?,?)',
            [$key, $value, date('Y-m-d H:i:s')]
        );
    }

    // ─── Stats for admin dashboard ────────────────────────────────────────────

    public function getStats(): array
    {
        return [
            'total_users'    => (int)$this->fetch('SELECT COUNT(*) c FROM users')['c'],
            'total_wallets'  => (int)$this->fetch('SELECT COUNT(*) c FROM wallets')['c'],
            'total_tx'       => (int)$this->fetch('SELECT COUNT(*) c FROM transactions')['c'],
            'active_alerts'  => (int)$this->fetch('SELECT COUNT(*) c FROM price_alerts WHERE triggered=0')['c'],
            'pending_tasks'  => (int)$this->fetch('SELECT COUNT(*) c FROM scheduled_tasks WHERE executed=0')['c'],
        ];
    }

    public function getRecentLogs(int $limit = 50): array
    {
        return $this->fetchAll(
            'SELECT * FROM bot_logs ORDER BY id DESC LIMIT ?', [$limit]
        );
    }

    public function getRecentTransactions(int $limit = 20): array
    {
        return $this->fetchAll(
            'SELECT t.*, u.username, u.first_name
             FROM transactions t JOIN users u ON t.user_id=u.id
             ORDER BY t.id DESC LIMIT ?',
            [$limit]
        );
    }

    public function getUsersOverTime(): array
    {
        return $this->fetchAll(
            "SELECT DATE(created_at) d, COUNT(*) c
             FROM users GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 30"
        );
    }

    // ─── Trading Strategies ───────────────────────────────────────────────────

    public function createStrategy(array $data): int
    {
        return $this->insert('trading_strategies', $data);
    }

    public function getActiveStrategies(): array
    {
        return $this->fetchAll(
            "SELECT * FROM trading_strategies WHERE status='active' ORDER BY id ASC"
        );
    }

    public function getUserStrategies(int $userId): array
    {
        return $this->fetchAll(
            "SELECT * FROM trading_strategies WHERE user_id=? ORDER BY id DESC LIMIT 20",
            [$userId]
        );
    }

    public function updateStrategy(int $id, array $data): void
    {
        $this->update('trading_strategies', $data, ['id' => $id]);
    }

    public function cancelStrategy(int $id, int $userId): bool
    {
        return $this->update('trading_strategies', ['status' => 'cancelled'], ['id' => $id, 'user_id' => $userId]) > 0;
    }
}
