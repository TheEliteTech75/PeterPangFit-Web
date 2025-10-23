<?php
// ppf_header_guest.php — same visual header as ppf_header.php, but no account chip/menu.
// Safe to include on public pages like register.php (no session/user dependencies).

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>
<style>
/* ===== Guest Header — refreshed palette ===== */
.ppf-topbar {
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 24px;
  background:rgba(2,6,23,0.9);
  border-bottom:1px solid rgba(148,163,184,0.18);
  backdrop-filter:blur(18px);
  box-shadow:0 24px 40px rgba(2,6,23,0.45);
  position: relative;
  z-index: 1000;
}
.ppf-brand {
  font-weight:800;font-size:22px;color:var(--header-text, #f8fafc);letter-spacing:-.02em;
}
.ppf-right {
  display:flex;align-items:center;gap:10px;
}
.ppf-link {
  color:#f8fafc;text-decoration:none;font-size:14px;
  padding:8px 12px;border:1px solid rgba(148,163,184,0.22);border-radius:10px;background:rgba(15,23,42,0.72);
  transition:background .25s ease,border-color .25s ease,box-shadow .25s ease;
}
.ppf-link:hover,
.ppf-link:focus-visible { background:rgba(30,41,59,0.75);border-color:rgba(56,189,248,0.45);box-shadow:0 12px 24px rgba(15,23,42,0.35); }
</style>

<header class="ppf-topbar">
  <div class="ppf-brand">Peter Pang Fit</div>
</header>