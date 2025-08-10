<?php
// store_clerk/history.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['storeclerk']);
include __DIR__ . '/../includes/storeclerk_navbar.php';

$sql = "SELECT h.*, s.item_name FROM stock_history h LEFT JOIN supplies s ON h.supply_id=s.supply_id ORDER BY h.action_at DESC LIMIT 500";
$res = $conn->query($sql);
?>
<!doctype html><html><head><meta charset="utf-8"><title>Stock History</title></head>
<body>
<div style="display:flex;gap:18px;padding:24px">
  <div class="sidebar"><h4>Menu</h4><hr>
    <a href="../store_clerk/dashboard.php"> Dashboard</a>
    <a href="../store_clerk/manage_supplies.php"> Manage Supplies</a>
    <a href="../store_clerk/issue_supplies.php"> Issue Items</a>
    <a href="../store_clerk/history.php"> Stock History</a>
  </div>

  <div style="flex:1" class="container-main card">
    <h3>Stock History</h3>
    <table style="width:100%;border-collapse:collapse">
      <thead style="background:#4311A5;color:#fff"><tr><th>Date</th><th>Item</th><th>Action</th><th>Change</th><th>By</th></tr></thead>
      <tbody>
      <?php while($r = $res->fetch_assoc()): ?>
        <tr>
          <td><?php echo date('M j, Y g:i A', strtotime($r['action_at']));?></td>
          <td><?php echo htmlspecialchars($r['item_name'] ?? '-');?></td>
          <td><?php echo htmlspecialchars($r['action_type']);?></td>
          <td><?php echo (int)$r['quantity_change'];?></td>
          <td><?php echo htmlspecialchars($r['action_by']);?></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
