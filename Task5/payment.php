<?php
$conn = new mysqli("localhost","root","","payments_db");
if($conn->connect_error) die("DB error");
$msg=""; $ok=false;

if($_SERVER["REQUEST_METHOD"]==="POST"){
    $amount = floatval($_POST["amount"] ?? 0);
    $merchantId = intval($_POST["merchant"] ?? 2);

    if($amount <= 0){
        $msg = "Enter a valid amount.";
    }else{
        $conn->begin_transaction();
        try{
            $userRes = $conn->query("SELECT * FROM accounts WHERE id=1 FOR UPDATE");
            $merRes  = $conn->query("SELECT * FROM accounts WHERE id=".$merchantId." FOR UPDATE");
            $user = $userRes->fetch_assoc();
            $mer  = $merRes->fetch_assoc();

            if(!$user || !$mer) throw new Exception("Accounts not found");
            if($user["status"]!=="active" || $mer["status"]!=="active")
                throw new Exception("One of the accounts is not active");
            if($user["balance"] < $amount)
                throw new Exception("Insufficient user balance");

            $newUserBal = $user["balance"] - $amount;
            $newMerBal  = $mer["balance"] + $amount;

            if(!$conn->query("UPDATE accounts SET balance=$newUserBal, last_updated=NOW() WHERE id=1"))
                throw new Exception("Failed to update user");
            if(!$conn->query("UPDATE accounts SET balance=$newMerBal, last_updated=NOW() WHERE id=".$merchantId))
                throw new Exception("Failed to update merchant");

            $conn->commit();
            $msg = "Payment successful! ₹".$amount." transferred.";
            $ok = true;
        }catch(Exception $e){
            $conn->rollback();
            $msg = "Payment failed: ".$e->getMessage();
        }
    }
}

$accRes = $conn->query("SELECT * FROM accounts ORDER BY id");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Payment Simulation</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{
  font-family:system-ui,Arial,sans-serif;
  background:#f3f4f6;
  min-height:100vh;
  display:flex;
  justify-content:center;
  align-items:center;
  padding:20px;
}
.wrap{display:flex;flex-wrap:wrap;gap:18px;max-width:950px;width:100%;}
.card{
  background:#ffffff;
  border-radius:14px;
  padding:16px 18px;
  box-shadow:0 8px 22px rgba(15,23,42,.12);
}
.main{flex:1.2 1 340px;}
.side{flex:1.5 1 360px;}
h2{font-size:22px;margin-bottom:8px;color:#111827;}
p{font-size:13px;color:#6b7280;margin-bottom:14px;}
.field{margin-bottom:12px;}
label{display:block;font-size:13px;margin-bottom:4px;color:#374151;}
input,select{
  width:100%;padding:8px 10px;border-radius:8px;
  border:1px solid #d1d5db;font-size:13px;outline:none;
}
input:focus,select:focus{
  border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.18);
}
button{
  width:100%;padding:9px 0;border:none;border-radius:999px;
  background:#2563eb;color:#fff;font-size:14px;font-weight:500;cursor:pointer;
}
button:hover{background:#1d4ed8;}
.msg{font-size:13px;margin-bottom:10px;text-align:center;}
.ok{color:#16a34a;}
.fail{color:#dc2626;}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:6px;}
th,td{padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;}
th{background:#111827;color:#f9fafb;}
.badge{
  display:inline-block;padding:3px 8px;border-radius:999px;
  font-size:11px;
}
.badge.user{background:#eef2ff;color:#3730a3;}
.badge.merchant{background:#fef3c7;color:#92400e;}
.status{font-size:11px;}
.status.active{color:#16a34a;}
.status.blocked{color:#dc2626;}
</style>
</head>
<body>
<div class="wrap">

  <div class="card main">
    <h2>Payment Simulation</h2>
    <p>Transfer money from the user wallet to a merchant account using a database transaction (COMMIT / ROLLBACK).</p>

    <?php if($msg): ?>
      <div class="msg <?= $ok?'ok':'fail'; ?>"><?= htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="field">
        <label for="amount">Amount (₹)</label>
        <input id="amount" name="amount" type="number" step="0.01" min="1" required>
      </div>
      <div class="field">
        <label for="merchant">Merchant</label>
        <select id="merchant" name="merchant">
          <option value="2">Merchant A</option>
          <option value="3">Merchant B</option>
        </select>
      </div>
      <button type="submit">Pay Now</button>
    </form>
  </div>

  <div class="card side">
    <h2>Account Overview</h2>
    <table>
      <tr>
        <th>Name</th>
        <th>Type</th>
        <th>Status</th>
        <th>Last Updated</th>
        <th>Balance (₹)</th>
      </tr>
      <?php if($accRes && $accRes->num_rows): while($a=$accRes->fetch_assoc()): ?>
        <tr>
          <td><?= htmlspecialchars($a['name']); ?></td>
          <td>
            <span class="badge <?= $a['type']==='user'?'user':'merchant'; ?>">
              <?= htmlspecialchars($a['type']); ?>
            </span>
          </td>
          <td>
            <span class="status <?= $a['status']==='active'?'active':'blocked'; ?>">
              <?= htmlspecialchars($a['status']); ?>
            </span>
          </td>
          <td><?= htmlspecialchars($a['last_updated']); ?></td>
          <td><?= number_format($a['balance'],2); ?></td>
        </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="5">No accounts.</td></tr>
      <?php endif; ?>
    </table>
  </div>

</div>
</body>
</html>
<?php $conn->close(); ?>
