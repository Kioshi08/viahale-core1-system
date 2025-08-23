<?php
/****************************************************
 * Viahale Ops Manager Dashboard (single-file)
 * - Multi-tab overview for Dispatcher, Fleet, Storeroom
 * - Driver Information System (HR link)
 * - Enhancements implemented:
 *   • Performance KPIs (trips, earnings*, fuel, vehicle availability)
 *   • Incident Tracker (accidents/complaints/commendations)
 *   • Predictive Maintenance Alerts (heuristic risk score)
 *   • Budget vs Actual (fuel/repairs/supplies)
 *   • Approval Workflow (high-value fuel/supplies)
 * Notes:
 *  - Uses your existing tables; creates a few helper tables if missing.
 *  - Keep black theme; Viahale accent = #00E0B8
 ****************************************************/

// ---------- CONFIG ----------
$DB_HOST = 'localhost';
$DB_PORT = '3307';
$DB_NAME = 'otp_login';
$DB_USER = 'root';
$DB_PASS = '';
$VIABRAND = '#6532C9';
$BASE_FARE_PER_COMPLETED_TRIP = 150.00;   // used if trips table doesn't have an amount column
$HIGH_VALUE_FUEL = 3000.00;               // PHP threshold for approvals
$HIGH_VALUE_SUPPLY = 5000.00;             // PHP threshold for approvals

// ---------- DB CONNECT ----------
$pdo = null;
try {
    $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo "DB connection failed: " . htmlspecialchars($e->getMessage());
    exit;
}

// ---------- LIGHT MIGRATIONS (safe, IF NOT EXISTS) ----------
try {

    // HR tables (if you already have them, this is a no-op)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_profiles (
          driver_id INT PRIMARY KEY,
          hr_employee_id INT NULL,
          first_name VARCHAR(100) NULL,
          last_name VARCHAR(100) NULL,
          contact_number VARCHAR(50) NULL,
          email VARCHAR(150) NULL,
          address VARCHAR(255) NULL,
          license_number VARCHAR(100) NULL,
          license_expiry DATE NULL,
          status VARCHAR(50) DEFAULT 'active',
          rating DECIMAL(3,2) DEFAULT 5.00,
          conflict_count INT DEFAULT 0,
          date_hired DATE NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_compliance (
          id INT AUTO_INCREMENT PRIMARY KEY,
          driver_id INT NOT NULL,
          nbi_clearance_expiry DATE NULL,
          medical_clearance_expiry DATE NULL,
          training_cert_expiry DATE NULL,
          notes TEXT NULL,
          UNIQUE KEY uq_dc_driver (driver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Incident tracker
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_incidents (
          id INT AUTO_INCREMENT PRIMARY KEY,
          driver_id INT NOT NULL,
          incident_type ENUM('accident','complaint','commendation') NOT NULL,
          description TEXT NULL,
          severity ENUM('low','medium','high') DEFAULT 'low',
          status ENUM('open','in_review','closed') DEFAULT 'open',
          reported_by VARCHAR(100) NULL,
          reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Fuel logs (efficiency + cost)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fuel_logs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          vehicle_id INT NOT NULL,
          driver_id INT NULL,
          liters DECIMAL(10,2) NOT NULL,
          cost DECIMAL(12,2) NOT NULL,
          odometer_before INT NULL,
          odometer_after INT NULL,
          station VARCHAR(150) NULL,
          notes TEXT NULL,
          filled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          created_by VARCHAR(100) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Budgets (simple monthly buckets)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS budgets (
          id INT AUTO_INCREMENT PRIMARY KEY,
          month_year CHAR(7) NOT NULL, /* e.g. 2025-08 */
          category ENUM('fuel','repairs','supplies') NOT NULL,
          amount DECIMAL(12,2) NOT NULL,
          UNIQUE KEY uq_budget (month_year, category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Optional supply pricing (to value consumption)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS supply_prices (
          id INT AUTO_INCREMENT PRIMARY KEY,
          supply_id INT NOT NULL,
          unit_price DECIMAL(12,2) NOT NULL,
          currency CHAR(3) DEFAULT 'PHP',
          UNIQUE KEY uq_supply (supply_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Approvals
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS approvals (
          id INT AUTO_INCREMENT PRIMARY KEY,
          entity_type ENUM('fuel','supply') NOT NULL,
          entity_id INT NOT NULL,
          amount DECIMAL(12,2) NOT NULL,
          status ENUM('pending','approved','rejected') DEFAULT 'pending',
          requested_by VARCHAR(100) NULL,
          requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          decided_by VARCHAR(100) NULL,
          decided_at TIMESTAMP NULL DEFAULT NULL,
          notes TEXT NULL,
          UNIQUE KEY uq_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

} catch (Exception $e) {
    // Non-fatal; dashboard still renders with what exists.
}

// ---------- HELPERS ----------
function json_out($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function col_exists(PDO $pdo, $table, $col) {
    try {
        $pdo->query("SELECT `$col` FROM `$table` LIMIT 0");
        return true;
    } catch (Exception $e) {
        return false;
    }
}
function table_exists(PDO $pdo, $table) {
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ---------- API ----------
$action = $_GET['action'] ?? null;
if ($action) {
    // who is the user? (optional; if you have auth session, read it)
    $username = 'ops_manager';

    // ------- Dashboard Overview -------
    if ($action === 'getOverview') {
        try {
            // Trips summary
            $tripCounts = ['total'=>0,'completed'=>0,'cancelled'=>0,'ongoing'=>0,'pending'=>0];
            if (table_exists($pdo, 'trips') && col_exists($pdo,'trips','status')) {
                $rows = $pdo->query("SELECT status, COUNT(*) cnt FROM trips GROUP BY status")->fetchAll();
                $all = 0;
                foreach ($rows as $r) { $tripCounts[$r['status']] = (int)$r['cnt']; $all += (int)$r['cnt']; }
                $tripCounts['total'] = $all;
            }

            // Earnings (prefer real column if present)
            $earnings = 0.00;
            if (table_exists($pdo,'trips') && col_exists($pdo,'trips','amount')) {
                $earnings = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM trips WHERE status='completed'")->fetchColumn();
            } else {
                // estimate
                $completed = $tripCounts['completed'] ?? 0;
                $earnings = $completed * $BASE_FARE_PER_COMPLETED_TRIP;
            }

            // Vehicles availability
            $fleet = ['total'=>0,'available'=>0,'in_use'=>0,'maintenance'=>0];
            if (table_exists($pdo,'vehicles') && col_exists($pdo,'vehicles','status')) {
                $f = $pdo->query("
                    SELECT
                      COUNT(*) total,
                      SUM(status='available') available,
                      SUM(status='in_use') in_use,
                      SUM(status='maintenance') maintenance
                    FROM vehicles
                ")->fetch();
                $fleet = array_map('intval', $f);
            }

            // Fuel last 30 days
            $fuel = ['liters'=>0.0,'cost'=>0.0];
            if (table_exists($pdo,'fuel_logs')) {
                $fuel = $pdo->query("
                    SELECT COALESCE(SUM(liters),0) liters, COALESCE(SUM(cost),0) cost
                    FROM fuel_logs
                    WHERE filled_at >= NOW() - INTERVAL 30 DAY
                ")->fetch();
                $fuel['liters'] = (float)$fuel['liters'];
                $fuel['cost'] = (float)$fuel['cost'];
            }

            json_out([
                'trips'=>$tripCounts,
                'earnings'=>round($earnings,2),
                'fleet'=>$fleet,
                'fuel'=>$fuel
            ]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }

    // ------- Drivers (HR) -------
    if ($action === 'fetchDriversHR') {
        try {
            $rows = $pdo->query("
                SELECT
                  d.id driver_id, d.name driver_name, d.phone, d.status driver_status,
                  dp.hr_employee_id, dp.first_name, dp.last_name, dp.email, dp.contact_number,
                  dp.address, dp.license_number, dp.license_expiry, dp.status employment_status,
                  dp.date_hired, dp.rating, dp.conflict_count,
                  dc.nbi_clearance_expiry, dc.medical_clearance_expiry, dc.training_cert_expiry
                FROM drivers d
                LEFT JOIN driver_profiles dp ON dp.driver_id = d.id
                LEFT JOIN driver_compliance dc ON dc.driver_id = d.id
                ORDER BY d.id ASC
            ")->fetchAll();
            json_out(['drivers'=>$rows]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }

    // ------- Incidents -------
if ($action === 'fetchIncidents') {
    try {
        $rows = $pdo->query("
            SELECT di.*,
                   COALESCE(d.name, CONCAT(dp.first_name, ' ', dp.last_name)) AS driver_name
            FROM driver_incidents di
            LEFT JOIN drivers d       ON d.id = di.driver_id
            LEFT JOIN driver_profiles dp ON dp.driver_id = di.driver_id
            ORDER BY di.reported_at DESC
            LIMIT 300
        ")->fetchAll();
        json_out(['incidents'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}
    if ($action === 'addIncident' && $_SERVER['REQUEST_METHOD']==='POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        $driver_id = (int)($b['driver_id'] ?? 0);
        $type = $b['incident_type'] ?? '';
        $desc = trim($b['description'] ?? '');
        $severity = $b['severity'] ?? 'low';
        if (!$driver_id || !in_array($type,['accident','complaint','commendation'],true)) {
            json_out(['error'=>'driver_id and valid incident_type required']); }
        try {
            $st = $pdo->prepare("INSERT INTO driver_incidents (driver_id, incident_type, description, severity, reported_by) VALUES (:d,:t,:x,:s,:u)");
            $st->execute(['d'=>$driver_id,'t'=>$type,'x'=>$desc,'s'=>$severity,'u'=>$username]);
            json_out(['success'=>true]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }
    if ($action === 'updateIncident' && $_SERVER['REQUEST_METHOD']==='POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($b['id'] ?? 0);
        $status = $b['status'] ?? null;
        if (!$id || !in_array($status,['open','in_review','closed'],true)) json_out(['error'=>'invalid']);
        try {
            $st = $pdo->prepare("UPDATE driver_incidents SET status=:s WHERE id=:id");
            $st->execute(['s'=>$status,'id'=>$id]);
            json_out(['success'=>true]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }

    // ------- Fleet & Predictive Alerts -------
    if ($action === 'fetchFleet') {
        try {
            $vehicles = $pdo->query("SELECT * FROM vehicles ORDER BY status DESC, plate_no ASC")->fetchAll();

            // predictive risk: recent repair count + overdue schedule + high mileage rate
            $alerts = [];
            $since = (new DateTime('-60 days'))->format('Y-m-d H:i:s');

            // recent repairs per vehicle
            $repairs = [];
            if (table_exists($pdo,'repair_logs')) {
                $r = $pdo->query("SELECT vehicle_id, COUNT(*) c FROM repair_logs WHERE log_date >= '$since' GROUP BY vehicle_id")->fetchAll();
                foreach ($r as $row) $repairs[(int)$row['vehicle_id']] = (int)$row['c'];
            }

            // overdue scheduled maint
            $overdue = [];
            if (table_exists($pdo,'maintenance_schedule')) {
                $o = $pdo->query("SELECT vehicle_id, COUNT(*) c FROM maintenance_schedule WHERE status IN ('scheduled') AND scheduled_date < CURDATE() GROUP BY vehicle_id")->fetchAll();
                foreach ($o as $row) $overdue[(int)$row['vehicle_id']] = (int)$row['c'];
            }

            // mileage/day proxy from fuel logs
            $kmday = []; // km per day
            if (table_exists($pdo,'fuel_logs')) {
                $km = $pdo->query("
                    SELECT vehicle_id,
                           (MAX(odometer_after) - MIN(odometer_before)) / GREATEST(DATEDIFF(MAX(filled_at), MIN(filled_at)),1) AS km_per_day
                    FROM fuel_logs
                    WHERE filled_at >= NOW() - INTERVAL 30 DAY
                      AND odometer_before IS NOT NULL AND odometer_after IS NOT NULL
                    GROUP BY vehicle_id
                ")->fetchAll();
                foreach ($km as $row) $kmday[(int)$row['vehicle_id']] = max(0, (float)$row['km_per_day']);
            }

            foreach ($vehicles as $v) {
                $vid = (int)$v['id'];
                $score = 0.0;
                $score += ($repairs[$vid] ?? 0) * 0.5;
                $score += ($overdue[$vid] ?? 0) * 0.8;
                $score += ($kmday[$vid] ?? 0)/300.0; // if >300km/day → +1
                if ($score >= 1.0) {
                    $alerts[] = [
                        'vehicle_id'=>$vid,
                        'plate_no'=>$v['plate_no'],
                        'score'=>round($score,2),
                        'reason'=>[
                            ($repairs[$vid]??0) ? "{$repairs[$vid]} recent repairs" : null,
                            ($overdue[$vid]??0) ? "{$overdue[$vid]} overdue maint." : null,
                            ($kmday[$vid]??0) ? number_format($kmday[$vid],1)." km/day" : null
                        ]
                    ];
                }
            }

            json_out(['vehicles'=>$vehicles,'alerts'=>$alerts]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }

    // ------- Fuel (logs + efficiency) -------
    if ($action === 'fetchFuel') {
        try {
            $logs = $pdo->query("
                SELECT fl.*, v.plate_no, d.name AS driver_name
                FROM fuel_logs fl
                LEFT JOIN vehicles v ON v.id = fl.vehicle_id
                LEFT JOIN drivers d ON d.id = fl.driver_id
                ORDER BY fl.filled_at DESC
                LIMIT 300
            ")->fetchAll();

            // efficiency per vehicle (last 60d)
            $eff = [];
            $rows = $pdo->query("
                SELECT vehicle_id,
                       SUM(GREATEST(0, odometer_after - odometer_before)) AS km,
                       SUM(liters) AS liters
                FROM fuel_logs
                WHERE filled_at >= NOW() - INTERVAL 60 DAY
                GROUP BY vehicle_id
            ")->fetchAll();
            foreach ($rows as $r) {
                $km = (float)$r['km'];
                $l  = (float)$r['liters'];
                $eff[(int)$r['vehicle_id']] = ($l>0 ? round($km/$l,2) : null);
            }

            $cost30 = $pdo->query("SELECT COALESCE(SUM(cost),0) FROM fuel_logs WHERE filled_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
            json_out(['logs'=>$logs,'efficiency'=>$eff,'cost30'=>round((float)$cost30,2)]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }
    if ($action === 'addFuelLog' && $_SERVER['REQUEST_METHOD']==='POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        $vid = (int)($b['vehicle_id'] ?? 0);
        $did = !empty($b['driver_id']) ? (int)$b['driver_id'] : null;
        $lit = (float)($b['liters'] ?? 0);
        $cost= (float)($b['cost'] ?? 0);
        $ob  = isset($b['odometer_before']) ? (int)$b['odometer_before'] : null;
        $oa  = isset($b['odometer_after']) ? (int)$b['odometer_after'] : null;
        $stn = trim($b['station'] ?? '');
        $nts = trim($b['notes'] ?? '');
        if ($vid<=0 || $lit<=0 || $cost<=0) json_out(['error'=>'vehicle_id, liters, cost required']);
        try {
            $st = $pdo->prepare("INSERT INTO fuel_logs (vehicle_id, driver_id, liters, cost, odometer_before, odometer_after, station, notes, created_by) VALUES (:v,:d,:l,:c,:ob,:oa,:s,:n,:u)");
            $st->execute(['v'=>$vid,'d'=>$did,'l'=>$lit,'c'=>$cost,'ob'=>$ob,'oa'=>$oa,'s'=>$stn,'n'=>$nts,'u'=>$username]);

            // create approval if high value
            if ($cost >= $HIGH_VALUE_FUEL) {
                $pdo->prepare("INSERT IGNORE INTO approvals (entity_type, entity_id, amount, requested_by) VALUES ('fuel', LAST_INSERT_ID(), :amt, :u)")
                    ->execute(['amt'=>$cost,'u'=>$username]);
            }

            json_out(['success'=>true]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }

    // ------- Budget vs Actual -------
    if ($action === 'fetchBudget') {
        $month = $_GET['month'] ?? date('Y-m');
        try {
            // budgets
            $bud = $pdo->prepare("SELECT category, amount FROM budgets WHERE month_year=:m");
            $bud->execute(['m'=>$month]);
            $budget = ['fuel'=>0.0,'repairs'=>0.0,'supplies'=>0.0];
            foreach ($bud->fetchAll() as $b) $budget[$b['category']] = (float)$b['amount'];

            // actual fuel
            $fuel = 0.0;
            if (table_exists($pdo,'fuel_logs')) {
                $fuel = (float)$pdo->query("SELECT COALESCE(SUM(cost),0) FROM fuel_logs WHERE DATE_FORMAT(filled_at,'%Y-%m') = ".$pdo->quote($month))->fetchColumn();
            }
            // actual repairs
            $repairs = 0.0;
            if (table_exists($pdo,'repair_logs') && col_exists($pdo,'repair_logs','cost')) {
                $repairs = (float)$pdo->query("SELECT COALESCE(SUM(cost),0) FROM repair_logs WHERE DATE_FORMAT(log_date,'%Y-%m') = ".$pdo->quote($month))->fetchColumn();
            }
            // actual supplies valuation (issued_items quantity * supply_prices.unit_price)
            $supplies = 0.0;
            if (table_exists($pdo,'issued_items') && table_exists($pdo,'supply_prices')) {
                $supplies = (float)$pdo->query("
                    SELECT COALESCE(SUM(ii.quantity_issued * sp.unit_price),0) AS amt
                    FROM issued_items ii
                    JOIN supply_prices sp ON sp.supply_id = ii.supply_id
                    WHERE DATE_FORMAT(ii.issued_at,'%Y-%m') = ".$pdo->quote($month)."
                ")->fetchColumn();
            }

            json_out([
                'month'=>$month,
                'budget'=>$budget,
                'actual'=>[
                    'fuel'=>round($fuel,2),
                    'repairs'=>round($repairs,2),
                    'supplies'=>round($supplies,2),
                ]
            ]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }
    if ($action === 'saveBudget' && $_SERVER['REQUEST_METHOD']==='POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        $month = $b['month'] ?? date('Y-m');
        $items = ['fuel','repairs','supplies'];
        try {
            foreach ($items as $cat) {
                if (!isset($b[$cat])) continue;
                $amt = (float)$b[$cat];
                $st = $pdo->prepare("INSERT INTO budgets (month_year, category, amount) VALUES (:m,:c,:a)
                                     ON DUPLICATE KEY UPDATE amount = VALUES(amount)");
                $st->execute(['m'=>$month,'c'=>$cat,'a'=>$amt]);
            }
            json_out(['success'=>true]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }

    // ------- Approvals (high value fuel/supplies) -------
    if ($action === 'fetchApprovals') {
        try {
            $rows = $pdo->query("
                SELECT a.*,
                       CASE WHEN a.entity_type='fuel'
                            THEN (SELECT CONCAT('Fuel#',fl.id,' / ',v.plate_no) FROM fuel_logs fl LEFT JOIN vehicles v ON v.id=fl.vehicle_id WHERE fl.id=a.entity_id)
                            ELSE (SELECT CONCAT('Supply#',ii.issued_id,' / ',s.item_name) FROM issued_items ii LEFT JOIN supplies s ON s.supply_id=ii.supply_id WHERE ii.issued_id=a.entity_id)
                       END AS ref
                FROM approvals a
                ORDER BY a.requested_at DESC
                LIMIT 200
            ")->fetchAll();
            json_out(['approvals'=>$rows]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }
    if ($action === 'decideApproval' && $_SERVER['REQUEST_METHOD']==='POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($b['id'] ?? 0);
        $decision = $b['decision'] ?? '';
        if (!$id || !in_array($decision,['approved','rejected'],true)) json_out(['error'=>'invalid']);
        try {
            $st = $pdo->prepare("UPDATE approvals SET status=:s, decided_by=:u, decided_at=NOW() WHERE id=:id");
            $st->execute(['s'=>$decision,'u'=>$username,'id'=>$id]);
            json_out(['success'=>true]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }

    // ------- Create approval for supply manually (optional) -------
    if ($action === 'flagSupplyForApproval' && $_SERVER['REQUEST_METHOD']==='POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        $issued_id = (int)($b['issued_id'] ?? 0);
        $amount = (float)($b['amount'] ?? 0);
        if ($issued_id<=0 || $amount<=0) json_out(['error'=>'issued_id and amount required']);
        try {
            $pdo->prepare("INSERT IGNORE INTO approvals (entity_type, entity_id, amount, requested_by) VALUES ('supply', :id, :amt, :u)")
                ->execute(['id'=>$issued_id, 'amt'=>$amount, 'u'=>$username]);
            json_out(['success'=>true]);
        } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
    }

    // fallthrough unknown
    json_out(['error'=>'Unknown action']);
}

// ---------- UI ----------
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>ViaHale — Operations Manager</title>
<style>
    :root{
        --bg:#0b0f12; --panel:#11161b; --muted:#6b7682; --text:#e7edf3; --bright:#ffffff;
        --accent: <?=htmlspecialchars($VIABRAND)?>; --danger:#ff5d5d; --warn:#ffcc66; --ok:#3ddc97;
        --chip:#1b242c; --border:#1b232b;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif}
    a{color:var(--accent);text-decoration:none}
    .layout{display:flex;min-height:100vh}
    .sidebar{width:280px;background:#0a0e11;border-right:1px solid var(--border);padding:16px 14px;position:sticky;top:0;height:100vh}
    .brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:18px;margin-bottom:18px}
    .brand .logo{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--accent),#0bf7d3);box-shadow:0 0 26px #00e0b84d}
    .nav{display:flex;flex-direction:column;gap:6px}
    .nav button{all:unset;display:flex;gap:10px;align-items:center;padding:10px 12px;border-radius:12px;color:#aab4be;cursor:pointer}
    .nav button.active{background:var(--panel);color:var(--bright);border:1px solid var(--border)}
    .nav .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 10px var(--accent)}
    .content{flex:1;padding:20px}
    .grid{display:grid;gap:12px}
    @media(min-width:900px){ .grid.cols-3{grid-template-columns:repeat(3,1fr)} .grid.cols-2{grid-template-columns:repeat(2,1fr)} }
    .card{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:14px;box-shadow:0 0 0 1px #0a0e11 inset}
    .card h3{margin:0 0 10px;font-size:15px;letter-spacing:.2px}
    .kpi{display:flex;align-items:baseline;gap:8px}
    .kpi .v{font-size:26px;font-weight:700}
    .kpi .s{color:var(--muted)}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th,td{padding:8px;border-bottom:1px solid var(--border)}
    th{color:#9fb0bf;text-align:left;font-weight:600}
    tr:hover td{background:#0f1318}
    .toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px}
    input,select,textarea{background:#0c1216;color:var(--text);border:1px solid var(--border);border-radius:10px;padding:8px;font:inherit}
    .btn{background:var(--accent);color:#001914;border:0;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer}
    .btn.ghost{background:transparent;color:var(--accent);border:1px solid var(--accent)}
    .chip{display:inline-flex;align-items:center;gap:6px;background:var(--chip);border:1px solid var(--border);padding:4px 8px;border-radius:999px;font-size:12px}
    .status-ok{color:var(--ok)} .status-warn{color:var(--warn)} .status-bad{color:var(--danger)}
    .muted{color:var(--muted)}
</style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><img src="../logo.png" alt="logo" style="height:36px"> ViaHale <span class="muted">Operator Manager</span></div>
        <div class="nav">
            <button class="active" data-tab="overview"><span class="dot"></span>Overview</button>
            <button data-tab="drivers"><span class="dot"></span>Driver Info (HR)</button>
            <button data-tab="fleet"><span class="dot"></span>Fleet & Alerts</button>
            <button data-tab="fuel"><span class="dot"></span>Fuel & Efficiency</button>
            <button data-tab="approvals"><span class="dot"></span>Approvals</button>
        </div>
    </aside>
    <main class="content">
        <!-- OVERVIEW -->
        <section id="tab-overview" class="tab">
            <div class="grid cols-3">
                <div class="card"><h3>Trips (30d)</h3><div class="kpi"><div class="v" id="ov-trips-total">–</div><div class="s">total</div></div><div class="muted">Completed: <span id="ov-trips-completed">–</span> · Ongoing: <span id="ov-trips-ongoing">–</span> · Pending: <span id="ov-trips-pending">–</span> · Cancelled: <span id="ov-trips-cancelled">–</span></div></div>
                <div class="card"><h3>Earnings (est.)</h3><div class="kpi"><div class="v">₱<span id="ov-earnings">–</span></div><div class="s">completed trips</div></div></div>
                <div class="card"><h3>Fuel (30d)</h3><div class="kpi"><div class="v"><span id="ov-fuel-liters">–</span>L</div><div class="s">₱<span id="ov-fuel-cost">–</span></div></div></div>
            </div>
            <div class="grid cols-2" style="margin-top:12px">
                <div class="card">
                    <h3>Vehicle Availability</h3>
                    <div class="kpi"><div class="v" id="ov-fleet-total">–</div><div class="s">total</div></div>
                    <div class="muted">Available: <span class="status-ok" id="ov-fleet-available">–</span> · In Use: <span id="ov-fleet-inuse">–</span> · Maintenance: <span class="status-warn" id="ov-fleet-maint">–</span></div>
                </div>
                <div class="card">
                    <h3>Quick Links</h3>
                    <div class="toolbar">
                        <a class="btn ghost" href="../dispatcher/dispatcher_dashboard.php" target="_blank">Open Dispatcher</a>
                        <a class="btn ghost" href="../fleet/fleet_dashboard.php" target="_blank">Open Fleet Staff</a>
                        <a class="btn ghost" href="../storeroom/storeroom_dashboard.php" target="_blank">Open Storeroom</a>
                    </div>
                    <div class="muted">These open the dedicated role dashboards. Ops overview pulls data directly from the same DB.</div>
                </div>
            </div>
        </section>

        <!-- DRIVERS (HR) -->
        <section id="tab-drivers" class="tab" style="display:none">
            <div class="card">
                <h3>Driver Information System</h3>
                <div class="toolbar">
                    <input id="drv-filter" placeholder="Search name, email, license…" style="min-width:260px" />
                </div>
                <div style="overflow:auto">
                    <table id="tbl-drivers">
                        <thead><tr>
                            <th>ID</th><th>Name</th><th>Phone</th><th>Ops Status</th>
                            <th>HR ID</th><th>Email</th><th>License</th><th>License Expiry</th>
                            <th>Employment</th><th>Hired</th><th>Rating</th><th>NBI</th><th>Medical</th><th>Training</th>
                        </tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="muted">This reads <code>drivers</code>, <code>driver_profiles</code>, and <code>driver_compliance</code>. If HR pushes data, it shows up here automatically.</div>
            </div>
        </section>

        <!-- FLEET -->
        <section id="tab-fleet" class="tab" style="display:none">
            <div class="grid cols-2">
                <div class="card">
                    <h3>Vehicles</h3>
                    <div style="overflow:auto;max-height:420px">
                        <table id="tbl-vehicles">
                            <thead><tr><th>ID</th><th>Plate</th><th>Model</th><th>Status</th><th>Year</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="card">
                    <h3>Predictive Maintenance Alerts</h3>
                    <div style="overflow:auto;max-height:420px">
                        <table id="tbl-alerts">
                            <thead><tr><th>Vehicle</th><th>Risk</th><th>Why</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="muted">Heuristic score uses: recent repairs (60d), overdue schedules, and km/day from fuel logs (30d).</div>
                </div>
            </div>
        </section>

        <!-- FUEL -->
        <section id="tab-fuel" class="tab" style="display:none">
            <div class="grid cols-2">
                <div class="card">
                    <h3>Add Fuel Log</h3>
                    <div class="grid cols-2">
                        <div><label>Vehicle ID</label><input id="fl-vid" placeholder="e.g. 1"></div>
                        <div><label>Driver ID</label><input id="fl-did" placeholder="optional"></div>
                        <div><label>Liters</label><input id="fl-lit" type="number" step="0.01"></div>
                        <div><label>Cost</label><input id="fl-cost" type="number" step="0.01"></div>
                        <div><label>Odometer Before</label><input id="fl-ob" type="number"></div>
                        <div><label>Odometer After</label><input id="fl-oa" type="number"></div>
                        <div class="grid"><label>Station</label><input id="fl-stn"></div>
                        <div class="grid"><label>Notes</label><input id="fl-notes"></div>
                    </div>
                    <div style="margin-top:10px"><button class="btn" onclick="addFuel()">Save Log</button></div>
                    <div class="muted" style="margin-top:6px">Logs with cost ≥ ₱<?=number_format($HIGH_VALUE_FUEL,0)?> are automatically sent to Approvals.</div>
                </div>
                <div class="card">
                    <h3>Last 60 Days Efficiency</h3>
                    <div id="fuel-summary" class="muted">Loading…</div>
                    <div style="overflow:auto;max-height:340px;margin-top:8px">
                        <table id="tbl-fuel">
                            <thead><tr><th>#</th><th>Date</th><th>Plate</th><th>Driver</th><th>Liters</th><th>Cost</th><th>Odo Δ</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- INCIDENTS -->
        <section id="tab-incidents" class="tab" style="display:none">
            <div class="grid cols-2">
                <div class="card">
                    <h3>Report Incident</h3>
                    <div class="grid cols-2">
                        <div><label>Driver ID</label><input id="ic-driver" placeholder="e.g. 1"></div>
                        <div><label>Type</label>
                            <select id="ic-type"><option>accident</option><option>complaint</option><option>commendation</option></select>
                        </div>
                        <div><label>Severity</label>
                            <select id="ic-sev"><option>low</option><option>medium</option><option>high</option></select>
                        </div>
                        <div class="grid"><label>Description</label><textarea id="ic-desc" rows="3"></textarea></div>
                    </div>
                    <div style="margin-top:10px"><button class="btn" onclick="addIncident()">Submit</button></div>
                </div>
                <div class="card">
                    <h3>Incident Log</h3>
                    <div style="overflow:auto;max-height:380px">
                        <table id="tbl-incidents">
                            <thead><tr><th>#</th><th>When</th><th>Driver</th><th>Type</th><th>Severity</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- BUDGET -->
        <section id="tab-budget" class="tab" style="display:none">
            <div class="card">
                <h3>Budget vs Actual</h3>
                <div class="toolbar">
                    <input id="bdg-month" type="month" value="<?=htmlspecialchars(date('Y-m'))?>">
                    <button class="btn" onclick="loadBudget()">Load</button>
                </div>
                <div class="grid cols-3" id="bdg-cards">
                    <div class="card"><h3>Fuel</h3><div class="kpi"><div class="v">₱<span id="bdg-fuel-a">–</span></div><div class="s">of ₱<span id="bdg-fuel-b">–</span></div></div></div>
                    <div class="card"><h3>Repairs</h3><div class="kpi"><div class="v">₱<span id="bdg-rep-a">–</span></div><div class="s">of ₱<span id="bdg-rep-b">–</span></div></div></div>
                    <div class="card"><h3>Supplies</h3><div class="kpi"><div class="v">₱<span id="bdg-sup-a">–</span></div><div class="s">of ₱<span id="bdg-sup-b">–</span></div></div></div>
                </div>
                <h3 style="margin-top:10px">Set Budgets</h3>
                <div class="grid cols-3">
                    <div><label>Fuel</label><input id="set-fuel" type="number" step="0.01"></div>
                    <div><label>Repairs</label><input id="set-rep" type="number" step="0.01"></div>
                    <div><label>Supplies</label><input id="set-sup" type="number" step="0.01"></div>
                </div>
                <div style="margin-top:10px"><button class="btn" onclick="saveBudget()">Save Budget</button></div>
                <div class="muted" style="margin-top:6px">Supplies “Actual” uses <code>issued_items × supply_prices.unit_price</code>. Set prices in Storeroom or insert here.</div>
            </div>
        </section>

        <!-- APPROVALS -->
        <section id="tab-approvals" class="tab" style="display:none">
            <div class="card">
                <h3>Pending Approvals</h3>
                <div style="overflow:auto">
                    <table id="tbl-approvals">
                        <thead><tr><th>#</th><th>Type</th><th>Reference</th><th>Amount</th><th>Status</th><th>Requested</th><th>Action</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </section>

    </main>
</div>

<script>
const fmt = n => (Number(n||0)).toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:0});
const money = n => (Number(n||0)).toLocaleString(undefined,{style:'decimal',minimumFractionDigits:2,maximumFractionDigits:2});

document.querySelectorAll('.nav button').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.querySelectorAll('.nav button').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const t = btn.dataset.tab;
    document.querySelectorAll('.tab').forEach(sec=>sec.style.display='none');
    document.getElementById('tab-'+t).style.display='';
    if (t==='overview') loadOverview();
    if (t==='drivers') loadDrivers();
    if (t==='fleet') loadFleet();
    if (t==='fuel') loadFuel();
    if (t==='incidents') loadIncidents();
    if (t==='budget') loadBudget();
    if (t==='approvals') loadApprovals();
  });
});

async function api(path, body) {
  const opt = body ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)} : {};
  const r = await fetch('?action='+encodeURIComponent(path), opt);
  return await r.json();
}

// OVERVIEW
async function loadOverview(){
  const d = await api('getOverview');
  if (d.error){ console.warn(d.error); return; }
  document.getElementById('ov-trips-total').textContent = fmt(d.trips.total);
  document.getElementById('ov-trips-completed').textContent = fmt(d.trips.completed||0);
  document.getElementById('ov-trips-ongoing').textContent = fmt(d.trips.ongoing||0);
  document.getElementById('ov-trips-pending').textContent = fmt(d.trips.pending||0);
  document.getElementById('ov-trips-cancelled').textContent = fmt(d.trips.cancelled||0);
  document.getElementById('ov-earnings').textContent = money(d.earnings);
  document.getElementById('ov-fuel-liters').textContent = money(d.fuel.liters);
  document.getElementById('ov-fuel-cost').textContent = money(d.fuel.cost);
  document.getElementById('ov-fleet-total').textContent = fmt(d.fleet.total||0);
  document.getElementById('ov-fleet-available').textContent = fmt(d.fleet.available||0);
  document.getElementById('ov-fleet-inuse').textContent = fmt(d.fleet.in_use||0);
  document.getElementById('ov-fleet-maint').textContent = fmt(d.fleet.maintenance||0);
}

// DRIVERS
let driversCache = [];
async function loadDrivers(){
  const d = await api('fetchDriversHR');
  if (d.error){ console.warn(d.error); return; }
  driversCache = d.drivers||[];
  renderDrivers();
}
function renderDrivers(){
  const q = (document.getElementById('drv-filter').value||'').toLowerCase();
  const tb = document.querySelector('#tbl-drivers tbody');
  tb.innerHTML='';
  (driversCache||[]).filter(r=>{
    const s = `${r.driver_id} ${r.driver_name||''} ${r.email||''} ${r.license_number||''}`.toLowerCase();
    return !q || s.includes(q);
  }).forEach(r=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${r.driver_id}</td>
      <td>${r.driver_name||''}</td>
      <td>${r.phone||''}</td>
      <td>${r.driver_status||''}</td>
      <td>${r.hr_employee_id||''}</td>
      <td>${r.email||''}</td>
      <td>${r.license_number||''}</td>
      <td>${r.license_expiry||''}</td>
      <td>${r.employment_status||''}</td>
      <td>${r.date_hired||''}</td>
      <td>${r.rating||''}</td>
      <td>${r.nbi_clearance_expiry||''}</td>
      <td>${r.medical_clearance_expiry||''}</td>
      <td>${r.training_cert_expiry||''}</td>
    `;
    tb.appendChild(tr);
  });
}
document.getElementById('drv-filter').addEventListener('input', renderDrivers);

// FLEET
async function loadFleet(){
  const d = await api('fetchFleet');
  if (d.error){ console.warn(d.error); return; }
  const tb = document.querySelector('#tbl-vehicles tbody');
  tb.innerHTML='';
  (d.vehicles||[]).forEach(v=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${v.id}</td><td>${v.plate_no}</td><td>${v.model||''}</td><td>${v.status||''}</td><td>${v.make_year||''}</td>`;
    tb.appendChild(tr);
  });
  const ta = document.querySelector('#tbl-alerts tbody');
  ta.innerHTML='';
  (d.alerts||[]).forEach(a=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${a.plate_no}</td><td class="${a.score>=1.8?'status-bad':(a.score>=1.2?'status-warn':'')}">${a.score}</td><td>${(a.reason||[]).filter(Boolean).join(' • ')}</td>`;
    ta.appendChild(tr);
  });
}

// FUEL
async function loadFuel(){
  const d = await api('fetchFuel');
  if (d.error){ console.warn(d.error); return; }
  document.getElementById('fuel-summary').innerHTML = `Fuel cost (30d): <b>₱${money(d.cost30)}</b> · Efficiency: see per-vehicle below`;
  const tb = document.querySelector('#tbl-fuel tbody');
  tb.innerHTML='';
  (d.logs||[]).forEach(x=>{
    const delta = (x.odometer_after&&x.odometer_before) ? (x.odometer_after - x.odometer_before) : '';
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${x.id}</td><td>${x.filled_at}</td><td>${x.plate_no||''}</td><td>${x.driver_name||''}</td><td>${money(x.liters)}</td><td>${money(x.cost)}</td><td>${delta}</td>`;
    tb.appendChild(tr);
  });
}
async function addFuel(){
  const body = {
    vehicle_id: Number(document.getElementById('fl-vid').value||0),
    driver_id: Number(document.getElementById('fl-did').value||0) || null,
    liters: Number(document.getElementById('fl-lit').value||0),
    cost: Number(document.getElementById('fl-cost').value||0),
    odometer_before: document.getElementById('fl-ob').value ? Number(document.getElementById('fl-ob').value) : null,
    odometer_after:  document.getElementById('fl-oa').value ? Number(document.getElementById('fl-oa').value) : null,
    station: document.getElementById('fl-stn').value,
    notes: document.getElementById('fl-notes').value
  };
  const d = await api('addFuelLog', body);
  if (d && d.success){ alert('Saved'); loadFuel(); } else { alert(d.error||'Failed'); }
}

// INCIDENTS
async function loadIncidents(){
  const d = await api('fetchIncidents');
  if (d.error){ console.warn(d.error); return; }
  const tb = document.querySelector('#tbl-incidents tbody');
  tb.innerHTML='';
  (d.incidents||[]).forEach(i=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${i.id}</td><td>${i.reported_at}</td><td>${i.driver_name||i.driver_id}</td>
      <td>${i.incident_type}</td><td>${i.severity}</td>
      <td>${i.status}</td>
      <td>
        <button class="btn" onclick="decideIncident(${i.id}, 'in_review')">Review</button>
        <button class="btn ghost" onclick="decideIncident(${i.id}, 'closed')">Close</button>
      </td>`;
    tb.appendChild(tr);
  });
}
async function addIncident(){
  const d = await api('addIncident', {
    driver_id: Number(document.getElementById('ic-driver').value||0),
    incident_type: document.getElementById('ic-type').value,
    severity: document.getElementById('ic-sev').value,
    description: document.getElementById('ic-desc').value
  });
  if (d && d.success){ alert('Recorded'); loadIncidents(); } else { alert(d.error||'Failed'); }
}
async function decideIncident(id, status){
  const d = await api('updateIncident', {id, status});
  if (d && d.success){ loadIncidents(); } else { alert(d.error||'Failed'); }
}

// BUDGET
async function loadBudget(){
  const m = document.getElementById('bdg-month').value || new Date().toISOString().slice(0,7);
  const d = await api('fetchBudget&month='+encodeURIComponent(m));
  if (d.error){ console.warn(d.error); return; }
  document.getElementById('bdg-fuel-a').textContent = money(d.actual.fuel);
  document.getElementById('bdg-rep-a').textContent = money(d.actual.repairs);
  document.getElementById('bdg-sup-a').textContent  = money(d.actual.supplies);
  document.getElementById('bdg-fuel-b').textContent = money(d.budget.fuel);
  document.getElementById('bdg-rep-b').textContent  = money(d.budget.repairs);
  document.getElementById('bdg-sup-b').textContent  = money(d.budget.supplies);
  document.getElementById('set-fuel').value = d.budget.fuel;
  document.getElementById('set-rep').value  = d.budget.repairs;
  document.getElementById('set-sup').value  = d.budget.supplies;
}
async function saveBudget(){
  const m = document.getElementById('bdg-month').value || new Date().toISOString().slice(0,7);
  const d = await api('saveBudget', {
    month: m,
    fuel: Number(document.getElementById('set-fuel').value||0),
    repairs: Number(document.getElementById('set-rep').value||0),
    supplies: Number(document.getElementById('set-sup').value||0),
  });
  if (d && d.success){ loadBudget(); } else { alert(d.error||'Failed'); }
}

// APPROVALS
async function loadApprovals(){
  const d = await api('fetchApprovals');
  if (d.error){ console.warn(d.error); return; }
  const tb = document.querySelector('#tbl-approvals tbody');
  tb.innerHTML='';
  (d.approvals||[]).forEach(a=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${a.id}</td>
      <td>${a.entity_type}</td>
      <td>${a.ref||a.entity_id}</td>
      <td>₱${money(a.amount)}</td>
      <td>${a.status}</td>
      <td>${a.requested_at}</td>
      <td>
        ${a.status==='pending'
          ? `<button class="btn" onclick="decideApproval(${a.id},'approved')">Approve</button>
             <button class="btn ghost" onclick="decideApproval(${a.id},'rejected')">Reject</button>`
          : '<span class="muted">—</span>'}
      </td>`;
    tb.appendChild(tr);
  });
}
async function decideApproval(id, decision){
  const d = await api('decideApproval', {id, decision});
  if (d && d.success){ loadApprovals(); } else { alert(d.error||'Failed'); }
}

// initial
loadOverview();
</script>
</body>
</html>
