<?php
/**
 * Support sessions — redeem (or end) a superadmin impersonation token.
 *
 * The superadmin console issues a single-use, 24-hour token; this page turns
 * it into a normal tenant admin session so support can see exactly what the
 * restaurant owner sees. Every use is written to the audit trail.
 */

require_once __DIR__ . '/bootstrap.php';

use Resto\Platform\Auth;
use Resto\Tenancy\Context;
use Resto\Tenancy\Links;


$redirect = (string) ($_GET['redirect_to'] ?? '/admin/dashboard.php');

/* ---------------- stop impersonating ---------------- */
if (isset($_GET['stop'])) {
    $tenant = Context::get();
    Auth::stopImpersonation();
    $_SESSION['resto_tenant'] = null;
    unset($_SESSION['resto_tenant']);

    $back = Links::console('/tenants' . ($tenant ? '/' . $tenant->id() : ''));
    header('Location: ' . $back);
    exit;
}

/* ---------------- redeem a token ---------------- */
$token  = (string) ($_GET['token'] ?? '');
$result = $token !== '' ? Auth::redeemImpersonation($token) : null;

if ($result === null) {
    http_response_code(403);
    $console = htmlspecialchars(Links::console('/tenants'), ENT_QUOTES);
    echo <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Support link expired</title>
<style>
 body { font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; background:#0b1120; color:#e2e8f0;
        display:grid; place-items:center; min-height:100vh; margin:0 }
 .card { max-width:34rem; padding:3rem }
 h1 { font-size:1.5rem } p { color:#94a3b8 } a { color:#a5b4fc }
</style></head><body><div class="card">
  <h1>This support link is no longer valid</h1>
  <p>Impersonation links are single-use and expire after 24 hours. Open the tenant in the console and start a new
     support session.</p>
  <p><a href="{$console}">Back to the tenant list →</a></p>
</div></body></html>
HTML;
    exit;
}

$tenant = $result['tenant'];
$_SESSION['resto_tenant'] = $tenant->slug();

header('Location: ' . Links::admin($tenant, ltrim($redirect, '/')));
exit;
