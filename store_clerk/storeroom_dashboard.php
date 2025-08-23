<?php
// storeroom_dashboard.php
// Single-file Store Room Clerk dashboard — inventory, suppliers, requests, usage trends
session_start();

// ========== CONFIG ==========
$username = $_SESSION['username'] ?? 'storeclerk';
$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_PORT = getenv('DB_PORT') ?: '3307';
$DB_NAME = getenv('DB_NAME') ?: 'otp_login';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: '';
$DSN = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";

// ViaHale branding
$V_PRIMARY = '#6532C9';
$V_DARK   = '#4311A5';
$V_ACCENT = '#9A66FF';

// ========== DB CONNECTION ==========
try {
    $pdo = new PDO($DSN, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo "DB connection failed: " . htmlspecialchars($e->getMessage());
    exit;
}

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

    // Items (inventory) - we create our own items table, even if 'supplies' exists in DB
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
function json_out($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// auto-prioritize a request based on trip context (if trip_id provided)
function auto_priority_for_request(PDO $pdo, $trip_id, $item_id, $quantity) {
    // default medium
    $priority = 'medium';
    if (!$trip_id) return $priority;

    try {
        $t = $pdo->prepare("SELECT priority AS trip_priority, status AS trip_status, scheduled_time FROM trips WHERE id=:id LIMIT 1");
        $t->execute(['id'=>$trip_id]);
        $trip = $t->fetch();
        if (!$trip) return $priority;

        // if trip urgent priority flag
        if (!empty($trip['trip_priority']) || (!empty($trip['priority']) && $trip['priority']==1)) $priority = 'high';
        // if trip ongoing -> high
        if (isset($trip['trip_status']) && $trip['trip_status']==='ongoing') $priority = 'high';

        // scheduled soon -> high
        if (!empty($trip['scheduled_time'])) {
            $sched = strtotime($trip['scheduled_time']);
            if ($sched !== false && $sched <= time() + (24*3600)) $priority = 'high';
            elseif ($sched <= time() + (72*3600) && $priority !== 'high') $priority = 'medium';
        }
        // You could also check item criticality (e.g., tires, fuel cards) -> make high automatically
        $critical_like = $pdo->prepare("SELECT name FROM items WHERE id=:id LIMIT 1");
        $critical_like->execute(['id'=>$item_id]);
        $iname = strtolower($critical_like->fetchColumn() ?: '');
        if ($iname !== '' && preg_match('/tire|brake|engine|fuel|battery|card|spare/', $iname)) $priority = 'high';
    } catch (Exception $e) {
        // keep medium
    }
    return $priority;
}

// ========== API ENDPOINTS ==========
$action = $_GET['action'] ?? null;
if ($action) header('Content-Type: application/json; charset=utf-8');

// ---------- overview ----------
if ($action === 'overview') {
    try {
        // low stock count
        $low = $pdo->query("SELECT COUNT(*) FROM items WHERE stock_quantity < reorder_level")->fetchColumn();
        // pending requests
        $pending = $pdo->query("SELECT COUNT(*) FROM supply_requests WHERE status='pending'")->fetchColumn();
        // top used last 30 days
        $top = $pdo->query("
            SELECT i.id, i.name, SUM(u.quantity) AS used
            FROM item_usage u
            JOIN items i ON i.id = u.item_id
            WHERE u.created_at >= NOW() - INTERVAL 30 DAY
            GROUP BY i.id, i.name
            ORDER BY used DESC
            LIMIT 5
        ")->fetchAll();
        json_out(['low_stock_count'=>(int)$low,'pending_requests'=>(int)$pending,'top_used'=>$top]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
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
        ")->fetchAll();
        json_out(['items'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// ---------- create/update item ----------
if ($action === 'item_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($b['name'] ?? '');
    $desc = trim($b['description'] ?? '');
    $qty = (int)($b['stock_quantity'] ?? 0);
    $reorder = (int)($b['reorder_level'] ?? 5);
    $unit = trim($b['unit'] ?? '');
    $cost = isset($b['unit_cost']) ? (float)$b['unit_cost'] : null;
    $supplier_id = isset($b['supplier_id']) && $b['supplier_id'] !== '' ? (int)$b['supplier_id'] : null;

    if ($name === '') json_out(['error'=>'name required']);
    try {
        $st = $pdo->prepare("INSERT INTO items (name, description, stock_quantity, reorder_level, unit, unit_cost, supplier_id, updated_at) VALUES (:n,:d,:q,:r,:u,:c,:s,NOW())");
        $st->execute(['n'=>$name,'d'=>$desc,'q'=>$qty,'r'=>$reorder,'u'=>$unit,'c'=>$cost,'s'=>$supplier_id]);
        json_out(['success'=>true,'id'=>$pdo->lastInsertId()]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// ---------- suppliers ----------
if ($action === 'suppliers') {
    try {
        $rows = $pdo->query("SELECT * FROM suppliers ORDER BY name ASC LIMIT 1000")->fetchAll();
        json_out(['suppliers'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

if ($action === 'supplier_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($b['name'] ?? '');
    if (!$name) json_out(['error'=>'name required']);
    $st = $pdo->prepare("INSERT INTO suppliers (name, contact_person, phone, email, address, category) VALUES (:n,:cp,:ph,:em,:ad,:cat)");
    try {
        $st->execute(['n'=>$name,'cp'=>$b['contact_person']??null,'ph'=>$b['phone']??null,'em'=>$b['email']??null,'ad'=>$b['address']??null,'cat'=>$b['category']??null]);
        json_out(['success'=>true,'id'=>$pdo->lastInsertId()]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// ---------- supply requests ----------
if ($action === 'requests') {
    try {
        $rows = $pdo->query("
            SELECT r.*, i.name AS item_name, i.stock_quantity, t.trip_code AS trip_code
            FROM supply_requests r
            LEFT JOIN items i ON i.id = r.item_id
            LEFT JOIN trips t ON t.id = r.trip_id
            ORDER BY FIELD(r.priority,'high','medium','low'), r.status ASC, r.created_at DESC
            LIMIT 1000
        ")->fetchAll();
        json_out(['requests'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// create request (auto-prioritize)
if ($action === 'request_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $item_id = (int)($b['item_id'] ?? 0);
    $quantity = (int)($b['quantity'] ?? 0);
    $requested_by = trim($b['requested_by'] ?? $username);
    $trip_id = isset($b['trip_id']) && $b['trip_id'] !== '' ? (int)$b['trip_id'] : null;
    $note = trim($b['note'] ?? '');
    if (!$item_id || $quantity <= 0) json_out(['error'=>'item_id and positive quantity required']);
    $priority = auto_priority_for_request($pdo, $trip_id, $item_id, $quantity);
    try {
        $st = $pdo->prepare("INSERT INTO supply_requests (item_id, requested_by, quantity, priority, status, trip_id, note) VALUES (:it,:rb,:q,:pr,'pending',:trip,:note)");
        $st->execute(['it'=>$item_id,'rb'=>$requested_by,'q'=>$quantity,'pr'=>$priority,'trip'=>$trip_id,'note'=>$note]);
        json_out(['success'=>true,'priority'=>$priority,'id'=>$pdo->lastInsertId()]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// approve / set request status (approve, reject)
if ($action === 'request_update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($b['id'] ?? 0);
    $status = $b['status'] ?? '';
    if (!$id || !in_array($status, ['pending','approved','issued','rejected'], true)) json_out(['error'=>'id and valid status required']);
    try {
        // If approving, just change status; issuing needs stock reduction handled by separate endpoint
        $pdo->prepare("UPDATE supply_requests SET status=:s, updated_at=NOW() WHERE id=:id")->execute(['s'=>$status,'id'=>$id]);
        json_out(['success'=>true]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// issue request: reduce stock, mark request issued, create usage record
if ($action === 'request_issue' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($b['id'] ?? 0);
    $issued_by = trim($b['issued_by'] ?? $username);
    if (!$id) json_out(['error'=>'id required']);
    try {
        $pdo->beginTransaction();
        $rq = $pdo->prepare("SELECT * FROM supply_requests WHERE id=:id FOR UPDATE");
        $rq->execute(['id'=>$id]);
        $req = $rq->fetch();
        if (!$req) { $pdo->rollBack(); json_out(['error'=>'request not found']); }
        if ($req['status']==='issued') { $pdo->rollBack(); json_out(['error'=>'already issued']); }

        // check stock
        $it = $pdo->prepare("SELECT id, stock_quantity FROM items WHERE id=:id FOR UPDATE");
        $it->execute(['id'=>$req['item_id']]);
        $item = $it->fetch();
        if (!$item) { $pdo->rollBack(); json_out(['error'=>'item not found']); }
        if ($item['stock_quantity'] < $req['quantity']) { $pdo->rollBack(); json_out(['error'=>'insufficient stock']); }

        // reduce stock
        $pdo->prepare("UPDATE items SET stock_quantity = stock_quantity - :q, updated_at = NOW() WHERE id=:id")
            ->execute(['q'=>$req['quantity'],'id'=>$req['item_id']]);

        // mark request issued
        $pdo->prepare("UPDATE supply_requests SET status='issued', updated_at=NOW() WHERE id=:id")->execute(['id'=>$id]);

        // log usage
        $pdo->prepare("INSERT INTO item_usage (item_id, quantity, used_for_trip, used_by) VALUES (:it,:q,:trip,:by)")
            ->execute(['it'=>$req['item_id'],'q'=>$req['quantity'],'trip'=>$req['trip_id'] ?: null,'by'=>$issued_by]);

        $pdo->commit();
        json_out(['success'=>true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error'=>$e->getMessage()]);
    }
}

// reorder list: items below reorder_level
if ($action === 'reorder_list') {
    try {
        $rows = $pdo->query("
            SELECT i.*, s.name AS supplier_name
            FROM items i
            LEFT JOIN suppliers s ON s.id = i.supplier_id
            WHERE i.stock_quantity < i.reorder_level
            ORDER BY (i.reorder_level - i.stock_quantity) DESC
            LIMIT 500
        ")->fetchAll();
        json_out(['reorder'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// usage trend: total usage grouped by month & item
if ($action === 'usage_trend') {
    $months = (int)($_GET['months'] ?? 6);
    try {
        $rows = $pdo->prepare("
            SELECT i.id AS item_id, i.name AS item_name, DATE_FORMAT(u.created_at, '%Y-%m') AS ym, SUM(u.quantity) AS used
            FROM item_usage u
            JOIN items i ON i.id = u.item_id
            WHERE u.created_at >= DATE_SUB(CURDATE(), INTERVAL :m MONTH)
            GROUP BY i.id, ym
            ORDER BY ym ASC, used DESC
        ");
        $rows->execute(['m'=>$months]);
        $data = $rows->fetchAll();
        json_out(['trend'=>$data]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// low stock alerts (list)
if ($action === 'low_stock_alerts') {
    try {
        $rows = $pdo->query("SELECT id, name, stock_quantity, reorder_level FROM items WHERE stock_quantity < reorder_level ORDER BY (reorder_level - stock_quantity) DESC")->fetchAll();
        json_out(['alerts'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// minimal drivers endpoint (to associate issuance with driver)
if ($action === 'drivers_min') {
    try {
        $rows = $pdo->query("SELECT id, name FROM drivers ORDER BY name ASC LIMIT 1000")->fetchAll();
        json_out(['drivers'=>$rows]);
    } catch (Exception $e) { json_out(['error'=>$e->getMessage()]); }
}

// Unknown action handler for API
if ($action) { json_out(['error'=>'Unknown action']); }

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
  --vh-primary: <?php echo $V_PRIMARY;?>;
  --vh-dark: <?php echo $V_DARK;?>;
  --bg: #05060b;
  --card: #0f1120;
  --muted: #9aa3bd;
  --accent: var(--vh-primary);
  color-scheme: dark;
  font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial;
}
*{box-sizing:border-box}
body{margin:0;background:linear-gradient(180deg,#07080c 0%, #0b0e18 100%);color:#e6eefc}
.topbar{height:64px;background:linear-gradient(90deg,var(--vh-primary),var(--vh-primary));display:flex;align-items:center;justify-content:space-between;padding:0 18px}
.container{padding:18px;display:grid;grid-template-columns:repeat(12,1fr);gap:14px}
.card{background:var(--card);border-radius:12px;padding:12px;border:1px solid rgba(255,255,255,0.04)}
.span-12{grid-column:span 12}.span-8{grid-column:span 8}.span-4{grid-column:span 4}
.h2{margin:0;font-size:16px}
.small{font-size:12px;color:#9aa3bd}
.table{width:100%;border-collapse:collapse;font-size:13px}
.table th, .table td{padding:8px;border-bottom:1px solid rgba(255,255,255,0.03);text-align:left}
.input, select, textarea{width:100%;padding:8px;border-radius:8px;background:#0b1226;border:1px solid rgba(255,255,255,0.03);color:#e6eefc}
.btn{background:var(--vh-primary);border:none;color:white;padding:8px 10px;border-radius:10px;cursor:pointer}
.btn.ghost{background:transparent;border:1px solid rgba(255,255,255,0.04);color:#e6eefc}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,0.03);font-size:12px}
.scroll{max-height:340px;overflow:auto}
.tag-high{background:#ffebe6;color:#b02a1a;padding:4px 8px;border-radius:999px}
.tag-med{background:#fff5e6;color:#b36b00;padding:4px 8px;border-radius:999px}
.tag-low{background:rgba(255,255,255,0.03);color:#9aa3bd;padding:4px 8px;border-radius:999px}
.alert{background:#3b0b0b;padding:8px;border-radius:8px;color:#ffd6d6}
.right{text-align:right}
.vih-logout-btn{background-color:#6532C9;color:white;border:none;padding:6px 14px;font-size:13px;font-family:'Poppins',sans-serif;border-radius:10px;cursor:pointer;transition:background .3s ease, transform .2s ease}
.vih-logout-btn:hover{background-color:#4311A5;transform:scale(1.05)}
</style>
</head>
<body>
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
  <!-- Overview -->
  <section class="card span-12">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div>
        <div class="h2">Overview</div>
        <div class="small">Quick glance — low stock, pending requests, top used</div>
      </div>
      <div id="overviewBlocks" style="display:flex;gap:10px"></div>
    </div>
  </section>

  <!-- Left column: Inventory & Reorder -->
  <section class="card span-8">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><div class="h2">Inventory</div><div class="small">Manage items and stock</div></div>
      <div><button class="btn ghost" onclick="showCreateItem()">+ New Item</button></div>
    </div>

    <div style="margin-top:10px">
      <div id="lowStockAlerts"></div>
      <div class="scroll" style="margin-top:10px">
        <table class="table" id="itemsTable">
          <thead><tr><th>Item</th><th>Stock</th><th>Reorder</th><th>Unit</th><th>Supplier</th><th class="right">Actions</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <div style="margin-top:12px">
      <div class="h2">Reorder List</div>
      <div class="small">Items below reorder level</div>
      <div class="scroll" style="margin-top:8px">
        <table class="table" id="reorderTable"><thead><tr><th>Item</th><th>Stock</th><th>Reorder</th><th>Supplier</th><th class="right">Reorder</th></tr></thead><tbody></tbody></table>
      </div>
    </div>
  </section>

  <!-- Right column: Suppliers & Requests -->
  <section class="card span-4">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><div class="h2">Suppliers</div><div class="small">Add & view suppliers</div></div>
      <div><button class="btn ghost" onclick="showCreateSupplier()">+ Supplier</button></div>
    </div>
    <div class="scroll" style="margin-top:8px">
      <table class="table" id="suppliersTable"><thead><tr><th>Name</th><th class="right">Contact</th></tr></thead><tbody></tbody></table>
    </div>

    <div style="margin-top:12px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div><div class="h2">Requests</div><div class="small">Pending & prioritized</div></div>
        <div><button class="btn" onclick="showCreateRequest()">+ Request</button></div>
      </div>
      <div class="scroll" style="margin-top:8px">
        <table class="table" id="requestsTable"><thead><tr><th>When</th><th>Item</th><th>Qty</th><th>Priority</th><th>Status</th><th class="right">Action</th></tr></thead><tbody></tbody></table>
      </div>
    </div>
  </section>

  <!-- Usage trends -->
  <section class="card span-12">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><div class="h2">Usage Trend (last 6 months)</div><div class="small">Top used items by month</div></div>
      <div><select id="trendMonths" class="input" style="width:120px"><option value="6">6 months</option><option value="3">3 months</option><option value="12">12 months</option></select></div>
    </div>
    <div style="margin-top:10px" class="scroll">
      <table class="table" id="trendTable"><thead><tr><th>Month</th><th>Item</th><th class="right">Used</th></tr></thead><tbody></tbody></table>
    </div>
  </section>
</div>

<!-- Modals (simple inline modals) -->
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
const API = 'storeroom_dashboard.php';
async function api(action, opts={}) {
  const url = `${API}?action=${encodeURIComponent(action)}`;
  const fetchOpts = { method: opts.body ? 'POST' : 'GET', headers: {} };
  if (opts.body) { fetchOpts.body = JSON.stringify(opts.body); fetchOpts.headers['Content-Type'] = 'application/json'; }
  const res = await fetch(url, fetchOpts);
  return res.json();
}

function el(tag, attrs={}, ...children) {
  const e = document.createElement(tag);
  for (const k in attrs) { if (k === 'html') e.innerHTML = attrs[k]; else e.setAttribute(k, attrs[k]); }
  for (const c of children) if (c!==null && c!==undefined) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
  return e;
}

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

async function loadItems() {
  const r = await api('items');
  const tb = document.querySelector('#itemsTable tbody');
  tb.innerHTML = '';
  document.getElementById('reorderTable').querySelector('tbody').innerHTML = '';
  document.getElementById('lowStockAlerts').innerHTML = '';
  if (r.error) { tb.appendChild(el('tr',{}, el('td',{colspan:6},'Error: '+r.error))); return; }
  r.items.forEach(it=>{
    const row = el('tr', {},
      el('td',{}, it.name),
      el('td',{}, String(it.stock_quantity)),
      el('td',{}, String(it.reorder_level)),
      el('td',{}, it.unit||''),
      el('td',{}, it.supplier_name||'—'),
      el('td',{'class':'right'},
        el('button',{class:'btn ghost', onclick:()=>showCreateRequestWithItem(it.id)}, 'Request'),
        el('button',{class:'btn ghost', style:'margin-left:6px', onclick:()=>showAdjustStock(it.id)}, 'Adjust')
      )
    );
    tb.appendChild(row);

    if (it.stock_quantity < it.reorder_level) {
      // reorder row
      const rrow = el('tr', {}, el('td',{}, it.name), el('td',{}, String(it.stock_quantity)), el('td',{}, String(it.reorder_level)), el('td',{}, it.supplier_name||'—'),
        el('td',{'class':'right'}, el('button',{class:'btn', onclick:()=>prepReorder(it.id)}, 'Prepare Reorder'))
      );
      document.querySelector('#reorderTable tbody').appendChild(rrow);
      // alert
      document.getElementById('lowStockAlerts').appendChild(el('div',{class:'alert'}, `${it.name} low: ${it.stock_quantity} < ${it.reorder_level}`));
    }
  });
}

async function loadSuppliers() {
  const r = await api('suppliers');
  const tb = document.querySelector('#suppliersTable tbody');
  tb.innerHTML = '';
  if (r.error) { tb.appendChild(el('tr',{}, el('td',{colspan:2},r.error))); return; }
  r.suppliers.forEach(s=>{
    tb.appendChild(el('tr',{}, el('td',{}, s.name), el('td',{'class':'right'}, [s.contact_person || '', s.phone ? ' • ' + s.phone : '', s.email ? ' • ' + s.email : ''].join(''))));
  });
}

async function loadRequests() {
  const r = await api('requests');
  const tb = document.querySelector('#requestsTable tbody');
  tb.innerHTML = '';
  if (r.error) { tb.appendChild(el('tr',{}, el('td',{colspan:6},r.error))); return; }
  r.requests.forEach(rr=>{
    const prioClass = rr.priority === 'high' ? 'tag-high' : (rr.priority === 'medium' ? 'tag-med' : 'tag-low');
    const actions = el('div', {});
    if (rr.status === 'pending') {
      actions.appendChild(el('button',{class:'btn', onclick:()=>approveRequest(rr.id)}, 'Approve'));
      actions.appendChild(el('button',{class:'btn ghost', style:'margin-left:6px', onclick:()=>issueRequest(rr.id)}, 'Issue'));
      actions.appendChild(el('button',{class:'btn ghost', style:'margin-left:6px', onclick:()=>rejectRequest(rr.id)}, 'Reject'));
    } else if (rr.status === 'approved') {
      actions.appendChild(el('button',{class:'btn', onclick:()=>issueRequest(rr.id)}, 'Issue'));
    } else {
      actions.appendChild(el('span',{}, rr.status));
    }
    const row = el('tr', {},
      el('td',{}, new Date(rr.created_at).toLocaleString()),
      el('td',{}, rr.item_name || '—'),
      el('td',{}, String(rr.quantity)),
      el('td',{}, el('span',{class:prioClass}, rr.priority)),
      el('td',{}, rr.status),
      el('td',{'class':'right'}, actions)
    );
    tb.appendChild(row);
  });
}

async function loadTrend() {
  const months = document.getElementById('trendMonths').value || '6';
  const r = await api('usage_trend&months=' + encodeURIComponent(months));
  const tb = document.querySelector('#trendTable tbody');
  tb.innerHTML = '';
  if (r.error) { tb.appendChild(el('tr',{}, el('td',{colspan:3}, r.error))); return; }
  (r.trend || []).forEach(row => {
    tb.appendChild(el('tr', {}, el('td', {}, row.ym), el('td', {}, row.item_name), el('td', {'class':'right'}, String(row.used))));
  });
}

// modal helpers
function showModal(title, bodyHtml, saveText='Save', saveHandler=null) {
  document.getElementById('modalTitle').innerText = title;
  const body = document.getElementById('modalBody');
  body.innerHTML = '';
  if (typeof bodyHtml === 'string') body.innerHTML = bodyHtml; else body.appendChild(bodyHtml);
  document.getElementById('modalSaveBtn').style.display = saveHandler ? 'inline-block' : 'none';
  if (saveHandler) {
    const btn = document.getElementById('modalSaveBtn');
    btn.onclick = saveHandler;
    btn.innerText = saveText;
  }
  document.getElementById('modalBackdrop').style.display = 'flex';
}
function closeModal(){ document.getElementById('modalBackdrop').style.display = 'none'; }

// create item modal
async function showCreateItem() {
  const suppliers = await api('suppliers');
  const form = el('div', {},
    el('label',{}, 'Name'), el('input', {id:'ci_name', class:'input'}),
    el('label',{}, 'Description'), el('textarea',{id:'ci_desc', class:'input', rows:3}),
    el('div', {style:'display:flex;gap:8px;margin-top:8px'},
      el('input',{id:'ci_qty', class:'input', placeholder:'Stock qty', type:'number', value:0}),
      el('input',{id:'ci_reorder', class:'input', placeholder:'Reorder level', type:'number', value:5})
    ),
    el('div', {style:'display:flex;gap:8px;margin-top:8px'},
      el('input',{id:'ci_unit', class:'input', placeholder:'Unit (pc/ltr)'}),
      el('input',{id:'ci_cost', class:'input', placeholder:'Unit cost', type:'number', step:'0.01'})
    ),
    el('label',{}, 'Supplier'),
    (() => {
      const sel = el('select',{id:'ci_supplier', class:'input'});
      sel.appendChild(el('option',{value:''}, 'None'));
      (suppliers.suppliers || []).forEach(s => sel.appendChild(el('option',{value:s.id}, s.name)));
      return sel;
    })()
  );
  showModal('Create Item', form, 'Create', async ()=> {
    const body = {
      name: document.getElementById('ci_name').value.trim(),
      description: document.getElementById('ci_desc').value.trim(),
      stock_quantity: parseInt(document.getElementById('ci_qty').value||'0',10),
      reorder_level: parseInt(document.getElementById('ci_reorder').value||'5',10),
      unit: document.getElementById('ci_unit').value.trim(),
      unit_cost: parseFloat(document.getElementById('ci_cost').value||'0')||null,
      supplier_id: document.getElementById('ci_supplier').value || null
    };
    const res = await api('item_create', {body});
    if (res.error) alert(res.error); else { closeModal(); loadItems(); }
  });
}

// create supplier modal
function showCreateSupplier() {
  const form = el('div', {},
    el('label',{},'Name'), el('input',{id:'cs_name', class:'input'}),
    el('label',{},'Contact person'), el('input',{id:'cs_contact', class:'input'}),
    el('label',{},'Phone'), el('input',{id:'cs_phone', class:'input'}),
    el('label',{},'Email'), el('input',{id:'cs_email', class:'input'}),
    el('label',{},'Address'), el('textarea',{id:'cs_addr', class:'input', rows:2})
  );
  showModal('Create Supplier', form, 'Create', async ()=> {
    const body = {
      name: document.getElementById('cs_name').value.trim(),
      contact_person: document.getElementById('cs_contact').value.trim(),
      phone: document.getElementById('cs_phone').value.trim(),
      email: document.getElementById('cs_email').value.trim(),
      address: document.getElementById('cs_addr').value.trim()
    };
    const res = await api('supplier_create', {body});
    if (res.error) alert(res.error); else { closeModal(); loadSuppliers(); }
  });
}

// create request modal
async function showCreateRequest() { showCreateRequestWithItem(null); }
async function showCreateRequestWithItem(itemId=null) {
  const itemsR = await api('items');
  const items = itemsR.items || [];
  const trips = await fetch('?action=fetchTrips&range=7d').then(r=>r.json()).catch(()=>({trips:[]}));
  const form = el('div', {},
    el('label',{},'Item'), (()=>{ const sel=el('select',{id:'cr_item', class:'input'}); sel.appendChild(el('option',{value:''}, 'Select item')); items.forEach(i=> sel.appendChild(el('option',{value:i.id, selected: itemId==i.id}, i.name))); return sel })(),
    el('label',{},'Quantity'), el('input',{id:'cr_qty', class:'input', type:'number', value:1}),
    el('label',{},'Trip (optional)'), (()=>{ const sel=el('select',{id:'cr_trip', class:'input'}); sel.appendChild(el('option',{value:''}, 'None')); (trips.trips||[]).forEach(t=> sel.appendChild(el('option',{value:t.id}, `${t.trip_code} • ${t.passenger_name||''}`))); return sel })(),
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
    if (res.error) alert(res.error); else { closeModal(); loadRequests(); loadItems(); }
  });
}

// approve request
async function approveRequest(id) {
  const res = await api('request_update_status', {body:{id, status:'approved'}});
  if (res.error) alert(res.error); else loadRequests();
}

// reject request
async function rejectRequest(id) {
  const res = await api('request_update_status', {body:{id, status:'rejected'}});
  if (res.error) alert(res.error); else loadRequests();
}

// issue request (calls endpoint that decrements stock, logs usage)
async function issueRequest(id) {
  if (!confirm('Issue this request and deduct stock?')) return;
  const res = await api('request_issue', {body:{id}});
  if (res.error) alert(res.error); else { loadRequests(); loadItems(); loadTrend(); }
}

// adjust stock
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
      const resp = await fetch(API + '?action=items', {method:'GET'}); // just to keep consistent
      // direct update SQL here via API? we don't have item_update endpoint - we'll use a lightweight inline call:
      const r = await fetch(API + '?action=item_adjust', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({item_id:itemId, delta:delta, note:document.getElementById('adj_note').value})});
      const jr = await r.json();
      if (jr.error) alert(jr.error); else { closeModal(); loadItems(); }
    } catch (e) { alert(e.message) }
  });
}

// prepare reorder (open supplier contact)
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

// ========== small helper: item_adjust endpoint (inline via fetch) ==========
// We'll call server-side endpoint ?action=item_adjust which we'll implement below by reloading the page and handling it.
// For now, note that the client calls it; server-side handler is appended at file bottom.

// refresh all
async function refreshAll() {
  refreshOverview();
  loadItems();
  loadSuppliers();
  loadRequests();
  loadTrend();
}
document.getElementById('trendMonths').addEventListener('change', loadTrend);
window.onload = refreshAll;
</script>

</body>
</html>

<?php
// ===========================================================
// Server-side small helper endpoints placed after HTML so client can call them in same file
// (these run when request has action param — already handled above, but we add item_adjust here)
// ===========================================================
if (isset($_GET['action']) && $_GET['action'] === 'item_adjust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $item_id = isset($b['item_id']) ? (int)$b['item_id'] : 0;
    $delta = isset($b['delta']) ? (int)$b['delta'] : 0;
    $note = trim($b['note'] ?? '');
    if (!$item_id || $delta === 0) { json_out(['error'=>'item_id and non-zero delta required']); }
    try {
        $pdo->beginTransaction();
        // lock row
        $st = $pdo->prepare("SELECT stock_quantity FROM items WHERE id=:id FOR UPDATE");
        $st->execute(['id'=>$item_id]);
        $row = $st->fetch();
        if (!$row) { $pdo->rollBack(); json_out(['error'=>'item not found']); }
        $new = max(0, $row['stock_quantity'] + $delta);
        $pdo->prepare("UPDATE items SET stock_quantity=:n, updated_at=NOW() WHERE id=:id")->execute(['n'=>$new,'id'=>$item_id]);
        if ($delta < 0) {
            // log usage entry
            $pdo->prepare("INSERT INTO item_usage (item_id, quantity, used_for_trip, used_by) VALUES (:it,:q,NULL,:by)")
                ->execute(['it'=>$item_id,'q'=>abs($delta),'by'=>$username]);
        }
        $pdo->commit();
        json_out(['success'=>true,'new_stock'=>$new]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['error'=>$e->getMessage()]);
    }
}
?>
