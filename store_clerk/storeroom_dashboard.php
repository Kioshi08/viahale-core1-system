<?php
// Store Room Clerk Dashboard — Single-File (AJAX + PHP pulse updates)
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('storeclerk', 'admin1');

// ========== SAFE MIGRATIONS (create expected tables if not present) ==========
try {
    // Suppliers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(150) NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(150) NULL,
            address VARCHAR(255) NULL,
            category VARCHAR(80) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Items (inventory)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            stock_quantity INT NOT NULL DEFAULT 0,
            reorder_level INT NOT NULL DEFAULT 5,
            unit VARCHAR(40) NULL,
            unit_cost DECIMAL(12,2) NULL,
            supplier_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL,
            INDEX (supplier_id),
            CONSTRAINT items_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Supply requests
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS supply_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            requested_by VARCHAR(150) NULL,
            quantity INT NOT NULL,
            priority ENUM('high','medium','low') DEFAULT 'medium',
            status ENUM('pending','approved','issued','rejected') DEFAULT 'pending',
            trip_id INT NULL,
            vehicle_id INT NULL,
            note TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL,
            INDEX (item_id),
            INDEX (trip_id),
            CONSTRAINT sr_item_fk FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Item usage log (history)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS item_usage (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            quantity INT NOT NULL,
            used_for_trip INT NULL,
            used_by VARCHAR(150) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (item_id),
            CONSTRAINT usage_item_fk FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    // continue even if migration partially fails
}

// ========== UTILITIES ==========
function json_out($data, $http=200) {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($data);
    exit;
}

// auto-prioritize a request based on trip context (if trip_id provided)
function auto_priority_for_request(PDO $pdo, $trip_id, $item_id, $quantity) {
    $priority = 'medium';
    if (!$trip_id) return $priority;
    try {
        $t = $pdo->prepare("SELECT priority AS trip_priority, status AS trip_status, scheduled_time FROM trips WHERE id=:id LIMIT 1");
        $t->execute(['id'=>$trip_id]);
        $trip = $t->fetch();
        if (!$trip) return $priority;

        if (!empty($trip['trip_priority']) || (!empty($trip['priority']) && $trip['priority']==1)) $priority = 'high';
        if (isset($trip['trip_status']) && $trip['trip_status']==='ongoing') $priority = 'high';

        if (!empty($trip['scheduled_time'])) {
            $sched = strtotime($trip['scheduled_time']);
            if ($sched !== false && $sched <= time() + (24*3600)) $priority = 'high';
            elseif ($sched <= time() + (72*3600) && $priority !== 'high') $priority = 'medium';
        }
        $critical_like = $pdo->prepare("SELECT name FROM items WHERE id=:id LIMIT 1");
        $critical_like->execute(['id'=>$item_id]);
        $iname = strtolower($critical_like->fetchColumn() ?: '');
        if ($iname !== '' && preg_match('/tire|brake|engine|fuel|battery|card|spare/', $iname)) $priority = 'high';
    } catch (Exception $e) { /* keep medium */ }
    return $priority;
}

// ========== API ENDPOINTS ==========
$action = $_GET['action'] ?? null;
if ($action) {
    // all API responses are JSON
    header('Content-Type: application/json; charset=utf-8');
}

// ---------- overview ----------
if ($action === 'overview') {
    try {
        $low = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE stock_quantity < reorder_level")->fetchColumn();
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM supply_requests WHERE status='pending'")->fetchColumn();
        $top = $pdo->query("
            SELECT i.id, i.name, SUM(u.quantity) AS used
            FROM item_usage u
            JOIN items i ON i.id = u.item_id
            WHERE u.created_at >= NOW() - INTERVAL 30 DAY
            GROUP BY i.id, i.name
            ORDER BY used DESC
            LIMIT 5
        ")->fetchAll();
        json_out(['low_stock_count'=>$low,'pending_requests'=>$pending,'top_used'=>$top]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()], 500); }
}

// ---------- list items ----------
if ($action === 'items') {
    try {
        $rows = $pdo->query("
            SELECT it.*, s.name AS supplier_name
            FROM items it
            LEFT JOIN suppliers s ON s.id = it.supplier_id
            ORDER BY it.name ASC
            LIMIT 1000
        ")->fetchAll(PDO::FETCH_ASSOC);
        json_out(['items'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()], 500); }
}

// ---------- create item ----------
if ($action === 'item_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($b['name'] ?? '');
    $desc = trim($b['description'] ?? '');
    $qty = (int)($b['stock_quantity'] ?? 0);
    $reorder = (int)($b['reorder_level'] ?? 5);
    $unit = trim($b['unit'] ?? '');
    $cost = isset($b['unit_cost']) ? (float)$b['unit_cost'] : null;
    $supplier_id = isset($b['supplier_id']) && $b['supplier_id'] !== '' ? (int)$b['supplier_id'] : null;

    if ($name === '') json_out(['error'=>'name required'], 400);
    try {
        $st = $pdo->prepare("INSERT INTO items (name, description, stock_quantity, reorder_level, unit, unit_cost, supplier_id, updated_at) VALUES (:n,:d,:q,:r,:u,:c,:s,NOW())");
        $st->execute(['n'=>$name,'d'=>$desc,'q'=>$qty,'r'=>$reorder,'u'=>$unit,'c'=>$cost,'s'=>$supplier_id]);
        json_out(['success'=>true,'id'=>$pdo->lastInsertId()]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()], 500); }
}

// ---------- suppliers ----------
if ($action === 'suppliers') {
    try {
        $rows = $pdo->query("SELECT * FROM suppliers ORDER BY name ASC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
        json_out(['suppliers'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()], 500); }
}

if ($action === 'supplier_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($b['name'] ?? '');
    if (!$name) json_out(['error'=>'name required'], 400);
    $st = $pdo->prepare("INSERT INTO suppliers (name, contact_person, phone, email, address, category) VALUES (:n,:cp,:ph,:em,:ad,:cat)");
    try {
        $st->execute([
            'n'=>$name,
            'cp'=>$b['contact_person']??null,
            'ph'=>$b['phone']??null,
            'em'=>$b['email']??null,
            'ad'=>$b['address']??null,
            'cat'=>$b['category']??null
        ]);
        json_out(['success'=>true,'id'=>$pdo->lastInsertId()]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()], 500); }
}

// ---------- supply requests ----------
if ($action === 'requests') {
    try {
        $rows = $pdo->query("
            SELECT r.*, i.name AS item_name, i.stock_quantity, t.trip_code AS trip_code, r.vehicle_id
            FROM supply_requests r
            LEFT JOIN items i ON i.id = r.item_id
            LEFT JOIN trips t ON t.id = r.trip_id
            ORDER BY FIELD(r.priority,'high','medium','low'), r.status ASC, r.created_at DESC
            LIMIT 1000
        ")->fetchAll(PDO::FETCH_ASSOC);
        json_out(['requests'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()], 500); }
}

// create request
if ($action === 'request_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $item_id = (int)($b['item_id'] ?? 0);
    $quantity = (int)($b['quantity'] ?? 0);
    $requested_by = trim($b['requested_by'] ?? $username);
    $trip_id = isset($b['trip_id']) && $b['trip_id'] !== '' ? (int)$b['trip_id'] : null;
    $vehicle_id = isset($b['vehicle_id']) && $b['vehicle_id'] !== '' ? (int)$b['vehicle_id'] : null;
    $note = trim($b['note'] ?? '');
    if (!$item_id || $quantity <= 0) json_out(['error'=>'item_id and positive quantity required'], 400);
    $priority = auto_priority_for_request($pdo, $trip_id, $item_id, $quantity);
    try {
        $st = $pdo->prepare("INSERT INTO supply_requests (item_id, requested_by, quantity, priority, status, trip_id, vehicle_id, note, updated_at) VALUES (:it,:rb,:q,:pr,'pending',:trip,:veh,:note,NOW())");
        $st->execute(['it'=>$item_id,'rb'=>$requested_by,'q'=>$quantity,'pr'=>$priority,'trip'=>$trip_id,'veh'=>$vehicle_id,'note'=>$note]);
        json_out(['success'=>true,'priority'=>$priority,'id'=>$pdo->lastInsertId()]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()], 500); }
}

// approve/reject/pending/issued (status-only; "issued" should go through request_issue)
if ($action === 'request_update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($b['id'] ?? 0);
    $status = $b['status'] ?? '';
    if (!$id || !in_array($status, ['pending','approved','issued','rejected'], true)) json_out(['error'=>'id and valid status required'], 400);
    try {
        $pdo->prepare("UPDATE supply_requests SET status=:s, updated_at=NOW() WHERE id=:id")->execute(['s'=>$status,'id'=>$id]);
        json_out(['success'=>true]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()], 500); }
}

// issue (deducts stock + logs usage)
if ($action === 'request_issue' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($b['id'] ?? 0);
    $issued_by = trim($b['issued_by'] ?? $username);
    if (!$id) json_out(['error'=>'id required'], 400);
    try {
        $pdo->beginTransaction();
        $rq = $pdo->prepare("SELECT * FROM supply_requests WHERE id=:id FOR UPDATE");
        $rq->execute(['id'=>$id]);
        $req = $rq->fetch(PDO::FETCH_ASSOC);
        if (!$req) { $pdo->rollBack(); json_out(['error'=>'request not found'], 404); }
        if ($req['status']==='issued') { $pdo->rollBack(); json_out(['error'=>'already issued'], 400); }

        $it = $pdo->prepare("SELECT id, stock_quantity FROM items WHERE id=:id FOR UPDATE");
        $it->execute(['id'=>$req['item_id']]);
        $item = $it->fetch(PDO::FETCH_ASSOC);
        if (!$item) { $pdo->rollBack(); json_out(['error'=>'item not found'], 404); }
        if ((int)$item['stock_quantity'] < (int)$req['quantity']) { $pdo->rollBack(); json_out(['error'=>'insufficient stock'], 400); }

        $pdo->prepare("UPDATE items SET stock_quantity = stock_quantity - :q, updated_at = NOW() WHERE id=:id")
            ->execute(['q'=>$req['quantity'],'id'=>$req['item_id']]);

        $pdo->prepare("UPDATE supply_requests SET status='issued', updated_at=NOW() WHERE id=:id")
            ->execute(['id'=>$id]);

        $pdo->prepare("INSERT INTO item_usage (item_id, quantity, used_for_trip, used_by) VALUES (:it,:q,:trip,:by)")
            ->execute(['it'=>$req['item_id'],'q'=>$req['quantity'],'trip'=>$req['trip_id'] ?: null,'by'=>$issued_by]);

        $pdo->commit();
        json_out(['success'=>true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error'=>$e->getMessage()], 500);
    }
}

// Fleet auto-sync: deduct stock when Fleet logs usage
if ($action === 'fleet_usage_sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $item_name = trim($b['item_name'] ?? '');
    $quantity = (int)($b['quantity'] ?? 0);
    $vehicle_id = isset($b['vehicle_id']) ? (int)$b['vehicle_id'] : null;
    $used_by = trim($b['used_by'] ?? 'fleetstaff');
    if ($item_name === '' || $quantity <= 0) json_out(['error'=>'item_name and positive quantity required'], 400);
    try {
        $st = $pdo->prepare("SELECT id, stock_quantity FROM items WHERE LOWER(name) LIKE :n LIMIT 1");
        $st->execute(['n'=>'%'.strtolower($item_name).'%']);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if (!$item) json_out(['error'=>'item not found'], 404);
        if ((int)$item['stock_quantity'] < $quantity) json_out(['error'=>'insufficient stock'], 400);
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE items SET stock_quantity = stock_quantity - :q, updated_at=NOW() WHERE id=:id")
            ->execute(['q'=>$quantity,'id'=>$item['id']]);
        $pdo->prepare("INSERT INTO item_usage (item_id, quantity, used_for_trip, used_by) VALUES (:it,:q,NULL,:by)")
            ->execute(['it'=>$item['id'],'q'=>$quantity,'by'=>$used_by]);
        $pdo->commit();
        json_out(['success'=>true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error'=>$e->getMessage()], 500);
    }
}

// ---------- fetch trips (for next 7/30 days etc.) ----------
if ($action === 'fetchTrips') {
    // optional range param: e.g., 7d, 30d; defaults to 7d
    $range = $_GET['range'] ?? '7d';
    $days = 7;
    if (preg_match('/^(\d+)\s*d$/', $range, $m)) $days = max(1, (int)$m[1]);
    try {
        $st = $pdo->prepare("
            SELECT id, trip_code, passenger_name, scheduled_time
            FROM trips
            WHERE scheduled_time >= NOW() AND scheduled_time <= DATE_ADD(NOW(), INTERVAL :d DAY)
            ORDER BY scheduled_time ASC
            LIMIT 500
        ");
        $st->execute(['d'=>$days]);
        $trips = $st->fetchAll(PDO::FETCH_ASSOC);
        json_out(['trips'=>$trips]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()], 500); }
}

// ---------- pulse: lightweight “version” heartbeat for real-time updates ----------
if ($action === 'pulse') {
    try {
        $versions = [
            'items'     => (int)$pdo->query("SELECT UNIX_TIMESTAMP(GREATEST(IFNULL(MAX(updated_at), '1970-01-01'), IFNULL(MAX(created_at),'1970-01-01'))) FROM items")->fetchColumn(),
            'requests'  => (int)$pdo->query("SELECT UNIX_TIMESTAMP(GREATEST(IFNULL(MAX(updated_at), '1970-01-01'), IFNULL(MAX(created_at),'1970-01-01'))) FROM supply_requests")->fetchColumn(),
            'suppliers' => (int)$pdo->query("SELECT UNIX_TIMESTAMP(IFNULL(MAX(created_at),'1970-01-01')) FROM suppliers")->fetchColumn(),
            'usage'     => (int)$pdo->query("SELECT UNIX_TIMESTAMP(IFNULL(MAX(created_at),'1970-01-01')) FROM item_usage")->fetchColumn(),
        ];
        json_out(['versions'=>$versions, 'server_time'=>time()]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()], 500); }
}
// ---------- manual item stock adjustment ----------
if ($action === 'item_adjust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $item_id = (int)($b['item_id'] ?? 0);
    $delta   = (int)($b['delta'] ?? 0);
    $note    = trim($b['note'] ?? '');
    $user    = $username ?? 'system';

    if ($item_id <= 0 || $delta === 0) {
        json_out(['error' => 'item_id and non-zero delta required'], 400);
    }

    try {
        $pdo->beginTransaction();

        // Lock item row
        $st = $pdo->prepare("SELECT id, stock_quantity FROM items WHERE id=:id FOR UPDATE");
        $st->execute(['id'=>$item_id]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            $pdo->rollBack();
            json_out(['error'=>'item not found'], 404);
        }

        // Update stock
        $pdo->prepare("UPDATE items SET stock_quantity = stock_quantity + :d, updated_at = NOW() WHERE id=:id")
            ->execute(['d'=>$delta,'id'=>$item_id]);

        // Log adjustment in usage (or in its own table if you created supply_adjustments)
        $pdo->prepare("INSERT INTO item_usage (item_id, quantity, used_for_trip, used_by) VALUES (:it,:q,NULL,:by)")
            ->execute(['it'=>$item_id,'q'=>$delta,'by'=>"adjust: ".$user]);

        $pdo->commit();
        json_out(['success'=>true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error'=>$e->getMessage()], 500);
    }
}

// Unknown action handler for API
if ($action) { json_out(['error'=>'Unknown action'], 404); }

// ========== FRONTEND UI (single-file) ==========
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>ViaHale — Store Room Clerk</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
<style>
:root{
  --vh-primary: #6532C9;
  --vh-dark: #4311A5;
  --vh-accent: #9A66FF;
  --bg: #05060b;
  --card: #0f1120;
  --muted: #9aa3bd;
  --accent: var(--vh-primary);
  color-scheme: dark;
  font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial;
}
*{box-sizing:border-box}
body{margin:0;background:linear-gradient(180deg,#07080c 0%, #0b0e18 100%);color:#e6eefc}
.topbar{height:64px;background:linear-gradient(90deg,var(--vh-primary),var(--vh-accent));display:flex;align-items:center;justify-content:space-between;padding:0 18px}
.container{
  padding:32px 32px 32px 0;
  display:grid;
  grid-template-columns:repeat(12,1fr);
  gap:32px;
  min-height:calc(100vh - 64px);
}
.card{
  background:var(--card);
  border-radius:16px;
  padding:32px 28px;
  border:1px solid rgba(255,255,255,0.04);
  box-shadow:0 2px 16px 0 rgba(0,0,0,0.10);
  margin-bottom:0;
}
.span-12{grid-column:span 12}
.span-8{grid-column:span 8}
.span-4{grid-column:span 4}
.h2{margin:0 0 8px 0;font-size:20px;font-weight:600;letter-spacing:0.01em}
.small{font-size:13px;color:#9aa3bd;margin-bottom:18px}
.table{width:100%;border-collapse:collapse;font-size:14px}
.table th, .table td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,0.03);text-align:left}
.input, select, textarea{width:100%;padding:10px;border-radius:8px;background:#0b1226;border:1px solid rgba(255,255,255,0.03);color:#e6eefc}
.btn{background:var(--vh-primary);border:none;color:white;padding:10px 16px;border-radius:10px;cursor:pointer;font-weight:500}
.btn.ghost{background:transparent;border:1px solid rgba(255,255,255,0.04);color:#e6eefc}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,0.03);font-size:12px}
.scroll{max-height:340px;overflow:auto}
.tag-high{background:#ffebe6;color:#b02a1a;padding:4px 8px;border-radius:999px}
.tag-med{background:#fff5e6;color:#b36b00;padding:4px 8px;border-radius:999px}
.tag-low{background:rgba(255,255,255,0.03);color:#9aa3bd;padding:4px 8px;border-radius:999px}
.alert{background:#3b0b0b;padding:8px;border-radius:8px;color:#ffd6d6;margin-bottom:8px}
.right{text-align:right}
.vih-logout-btn{background-color:#6532C9;color:white;border:none;padding:8px 18px;font-size:15px;font-family:'Poppins',sans-serif;border-radius:10px;cursor:pointer;transition:background .3s ease, transform .2s ease}
.vih-logout-btn:hover{background-color:#4311A5;transform:scale(1.05)}
.sidebar {
  width: 220px;
  background: #0f1120;
  color: #e6eefc;
  position: fixed;
  top: 0; left: 0; bottom: 0;
  padding: 32px 0;
  z-index: 100;
  box-shadow:2px 0 16px 0 rgba(0,0,0,0.10);
}
.sidebar ul { list-style: none; padding: 0; }
.sidebar li { margin-bottom: 22px; }
.sidebar a {
  color: #e6eefc;
  text-decoration: none;
  padding: 12px 28px;
  display: block;
  border-radius: 10px;
  font-size:16px;
  font-weight:500;
  transition: background 0.2s;
}
.sidebar a:hover, .sidebar a.active {
  background: #4311A5;
}
body { margin-left: 220px; }
@media (max-width:900px){
  .container{grid-template-columns:1fr;gap:18px;padding:18px 0 18px 0;}
  .sidebar{position:static;width:100%;height:auto;box-shadow:none;}
  body{margin-left:0;}
}
</style>
</head>
<body>
<!-- Sidebar Navigation -->
<nav class="sidebar">
  <ul>
    <li><a href="#" data-section="overview" class="active">Overview</a></li>
    <li><a href="#" data-section="inventory">Inventory</a></li>
    <li><a href="#" data-section="requests">Requests</a></li>
    <li><a href="#" data-section="suppliers">Suppliers</a></li>
    <li><a href="#" data-section="usage">Usage Trends</a></li>
    <li><a href="/core1/logout.php">Logout</a></li>
  </ul>
</nav>

<div class="topbar">
  <div style="display:flex;gap:12px;align-items:center">
    <img src="../logo.png" alt="logo" style="height:36px;">
    <div>
      <div class="h2">ViaHale — Store Room Clerk</div>
      <div class="small">Signed in as <strong><?php echo htmlspecialchars($username); ?></strong></div>
    </div>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button class="btn" onclick="refreshAll()">Refresh</button>
    <a class="vih-logout-btn" href="/core1/logout.php" onclick="return confirm('Are you sure you want to log out?')">Logout</a>
  </div>
</div>

<div class="container">
  <!-- Overview Module -->
  <section id="section-overview" class="card span-12 module-section">
    <div style="display:flex;flex-direction:column;gap:18px;">
      <div>
        <div class="h2" style="color:var(--vh-primary);font-size:24px;">Overview</div>
        <div class="small">Quick glance at low stock, pending requests, and top used items.</div>
      </div>
      <div id="overviewBlocks" style="display:flex;gap:32px;flex-wrap:wrap"></div>
    </div>
  </section>

  <!-- Inventory Module -->
  <section id="section-inventory" class="card span-8 module-section" style="display:none">
    <div style="display:flex;flex-direction:column;gap:18px;">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <div class="h2" style="color:var(--vh-accent);">Inventory</div>
          <div class="small">Manage items and stock levels.</div>
        </div>
        <div><button class="btn ghost" onclick="showCreateItem()">+ New Item</button></div>
      </div>
      <div id="lowStockAlerts"></div>
      <div class="scroll" style="margin-top:10px">
        <table class="table" id="itemsTable">
          <thead><tr><th>Item</th><th>Stock</th><th>Reorder</th><th>Unit</th><th>Supplier</th><th class="right">Actions</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div style="margin-top:18px">
        <div class="h2" style="color:var(--vh-accent);">Reorder List</div>
        <div class="small">Items below reorder level</div>
        <div class="scroll" style="margin-top:8px">
          <table class="table" id="reorderTable"><thead><tr><th>Item</th><th>Stock</th><th>Reorder</th><th>Supplier</th><th class="right">Reorder</th></tr></thead><tbody></tbody></table>
        </div>
      </div>
    </div>
  </section>

  <!-- Requests Module -->
  <section id="section-requests" class="card span-4 module-section" style="display:none">
    <div style="display:flex;flex-direction:column;gap:18px;">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <div class="h2" style="color:var(--vh-dark);">Requests</div>
          <div class="small">Pending & prioritized supply requests.</div>
        </div>
        <div><button class="btn" onclick="showCreateRequest()">+ Request</button></div>
      </div>
      <div class="scroll" style="margin-top:8px">
        <table class="table" id="requestsTable"><thead><tr><th>When</th><th>Item</th><th>Qty</th><th>Priority</th><th>Status</th><th class="right">Action</th></tr></thead><tbody></tbody></table>
      </div>
    </div>
  </section>

  <!-- Suppliers Module -->
  <section id="section-suppliers" class="card span-4 module-section" style="display:none">
    <div style="display:flex;flex-direction:column;gap:18px;">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <div class="h2" style="color:var(--vh-primary);">Suppliers</div>
          <div class="small">Add & view suppliers.</div>
        </div>
        <div><button class="btn ghost" onclick="showCreateSupplier()">+ Supplier</button></div>
      </div>
      <div class="scroll" style="margin-top:8px">
        <table class="table" id="suppliersTable"><thead><tr><th>Name</th><th class="right">Contact</th></tr></thead><tbody></tbody></table>
      </div>
    </div>
  </section>

  <!-- Usage Trends Module -->
  <section id="section-usage" class="card span-12 module-section" style="display:none">
    <div style="display:flex;flex-direction:column;gap:18px;">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <div class="h2" style="color:var(--vh-accent);">Usage Trend</div>
          <div class="small">Top used items by month (last 6 months).</div>
        </div>
        <div><select id="trendMonths" class="input" style="width:120px"><option value="6">6 months</option><option value="3">3 months</option><option value="12">12 months</option></select></div>
      </div>
      <div class="scroll" style="margin-top:10px">
        <table class="table" id="trendTable"><thead><tr><th>Month</th><th>Item</th><th class="right">Used</th></tr></thead><tbody></tbody></table>
      </div>
    </div>
  </section>
</div>

<!-- Modals -->
<div id="modalBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;z-index:999">
  <div id="modal" style="background:#0f1220;border-radius:10px;padding:14px;width:640px;max-width:96%;color:#e6eefc">
    <div id="modalTitle" style="font-weight:700;margin-bottom:8px"></div>
    <div id="modalBody"></div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
      <button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn" id="modalSaveBtn">Save</button>
    </div>
  </div>
</div>

<script>
// Sidebar navigation logic
document.querySelectorAll('.sidebar a[data-section]').forEach(link => {
  link.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelectorAll('.sidebar a').forEach(a => a.classList.remove('active'));
    this.classList.add('active');
    document.querySelectorAll('.module-section').forEach(sec => sec.style.display = 'none');
    const secId = 'section-' + this.getAttribute('data-section');
    const sec = document.getElementById(secId);
    if (sec) sec.style.display = '';
  });
});

// Figure out our API endpoint (this same file)
const API = window.location.pathname.substring(window.location.pathname.lastIndexOf('/')+1) || 'storeroom_dashboard.php';

async function api(action, opts={}) {
  const url = `${API}?action=${encodeURIComponent(action)}`;
  const fetchOpts = { method: opts.body ? 'POST' : 'GET', headers: {} };
  if (opts.body) { fetchOpts.body = JSON.stringify(opts.body); fetchOpts.headers['Content-Type'] = 'application/json'; }
  const res = await fetch(url, fetchOpts);
  return res.json();
}

function el(tag, attrs={}, ...children) {
  const e = document.createElement(tag);
  for (const k in attrs) {
    if (k === 'html') e.innerHTML = attrs[k];
    else if (k === 'onclick') e.addEventListener('click', attrs[k]);
    else e.setAttribute(k, attrs[k]);
  }
  for (const c of children) if (c!==null && c!==undefined) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
  return e;
}

function actionBtn(text, handler, className='btn') {
  const btn = document.createElement('button');
  btn.className = className;
  btn.textContent = text;
  btn.type = 'button';
  btn.addEventListener('click', handler);
  return btn;
}

// ======= REAL-TIME PULSE =======
let lastVersions = {items:0, requests:0, suppliers:0, usage:0};
let pulseTimer = null;
async function pulse() {
  try {
    const r = await api('pulse');
    if (!r || !r.versions) return;
    const v = r.versions;
    // compare and refresh only what changed
    if (v.items !== lastVersions.items) { loadItems(); refreshOverview(); }
    if (v.requests !== lastVersions.requests) { loadRequests(); refreshOverview(); }
    if (v.suppliers !== lastVersions.suppliers) { loadSuppliers(); }
    if (v.usage !== lastVersions.usage) { loadTrend(); refreshOverview(); }
    lastVersions = v;
  } catch (e) { /* ignore transient */ }
}

function startPulse(intervalMs=4000) {
  if (pulseTimer) clearInterval(pulseTimer);
  pulseTimer = setInterval(pulse, intervalMs);
}

// ======= Overview =======
async function refreshOverview() {
  const o = await api('overview');
  const container = document.getElementById('overviewBlocks');
  container.innerHTML = '';
  if (o.error) { container.appendChild(el('div',{},'Error')); return; }
  const cards = [
    {label:'Low stock', value:o.low_stock_count||0},
    {label:'Pending requests', value:o.pending_requests||0},
    {label:'Top used (30d)', value:(o.top_used||[]).map(x=>`${x.name} (${x.used})`).join(', ')}
  ];
  cards.forEach(c=>{
    const card = el('div',{style:'background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));padding:10px;border-radius:8px;min-width:220px'},
      el('div',{style:'font-size:20px;font-weight:700'},String(c.value)),
      el('div',{class:'small'},c.label)
    );
    container.appendChild(card);
  });
}

// ======= Inventory =======
async function loadItems() {
  const r = await api('items');
  const tb = document.querySelector('#itemsTable tbody');
  tb.innerHTML = '';
  document.getElementById('reorderTable').querySelector('tbody').innerHTML = '';
  document.getElementById('lowStockAlerts').innerHTML = '';
  if (r.error) { tb.appendChild(el('tr',{}, el('td',{colspan:6},'Error: '+r.error))); return; }
  (r.items||[]).forEach(it=>{
    const row = el('tr', {},
      el('td',{}, it.name),
      el('td',{}, String(it.stock_quantity)),
      el('td',{}, String(it.reorder_level)),
      el('td',{}, it.unit||''),
      el('td',{}, it.supplier_name||'—'),
      (()=>{
        const td = el('td',{'class':'right'});
        td.appendChild(el('button',{class:'btn ghost', onclick:()=>showCreateRequestWithItem(it.id)}, 'Request'));
        td.appendChild(el('button',{class:'btn ghost', style:'margin-left:6px', onclick:()=>showAdjustStock(it.id)}, 'Adjust'));
        return td;
      })()
    );
    tb.appendChild(row);

    if (it.stock_quantity < it.reorder_level) {
      const rrow = el('tr', {},
        el('td',{}, it.name),
        el('td',{}, String(it.stock_quantity)),
        el('td',{}, String(it.reorder_level)),
        el('td',{}, it.supplier_name||'—'),
        (()=>{
          const td = el('td',{'class':'right'});
          td.appendChild(el('button',{class:'btn', onclick:()=>prepReorder(it.id)}, 'Prepare Reorder'));
          return td;
        })()
      );
      document.querySelector('#reorderTable tbody').appendChild(rrow);
      document.getElementById('lowStockAlerts').appendChild(el('div',{class:'alert'}, `${it.name} low: ${it.stock_quantity} < ${it.reorder_level}`));
    }
  });
}

// ======= Suppliers =======
async function loadSuppliers() {
  const r = await api('suppliers');
  const tb = document.querySelector('#suppliersTable tbody');
  tb.innerHTML = '';
  if (r.error) { tb.appendChild(el('tr',{}, el('td',{colspan:2},r.error))); return; }
  (r.suppliers||[]).forEach(s=>{
    tb.appendChild(el('tr',{},
      el('td',{}, s.name),
      el('td',{'class':'right'}, [s.contact_person || '', s.phone ? ' • ' + s.phone : '', s.email ? ' • ' + s.email : ''].join(''))
    ));
  });
}

// ======= Requests =======
async function loadRequests() {
  const r = await api('requests');
  const tb = document.querySelector('#requestsTable tbody');
  tb.innerHTML = '';
  if (r.error) { tb.appendChild(el('tr',{}, el('td',{colspan:6},r.error))); return; }
  (r.requests||[]).forEach(rr=>{
    const prioClass = rr.priority === 'high' ? 'tag-high' : (rr.priority === 'medium' ? 'tag-med' : 'tag-low');
    const actionsCell = document.createElement('td');
    actionsCell.className = 'right';
    if (rr.status === 'pending') {
      actionsCell.appendChild(actionBtn('Approve', ()=>approveRequest(rr.id)));
      actionsCell.appendChild(actionBtn('Issue', ()=>issueRequest(rr.id), 'btn ghost'));
      actionsCell.appendChild(actionBtn('Reject', ()=>rejectRequest(rr.id), 'btn ghost'));
    } else if (rr.status === 'approved') {
      actionsCell.appendChild(actionBtn('Issue', ()=>issueRequest(rr.id)));
      actionsCell.appendChild(actionBtn('Reject', ()=>rejectRequest(rr.id), 'btn ghost'));
    } else if (rr.status === 'issued') {
      actionsCell.textContent = 'Issued';
    } else if (rr.status === 'rejected') {
      actionsCell.textContent = 'Rejected';
    } else {
      actionsCell.textContent = rr.status;
    }
    const row = el('tr', {},
      el('td',{}, new Date(rr.created_at).toLocaleString()),
      el('td',{}, rr.item_name || '—'),
      el('td',{}, String(rr.quantity)),
      el('td',{}, el('span',{class:prioClass}, rr.priority)),
      el('td',{}, rr.status),
      actionsCell
    );
    tb.appendChild(row);
  });
}

async function approveRequest(id) {
  const res = await api('request_update_status', {body:{id, status:'approved'}});
  if (res.error) alert(res.error);
}
async function rejectRequest(id) {
  if (!confirm('Are you sure you want to reject this request?')) return;
  const res = await api('request_update_status', {body:{id, status:'rejected'}});
  if (res.error) alert(res.error);
}
async function issueRequest(id) {
  if (!confirm('Issue this request and deduct stock?')) return;
  const res = await api('request_issue', {body:{id}});
  if (res.error) alert(res.error);
}

// ======= Usage Trend =======
document.getElementById('trendMonths').addEventListener('change', loadTrend);
async function loadTrend() {
  const months = parseInt(document.getElementById('trendMonths').value || '6', 10);
  const r = await api('trend', {body:{months}});
  const tb = document.getElementById('trendTable').querySelector('tbody');
  tb.innerHTML = '';
  if (r.error) {
    tb.appendChild(el('tr',{}, el('td',{colspan:3},'Error: '+r.error)));
    return;
  }
  (r.trend||[]).forEach(t=>{
    tb.appendChild(el('tr',{},
      el('td',{}, t.ym),
      el('td',{}, t.item_name),
      el('td',{'class':'right'}, t.used)
    ));
  });
}

// ======= Create / Adjust helpers =======
async function showCreateItem() {
  const res = await api('suppliers');
  const suppliers = res.suppliers || [];
  const supplierSelect = el('select', {id:'new_item_supplier', class:'input'});
  supplierSelect.appendChild(el('option', {value:''}, 'Select supplier'));
  suppliers.forEach(s => supplierSelect.appendChild(el('option', {value:s.id}, s.name)));

  showModal('Create New Item',
    el('div', {},
      el('label', {}, 'Item Name'),
      el('input', {id:'new_item_name', class:'input'}),
      el('label', {}, 'Description'),
      el('textarea', {id:'new_item_desc', class:'input'}),
      el('label', {}, 'Stock Quantity'),
      el('input', {id:'new_item_qty', class:'input', type:'number', value:0}),
      el('label', {}, 'Reorder Level'),
      el('input', {id:'new_item_reorder', class:'input', type:'number', value:5}),
      el('label', {}, 'Unit'),
      el('input', {id:'new_item_unit', class:'input'}),
      el('label', {}, 'Unit Cost'),
      el('input', {id:'new_item_cost', class:'input', type:'number', step:'0.01'}),
      el('label', {}, 'Supplier'),
      supplierSelect
    ),
    'Create',
    async () => {
      const body = {
        name: document.getElementById('new_item_name').value,
        description: document.getElementById('new_item_desc').value,
        stock_quantity: parseInt(document.getElementById('new_item_qty').value||'0',10),
        reorder_level: parseInt(document.getElementById('new_item_reorder').value||'5',10),
        unit: document.getElementById('new_item_unit').value,
        unit_cost: parseFloat(document.getElementById('new_item_cost').value||'0'),
        supplier_id: document.getElementById('new_item_supplier').value || null
      };
      const res = await api('item_create', {body});
      if (res.error) alert(res.error); else { closeModal(); }
    }
  );
}

function showCreateRequest() { showCreateRequestWithItem(); }

async function showCreateRequestWithItem(itemId=null) {
  const itemsR = await api('items');
  const items = itemsR.items || [];
  const trips = await fetch(`${API}?action=fetchTrips&range=7d`).then(r=>r.json()).catch(()=>({trips:[]}));
  const form = el('div', {},
    el('label',{},'Item'),
    (()=>{ const sel=el('select',{id:'cr_item', class:'input'}); sel.appendChild(el('option',{value:''}, 'Select item')); items.forEach(i=> sel.appendChild(el('option',{value:i.id, selected: itemId==i.id}, i.name))); return sel })(),
    el('label',{},'Quantity'), el('input',{id:'cr_qty', class:'input', type:'number', value:1}),
    el('label',{},'Trip (optional)'),
    (()=>{ const sel=el('select',{id:'cr_trip', class:'input'}); sel.appendChild(el('option',{value:''}, 'None')); (trips.trips||[]).forEach(t=> sel.appendChild(el('option',{value:t.id}, `${t.trip_code} • ${t.passenger_name||''}`))); return sel })(),
    el('label',{},'Note'), el('textarea',{id:'cr_note', class:'input', rows:2})
  );
  showModal('Create Request', form, 'Request', async ()=> {
    const body = {
      item_id: parseInt(document.getElementById('cr_item').value||'0',10),
      quantity: parseInt(document.getElementById('cr_qty').value||'0',10),
      requested_by: '<?php echo addslashes($username); ?>',
      trip_id: document.getElementById('cr_trip').value || null,
      note: document.getElementById('cr_note').value.trim()
    };
    const res = await api('request_create', {body});
    if (res.error) alert(res.error); else { closeModal(); }
  });
}

async function showAdjustStock(itemId) {
  const it = await api('items');
  const item = (it.items || []).find(x=>x.id==itemId) || null;
  if (!item) return alert('Item not found');
  const f = el('div', {},
    el('div', {style:'display:flex;gap:8px'}, el('input',{id:'adj_qty', class:'input', type:'number', placeholder:'Delta (use negative to reduce)'})),
    el('div', {style:'margin-top:8px'}, el('label',{}, 'Reason (optional)'), el('textarea',{id:'adj_note', class:'input', rows:2}))
  );
  showModal('Adjust stock for: ' + item.name, f, 'Apply', async ()=> {
    const delta = parseInt(document.getElementById('adj_qty').value||'0',10);
    if (!delta) { alert('Enter non-zero delta'); return; }
    try {
      const r = await fetch(API + '?action=item_adjust', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({item_id:itemId, delta:delta, note:document.getElementById('adj_note').value})});
      const jr = await r.json();
      if (jr.error) alert(jr.error); else { closeModal(); }
    } catch (e) { alert(e.message) }
  });
}

async function prepReorder(itemId) {
  const it = await api('items');
  const item = (it.items || []).find(x=>x.id==itemId);
  if (!item) return alert('Item not found');
  const html = el('div', {},
    el('div',{}, el('strong',{}, item.name)),
    el('div', {class:'small'}, 'Stock: ' + item.stock_quantity + ' • Reorder level: ' + item.reorder_level),
    el('div', {style:'margin-top:8px'}, 'Supplier: ' + (item.supplier_name || '—'))
  );
  showModal('Prepare Reorder', html, null, null);
}

// Global refresh (manual)
async function refreshAll() {
  await Promise.all([refreshOverview(), loadItems(), loadSuppliers(), loadRequests(), loadTrend()]);
}

// Modals
function closeModal() {
  document.getElementById('modalBackdrop').style.display = 'none';
}
function showModal(title, bodyElem, saveLabel, onSave) {
  document.getElementById('modalTitle').textContent = title;
  const modalBody = document.getElementById('modalBody');
  modalBody.innerHTML = '';
  if (bodyElem) modalBody.appendChild(bodyElem);
  document.getElementById('modalBackdrop').style.display = 'flex';
  const saveBtn = document.getElementById('modalSaveBtn');
  if (saveLabel) {
    saveBtn.style.display = '';
    saveBtn.textContent = saveLabel;
    saveBtn.onclick = async function() { if (onSave) await onSave(); };
  } else {
    saveBtn.style.display = 'none';
    saveBtn.onclick = null;
  }
}

// Boot
window.addEventListener('load', async ()=>{
  await refreshAll();
  await pulse();       // first pulse primes versions
  startPulse(4000);    // repeat every 4s
});
</script>
</body>
</html>

<?php
// ===========================================================
// Post-HTML helper endpoints (same-file)
// ===========================================================
if (isset($_GET['action']) && $_GET['action'] === 'item_adjust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $item_id = isset($b['item_id']) ? (int)$b['item_id'] : 0;
    $delta = isset($b['delta']) ? (int)$b['delta'] : 0;
    $note = trim($b['note'] ?? '');
    if (!$item_id || $delta === 0) { json_out(['error'=>'item_id and non-zero delta required'], 400); }
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT stock_quantity FROM items WHERE id=:id FOR UPDATE");
        $st->execute(['id'=>$item_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $pdo->rollBack(); json_out(['error'=>'item not found'], 404); }
        $new = max(0, (int)$row['stock_quantity'] + $delta);
        $pdo->prepare("UPDATE items SET stock_quantity=:n, updated_at=NOW() WHERE id=:id")->execute(['n'=>$new,'id'=>$item_id]);
        if ($delta < 0) {
            $pdo->prepare("INSERT INTO item_usage (item_id, quantity, used_for_trip, used_by) VALUES (:it,:q,NULL,:by)")
                ->execute(['it'=>$item_id,'q'=>abs($delta),'by'=>$username]);
        }
        $pdo->commit();
        json_out(['success'=>true,'new_stock'=>$new]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error'=>$e->getMessage()], 500);
    }
} else if (isset($_GET['action']) && $_GET['action'] === 'trend') {
    $b = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $months = isset($b['months']) ? (int)$b['months'] : 6;
    $months = max(1, min(24, $months));
    try {
        $rows = $pdo->query("
            SELECT DATE_FORMAT(u.created_at,'%Y-%m') AS ym, i.name AS item_name, SUM(u.quantity) AS used
            FROM item_usage u
            JOIN items i ON i.id = u.item_id
            WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL {$months} MONTH)
            GROUP BY ym, i.name
            ORDER BY ym DESC, used DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
        json_out(['trend'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()], 500); }
}
?>
