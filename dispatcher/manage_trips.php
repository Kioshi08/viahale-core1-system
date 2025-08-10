<?php
// dispatcher/manage_trips.php
require '../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['dispatcher']);
include '../includes/dispatcher_navbar.php';

$error = '';
$success = '';

// --- Cancel flow -----------------------------------------------------------
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $trip_id = (int)$_GET['cancel'];

    // get trip info
    $stmt = $conn->prepare("SELECT driver_id, vehicle_id, status FROM trips WHERE id = ?");
    $stmt->bind_param("i", $trip_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows) {
        $trip = $res->fetch_assoc();

        // set trip to cancelled
        $u = $conn->prepare("UPDATE trips SET status = 'cancelled' WHERE id = ?");
        $u->bind_param("i", $trip_id);
        $u->execute();

        // free driver if assigned and not on another trip
        if (!empty($trip['driver_id'])) {
            $upd = $conn->prepare("UPDATE drivers SET status = 'available' WHERE id = ? AND status != 'on_trip'");
            $upd->bind_param("i", $trip['driver_id']);
            $upd->execute();
        }

        // free vehicle if assigned
        if (!empty($trip['vehicle_id'])) {
            $upd2 = $conn->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
            $upd2->bind_param("i", $trip['vehicle_id']);
            $upd2->execute();
        }

        $success = "Trip cancelled successfully.";
    } else {
        $error = "Trip not found.";
    }
}

// --- Edit/Update flow -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_trip'])) {
    $trip_id = (int)$_POST['trip_id'];
    $passenger = $_POST['passenger_name'] ?? '';
    $origin = $_POST['origin'] ?? '';
    $destination = $_POST['destination'] ?? '';
    $scheduled_time = $_POST['scheduled_time'] ?: null;
    $new_driver_id = $_POST['driver_id'] !== '' ? (int)$_POST['driver_id'] : null;
    $new_vehicle_id = $_POST['vehicle_id'] !== '' ? (int)$_POST['vehicle_id'] : null;
    $new_status = in_array($_POST['status'], ['pending','ongoing','completed','cancelled']) ? $_POST['status'] : 'pending';

    // fetch current trip data
    $stmt = $conn->prepare("SELECT driver_id, vehicle_id, status FROM trips WHERE id = ?");
    $stmt->bind_param("i", $trip_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!$res->num_rows) {
        $error = "Trip not found.";
    } else {
        $old = $res->fetch_assoc();
        $old_driver = $old['driver_id'] ? (int)$old['driver_id'] : null;
        $old_vehicle = $old['vehicle_id'] ? (int)$old['vehicle_id'] : null;
        $old_status = $old['status'];

        // Update trip
        $upd = $conn->prepare("UPDATE trips SET passenger_name=?, origin=?, destination=?, scheduled_time=?, driver_id=?, vehicle_id=?, status=? WHERE id=?");
        // for scheduled_time allow null
        if ($scheduled_time === null) {
            $sched = null;
        } else {
            // attempt to convert to proper datetime format
            $sched = date('Y-m-d H:i:s', strtotime($scheduled_time));
        }
        // bind params (use s for datetime or null handling via variable)
        $upd->bind_param("ssssisisi", $passenger, $origin, $destination, $sched, $new_driver_id, $new_vehicle_id, $new_status, $trip_id);
        // Note: PHP's bind_param can't bind nulls using "i" or "s" easily; workaround is to use variables and pass nulls as NULL string.
        // To be safer, we'll build a prepared statement dynamically depending on nulls:

        // Close earlier prepared and redo safer update
        $upd->close();

        $update_sql = "UPDATE trips SET passenger_name=?, origin=?, destination=?, scheduled_time=?, driver_id=?, vehicle_id=?, status=? WHERE id=?";
        $stmt2 = $conn->prepare($update_sql);
        // handle nulls for driver_id and vehicle_id by sending NULLs properly
        if ($new_driver_id === null) {
            $driver_param = null;
        } else {
            $driver_param = $new_driver_id;
        }
        if ($new_vehicle_id === null) {
            $vehicle_param = null;
        } else {
            $vehicle_param = $new_vehicle_id;
        }
        // bind with types: s s s s i i s i (but if nulls present, still OK to bind as i with null)
        $stmt2->bind_param("ssssisis", $passenger, $origin, $destination, $sched, $driver_param, $vehicle_param, $new_status, $trip_id);
        $ok = $stmt2->execute();

        if ($ok) {
            // If driver changed: free old driver if exists and not used elsewhere, and mark new driver as on_trip if status is ongoing
            if ($old_driver && $old_driver !== $new_driver_id) {
                $rel = $conn->prepare("UPDATE drivers SET status = 'available' WHERE id = ? AND status != 'on_trip'");
                $rel->bind_param("i", $old_driver);
                $rel->execute();
            }

            if ($new_driver_id && ($old_driver !== $new_driver_id)) {
                // assign new driver: set to on_trip if trip status ongoing, else keep available
                $new_status_for_driver = $new_status === 'ongoing' ? 'on_trip' : 'available';
                $r2 = $conn->prepare("UPDATE drivers SET status = ? WHERE id = ?");
                $r2->bind_param("si", $new_status_for_driver, $new_driver_id);
                $r2->execute();
            } else if ($new_driver_id && $new_status === 'completed') {
                // if trip completed, ensure driver freed
                $r3 = $conn->prepare("UPDATE drivers SET status = 'available' WHERE id = ?");
                $r3->bind_param("i", $new_driver_id);
                $r3->execute();
            }

            // Vehicle change handling similar
            if ($old_vehicle && $old_vehicle !== $new_vehicle_id) {
                $relv = $conn->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
                $relv->bind_param("i", $old_vehicle);
                $relv->execute();
            }

            if ($new_vehicle_id && ($old_vehicle !== $new_vehicle_id)) {
                $new_status_for_vehicle = $new_status === 'ongoing' ? 'in_use' : 'available';
                $r4 = $conn->prepare("UPDATE vehicles SET status = ? WHERE id = ?");
                $r4->bind_param("si", $new_status_for_vehicle, $new_vehicle_id);
                $r4->execute();
            } else if ($new_vehicle_id && $new_status === 'completed') {
                $r5 = $conn->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
                $r5->bind_param("i", $new_vehicle_id);
                $r5->execute();
            }

            // If status changed to cancelled, free assigned driver/vehicle
            if ($new_status === 'cancelled') {
                if ($new_driver_id) {
                    $f1 = $conn->prepare("UPDATE drivers SET status = 'available' WHERE id = ? AND status != 'on_trip'");
                    $f1->bind_param("i", $new_driver_id);
                    $f1->execute();
                }
                if ($new_vehicle_id) {
                    $f2 = $conn->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
                    $f2->bind_param("i", $new_vehicle_id);
                    $f2->execute();
                }
            }

            // If status set to completed, free driver & vehicle
            if ($new_status === 'completed') {
                if ($new_driver_id) {
                    $fc1 = $conn->prepare("UPDATE drivers SET status = 'available' WHERE id = ?");
                    $fc1->bind_param("i", $new_driver_id);
                    $fc1->execute();
                }
                if ($new_vehicle_id) {
                    $fc2 = $conn->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
                    $fc2->bind_param("i", $new_vehicle_id);
                    $fc2->execute();
                }
            }

            $success = "Trip updated successfully.";
        } else {
            $error = "Failed to update trip: " . $stmt2->error;
        }

        $stmt2->close();
    }
}

// --- Fetch trips for listing ------------------------------------------------
$trips = [];
$q = "SELECT t.*, d.name as driver_name, v.plate_no, v.model 
      FROM trips t
      LEFT JOIN drivers d ON t.driver_id = d.id
      LEFT JOIN vehicles v ON t.vehicle_id = v.id
      ORDER BY t.created_at DESC LIMIT 500";
$rs = $conn->query($q);
while ($r = $rs->fetch_assoc()) $trips[] = $r;

// --- If edit requested, load trip data --------------------------------------
$edit_trip = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $s = $conn->prepare("SELECT * FROM trips WHERE id = ?");
    $s->bind_param("i", $eid);
    $s->execute();
    $res = $s->get_result();
    if ($res->num_rows) {
        $edit_trip = $res->fetch_assoc();
    }
    $s->close();
}

// fetch drivers & vehicles for selects
$drivers = $conn->query("SELECT * FROM drivers ORDER BY name");
$vehicles = $conn->query("SELECT * FROM vehicles ORDER BY plate_no");
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Manage Trips - Dispatcher</title>
</head>
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
        <h3>Manage Trips</h3>

        <?php if ($error): ?>
          <div style="color:red;padding:8px;border-left:4px solid #f00;margin-bottom:10px"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
          <div style="color:green;padding:8px;border-left:4px solid #0a0;margin-bottom:10px"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($edit_trip): ?>
          <h4>Edit Trip: <?php echo htmlspecialchars($edit_trip['trip_code']); ?></h4>
          <form method="post">
            <input type="hidden" name="trip_id" value="<?php echo (int)$edit_trip['id']; ?>">
            <div style="margin:8px 0">
              <label>Passenger name</label><br>
              <input type="text" name="passenger_name" required value="<?php echo htmlspecialchars($edit_trip['passenger_name']); ?>" style="width:100%;padding:8px;border-radius:8px">
            </div>
            <div style="margin:8px 0">
              <label>Origin</label><br>
              <input type="text" name="origin" required value="<?php echo htmlspecialchars($edit_trip['origin']); ?>" style="width:100%;padding:8px;border-radius:8px">
            </div>
            <div style="margin:8px 0">
              <label>Destination</label><br>
              <input type="text" name="destination" required value="<?php echo htmlspecialchars($edit_trip['destination']); ?>" style="width:100%;padding:8px;border-radius:8px">
            </div>

            <div style="display:flex;gap:12px">
              <div style="flex:1">
                <label>Assign Driver (optional)</label><br>
                <select name="driver_id" style="width:100%;padding:8px;border-radius:8px">
                  <option value="">-- Select driver --</option>
                  <?php
                  // reset driver result pointer
                  $drivers->data_seek(0);
                  while ($d = $drivers->fetch_assoc()): ?>
                    <option value="<?php echo $d['id']; ?>" <?php if ($d['id'] == $edit_trip['driver_id']) echo 'selected'; ?>>
                      <?php echo htmlspecialchars($d['name'] . ' (' . $d['status'] . ')'); ?>
                    </option>
                  <?php endwhile; ?>
                </select>
              </div>

              <div style="flex:1">
                <label>Assign Vehicle (optional)</label><br>
                <select name="vehicle_id" style="width:100%;padding:8px;border-radius:8px">
                  <option value="">-- Select vehicle --</option>
                  <?php
                  $vehicles->data_seek(0);
                  while ($v = $vehicles->fetch_assoc()): ?>
                    <option value="<?php echo $v['id']; ?>" <?php if ($v['id'] == $edit_trip['vehicle_id']) echo 'selected'; ?>>
                      <?php echo htmlspecialchars($v['plate_no'] . ' - ' . $v['model'] . ' (' . $v['status'] . ')'); ?>
                    </option>
                  <?php endwhile; ?>
                </select>
              </div>
            </div>

            <div style="margin-top:12px">
              <label>Scheduled time</label><br>
              <input type="datetime-local" name="scheduled_time" value="<?php echo $edit_trip['scheduled_time'] ? date('Y-m-d\TH:i', strtotime($edit_trip['scheduled_time'])) : ''; ?>" style="padding:8px;border-radius:8px">
            </div>

            <div style="margin-top:12px">
              <label>Status</label><br>
              <select name="status" style="padding:8px;border-radius:8px">
                <option value="pending" <?php if ($edit_trip['status']=='pending') echo 'selected'; ?>>Pending</option>
                <option value="ongoing" <?php if ($edit_trip['status']=='ongoing') echo 'selected'; ?>>Ongoing</option>
                <option value="completed" <?php if ($edit_trip['status']=='completed') echo 'selected'; ?>>Completed</option>
                <option value="cancelled" <?php if ($edit_trip['status']=='cancelled') echo 'selected'; ?>>Cancelled</option>
              </select>
            </div>

            <div style="margin-top:12px">
              <button type="submit" name="update_trip" style="background:var(--vh-color1);color:#fff;padding:10px 16px;border-radius:8px;border:none;">Update Trip</button>
              <a href="manage_trips.php" style="margin-left:12px;">Cancel</a>
            </div>
          </form>

        <?php else: ?>

          <h4>All Trips</h4>
          <div class="table-responsive">
            <table style="width:100%;border-collapse:collapse">
              <thead style="background:#4311A5;color:#fff">
                <tr>
                  <th style="padding:10px">Trip Code</th>
                  <th>Passenger</th>
                  <th>Driver</th>
                  <th>Vehicle</th>
                  <th>Origin</th>
                  <th>Destination</th>
                  <th>Scheduled</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($trips)): ?>
                  <tr><td colspan="9" style="padding:12px">No trips found.</td></tr>
                <?php else: foreach ($trips as $t): ?>
                  <tr>
                    <td style="padding:8px"><?php echo htmlspecialchars($t['trip_code']); ?></td>
                    <td><?php echo htmlspecialchars($t['passenger_name']); ?></td>
                    <td><?php echo htmlspecialchars($t['driver_name'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($t['plate_no'] ? $t['plate_no'].' ('.$t['model'].')' : '-'); ?></td>
                    <td><?php echo htmlspecialchars($t['origin']); ?></td>
                    <td><?php echo htmlspecialchars($t['destination']); ?></td>
                    <td><?php echo $t['scheduled_time'] ? date('M j, Y g:i A', strtotime($t['scheduled_time'])) : '-'; ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($t['status'])); ?></td>
                    <td>
                      <a href="manage_trips.php?edit=<?php echo (int)$t['id']; ?>">Edit</a> |
                      <?php if ($t['status'] !== 'cancelled' && $t['status'] !== 'completed'): ?>
                        <a href="manage_trips.php?cancel=<?php echo (int)$t['id']; ?>" onclick="return confirm('Cancel this trip?')">Cancel</a>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

        <?php endif; ?>

      </div>
    </div>
  </div>
</body>
</html>
