<?php
// /operations/maintenance_approvals.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['admin1']);
include __DIR__ . '/../includes/ops_navbar.php';

$action_msg = '';
if (isset($_GET['action'], $_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($_GET['action'] === 'approve') {
        $u = $conn->prepare("UPDATE maintenance_schedule SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?");
        $u->bind_param("si", $_SESSION['username'], $id); $u->execute(); $u->close();
        $action_msg = "Approved.";
    } elseif ($_GET['action']==='reject') {
        $u = $conn->prepare("UPDATE maintenance_schedule SET approval_status='rejected', approved_by=?, approved_at=NOW() WHERE id=?");
        $u->bind_param("si", $_SESSION['username'], $id); $u->execute(); $u->close();
        $action_msg = "Rejected.";
    }
}

$res = $conn->query("SELECT m.*, v.plate_no FROM maintenance_schedule m LEFT JOIN vehicles v ON m.vehicle_id=v.id ORDER BY m.scheduled_date ASC LIMIT 500");
?>
<!doctype html><html><head><meta charset="utf-8"><title>Maintenance Approvals</title></head>
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
      <h3>Maintenance Requests</h3>
      <?php if($action_msg): ?><div style="color:green"><?php echo htmlspecialchars($action_msg); ?></div><?php endif; ?>
      <table style="width:100%;border-collapse:collapse">
        <thead style="background:#4311A5;color:#fff"><tr><th>Vehicle</th><th>Date</th><th>Type</th><th>Requested By</th><th>Status</th><th>Approval</th></tr></thead>
        <tbody>
        <?php while($r = $res->fetch_assoc()): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['plate_no'] ?: '-'); ?></td>
            <td><?php echo htmlspecialchars($r['scheduled_date']); ?></td>
            <td><?php echo htmlspecialchars($r['type']); ?></td>
            <td><?php echo htmlspecialchars($r['created_by']); ?></td>
            <td><?php echo htmlspecialchars($r['approval_status'] ?? 'pending'); ?></td>
            <td>
              <?php if(($r['approval_status'] ?? 'pending') === 'pending'): ?>
                <a href="maintenance_approvals.php?action=approve&id=<?php echo $r['id']; ?>">Approve</a> |
                <a href="maintenance_approvals.php?action=reject&id=<?php echo $r['id']; ?>">Reject</a>
              <?php else: ?>
                <?php echo htmlspecialchars($r['approval_status']); ?> by <?php echo htmlspecialchars($r['approved_by'] ?? '-'); ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body></html>
