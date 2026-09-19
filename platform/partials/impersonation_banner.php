<?php
/**
 * "You are viewing this restaurant as support" banner.
 *
 * Included by admin/includes/admin_header.php on every tenant admin page, so a
 * support session can never be mistaken for the owner's own session.
 */

if (empty($_SESSION['impersonating']) || !class_exists(\Resto\Tenancy\Links::class)) {
    return;
}

$resto_tenant = \Resto\Tenancy\Context::get();
$resto_stop   = \Resto\Tenancy\Links::origin() . '/platform/impersonate.php?stop=1';
$resto_agent  = htmlspecialchars((string) ($_SESSION['impersonating']['platform_user'] ?? 'Support'), ENT_QUOTES);
$resto_tenant_name = htmlspecialchars((string) ($resto_tenant?->name() ?? 'this restaurant'), ENT_QUOTES);
$resto_reason = htmlspecialchars((string) ($_SESSION['impersonating']['reason'] ?? ''), ENT_QUOTES);
?>
<div style="background:linear-gradient(90deg,#7c3aed,#4f46e5);color:#fff;padding:10px 18px;display:flex;
            gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;font-size:14px">
    <span>
        <strong>Support session</strong> —
        <?php echo $resto_agent; ?> is signed in as the owner of <?php echo $resto_tenant_name; ?>
        <?php if ($resto_reason !== ''): ?><span style="opacity:.8">· <?php echo $resto_reason; ?></span><?php endif; ?>
    </span>
    <a href="<?php echo htmlspecialchars($resto_stop, ENT_QUOTES); ?>"
       style="background:rgba(255,255,255,.18);color:#fff;padding:6px 12px;border-radius:8px;text-decoration:none">
        End session and return to console
    </a>
</div>
