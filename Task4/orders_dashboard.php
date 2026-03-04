<?php
$conn = new mysqli("localhost","root","","orders_db");
if($conn->connect_error) die("DB error");

// sort by date or customer name
$sort = $_GET['sort'] ?? 'date';
$orderBy = $sort === 'name' ? 'c.name' : 'o.order_date';

// joined order history
$sql = "SELECT o.id,o.order_date,o.total_amount,
               c.name AS customer,c.email,
               GROUP_CONCAT(p.name,' x',oi.quantity SEPARATOR ', ') AS items
        FROM orders o
        JOIN customers c ON o.customer_id=c.id
        JOIN order_items oi ON o.id=oi.order_id
        JOIN products p ON oi.product_id=p.id
        GROUP BY o.id
        ORDER BY $orderBy DESC";
$orders = $conn->query($sql);

// highest value order (subquery)
$maxOrder = $conn->query(
  "SELECT o.id,o.total_amount,c.name
   FROM orders o
   JOIN customers c ON o.customer_id=c.id
   WHERE o.total_amount = (SELECT MAX(total_amount) FROM orders)
   LIMIT 1"
)->fetch_assoc();

// most active customer by number of orders (subquery)
$active = $conn->query(
  "SELECT c.name, COUNT(o.id) AS total_orders
   FROM customers c
   JOIN orders o ON o.customer_id=c.id
   GROUP BY c.id
   HAVING total_orders = (
      SELECT MAX(cnt) FROM (
        SELECT COUNT(*) AS cnt
        FROM orders GROUP BY customer_id
      ) AS t
   )
   LIMIT 1"
)->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Order Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{
  font-family:system-ui,Arial,sans-serif;
  background:#f3f4f6;
  padding:20px;
}
h1{font-size:24px;margin-bottom:10px;color:#111827;}
.wrapper{display:flex;flex-wrap:wrap;gap:18px;}
.card{
  background:#ffffff;
  border-radius:14px;
  padding:16px 18px;
  box-shadow:0 8px 22px rgba(15,23,42,.12);
}
.main{flex:2 1 380px;}
.side{flex:1 1 220px;}
.controls{margin-bottom:10px;font-size:13px;}
select,button{
  padding:5px 10px;
  border-radius:999px;
  border:1px solid #d1d5db;
  font-size:13px;
}
button{
  background:#2563eb;
  color:#fff;
  border:none;
  cursor:pointer;
}
button:hover{background:#1d4ed8;}
table{
  width:100%;
  border-collapse:collapse;
  font-size:13px;
  margin-top:8px;
}
th,td{
  padding:8px 10px;
  border-bottom:1px solid #e5e7eb;
  text-align:left;
}
th{
  background:#111827;
  color:#f9fafb;
}
.tag{
  display:inline-block;
  padding:3px 8px;
  border-radius:999px;
  background:#eef2ff;
  color:#3730a3;
  font-size:11px;
}
.stat{
  font-size:14px;
  margin-bottom:8px;
}
.stat span{font-weight:500;}
.note{font-size:11px;color:#6b7280;margin-top:6px;}
</style>
</head>
<body>
<h1>Order Management Dashboard</h1>

<div class="wrapper">
  <div class="card main">
    <div class="controls">
      <form method="get">
        <label>Sort by: </label>
        <select name="sort">
          <option value="date" <?php if($sort==='date') echo 'selected'; ?>>Order Date</option>
          <option value="name" <?php if($sort==='name') echo 'selected'; ?>>Customer Name</option>
        </select>
        <button type="submit">Apply</button>
      </form>
    </div>

    <table>
      <tr>
        <th>#</th>
        <th>Customer</th>
        <th>Date</th>
        <th>Items</th>
        <th>Total</th>
      </tr>
      <?php if($orders && $orders->num_rows): ?>
        <?php while($row = $orders->fetch_assoc()): ?>
          <tr>
            <td><?= $row['id']; ?></td>
            <td>
              <?= htmlspecialchars($row['customer']); ?><br>
              <span class="tag"><?= htmlspecialchars($row['email']); ?></span>
            </td>
            <td><?= $row['order_date']; ?></td>
            <td><?= htmlspecialchars($row['items']); ?></td>
            <td>₹<?= number_format($row['total_amount'],2); ?></td>
          </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="5">No orders found.</td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="card side">
    <h3>Highlights</h3><br>
    <?php if($maxOrder): ?>
      <div class="stat">
        Highest order: <span>#<?= $maxOrder['id']; ?></span><br>
        Customer: <span><?= htmlspecialchars($maxOrder['name']); ?></span><br>
        Value: <span>₹<?= number_format($maxOrder['total_amount'],2); ?></span>
      </div>
    <?php endif; ?>

    <?php if($active): ?>
      <div class="stat">
        Most active customer:<br>
        <span><?= htmlspecialchars($active['name']); ?></span><br>
        Orders: <span><?= $active['total_orders']; ?></span>
      </div>
    <?php endif; ?>

    <div class="note">
      Uses JOINs to link customers, orders, items, and products, plus subqueries for highest order and most active customer.
    </div>
  </div>
</div>

</body>
</html>
<?php $conn->close(); ?>
