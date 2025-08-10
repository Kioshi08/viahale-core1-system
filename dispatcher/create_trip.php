<?php
// dispatcher/create_trip.php
require '../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['dispatcher']);
include '../includes/dispatcher_navbar.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $passenger = $_POST['passenger_name'] ?? '';
    $origin = $_POST['origin'] ?? '';
    $destination = $_POST['destination'] ?? '';
    $driver_id = $_POST['driver_id'] ?: null;
    $vehicle_id = $_POST['vehicle_id'] ?: null;
    $scheduled = $_POST['scheduled_time'] ?: date('Y-m-d H:i:s');

    // generate trip code
    $trip_code = 'TRP'.strtoupper(uniqid());

    $stmt = $conn->prepare("INSERT INTO trips (trip_code, passenger_name, driver_id, vehicle_id, origin, destination, scheduled_time, status) VALUES (?,?,?,?,?,?,?,?)");
    $status = $driver_id && $vehicle_id ? 'ongoing' : 'pending';
    $stmt->bind_param("ssisssss", $trip_code, $passenger, $driver_id, $vehicle_id, $origin, $destination, $scheduled, $status);
    if ($stmt->execute()) {
        // if assigned, mark driver/vehicle as in_use/on_trip
        if ($status === 'ongoing' && $driver_id) {
            $u = $conn->prepare("UPDATE drivers SET status='on_trip' WHERE id=?");
            $u->bind_param("i",$driver_id); $u->execute();
        }
        if ($status === 'ongoing' && $vehicle_id) {
            $u2 = $conn->prepare("UPDATE vehicles SET status='in_use' WHERE id=?");
            $u2->bind_param("i",$vehicle_id); $u2->execute();
        }
        $success = "Trip created (Code: $trip_code).";
    } else {
        $error = "DB error: ".$conn->error;
    }
}

// fetch drivers and vehicles
$drivers = $conn->query("SELECT * FROM drivers ORDER BY name");
$vehicles = $conn->query("SELECT * FROM vehicles ORDER BY plate_no");
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Create Trip</title></head>
<body>
<div style="display:flex; gap:18px; padding:24px;">
  <div class="sidebar">
    <h4>Menu</h4><hr>
    <a href="dispatcher_dashboard.php"> Dashboard</a>
    <a href="create_trip.php"> Create Trip</a>
    <a href="manage_trips.php"> Manage Trips</a>
    <a href="driver_availability.php"> Driver Availability</a>
    <a href="trip_history.php"> Trip History</a>
  </div>

  <div style="flex:1;" class="container-main">
    <div class="card">
      <h3>Create Trip</h3>
      <?php if($error):?><div style="color:red;"><?php echo $error;?></div><?php endif;?>
      <?php if($success):?><div style="color:green;"><?php echo $success;?></div><?php endif;?>

      <form method="post">
        <div style="margin:8px 0">
          <label>Passenger name</label><br>
          <input type="text" name="passenger_name" required style="width:100%;padding:8px;border-radius:8px">
        </div>
        <div style="margin:8px 0">
          <label>Origin</label><br>
          <input type="text" name="origin" required style="width:100%;padding:8px;border-radius:8px">
        </div>
        <div style="margin:8px 0">
          <label>Destination</label><br>
          <input type="text" name="destination" required style="width:100%;padding:8px;border-radius:8px">
        </div>
        <div style="display:flex;gap:12px;">
          <div style="flex:1">
            <label>Assign Driver (optional)</label><br>
            <select name="driver_id" style="width:100%;padding:8px;border-radius:8px">
              <option value="">-- Select driver --</option>
              <?php while($d = $drivers->fetch_assoc()): ?>
                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name'].' ('.$d['status'].')'); ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div style="flex:1">
            <label>Assign Vehicle (optional)</label><br>
            <select name="vehicle_id" style="width:100%;padding:8px;border-radius:8px">
              <option value="">-- Select vehicle --</option>
              <?php while($v = $vehicles->fetch_assoc()): ?>
                <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['plate_no'].' - '.$v['model'].' ('.$v['status'].')'); ?></option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>

        <div style="margin-top:12px">
          <label>Scheduled time</label><br>
          <input type="datetime-local" name="scheduled_time" style="padding:8px;border-radius:8px">
        </div>

        <div style="margin-top:12px">
          <button type="submit" style="background:var(--vh-color1);color:#fff;padding:10px 16px;border-radius:8px;border:none;">Create Trip</button>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
