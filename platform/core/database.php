<?php
/**
 * RestaurantOS — database layer.
 *
 * Multi-tenancy strategy: **one database per tenant**.
 *
 *   MySQL  (production) : restaurantos_platform  +  restaurantos_t_<slug>
 *   SQLite (sandbox/demo): storage/platform.sqlite + storage/tenants/<slug>.sqlite
 *
 * Because every tenant owns a private database, the existing single-tenant
 * restaurant application keeps working verbatim — it still talks to `$conn`,
 * it is simply handed a different connection per tenant.
 *
 * The translator below lets MySQL-flavoured SQL (the dialect the legacy app
 * was written in) run on SQLite, which is what makes the sandbox demo work.
 *
 * @package Resto\Database
 */

namespace Resto\Database;

use PDO;
use PDOException;
use PDOStatement;
use Resto\Support\Config;

/* -------------------------------------------------------------------------
 * Translator — MySQL dialect => SQLite dialect
 * ---------------------------------------------------------------------- */

final class Translator
{
    private const READ_QUERY = '/^\s*(SELECT|WITH|PRAGMA|SHOW|EXPLAIN)\b/i';

    public static function isReadQuery(string $sql): bool
    {
        return (bool) preg_match(self::READ_QUERY, $sql);
    }

    /**
     * Translate a statement for the target driver. MySQL statements are
     * returned untouched; only SQLite needs rewriting.
     */
    public static function translate(string $sql, string $driver, ?PDO $introspect = null): string
    {
        if ($driver !== 'sqlite') {
            return $sql;
        }

        $sql = self::translateShow($sql);
        $sql = self::translateDdl($sql);
        $sql = str_replace('`', '"', $sql);
        $sql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $sql) ?? $sql;
        $sql = preg_replace('/\bNOW\(\)/i', "datetime('now')", $sql) ?? $sql;
        $sql = preg_replace('/\bCURDATE\(\)/i', "date('now')", $sql) ?? $sql;
        $sql = preg_replace('/\bRAND\(\)/i', 'RANDOM()', $sql) ?? $sql;
        $sql = self::translateDateFunctions($sql);
        $sql = self::translateGroupConcat($sql);
        $sql = self::translateLimitOffset($sql);
        $sql = self::translateUpsert($sql, $introspect);

        // MySQL-only session statements are no-ops here.
        if (preg_match('/^\s*SET\s+(NAMES|SQL_MODE|time_zone|FOREIGN_KEY_CHECKS)/i', $sql)) {
            return 'SELECT 1';
        }

        return $sql;
    }

    /** SHOW TABLES / SHOW COLUMNS => sqlite_master + pragma_table_info. */
    private static function translateShow(string $sql): string
    {
        if (preg_match('/^\s*SHOW\s+TABLES\s+LIKE\s+([\'"][^\'"]+[\'"])/i', $sql, $m)) {
            return "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE {$m[1]}";
        }
        if (preg_match('/^\s*SHOW\s+TABLES\b/i', $sql)) {
            return "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
        }
        if (preg_match('/^\s*SHOW\s+COLUMNS\s+FROM\s+([`"\w]+)(?:\s+(?:LIKE|WHERE\s+Field\s+LIKE)\s+([\'"][^\'"]+[\'"]))?/i', $sql, $m)) {
            $table  = trim($m[1], '`"');
            $filter = isset($m[2]) ? ' WHERE name LIKE ' . $m[2] : '';
            return 'SELECT name AS "Field", type AS "Type", '
                . "CASE WHEN \"notnull\" = 0 THEN 'YES' ELSE 'NO' END AS \"Null\", "
                . 'dflt_value AS "Default", '
                . "CASE WHEN pk = 1 THEN 'PRI' ELSE '' END AS \"Key\", "
                . "'' AS \"Extra\" "
                . "FROM pragma_table_info('{$table}'){$filter}";
        }
        return $sql;
    }

    /** MySQL custom DDL (legacy installer scripts) => SQLite DDL. */
    private static function translateDdl(string $sql): string
    {
        if (!preg_match('/^\s*CREATE\s+TABLE/i', $sql)) {
            return $sql;
        }

        $sql = preg_replace('/\bINT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql) ?? $sql;
        $sql = preg_replace('/\bBIGINT\s+UNSIGNED\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql) ?? $sql;
        $sql = preg_replace('/\bAUTO_INCREMENT\b/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\bUNSIGNED\b/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\bTINYINT\(1\)/i', 'INTEGER', $sql) ?? $sql;
        $sql = preg_replace('/\bTINYINT\b/i', 'INTEGER', $sql) ?? $sql;
        $sql = preg_replace('/\bBOOLEAN\b/i', 'INTEGER', $sql) ?? $sql;
        $sql = preg_replace('/\bDOUBLE\b/i', 'REAL', $sql) ?? $sql;
        $sql = preg_replace('/\bENUM\s*\([^)]*\)/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/\bVARCHAR\s*\(\s*\d+\s*\)/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/\b(JSON|DATETIME|TIMESTAMP)\b/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/\bDECIMAL\s*\(\s*\d+\s*,\s*\d+\s*\)/i', 'NUMERIC', $sql) ?? $sql;
        $sql = preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\bCHARACTER\s+SET\s+\w+/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\bCOLLATE\s+\w+/i', '', $sql) ?? $sql;
        $sql = preg_replace("/\bCOMMENT\s+'(?:[^'\\\\]|\\\\.)*'/i", '', $sql) ?? $sql;
        $sql = preg_replace('/\)\s*ENGINE\s*=\s*\w+[^;]*$/i', ')', $sql) ?? $sql;
        // Inline "KEY idx (col)" / "INDEX idx (col)" lines are not valid SQLite.
        $sql = preg_replace('/,\s*(?:UNIQUE\s+)?KEY\s+`?\w+`?\s*\([^)]*\)/i', '', $sql) ?? $sql;
        $sql = preg_replace('/,\s*INDEX\s+`?\w+`?\s*\([^)]*\)/i', '', $sql) ?? $sql;
        $sql = preg_replace('/,\s*UNIQUE\s+KEY\s+`?\w+`?\s*\(([^)]*)\)/i', ', UNIQUE ($1)', $sql) ?? $sql;
        return $sql;
    }

    /** DATE_FORMAT(x, '%Y-%m') => strftime('%Y-%m', x). */
    private static function translateDateFunctions(string $sql): string
    {
        return preg_replace_callback(
            '/DATE_FORMAT\s*\((.*?),(\s*\'[^\']*\'\s*)\)/i',
            static function (array $m): string {
                $format = str_replace(
                    ['%i', '%s', '%W', '%M', '%D'],
                    ['%M', '%S', '%w', '%m', '%d'],
                    trim($m[2], " '\t")
                );
                return "strftime('{$format}', {$m[1]})";
            },
            $sql
        ) ?? $sql;
    }

    /** GROUP_CONCAT(x SEPARATOR ',') => GROUP_CONCAT(x, ','). */
    private static function translateGroupConcat(string $sql): string
    {
        return preg_replace(
            '/GROUP_CONCAT\s*\((.*?)\s+SEPARATOR\s+(\'[^\']*\')\)/i',
            'GROUP_CONCAT($1, $2)',
            $sql
        ) ?? $sql;
    }

    /** MySQL "LIMIT offset, count" => SQLite "LIMIT count OFFSET offset". */
    private static function translateLimitOffset(string $sql): string
    {
        return preg_replace('/\bLIMIT\s+(\d+)\s*,\s*(\d+)/i', 'LIMIT $2 OFFSET $1', $sql) ?? $sql;
    }

    /**
     * INSERT … ON DUPLICATE KEY UPDATE … => INSERT … ON CONFLICT (target) DO UPDATE …
     *
     * SQLite requires an explicit conflict target, so we look for a unique
     * index whose columns are all present in the INSERT column list.
     */
    private static function translateUpsert(string $sql, ?PDO $introspect): string
    {
        if (!preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql)) {
            return $sql;
        }

        [$insertPart, $updatePart] = preg_split('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql, 2);

        // VALUES(col) => excluded.col inside the SET list.
        $updatePart = preg_replace('/VALUES\s*\(\s*[`"]?(\w+)[`"]?\s*\)/i', 'excluded.$1', $updatePart) ?? $updatePart;

        $target = self::guessConflictTarget($insertPart, $introspect);
        if ($target === null) {
            // Nothing unique to key on: keep the insert, drop the update half.
            return rtrim($insertPart, "; \t\n\r") . ' ON CONFLICT DO NOTHING';
        }

        return rtrim($insertPart, "; \t\n\r") . ' ON CONFLICT (' . $target . ') DO UPDATE SET ' . ltrim($updatePart);
    }

    private static function guessConflictTarget(string $insertPart, ?PDO $introspect): ?string
    {
        if ($introspect === null) {
            return null;
        }
        if (!preg_match('/INSERT\s+(?:OR\s+IGNORE\s+)?INTO\s+[`"]?(\w+)[`"]?\s*\(([^)]*)\)/i', $insertPart, $m)) {
            return null;
        }
        $table   = $m[1];
        $columns = array_map(static fn ($c) => trim($c, " `\"\n\r\t"), explode(',', $m[2]));

        try {
            $indexes = $introspect->query("PRAGMA index_list(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return null;
        }

        $best = null;
        foreach ($indexes as $index) {
            if ((int) ($index['unique'] ?? 0) !== 1) {
                continue;
            }
            try {
                $cols = $introspect->query("PRAGMA index_info(\"{$index['name']}\")")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException) {
                continue;
            }
            $names = array_column($cols, 'name');
            if ($names === [] || array_diff($names, $columns) !== []) {
                continue;
            }
            if ($best === null || count($names) < count($best)) {
                $best = $names;
            }
        }

        return $best === null ? null : implode(', ', array_map(static fn ($c) => '"' . $c . '"', $best));
    }
}

/* -------------------------------------------------------------------------
 * Statement — buffered statement so rowCount() behaves like MySQL
 * ---------------------------------------------------------------------- */

/**
 * PDO_SQLite only reports rowCount() for writes, while the legacy application
 * uses it on SELECTs (a MySQL habit). Statements for read queries are buffered
 * so rowCount(), fetch(), fetchAll() and fetchColumn() all behave.
 */
class Statement extends PDOStatement
{
    /** @var array<int,array<int|string,mixed>>|null */
    private ?array $rows = null;
    private int $cursor = 0;
    private int $defaultMode = PDO::FETCH_BOTH;
    private bool $needsBuffering = false;

    /** Called by Connection for statements created through query(). */
    public function markForBuffering(int $defaultMode = PDO::FETCH_BOTH): void
    {
        $this->defaultMode    = $defaultMode;
        $this->needsBuffering = true;
        $this->buffer();
    }

    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->defaultMode = $mode;
        return parent::setFetchMode($mode, ...$args);
    }

    public function execute(?array $params = null): bool
    {
        $ok = parent::execute($params);
        if ($ok && Translator::isReadQuery((string) $this->queryString)) {
            $this->buffer();
        }
        return $ok;
    }

    private function buffer(): void
    {
        if ($this->rows !== null) {
            return;
        }
        try {
            $this->rows = parent::fetchAll(PDO::FETCH_BOTH) ?: [];
        } catch (\Throwable) {
            $this->rows = [];
        }
        $this->cursor = 0;
    }

    public function rowCount(): int
    {
        return $this->rows !== null ? count($this->rows) : parent::rowCount();
    }

    public function fetch(int $mode = 0, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->rows === null) {
            return parent::fetch($mode, $cursorOrientation, $cursorOffset);
        }
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }
        $row  = $this->rows[$this->cursor++];
        $mode = $mode ?: $this->defaultMode;
        return $this->shape($row, $mode);
    }

    public function fetchAll(int $mode = 0, mixed ...$args): array
    {
        if ($this->rows === null) {
            return parent::fetchAll($mode, ...$args);
        }
        $mode = $mode ?: $this->defaultMode;
        $rows = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);

        if ($mode === PDO::FETCH_COLUMN) {
            $column = (int) ($args[0] ?? 0);
            return array_map(static fn ($row) => $row[$column] ?? null, $rows);
        }
        if ($mode === PDO::FETCH_KEY_PAIR) {
            $out = [];
            foreach ($rows as $row) {
                $values = array_values($row);
                $out[$values[0] ?? null] = $values[1] ?? null;
            }
            return $out;
        }
        if ($mode === PDO::FETCH_GROUP) {
            $out = [];
            foreach ($rows as $row) {
                $values = array_values($row);
                $group  = array_shift($values);
                $out[$group][] = $this->shape($row, PDO::FETCH_ASSOC);
            }
            return $out;
        }

        return array_map(fn ($row) => $this->shape($row, $mode), $rows);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->rows === null) {
            return parent::fetchColumn($column);
        }
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }
        $row = $this->rows[$this->cursor++];
        return array_values($row)[$column] ?? false;
    }

    public function fetchObject(?string $class = null, array $constructorArgs = []): object|false
    {
        if ($this->rows === null) {
            return parent::fetchObject($class ?? 'stdClass', $constructorArgs);
        }
        $row = $this->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        if ($class === null) {
            return (object) $row;
        }
        return new $class(...array_values($row));
    }

    public function closeCursor(): bool
    {
        $this->cursor = $this->rows === null ? $this->cursor : count($this->rows);
        return parent::closeCursor();
    }

    /** Convert a FETCH_BOTH row into the requested fetch mode. */
    private function shape(array $row, int $mode): mixed
    {
        return match ($mode) {
            PDO::FETCH_ASSOC => array_filter($row, static fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY),
            PDO::FETCH_NUM   => array_values(array_filter($row, static fn ($k) => is_int($k), ARRAY_FILTER_USE_KEY)),
            PDO::FETCH_COLUMN => array_values($row)[0] ?? null,
            PDO::FETCH_OBJ   => (object) array_filter($row, static fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY),
            default          => $row,
        };
    }
}

/* -------------------------------------------------------------------------
 * Connection — translated PDO
 * ---------------------------------------------------------------------- */

class Connection extends PDO
{
    public string $driverName = 'mysql';
    /** @var array<int,array{sql:string,ms:float}> */
    public array $queryLog = [];
    public bool $logQueries = false;

    private bool $introspecting = false;
    private int $defaultFetchMode = PDO::FETCH_BOTH;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null, array $options = [], string $driver = 'mysql')
    {
        parent::__construct($dsn, $user, $pass, $options);
        $this->driverName = $driver;

        parent::setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        parent::setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->defaultFetchMode = PDO::FETCH_ASSOC;

        if ($driver === 'sqlite') {
            // Let the statement subclass fix rowCount()/fetch() semantics.
            parent::setAttribute(PDO::ATTR_STATEMENT_CLASS, [Statement::class]);
            parent::exec('PRAGMA foreign_keys = ON');
            parent::exec('PRAGMA journal_mode = WAL');
            parent::exec('PRAGMA busy_timeout = 5000');
            parent::exec('PRAGMA synchronous = NORMAL');
        }
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        if ($attribute === PDO::ATTR_DEFAULT_FETCH_MODE) {
            $this->defaultFetchMode = (int) $value;
        }
        return parent::setAttribute($attribute, $value);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): PDOStatement|false
    {
        $sql     = $this->translate($query);
        $started = microtime(true);
        $stmt    = $fetchMode === null
            ? parent::query($sql)
            : parent::query($sql, $fetchMode, ...$fetchModeArgs);

        if ($stmt instanceof Statement && Translator::isReadQuery($sql)) {
            if ($fetchMode !== null) {
                $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
            }
            $stmt->markForBuffering($fetchMode ?? $this->defaultFetchMode);
        }
        $this->log($sql, $started);
        return $stmt;
    }

    public function exec(string $statement): int|false
    {
        $sql     = $this->translate($statement);
        $started = microtime(true);
        $result  = parent::exec($sql);
        $this->log($sql, $started);
        return $result;
    }

    /** Raw PDO access, used by the schema builder (no translation). */
    public function pdo(): PDO
    {
        return $this;
    }

    private function translate(string $sql): string
    {
        if ($this->driverName !== 'sqlite') {
            return $sql;
        }
        // Avoid recursion when the translator itself introspects the schema.
        $introspect = $this->introspecting ? null : $this;
        $this->introspecting = true;
        try {
            return Translator::translate($sql, $this->driverName, $introspect);
        } finally {
            $this->introspecting = false;
        }
    }

    private function log(string $sql, float $started): void
    {
        if (!$this->logQueries) {
            return;
        }
        $this->queryLog[] = ['sql' => $sql, 'ms' => round((microtime(true) - $started) * 1000, 2)];
    }
}

/* -------------------------------------------------------------------------
 * Manager — connection registry + database provisioning
 * ---------------------------------------------------------------------- */

final class Manager
{
    /** @var array<string,Connection> */
    private static array $connections = [];
    private static ?Connection $platform = null;

    /** The control-plane connection (tenants, plans, billing, audit…). */
    public static function platform(): Connection
    {
        if (self::$platform instanceof Connection) {
            return self::$platform;
        }

        $driver = self::driver();
        $config = Config::get('database.platform');

        if ($driver === 'sqlite') {
            $path = self::sqliteDir() . '/platform.sqlite';
            self::$platform = new Connection('sqlite:' . $path, null, null, [], 'sqlite');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['name']);
            self::$platform = new Connection($dsn, $config['user'], $config['pass'], [], 'mysql');
        }

        return self::$platform;
    }

    /** Connection for one tenant, cached per request. */
    public static function tenant(array $tenant): Connection
    {
        $slug = (string) ($tenant['slug'] ?? '');
        $key  = 'tenant:' . ($tenant['id'] ?? $slug);

        if (isset(self::$connections[$key])) {
            return self::$connections[$key];
        }

        $driver = self::driver();

        if ($driver === 'sqlite') {
            self::ensureSqliteDir();
            $file = self::tenantSqlitePath($tenant);
            if (!file_exists($file)) {
                touch($file);
            }
            $connection = new Connection('sqlite:' . $file, null, null, [], 'sqlite');
        } else {
            $config = Config::get('database.tenant');
            $name   = $tenant['db_name'] ?: self::tenantDatabaseName($slug);
            $dsn    = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $name);
            $connection = new Connection($dsn, $config['user'], $config['pass'], [], 'mysql');
        }

        return self::$connections[$key] = $connection;
    }

    public static function driver(): string
    {
        return Config::get('database.driver', 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
    }

    public static function tenantDatabaseName(string $slug): string
    {
        return \Resto\Support\Str::dbIdentifier(Config::get('database.tenant.prefix', 'restaurantos_t_'), $slug);
    }

    public static function sqliteDir(): string
    {
        return Config::path((string) Config::get('database.sqlite_path', 'storage'));
    }

    public static function tenantSqlitePath(array $tenant): string
    {
        $dir = self::sqliteDir() . '/tenants';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = $tenant['db_name'] ?: self::tenantDatabaseName((string) $tenant['slug']);
        return $dir . '/' . $name . '.sqlite';
    }

    private static function ensureSqliteDir(): void
    {
        foreach ([self::sqliteDir(), self::sqliteDir() . '/tenants'] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }

    /**
     * Create the physical database for a tenant.
     * SQLite: touches the file. MySQL: CREATE DATABASE.
     */
    public static function createTenantDatabase(array $tenant): void
    {
        if (self::driver() === 'sqlite') {
            self::ensureSqliteDir();
            $path = self::tenantSqlitePath($tenant);
            if (!file_exists($path)) {
                touch($path);
            }
            return;
        }

        // MySQL: connect without a database name and create it.
        $config = Config::get('database.tenant');
        $name   = $tenant['db_name'] ?: self::tenantDatabaseName((string) $tenant['slug']);
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new PDOException('Unsafe database name: ' . $name);
        }

        $root = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']),
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $root->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    /** Drop a tenant database (only used by the "delete tenant" flow). */
    public static function dropTenantDatabase(array $tenant): void
    {
        if (self::driver() === 'sqlite') {
            $path = self::tenantSqlitePath($tenant);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
            return;
        }

        $config = Config::get('database.tenant');
        $name   = $tenant['db_name'] ?: self::tenantDatabaseName((string) $tenant['slug']);
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new PDOException('Unsafe database name: ' . $name);
        }
        $root = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']),
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $root->exec("DROP DATABASE IF EXISTS `{$name}`");
    }

    /** Size of a tenant database in bytes (0 when unknown). */
    public static function tenantDatabaseSize(string $dbName): int
    {
        if (self::driver() === 'sqlite') {
            $dir    = Config::path((string) Config::get('storage.path', 'storage'));
            $path   = rtrim($dir, '/') . '/tenants/' . $dbName . '.sqlite';
            return is_file($path) ? (int) filesize($path) : 0;
        }

        try {
            $row = self::platformQuery(
                'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = ?',
                [$dbName]
            );
            return (int) ($row[0]['bytes'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function databaseExists(string $slug, ?string $dbName = null): bool
    {
        if (self::driver() === 'sqlite') {
            return file_exists(self::sqliteDir() . '/tenants/' . ($dbName ?: self::tenantDatabaseName($slug)) . '.sqlite');
        }
        $config = Config::get('database.tenant');
        $root   = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']),
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $root->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$dbName ?: self::tenantDatabaseName($slug)]);
        return (bool) $stmt->fetchColumn();
    }

    /** Convenience: run one statement against the platform database. */
    public static function platformQuery(string $sql, array $params = []): array
    {
        $stmt = self::platform()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
