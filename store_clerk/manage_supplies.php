<?php
// store_clerk/manage_supplies.php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

checkRole(['storeclerk']);
include __DIR__ . '/../includes/storeclerk_navbar.php';

$error=''; $success='';

// Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $item_name = $_POST['item_name'];
    $description = $_POST['description'];
    $quantity = intval($_POST['quantity']);
    $unit = $_POST['unit'];
    $created_by = $_SESSION['username'];

    $stmt = $conn->prepare("INSERT INTO supplies (item_name, description, quantity, unit, created_by) VALUES (?,?,?,?,?)");
    $stmt->bind_param("ssiss", $item_name, $description, $quantity, $unit, $created_by);
    if ($stmt->execute()) {
        $supply_id = $stmt->insert_id;
        // history
        $h = $conn->prepare("INSERT INTO stock_history (supply_id, action_type, quantity_change, action_by) VALUES (?,?,?,?)");
        $act = 'Added'; $h->bind_param("isis", $supply_id, $act, $quantity, $created_by); $h->execute(); $h->close();
        $success = "Supply added.";
    } else $error = $stmt->error;
    $stmt->close();
}

// Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $id = intval($_POST['supply_id']);
    $item_name = $_POST['item_name'];
    $description = $_POST['description'];
    $new_qty = intval($_POST['quantity']);
    $unit = $_POST['unit'];
    $updated_by = $_SESSION['username'];

    // get old qty
    $old = $conn->prepare("SELECT quantity FROM supplies WHERE supply_id=?");
    $old->bind_param("i",$id); $old->execute(); $res = $old->get_result(); $old_row = $res->fetch_assoc(); $old_qty = $old_row['quantity'] ?? 0; $old->close();

    $stmt = $conn->prepare("UPDATE supplies SET item_name=?, description=?, quantity=?, unit=? WHERE supply_id=?");
    $stmt->bind_param("ssisi", $item_name, $description, $new_qty, $unit, $id);
    if ($stmt->execute()) {
        $delta = $new_qty - $old_qty;
        if ($delta != 0) {
            $h = $conn->prepare("INSERT INTO stock_history (supply_id, action_type, quantity_change, action_by) VALUES (?,?,?,?)");
            $act = 'Updated'; $h->bind_param("isis", $id, $act, $delta, $updated_by); $h->execute(); $h->close();
        }
        $success = "Supply updated.";
    } else $error = $stmt->error;
    $stmt->close();
}

// Delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del = (int)$_GET['delete'];
    // optional: record deletion in history as negative of current qty
    $sel = $conn->prepare("SELECT quantity FROM supplies WHERE supply_id=?");
    $sel->bind_param("i",$del); $sel->execute(); $r = $sel->get_result(); $row = $r->fetch_assoc(); $qty = $row['quantity'] ?? 0; $sel->close();

    $stmt = $conn->prepare("DELETE FROM supplies WHERE supply_id=?");
    $stmt->bind_param("i",$del);
    if ($stmt->execute()) {
        if ($qty != 0) {
            $h = $conn->prepare("INSERT INTO stock_history (supply_id, action_type, quantity_change, action_by) VALUES (?,?,?,?)");
            $act = 'Deleted'; $by = $_SESSION['username']; $neg = -$qty; $h->bind_param("isis", $del, $act, $neg, $by); $h->execute(); $h->close();
        }
        header("Location: manage_supplies.php"); exit();
    } else $error = $stmt->error;
}

// For edit form
$editSupply = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $s = $conn->prepare("SELECT * FROM supplies WHERE supply_id=?");
    $s->bind_param("i",$eid); $s->execute(); $res = $s->get_result();
    if ($res->num_rows) $editSupply = $res->fetch_assoc();
    $s->close();
}

// list
$supplies = $conn->query("SELECT * FROM supplies ORDER BY created_at DESC LIMIT 500");
?>
<!doctype html><html><head><meta charset="utf-8"><title>Manage Supplies</title></head><body>
<div style="display:flex;gap:18px;padding:24px">
  <div class="sidebar">
    <h4>Menu</h4><hr>
    <a href="../store_clerk/dashboard.php"> Dashboard</a>
    <a href="../store_clerk/manage_supplies.php"> Manage Supplies</a>
    <a href="../store_clerk/issue_supplies.php"> Issue Items</a>
    <a href="../store_clerk/history.php"> Stock History</a>
  </div>

  <div style="flex:1" class="container-main">
    <div class="card">
      <h3><?php echo $editSupply ? 'Edit Supply' : 'Add Supply'; ?></h3>
      <?php if($error):?><div style="color:red"><?php echo htmlspecialchars($error); ?></div><?php endif;?>
      <?php if($success):?><div style="color:green"><?php echo htmlspecialchars($success); ?></div><?php endif;?>
      <form method="post">
        <?php if($editSupply): ?><input type="hidden" name="supply_id" value="<?php echo (int)$editSupply['supply_id']; ?>"><?php endif; ?>
        <div style="display:flex;gap:8px">
          <input name="item_name" required placeholder="Item name" value="<?php echo $editSupply['item_name'] ?? ''; ?>" style="flex:1;padding:8px">
          <input name="unit" placeholder="Unit (pcs, box...)" value="<?php echo $editSupply['unit'] ?? ''; ?>" style="width:160px;padding:8px">
          <input name="quantity" type="number" required placeholder="Qty" value="<?php echo $editSupply['quantity'] ?? 0; ?>" style="width:120px;padding:8px">
        </div>
        <div style="margin-top:8px">
          <input name="description" placeholder="Description" value="<?php echo $editSupply['description'] ?? ''; ?>" style="width:80%;padding:8px">
        </div>
        <div style="margin-top:10px">
          <?php if($editSupply): ?>
            <button type="submit" name="update" style="background:#6532C9;color:#fff;padding:8px 12px">Update</button>
            <a href="manage_supplies.php" style="margin-left:8px">Cancel</a>
          <?php else: ?>
            <button type="submit" name="create" style="background:#6532C9;color:#fff;padding:8px 12px">Add Supply</button>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <h4>Current Supplies</h4>
      <table style="width:100%;border-collapse:collapse">
        <thead style="background:#4311A5;color:#fff"><tr><th>Item</th><th>Unit</th><th>Qty</th><th>Added By</th><th>Actions</th></tr></thead>
        <tbody>
        <?php while($r = $supplies->fetch_assoc()): ?>
          <tr>
            <td style="padding:8px"><?php echo htmlspecialchars($r['item_name']);?></td>
            <td><?php echo htmlspecialchars($r['unit']);?></td>
            <td><?php echo (int)$r['quantity'];?></td>
            <td><?php echo htmlspecialchars($r['created_by']);?></td>
            <td>
              <a href="manage_supplies.php?edit=<?php echo $r['supply_id']; ?>">Edit</a> |
              <a href="manage_supplies.php?delete=<?php echo $r['supply_id']; ?>" onclick="return confirm('Delete this item?')">Delete</a>
            </td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>
</body></html>
