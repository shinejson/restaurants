<?php
/**
 * Role-Based Access Control (RBAC) — Storage & Helpers
 * ------------------------------------------------------------------
 * Roles live in the `roles` table and any deviation from the shipped
 * defaults is stored in `role_permissions` (one row per role/feature).
 *
 *  - No row for a role/feature  => the shipped default is used
 *  - A row with allowed = 1     => access granted
 *  - A row with allowed = 0     => access explicitly denied
 *
 * Keeping only the *differences* in the database means future updates to
 * the default matrix in this file still reach roles nobody customised.
 *
 * The Super Admin role (`admin`) is always full access and cannot be
 * locked out of the panel by mistake.
 */

/* ------------------------------------------------------------------
 * Catalogue: every feature that can be granted to a role
 * ------------------------------------------------------------------ */

/**
 * Feature catalogue grouped for display in the permissions matrix.
 *
 * label      - human readable name
 * description- what the feature unlocks
 * icon       - Font Awesome 6 class
 * enforced   - whether the code base currently calls require_permission()
 *              for the feature (reserved features are shown, but denying
 *              them has no effect yet)
 */
function rbac_permission_catalog()
{
    return [
        'Dashboard & Insights' => [
            'view_dashboard' => [
                'label' => 'View Dashboard',
                'description' => 'Open the admin dashboard (overview, sales, customer and product analytics).',
                'icon' => 'fa-gauge-high',
                'enforced' => false,
            ],
            'view_reports' => [
                'label' => 'View Reports',
                'description' => 'Open the reports module, run and export reports.',
                'icon' => 'fa-chart-line',
                'enforced' => true,
            ],
        ],
        'Orders & Service' => [
            'manage_orders' => [
                'label' => 'Manage Orders',
                'description' => 'Take, settle, void and print orders, manage tables and the cleaning log.',
                'icon' => 'fa-receipt',
                'enforced' => true,
            ],
            'view_orders' => [
                'label' => 'View Orders',
                'description' => 'Read-only access to the order list.',
                'icon' => 'fa-eye',
                'enforced' => false,
            ],
            'manage_events' => [
                'label' => 'Manage Events',
                'description' => 'Create events/packages and manage event bookings.',
                'icon' => 'fa-calendar-check',
                'enforced' => true,
            ],
        ],
        'Menu & Catalogue' => [
            'manage_menu' => [
                'label' => 'Manage Menu Items',
                'description' => 'Add, edit and retire food and drink items.',
                'icon' => 'fa-hamburger',
                'enforced' => true,
            ],
            'manage_categories' => [
                'label' => 'Manage Categories',
                'description' => 'Organise main categories and sub categories.',
                'icon' => 'fa-list',
                'enforced' => true,
            ],
        ],
        'Customers' => [
            'manage_customers' => [
                'label' => 'Manage Customers',
                'description' => 'View, edit and delete customer accounts.',
                'icon' => 'fa-user-group',
                'enforced' => true,
            ],
            'view_customers' => [
                'label' => 'View Customers',
                'description' => 'Read-only access to customer records.',
                'icon' => 'fa-address-book',
                'enforced' => false,
            ],
        ],
        'Administration' => [
            'manage_admins' => [
                'label' => 'Manage Admins & Roles',
                'description' => 'Create admin users and open this role & permission manager.',
                'icon' => 'fa-users-cog',
                'enforced' => true,
            ],
            'view_admins' => [
                'label' => 'View Admins',
                'description' => 'Read-only access to the admin user list.',
                'icon' => 'fa-id-badge',
                'enforced' => false,
            ],
            'manage_settings' => [
                'label' => 'Manage Settings',
                'description' => 'Restaurant settings, tax, printers/terminals, delivery zones and data import.',
                'icon' => 'fa-cog',
                'enforced' => true,
            ],
            'import_data' => [
                'label' => 'Import Data',
                'description' => 'Bulk import items, customers and other records.',
                'icon' => 'fa-file-import',
                'enforced' => true,
            ],
        ],
    ];
}

/** Flat feature map: feature key => metadata (metadata includes its group). */
function rbac_features()
{
    static $flat = null;
    if ($flat !== null) {
        return $flat;
    }

    $flat = [];
    foreach (rbac_permission_catalog() as $group => $features) {
        foreach ($features as $key => $meta) {
            $meta['group'] = $group;
            $meta['key'] = $key;
            $flat[$key] = $meta;
        }
    }

    return $flat;
}

/** All feature keys. */
function rbac_feature_keys()
{
    return array_keys(rbac_features());
}

/** Metadata for a single feature, or null. */
function rbac_feature($feature)
{
    $features = rbac_features();
    return isset($features[$feature]) ? $features[$feature] : null;
}

/* ------------------------------------------------------------------
 * Shipped defaults
 * ------------------------------------------------------------------ */

/**
 * Default permission matrix: feature => roles that get it out of the box.
 * Custom roles are granted nothing until an admin ticks their boxes.
 */
function rbac_default_permissions()
{
    return [
        'view_dashboard' => ['staff', 'manager', 'admin'],

        'manage_orders' => ['staff', 'manager', 'admin'],
        'view_orders' => ['staff', 'manager', 'admin'],

        'manage_menu' => ['manager', 'admin'],
        'manage_categories' => ['manager', 'admin'],

        'manage_events' => ['manager', 'admin'],

        'manage_customers' => ['manager', 'admin'],
        'view_customers' => ['manager', 'admin'],

        'manage_admins' => ['admin'],
        'view_admins' => ['admin'],

        'view_reports' => ['manager', 'admin'],

        'manage_settings' => ['admin'],

        'import_data' => ['admin'],
    ];
}

/** Built-in roles shipped with the app. */
function rbac_default_roles()
{
    return [
        'admin' => [
            'id' => null,
            'slug' => 'admin',
            'name' => 'Super Admin',
            'description' => 'Unrestricted access to every part of the system.',
            'icon' => 'fa-user-shield',
            'color' => '#dc2626',
            'is_system' => 1,
        ],
        'manager' => [
            'id' => null,
            'slug' => 'manager',
            'name' => 'Manager',
            'description' => 'Day to day operations: orders, menu, events, customers and reports.',
            'icon' => 'fa-user-tie',
            'color' => '#f59e0b',
            'is_system' => 1,
        ],
        'staff' => [
            'id' => null,
            'slug' => 'staff',
            'name' => 'Staff',
            'description' => 'Front of house access: dashboard and order handling.',
            'icon' => 'fa-user',
            'color' => '#64748b',
            'is_system' => 1,
        ],
    ];
}

/** Roles that can never be deleted and whose permissions are locked. */
function rbac_locked_roles()
{
    return ['admin'];
}

function rbac_is_locked_role($slug)
{
    return in_array((string) $slug, rbac_locked_roles(), true);
}

/** Icon picker options. */
function rbac_icon_options()
{
    return [
        'fa-user', 'fa-user-shield', 'fa-user-tie', 'fa-user-cog', 'fa-user-gear',
        'fa-users', 'fa-users-gear', 'fa-id-badge', 'fa-user-ninja', 'fa-user-graduate',
        'fa-cash-register', 'fa-utensils', 'fa-kitchen-set', 'fa-clipboard-list',
        'fa-truck-fast', 'fa-chart-line', 'fa-headset', 'fa-shield-halved',
        'fa-bell-concierge', 'fa-concierge-bell',
    ];
}

/** Colour palette offered by the role editor. */
function rbac_color_options()
{
    return [
        '#dc2626' => 'Red',
        '#f59e0b' => 'Amber',
        '#10b981' => 'Green',
        '#3b82f6' => 'Blue',
        '#8b5cf6' => 'Purple',
        '#ec4899' => 'Pink',
        '#0ea5e9' => 'Sky',
        '#64748b' => 'Slate',
    ];
}

/* ------------------------------------------------------------------
 * Storage plumbing
 * ------------------------------------------------------------------ */

/** Current PDO connection (if the page bootstrapped one). */
function rbac_db()
{
    global $conn;
    return (isset($conn) && $conn instanceof PDO) ? $conn : null;
}

/**
 * Create the RBAC tables (and seed the built-in roles) when missing.
 * Returns true when the tables are ready for use.
 */
function rbac_ensure_schema($conn = null, $force = false)
{
    static $ready = null;
    if ($force) {
        $ready = null;
    }
    if ($ready !== null) {
        return $ready;
    }

    if (!$conn instanceof PDO) {
        $conn = rbac_db();
    }
    if (!$conn) {
        return $ready = false;
    }

    // Fast path: both tables already exist (two very cheap reads).
    try {
        $role_count = (int) $conn->query("SELECT COUNT(*) FROM roles")->fetchColumn();
        $conn->query("SELECT COUNT(*) FROM role_permissions")->fetchColumn();

        if ($role_count === 0) {
            rbac_seed_roles($conn);
        }

        return $ready = true;
    } catch (PDOException $e) {
        // Tables are missing (or unreadable) — create them below.
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(20) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            icon VARCHAR(50) NOT NULL DEFAULT 'fa-user',
            color VARCHAR(20) NOT NULL DEFAULT '#64748b',
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_role_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_slug VARCHAR(20) NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            allowed TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_role_permission (role_slug, permission_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        rbac_seed_roles($conn);

        return $ready = true;
    } catch (PDOException $e) {
        return $ready = false;
    }
}

/** Insert the built-in roles when they are missing. */
function rbac_seed_roles($conn)
{
    $seed = $conn->prepare("INSERT IGNORE INTO roles (slug, name, description, icon, color, is_system) VALUES (?, ?, ?, ?, ?, 1)");
    foreach (rbac_default_roles() as $slug => $role) {
        $seed->execute([$slug, $role['name'], $role['description'], $role['icon'], $role['color']]);
    }
}

/** Have the RBAC tables been created successfully? */
function rbac_ready()
{
    return rbac_ensure_schema();
}

/** How many admin users hold each role. */
function rbac_role_user_counts($force = false)
{
    static $counts = null;
    if ($force) {
        $counts = null;
    }
    if ($counts !== null) {
        return $counts;
    }

    $counts = [];
    $conn = rbac_db();
    if (!$conn) {
        return $counts;
    }

    try {
        $rows = $conn->query("SELECT role, COUNT(*) AS total FROM admins GROUP BY role")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $slug = (string) $row['role'];
            $counts[$slug] = (int) $row['total'];
        }
    } catch (PDOException $e) {
        $counts = [];
    }

    return $counts;
}

/**
 * Every role available on the system: shipped roles first, then custom
 * roles, then any "orphan" role value still stored on an admin account.
 *
 * @param bool $force Skip the per-request cache.
 */
function rbac_roles($force = false)
{
    static $cache = null;
    if ($force) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }

    $roles = rbac_default_roles();

    $conn = rbac_db();
    if ($conn && rbac_ensure_schema($conn)) {
        try {
            $rows = $conn->query("SELECT * FROM roles ORDER BY is_system DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $slug = (string) $row['slug'];
                $roles[$slug] = [
                    'id' => (int) $row['id'],
                    'slug' => $slug,
                    'name' => $row['name'],
                    'description' => (string) $row['description'],
                    'icon' => $row['icon'] ?: 'fa-user',
                    'color' => $row['color'] ?: '#64748b',
                    'is_system' => (int) $row['is_system'],
                ];
            }
        } catch (PDOException $e) {
            // Fall back to the shipped roles only.
        }
    }

    // Roles still assigned to accounts but no longer defined anywhere.
    foreach (rbac_role_user_counts() as $slug => $count) {
        if ($slug === '' || isset($roles[$slug])) {
            continue;
        }
        $roles[$slug] = [
            'id' => null,
            'slug' => $slug,
            'name' => ucfirst(str_replace('_', ' ', $slug)),
            'description' => 'Legacy role value found on existing admin accounts.',
            'icon' => 'fa-user-clock',
            'color' => '#94a3b8',
            'is_system' => 0,
            'legacy' => true,
        ];
    }

    foreach ($roles as $slug => $role) {
        $roles[$slug]['users'] = isset(rbac_role_user_counts()[$slug]) ? rbac_role_user_counts()[$slug] : 0;
    }

    return $cache = $roles;
}

/**
 * Number of admin accounts holding a role (defaults to the Super Admin role).
 * Used to keep the panel from ever deleting the last Super Admin.
 */
function rbac_role_count($slug = 'admin')
{
    $counts = rbac_role_user_counts();
    return isset($counts[$slug]) ? (int) $counts[$slug] : 0;
}

/** Number of Super Admin accounts in total. */
function rbac_super_admin_count()
{
    return rbac_role_count('admin');
}

/** Display label for a role slug. */
function rbac_role_name($slug)
{
    $roles = rbac_roles();
    $slug = (string) $slug;

    if (isset($roles[$slug])) {
        return $roles[$slug]['name'];
    }

    return $slug === '' ? 'Unassigned' : ucfirst(str_replace('_', ' ', $slug));
}

/** Full definition for a role slug (falls back to a synthetic entry). */
function rbac_role($slug)
{
    $roles = rbac_roles();
    $slug = (string) $slug;

    if (isset($roles[$slug])) {
        return $roles[$slug];
    }

    return [
        'id' => null,
        'slug' => $slug,
        'name' => rbac_role_name($slug),
        'description' => '',
        'icon' => 'fa-user',
        'color' => '#94a3b8',
        'is_system' => 0,
        'users' => 0,
    ];
}

/** Stored overrides: [role_slug][feature] => bool. */
function rbac_permission_overrides($force = false)
{
    static $overrides = null;
    if ($force) {
        $overrides = null;
    }
    if ($overrides !== null) {
        return $overrides;
    }

    $overrides = [];
    $conn = rbac_db();
    if (!$conn || !rbac_ensure_schema($conn)) {
        return $overrides;
    }

    try {
        $rows = $conn->query("SELECT role_slug, permission_key, allowed FROM role_permissions")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $overrides[(string) $row['role_slug']][(string) $row['permission_key']] = (bool) $row['allowed'];
        }
    } catch (PDOException $e) {
        $overrides = [];
    }

    return $overrides;
}

/**
 * Effective permissions for a role: [feature => bool].
 */
function rbac_role_permissions($slug)
{
    $slug = (string) $slug;
    $defaults = rbac_default_permissions();
    $overrides = rbac_permission_overrides();
    $role_overrides = isset($overrides[$slug]) ? $overrides[$slug] : [];

    $permissions = [];
    foreach (rbac_feature_keys() as $feature) {
        if (rbac_is_locked_role($slug)) {
            $permissions[$feature] = true;
            continue;
        }
        if (array_key_exists($feature, $role_overrides)) {
            $permissions[$feature] = (bool) $role_overrides[$feature];
            continue;
        }
        $allowed = isset($defaults[$feature]) ? $defaults[$feature] : [];
        $permissions[$feature] = in_array($slug, $allowed, true);
    }

    return $permissions;
}

/** Effective matrix for every role: [role_slug][feature] => bool. */
function rbac_permission_matrix()
{
    $matrix = [];
    foreach (rbac_roles() as $slug => $role) {
        $matrix[$slug] = rbac_role_permissions($slug);
    }
    return $matrix;
}

/** Can a specific role use a feature? */
function rbac_role_can($slug, $feature)
{
    if (rbac_is_locked_role($slug)) {
        return true;
    }

    $overrides = rbac_permission_overrides();
    if (isset($overrides[$slug]) && array_key_exists($feature, $overrides[$slug])) {
        return (bool) $overrides[$slug][$feature];
    }

    $defaults = rbac_default_permissions();
    return in_array($slug, isset($defaults[$feature]) ? $defaults[$feature] : [], true);
}

/** How many features a role can currently use. */
function rbac_role_permission_count($slug)
{
    return count(array_filter(rbac_role_permissions($slug)));
}

/* ------------------------------------------------------------------
 * Mutations
 * ------------------------------------------------------------------ */

/**
 * Persist the permission matrix posted from the UI.
 *
 * @param array $posted      [role_slug => [feature => any truthy value]]
 * @param array $role_slugs  Roles shown in the matrix. Needed because a
 *                           role with every box cleared posts nothing at all.
 * @return array [saved => int, errors => string[]]
 */
function rbac_save_permissions(array $posted, array $role_slugs = [])
{
    $saved = 0;
    $errors = [];
    $conn = rbac_db();

    if (!$conn) {
        return ['saved' => 0, 'errors' => ['No database connection available.']];
    }
    if (!rbac_ensure_schema($conn)) {
        return ['saved' => 0, 'errors' => ['The roles table could not be created. Check database permissions.']];
    }

    $roles = rbac_roles();
    $defaults = rbac_default_permissions();
    $features = rbac_feature_keys();

    // Every role the matrix submitted (locked roles are never editable).
    $slugs = [];
    foreach (array_merge($role_slugs, array_keys($posted)) as $slug) {
        $slug = (string) $slug;
        if (isset($roles[$slug]) && !rbac_is_locked_role($slug)) {
            $slugs[$slug] = true;
        }
    }

    if (empty($slugs)) {
        return ['saved' => 0, 'errors' => ['No editable roles were submitted.']];
    }

    // Work out the rows that actually deviate from the shipped defaults.
    $rows = [];
    foreach (array_keys($slugs) as $slug) {
        $granted = (isset($posted[$slug]) && is_array($posted[$slug])) ? $posted[$slug] : [];
        foreach ($features as $feature) {
            $wants = !empty($granted[$feature]);
            $default = in_array($slug, isset($defaults[$feature]) ? $defaults[$feature] : [], true);
            if ($wants === $default) {
                continue; // matches the shipped default — keep the table lean
            }
            $rows[] = [$slug, $feature, $wants ? 1 : 0];
        }
    }

    try {
        $conn->beginTransaction();

        if (!empty($slugs)) {
            $placeholders = implode(',', array_fill(0, count($slugs), '?'));
            $delete = $conn->prepare("DELETE FROM role_permissions WHERE role_slug IN ($placeholders)");
            $delete->execute(array_keys($slugs));
        }

        if (!empty($rows)) {
            $insert = $conn->prepare("INSERT INTO role_permissions (role_slug, permission_key, allowed) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)");
            foreach ($rows as $row) {
                $insert->execute($row);
                $saved++;
            }
        }

        $conn->commit();
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['saved' => 0, 'errors' => ['Could not save permissions: ' . $e->getMessage()]];
    }

    rbac_roles(true);
    rbac_permission_overrides(true);

    return ['saved' => $saved, 'errors' => $errors];
}

/** Drop every override for a role so it follows the shipped defaults again. */
function rbac_reset_role_permissions($slug)
{
    $conn = rbac_db();
    if (!$conn || !rbac_ensure_schema($conn)) {
        return false;
    }

    try {
        $stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_slug = ?");
        $stmt->execute([(string) $slug]);
    } catch (PDOException $e) {
        return false;
    }

    rbac_roles(true);
    rbac_permission_overrides(true);

    return true;
}

/** Normalise + validate a role slug (must fit admins.role VARCHAR(20)). */
function rbac_sanitize_slug($slug)
{
    $slug = strtolower(trim((string) $slug));
    $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug);
    $slug = trim((string) $slug, '_');

    return (string) $slug;
}

function rbac_slug_is_valid($slug)
{
    return (bool) preg_match('/^[a-z][a-z0-9_]{1,15}$/', (string) $slug);
}

/**
 * Create a new custom role.
 *
 * @return array [ok => bool, error => string|null, slug => string]
 */
function rbac_create_role(array $data)
{
    $conn = rbac_db();
    if (!$conn || !rbac_ensure_schema($conn)) {
        return ['ok' => false, 'error' => 'The roles table is unavailable.', 'slug' => ''];
    }

    $slug = rbac_sanitize_slug(isset($data['slug']) ? $data['slug'] : '');
    $name = trim((string) (isset($data['name']) ? $data['name'] : ''));
    $name = strip_tags($name);
    $description = strip_tags(trim((string) (isset($data['description']) ? $data['description'] : '')));
    $icon = (string) (isset($data['icon']) ? $data['icon'] : 'fa-user');
    $color = (string) (isset($data['color']) ? $data['color'] : '#64748b');

    if ($slug === '' && $name !== '') {
        $slug = rbac_sanitize_slug($name);
    }
    if (!rbac_slug_is_valid($slug)) {
        return ['ok' => false, 'error' => 'Role key must start with a letter and contain only lowercase letters, numbers or underscores (2-16 characters).', 'slug' => $slug];
    }
    if (strlen($name) < 2) {
        return ['ok' => false, 'error' => 'Please enter a role name with at least 2 characters.', 'slug' => $slug];
    }

    $roles = rbac_roles();
    if (isset($roles[$slug])) {
        return ['ok' => false, 'error' => 'A role with the key "' . $slug . '" already exists.', 'slug' => $slug];
    }

    if (!in_array($icon, rbac_icon_options(), true)) {
        $icon = 'fa-user';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#64748b';
    }

    try {
        $stmt = $conn->prepare("INSERT INTO roles (slug, name, description, icon, color, is_system) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->execute([$slug, $name, substr($description, 0, 255), $icon, strtolower($color)]);
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Could not create role: ' . $e->getMessage(), 'slug' => $slug];
    }

    rbac_roles(true);

    return ['ok' => true, 'error' => null, 'slug' => $slug];
}

/**
 * Update an existing role. System roles keep their key; custom roles may
 * be re-keyed, in which case every assigned admin is migrated too.
 *
 * @return array [ok => bool, error => string|null, slug => string]
 */
function rbac_update_role($current_slug, array $data)
{
    $current_slug = (string) $current_slug;
    $roles = rbac_roles();
    if (!isset($roles[$current_slug])) {
        return ['ok' => false, 'error' => 'That role no longer exists.', 'slug' => $current_slug];
    }

    $is_system = !empty($roles[$current_slug]['is_system']);
    $legacy = !empty($roles[$current_slug]['legacy']);
    $new_slug = $is_system ? $current_slug : rbac_sanitize_slug(isset($data['slug']) ? $data['slug'] : $current_slug);
    if ($new_slug === '') {
        $new_slug = $current_slug;
    }

    $name = strip_tags(trim((string) (isset($data['name']) ? $data['name'] : '')));
    $description = substr(strip_tags(trim((string) (isset($data['description']) ? $data['description'] : ''))), 0, 255);
    $icon = (string) (isset($data['icon']) ? $data['icon'] : $roles[$current_slug]['icon']);
    $color = (string) (isset($data['color']) ? $data['color'] : $roles[$current_slug]['color']);

    if (strlen($name) < 2) {
        return ['ok' => false, 'error' => 'Please enter a role name with at least 2 characters.', 'slug' => $current_slug];
    }
    if (!$is_system && !rbac_slug_is_valid($new_slug)) {
        return ['ok' => false, 'error' => 'Role key must start with a letter and contain only lowercase letters, numbers or underscores (2-16 characters).', 'slug' => $current_slug];
    }
    if (!$is_system && $new_slug !== $current_slug && isset($roles[$new_slug])) {
        return ['ok' => false, 'error' => 'A role with the key "' . $new_slug . '" already exists.', 'slug' => $current_slug];
    }
    if (!in_array($icon, rbac_icon_options(), true)) {
        $icon = 'fa-user';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#64748b';
    }

    $conn = rbac_db();
    if (!$conn || !rbac_ensure_schema($conn)) {
        return ['ok' => false, 'error' => 'The roles table is unavailable.', 'slug' => $current_slug];
    }

    try {
        $conn->beginTransaction();

        if ($legacy) {
            // Only referenced by admin accounts — give it a real row now.
            $insert = $conn->prepare("INSERT INTO roles (slug, name, description, icon, color, is_system) VALUES (?, ?, ?, ?, ?, 0)");
            $insert->execute([$new_slug, $name, $description, $icon, strtolower($color)]);
            if ($new_slug !== $current_slug) {
                $move = $conn->prepare("UPDATE admins SET role = ? WHERE role = ?");
                $move->execute([$new_slug, $current_slug]);
                $movePerms = $conn->prepare("UPDATE role_permissions SET role_slug = ? WHERE role_slug = ?");
                $movePerms->execute([$new_slug, $current_slug]);
            }
        } else {
            $update = $conn->prepare("UPDATE roles SET slug = ?, name = ?, description = ?, icon = ?, color = ? WHERE slug = ?");
            $update->execute([$new_slug, $name, $description, $icon, strtolower($color), $current_slug]);

            if ($new_slug !== $current_slug) {
                $move = $conn->prepare("UPDATE admins SET role = ? WHERE role = ?");
                $move->execute([$new_slug, $current_slug]);
                $movePerms = $conn->prepare("UPDATE role_permissions SET role_slug = ? WHERE role_slug = ?");
                $movePerms->execute([$new_slug, $current_slug]);
            }
        }

        $conn->commit();
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not update role: ' . $e->getMessage(), 'slug' => $current_slug];
    }

    rbac_role_user_counts(true);
    rbac_roles(true);

    return ['ok' => true, 'error' => null, 'slug' => $new_slug];
}

/**
 * Delete a custom role. If admins still hold it they must be moved to
 * another role first (pass $reassign_to).
 *
 * @return array [ok => bool, error => string|null, moved => int]
 */
function rbac_delete_role($slug, $reassign_to = '')
{
    $slug = (string) $slug;
    $roles = rbac_roles();

    if (!isset($roles[$slug])) {
        return ['ok' => false, 'error' => 'That role no longer exists.', 'moved' => 0];
    }
    if (!empty($roles[$slug]['is_system'])) {
        return ['ok' => false, 'error' => rbac_role_name($slug) . ' is a built-in role and cannot be deleted.', 'moved' => 0];
    }
    if (!empty($roles[$slug]['legacy'])) {
        return ['ok' => false, 'error' => 'This role key is still stored on admin accounts — rename it instead of deleting it.', 'moved' => 0];
    }
    if (rbac_is_locked_role($slug)) {
        return ['ok' => false, 'error' => 'This role is locked.', 'moved' => 0];
    }

    $holders = isset($roles[$slug]['users']) ? (int) $roles[$slug]['users'] : 0;
    $reassign_to = (string) $reassign_to;
    $moved = 0;

    if ($holders > 0) {
        if ($reassign_to === '' || $reassign_to === $slug || !isset($roles[$reassign_to])) {
            return ['ok' => false, 'error' => 'Move the ' . $holders . ' admin(s) using this role to another role before deleting it.', 'moved' => 0];
        }
    } else {
        $reassign_to = '';
    }

    $conn = rbac_db();
    if (!$conn || !rbac_ensure_schema($conn)) {
        return ['ok' => false, 'error' => 'The roles table is unavailable.', 'moved' => 0];
    }

    try {
        $conn->beginTransaction();

        if ($reassign_to !== '') {
            $move = $conn->prepare("UPDATE admins SET role = ? WHERE role = ?");
            $move->execute([$reassign_to, $slug]);
            $moved = $move->rowCount();
        }

        $deletePerms = $conn->prepare("DELETE FROM role_permissions WHERE role_slug = ?");
        $deletePerms->execute([$slug]);

        $delete = $conn->prepare("DELETE FROM roles WHERE slug = ?");
        $delete->execute([$slug]);

        $conn->commit();
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not delete role: ' . $e->getMessage(), 'moved' => 0];
    }

    rbac_role_user_counts(true);
    rbac_roles(true);
    rbac_permission_overrides(true);

    return ['ok' => true, 'error' => null, 'moved' => $moved];
}
