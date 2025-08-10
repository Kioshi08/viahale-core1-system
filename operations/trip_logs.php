<?php
// /operations/trip_logs.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['admin1']);
include __DIR__ . '/../includes/ops_navbar.php';

$q = "SELECT t.*, d.name as driver_name, v.plate_no FROM trips t
      LEFT JOIN drivers d ON t.driver_id=d.id
      LEFT JOIN vehicles v ON t.vehicle_id=v.id
      ORDER BY t.scheduled_time DESC LIMIT 1000";
$res = $conn->query($q);
?>
<!doctype html><html><head><meta charset="utf-8"><title>Trip Logs</title></head>
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
      <h3>Trip Logs</h3>
      <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse">
          <thead style="background:#4311A5;color:#fff"><tr><th>Code</th><th>Passenger</th><th>Driver</th><th>Vehicle</th><th>Origin</th><th>Dest</th><th>Time</th><th>Status</th></tr></thead>
          <tbody>
          <?php while($r = $res->fetch_assoc()): ?>
            <tr>
              <td><?php echo htmlspecialchars($r['trip_code']);?></td>
              <td><?php echo htmlspecialchars($r['passenger_name']);?></td>
              <td><?php echo htmlspecialchars($r['driver_name'] ?: '-');?></td>
              <td><?php echo htmlspecialchars($r['plate_no'] ?: '-');?></td>
              <td><?php echo htmlspecialchars($r['origin']);?></td>
              <td><?php echo htmlspecialchars($r['destination']);?></td>
              <td><?php echo $r['scheduled_time'] ? date('M j, Y g:i A', strtotime($r['scheduled_time'])) : '-';?></td>
              <td><?php echo htmlspecialchars(ucfirst($r['status']));?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body></html>
