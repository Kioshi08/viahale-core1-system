<?php
// /operations/inventory_overview.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['admin1']);
include __DIR__ . '/../includes/ops_navbar.php';

$supplies = $conn->query("SELECT * FROM supplies ORDER BY quantity ASC LIMIT 500");
?>
<!doctype html><html><head><meta charset="utf-8"><title>Inventory Overview</title></head>
<body>
<div style="display:flex;gap:18px;padding:24px">
  <div class="sidebar">
    <h4>Menu</h4><hr>
    <a href="../operations/dashboard.php"> Dashboard</a>
    <a href="../operations/trip_logs.php"> Trip Logs</a>
    <a href="../operations/maintenance_approvals.php"> Maintenance Approvals <?php if($pendingMaintenance) echo "({$pendingMaintenance})"; ?></a>
    <a href="../operations/inventory_overview.php"> Inventory Overview <?php if($lowStock) echo "({$lowStock} low)"; ?></a>
    <a href="../operations/reports.php"> Reports</a>
  </div>

  <div style="flex:1" class="container-main">
    <div class="card">
      <h3>Inventory Overview</h3>
      <table style="width:100%;border-collapse:collapse">
        <thead style="background:#4311A5;color:#fff"><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Added By</th></tr></thead>
        <tbody>
          <?php while($s = $supplies->fetch_assoc()): ?>
            <tr>
              <td><?php echo htmlspecialchars($s['item_name']);?></td>
              <td><?php echo (int)$s['quantity'];?></td>
              <td><?php echo htmlspecialchars($s['unit']);?></td>
              <td><?php echo htmlspecialchars($s['created_by']);?></td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h4>Low Stock Alerts</h4>
      <ul>
        <?php
        $low = $conn->query("SELECT * FROM supplies WHERE quantity <= 5 ORDER BY quantity ASC");
        while($l = $low->fetch_assoc()): ?>
          <li><?php echo htmlspecialchars($l['item_name'].' — '.$l['quantity'].' '.$l['unit']); ?></li>
        <?php endwhile; ?>
      </ul>
    </div>

  </div>
</div>
</body></html>
