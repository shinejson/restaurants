<?php
/**
 * User Roles tab for admin/admins/index.php
 *
 * Expects the parent page to have bootstrapped:
 *   $conn, $roles, $stats, $can_edit_roles, $rbac_ready, $_SESSION['csrf_token']
 */
if (!defined('ADMINS_PAGE')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

$perm_catalog = rbac_permission_catalog();
$perm_matrix = rbac_permission_matrix();
$role_list = rbac_roles();
$feature_total = count(rbac_feature_keys());
$editable = (bool) $can_edit_roles;
$csrf = htmlspecialchars($_SESSION['csrf_token']);

$form_open = $editable
    ? '<form method="POST" id="permForm">'
    : '<div id="permForm">';
$form_close = $editable ? '</form>' : '</div>';
?>

<style>
    .roles-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }

    .roles-head h2 {
        margin: 0 0 0.25rem;
        font-size: 1.35rem;
        font-weight: 800;
        color: var(--text-main);
    }

    .roles-head p {
        margin: 0;
        color: var(--text-muted);
        font-size: 0.92rem;
    }

    .roles-head-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.55rem;
        align-items: center;
    }

    .roles-head-actions form {
        display: inline-flex;
        gap: 0.5rem;
        align-items: center;
        margin: 0;
    }

    .roles-notice {
        display: flex;
        align-items: flex-start;
        gap: 0.6rem;
        background: rgba(59, 130, 246, 0.08);
        border: 1px solid rgba(59, 130, 246, 0.25);
        color: #1d4ed8;
        border-radius: 12px;
        padding: 0.85rem 1.1rem;
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 1.25rem;
    }

    /* ---------- Role cards ---------- */
    .role-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1rem;
        margin-bottom: 1.75rem;
    }

    .role-card {
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 1.1rem 1.15rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        position: relative;
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .role-card::before {
        content: "";
        position: absolute;
        inset: 0 auto 0 0;
        width: 4px;
        background: var(--role-color, #94a3b8);
    }

    .role-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 40px -28px rgba(15, 23, 42, 0.55);
    }

    .role-card-top {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .role-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
        flex-shrink: 0;
        background: var(--role-color-soft, rgba(100, 116, 139, 0.12));
        color: var(--role-color, #64748b);
    }

    .role-name {
        font-weight: 800;
        color: var(--text-main);
        font-size: 1rem;
        line-height: 1.2;
    }

    .role-slug {
        display: inline-block;
        margin-top: 0.15rem;
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--text-muted);
        background: var(--light-bg);
        border-radius: 6px;
        padding: 0.1rem 0.4rem;
    }

    .role-flags {
        margin-left: auto;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 0.25rem;
    }

    .role-flag {
        font-size: 0.66rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 0.15rem 0.45rem;
        border-radius: 999px;
        background: rgba(100, 116, 139, 0.14);
        color: #475569;
    }

    .role-flag.locked {
        background: rgba(220, 38, 38, 0.12);
        color: #b91c1c;
    }

    .role-flag.system {
        background: rgba(59, 130, 246, 0.12);
        color: #1d4ed8;
    }

    .role-flag.legacy {
        background: rgba(245, 158, 11, 0.16);
        color: #b45309;
    }

    .role-desc {
        margin: 0;
        font-size: 0.87rem;
        color: var(--text-muted);
        line-height: 1.45;
        min-height: 2.4em;
    }

    .role-stats {
        display: flex;
        flex-wrap: wrap;
        gap: 0.9rem;
        font-size: 0.83rem;
        color: var(--text-muted);
        font-weight: 600;
    }

    .role-stats strong {
        color: var(--text-main);
        font-weight: 800;
    }

    .role-card-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem;
        margin-top: auto;
    }

    .role-danger {
        margin-left: auto;
    }

    .role-danger summary {
        list-style: none;
        cursor: pointer;
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--text-muted);
        padding: 0.45rem 0.6rem;
        border-radius: 8px;
    }

    .role-danger summary::-webkit-details-marker {
        display: none;
    }

    .role-danger summary:hover {
        background: var(--light-bg);
        color: #b91c1c;
    }

    .role-danger[open] summary {
        color: #b91c1c;
    }

    .role-danger form {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
        margin-top: 0.5rem;
    }

    .role-danger select {
        max-width: 100%;
    }

    /* ---------- Role editor ---------- */
    .role-editor-card {
        background: var(--white);
        border: 1px solid var(--primary-color);
        border-radius: 14px;
        padding: 1.25rem 1.35rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 20px 45px -35px rgba(255, 107, 53, 0.9);
    }

    .role-editor-card[hidden] {
        display: none;
    }

    .role-editor-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .role-editor-head h3 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--text-main);
    }

    .role-editor-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 0 1rem;
    }

    .role-editor-grid .form-group {
        margin-bottom: 1rem;
    }

    .role-editor-grid .role-editor-wide {
        grid-column: 1 / -1;
    }

    .role-editor-grid small {
        display: block;
        margin-top: 0.35rem;
        color: var(--text-muted);
        font-size: 0.78rem;
    }

    .role-editor-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.75rem;
    }

    .role-editor-hint {
        font-size: 0.82rem;
        color: var(--text-muted);
        font-weight: 600;
    }

    /* ---------- Permission matrix ---------- */
    .perm-card {
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        overflow: hidden;
    }

    .perm-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        padding: 1.15rem 1.25rem;
        border-bottom: 1px solid var(--border-color);
    }

    .perm-head h3 {
        margin: 0 0 0.2rem;
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--text-main);
    }

    .perm-head p {
        margin: 0;
        font-size: 0.87rem;
        color: var(--text-muted);
    }

    .perm-tools {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.6rem;
    }

    .perm-dirty {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: rgba(245, 158, 11, 0.14);
        color: #b45309;
        font-size: 0.78rem;
        font-weight: 800;
        padding: 0.35rem 0.7rem;
        border-radius: 999px;
    }

    .perm-dirty[hidden] {
        display: none;
    }

    .perm-scroll {
        max-height: 620px;
        overflow: auto;
    }

    .perm-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        min-width: 680px;
    }

    .perm-table th,
    .perm-table td {
        padding: 0.7rem 0.85rem;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.86rem;
        vertical-align: middle;
    }

    .perm-table thead th {
        position: sticky;
        top: 0;
        z-index: 3;
        background: var(--light-bg);
        text-align: center;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-muted);
        font-weight: 800;
    }

    .perm-table th.perm-feature-col {
        text-align: left;
    }

    .perm-role-head {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.35rem;
    }

    .perm-role-name {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-weight: 800;
        text-transform: none;
        letter-spacing: 0;
        font-size: 0.82rem;
    }

    .perm-col-actions {
        display: inline-flex;
        gap: 0.25rem;
    }

    .perm-mini {
        border: 1px solid var(--border-color);
        background: var(--white);
        color: var(--text-muted);
        border-radius: 6px;
        font-size: 0.68rem;
        font-weight: 800;
        padding: 0.1rem 0.4rem;
        cursor: pointer;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .perm-mini:hover {
        border-color: var(--primary-color);
        color: var(--primary-color);
    }

    .perm-locked-note {
        font-size: 0.68rem;
        font-weight: 800;
        color: #b91c1c;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .perm-group-row td {
        background: rgba(15, 23, 42, 0.04);
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--text-muted);
    }

    .perm-feature-cell {
        position: sticky;
        left: 0;
        background: var(--white);
        z-index: 2;
        min-width: 300px;
        max-width: 420px;
    }

    tr:hover .perm-feature-cell {
        background: rgba(249, 115, 22, 0.03);
    }

    .perm-feature-name {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        font-weight: 700;
        color: var(--text-main);
    }

    .perm-feature-name i {
        color: var(--text-muted);
        font-size: 0.8rem;
        width: 14px;
        text-align: center;
    }

    .perm-flag {
        font-size: 0.64rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #6b7280;
        background: rgba(107, 114, 128, 0.12);
        border-radius: 999px;
        padding: 0.1rem 0.4rem;
    }

    .perm-feature-desc {
        font-size: 0.78rem;
        color: var(--text-muted);
        margin-top: 0.15rem;
        line-height: 1.35;
        white-space: normal;
    }

    .perm-cell {
        text-align: center;
    }

    /* Toggle switch */
    .switch {
        position: relative;
        display: inline-block;
        width: 40px;
        height: 22px;
        vertical-align: middle;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .switch .slider {
        position: absolute;
        cursor: pointer;
        inset: 0;
        background: #cbd5e1;
        border-radius: 999px;
        transition: background 0.2s ease;
    }

    .switch .slider::before {
        content: "";
        position: absolute;
        height: 16px;
        width: 16px;
        left: 3px;
        top: 3px;
        background: #fff;
        border-radius: 50%;
        transition: transform 0.2s ease;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.35);
    }

    .switch input:checked + .slider {
        background: var(--role-accent, var(--primary-color));
    }

    .switch input:checked + .slider::before {
        transform: translateX(18px);
    }

    .switch input:focus-visible + .slider {
        box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.25);
    }

    .switch input:disabled + .slider {
        opacity: 0.55;
        cursor: not-allowed;
    }

    .perm-savebar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.9rem 1.25rem;
        border-top: 1px solid var(--border-color);
        background: var(--light-bg);
        position: sticky;
        bottom: 0;
        z-index: 4;
    }

    .perm-savebar-info {
        font-size: 0.82rem;
        color: var(--text-muted);
        font-weight: 600;
    }

    .perm-savebar-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.55rem;
        align-items: center;
    }

    @media (max-width: 720px) {
        .role-stats {
            flex-direction: column;
            gap: 0.35rem;
        }

        .perm-head {
            align-items: flex-start;
        }
    }
</style>

<section id="panel-roles" role="tabpanel" aria-label="User roles">

    <div class="roles-head">
        <div>
            <h2><i class="fas fa-user-tag"></i> User Roles &amp; Permissions</h2>
            <p>Every admin account carries one role. Tick what a role is allowed to do — changes apply the moment you save.
            </p>
        </div>
        <div class="roles-head-actions">
            <?php if ($editable): ?>
                <form method="POST"
                    onsubmit="return confirm('Reset every role back to the shipped default permissions?');">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="reset_permissions">
                    <input type="hidden" name="role_current" value="__all__">
                    <button type="submit" class="dt-btn"><i class="fas fa-rotate-left"></i> Reset all to defaults</button>
                </form>
                <button type="button" class="btn-primary dt-btn-primary" id="roleEditorOpen">
                    <i class="fas fa-plus"></i> New Role
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$rbac_ready): ?>
        <div class="roles-notice" style="background: rgba(239,68,68,0.08); border-color: rgba(239,68,68,0.25); color:#b91c1c;">
            <i class="fas fa-triangle-exclamation"></i>
            <span>The <code>roles</code> / <code>role_permissions</code> tables could not be created, so only the built-in
                defaults below are in force.</span>
        </div>
    <?php elseif (!$editable): ?>
        <div class="roles-notice">
            <i class="fas fa-lock"></i>
            <span>You can review roles here, but only a <strong>Super Admin</strong> may change permissions.</span>
        </div>
    <?php endif; ?>

    <?php if ($editable): ?>
        <!-- Role editor: used both for "New Role" and for editing an existing role -->
        <div class="role-editor-card" id="roleEditor" hidden>
            <div class="role-editor-head">
                <h3 id="roleEditorTitle"><i class="fas fa-plus-circle"></i> New role</h3>
                <button type="button" class="dt-btn" id="roleEditorCancel">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>

            <form method="POST" id="roleEditorForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="save_role">
                <input type="hidden" name="role_current" id="roleCurrent" value="">

                <div class="role-editor-grid">
                    <div class="form-group">
                        <label for="roleName">Role name</label>
                        <input type="text" class="form-control" id="roleName" name="role_name" maxlength="60" required
                            placeholder="e.g. Kitchen">
                    </div>

                    <div class="form-group">
                        <label for="roleSlug">Role key</label>
                        <input type="text" class="form-control" id="roleSlug" name="role_slug" maxlength="16"
                            placeholder="e.g. kitchen">
                        <small>Stored on each admin account. Lowercase letters, numbers and underscores (max 16).
                            Leave blank to build it from the name.</small>
                    </div>

                    <div class="form-group">
                        <label for="roleIcon">Icon</label>
                        <select class="form-control" id="roleIcon" name="role_icon">
                            <?php foreach (rbac_icon_options() as $icon): ?>
                                <option value="<?php echo htmlspecialchars($icon); ?>"><?php echo htmlspecialchars($icon); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="roleColor">Colour</label>
                        <select class="form-control" id="roleColor" name="role_color">
                            <?php foreach (rbac_color_options() as $hex => $label): ?>
                                <option value="<?php echo htmlspecialchars($hex); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group role-editor-wide">
                        <label for="roleDescription">Description</label>
                        <input type="text" class="form-control" id="roleDescription" name="role_description" maxlength="200"
                            placeholder="What is this role responsible for?">
                    </div>
                </div>

                <div class="role-editor-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-floppy-disk"></i> <span id="roleEditorSubmit">Create role</span>
                    </button>
                    <span class="role-editor-hint">Permissions are granted in the matrix below.</span>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Role overview cards -->
    <div class="role-grid">
        <?php foreach ($role_list as $slug => $role): ?>
            <?php
            $role_users = (int) $role['users'];
            $role_perm_count = count(array_filter(isset($perm_matrix[$slug]) ? $perm_matrix[$slug] : []));
            $is_locked = rbac_is_locked_role($slug);
            $is_system = !empty($role['is_system']);
            $is_legacy = !empty($role['legacy']);
            $color = $role['color'];
            $reassign_options = [];
            foreach ($role_list as $other_slug => $other) {
                if ($other_slug !== $slug && empty($other['legacy'])) {
                    $reassign_options[$other_slug] = $other['name'];
                }
            }
            ?>
            <div class="role-card" data-role-card="<?php echo htmlspecialchars($slug); ?>"
                style="--role-color: <?php echo htmlspecialchars($color); ?>; --role-color-soft: <?php echo htmlspecialchars($color); ?>1f;">
                <div class="role-card-top">
                    <span class="role-icon"><i class="fas <?php echo htmlspecialchars($role['icon']); ?>"></i></span>
                    <div>
                        <div class="role-name"><?php echo htmlspecialchars($role['name']); ?></div>
                        <code class="role-slug"><?php echo htmlspecialchars($slug); ?></code>
                    </div>
                    <div class="role-flags">
                        <?php if ($is_locked): ?>
                            <span class="role-flag locked" title="Full access, cannot be reduced"><i class="fas fa-lock"></i> Locked</span>
                        <?php elseif ($is_system): ?>
                            <span class="role-flag system">Built-in</span>
                        <?php endif; ?>
                        <?php if ($is_legacy): ?>
                            <span class="role-flag legacy">Legacy key</span>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="role-desc"><?php echo htmlspecialchars($role['description']); ?></p>

                <div class="role-stats">
                    <span><i class="fas fa-users"></i> <strong><?php echo $role_users; ?></strong>
                        admin<?php echo $role_users === 1 ? '' : 's'; ?></span>
                    <span><i class="fas fa-key"></i> <strong
                            data-perm-count="<?php echo htmlspecialchars($slug); ?>"><?php echo $role_perm_count; ?></strong> /
                        <?php echo $feature_total; ?> permissions</span>
                </div>

                <div class="role-card-actions">
                    <?php if ($editable && !$is_legacy): ?>
                        <button type="button" class="dt-btn js-role-edit"
                            data-slug="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>"
                            data-name="<?php echo htmlspecialchars($role['name'], ENT_QUOTES); ?>"
                            data-description="<?php echo htmlspecialchars($role['description'], ENT_QUOTES); ?>"
                            data-icon="<?php echo htmlspecialchars($role['icon'], ENT_QUOTES); ?>"
                            data-color="<?php echo htmlspecialchars($color, ENT_QUOTES); ?>"
                            data-system="<?php echo $is_system ? '1' : '0'; ?>">
                            <i class="fas fa-pen"></i> Edit
                        </button>
                    <?php endif; ?>

                    <?php if ($editable && $is_legacy): ?>
                        <button type="button" class="dt-btn js-role-edit"
                            data-slug="<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>"
                            data-name="<?php echo htmlspecialchars($role['name'], ENT_QUOTES); ?>"
                            data-description="" data-icon="fa-user-clock"
                            data-color="<?php echo htmlspecialchars($color, ENT_QUOTES); ?>" data-system="1">
                            <i class="fas fa-wand-magic-sparkles"></i> Adopt into roles table
                        </button>
                    <?php endif; ?>

                    <?php if ($editable && !$is_system && !$is_locked && !$is_legacy): ?>
                        <details class="role-danger">
                            <summary><i class="fas fa-trash"></i> Delete role</summary>
                            <form method="POST"
                                onsubmit="return confirm('Delete the role <?php echo htmlspecialchars($role['name'], ENT_QUOTES); ?>?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="delete_role">
                                <input type="hidden" name="role_current" value="<?php echo htmlspecialchars($slug); ?>">
                                <?php if ($role_users > 0): ?>
                                    <select name="role_reassign" class="dt-select" required
                                        aria-label="Move these admins to">
                                        <option value="">Move <?php echo $role_users; ?> admin(s) to…</option>
                                        <?php foreach ($reassign_options as $option_slug => $option_label): ?>
                                            <option value="<?php echo htmlspecialchars($option_slug); ?>">
                                                <?php echo htmlspecialchars($option_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <button type="submit" class="dt-btn danger">
                                    <i class="fas fa-check"></i> Confirm delete
                                </button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Permission matrix -->
    <div class="perm-card">
        <div class="perm-head">
            <div>
                <h3><i class="fas fa-table-cells-large"></i> Permission Matrix</h3>
                <p><?php echo $editable
                    ? 'Toggle a permission on or off, then press Save. Roles marked Locked always keep full access.'
                    : 'This is the access each role currently has.'; ?></p>
            </div>
            <div class="perm-tools">
                <div class="dt-search">
                    <i class="fas fa-filter"></i>
                    <input type="search" id="permFilter" class="dt-input" placeholder="Filter permissions…"
                        autocomplete="off" aria-label="Filter permissions">
                </div>
                <span class="perm-dirty" id="permDirty" hidden><i class="fas fa-circle"></i> Unsaved changes</span>
            </div>
        </div>

        <?php echo $form_open; ?>
        <?php if ($editable): ?>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="save_permissions">
            <?php foreach (array_keys($role_list) as $slug): ?>
                <?php if (!rbac_is_locked_role($slug)): ?>
                    <input type="hidden" name="perm_roles[]" value="<?php echo htmlspecialchars($slug); ?>">
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="perm-scroll">
            <table class="perm-table">
                <thead>
                    <tr>
                        <th class="perm-feature-col">Permission</th>
                        <?php foreach ($role_list as $slug => $role): ?>
                            <th>
                                <div class="perm-role-head">
                                    <span class="perm-role-name" style="color: <?php echo htmlspecialchars($role['color']); ?>;">
                                        <i class="fas <?php echo htmlspecialchars($role['icon']); ?>"></i>
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </span>
                                    <?php if (rbac_is_locked_role($slug)): ?>
                                        <span class="perm-locked-note"><i class="fas fa-lock"></i> Always on</span>
                                    <?php elseif ($editable): ?>
                                        <span class="perm-col-actions">
                                            <button type="button" class="perm-mini" data-column-all="<?php echo htmlspecialchars($slug); ?>"
                                                data-value="1">All</button>
                                            <button type="button" class="perm-mini" data-column-all="<?php echo htmlspecialchars($slug); ?>"
                                                data-value="0">None</button>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($perm_catalog as $group => $group_features): ?>
                        <tr class="perm-group-row">
                            <td colspan="<?php echo 1 + count($role_list); ?>"><i class="fas fa-layer-group"></i>
                                <?php echo htmlspecialchars($group); ?></td>
                        </tr>
                        <?php foreach ($group_features as $feature_key => $feature): ?>
                            <tr class="perm-row" data-feature-row="<?php echo htmlspecialchars($feature_key); ?>"
                                data-feature-search="<?php echo htmlspecialchars(strtolower($feature['label'] . ' ' . $feature['description'] . ' ' . $feature_key . ' ' . $group), ENT_QUOTES); ?>">
                                <td class="perm-feature-cell">
                                    <div class="perm-feature-name">
                                        <i class="fas <?php echo htmlspecialchars($feature['icon']); ?>"></i>
                                        <?php echo htmlspecialchars($feature['label']); ?>
                                        <?php if (empty($feature['enforced'])): ?>
                                            <span class="perm-flag" title="Reserved for future releases">reserved</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="perm-feature-desc"><?php echo htmlspecialchars($feature['description']); ?></div>
                                </td>
                                <?php foreach ($role_list as $slug => $role): ?>
                                    <?php
                                    $allowed = !empty($perm_matrix[$slug][$feature_key]);
                                    $input_locked = rbac_is_locked_role($slug) || !$editable;
                                    ?>
                                    <td class="perm-cell">
                                        <label class="switch" style="--role-accent: <?php echo htmlspecialchars($role['color']); ?>;" title="<?php echo htmlspecialchars($role['name'] . ' — ' . $feature['label']); ?>">
                                            <input type="checkbox" value="1"
                                                name="perm[<?php echo htmlspecialchars($slug); ?>][<?php echo htmlspecialchars($feature_key); ?>]"
                                                data-role="<?php echo htmlspecialchars($slug); ?>"
                                                data-feature="<?php echo htmlspecialchars($feature_key); ?>"
                                                <?php echo $allowed ? 'checked' : ''; ?>
                                                <?php echo $input_locked ? 'disabled' : ''; ?>
                                                aria-label="<?php echo htmlspecialchars($role['name'] . ' can ' . $feature['label']); ?>">
                                            <span class="slider"></span>
                                        </label>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <tr class="perm-row" id="permNoMatchRow" hidden>
                        <td class="perm-feature-cell" colspan="<?php echo 1 + count($role_list); ?>"
                            style="text-align:center; color: var(--text-muted);">
                            No permissions match your filter.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($editable): ?>
            <div class="perm-savebar">
                <div class="perm-savebar-info">
                    <i class="fas fa-circle-info"></i> Changes affect every admin holding the role straight away.
                </div>
                <div class="perm-savebar-actions">
                    <button type="button" class="dt-btn" id="permDiscard"><i class="fas fa-rotate-left"></i> Discard</button>
                    <button type="submit" class="btn-primary dt-btn-primary" id="permSave">
                        <i class="fas fa-floppy-disk"></i> Save permissions
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <?php echo $form_close; ?>
    </div>
</section>

<?php if ($editable): ?>
    <script>
        (function () {
            'use strict';

            var editor = document.getElementById('roleEditor');
            var editorOpen = document.getElementById('roleEditorOpen');
            var editorCancel = document.getElementById('roleEditorCancel');
            var editorTitle = document.getElementById('roleEditorTitle');
            var editorSubmit = document.getElementById('roleEditorSubmit');
            var fields = {
                current: document.getElementById('roleCurrent'),
                name: document.getElementById('roleName'),
                slug: document.getElementById('roleSlug'),
                description: document.getElementById('roleDescription'),
                icon: document.getElementById('roleIcon'),
                color: document.getElementById('roleColor')
            };

            function openEditor(mode, data) {
                data = data || {};
                editor.hidden = false;

                if (mode === 'edit') {
                    editorTitle.innerHTML = '<i class="fas fa-pen"></i> Edit role: ' + (data.name || '');
                    editorSubmit.textContent = 'Save role';
                    fields.current.value = data.slug || '';
                    fields.name.value = data.name || '';
                    fields.slug.value = data.slug || '';
                    fields.description.value = data.description || '';
                    fields.slug.readOnly = data.system === '1';
                    fields.slug.style.opacity = data.system === '1' ? '0.6' : '1';
                } else {
                    editorTitle.innerHTML = '<i class="fas fa-plus-circle"></i> New role';
                    editorSubmit.textContent = 'Create role';
                    fields.current.value = '';
                    fields.name.value = '';
                    fields.slug.value = '';
                    fields.description.value = '';
                    fields.slug.readOnly = false;
                    fields.slug.style.opacity = '1';
                }

                if (data.icon) {
                    fields.icon.value = data.icon;
                }
                if (data.color) {
                    fields.color.value = data.color;
                }

                try {
                    editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } catch (e) { /* older browsers */ }
                fields.name.focus();
            }

            if (editorOpen) {
                editorOpen.addEventListener('click', function () {
                    openEditor('create');
                });
            }

            if (editorCancel) {
                editorCancel.addEventListener('click', function () {
                    editor.hidden = true;
                    fields.slug.readOnly = false;
                });
            }

            Array.prototype.forEach.call(document.querySelectorAll('.js-role-edit'), function (button) {
                button.addEventListener('click', function () {
                    openEditor('edit', {
                        slug: button.getAttribute('data-slug'),
                        name: button.getAttribute('data-name'),
                        description: button.getAttribute('data-description'),
                        icon: button.getAttribute('data-icon'),
                        color: button.getAttribute('data-color'),
                        system: button.getAttribute('data-system')
                    });
                });
            });

            /* ---------------- Permission matrix ---------------- */
            var checkboxes = Array.prototype.slice.call(document.querySelectorAll('#permForm input[type="checkbox"][data-role]'));
            var dirtyBadge = document.getElementById('permDirty');
            var initialState = checkboxes.map(function (input) {
                return input.checked;
            });

            function isDirty() {
                for (var i = 0; i < checkboxes.length; i++) {
                    if (checkboxes[i].checked !== initialState[i]) {
                        return true;
                    }
                }
                return false;
            }

            function updateCounters() {
                var totals = {};
                checkboxes.forEach(function (input) {
                    var slug = input.getAttribute('data-role');
                    totals[slug] = (totals[slug] || 0) + (input.checked ? 1 : 0);
                });

                Object.keys(totals).forEach(function (slug) {
                    var target = document.querySelector('[data-perm-count="' + slug + '"]');
                    if (target) {
                        target.textContent = totals[slug];
                    }
                });
            }

            function updateDirty() {
                var dirty = isDirty();
                if (dirtyBadge) {
                    dirtyBadge.hidden = !dirty;
                }
            }

            checkboxes.forEach(function (input) {
                input.addEventListener('change', function () {
                    updateCounters();
                    updateDirty();
                });
            });

            Array.prototype.forEach.call(document.querySelectorAll('.perm-mini'), function (button) {
                button.addEventListener('click', function () {
                    var slug = button.getAttribute('data-column-all');
                    var value = button.getAttribute('data-value') === '1';
                    checkboxes.forEach(function (input) {
                        if (input.getAttribute('data-role') === slug && !input.disabled) {
                            input.checked = value;
                        }
                    });
                    updateCounters();
                    updateDirty();
                });
            });

            var dirtyWarning = function (event) {
                if (!isDirty()) {
                    return undefined;
                }
                event.preventDefault();
                event.returnValue = '';
                return '';
            };
            window.addEventListener('beforeunload', dirtyWarning);

            var permForm = document.getElementById('permForm');
            if (permForm) {
                permForm.addEventListener('submit', function () {
                    window.removeEventListener('beforeunload', dirtyWarning);
                });
            }

            var discard = document.getElementById('permDiscard');
            if (discard) {
                discard.addEventListener('click', function () {
                    if (!isDirty() || window.confirm('Discard the unsaved permission changes?')) {
                        window.location.reload();
                    }
                });
            }

            /* Filter rows of the matrix */
            var filter = document.getElementById('permFilter');
            var noMatch = document.getElementById('permNoMatchRow');
            if (filter) {
                filter.addEventListener('input', function () {
                    var query = filter.value.trim().toLowerCase();
                    var rows = document.querySelectorAll('.perm-row[data-feature-row]');
                    var groups = document.querySelectorAll('.perm-group-row');
                    var rowGroups = {};

                    Array.prototype.forEach.call(rows, function (row) {
                        var haystack = row.getAttribute('data-feature-search') || '';
                        var show = query === '' || haystack.indexOf(query) !== -1;
                        row.hidden = !show;

                        // Hide a group heading when every row underneath it is hidden.
                        var previous = row.previousElementSibling;
                        while (previous && !previous.classList.contains('perm-group-row')) {
                            previous = previous.previousElementSibling;
                        }
                        if (previous) {
                            var key = Array.prototype.indexOf.call(groups, previous);
                            rowGroups[key] = rowGroups[key] || show;
                        }
                    });

                    Array.prototype.forEach.call(groups, function (group, index) {
                        group.hidden = !rowGroups[index];
                    });

                    if (noMatch) {
                        noMatch.hidden = document.querySelectorAll('.perm-row[data-feature-row]:not([hidden])').length > 0;
                    }
                });
            }

            updateCounters();
        })();
    </script>
<?php endif; ?>
