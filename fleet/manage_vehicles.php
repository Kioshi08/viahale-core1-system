<?php
// /fleet/manage_vehicles.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['fleetstaff']);
include __DIR__ . '/../includes/fleet_navbar.php';

$error=''; $success='';

// Create/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plate = $_POST['plate_no'] ?? '';
    $model = $_POST['model'] ?? '';
    $year = $_POST['make_year'] ?: null;
    $notes = $_POST['notes'] ?? '';
    $status = in_array($_POST['status'] ?? 'available', ['available','in_use','maintenance']) ? $_POST['status'] : 'available';

    if (isset($_POST['create'])) {
        $stmt = $conn->prepare("INSERT INTO vehicles (plate_no, model, make_year, status, notes) VALUES (?,?,?,?,?)");
        $stmt->bind_param("ssiss", $plate, $model, $year, $status, $notes);
        if ($stmt->execute()) $success = "Vehicle added.";
        else $error = $stmt->error;
        $stmt->close();
    }

    if (isset($_POST['update'])) {
        $id = (int)$_POST['vehicle_id'];
        $stmt = $conn->prepare("UPDATE vehicles SET plate_no=?, model=?, make_year=?, status=?, notes=? WHERE id=?");
        $stmt->bind_param("ssissi", $plate, $model, $year, $status, $notes, $id);
        if ($stmt->execute()) $success = "Vehicle updated.";
        else $error = $stmt->error;
        $stmt->close();
    }
}

// Delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $d = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM vehicles WHERE id=?");
    $stmt->bind_param("i",$d);
    $stmt->execute();
    header("Location: manage_vehicles.php"); exit();
}

// For edit form
$editVehicle = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $s = $conn->prepare("SELECT * FROM vehicles WHERE id=?");
    $s->bind_param("i",$id); $s->execute(); $res = $s->get_result();
    if ($res->num_rows) $editVehicle = $res->fetch_assoc();
    $s->close();
}

// list
$vehicles = $conn->query("SELECT * FROM vehicles ORDER BY id DESC LIMIT 500");
?>
<!doctype html><html><head><meta charset="utf-8"><title>Manage Vehicles</title></head>
<body>
<div style="display:flex;gap:18px;padding:24px">
  <div class="sidebar">
    <h4>Menu</h4><hr>
    <a href="../fleet/dashboard.php"> Dashboard</a>
    <a href="../fleet/manage_vehicles.php"> Manage Vehicles</a>
    <a href="../fleet/maintenance_schedule.php"> Maintenance Schedule</a>
    <a href="../fleet/repair_logs.php"> Repair Logs</a>
  </div>

  <div style="flex:1" class="container-main">
    <div class="card">
      <h3><?php echo $editVehicle ? 'Edit Vehicle' : 'Add Vehicle'; ?></h3>
      <?php if($error):?><div style="color:red"><?php echo htmlspecialchars($error);?></div><?php endif;?>
      <?php if($success):?><div style="color:green"><?php echo htmlspecialchars($success);?></div><?php endif;?>
      <form method="post">
        <?php if($editVehicle): ?><input type="hidden" name="vehicle_id" value="<?php echo (int)$editVehicle['id']; ?>"><?php endif; ?>
        <div style="display:flex;gap:8px">
          <input name="plate_no" required placeholder="Plate No" value="<?php echo $editVehicle['plate_no'] ?? ''; ?>" style="flex:1;padding:8px">
          <input name="model" placeholder="Model" value="<?php echo $editVehicle['model'] ?? ''; ?>" style="flex:1;padding:8px">
          <input name="make_year" type="number" placeholder="Year" value="<?php echo $editVehicle['make_year'] ?? ''; ?>" style="width:100px;padding:8px">
        </div>
        <div style="margin-top:8px">
          <select name="status" style="padding:8px">
            <option value="available" <?php if(($editVehicle['status'] ?? '')==='available') echo 'selected'; ?>>Available</option>
            <option value="in_use" <?php if(($editVehicle['status'] ?? '')==='in_use') echo 'selected'; ?>>In Use</option>
            <option value="maintenance" <?php if(($editVehicle['status'] ?? '')==='maintenance') echo 'selected'; ?>>Maintenance</option>
          </select>
          <input name="notes" placeholder="Notes" value="<?php echo $editVehicle['notes'] ?? ''; ?>" style="width:60%;padding:8px;margin-left:8px">
        </div>
        <div style="margin-top:10px">
          <?php if($editVehicle): ?>
            <button type="submit" name="update" style="background:#6532C9;color:#fff;padding:8px 12px">Update</button>
            <a href="manage_vehicles.php" style="margin-left:8px">Cancel</a>
          <?php else: ?>
            <button type="submit" name="create" style="background:#6532C9;color:#fff;padding:8px 12px">Create</button>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <h4>All Vehicles</h4>
      <table style="width:100%;border-collapse:collapse">
        <thead style="background:#4311A5;color:#fff"><tr><th>Plate</th><th>Model</th><th>Year</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php while($v = $vehicles->fetch_assoc()): ?>
          <tr>
            <td style="padding:8px"><?php echo htmlspecialchars($v['plate_no']);?></td>
            <td><?php echo htmlspecialchars($v['model']);?></td>
            <td><?php echo htmlspecialchars($v['make_year']);?></td>
            <td><?php echo htmlspecialchars(ucfirst($v['status']));?></td>
            <td>
              <a href="manage_vehicles.php?edit=<?php echo $v['id'];?>">Edit</a> |
              <a href="manage_vehicles.php?delete=<?php echo $v['id'];?>" onclick="return confirm('Delete vehicle?')">Delete</a>
            </td>
          </tr>
        <?php endwhile;?>
        </tbody>
      </table>
    </div>

  </div>
</div>
</body></html>
