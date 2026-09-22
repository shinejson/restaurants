<?php
/**
 * RestaurantOS — schema definitions.
 *
 * All tables are declared once, in a driver-neutral form, and rendered as
 * MySQL or SQLite DDL by Schema::render(). Both the control plane and the
 * per-tenant restaurant database are built from this file, which finally gives
 * the application something it never had: real, repeatable migrations.
 *
 * @package Resto\Database
 */

namespace Resto\Database;

use PDO;

/* -------------------------------------------------------------------------
 * Schema — neutral DDL builder
 * ---------------------------------------------------------------------- */

final class Schema
{
    /**
     * Create a table if it does not exist yet.
     *
     * @param array<string,array> $columns  column => definition
     * @param array{index?:array<array|string>,unique?:array<array|string>,foreign?:array} $options
     */
    public static function createTable(PDO $conn, string $driver, string $table, array $columns, array $options = []): void
    {
        $definitions = [];
        foreach ($columns as $name => $definition) {
            $definitions[] = self::column($name, (array) $definition, $driver, $options);
        }

        foreach ((array) ($options['unique'] ?? []) as $unique) {
            $columns_ = (array) $unique;
            $definitions[] = 'UNIQUE (' . implode(', ', array_map(
                static fn (string $column): string => self::quote($column, $driver),
                $columns_
            )) . ')';
        }

        $sql = "CREATE TABLE IF NOT EXISTS " . self::quote($table, $driver) . " (\n    "
            . implode(",\n    ", array_filter($definitions))
            . "\n)";

        if ($driver === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }

        $conn->exec($sql);

        // Secondary indexes are created separately so they can be added later.
        foreach ((array) ($options['index'] ?? []) as $index) {
            self::createIndex($conn, $driver, $table, (array) $index);
        }
    }

    public static function createIndex(PDO $conn, string $driver, string $table, array $index): void
    {
        $name    = (string) ($index['name'] ?? ($table . '_' . implode('_', $index['columns']) . '_idx'));
        $columns = array_map(
            static fn (string $column): string => self::quote($column, $driver),
            (array) $index['columns']
        );
        $kind    = !empty($index['unique']) ? 'UNIQUE INDEX' : 'INDEX';

        if ($driver === 'sqlite') {
            $conn->exec(
                "CREATE {$kind} IF NOT EXISTS " . self::quote($name, $driver)
                . ' ON ' . self::quote($table, $driver) . ' (' . implode(', ', $columns) . ')'
            );
            return;
        }

        // MySQL: skip when the index already exists (works on all versions).
        $check = $conn->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $check->execute([$table, $name]);
        if ((int) $check->fetchColumn() > 0) {
            return;
        }

        $conn->exec(
            "CREATE {$kind} `{$name}` ON `{$table}` (" . implode(', ', $columns) . ')'
        );
    }

    /** Render one column definition. */
    private static function column(string $name, array $definition, string $driver, array $tableOptions): string
    {
        $type = $definition[0] ?? 'string';
        $args = array_slice($definition, 1);

        // Separate named options from positional arguments.
        $options = [];
        foreach ($definition as $key => $value) {
            if (is_string($key)) {
                $options[$key] = $value;
            }
        }
        $length  = 0;
        $precision = 10;
        $scale   = 2;
        foreach ($args as $arg) {
            if (is_int($arg) && $length === 0) {
                $length = $arg;
            }
        }

        $nullable = !empty($options['null']);
        $default  = $options['default'] ?? null;

        if (in_array('null', $args, true)) {
            $nullable = true;
        }
        if (in_array('unique', $args, true)) {
            $options['unique'] = true;
        }
        if (in_array('index', $args, true)) {
            $options['index'] = true;
        }

        if ($type === 'id') {
            $sql = $driver === 'sqlite'
                ? self::quote($name, $driver) . ' INTEGER PRIMARY KEY AUTOINCREMENT'
                : self::quote($name, $driver) . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
            return $sql;
        }

        $sql = match ($type) {
            'int', 'integer' => self::quote($name, $driver) . ($driver === 'sqlite' ? ' INTEGER' : ' INT'),
            'bigint'         => self::quote($name, $driver) . ' BIGINT',
            'bool', 'boolean' => self::quote($name, $driver) . ($driver === 'sqlite' ? ' INTEGER' : ' TINYINT(1)'),
            'decimal', 'money' => self::quote($name, $driver) . ($driver === 'sqlite' ? ' NUMERIC' : " DECIMAL({$precision},{$scale})"),
            'text', 'longtext' => self::quote($name, $driver) . ' TEXT',
            'json'           => self::quote($name, $driver) . ($driver === 'sqlite' ? ' TEXT' : ' JSON'),
            'date'           => self::quote($name, $driver) . ' DATE',
            'datetime', 'timestamp' => self::quote($name, $driver) . ($driver === 'sqlite' ? ' TEXT' : ' DATETIME'),
            default          => self::quote($name, $driver) . ($driver === 'sqlite' ? ' TEXT' : ' VARCHAR(' . ($length ?: 191) . ')'),
        };

        if (!$nullable) {
            $sql .= ' NOT NULL';
        }
        if ($default !== null) {
            $sql .= ' DEFAULT ' . self::literal($default, $type);
        } elseif ($nullable) {
            $sql .= ' DEFAULT NULL';
        }

        if (!empty($options['unique'])) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }

    private static function literal(mixed $value, string $type): string
    {
        if (is_string($value) && strtoupper($value) === 'CURRENT_TIMESTAMP') {
            return 'CURRENT_TIMESTAMP';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'NULL';
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /**
     * Quote an identifier for the target driver.
     *
     * MySQL/MariaDB identifiers use backticks (the dialect the legacy app was
     * written in — the SQLite translator rewrites backticks to double quotes);
     * SQLite identifiers use double quotes. Bare double quotes are *not* valid
     * MySQL identifiers unless the server runs with ANSI_QUOTES.
     */
    public static function quote(string $identifier, string $driver = 'mysql'): string
    {
        $clean = str_replace(['"', '`'], '', $identifier);

        return $driver === 'sqlite' ? '"' . $clean . '"' : '`' . $clean . '`';
    }

    /** Does a table exist? Works on both drivers. */
    public static function hasTable(PDO $conn, string $table): bool
    {
        try {
            if ($conn instanceof Connection && $conn->driverName === 'sqlite') {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            } else {
                $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            }
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Add a column if it is missing (used by the legacy upgrade screens). */
    public static function hasColumn(PDO $conn, string $table, string $column): bool
    {
        try {
            if ($conn instanceof Connection && $conn->driverName === 'sqlite') {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?");
                $stmt->execute([$table, $column]);
                return (int) $stmt->fetchColumn() > 0;
            }
            $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function addColumn(PDO $conn, string $driver, string $table, string $column, array $definition): void
    {
        if (self::hasColumn($conn, $table, $column)) {
            return;
        }
        $rendered = self::column($column, $definition, $driver, []);
        // SQLite cannot add NOT NULL columns without a default.
        if ($driver === 'sqlite' && str_contains($rendered, 'NOT NULL') && !str_contains($rendered, 'DEFAULT')) {
            $rendered = str_replace(' NOT NULL', '', $rendered) . ' DEFAULT NULL';
        }
        $conn->exec('ALTER TABLE ' . self::quote($table, $driver) . ' ADD COLUMN ' . $rendered);
    }
}
