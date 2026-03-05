<?php
$conn = new mysqli("localhost","root","","audit_db");
if ($conn->connect_error) die("DB error");

$daily = $conn->query("SELECT * FROM daily_activity_report");
$logs  = $conn->query("SELECT * FROM activity_log ORDER BY log_time DESC LIMIT 10");
$rows  = $conn->query("SELECT * FROM activity_table ORDER BY changed_at DESC LIMIT 10");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Audit Dashboard - Task 6</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{
  font-family:system-ui,Arial,sans-serif;
  background:#f3f4f6;
  padding:24px;
}
.page{
  max-width:1100px;
  margin:0 auto;
  display:flex;
  flex-direction:column;
  gap:16px;
}
header h1{
  font-size:24px;
  color:#111827;
}
header p{
  font-size:13px;
  color:#6b7280;
  margin-top:4px;
}
.section{
  background:#ffffff;
  border-radius:14px;
  padding:14px 16px;
  box-shadow:0 8px 22px rgba(15,23,42,.12);
}
.section h2{
  font-size:18px;
  margin-bottom:4px;
  color:#111827;
}
.section p{
  font-size:12px;
  color:#6b7280;
  margin-bottom:6px;
}
.badge{
  display:inline-block;
  padding:3px 8px;
  border-radius:999px;
  background:#eef2ff;
  color:#3730a3;
  font-size:11px;
  margin-right:4px;
}
.layout{
  display:flex;
  flex-wrap:wrap;
  gap:12px;
}
.col{
  flex:1 1 320px;
}
table{
  width:100%;
  border-collapse:collapse;
  font-size:12px;
  margin-top:6px;
}
th,td{
  padding:6px 8px;
  border-bottom:1px solid #e5e7eb;
  text-align:left;
}
th{
  background:#111827;
  color:#f9fafb;
}
.user{font-weight:500;}
.date{font-size:11px;color:#4b5563;}
.small{font-size:11px;color:#6b7280;}
.empty{text-align:center;font-size:12px;color:#6b7280;padding:10px 0;}
</style>
</head>
<body>
<div class="page">

  <header>
    <h1>Audit Dashboard (Triggers & Views)</h1>
    <p>Every INSERT and UPDATE on <code>activity_table</code> is logged into <code>activity_log</code> by MySQL triggers and summarized using the <code>daily_activity_report</code> view.</p>
  </header>

  <!-- Daily summary -->
  <section class="section">
    <h2>Daily Activity Summary</h2>
    <p><span class="badge">View: daily_activity_report</span> Actions grouped by date and user.</p>
    <table>
      <tr>
        <th>Date</th>
        <th>User</th>
        <th>Total</th>
        <th>Inserts</th>
        <th>Updates</th>
      </tr>
      <?php if($daily && $daily->num_rows): ?>
        <?php while($r=$daily->fetch_assoc()): ?>
          <tr>
            <td class="date"><?= htmlspecialchars($r['activity_date']); ?></td>
            <td class="user"><?= htmlspecialchars($r['changed_by']); ?></td>
            <td><?= (int)$r['total_actions']; ?></td>
            <td><?= (int)$r['inserts_count']; ?></td>
            <td><?= (int)$r['updates_count']; ?></td>
          </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="5" class="empty">No summary yet.</td></tr>
      <?php endif; ?>
    </table>
  </section>

  <div class="layout">
    <!-- Recent log entries -->
    <section class="section col">
      <h2>Recent Log Entries</h2>
      <p>Raw rows from <span class="badge">activity_log</span> (last 10 actions).</p>
      <table>
        <tr>
          <th>Time</th>
          <th>User</th>
          <th>Action</th>
          <th>Old → New</th>
        </tr>
        <?php if($logs && $logs->num_rows): ?>
          <?php while($l=$logs->fetch_assoc()): ?>
            <tr>
              <td class="date"><?= htmlspecialchars($l['log_time']); ?></td>
              <td class="user"><?= htmlspecialchars($l['changed_by']); ?></td>
              <td><?= htmlspecialchars($l['action_type']); ?></td>
              <td>
                <span class="small">
                  <?= htmlspecialchars($l['old_description'] ?? 'NULL'); ?>
                  →
                  <?= htmlspecialchars($l['new_description'] ?? 'NULL'); ?>
                </span>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="4" class="empty">No logs yet.</td></tr>
        <?php endif; ?>
      </table>
    </section>

    <!-- Recent business rows -->
    <section class="section col">
      <h2>Recent Activity Records</h2>
      <p>Latest rows in <span class="badge">activity_table</span>.</p>
      <table>
        <tr>
          <th>ID</th>
          <th>Type</th>
          <th>Description</th>
          <th>User</th>
          <th>Time</th>
        </tr>
        <?php if($rows && $rows->num_rows): ?>
          <?php while($a=$rows->fetch_assoc()): ?>
            <tr>
              <td><?= $a['id']; ?></td>
              <td><?= htmlspecialchars($a['entity_type']); ?></td>
              <td class="small"><?= htmlspecialchars($a['description']); ?></td>
              <td class="user"><?= htmlspecialchars($a['changed_by']); ?></td>
              <td class="date"><?= htmlspecialchars($a['changed_at']); ?></td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="5" class="empty">No activity rows.</td></tr>
        <?php endif; ?>
      </table>
    </section>
  </div>

</div>
</body>
</html>
<?php $conn->close(); ?>
