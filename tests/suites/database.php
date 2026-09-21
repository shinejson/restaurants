<?php
/**
 * Database abstraction tests: dialect translation, PDO compatibility and
 * tenant isolation.
 */
use Resto\Database\Connection;
use Resto\Database\Manager;

return function (TestRunner $t): void {
    $t->suite('database', function (TestRunner $t) {
        $db = new Connection('sqlite::memory:', null, null, [], 'sqlite');

        $t->ok($db instanceof PDO, 'Connection is still a PDO instance (legacy instanceof checks)');

        $db->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, qty INT DEFAULT 0, created_at TEXT)');

        // --- translation -------------------------------------------------
        $t->ok(
            str_contains(\Resto\Database\Translator::translate('SELECT * FROM `widgets` WHERE id = ?', 'sqlite'), '"widgets"'),
            'backticks are rewritten to double quotes'
        );

        $t->ok(
            str_contains(\Resto\Database\Translator::translate('SHOW TABLES LIKE \'widgets\'', 'sqlite'), 'sqlite_master'),
            'SHOW TABLES LIKE is translated'
        );

        $t->ok(
            str_contains(\Resto\Database\Translator::translate("SELECT * FROM widgets WHERE created_at > NOW()", 'sqlite'), "datetime('now')"),
            'NOW() is translated'
        );

        $t->ok(
            str_contains(
                \Resto\Database\Translator::translate("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m FROM widgets", 'sqlite'),
                "strftime('%Y-%m'"
            ),
            'DATE_FORMAT is translated'
        );

        $t->same(
            'SELECT * FROM widgets',
            \Resto\Database\Translator::translate('SELECT * FROM widgets', 'mysql'),
            'MySQL statements pass through untouched'
        );

        // --- legacy patterns ---------------------------------------------
        $stmt = $db->prepare('INSERT IGNORE INTO widgets (name, qty) VALUES (?, ?)');
        $stmt->execute(['alpha', 5]);
        $stmt->execute(['alpha', 9]); // must be ignored
        $t->same(1, (int) $db->query('SELECT COUNT(*) FROM widgets')->fetchColumn(), 'INSERT IGNORE does not create duplicates');

        $stmt = $db->query("SHOW TABLES LIKE 'widgets'");
        $t->same(1, $stmt->rowCount(), 'rowCount() works on a translated SELECT');

        $stmt = $db->query('SHOW COLUMNS FROM widgets');
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $t->ok(in_array('qty', $columns, true), 'SHOW COLUMNS returns column names');

        $stmt = $db->prepare("INSERT INTO widgets (name, qty, created_at) VALUES (?, ?, NOW())
                              ON DUPLICATE KEY UPDATE qty = VALUES(qty)");
        $stmt->execute(['alpha', 12]);
        $t->same(12, (int) $db->query("SELECT qty FROM widgets WHERE name = 'alpha'")->fetchColumn(), 'ON DUPLICATE KEY UPDATE becomes an upsert');

        // --- fetch modes ---------------------------------------------------
        $stmt = $db->prepare('SELECT name, qty FROM widgets');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $t->same(['name' => 'alpha', 'qty' => 12], $rows[0] ?? null, 'FETCH_ASSOC rows');
        $t->same(1, $stmt->rowCount(), 'rowCount() after fetchAll()');

        $stmt = $db->prepare('SELECT id, name FROM widgets');
        $stmt->execute();
        $t->same(['alpha'], $stmt->fetchAll(PDO::FETCH_COLUMN, 1), 'FETCH_COLUMN honours the column index');

        $stmt = $db->prepare('SELECT name, qty FROM widgets');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $t->same('alpha', $row[0] ?? null, 'FETCH_NUM rows');

        $stmt = $db->prepare('SELECT name, qty FROM widgets');
        $stmt->execute();
        $pairs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $t->same(12, (int) ($pairs['alpha'] ?? 0), 'FETCH_KEY_PAIR');

        // --- rowCount on writes -------------------------------------------
        $t->same(1, $db->exec("UPDATE widgets SET qty = 3 WHERE name = 'alpha'"), 'exec returns affected rows');

        // --- tenant isolation ---------------------------------------------
        $demo = ['id' => 1, 'slug' => 'demo', 'db_name' => 'restaurantos_t_demo'];
        $one  = Manager::tenant($demo);
        $t->ok($one instanceof Connection, 'Manager::tenant() returns a connection');
        $t->same($one, Manager::tenant($demo), 'tenant connections are memoised per request');

        $other = Manager::tenant(['id' => 2, 'slug' => 'other-co', 'db_name' => 'restaurantos_t_other_co']);
        $t->ok($one !== $other, 'different tenants get different connections');

        $t->same('restaurantos_t_other_co', Manager::tenantDatabaseName('other-co'), 'slug => database name is sanitised');
    });
};
