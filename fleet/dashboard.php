<?php
// /fleet/dashboard.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['fleetstaff']);
include __DIR__ . '/../includes/fleet_navbar.php';

// KPIs
$totalVehicles = $conn->query("SELECT COUNT(*) AS c FROM vehicles")->fetch_assoc()['c'] ?? 0;
$inMaintenance = $conn->query("SELECT COUNT(*) AS c FROM vehicles WHERE status='maintenance'")->fetch_assoc()['c'] ?? 0;
$upcomingMaint = $conn->query("SELECT COUNT(*) AS c FROM maintenance_schedule WHERE scheduled_date >= CURDATE() AND status='scheduled'")->fetch_assoc()['c'] ?? 0;
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Fleet Dashboard</title></head>
<body>
<div style="display:flex;gap:18px;padding:24px;">
  <div class="sidebar">
    <h4>Menu</h4><hr>
    <a href="../fleet/dashboard.php"> Dashboard</a>
    <a href="../fleet/manage_vehicles.php"> Manage Vehicles</a>
    <a href="../fleet/maintenance_schedule.php"> Maintenance Schedule</a>
    <a href="../fleet/repair_logs.php"> Repair Logs</a>
  </div>

  <div style="flex:1" class="container-main">
    <div class="card">
      <h2>Fleet Overview</h2>
      <div class="small">As of <?php echo date('F j, Y, g:i A'); ?></div>
      <div style="height:12px"></div>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:180px;padding:14px;border-radius:10px;background:linear-gradient(180deg,#6532C9,#9A66FF);color:#fff">
          <div class="small">Total Vehicles</div><h3><?php echo $totalVehicles; ?></h3>
        </div>
        <div style="flex:1;min-width:180px;padding:14px;border-radius:10px;background:linear-gradient(180deg,#ff9800,#ffc107);color:#222">
          <div class="small">In Maintenance</div><h3><?php echo $inMaintenance; ?></h3>
        </div>
        <div style="flex:1;min-width:180px;padding:14px;border-radius:10px;background:linear-gradient(180deg,#28a745,#20c997);color:#fff">
          <div class="small">Upcoming Maint.</div><h3><?php echo $upcomingMaint; ?></h3>
        </div>
      </div>
    </div>

    <div class="card">
      <h4>Quick Links</h4>
      <p><a href="/fleet/manage_vehicles.php">Manage Vehicles</a> · <a href="/fleet/maintenance_schedule.php">Maintenance Schedule</a> · <a href="/fleet/repair_logs.php">Repair Logs</a></p>
    </div>
  </div>
</div>
</body>
</html>
