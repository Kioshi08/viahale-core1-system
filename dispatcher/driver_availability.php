<?php
// dispatcher/driver_availability.php (short version)
require '../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['dispatcher']);
include '../includes/dispatcher_navbar.php';

if(isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
  $id = (int)$_GET['toggle'];
  // toggle between available and unavailable (if on_trip stay)
  $d = $conn->query("SELECT status FROM drivers WHERE id=$id")->fetch_assoc();
  if($d){
    $new = $d['status']==='available' ? 'unavailable' : 'available';
    $u = $conn->prepare("UPDATE drivers SET status=? WHERE id=?");
    $u->bind_param("si",$new,$id);
    $u->execute();
    header("Location: driver_availability.php"); exit();
  }
}

$drivers = $conn->query("SELECT * FROM drivers ORDER BY name");
?>
<!doctype html><html><head><meta charset="utf-8"><title>Driver Availability</title>
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
    
  <div style="flex:1" class="container-main">
    <div class="card"><h3>Driver Availability</h3>
      <table style="width:100%;border-collapse:collapse">
        <thead style="background:#4311A5;color:#fff"><tr><th>Name</th><th>Phone</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php while($d = $drivers->fetch_assoc()): ?>
            <tr>
              <td><?php echo htmlspecialchars($d['name']);?></td>
              <td><?php echo htmlspecialchars($d['phone']);?></td>
              <td><?php echo htmlspecialchars($d['status']);?></td>
              <td>
                <?php if($d['status'] !== 'on_trip'): ?>
                  <a href="driver_availability.php?toggle=<?php echo $d['id']; ?>">Toggle</a>
                <?php else: ?>
                  In Trip
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
