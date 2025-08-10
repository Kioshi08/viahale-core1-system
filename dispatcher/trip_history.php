<?php
// dispatcher/trip_history.php (simple)
require '../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['dispatcher', 'admin1']);
include '../includes/dispatcher_navbar.php';

$q = $conn->query("SELECT t.*, d.name as driver_name, v.plate_no FROM trips t 
                   LEFT JOIN drivers d ON t.driver_id=d.id
                   LEFT JOIN vehicles v ON t.vehicle_id=v.id
                   WHERE t.status IN ('completed','cancelled') ORDER BY t.created_at DESC LIMIT 200");
?>
<!doctype html><html><head><meta charset="utf-8"><title>Trip History</title>
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


  <div style="flex:1" class="container-main card">
    <h3>Trip History</h3>
    <table style="width:100%;border-collapse:collapse">
      <thead style="background:#4311A5;color:#fff"><tr><th>Code</th><th>Passenger</th><th>Driver</th><th>Vehicle</th><th>Origin</th><th>Destination</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php while($t = $q->fetch_assoc()): ?>
        <tr>
          <td><?php echo htmlspecialchars($t['trip_code']);?></td>
          <td><?php echo htmlspecialchars($t['passenger_name']);?></td>
          <td><?php echo htmlspecialchars($t['driver_name']?:'-');?></td>
          <td><?php echo htmlspecialchars($t['plate_no']?:'-');?></td>
          <td><?php echo htmlspecialchars($t['origin']);?></td>
          <td><?php echo htmlspecialchars($t['destination']);?></td>
          <td><?php echo htmlspecialchars($t['status']);?></td>
          <td><?php echo date('M j, Y g:i A',strtotime($t['created_at']));?></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
</body></html>
