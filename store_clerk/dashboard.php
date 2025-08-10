<?php
// store_clerk/dashboard.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['storeclerk']);
include __DIR__ . '/../includes/storeclerk_navbar.php';

// KPIs
$totalItems = $conn->query("SELECT COUNT(*) AS c FROM supplies")->fetch_assoc()['c'] ?? 0;
$totalQty = $conn->query("SELECT SUM(quantity) AS s FROM supplies")->fetch_assoc()['s'] ?? 0;
$recentIssues = $conn->query("SELECT COUNT(*) AS c FROM issued_items WHERE issued_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'] ?? 0;
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Store Clerk Dashboard</title></head>
<body>
<div style="display:flex;gap:18px;padding:24px">
  <div class="sidebar">
    <h4>Menu</h4><hr>
    <a href="../store_clerk/dashboard.php"> Dashboard</a>
    <a href="../store_clerk/manage_supplies.php"> Manage Supplies</a>
    <a href="../store_clerk/issue_supplies.php"> Issue Items</a>
    <a href="../store_clerk/history.php"> Stock History</a>
  </div>

  <div style="flex:1" class="container-main">
    <div class="card">
      <h2>Store Room Overview</h2>
      <div class="small">As of <?php echo date('F j, Y, g:i A'); ?></div>
      <div style="height:12px"></div>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:180px;padding:14px;border-radius:10px;background:linear-gradient(180deg,#6532C9,#9A66FF);color:#fff">
          <div class="small">Total Item Types</div><h3><?php echo $totalItems; ?></h3>
        </div>
        <div style="flex:1;min-width:180px;padding:14px;border-radius:10px;background:linear-gradient(180deg,#28a745,#20c997);color:#fff">
          <div class="small">Total Quantity</div><h3><?php echo $totalQty ?: 0; ?></h3>
        </div>
        <div style="flex:1;min-width:180px;padding:14px;border-radius:10px;background:linear-gradient(180deg,#ff9800,#ffc107);color:#222">
          <div class="small">Issued (7d)</div><h3><?php echo $recentIssues; ?></h3>
        </div>
      </div>
    </div>

    <div class="card">
      <h4>Quick Links</h4>
      <p><a href="/store_clerk/manage_supplies.php">Manage Supplies</a> · <a href="../store_clerk/issue_supplies.php">Issue Items</a> · <a href="../store_clerk/history.php">Stock History</a></p>
    </div>
  </div>
</div>
</body>
</html>
