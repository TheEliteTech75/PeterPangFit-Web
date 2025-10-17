<?php
// ppf_header_guest.php — same visual header as ppf_header.php, but no account chip/menu.
// Safe to include on public pages like register.php (no session/user dependencies).

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>
<style>
/* ===== Shared Header (guest), same colors as dashboard ===== */
.ppf-topbar {
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 24px;
  background:#0b0c10;
  border-bottom:1px solid #1c212b;
  position: relative;
  z-index: 1000;
}
.ppf-brand {
  font-weight:800;font-size:22px;color:#e6e8ee;letter-spacing:.3px;
}
.ppf-right {
  display:flex;align-items:center;gap:10px;
}
.ppf-link {
  color:#e6e8ee;text-decoration:none;font-size:14px;
  padding:6px 10px;border:1px solid #1c212b;border-radius:8px;background:#151923;
}
.ppf-link:hover { background:#1f2430; }
</style>

<header class="ppf-topbar">
  <div class="ppf-brand">Peter Pang Fit</div>
</header>