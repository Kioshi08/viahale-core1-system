<?php
// store_clerk/issue_supplies.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['storeclerk']);
include __DIR__ . '/../includes/storeclerk_navbar.php';

$error=''; $success='';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue'])) {
    $supply_id = intval($_POST['supply_id']);
    $issued_to = trim($_POST['issued_to']);
    $qty = intval($_POST['quantity']);
    $issued_by = $_SESSION['username'];

    // check stock
    $chk = $conn->prepare("SELECT quantity FROM supplies WHERE supply_id=?");
    $chk->bind_param("i",$supply_id); $chk->execute(); $res = $chk->get_result();
    if (!$res->num_rows) { $error = "Item not found."; }
    else {
        $row = $res->fetch_assoc();
        $available = intval($row['quantity']);
        if ($available < $qty) {
            $error = "Not enough stock. Available: $available";
        } else {
            // insert issued_items
            $ins = $conn->prepare("INSERT INTO issued_items (supply_id, issued_to, quantity_issued, issued_by) VALUES (?,?,?,?)");
            $ins->bind_param("isis", $supply_id, $issued_to, $qty, $issued_by);
            if ($ins->execute()) {
                // update supplies qty
                $upd = $conn->prepare("UPDATE supplies SET quantity = quantity - ? WHERE supply_id = ?");
                $upd->bind_param("ii", $qty, $supply_id); $upd->execute(); $upd->close();

                // history
                $h = $conn->prepare("INSERT INTO stock_history (supply_id, action_type, quantity_change, action_by) VALUES (?,?,?,?)");
                $act = 'Issued'; $neg = -$qty; $h->bind_param("isis", $supply_id, $act, $neg, $issued_by); $h->execute(); $h->close();

                $success = "Issued $qty item(s) to " . htmlspecialchars($issued_to);
            } else $error = $ins->error;
            $ins->close();
        }
    }
    $chk->close();
}

// list supplies for select and show recent issues
$supplies = $conn->query("SELECT supply_id, item_name, quantity FROM supplies ORDER BY item_name");
$recent = $conn->query("SELECT i.*, s.item_name FROM issued_items i LEFT JOIN supplies s ON i.supply_id=s.supply_id ORDER BY i.issued_at DESC LIMIT 50");
?>
<!doctype html><html><head><meta charset="utf-8"><title>Issue Supplies</title></head>
<body>
<div style="display:flex;gap:18px;padding:24px">
  <div class="sidebar"><h4>Menu</h4><hr>
    <a href="../store_clerk/dashboard.php"> Dashboard</a>
    <a href="../store_clerk/manage_supplies.php"> Manage Supplies</a>
    <a href="../store_clerk/issue_supplies.php"> Issue Items</a>
    <a href="../store_clerk/history.php"> Stock History</a>
  </div>

  <div style="flex:1" class="container-main">
    <div class="card">
      <h3>Issue Item</h3>
      <?php if($error):?><div style="color:red"><?php echo htmlspecialchars($error);?></div><?php endif;?>
      <?php if($success):?><div style="color:green"><?php echo htmlspecialchars($success);?></div><?php endif;?>
      <form method="post">
        <div style="display:flex;gap:8px">
          <select name="supply_id" required>
            <option value="">-- Select item --</option>
            <?php while($s = $supplies->fetch_assoc()): ?>
              <option value="<?php echo $s['supply_id'];?>"><?php echo htmlspecialchars($s['item_name'] . " (".$s['quantity']." available)");?></option>
            <?php endwhile; ?>
          </select>
          <input name="issued_to" placeholder="Issued to (name/department)" required style="flex:1;padding:8px">
          <input name="quantity" type="number" min="1" required placeholder="Qty" style="width:100px;padding:8px">
        </div>
        <div style="margin-top:8px"><button name="issue" type="submit" style="background:#6532C9;color:#fff;padding:8px 12px">Issue</button></div>
      </form>
    </div>

    <div class="card">
      <h4>Recent Issued Items</h4>
      <table style="width:100%;border-collapse:collapse">
        <thead style="background:#4311A5;color:#fff"><tr><th>Date</th><th>Item</th><th>Qty</th><th>Issued To</th><th>By</th></tr></thead>
        <tbody>
        <?php while($r = $recent->fetch_assoc()): ?>
          <tr>
            <td><?php echo date('M j, Y g:i A', strtotime($r['issued_at']));?></td>
            <td><?php echo htmlspecialchars($r['item_name'] ?: '-');?></td>
            <td><?php echo (int)$r['quantity_issued'];?></td>
            <td><?php echo htmlspecialchars($r['issued_to']);?></td>
            <td><?php echo htmlspecialchars($r['issued_by']);?></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>
</body>
</html>
