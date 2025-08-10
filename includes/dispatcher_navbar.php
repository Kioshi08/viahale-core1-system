<?php
// includes/dispatcher_navbar.php
if (session_status() === PHP_SESSION_NONE) session_start();
$username = $_SESSION['username'] ?? '';
?>
<link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;600&family=Poppins:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{
  --vh-color1:#6532C9; /* primary */
  --vh-color2:#4311A5; /* dark */
  --vh-accent:#9A66FF;
  --vh-bg:#f5f3ff;
}
body{ font-family: 'Poppins', sans-serif; background: var(--vh-bg); }
.navbar-vh{
  background: linear-gradient(90deg,var(--vh-color2),var(--vh-color1));
  color:#fff; padding:12px 18px; display:flex; align-items:center; justify-content:space-between;
  box-shadow:0 6px 18px rgba(65,17,165,0.12);
}
.nav-left{display:flex;align-items:center;gap:12px}
.brand {
  font-weight:700;font-size:18px;
}
.role-badge{
  background: rgba(255,255,255,0.08); padding:6px 10px; border-radius:8px; font-size:13px;
}
.nav-actions a { color:#fff; text-decoration:none; background:transparent; border:1px solid rgba(255,255,255,0.12);
  padding:8px 12px; border-radius:8px; font-weight:600; }
.nav-actions a:hover{ background: rgba(255,255,255,0.06) }
.sidebar {
  width:220px; background:#fff; min-height:calc(100vh - 60px); padding:18px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  border-radius:10px;
}
.sidebar a { display:block; padding:10px 12px; color:#4311A5; text-decoration:none; border-radius:8px; margin-bottom:8px; font-weight:600;}
.sidebar a:hover{ background:var(--vh-accent); color:#fff; }
.container-main{ padding:24px; }
.card{ background:#fff; border-radius:12px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,0.04); margin-bottom:18px;}
.small { font-size:13px; color:#666; }
.kpi { display:flex; gap:14px; flex-wrap:wrap; }
.kpi .item { flex:1 1 200px; padding:14px; border-radius:10px; color:#fff; }
.kpi .blue{ background: linear-gradient(180deg,var(--vh-color1),var(--vh-accent)); }
.kpi .green{ background: linear-gradient(180deg,#28a745,#20c997); }
.kpi .yellow{ background: linear-gradient(180deg,#ffc107,#ffca2c); color:#222; }
.table-responsive{ overflow:auto; }
</style>

<div class="navbar-vh">
  <div class="nav-left">
    <img src="../logo.png" 
         alt="ViaHale Logo" 
         style="height:40px;width:auto;display:block;"> 
    <div class="brand">ViaHale</div>
    <div class="role-badge">Dispatcher</div>
  </div>
  <div class="nav-actions">
    <span class="small" style="margin-right:12px">Hello, <?php echo htmlspecialchars($username); ?></span>
    <a class="nav-link" href="/core1/logout.php" onclick="return confirm('Are you sure you want to log out?')">Logout</a>
  </div>
</div>
