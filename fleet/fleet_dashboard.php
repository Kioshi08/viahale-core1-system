<?php
/*******************************************************
 * fleet_dashboard.php
 * Fleet Maintenance Staff — single-file interactive module
 * - Maintenance scheduling & repair logs
 * - Fuel logging & fuel-efficiency analysis
 * - Auto migrations (non-destructive where possible)
 * - ViaHale color branding + dark UI
 *******************************************************/
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('fleetstaff', 'admin1'); // Only fleetstaff or admin1

// ---------- LIGHT MIGRATIONS ----------
try {
    // maintenance_schedule.auto_generated
    $pdo->exec("ALTER TABLE maintenance_schedule ADD COLUMN IF NOT EXISTS auto_generated TINYINT(1) DEFAULT 0");

    // repair_logs enhancement columns
    $pdo->exec("ALTER TABLE repair_logs ADD COLUMN IF NOT EXISTS parts_used VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE repair_logs ADD COLUMN IF NOT EXISTS part_lifespan_months INT NULL");
    $pdo->exec("ALTER TABLE repair_logs ADD COLUMN IF NOT EXISTS next_replacement_date DATE NULL");

    // vehicles.mileage (optional, useful for mileage based scheduling later)
    $pdo->exec("ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS mileage INT DEFAULT 0");

    // Create fuel_logs table if missing
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS fuel_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        driver_id INT NULL,
        liters DECIMAL(8,2) NOT NULL,
        cost DECIMAL(12,2) DEFAULT 0,
        odometer INT NULL,
        refuel_date DATE NOT NULL,
        created_by VARCHAR(100) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (vehicle_id),
        INDEX (refuel_date),
        CONSTRAINT fuel_logs_fk_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    // If migrations fail, continue — UI will still work for reads/writes where possible.
}

// ---------- UTILS ----------
function json_out($arr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

// ---------- AUTO SCHEDULER (time-based) ----------
function run_auto_scheduler(PDO $pdo, string $username) {
    // Basic schedule plan: create 'Oil Change' & 'General Preventive Maintenance' every 6 months
    $plans = ['Oil Change' => 6, 'General Preventive Maintenance' => 6];
    $vehicles = $pdo->query("SELECT id FROM vehicles")->fetchAll();
    $today = new DateTimeImmutable('today');

    $insertStmt = $pdo->prepare("
      INSERT INTO maintenance_schedule (vehicle_id, scheduled_date, type, notes, status, created_by, auto_generated)
      VALUES (:vid, :date, :type, :notes, 'scheduled', :by, 1)
    ");

    foreach ($vehicles as $v) {
        $vid = (int)$v['id'];
        foreach ($plans as $type => $months) {
            $st = $pdo->prepare("
              SELECT
                MAX(CASE WHEN status='done' THEN scheduled_date ELSE NULL END) AS last_done,
                MAX(scheduled_date) AS last_any
              FROM maintenance_schedule
              WHERE vehicle_id = :vid AND type = :type
            ");
            $st->execute(['vid'=>$vid,'type'=>$type]);
            $row = $st->fetch();

            if (!empty($row['last_done'])) $base = new DateTimeImmutable($row['last_done']);
            elseif (!empty($row['last_any'])) $base = new DateTimeImmutable($row['last_any']);
            else $base = $today;

            $nextDue = $base->modify("+{$months} months");
            // Only schedule if nextDue within next 14 days and not already scheduled
            if ($nextDue <= $today->modify('+14 days')) {
                $chk = $pdo->prepare("
                  SELECT COUNT(*) FROM maintenance_schedule
                  WHERE vehicle_id = :vid AND type = :type AND status = 'scheduled' AND scheduled_date >= :due
                ");
                $chk->execute(['vid'=>$vid,'type'=>$type,'due'=>$nextDue->format('Y-m-d')]);
                if ((int)$chk->fetchColumn() === 0) {
                    $insertStmt->execute([
                        'vid'=>$vid,'date'=>$nextDue->format('Y-m-d'),
                        'type'=>$type,'notes'=>"Auto-generated ({$months}m)", 'by'=>$username
                    ]);
                }
            }
        }
    }
}

// run scheduler on page load (also should be cron in prod)
try { run_auto_scheduler($pdo, $username); } catch (Exception $e) { /* ignore */ }

// ---------- API ----------
$action = $_GET['action'] ?? null;
if ($action) header('Content-Type: application/json; charset=utf-8');

// Overview
if ($action === 'overview') {
    try {
        $veh = $pdo->query("
            SELECT
              SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) AS available,
              SUM(CASE WHEN status='in_use' THEN 1 ELSE 0 END) AS in_use,
              SUM(CASE WHEN status='maintenance' THEN 1 ELSE 0 END) AS maintenance,
              COUNT(*) AS total
            FROM vehicles
        ")->fetch();

        $nextMaint = $pdo->query("
            SELECT ms.id, v.plate_no, ms.type, ms.scheduled_date, ms.status, ms.auto_generated
            FROM maintenance_schedule ms
            JOIN vehicles v ON v.id = ms.vehicle_id
            WHERE ms.status='scheduled'
            ORDER BY ms.scheduled_date ASC
            LIMIT 10
        ")->fetchAll();

        $cost = $pdo->query("
            SELECT DATE_FORMAT(log_date, '%Y-%m') AS ym, SUM(cost) AS total_cost
            FROM repair_logs
            WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(log_date, '%Y-%m')
            ORDER BY ym ASC
        ")->fetchAll();

        json_out(['vehicles'=>$veh, 'upcoming'=>$nextMaint, 'cost_trend'=>$cost]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Vehicles list
if ($action === 'vehicles') {
    try {
        $rows = $pdo->query("
          SELECT v.*,
            (SELECT COUNT(*) FROM maintenance_schedule ms WHERE ms.vehicle_id=v.id AND ms.status='scheduled') AS pending_maint
          FROM vehicles v
          ORDER BY v.status DESC, v.plate_no ASC
        ")->fetchAll();
        json_out(['vehicles'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Maintenance list
if ($action === 'maintenance_list') {
    try {
        $rows = $pdo->query("
          SELECT ms.*, v.plate_no
          FROM maintenance_schedule ms
          JOIN vehicles v ON v.id = ms.vehicle_id
          ORDER BY ms.scheduled_date DESC, ms.created_at DESC
          LIMIT 500
        ")->fetchAll();
        json_out(['maintenance'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Create maintenance
if ($action === 'maintenance_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $vehicle_id = (int)($b['vehicle_id'] ?? 0);
    $date = $b['scheduled_date'] ?? null;
    $type = trim($b['type'] ?? '');
    $notes = trim($b['notes'] ?? '');
    if (!$vehicle_id || !$date || !$type) json_out(['error'=>'vehicle_id, scheduled_date, type required']);
    try {
        $st = $pdo->prepare("
          INSERT INTO maintenance_schedule
            (vehicle_id, scheduled_date, type, notes, status, created_by, auto_generated)
          VALUES (:v,:d,:t,:n,'scheduled',:u,0)
        ");
        $st->execute(['v'=>$vehicle_id,'d'=>$date,'t'=>$type,'n'=>$notes,'u'=>$username]);
        json_out(['success'=>true]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Update maintenance status
if ($action === 'maintenance_update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($b['id'] ?? 0);
    $status = $b['status'] ?? '';
    if (!$id || !in_array($status, ['scheduled','done','missed','cancelled'], true)) json_out(['error'=>'id and valid status required']);
    try {
        $pdo->prepare("UPDATE maintenance_schedule SET status=:s WHERE id=:id")->execute(['s'=>$status,'id'=>$id]);
        json_out(['success'=>true]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Repairs list
if ($action === 'repairs_list') {
    try {
        $rows = $pdo->query("
          SELECT r.*, v.plate_no
          FROM repair_logs r
          JOIN vehicles v ON v.id = r.vehicle_id
          ORDER BY r.log_date DESC, r.created_at DESC
          LIMIT 500
        ")->fetchAll();
        json_out(['repairs'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Create repair (parts + lifespan => next_replacement_date)
if ($action === 'repairs_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $vehicle_id = (int)($b['vehicle_id'] ?? 0);
    $log_date = $b['log_date'] ?? date('Y-m-d H:i:s');
    $description = trim($b['description'] ?? '');
    $cost = (float)($b['cost'] ?? 0);
    $performed_by = trim($b['performed_by'] ?? '');
    $parts_used = trim($b['parts_used'] ?? '');
    $lifespan = isset($b['part_lifespan_months']) ? (int)$b['part_lifespan_months'] : null;
    if (!$vehicle_id || !$description) json_out(['error'=>'vehicle_id and description required']);

    try {
        $nextRep = null;
        if ($lifespan && $lifespan > 0) {
            $nextRep = (new DateTime($log_date))->modify("+{$lifespan} months")->format('Y-m-d');
        }
        $st = $pdo->prepare("
          INSERT INTO repair_logs
            (vehicle_id, log_date, description, cost, performed_by, created_by, parts_used, part_lifespan_months, next_replacement_date)
          VALUES (:v,:dt,:desc,:cost,:perf,:by,:parts,:life,:nextd)
        ");
        $st->execute([
            'v'=>$vehicle_id,'dt'=>$log_date,'desc'=>$description,'cost'=>$cost,
            'perf'=>$performed_by,'by'=>$username,
            'parts'=>$parts_used?:null,'life'=>$lifespan?:null,'nextd'=>$nextRep
        ]);
        json_out(['success'=>true]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// ---------- FUEL: list, create, efficiency ----------

// List fuel logs
if ($action === 'fuel_list') {
    try {
        $rows = $pdo->query("
          SELECT f.*, v.plate_no, d.name AS driver_name
          FROM fuel_logs f
          LEFT JOIN vehicles v ON v.id = f.vehicle_id
          LEFT JOIN drivers d ON d.id = f.driver_id
          ORDER BY f.refuel_date DESC, f.created_at DESC
          LIMIT 500
        ")->fetchAll();
        json_out(['fuel'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Create fuel log
if ($action === 'fuel_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $vehicle_id = (int)($b['vehicle_id'] ?? 0);
    $driver_id = isset($b['driver_id']) ? (int)$b['driver_id'] : null;
    $liters = (float)($b['liters'] ?? 0);
    $cost = (float)($b['cost'] ?? 0);
    $odometer = isset($b['odometer']) && $b['odometer'] !== '' ? (int)$b['odometer'] : null;
    $refuel_date = $b['refuel_date'] ?? date('Y-m-d');
    if (!$vehicle_id || $liters <= 0) json_out(['error'=>'vehicle_id and positive liters required']);

    try {
        $pdo->beginTransaction();
        $pdo->prepare("
          INSERT INTO fuel_logs (vehicle_id, driver_id, liters, cost, odometer, refuel_date, created_by)
          VALUES (:vid,:did,:lit,:cost,:odo,:date,:by)
        ")->execute([
          'vid'=>$vehicle_id,'did'=>$driver_id,'lit'=>$liters,'cost'=>$cost,
          'odo'=>$odometer,'date'=>$refuel_date,'by'=>$username
        ]);
        // optionally update vehicles.mileage if odometer provided and larger than stored
        if ($odometer !== null) {
            $pdo->prepare("UPDATE vehicles SET mileage = GREATEST(mileage, :odo) WHERE id = :vid")
                ->execute(['odo'=>$odometer,'vid'=>$vehicle_id]);
        }
        $pdo->commit();
        json_out(['success'=>true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error'=>$e->getMessage()]);
    }
}

// Fuel efficiency analysis (estimates km per liter using consecutive odometer readings)
if ($action === 'fuel_efficiency') {
    try {
        // fetch fuel logs ordered by vehicle & odometer/date
        $logs = $pdo->query("SELECT id, vehicle_id, liters, odometer, refuel_date FROM fuel_logs ORDER BY vehicle_id, COALESCE(odometer, refuel_date), refuel_date")->fetchAll();

        // group by vehicle and compute segment efficiency where odometer deltas exist
        $byVeh = [];
        foreach ($logs as $l) {
            $vid = $l['vehicle_id'];
            if (!isset($byVeh[$vid])) $byVeh[$vid] = [];
            $byVeh[$vid][] = $l;
        }

        $results = [];
        foreach ($byVeh as $vid => $list) {
            // sort by odometer if present else by date
            usort($list, function($a,$b){
                if ($a['odometer'] !== null && $b['odometer'] !== null) return $a['odometer'] <=> $b['odometer'];
                return strtotime($a['refuel_date']) <=> strtotime($b['refuel_date']);
            });
            $segments = [];
            for ($i=1;$i<count($list);$i++) {
                $prev = $list[$i-1];
                $cur = $list[$i];
                if ($prev['odometer'] !== null && $cur['odometer'] !== null && $cur['odometer'] > $prev['odometer'] && $cur['liters'] > 0) {
                    $km = $cur['odometer'] - $prev['odometer'];
                    // liters associated with segment: we assign current refuel liters as fuel used to travel previous segment (common heuristic)
                    $ltrs = (float)$cur['liters'];
                    $kmpl = $km / $ltrs;
                    $segments[] = ['km'=>$km,'liters'=>$ltrs,'kmpl'=>$kmpl,'from_id'=>$prev['id'],'to_id'=>$cur['id']];
                }
            }
            // compute average kmpl and latest kmpl
            $avg = null; $latest = null;
            if (count($segments)>0) {
                $sum = 0; foreach($segments as $s) $sum += $s['kmpl'];
                $avg = $sum / count($segments);
                $latest = end($segments)['kmpl'];
            }
            $results[] = ['vehicle_id'=>$vid,'avg_kmpl'=>$avg===null?null:round($avg,2),'latest_kmpl'=>$latest===null?null:round($latest,2),'segments'=>$segments];
        }

        // attach plate numbers
        foreach ($results as &$r) {
            $row = $pdo->prepare("SELECT plate_no FROM vehicles WHERE id=:id LIMIT 1");
            $row->execute(['id'=>$r['vehicle_id']]);
            $r['plate_no'] = $row->fetchColumn();
        }

        json_out(['eff'=>$results]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Analytics: maintenance cost by vehicle/month + replacement watchlist
if ($action === 'analytics') {
    try {
        $cost = $pdo->query("
          SELECT v.plate_no,
                 DATE_FORMAT(r.log_date, '%Y-%m') AS ym,
                 SUM(r.cost) AS total_cost
          FROM repair_logs r
          JOIN vehicles v ON v.id = r.vehicle_id
          WHERE r.log_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
          GROUP BY v.plate_no, DATE_FORMAT(r.log_date, '%Y-%m')
          ORDER BY v.plate_no, ym
        ")->fetchAll();
    } catch (Exception $e) { $cost = []; }

    try {
        $repl = $pdo->query("
          SELECT v.plate_no, r.parts_used, r.log_date, r.part_lifespan_months, r.next_replacement_date
          FROM repair_logs r
          JOIN vehicles v ON v.id = r.vehicle_id
          WHERE r.parts_used IS NOT NULL OR r.next_replacement_date IS NOT NULL
          ORDER BY r.next_replacement_date ASC, r.log_date DESC
        ")->fetchAll();
    } catch (Exception $e) { $repl = []; }

    json_out(['cost'=>$cost,'replacements'=>$repl]);
}

// Supplies list from storeroom (integration)
if ($action === 'supplies_list') {
    try {
        // Pull from storeroom items table
        $rows = $pdo->query("SELECT id AS item_id, name AS item_name, stock_quantity AS stock, unit, unit_cost FROM items ORDER BY name ASC")->fetchAll();
        json_out(['supplies'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Request supply (integration with storeroom requests)
if ($action === 'request_supply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $item_id = (int)($b['item_id'] ?? 0);
    $quantity = (int)($b['quantity'] ?? 0);
    $notes = trim($b['notes'] ?? '');
    $vehicle_id = (int)($b['vehicle_id'] ?? 0);
    if (!$item_id || $quantity <= 0) json_out(['error'=>'item_id and positive quantity required']);
    try {
        // Insert into storeroom requests table
        $st = $pdo->prepare("INSERT INTO supply_requests (item_id, quantity, requested_by, note, vehicle_id, status) VALUES (:iid,:qty,:by,:note,:vid,'pending')");
        $st->execute(['iid'=>$item_id,'qty'=>$quantity,'by'=>$username,'note'=>$notes,'vid'=>$vehicle_id]);
        json_out(['success'=>true]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Preventive Maintenance Alerts (overdue/upcoming)
if ($action === 'maintenance_alerts') {
    try {
        $alerts = $pdo->query("
            SELECT ms.*, v.plate_no
            FROM maintenance_schedule ms
            JOIN vehicles v ON v.id = ms.vehicle_id
            WHERE ms.status='scheduled' AND ms.scheduled_date <= CURDATE() + INTERVAL 14 DAY
            ORDER BY ms.scheduled_date ASC
        ")->fetchAll();
        json_out(['alerts'=>$alerts]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Unknown action handler
if ($action) { json_out(['error'=>'Unknown action']); }

// ---------- HTML / UI ----------
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>ViaHale — Fleet Maintenance</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
<style>
:root{
  --vh-primary: <?php echo $V_PRIMARY;?>;
  --vh-dark: <?php echo $V_DARK;?>;
  --vh-accent: <?php echo $V_ACCENT;?>;
  --bg:#07080b;
  --card:#0f1120;
  --muted:#9aa3bd;
  --accent:var(--vh-primary);
  --ok:#22c55e;
  --warn:#f59e0b;
  --bad:#ef4444;
  font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial;
}
*{box-sizing:border-box}
body{margin:0;background:linear-gradient(180deg,#05060b 0%, #0b0e18 100%);color:#e6eefc}
.topbar{height:64px;background:linear-gradient(90deg,var(--vh-primary),var(--vh-accent));display:flex;align-items:center;justify-content:space-between;padding:0 20px}
.brand h1{margin:0;font-size: 18px;}
.container{padding:18px;display:grid;grid-template-columns:repeat(12,1fr);gap:14px}
.card{background:var(--card);border-radius:12px;padding:14px;border:1px solid rgba(255,255,255,0.04)}
.span-12{grid-column:span 12}.span-6{grid-column:span 6}.span-4{grid-column:span 4}
h2{margin:0 0 8px 0;font-size:16px}
.table{width:100%;border-collapse:collapse;font-size:13px}
.table th, .table td{padding:8px;border-bottom:1px solid rgba(255,255,255,0.03);text-align:left}
.input, select, textarea{width:100%;padding:8px;border-radius:8px;background:#0b1226;border:1px solid rgba(255,255,255,0.03);color:#e6eefc}
.btn{background:var(--vh-primary);border:none;color:white;padding:8px 12px;border-radius:10px;cursor:pointer}
.btn.ghost{background:transparent;border:1px solid rgba(255,255,255,0.04)}
.small{font-size:12px;color:var(--muted)}
.tag{display:inline-block;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,0.03);font-size:12px}
.right{text-align:right}
.grid-row{display:flex;gap:8px}
.scroll{max-height:320px;overflow:auto}
.bad{color:var(--bad)}.warn{color:var(--warn)}.ok{color:var(--ok)}
.vih-logout-btn{background-color:#6532C9;color:white;border:none;padding:6px 14px;font-size:13px;font-family:'Poppins',sans-serif;border-radius:10px;cursor:pointer;transition:background .3s ease, transform .2s ease}
.vih-logout-btn:hover{background-color:#4311A5;transform:scale(1.05)}
</style>
</head>
<body>
<div class="topbar">
  <div style="display:flex;align-items:center;gap:12px">
    <img src="../logo.png" alt="logo" style="height:36px">
    <div>
      <div class="brand"><h1>ViaHale Fleet — Maintenance</h1></div>
      <div class="small">Signed in as <strong><?php echo htmlspecialchars($username); ?></strong></div>
    </div>
  </div>
  <div style="display:flex;gap:10px;align-items:center">
    <button class="btn" onclick="refreshAll()">Refresh</button>
    <a class="vih-logout-btn" href="/core1/logout.php" onclick="return confirm('Are you sure you want to log out?')">Logout</a>
  </div>
</div>

<div class="container">
  <!-- Overview -->
  <section class="card span-12">
    <h2>Overview</h2>
    <div id="overviewBoxes" style="display:flex;gap:12px;margin-top:10px"></div>
  </section>

  <!-- Vehicles -->
  <section class="card span-6">
    <h2>Vehicles</h2>
    <div class="scroll">
      <table class="table" id="vehTable">
        <thead><tr><th>Plate</th><th>Model</th><th>Status</th><th>Year</th><th class="right">Pending</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <!-- Maintenance -->
  <section class="card span-6">
    <h2>Maintenance Schedule</h2>
    <div style="margin-top:8px" class="grid-row">
      <select id="msVehicle" class="input"></select>
      <input type="date" id="msDate" class="input">
    </div>
    <div style="margin-top:8px" class="grid-row">
      <input type="text" id="msType" class="input" placeholder="Type (Oil Change)">
    </div>
    <div style="margin-top:8px">
      <textarea id="msNotes" rows="2" class="input" placeholder="Notes (optional)"></textarea>
    </div>
    <div style="margin-top:8px">
      <button class="btn" onclick="createMaintenance()">Add Maintenance</button>
    </div>

    <div style="margin-top:12px" class="scroll">
      <table class="table" id="msTable">
        <thead><tr><th>When</th><th>Plate</th><th>Type</th><th>Status</th><th>Auto</th><th>Action</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <!-- Repairs -->
  <section class="card span-6">
    <h2>Repairs & Cost Tracker</h2>
    <div class="grid-row" style="margin-top:8px">
      <select id="rpVehicle" class="input"></select>
      <input type="datetime-local" id="rpDate" class="input">
    </div>
    <div class="grid-row" style="margin-top:8px">
      <input id="rpDesc" class="input" placeholder="Description">
      <input id="rpCost" class="input" type="number" min="0" step="0.01" placeholder="Cost">
    </div>
    <div class="grid-row" style="margin-top:8px">
      <input id="rpBy" class="input" placeholder="Performed by">
      <input id="rpParts" class="input" placeholder="Parts used">
      <input id="rpLife" class="input" type="number" min="0" placeholder="Lifespan (months)">
    </div>
    <div style="margin-top:8px"><button class="btn" onclick="createRepair()">Log Repair</button></div>

    <div style="margin-top:12px" class="scroll">
      <table class="table" id="rpTable">
        <thead><tr><th>Date</th><th>Plate</th><th>Desc</th><th class="right">Cost</th><th>Parts</th><th>Next Replace</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <!-- Fuel -->
  <section class="card span-6">
    <h2>Fuel Logs</h2>
    <div class="grid-row" style="margin-top:8px">
      <select id="fuelVehicle" class="input"></select>
      <select id="fuelDriver" class="input"></select>
    </div>
    <div class="grid-row" style="margin-top:8px">
      <input id="fuelLiters" class="input" type="number" step="0.01" placeholder="Liters">
      <input id="fuelCost" class="input" type="number" step="0.01" placeholder="Cost">
      <input id="fuelOdo" class="input" type="number" placeholder="Odometer (km)">
    </div>
    <div style="margin-top:8px">
      <input id="fuelDate" class="input" type="date" value="<?php echo date('Y-m-d'); ?>">
      <button class="btn" style="margin-left:8px" onclick="createFuel()">Add Fuel</button>
    </div>

    <div style="margin-top:12px" class="scroll">
      <table class="table" id="fuelTable">
        <thead><tr><th>Date</th><th>Plate</th><th>Driver</th><th>Liters</th><th class="right">Cost</th><th>Odometer</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <!-- Analytics -->
  <section class="card span-6">
    <h2>Analytics</h2>
    <div style="margin-top:8px">
      <div class="small">Maintenance cost (last 6 months)</div>
      <table class="table" id="costTable">
        <thead><tr><th>Plate</th><th>Month</th><th class="right">Total Cost</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>

    <div style="margin-top:12px">
      <div class="small">Fuel efficiency (estimated)</div>
      <table class="table" id="effTable">
        <thead><tr><th>Plate</th><th>Avg km/L</th><th>Latest km/L</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>

    <div style="margin-top:12px">
      <div class="small">Parts replacement watchlist</div>
      <table class="table" id="replTable"><thead><tr><th>Plate</th><th>Part</th><th>Installed</th><th>Life</th><th>Next</th><th>Status</th></tr></thead><tbody></tbody></table>
    </div>
  </section>

  <!-- Supplies Section -->
  <section class="card span-6">
    <h2>Supplies (Storeroom)</h2>
    <div style="margin-bottom:8px">
      <table class="table" id="supTable">
        <thead><tr><th>Item</th><th>Stock</th><th>Unit</th><th>Price</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
    <div style="margin-bottom:8px">
      <select id="supItem" class="input"></select>
      <input id="supQty" class="input" type="number" min="1" placeholder="Quantity">
      <select id="supVehicle" class="input"></select>
      <input id="supNotes" class="input" placeholder="Notes (optional)">
      <button class="btn" onclick="requestSupply()">Request Supply</button>
    </div>
    <div id="supReqResult" class="small"></div>
  </section>

  <!-- Preventive Maintenance Alerts Section -->
  <section class="card span-6">
    <h2>Preventive Maintenance Alerts</h2>
    <div class="scroll">
      <table class="table" id="alertTable">
        <thead><tr><th>Date</th><th>Plate</th><th>Type</th><th>Status</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>
</div>

<script>
const API = 'fleet_dashboard.php';
async function api(action, opts={}) {
  const url = `?action=${encodeURIComponent(action)}`;
  const res = await fetch(url, {
    method: opts.body ? 'POST' : 'GET',
    headers: opts.body ? {'Content-Type':'application/json'} : {},
    body: opts.body ? JSON.stringify(opts.body) : undefined
  });
  return res.json();
}

function el(tag, attrs={}, ...kids) {
  const e = document.createElement(tag);
  for (const k in attrs) {
    if (k==='html') e.innerHTML = attrs[k];
    else e.setAttribute(k, attrs[k]);
  }
  for (const c of kids) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
  return e;
}

async function loadOverview() {
  const o = await api('overview');
  const box = document.getElementById('overviewBoxes'); box.innerHTML='';
  if (o.error) { box.append(el('div', {}, 'Error: '+o.error)); return; }
  const v = o.vehicles || {};
  const cards = [
    {label:'Vehicles', value:v.total||0, sub:`In use ${v.in_use||0} • Maint ${v.maintenance||0} • Avail ${v.available||0}`},
    {label:'Upcoming Maint', value:(o.upcoming||[]).length, sub:'Next scheduled'},
    {label:'Cost points', value:(o.cost_trend||[]).length, sub:'6 months trend'}
  ];
  for (const c of cards) {
    const card = el('div', {style:'background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); padding:12px;border-radius:8px;min-width:200px'}, 
      el('div', {style:'font-size:22px;font-weight:700'}, String(c.value)),
      el('div', {class:'small'}, c.label),
      el('div', {class:'small', style:'margin-top:6px;color:rgba(255,255,255,0.6)'}, c.sub)
    );
    box.appendChild(card);
  }
}

let vehicles = [];
async function loadVehicles() {
  const r = await api('vehicles');
  vehicles = r.vehicles || [];
  const tb = document.querySelector('#vehTable tbody'); tb.innerHTML='';
  for (const v of vehicles) {
    const tr = el('tr', {}, el('td',{},v.plate_no), el('td',{},v.model||''), el('td',{},v.status||''), el('td',{},v.make_year||''), el('td',{'class':'right'},String(v.pending_maint||0)));
    tb.appendChild(tr);
  }
  // fill selects
  const msVehicle = document.getElementById('msVehicle'); msVehicle.innerHTML=''; msVehicle.appendChild(el('option',{value:''},'Select Vehicle'));
  const rpVehicle = document.getElementById('rpVehicle'); rpVehicle.innerHTML=''; rpVehicle.appendChild(el('option',{value:''},'Select Vehicle'));
  const fuelVehicle = document.getElementById('fuelVehicle'); fuelVehicle.innerHTML=''; fuelVehicle.appendChild(el('option',{value:''},'Select Vehicle'));
  const vsel = document.getElementById('supVehicle'); vsel.innerHTML='';
  for (const v of vehicles) {
    const opt = el('option',{value:v.id}, v.plate_no);
    msVehicle.appendChild(opt.cloneNode(true));
    rpVehicle.appendChild(opt.cloneNode(true));
    fuelVehicle.appendChild(opt.cloneNode(true));
    vsel.appendChild(opt.cloneNode(true));
  }
}

async function loadMaintenance() {
  const r = await api('maintenance_list');
  const tb = document.querySelector('#msTable tbody'); tb.innerHTML='';
  (r.maintenance||[]).forEach(m=>{
    const status = el('span', {class:'tag'}, m.status);
    const auto = m.auto_generated ? 'Yes' : 'No';
    const actions = el('div', {});
    actions.appendChild(button('Done', ()=>updateMaintenance(m.id,'done')));
    actions.appendChild(button('Missed', ()=>updateMaintenance(m.id,'missed')));
    actions.appendChild(button('Cancel', ()=>updateMaintenance(m.id,'cancelled')));
    tb.appendChild(el('tr', {}, el('td',{},m.scheduled_date), el('td',{},m.plate_no), el('td',{},m.type||''), el('td',{},status), el('td',{},auto), el('td',{}, actions)));
  });
}

function button(txt, fn){ const b = el('button', {class:'btn ghost', type:'button'}, txt); b.addEventListener('click', fn); return b; }

async function createMaintenance(){
  const vehicle_id = +document.getElementById('msVehicle').value;
  const scheduled_date = document.getElementById('msDate').value;
  const type = document.getElementById('msType').value.trim();
  const notes = document.getElementById('msNotes').value.trim();
  const r = await api('maintenance_create', {body:{vehicle_id, scheduled_date, type, notes}});
  if (r.success) { document.getElementById('msNotes').value=''; loadMaintenance(); loadOverview(); } else alert(r.error||'Failed');
}

async function updateMaintenance(id, status) {
  const r = await api('maintenance_update_status', {body:{id,status}});
  if (r.success) loadMaintenance(); else alert(r.error||'Failed');
}

async function loadRepairs() {
  const r = await api('repairs_list');
  const tb = document.querySelector('#rpTable tbody'); tb.innerHTML='';
  (r.repairs||[]).forEach(x=>{
    tb.appendChild(el('tr', {}, 
      el('td',{}, (x.log_date||'').replace('T',' ')),
      el('td',{}, x.plate_no||''),
      el('td',{}, x.description||''),
      el('td',{'class':'right'}, (parseFloat(x.cost)||0).toFixed(2)),
      el('td',{}, x.parts_used||''),
      el('td',{}, x.next_replacement_date||'')
    ));
  });
}

async function createRepair(){
  const vehicle_id = +document.getElementById('rpVehicle').value;
  const log_date = document.getElementById('rpDate').value;
  const description = document.getElementById('rpDesc').value.trim();
  const cost = parseFloat(document.getElementById('rpCost').value||'0');
  const performed_by = document.getElementById('rpBy').value.trim();
  const parts_used = document.getElementById('rpParts').value.trim();
  const part_lifespan_months = parseInt(document.getElementById('rpLife').value||'0',10)||null;
  const r = await api('repairs_create', {body:{vehicle_id, log_date, description, cost, performed_by, parts_used, part_lifespan_months}});
  if (r.success) { loadRepairs(); loadAnalytics(); } else alert(r.error||'Failed');
}

// Fuel
async function loadFuel(){
  const r = await api('fuel_list');
  const tb = document.querySelector('#fuelTable tbody'); tb.innerHTML='';
  (r.fuel||[]).forEach(f=>{
    tb.appendChild(el('tr', {},
      el('td',{}, f.refuel_date || f.created_at),
      el('td',{}, f.plate_no || ''),
      el('td',{}, f.driver_name || ''),
      el('td',{}, (parseFloat(f.liters)||0).toFixed(2)),
      el('td',{'class':'right'}, (parseFloat(f.cost)||0).toFixed(2)),
      el('td',{}, f.odometer || '')
    ));
  });
  // fill drivers select
  const drvSel = document.getElementById('fuelDriver'); drvSel.innerHTML=''; drvSel.appendChild(el('option',{value:''},'Driver (optional)'));
  // fetch drivers minimal
  const dres = await fetch('?action=drivers_min').then(r=>r.json()).catch(()=>({}));
  if (dres && dres.drivers) {
    dres.drivers.forEach(d=> drvSel.appendChild(el('option',{value:d.id}, d.name)));
  }
}

async function createFuel(){
  const vehicle_id = +document.getElementById('fuelVehicle').value;
  const driver_id = +document.getElementById('fuelDriver').value || null;
  const liters = parseFloat(document.getElementById('fuelLiters').value || '0');
  const cost = parseFloat(document.getElementById('fuelCost').value || '0');
  const odometer = document.getElementById('fuelOdo').value ? parseInt(document.getElementById('fuelOdo').value,10) : null;
  const refuel_date = document.getElementById('fuelDate').value;
  const r = await api('fuel_create', {body:{vehicle_id, driver_id, liters, cost, odometer, refuel_date}});
  if (r.success) { loadFuel(); loadAnalytics(); } else alert(r.error||'Failed');
}

// Drivers minimal endpoint
async function loadDriversMin() {
  // drivers_min action is implemented below in PHP to keep single-file
  const r = await fetch('?action=drivers_min').then(x=>x.json()).catch(()=>({}));
  return r.drivers || [];
}

async function loadAnalytics() {
  const r = await api('analytics');
  // cost
  const ct = document.querySelector('#costTable tbody'); ct.innerHTML='';
  (r.cost||[]).forEach(c => {
    ct.appendChild(el('tr', {}, el('td',{}, c.plate_no), el('td',{}, c.ym), el('td', {'class':'right'}, (parseFloat(c.total_cost)||0).toFixed(2))));
  });
  // replacements
  const repl = document.querySelector('#replTable tbody'); repl.innerHTML='';
  (r.replacements||[]).forEach(y=>{
    const status = (()=> {
      if (!y.next_replacement_date) return '—';
      const d = new Date(y.next_replacement_date + 'T00:00:00');
      const today = new Date(); today.setHours(0,0,0,0);
      if (d < today) return 'Overdue';
      const days = (d - today) / 86400000;
      if (days <= 14) return 'Due soon';
      return 'OK';
    })();
    const cls = status==='Overdue' ? 'bad' : (status==='Due soon' ? 'warn' : 'ok');
    repl.appendChild(el('tr', {}, el('td',{}, y.plate_no||''), el('td',{}, y.parts_used||''), el('td',{}, (y.log_date||'').split(' ')[0]||''), el('td',{}, y.part_lifespan_months ? y.part_lifespan_months + ' mo' : '—'), el('td',{}, y.next_replacement_date||'—'), el('td', {class:cls}, status)));
  });

  // fuel efficiency
  const effRes = await api('fuel_efficiency');
  const eT = document.querySelector('#effTable tbody'); eT.innerHTML='';
  (effRes.eff||[]).forEach(rw=>{
    eT.appendChild(el('tr', {}, el('td',{}, rw.plate_no||''), el('td',{}, rw.avg_kmpl===null? '—' : rw.avg_kmpl.toFixed(2)), el('td',{}, rw.latest_kmpl===null ? '—' : rw.latest_kmpl.toFixed(2))));
  });
}

// Supplies integration
async function loadSupplies() {
  const r = await api('supplies_list');
  console.log('Supplies API:', r);
  const tb = document.querySelector('#supTable tbody'); tb.innerHTML='';
  const sel = document.getElementById('supItem'); sel.innerHTML='';
  (r.supplies||[]).forEach(s=>{
    tb.appendChild(el('tr',{},
      el('td',{}, s.item_name ? String(s.item_name) : ''),
      el('td',{}, s.stock !== undefined && s.stock !== null ? String(s.stock) : ''),
      el('td',{}, s.unit ? String(s.unit) : ''),
      el('td',{}, s.unit_cost !== undefined && s.unit_cost !== null ? Number(s.unit_cost).toFixed(2) : '—')
    ));
    sel.appendChild(el('option',{value:s.item_id}, s.item_name ? String(s.item_name) : ''));
  });
  // fill vehicles for request
  const vsel = document.getElementById('supVehicle'); vsel.innerHTML='';
  vehicles.forEach(v=>vsel.appendChild(el('option',{value:v.id},v.plate_no)));
}

async function requestSupply() {
  const item_id = +document.getElementById('supItem').value;
  const quantity = +document.getElementById('supQty').value;
  const notes = document.getElementById('supNotes').value.trim();
  const vehicle_id = +document.getElementById('supVehicle').value;
  const r = await api('request_supply', {body:{item_id, quantity, notes, vehicle_id}});
  document.getElementById('supReqResult').innerText = r.success ? 'Request submitted!' : (r.error||'Failed');
}

// Preventive Maintenance Alerts
async function loadAlerts() {
  const r = await api('maintenance_alerts');
  const tb = document.querySelector('#alertTable tbody'); tb.innerHTML='';
  (r.alerts||[]).forEach(a=>{
    tb.appendChild(el('tr',{},el('td',{},a.scheduled_date),el('td',{},a.plate_no),el('td',{},a.type),el('td',{},a.status)));
  });
}

// Update refreshAll to include new sections
async function refreshAll() {
  await Promise.all([
    loadOverview(),
    loadVehicles(),
    loadMaintenance(),
    loadRepairs(),
    loadFuel(),
    loadAnalytics(),
    loadSupplies(), // <-- this must be here!
    loadAlerts()
  ]);
}

// minimal drivers endpoint call (used to populate driver select)
document.addEventListener('DOMContentLoaded', () => {
  // default date/time values
  const now = new Date();
  document.getElementById('rpDate').value = new Date(now.getTime()-now.getTimezoneOffset()*60000).toISOString().slice(0,16);
  refreshAll();
});
</script>
</body>
</html>
<?php
// ---------- additional backend helper endpoint: drivers_min ----------
// placed after HTML so still in same file; called by client JS via ?action=drivers_min
if (isset($_GET['action']) && $_GET['action'] === 'drivers_min') {
    try {
        $rows = $pdo->query("SELECT id, name FROM drivers ORDER BY name ASC LIMIT 500")->fetchAll();
        json_out(['drivers'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}
?>
