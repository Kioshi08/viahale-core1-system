<?php
// /fleet/repair_logs.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['fleetstaff']);
include __DIR__ . '/../includes/fleet_navbar.php';

$error=''; $success='';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_log'])) {
    $vehicle_id = (int)$_POST['vehicle_id'];
    $desc = $_POST['description'];
    $cost = floatval($_POST['cost'] ?: 0);
    $performed_by = $_POST['performed_by'] ?: $_SESSION['username'];
    $created_by = $_SESSION['username'];

    $stmt = $conn->prepare("INSERT INTO repair_logs (vehicle_id, description, cost, performed_by, created_by) VALUES (?,?,?,?,?)");
    $stmt->bind_param("isdss", $vehicle_id, $desc, $cost, $performed_by, $created_by);
    if ($stmt->execute()) $success = "Repair log saved.";
    else $error = $stmt->error;
    $stmt->close();
}

$vehicles = $conn->query("SELECT id, plate_no FROM vehicles ORDER BY plate_no");
$logs = $conn->query("SELECT r.*, v.plate_no FROM repair_logs r LEFT JOIN vehicles v ON r.vehicle_id=v.id ORDER BY r.log_date DESC LIMIT 500");
?>
<!doctype html><html><head><meta charset="utf-8"><title>Repair Logs</title></head>
<body>
<div style="display:flex;gap:18px;padding:24px">
  <div class="sidebar"><h4>Menu</h4><hr>
    <a href="../fleet/dashboard.php"> Dashboard</a>
    <a href="../fleet/manage_vehicles.php"> Manage Vehicles</a>
    <a href="../fleet/maintenance_schedule.php"> Maintenance Schedule</a>
    <a href="../fleet/repair_logs.php"> Repair Logs</a>
  </div>

  <div style="flex:1" class="container-main">
    <div class="card">
      <h3>Add Repair Log</h3>
      <?php if($error):?><div style="color:red"><?php echo htmlspecialchars($error);?></div><?php endif;?>
      <?php if($success):?><div style="color:green"><?php echo htmlspecialchars($success);?></div><?php endif;?>
      <form method="post">
        <div style="display:flex;gap:8px">
          <select name="vehicle_id" required>
            <option value="">-- Select vehicle --</option>
            <?php while($v = $vehicles->fetch_assoc()): ?>
              <option value="<?php echo $v['id'];?>"><?php echo htmlspecialchars($v['plate_no']);?></option>
            <?php endwhile;?>
          </select>
          <input name="performed_by" placeholder="Performed by (mechanic)">
          <input name="cost" type="number" step="0.01" placeholder="Cost">
        </div>
        <div style="margin-top:8px">
          <textarea name="description" placeholder="Description" style="width:80%;height:80px"></textarea>
        </div>
        <div style="margin-top:8px">
          <button name="add_log" type="submit" style="background:#6532C9;color:#fff;padding:8px 12px">Save Log</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h4>Recent Repair Logs</h4>
      <table style="width:100%;border-collapse:collapse">
        <thead style="background:#4311A5;color:#fff"><tr><th>Date</th><th>Vehicle</th><th>Description</th><th>Cost</th><th>Performed by</th></tr></thead>
        <tbody>
        <?php while($l = $logs->fetch_assoc()): ?>
          <tr>
            <td><?php echo date('M j, Y g:i A', strtotime($l['log_date']));?></td>
            <td><?php echo htmlspecialchars($l['plate_no']);?></td>
            <td><?php echo htmlspecialchars($l['description']);?></td>
            <td><?php echo number_format($l['cost'],2);?></td>
            <td><?php echo htmlspecialchars($l['performed_by']);?></td>
          </tr>
        <?php endwhile;?>
        </tbody>
      </table>
    </div>

  </div>
</div>
</body></html>
