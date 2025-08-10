<?php
// /operations/dashboard.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['admin1']);

include __DIR__ . '/../includes/ops_navbar.php';

// KPIs
$totalTripsToday = $conn->query("SELECT COUNT(*) AS c FROM trips WHERE DATE(scheduled_time)=CURDATE()")->fetch_assoc()['c'] ?? 0;
$ongoingTrips = $conn->query("SELECT COUNT(*) AS c FROM trips WHERE status='ongoing'")->fetch_assoc()['c'] ?? 0;
$totalVehicles = $conn->query("SELECT COUNT(*) AS c FROM vehicles")->fetch_assoc()['c'] ?? 0;
$vehiclesInMaint = $conn->query("SELECT COUNT(*) AS c FROM vehicles WHERE status='maintenance'")->fetch_assoc()['c'] ?? 0;
$pendingMaintenance = $conn->query("SELECT COUNT(*) AS c FROM maintenance_schedule WHERE approval_status='pending'")->fetch_assoc()['c'] ?? 0;
$lowStock = $conn->query("SELECT COUNT(*) AS c FROM supplies WHERE quantity <= 5")->fetch_assoc()['c'] ?? 0;
?>
<!doctype html>
<html>
  <head><meta charset="utf-8"><title>Operations Dashboard</title>
</head>
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
      <h2>Operations Summary</h2>
      <div class="small">As of <?php echo date('F j, Y, g:i A'); ?></div>
      <div style="height:12px"></div>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:180px;padding:14px;border-radius:10px;background:linear-gradient(180deg,#6532C9,#9A66FF);color:#fff">
          <div class="small">Trips Today</div><h3><?php echo $totalTripsToday; ?></h3>
        </div>
        <div style="flex:1;min-width:180px;padding:14px;border-radius:10px;background:linear-gradient(180deg,#0dcaf0,#0bb4d9);color:#fff">
          <div class="small">Ongoing Trips</div><h3><?php echo $ongoingTrips; ?></h3>
        </div>
        <div style="flex:1;min-width:180px;padding:14px;border-radius:10px;background:linear-gradient(180deg,#28a745,#20c997);color:#fff">
          <div class="small">Total Vehicles</div><h3><?php echo $totalVehicles; ?></h3>
        </div>
        <div style="flex:1;min-width:180px;padding:14px;border-radius:10px;background:linear-gradient(180deg,#ffc107,#ffca2c);color:#222">
          <div class="small">Vehicles in Maintenance</div><h3><?php echo $vehiclesInMaint; ?></h3>
        </div>
      </div>
    </div>

    <div class="card">
      <h4>Quick Actions</h4>
      <p>
        <a href="/operations/trip_logs.php">View Trip Logs</a> ·
        <a href="/operations/maintenance_approvals.php">Review Maintenance Requests</a> ·
        <a href="/operations/inventory_overview.php">Inventory Overview</a>
      </p>
    </div>

  </div>
</div>
</body>
</html>
