<?php
session_start();
$conn = new mysqli("localhost","root","","login_db");
if($conn->connect_error) die("DB error");
$msg = "";

if($_SERVER["REQUEST_METHOD"]==="POST"){
    $u = trim($_POST["username"]??"");
    $p = trim($_POST["password"]??"");
    $stmt = $conn->prepare("SELECT * FROM users WHERE username=? AND password=?");
    $stmt->bind_param("ss",$u,$p);
    $stmt->execute();
    $res = $stmt->get_result();
    $msg = $res->num_rows===1 ? "Login successful" : "Invalid username or password";
    if($res->num_rows===1) $_SESSION["user"]=$u;
    $stmt->close();
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{
  font-family:'Poppins',sans-serif;
  background:radial-gradient(circle at top,#1d4ed8 0,#020617 55%);
  min-height:100vh;display:flex;justify-content:center;align-items:center;padding:20px;
}
.card{
  background:#020617;border-radius:18px;padding:22px 22px 18px;
  width:100%;max-width:360px;color:#e5e7eb;
  box-shadow:0 18px 40px rgba(15,23,42,.95);
  border:1px solid rgba(148,163,184,.35);
}
h2{font-size:22px;margin-bottom:6px;}
p{font-size:13px;color:#9ca3af;margin-bottom:14px;}
.field{margin-bottom:14px;}
label{display:block;font-size:13px;margin-bottom:4px;}
input{
  width:100%;padding:9px 11px;border-radius:999px;
  border:1px solid #4b5563;background:#020617;color:#e5e7eb;
  font-size:13px;outline:none;transition:border-color .15s,box-shadow .15s;
}
input:focus{border-color:#38bdf8;box-shadow:0 0 0 1px #38bdf8;}
button{
  width:100%;margin-top:4px;padding:9px 0;border:none;border-radius:999px;
  background:linear-gradient(135deg,#38bdf8,#6366f1);color:#fff;
  font-size:14px;font-weight:500;cursor:pointer;transition:filter .15s,transform .05s;
}
button:hover{filter:brightness(1.05);}
button:active{transform:scale(.98);}
.msg{text-align:center;font-size:13px;margin-bottom:8px;}
.ok{color:#bbf7d0;}
.fail{color:#fed7d7;}
.error{font-size:12px;color:#fca5a5;margin-top:3px;}
.hint{margin-top:10px;font-size:11px;color:#9ca3af;text-align:center;}
</style>
</head>
<body>
<div class="card">
  <h2>Sign in</h2>
  <p>Use demo users: admin/admin123, palanivel/cse2023, testuser/test123</p>

  <?php if($msg): ?>
    <div class="msg <?= $msg==='Login successful'?'ok':'fail'; ?>"><?= htmlspecialchars($msg); ?></div>
  <?php endif; ?>

  <form id="loginForm" method="POST" onsubmit="return validateLogin()">
    <div class="field">
      <label for="username">Username</label>
      <input id="username" name="username" type="text">
      <div id="userErr" class="error"></div>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password">
      <div id="passErr" class="error"></div>
    </div>
    <button type="submit">Login</button>
  </form>
  <div class="hint">Client-side validation + DB check.</div>
</div>

<script>
function validateLogin(){
  let ok=true;
  const u=document.getElementById('username');
  const p=document.getElementById('password');
  const ue=document.getElementById('userErr');
  const pe=document.getElementById('passErr');
  ue.textContent=""; pe.textContent="";
  if(!u.value.trim()){ue.textContent="Username is required";ok=false;}
  else if(u.value.length<3){ue.textContent="At least 3 characters";ok=false;}
  if(!p.value.trim()){pe.textContent="Password is required";ok=false;}
  else if(p.value.length<4){pe.textContent="At least 4 characters";ok=false;}
  return ok;
}
</script>
</body>
</html>
<?php $conn->close(); ?>
