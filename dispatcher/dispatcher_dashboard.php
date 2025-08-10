<?php
// dispatcher/dispatcher_dashboard.php
require '../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['dispatcher']);
include '../includes/dispatcher_navbar.php';

// compute KPIs
$today_start = date('Y-m-d').' 00:00:00';
$today_end = date('Y-m-d').' 23:59:59';

$counts = ['ongoing'=>0,'completed'=>0,'pending'=>0];
$stmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM trips WHERE created_at BETWEEN ? AND ? GROUP BY status");
$stmt->bind_param("ss",$today_start,$today_end);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()){
  $counts[$r['status']] = (int)$r['cnt'];
}
$stmt->close();

// fetch active trips
$trips = [];
$q = "SELECT t.*, d.name as driver_name, v.plate_no, v.model 
      FROM trips t
      LEFT JOIN drivers d ON t.driver_id = d.id
      LEFT JOIN vehicles v ON t.vehicle_id = v.id
      WHERE t.status IN ('ongoing','pending') ORDER BY t.scheduled_time ASC LIMIT 100";
$rs = $conn->query($q);
while($row = $rs->fetch_assoc()) $trips[] = $row;
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Dispatcher | ViaHale</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body>
  <div style="display:flex; gap:18px; padding:24px;">
    <div class="sidebar">
      <h4>Menu</h4>
      <hr>
      <a href="dispatcher_dashboard.php"> Dashboard</a>
      <a href="create_trip.php"> Create Trip</a>
      <a href="manage_trips.php"> Manage Trips</a>
      <a href="driver_availability.php"> Driver Availability</a>
      <a href="trip_history.php"> Trip History</a>
    </div>

    <div style="flex:1;" class="container-main">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <h2 style="margin:0">Today's Trips Overview</h2>
            <div class="small">As of <?php echo date('F j, Y, g:i A'); ?></div>
          </div>
        </div>
        <div style="height:12px"></div>

        <div class="kpi">
          <div class="item kpi-box blue card">
            <div class="small">Ongoing</div>
            <h3><?php echo $counts['ongoing']; ?></h3>
          </div>
          <div class="item kpi-box green card">
            <div class="small">Completed</div>
            <h3><?php echo $counts['completed']; ?></h3>
          </div>
          <div class="item kpi-box yellow card">
            <div class="small">Pending</div>
            <h3><?php echo $counts['pending']; ?></h3>
          </div>
        </div>
      </div>

      <div class="card">
        <h4>Active Trips</h4>
        <div class="table-responsive">
          <table style="width:100%; border-collapse:collapse">
            <thead style="background:#4311A5;color:#fff">
              <tr>
                <th style="padding:10px">Trip Code</th>
                <th>Passenger</th>
                <th>Driver</th>
                <th>Vehicle</th>
                <th>Destination</th>
                <th>Time</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($trips)):?>
                <tr><td colspan="8" style="padding:12px">No active trips.</td></tr>
              <?php else: foreach($trips as $t): ?>
                <tr>
                  <td style="padding:8px"><?php echo htmlspecialchars($t['trip_code']); ?></td>
                  <td><?php echo htmlspecialchars($t['passenger_name']); ?></td>
                  <td><?php echo htmlspecialchars($t['driver_name']?:'-'); ?></td>
                  <td><?php echo htmlspecialchars($t['plate_no'] ? $t['plate_no'].' ('.$t['model'].')':'-'); ?></td>
                  <td><?php echo htmlspecialchars($t['destination']); ?></td>
                  <td><?php echo date('M j, g:i A',strtotime($t['scheduled_time'])); ?></td>
                  <td><?php echo htmlspecialchars(ucfirst($t['status'])); ?></td>
                  <td>
                    <a href="manage_trips.php?edit=<?php echo $t['id']; ?>">Edit</a> |
                    <a href="manage_trips.php?cancel=<?php echo $t['id']; ?>" onclick="return confirm('Cancel trip?')">Cancel</a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</body>
</html>
