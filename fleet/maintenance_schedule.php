<?php
// /fleet/maintenance_schedule.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['fleetstaff', 'admin1']);
include __DIR__ . '/../includes/fleet_navbar.php';

$error=''; $success='';

// add schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_schedule'])) {
    $vehicle_id = (int)$_POST['vehicle_id'];
    $date = $_POST['scheduled_date'];
    $type = $_POST['type'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $created_by = $_SESSION['username'];

    $stmt = $conn->prepare("INSERT INTO maintenance_schedule (vehicle_id, scheduled_date, type, notes, created_by) VALUES (?,?,?,?,?)");
    $stmt->bind_param("issss", $vehicle_id, $date, $type, $notes, $created_by);
    if ($stmt->execute()) $success = "Schedule added.";
    else $error = $stmt->error;
    $stmt->close();
}

// mark done / cancel
if (isset($_GET['action']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($_GET['action'] === 'done') {
        $u = $conn->prepare("UPDATE maintenance_schedule SET status='done' WHERE id=?");
        $u->bind_param("i",$id); $u->execute();
        $success = "Marked as done.";
    } elseif ($_GET['action']==='cancel') {
        $u = $conn->prepare("UPDATE maintenance_schedule SET status='cancelled' WHERE id=?");
        $u->bind_param("i",$id); $u->execute();
        $success = "Cancelled.";
    }
}

// fetch lists
$schedules = $conn->query("SELECT m.*, v.plate_no, v.model FROM maintenance_schedule m LEFT JOIN vehicles v ON m.vehicle_id=v.id ORDER BY m.scheduled_date ASC LIMIT 500");
$vehicles = $conn->query("SELECT id, plate_no FROM vehicles ORDER BY plate_no");
?>
<!doctype html><html><head><meta charset="utf-8"><title>Maintenance Schedule</title></head>
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
      <h3>Add Maintenance Schedule</h3>
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
          <input type="date" name="scheduled_date" required>
          <input name="type" placeholder="Type e.g. Oil Change">
        </div>
        <div style="margin-top:8px">
          <input name="notes" placeholder="Notes" style="width:60%">
          <button type="submit" name="add_schedule" style="background:#6532C9;color:#fff;padding:8px 12px">Add</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h4>Upcoming Maintenances</h4>
      <table style="width:100%;border-collapse:collapse">
        <thead style="background:#4311A5;color:#fff"><tr><th>Vehicle</th><th>Date</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php while($s = $schedules->fetch_assoc()): ?>
            <tr>
              <td><?php echo htmlspecialchars($s['plate_no'].' '.$s['model']);?></td>
              <td><?php echo htmlspecialchars($s['scheduled_date']);?></td>
              <td><?php echo htmlspecialchars($s['type']);?></td>
              <td><?php echo htmlspecialchars(ucfirst($s['status']));?></td>
              <td>
                <?php if($s['status']==='scheduled'): ?>
                  <a href="maintenance_schedule.php?action=done&id=<?php echo $s['id'];?>">Mark Done</a> |
                  <a href="maintenance_schedule.php?action=cancel&id=<?php echo $s['id'];?>">Cancel</a>
                <?php else: echo '-'; endif;?>
              </td>
            </tr>
          <?php endwhile;?>
        </tbody>
      </table>
    </div>

  </div>
</div>
</body></html>
