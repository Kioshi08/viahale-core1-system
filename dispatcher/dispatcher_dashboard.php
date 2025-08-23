<?php
// dispatcher_dashboard.php — ViaHale Dispatcher (Enhanced Single-File)
// Adds: customer_contact, trip_assignments logging, reassign flow, complete/cancel, comms autofill.

// ========== CONFIG ==========
session_start();
$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_PORT = getenv('DB_PORT') ?: '3307';
$DB_NAME = getenv('DB_NAME') ?: 'otp_login';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: '';
$SOCKET_IO_URL = getenv('SOCKET_IO_URL') ?: 'http://localhost:3001';
$NOMINATIM_EMAIL = getenv('NOMINATIM_EMAIL') ?: 'youremail@example.com'; // change to your contact email

// ViaHale branding
$V_PRIMARY = '#6532C9';
$V_DARK   = '#4311A5';
$V_ACCENT = '#9A66FF';

// ========== DB CONNECTION ==========
try {
    $pdo = new PDO("mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    http_response_code(500);
    echo "DB connection failed: " . htmlspecialchars($e->getMessage());
    exit;
}

// ========== AUTHORIZATION ==========
$username = $_SESSION['username'] ?? null;
if (!$username) { header('Location: login.php'); exit; }
$allowed = false;
try {
    $q = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME='users' AND COLUMN_NAME='role'");
    $q->execute(['db' => $DB_NAME]);
    if ($q->fetch()) {
        $r = $pdo->prepare('SELECT role FROM users WHERE username=:u LIMIT 1');
        $r->execute(['u' => $username]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['role'] === 'dispatcher') $allowed = true;
    } else {
        $allowed = in_array($username, ['dispatcher','admin1'], true);
    }
} catch (Exception $e) { $allowed = in_array($username, ['dispatcher','admin1'], true); }
if (!$allowed) { http_response_code(403); echo 'Access denied'; exit; }

// ========== UTILITIES ==========
function notify_socket($type, $socketUrl) {
    $url = rtrim($socketUrl, '/') . '/update?type=' . urlencode($type);
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 0.5]]);
    @file_get_contents($url, false, $ctx);
}
function haversine($lat1,$lon1,$lat2,$lon2){
    $R=6371;
    $dLat=deg2rad($lat2-$lat1);
    $dLon=deg2rad($lon2-$lon1);
    $a=sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
    return $R*2*atan2(sqrt($a), sqrt(1-$a));
}
function geocode_address($address, $email){
    if (!$address) return null;
    $q = http_build_query(['q'=>$address,'format'=>'json','limit'=>1,'addressdetails'=>1,'email'=>$email]);
    $url = "https://nominatim.openstreetmap.org/search?{$q}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_USERAGENT=>'ViaHale-Dispatcher/1.0', CURLOPT_TIMEOUT=>6]);
    $out = curl_exec($ch);
    if ($out === false) return null;
    $arr = json_decode($out, true);
    if (!$arr || !isset($arr[0])) return null;
    return ['lat' => (float)$arr[0]['lat'], 'lng' => (float)$arr[0]['lon'], 'display_name' => $arr[0]['display_name'] ?? ''];
}

// Ensure columns exist (idempotent safety)
try { $pdo->exec("ALTER TABLE trips ADD COLUMN customer_contact VARCHAR(50) NULL AFTER passenger_name"); } catch(Exception $e) {}
try { 
    $pdo->exec("CREATE TABLE IF NOT EXISTS trip_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trip_id INT NOT NULL,
        driver_id INT NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('assigned','reassigned','cancelled') DEFAULT 'assigned',
        FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
        FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}
try { $pdo->exec("CREATE INDEX idx_drivers_status ON drivers(status)"); } catch(Exception $e) {}
try { $pdo->exec("CREATE INDEX idx_driver_location ON drivers(current_location_lat, current_location_lng)"); } catch(Exception $e) {}

// ========== AJAX endpoints ==========
$action = $_GET['action'] ?? null;
if ($action) header('Content-Type: application/json; charset=utf-8');

if ($action === 'getDrivers') {
    $stmt = $pdo->query("SELECT id,name,phone,status,current_location_lat,current_location_lng,shift_end_time,rating_average FROM drivers");
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($drivers as &$d) {
        $d['current_location_lat'] = $d['current_location_lat'] !== null ? (float)$d['current_location_lat'] : null;
        $d['current_location_lng'] = $d['current_location_lng'] !== null ? (float)$d['current_location_lng'] : null;
        $d['rating_average'] = $d['rating_average'] !== null ? (float)$d['rating_average'] : 5.0;
    }
    echo json_encode(['drivers'=>$drivers]); exit;
}

if ($action === 'getTrips') {
    $stmt = $pdo->query("SELECT t.*, d.name AS driver_name, d.phone AS driver_phone 
                         FROM trips t 
                         LEFT JOIN drivers d ON d.id = t.driver_id 
                         ORDER BY t.priority DESC, t.created_at ASC");
    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['trips'=>$trips]); exit;
}

// Create trip (server geocodes origin/destination and inserts)
if ($action === 'createTrip' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $passenger = trim($b['passenger_name'] ?? 'Passenger');
    $customer_contact = trim($b['customer_contact'] ?? '');
    $origin = trim($b['origin'] ?? '');
    $destination = trim($b['destination'] ?? '');
    $priority = (int)($b['priority'] ?? 0);
    if (!$origin || !$destination) { echo json_encode(['error'=>'origin and destination required']); exit; }

    $geoO = geocode_address($origin, $NOMINATIM_EMAIL);
    usleep(200000);
    $geoD = geocode_address($destination, $NOMINATIM_EMAIL);

    $trip_code = 'TRP'.strtoupper(substr(md5(uniqid('', true)), 0, 12));
    try {
        $sql = "INSERT INTO trips (trip_code, passenger_name, customer_contact, origin, destination, scheduled_time, priority, status, created_at, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng)
                VALUES (:tc,:pn,:cc,:o,:d, NOW(), :p, 'pending', NOW(), :plat, :plng, :dlat, :dlng)";
        $st = $pdo->prepare($sql);
        $st->execute([
            'tc'=>$trip_code,'pn'=>$passenger,'cc'=>$customer_contact,'o'=>$origin,'d'=>$destination,'p'=>$priority,
            'plat'=>$geoO['lat']??null,'plng'=>$geoO['lng']??null,'dlat'=>$geoD['lat']??null,'dlng'=>$geoD['lng']??null
        ]);
        $trip_id = $pdo->lastInsertId();
    } catch (Exception $e) {
        $st = $pdo->prepare("INSERT INTO trips (trip_code, passenger_name, customer_contact, origin, destination, scheduled_time, priority, status, created_at) VALUES (:tc,:pn,:cc,:o,:d, NOW(), :p, 'pending', NOW())");
        $st->execute(['tc'=>$trip_code,'pn'=>$passenger,'cc'=>$customer_contact,'o'=>$origin,'d'=>$destination,'p'=>$priority]);
        $trip_id = $pdo->lastInsertId();
    }

    notify_socket('trips', $SOCKET_IO_URL);
    echo json_encode(['success'=>true,'trip_code'=>$trip_code,'trip_id'=>$trip_id,'pickup'=>$geoO,'dropoff'=>$geoD]); exit;
}

// Suggest driver (smart)
if ($action === 'suggestDriver' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $plat = (float)($b['pickup_lat'] ?? 0);
    $plng = (float)($b['pickup_lng'] ?? 0);
    if (!$plat || !$plng) { echo json_encode(['error'=>'pickup lat/lng required']); exit; }
    $stmt = $pdo->prepare("SELECT * FROM drivers WHERE status='available'");
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cands = [];
    foreach ($drivers as $d) {
        if ($d['current_location_lat'] === null || $d['current_location_lng'] === null) continue;
        $dist = haversine($plat, $plng, (float)$d['current_location_lat'], (float)$d['current_location_lng']);
        $est_min = ($dist/40)*60;
        $shift_ok = true;
        if (!empty($d['shift_end_time'])) {
            $shift_ts = strtotime(date('Y-m-d') . ' ' . $d['shift_end_time']);
            if ($shift_ts <= time() + ($est_min * 60)) $shift_ok = false;
        }
        $rating = (float)($d['rating_average'] ?? 5.0);
        $score = ($dist * 0.6) - ($rating * 0.4);
        $cands[] = ['driver'=>['id'=>(int)$d['id'],'name'=>$d['name'],'phone'=>$d['phone'],'status'=>$d['status'],'rating_average'=>$rating],'distance_km'=>round($dist,3),'est_min'=>max(1, round($est_min)),'shift_ok'=>$shift_ok,'score'=>$score];
    }
    usort($cands, fn($a,$b)=>$a['score'] <=> $b['score']);
    echo json_encode(['candidates'=>$cands]); exit;
}

// Assign driver (transaction + conflict + trip_assignments + optional reassign)
if ($action === 'assignDriver' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $trip_id = (int)($b['trip_id'] ?? 0);
    $driver_id = (int)($b['driver_id'] ?? 0);
    if (!$trip_id || !$driver_id) { echo json_encode(['error'=>'trip_id & driver_id required']); exit; }

    // driver validation
    $st = $pdo->prepare("SELECT id,status,shift_end_time FROM drivers WHERE id=:id LIMIT 1");
    $st->execute(['id'=>$driver_id]);
    $driver = $st->fetch(PDO::FETCH_ASSOC);
    if (!$driver) { echo json_encode(['error'=>'Driver not found']); exit; }
    if ($driver['status'] !== 'available') { echo json_encode(['error'=>'Driver not available']); exit; }

    // conflict check: active trip
    $chk = $pdo->prepare("SELECT COUNT(*) FROM trips WHERE driver_id=:d AND status='ongoing'");
    $chk->execute(['d'=>$driver_id]);
    if ($chk->fetchColumn() > 0) { echo json_encode(['error'=>'Driver already has an ongoing trip']); exit; }

    // fetch trip to detect reassign
    $tq = $pdo->prepare("SELECT id, driver_id, status FROM trips WHERE id=:t LIMIT 1");
    $tq->execute(['t'=>$trip_id]);
    $trip = $tq->fetch(PDO::FETCH_ASSOC);
    if (!$trip) { echo json_encode(['error'=>'Trip not found']); exit; }

    $warning = null;
    if (!empty($driver['shift_end_time'])) {
        $shift_ts = strtotime(date('Y-m-d') . ' ' . $driver['shift_end_time']);
        if ($shift_ts <= time() + (30 * 60)) $warning = "Driver's shift ends within ~30 minutes.";
    }

    try {
        $pdo->beginTransaction();

        // If trip already had a driver, release them and mark trip_assignments reassign log
        if (!empty($trip['driver_id']) && (int)$trip['driver_id'] !== $driver_id) {
            $prev = (int)$trip['driver_id'];
            $pdo->prepare("UPDATE drivers SET status='available' WHERE id=:id")->execute(['id'=>$prev]);
            $pdo->prepare("INSERT INTO trip_assignments (trip_id, driver_id, status) VALUES (:t,:d,'reassigned')")
                ->execute(['t'=>$trip_id,'d'=>$prev]);
        }

        // Assign new driver
        $pdo->prepare("UPDATE trips SET driver_id = :d, status = 'ongoing' WHERE id = :t")
            ->execute(['d'=>$driver_id,'t'=>$trip_id]);
        $pdo->prepare("UPDATE drivers SET status = 'on_trip' WHERE id = :d")
            ->execute(['d'=>$driver_id]);

        // Log assignment
        $pdo->prepare("INSERT INTO trip_assignments (trip_id, driver_id, status) VALUES (:t,:d,'assigned')")
            ->execute(['t'=>$trip_id,'d'=>$driver_id]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error'=>'DB error: '.$e->getMessage()]); exit;
    }

    notify_socket('trips', $SOCKET_IO_URL);
    notify_socket('drivers', $SOCKET_IO_URL);
    echo json_encode(['success'=>true,'warning'=>$warning]); exit;
}

// Update driver location (mobile simulation)
if ($action === 'updateDriverLocation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $driver_id = (int)($b['driver_id'] ?? 0);
    $lat = $b['lat'] ?? null; $lng = $b['lng'] ?? null;
    if (!$driver_id || $lat === null || $lng === null) { echo json_encode(['error'=>'driver_id, lat, lng required']); exit; }
    $u = $pdo->prepare("UPDATE drivers SET current_location_lat = :lat, current_location_lng = :lng WHERE id = :id");
    $u->execute(['lat'=>$lat,'lng'=>$lng,'id'=>$driver_id]);
    notify_socket('drivers', $SOCKET_IO_URL);
    echo json_encode(['success'=>true]); exit;
}

// Trip status: complete/cancel (frees driver)
if ($action === 'updateTripStatus' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $trip_id = (int)($b['trip_id'] ?? 0);
    $new_status = $b['status'] ?? '';
    if (!$trip_id || !in_array($new_status, ['completed','cancelled','pending','ongoing'], true)) {
        echo json_encode(['error'=>'trip_id and valid status required']); exit;
    }

    // fetch current trip
    $st = $pdo->prepare("SELECT id, driver_id, status FROM trips WHERE id=:t LIMIT 1");
    $st->execute(['t'=>$trip_id]);
    $trip = $st->fetch(PDO::FETCH_ASSOC);
    if (!$trip) { echo json_encode(['error'=>'Trip not found']); exit; }

    try {
        $pdo->beginTransaction();

        // update trip
        $pdo->prepare("UPDATE trips SET status=:s WHERE id=:t")->execute(['s'=>$new_status,'t'=>$trip_id]);

        // if completed/cancelled, free driver
        if (in_array($new_status, ['completed','cancelled'], true) && !empty($trip['driver_id'])) {
            $pdo->prepare("UPDATE drivers SET status='available' WHERE id=:d")->execute(['d'=>$trip['driver_id']]);
            if ($new_status === 'cancelled') {
                $pdo->prepare("INSERT INTO trip_assignments (trip_id, driver_id, status) VALUES (:t,:d,'cancelled')")
                    ->execute(['t'=>$trip_id, 'd'=>$trip['driver_id']]);
            }
        }

        $pdo->commit();
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error'=>'DB error: '.$e->getMessage()]); exit;
    }

    notify_socket('trips', $SOCKET_IO_URL);
    notify_socket('drivers', $SOCKET_IO_URL);
    echo json_encode(['success'=>true]); exit;
}

// SMS stub (now supports trip autofill)
if ($action === 'sendSMS' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $to = trim($b['to'] ?? '');
    $trip_id = isset($b['trip_id']) ? (int)$b['trip_id'] : 0;
    $message = $b['message'] ?? '';
    if (!$to && $trip_id) {
        $q = $pdo->prepare("SELECT customer_contact FROM trips WHERE id=:t");
        $q->execute(['t'=>$trip_id]);
        $to = (string)$q->fetchColumn();
    }
    if (!$to) { echo json_encode(['error'=>'No phone provided']); exit; }
    echo json_encode(['success'=>true,'note'=>'SMS endpoint is stubbed. Integrate Twilio to send real SMS.','to'=>$to,'message'=>$message]); exit;
}

// No action: serve the HTML/JS UI
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>ViaHale — Dispatcher</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&family=Quicksand:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>
<style>
:root{
  --vh-primary: <?php echo $V_PRIMARY;?>;
  --vh-dark: <?php echo $V_DARK;?>;
  --vh-accent: <?php echo $V_ACCENT;?>;
  --font-h: 'Quicksand',sans-serif;
  --font-b: 'Poppins',sans-serif;
}
*{box-sizing:border-box}
body{margin:0;font-family:var(--font-b);background:#f7f7fb;color:#111}
.topbar{height:64px;background:linear-gradient(90deg,var(--vh-primary),var(--vh-accent));display:flex;align-items:center;justify-content:space-between;padding:0 18px;color:white}
.brand{display:flex;align-items:center;gap:12px}
.brand h1{font-family:var(--font-h);font-size:18px;margin:0}
#wrap{display:flex;height:calc(100vh - 64px)}
#sidebar{width:440px;background:white;border-right:1px solid #e6e6f0;padding:18px;overflow:auto}
#map{flex:1}
.section{margin-bottom:14px}
.btn{background:var(--vh-primary);color:white;padding:8px 12px;border-radius:12px;border:none;cursor:pointer;box-shadow:0 6px 14px rgba(101,50,201,0.12)}
.btn.ghost{background:transparent;color:var(--vh-dark);border:1px solid rgba(67,17,165,0.08);box-shadow:none}
.card{background:white;border-radius:12px;padding:12px;box-shadow:0 6px 16px rgba(66,11,145,0.06);border:1px solid #f0eff8}
input, textarea, select{width:100%;padding:8px;border-radius:10px;border:1px solid #e6e6f0;outline:none;font-family:var(--font-b)}
label{font-size:13px;color:#333;margin-bottom:6px;display:block}
.trip{padding:10px;border-radius:10px;border:1px solid #f0eef8;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center}
.trip .left{max-width:68%}
.priority{background:linear-gradient(90deg,#fff7e6,#fff8f0);border-left:4px solid #ffb74d}
.driver-item{padding:8px;border-radius:10px;border:1px solid #f0eef8;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center}
.modal-backdrop{position:fixed;inset:0;background:rgba(12,10,30,0.45);display:none;align-items:center;justify-content:center;z-index:999}
.modal{background:white;border-radius:14px;padding:18px;width:560px;max-width:96%;box-shadow:0 18px 40px rgba(67,17,165,0.12);transform:translateY(-8px);opacity:0;transition:all .18s ease}
.modal.show{transform:none;opacity:1}
.modal .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.modal .modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
.tag{display:inline-block;padding:6px 8px;border-radius:999px;background:#f3f0ff;color:var(--vh-dark);font-size:13px}
@media (max-width:900px){ #sidebar{width:360px} }
.vih-logout-btn{background-color:#6532C9;color:white;border:none;padding:6px 14px;font-size:13px;font-family:'Poppins',sans-serif;border-radius:10px;cursor:pointer;transition:background .3s ease, transform .2s ease}
.vih-logout-btn:hover{background-color:#4311A5;transform:scale(1.05)}
.small{font-size:12px;color:#666}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid #e7e3ff}
.badge-prio{background:#fff5e6}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">
    <img src="../logo.png" alt="ViaHale Logo" style="height:40px;width:auto;display:block;">
    <div>
      <h1>ViaHale Dispatcher</h1>
      <div style="font-size:13px;opacity:0.9">Realtime dispatch console</div>
    </div>
  </div>
  <div style="display:flex;gap:12px;align-items:center">
    <button class="btn" id="newTripBtn">+ New Trip</button>
    <div style="color:white;font-size:14px">Logged in as <strong><?php echo htmlspecialchars($username); ?></strong></div>
    <a class="vih-logout-btn" href="/core1/logout.php" onclick="return confirm('Are you sure you want to log out?')">Logout</a>
  </div>
</div>

<div id="wrap">
  <div id="sidebar">
    <div class="section card" style="display:flex;justify-content:space-between;align-items:center">
      <div>
        <div class="tag">Live Map</div>
        <div class="small" style="margin-top:6px">Drivers & Trips update instantly</div>
      </div>
      <div><button class="btn ghost" id="refreshBtn">Refresh</button></div>
    </div>

    <div class="section">
      <h3 style="margin:6px 0 10px 0">Trips <span class="badge badge-prio">Priority first</span></h3>
      <div id="tripsList"></div>
    </div>

    <div class="section">
      <h3 style="margin:6px 0 10px 0">Available Drivers</h3>
      <div id="driversList"></div>
    </div>

    <div class="section card">
      <h4 style="margin:0 0 8px 0">Customer Communication</h4>
      <label>Phone</label>
      <input id="sms_to" placeholder="09xxxxxxxxx">
      <label style="margin-top:8px">Message</label>
      <textarea id="sms_msg" rows="3" placeholder="Message to passenger..."></textarea>
      <div style="display:flex;gap:8px;margin-top:8px;justify-content:flex-end">
        <button class="btn ghost" id="smsBtn">Send (stub)</button>
      </div>
      <div id="smsResult" class="small" style="margin-top:8px"></div>
    </div>
  </div>

  <div id="map" style="height:calc(100vh - 64px)"></div>
</div>

<!-- Modals -->
<div id="modalBackdrop" class="modal-backdrop">
  <!-- New Trip -->
  <div id="newTripModal" class="modal" role="dialog" aria-modal="true" style="display:none">
    <div class="modal-header">
      <div>
        <strong style="font-family:var(--font-h)">Create New Trip</strong>
        <div class="small">Add pickup & dropoff and mark priority</div>
      </div>
      <button class="btn ghost" onclick="closeModal('newTripModal')">Close</button>
    </div>
    <div>
      <label>Passenger name</label>
      <input id="nt_passenger" placeholder="Passenger name">
      <label style="margin-top:8px">Customer phone</label>
      <input id="nt_contact" placeholder="09xxxxxxxxx">
      <label style="margin-top:8px">Origin address</label>
      <input id="nt_origin" placeholder="Address, city">
      <label style="margin-top:8px">Destination address</label>
      <input id="nt_destination" placeholder="Address, city">
      <label style="margin-top:8px"><input type="checkbox" id="nt_priority"> Mark as priority (urgent)</label>
      <div class="modal-footer">
        <button class="btn ghost" onclick="closeModal('newTripModal')">Cancel</button>
        <button class="btn" id="createTripConfirm">Create Trip</button>
      </div>
    </div>
  </div>

  <!-- Assign -->
  <div id="assignModal" class="modal" role="dialog" aria-modal="true" style="display:none">
    <div class="modal-header">
      <div>
        <strong style="font-family:var(--font-h)">Assign Driver</strong>
        <div id="assignTripLabel" class="small">Trip: —</div>
      </div>
      <button class="btn ghost" onclick="closeModal('assignModal')">Close</button>
    </div>
    <div>
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <input id="assign_search" placeholder="Search driver by name or phone">
        <button class="btn ghost" id="refreshDriversBtn">Refresh</button>
      </div>
      <div style="display:flex;gap:10px">
        <div style="flex:1">
          <div class="small">Suggested</div>
          <div id="suggestedDriverBox" style="margin-top:8px"></div>
        </div>
        <div style="flex:1">
          <div class="small">All Available</div>
          <div id="allDriversBox" style="margin-top:8px;max-height:220px;overflow:auto"></div>
        </div>
      </div>
      <div class="modal-footer" style="margin-top:12px">
        <button class="btn ghost" onclick="closeModal('assignModal')">Cancel</button>
        <button class="btn" id="assignConfirmBtn">Assign Driver</button>
      </div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
<script>
const API = 'dispatcher_dashboard.php';
const SOCKET_IO_URL = <?php echo json_encode($SOCKET_IO_URL); ?>;
const PRIMARY = <?php echo json_encode($V_PRIMARY); ?>;
let map = L.map('map').setView([14.5995,120.9842], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(map);

let driverMarkers = {};
let tripMarkers = {};
let assign_context = { trip_id: null, pickup_lat: null, pickup_lng: null, selected_driver_id: null };

/* Utility fetch helpers */
async function apiGet(action){ const r = await fetch(`${API}?action=${action}`); return r.json(); }
async function apiPost(action, data){ const r = await fetch(`${API}?action=${action}`, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data)}); return r.json(); }

/* Socket.IO real-time updates */
const socket = io(SOCKET_IO_URL, { transports:['websocket','polling'] });
socket.on('connect', ()=> console.log('socket connected'));
socket.on('updateData', (_) => { refreshAll(); });

/* Modal helpers */
function showModal(id){ document.getElementById('modalBackdrop').style.display='flex'; const m=document.getElementById(id); m.style.display='block'; setTimeout(()=>m.classList.add('show'),10); }
function closeModal(id){ const m=document.getElementById(id); m.classList.remove('show'); setTimeout(()=>{ m.style.display='none'; if(!Array.from(document.querySelectorAll('.modal')).some(x=>x.style.display==='block')) document.getElementById('modalBackdrop').style.display='none'; },180); }

/* Render drivers & trips */
function clearMarkers(dict){ for(const k in dict){ try{ map.removeLayer(dict[k]); }catch{} } }

async function refreshDrivers(){
  const res = await apiGet('getDrivers');
  const box = document.getElementById('driversList');
  box.innerHTML = '';
  if(!res.drivers) return;
  res.drivers.forEach(d=>{
    if(d.status === 'available'){
      const el = document.createElement('div'); el.className='driver-item';
      el.innerHTML = `<div><strong>${escapeHtml(d.name)}</strong><div class="small">${escapeHtml(d.phone)} — ${d.rating_average}★</div></div>
        <div style="display:flex;gap:6px"><button class="btn ghost" data-quickassign="${d.id}">Assign</button></div>`;
      box.appendChild(el);
    }
    // markers
    if(d.current_location_lat && d.current_location_lng){
      const key = 'd_'+d.id;
      const color = d.status === 'available' ? PRIMARY : '#16a34a';
      const icon = L.divIcon({ html:`<div style="width:18px;height:18px;border-radius:9px;background:${color};border:2px solid #fff"></div>`, className:'' });
      if(driverMarkers[key]) {
        driverMarkers[key].setLatLng([d.current_location_lat, d.current_location_lng]);
        driverMarkers[key].setPopupContent(`<b>${escapeHtml(d.name)}</b><br>${escapeHtml(d.phone)}<br>Status: ${escapeHtml(d.status)}`);
      } else {
        driverMarkers[key] = L.marker([d.current_location_lat, d.current_location_lng], {icon})
          .addTo(map).bindPopup(`<b>${escapeHtml(d.name)}</b><br>${escapeHtml(d.phone)}<br>Status: ${escapeHtml(d.status)}`);
      }
    }
  });
}

async function refreshTrips(){
  const res = await apiGet('getTrips');
  const box = document.getElementById('tripsList');
  box.innerHTML = '';
  if(!res.trips) return;
  res.trips.forEach(t=>{
    const div = document.createElement('div'); div.className = 'trip' + (Number(t.priority)===1 ? ' priority' : '');
    const assignBtn = t.driver_name ? '' : `<button class="btn" data-assign="${t.id}" data-plat="${t.pickup_lat??''}" data-plng="${t.pickup_lng??''}">Assign</button>`;
    const msgBtn = `<button class="btn ghost" data-msg="${t.id}" data-phone="${escapeAttr(t.customer_contact||'')}">Msg</button>`;
    const completeBtn = (t.status==='ongoing') ? `<button class="btn ghost" data-done="${t.id}">Complete</button>` : '';
    const cancelBtn = (t.status!=='completed' && t.status!=='cancelled') ? `<button class="btn ghost" data-cancel="${t.id}">Cancel</button>` : '';

    div.innerHTML = `
      <div class="left">
        <strong>${escapeHtml(t.trip_code)}</strong>
        ${Number(t.priority)===1 ? ' <span class="badge badge-prio">URGENT</span>' : ''}
        <div class="small">${escapeHtml(t.passenger_name||'')} ${t.customer_contact?` • ${escapeHtml(t.customer_contact)}`:''}</div>
        <div>${escapeHtml(t.origin||'')} → ${escapeHtml(t.destination||'')}</div>
      </div>
      <div style="text-align:right">
        <div class="small">${escapeHtml(t.status)}${t.driver_name ? ' — '+escapeHtml(t.driver_name) : ''}</div>
        <div style="margin-top:6px;display:flex;gap:6px;justify-content:flex-end">${assignBtn}${msgBtn}${completeBtn}${cancelBtn}</div>
      </div>`;
    box.appendChild(div);

    // markers
    if(t.pickup_lat && t.pickup_lng){
      const keyp = 't_'+t.id+'_p';
      const lat = +t.pickup_lat, lng = +t.pickup_lng;
      if(tripMarkers[keyp]) tripMarkers[keyp].setLatLng([lat,lng]);
      else tripMarkers[keyp] = L.marker([lat,lng], {title:'Pickup:'+t.trip_code, icon:L.divIcon({html:`<div style="width:14px;height:14px;border-radius:7px;background:#ffb84d;border:2px solid #fff"></div>`})}).addTo(map).bindPopup(`<b>Pickup</b><br>${escapeHtml(t.origin||'')}`);
    }
    if(t.dropoff_lat && t.dropoff_lng){
      const keyd = 't_'+t.id+'_d';
      const lat = +t.dropoff_lat, lng = +t.dropoff_lng;
      if(tripMarkers[keyd]) tripMarkers[keyd].setLatLng([lat,lng]);
      else tripMarkers[keyd] = L.marker([lat,lng], {title:'Dropoff:'+t.trip_code, icon:L.divIcon({html:`<div style="width:14px;height:14px;border-radius:7px;background:#38bdf8;border:2px solid #fff"></div>`})}).addTo(map).bindPopup(`<b>Dropoff</b><br>${escapeHtml(t.destination||'')}`);
    }
  });

  // bind actions
  document.querySelectorAll('[data-assign]').forEach(btn=>{
    btn.removeEventListener('click', assignBtnHandler);
    btn.addEventListener('click', assignBtnHandler);
  });
  document.querySelectorAll('[data-quickassign]').forEach(btn=>{
    btn.removeEventListener('click', quickAssignHandler);
    btn.addEventListener('click', quickAssignHandler);
  });
  document.querySelectorAll('[data-msg]').forEach(btn=>{
    btn.addEventListener('click', (e)=>{
      const phone = e.currentTarget.getAttribute('data-phone') || '';
      document.getElementById('sms_to').value = phone;
      document.getElementById('sms_msg').value = '';
      document.getElementById('sms_to').focus();
    });
  });
  document.querySelectorAll('[data-done]').forEach(btn=>{
    btn.addEventListener('click', async (e)=>{
      const id = parseInt(e.currentTarget.getAttribute('data-done'));
      if(!confirm('Mark trip as completed?')) return;
      const r = await apiPost('updateTripStatus', { trip_id:id, status:'completed' });
      if(r.error) alert('Error: '+r.error); else refreshAll();
    });
  });
  document.querySelectorAll('[data-cancel]').forEach(btn=>{
    btn.addEventListener('click', async (e)=>{
      const id = parseInt(e.currentTarget.getAttribute('data-cancel'));
      if(!confirm('Cancel this trip?')) return;
      const r = await apiPost('updateTripStatus', { trip_id:id, status:'cancelled' });
      if(r.error) alert('Error: '+r.error); else refreshAll();
    });
  });
}

function assignBtnHandler(e){
  const btn = e.currentTarget;
  const tripId = parseInt(btn.getAttribute('data-assign'));
  const plat = btn.getAttribute('data-plat') || null;
  const plng = btn.getAttribute('data-plng') || null;
  openAssign(tripId, plat ? Number(plat) : null, plng ? Number(plng) : null);
}

function quickAssignHandler(e){
  const btn = e.currentTarget;
  const driverId = parseInt(btn.getAttribute('data-quickassign'));
  const tid = prompt('Enter Trip ID to assign this driver to (copy from trip list):');
  if(!tid) return;
  assign_context.selected_driver_id = driverId;
  assign_context.trip_id = parseInt(tid);
  document.getElementById('assignConfirmBtn').click();
}

/* Refresh all */
async function refreshAll(){ await Promise.all([refreshDrivers(), refreshTrips()]); }

/* Helpers */
function escapeHtml(s){ if (s===null||s===undefined) return ''; return String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function escapeAttr(s){ return String(s||'').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

/* New Trip flow */
document.getElementById('newTripBtn').addEventListener('click', ()=>{
  document.getElementById('nt_passenger').value='';
  document.getElementById('nt_contact').value='';
  document.getElementById('nt_origin').value='';
  document.getElementById('nt_destination').value='';
  document.getElementById('nt_priority').checked=false;
  showModal('newTripModal');
});
document.getElementById('createTripConfirm').addEventListener('click', async ()=>{
  const passenger = document.getElementById('nt_passenger').value || 'Passenger';
  const contact = document.getElementById('nt_contact').value.trim();
  const origin = document.getElementById('nt_origin').value.trim();
  const destination = document.getElementById('nt_destination').value.trim();
  const priority = document.getElementById('nt_priority').checked ? 1 : 0;
  if(!origin || !destination) return alert('Please enter origin and destination.');
  const res = await apiPost('createTrip', { passenger_name: passenger, customer_contact: contact, origin, destination, priority });
  if(res.error) return alert('Error: '+res.error);
  if(res.success){
    closeModal('newTripModal');
    await refreshAll();
    if(res.pickup && res.pickup.lat && res.pickup.lng){ map.flyTo([res.pickup.lat, res.pickup.lng], 15, {duration:1.2}); }
    alert('Trip created: '+res.trip_code);
  } else alert('Unexpected create response');
});

/* Assign flow */
async function openAssign(tripId, plat=null, plng=null){
  assign_context.trip_id = tripId; assign_context.pickup_lat = plat; assign_context.pickup_lng = plng; assign_context.selected_driver_id = null;
  document.getElementById('assignTripLabel').innerText = 'Trip: ' + tripId;
  document.getElementById('assign_search').value = '';
  document.getElementById('suggestedDriverBox').innerHTML = 'Loading...';
  document.getElementById('allDriversBox').innerHTML = 'Loading...';
  showModal('assignModal');
  // suggested
  if(assign_context.pickup_lat && assign_context.pickup_lng){
    const sres = await apiPost('suggestDriver', { pickup_lat: assign_context.pickup_lat, pickup_lng: assign_context.pickup_lng });
    renderSuggested(sres.candidates || []);
  } else {
    document.getElementById('suggestedDriverBox').innerHTML = '<div class="small">No pickup coordinates available.</div>';
  }
  // all available
  const dres = await apiGet('getDrivers');
  renderAllDrivers(dres.drivers || []);
}

function renderSuggested(candidates){
  const box = document.getElementById('suggestedDriverBox'); box.innerHTML = '';
  if(!candidates || candidates.length === 0){ box.innerHTML = '<div class="small">No suggestions</div>'; return; }
  const first = candidates[0];
  const d = first.driver;
  const el = document.createElement('div'); el.className = 'driver-item';
  el.innerHTML = `<div><strong>${escapeHtml(d.name)}</strong><div class="small">${escapeHtml(d.phone)} — ${first.distance_km} km — est ${first.est_min} min ${first.shift_ok ? '' : '<span style="color:#b36b00">(shift close)</span>'}</div></div>
    <div><button class="btn" data-select="${d.id}">Select</button></div>`;
  box.appendChild(el);
  assign_context.selected_driver_id = d.id;
  candidates.slice(1).forEach(c=>{
    const dd=c.driver;
    const x = document.createElement('div'); x.className='driver-item';
    x.innerHTML = `<div><strong>${escapeHtml(dd.name)}</strong><div class="small">${escapeHtml(dd.phone)} — ${c.distance_km} km</div></div><div><button class="btn ghost" data-select="${dd.id}">Select</button></div>`;
    box.appendChild(x);
  });
  box.querySelectorAll('[data-select]').forEach(b=>{ b.removeEventListener('click', selectDriverHandler); b.addEventListener('click', selectDriverHandler); });
}

function renderAllDrivers(drivers){
  const box = document.getElementById('allDriversBox'); box.innerHTML = '';
  drivers.filter(d=>d.status==='available').forEach(d=>{
    const el = document.createElement('div'); el.className='driver-item';
    el.innerHTML = `<div><strong>${escapeHtml(d.name)}</strong><div class="small">${escapeHtml(d.phone)} — ${d.rating_average}★</div></div>
      <div><button class="btn ghost" data-select="${d.id}">Select</button></div>`;
    box.appendChild(el);
  });
  box.querySelectorAll('[data-select]').forEach(b=>{ b.removeEventListener('click', selectDriverHandler); b.addEventListener('click', selectDriverHandler); });
}

function selectDriverHandler(e){ const id = parseInt(e.currentTarget.getAttribute('data-select')); assign_context.selected_driver_id = id; }

document.getElementById('assignConfirmBtn').addEventListener('click', async ()=>{
  const tid = assign_context.trip_id; const did = assign_context.selected_driver_id;
  if(!did) return alert('Please select a driver first.');
  const res = await apiPost('assignDriver', { trip_id: tid, driver_id: did });
  if(res.error) return alert('Error: '+res.error);
  if(res.warning) alert(res.warning);
  closeModal('assignModal');
  refreshAll();
});

/* assign search filter */
document.getElementById('assign_search').addEventListener('input', function(){
  const q = this.value.toLowerCase();
  document.querySelectorAll('#allDriversBox .driver-item').forEach(it=>{
    it.style.display = it.innerText.toLowerCase().includes(q) ? '' : 'none';
  });
});
document.getElementById('refreshDriversBtn').addEventListener('click', refreshDrivers);

/* quick assign buttons */
document.addEventListener('click', e=>{
  const a = e.target;
  if(a && a.matches('[data-quickassign]')) quickAssignHandler({currentTarget: a});
});

/* quick refresh */
document.getElementById('refreshBtn').addEventListener('click', refreshAll);

/* SMS stub */
document.getElementById('smsBtn').addEventListener('click', async ()=>{
  const to = document.getElementById('sms_to').value, msg = document.getElementById('sms_msg').value;
  const res = await apiPost('sendSMS', { to, message: msg });
  document.getElementById('smsResult').innerText = JSON.stringify(res);
});

/* init */
refreshAll();
</script>
</body>
</html>
