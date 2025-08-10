<?php
// /operations/reports.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['admin1']);
include __DIR__ . '/../includes/ops_navbar.php';

// CSV download handlers
if (isset($_GET['export'])) {
    if ($_GET['export'] === 'trips') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=trips.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['trip_code','passenger','driver','vehicle','origin','destination','scheduled_time','status']);
        $res = $conn->query("SELECT t.*, d.name driver_name, v.plate_no FROM trips t LEFT JOIN drivers d ON t.driver_id=d.id LEFT JOIN vehicles v ON t.vehicle_id=v.id");
        while($r = $res->fetch_assoc()){
            fputcsv($out, [$r['trip_code'],$r['passenger_name'],$r['driver_name'],$r['plate_no'],$r['origin'],$r['destination'],$r['scheduled_time'],$r['status']]);
        }
        fclose($out); exit();
    } elseif ($_GET['export'] === 'maintenance') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=maintenance.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','vehicle','scheduled_date','type','status','approval_status','created_by','approved_by','approved_at']);
        $res = $conn->query("SELECT m.*, v.plate_no FROM maintenance_schedule m LEFT JOIN vehicles v ON m.vehicle_id=v.id");
        while($r = $res->fetch_assoc()){
            fputcsv($out, [$r['id'],$r['plate_no'],$r['scheduled_date'],$r['type'],$r['status'],$r['approval_status'],$r['created_by'],$r['approved_by'],$r['approved_at']]);
        }
        fclose($out); exit();
    } elseif ($_GET['export'] === 'inventory') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=inventory.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['supply_id','item_name','quantity','unit','created_by','created_at']);
        $res = $conn->query("SELECT * FROM supplies");
        while($r = $res->fetch_assoc()){
            fputcsv($out, [$r['supply_id'],$r['item_name'],$r['quantity'],$r['unit'],$r['created_by'],$r['created_at']]);
        }
        fclose($out); exit();
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Reports</title></head>
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
      <h3>Reports</h3>
      <p>
        <a href="reports.php?export=trips">Export Trips CSV</a> ·
        <a href="reports.php?export=maintenance">Export Maintenance CSV</a> ·
        <a href="reports.php?export=inventory">Export Inventory CSV</a>
      </p>
    </div>
  </div>
</div>
</body></html>
