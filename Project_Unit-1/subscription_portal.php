<?php
session_start();
$conn = new mysqli('localhost','root','','subscription_db');

function q($c,$s,...$p){$st=$c->prepare($s);if($p){$st->bind_param(...$p);}$st->execute();return $st;}

$act = $_POST['action'] ?? '';
if($act && $_SERVER['REQUEST_METHOD']==='POST'){
  header('Content-Type: application/json');
  $uid = $_SESSION['uid'] ?? 0;

  if($act==='login'){
    $u=$_POST['u'];$p=$_POST['p'];
    $r=q($conn,"SELECT id,full_name,password FROM users WHERE username=? OR email=?",'ss',$u,$u)->get_result()->fetch_assoc();
    if($r&&password_verify($p,$r['password'])){$_SESSION['uid']=$r['id'];$_SESSION['name']=$r['full_name'];echo json_encode(['ok'=>1,'name'=>$r['full_name']]);}
    else echo json_encode(['err'=>'Invalid credentials']);
  }
  elseif($act==='register'){
    $st=q($conn,"INSERT INTO users(full_name,email,phone,username,password)VALUES(?,?,?,?,?)",'sssss',$_POST['name'],$_POST['email'],$_POST['phone'],$_POST['uname'],password_hash($_POST['pass'],PASSWORD_DEFAULT));
    if($conn->affected_rows){$_SESSION['uid']=$conn->insert_id;$_SESSION['name']=$_POST['name'];echo json_encode(['ok'=>1,'name'=>$_POST['name']]);}
    else echo json_encode(['err'=>'Username or email taken']);
  }
  elseif($act==='logout'){session_destroy();echo json_encode(['ok'=>1]);}
  elseif($act==='plans'){
    $rows=[];$r=q($conn,"SELECT * FROM plans")->get_result();
    while($x=$r->fetch_assoc())$rows[]=$x;echo json_encode($rows);
  }
  elseif($act==='pay'&&$uid){
    $pid=intval($_POST['pid']);$method=$_POST['method'];
    $plan=q($conn,"SELECT * FROM plans WHERE id=?",'i',$pid)->get_result()->fetch_assoc();
    $txn=strtoupper(bin2hex(random_bytes(6)));
    q($conn,"UPDATE subscriptions SET status='cancelled' WHERE user_id=? AND status='active'",'i',$uid);
    q($conn,"INSERT INTO subscriptions(user_id,plan_id,start_date,end_date,payment_method,transaction_id,amount_paid)VALUES(?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),?,?,?)",'iissd',$uid,$pid,$method,$txn,$plan['price']);
    q($conn,"INSERT INTO payments(user_id,plan_id,amount,method,transaction_id)VALUES(?,?,?,?,?)",'iidss',$uid,$pid,$plan['price'],$method,$txn);
    echo json_encode(['ok'=>1,'txn'=>$txn,'plan'=>$plan['name'],'amt'=>$plan['price']]);
  }
  elseif($act==='dash'&&$uid){
    $sub=q($conn,"SELECT s.*,p.name pn,p.price FROM subscriptions s JOIN plans p ON s.plan_id=p.id WHERE s.user_id=? AND s.status='active' ORDER BY s.id DESC LIMIT 1",'i',$uid)->get_result()->fetch_assoc();
    $user=q($conn,"SELECT full_name,email,username,phone,created_at FROM users WHERE id=?",'i',$uid)->get_result()->fetch_assoc();
    echo json_encode(['sub'=>$sub,'user'=>$user]);
  }
  exit;
}
$loggedIn=isset($_SESSION['uid']);
$uname=$_SESSION['name']??'';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SubVault</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--sky:#e8f4fd;--blue:#2563eb;--blue2:#1d4ed8;--teal:#0ea5e9;--green:#10b981;--text:#0f172a;--muted:#64748b;--border:#e2e8f0;--white:#fff;--card:#ffffffee;--r:14px}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#dbeafe 0%,#e0f2fe 50%,#ccfbf1 100%);min-height:100vh;color:var(--text);background-attachment:fixed}

/* NAV */
nav{position:fixed;top:0;left:0;right:0;z-index:100;background:rgba(255,255,255,.8);backdrop-filter:blur(16px);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 5%;height:62px}
.logo{font-weight:800;font-size:1.2rem;color:var(--blue);display:flex;align-items:center;gap:8px;cursor:pointer;text-decoration:none}
.logo span{width:32px;height:32px;background:linear-gradient(135deg,var(--blue),var(--teal));border-radius:8px;display:grid;place-items:center;color:#fff;font-size:.9rem}
.nav-r{display:flex;align-items:center;gap:6px}
.nav-r a,.nav-r button{font:600 .85rem 'Inter',sans-serif;padding:7px 16px;border-radius:8px;border:none;cursor:pointer;text-decoration:none;transition:.2s;background:transparent;color:var(--muted)}
.nav-r a:hover{color:var(--blue);background:#eff6ff}
.nav-r .btn-solid{background:var(--blue);color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.3)}
.nav-r .btn-solid:hover{background:var(--blue2)}
.nav-user{display:flex;align-items:center;gap:8px;font-weight:600;font-size:.88rem;color:var(--text)}
.avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--teal));color:#fff;display:grid;place-items:center;font-weight:700;font-size:.85rem}

/* PAGES */
.pg{display:none;min-height:100vh;padding-top:62px;animation:fi .35s ease}
.pg.on{display:block}
@keyframes fi{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

/* AUTH */
.auth-wrap{display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 62px);padding:30px 16px}
.auth-box{background:var(--card);backdrop-filter:blur(20px);border-radius:20px;padding:44px 40px;width:100%;max-width:420px;border:1px solid var(--border);box-shadow:0 16px 48px rgba(37,99,235,.1)}
.auth-icon{width:52px;height:52px;background:linear-gradient(135deg,var(--blue),var(--teal));border-radius:14px;display:grid;place-items:center;color:#fff;font-size:1.3rem;margin:0 auto 18px}
.auth-box h2{text-align:center;font-size:1.5rem;font-weight:800;margin-bottom:6px}
.auth-box p{text-align:center;color:var(--muted);font-size:.88rem;margin-bottom:28px}
.fg{margin-bottom:16px}
.fg label{display:block;font-weight:600;font-size:.8rem;color:var(--muted);margin-bottom:6px;letter-spacing:.3px}
.fg input{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;font:500 .92rem 'Inter',sans-serif;background:#fff;color:var(--text);outline:none;transition:.25s}
.fg input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn-full{width:100%;padding:12px;background:linear-gradient(135deg,var(--blue),var(--teal));color:#fff;font:700 .95rem 'Inter',sans-serif;border:none;border-radius:10px;cursor:pointer;box-shadow:0 4px 14px rgba(37,99,235,.3);transition:.25s;margin-top:4px}
.btn-full:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(37,99,235,.35)}
.switch-link{text-align:center;margin-top:20px;font-size:.85rem;color:var(--muted)}
.switch-link a{color:var(--blue);font-weight:700;text-decoration:none}
.err{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:.83rem;font-weight:600;margin-bottom:14px;display:none}

/* HOME */
.hero{padding:80px 6% 60px;text-align:center}
.hero-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.2);color:var(--blue);font-size:.75rem;font-weight:700;padding:5px 14px;border-radius:20px;margin-bottom:20px}
.hero h1{font-size:clamp(2rem,5vw,3.2rem);font-weight:800;line-height:1.15;margin-bottom:16px}
.hero h1 em{font-style:normal;background:linear-gradient(135deg,var(--blue),var(--teal));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.hero p{color:var(--muted);font-size:1.05rem;max-width:500px;margin:0 auto 32px;line-height:1.7}
.hero-btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.btn-primary{padding:13px 28px;background:linear-gradient(135deg,var(--blue),var(--teal));color:#fff;font:700 .95rem 'Inter',sans-serif;border:none;border-radius:10px;cursor:pointer;box-shadow:0 4px 16px rgba(37,99,235,.35);transition:.25s}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(37,99,235,.4)}
.btn-outline{padding:13px 28px;background:#fff;color:var(--blue);font:700 .95rem 'Inter',sans-serif;border:2px solid rgba(37,99,235,.25);border-radius:10px;cursor:pointer;transition:.25s}
.btn-outline:hover{border-color:var(--blue);background:#eff6ff}
.stats{display:flex;justify-content:center;gap:48px;margin-top:52px;flex-wrap:wrap}
.stat-n{font-size:2rem;font-weight:800;color:var(--text)}
.stat-l{font-size:.78rem;color:var(--muted);font-weight:500;margin-top:2px}
.feats{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;padding:20px 6% 80px}
.fc{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:26px;transition:.3s;backdrop-filter:blur(12px)}
.fc:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(37,99,235,.1);border-color:rgba(37,99,235,.2)}
.fc-icon{width:44px;height:44px;border-radius:10px;display:grid;place-items:center;font-size:1.1rem;margin-bottom:14px}
.fc h3{font-size:.95rem;font-weight:700;margin-bottom:6px}
.fc p{font-size:.82rem;color:var(--muted);line-height:1.6}

/* PLANS */
.plans-wrap{padding:60px 6%}
.section-head{text-align:center;margin-bottom:44px}
.section-head h2{font-size:2rem;font-weight:800;margin-bottom:8px}
.section-head p{color:var(--muted);font-size:.92rem}
.plans-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:22px}
.pc{background:var(--card);border:1.5px solid var(--border);border-radius:20px;padding:34px 28px;backdrop-filter:blur(12px);transition:.35s;position:relative}
.pc:hover{transform:translateY(-6px);box-shadow:0 20px 48px rgba(37,99,235,.13);border-color:rgba(37,99,235,.25)}
.pc.hot{border-color:var(--blue);box-shadow:0 8px 32px rgba(37,99,235,.15)}
.badge{position:absolute;top:18px;right:18px;background:linear-gradient(135deg,var(--blue),var(--teal));color:#fff;font-size:.7rem;font-weight:700;padding:3px 10px;border-radius:20px}
.plan-emoji{font-size:2rem;margin-bottom:12px}
.plan-name{font-weight:700;font-size:1rem;color:var(--muted);margin-bottom:8px}
.plan-amt{font-size:2.8rem;font-weight:800;color:var(--text);line-height:1}
.plan-amt sup{font-size:1.2rem;vertical-align:top;margin-top:8px;display:inline-block}
.plan-mo{font-size:.8rem;color:var(--muted);margin-bottom:16px}
.plan-feats{list-style:none;margin-bottom:26px;display:flex;flex-direction:column;gap:9px}
.plan-feats li{display:flex;align-items:center;gap:9px;font-size:.85rem;font-weight:500}
.plan-feats li i{color:var(--green);font-size:.8rem}
.btn-plan{width:100%;padding:12px;border:2px solid var(--blue);background:transparent;color:var(--blue);font:700 .9rem 'Inter',sans-serif;border-radius:10px;cursor:pointer;transition:.25s}
.btn-plan:hover,.pc.hot .btn-plan{background:var(--blue);color:#fff;box-shadow:0 4px 14px rgba(37,99,235,.3)}

/* PAYMENT */
.pay-wrap{display:flex;gap:32px;padding:50px 6%;align-items:flex-start;flex-wrap:wrap}
.pay-left{flex:1;min-width:280px}
.pay-right{width:300px;flex-shrink:0}
.card-box{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:30px;backdrop-filter:blur(16px);box-shadow:0 8px 32px rgba(37,99,235,.08)}
.card-box h3{font-size:1.1rem;font-weight:700;margin-bottom:4px}
.card-box .sub{color:var(--muted);font-size:.82rem;margin-bottom:24px}
.tabs{display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap}
.tab{flex:1;min-width:70px;padding:10px 8px;border:1.5px solid var(--border);background:#fff;border-radius:10px;cursor:pointer;font:600 .78rem 'Inter',sans-serif;color:var(--muted);text-align:center;transition:.2s;display:flex;flex-direction:column;align-items:center;gap:4px}
.tab i{font-size:1.1rem}
.tab.on,.tab:hover{border-color:var(--blue);color:var(--blue);background:#eff6ff}
.mc{display:none}.mc.on{display:block;animation:fi .3s ease}

/* Card visual */
.cv{background:linear-gradient(135deg,#1e3a8a,#2563eb,#0ea5e9);border-radius:16px;padding:24px;color:#fff;margin-bottom:20px;position:relative;overflow:hidden}
.cv::after{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.07)}
.cv-chip{width:36px;height:26px;background:linear-gradient(135deg,#fbbf24,#f59e0b);border-radius:5px;margin-bottom:18px}
.cv-num{font-size:1.2rem;letter-spacing:3px;margin-bottom:16px;font-weight:600;opacity:.95}
.cv-bot{display:flex;justify-content:space-between;font-size:.72rem;opacity:.8}
.cv-bot .lbl{opacity:.7;font-size:.6rem;letter-spacing:.8px;margin-bottom:1px}

.upi-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
.upi-opt{padding:12px 6px;border:1.5px solid var(--border);border-radius:10px;text-align:center;cursor:pointer;font:.7rem 'Inter',sans-serif;font-weight:600;color:var(--muted);transition:.2s}
.upi-opt i{display:block;font-size:1.2rem;margin-bottom:4px}
.upi-opt:hover,.upi-opt.on{border-color:var(--blue);color:var(--blue);background:#eff6ff}
.bank-list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
.bank-r{display:flex;align-items:center;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;font:.85rem 'Inter',sans-serif;font-weight:600;transition:.2s}
.bank-r:hover,.bank-r.on{border-color:var(--blue);color:var(--blue);background:#eff6ff}
.wallet-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.w-opt{padding:14px;border:1.5px solid var(--border);border-radius:10px;text-align:center;cursor:pointer;font:.78rem 'Inter',sans-serif;font-weight:600;color:var(--muted);transition:.2s}
.w-opt i{display:block;font-size:1.3rem;margin-bottom:5px}
.w-opt:hover,.w-opt.on{border-color:var(--blue);color:var(--blue);background:#eff6ff}
.btn-pay{width:100%;padding:13px;background:linear-gradient(135deg,var(--green),#059669);color:#fff;font:700 .95rem 'Inter',sans-serif;border:none;border-radius:10px;cursor:pointer;box-shadow:0 4px 14px rgba(16,185,129,.35);transition:.25s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px}
.btn-pay:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(16,185,129,.4)}

/* Order summary */
.summary{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:26px;backdrop-filter:blur(16px);box-shadow:0 8px 32px rgba(37,99,235,.08);position:sticky;top:80px}
.summary h3{font-size:1rem;font-weight:700;margin-bottom:18px}
.sum-plan{display:flex;align-items:center;gap:12px;background:#eff6ff;border-radius:12px;padding:14px;margin-bottom:18px;border:1px solid rgba(37,99,235,.12)}
.sum-icon{width:38px;height:38px;background:linear-gradient(135deg,var(--blue),var(--teal));border-radius:10px;display:grid;place-items:center;color:#fff;font-size:.95rem;flex-shrink:0}
.sum-name{font-weight:700;font-size:.9rem}
.sum-per{font-size:.75rem;color:var(--muted)}
.sum-row{display:flex;justify-content:space-between;font-size:.84rem;color:var(--muted);padding:6px 0}
.sum-row.total{border-top:1px solid var(--border);margin-top:8px;padding-top:12px;font-weight:800;color:var(--text);font-size:.95rem}
.badges{display:flex;gap:10px;justify-content:center;margin-top:16px;flex-wrap:wrap}
.sec{display:flex;align-items:center;gap:4px;font-size:.7rem;color:var(--muted);font-weight:600}
.sec i{color:var(--green);font-size:.8rem}

/* DASHBOARD */
.dash{padding:46px 6%}
.dash-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px;flex-wrap:wrap;gap:14px}
.dash-top h2{font-size:1.6rem;font-weight:800}
.dash-top p{color:var(--muted);font-size:.85rem;margin-top:3px}
.d-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;margin-bottom:28px}
.dc{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:20px;backdrop-filter:blur(12px);transition:.2s}
.dc:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(37,99,235,.1)}
.dc-icon{width:38px;height:38px;border-radius:9px;display:grid;place-items:center;font-size:1rem;margin-bottom:12px}
.dc-val{font-size:1.5rem;font-weight:800;margin-bottom:2px}
.dc-lbl{font-size:.75rem;color:var(--muted);font-weight:500}
.sub-card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:28px;backdrop-filter:blur(12px);display:flex;gap:24px;flex-wrap:wrap;align-items:center;margin-bottom:22px}
.sub-info{flex:1;min-width:180px}
.sub-title{font-size:1.3rem;font-weight:800;margin-bottom:6px}
.status-dot{display:inline-flex;align-items:center;gap:5px;background:#d1fae5;color:#065f46;font:.7rem 'Inter',sans-serif;font-weight:700;padding:3px 10px;border-radius:20px;margin-bottom:14px}
.status-dot span{width:6px;height:6px;border-radius:50%;background:#10b981;animation:blink 1.5s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.meta{display:flex;gap:20px;flex-wrap:wrap}
.meta-i label{font-size:.7rem;color:var(--muted);font-weight:700;letter-spacing:.3px;display:block;margin-bottom:2px}
.meta-i span{font-weight:700;font-size:.85rem}
.prog{margin-top:16px}
.prog-lbl{display:flex;justify-content:space-between;font-size:.75rem;color:var(--muted);margin-bottom:5px}
.prog-bar{height:6px;background:#e2e8f0;border-radius:30px;overflow:hidden}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--blue),var(--teal));border-radius:30px}
.sub-right{text-align:right;flex-shrink:0}
.sub-amt{font-size:2.2rem;font-weight:800}
.sub-mo{font-size:.8rem;color:var(--muted)}
.no-sub{background:var(--card);border:2px dashed var(--border);border-radius:20px;padding:50px;text-align:center}
.no-sub i{font-size:3rem;color:#cbd5e1;margin-bottom:14px}
.no-sub h3{font-size:1.2rem;font-weight:700;margin-bottom:6px}
.no-sub p{color:var(--muted);font-size:.85rem;margin-bottom:20px}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;background:var(--card);border:1px solid var(--border);border-radius:20px;padding:24px;backdrop-filter:blur(12px)}
.info-item label{font-size:.7rem;color:var(--muted);font-weight:700;letter-spacing:.3px;display:block;margin-bottom:3px}
.info-item span{font-weight:700;font-size:.88rem;color:var(--text)}

/* MODAL */
.overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(6px);z-index:999;display:none;align-items:center;justify-content:center}
.overlay.on{display:flex;animation:fi .3s ease}
.modal{background:#fff;border-radius:22px;padding:44px 36px;text-align:center;max-width:380px;width:90%;box-shadow:0 24px 64px rgba(15,23,42,.2);animation:pop .4s cubic-bezier(.175,.885,.32,1.275)}
@keyframes pop{from{transform:scale(.75);opacity:0}to{transform:scale(1);opacity:1}}
.modal-icon{width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,var(--green),#059669);display:grid;place-items:center;margin:0 auto 18px;font-size:1.9rem;color:#fff;box-shadow:0 6px 24px rgba(16,185,129,.4)}
.modal h2{font-size:1.4rem;font-weight:800;margin-bottom:8px}
.modal p{color:var(--muted);font-size:.88rem;margin-bottom:6px}
.txn{font-size:.75rem;background:#f1f5f9;padding:7px 14px;border-radius:7px;margin:10px 0 20px;font-family:monospace;word-break:break-all;display:inline-block}

/* TOAST */
.toast{position:fixed;bottom:22px;right:22px;z-index:9999;background:#fff;border-radius:12px;padding:12px 18px;box-shadow:0 8px 28px rgba(15,23,42,.15);border-left:3px solid var(--green);font-size:.85rem;font-weight:600;transform:translateX(130%);transition:transform .35s cubic-bezier(.175,.885,.32,1);display:flex;align-items:center;gap:8px;max-width:280px}
.toast.on{transform:translateX(0)}
.toast i{color:var(--green)}

/* FOOTER */
footer{background:rgba(15,23,42,.9);color:rgba(255,255,255,.6);padding:36px 6% 20px;margin-top:60px}
.footer-inner{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;border-top:1px solid rgba(255,255,255,.1);padding-top:20px}
footer .logo{color:#fff;margin-bottom:20px}
footer p{font-size:.8rem}

/* SPINNER */
.spin{width:18px;height:18px;border:2.5px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:s .7s linear infinite;display:inline-block}
@keyframes s{to{transform:rotate(360deg)}}

@media(max-width:700px){
  .hero{padding:50px 5% 30px}.row2{grid-template-columns:1fr}
  .auth-box{padding:30px 20px}.pay-right{width:100%}.summary{position:static}
  .sub-card{flex-direction:column}.sub-right{text-align:left}
  nav .nav-r a:not(.btn-solid){display:none}
}
</style>
</head>
<body>

<nav>
  <a class="logo" onclick="go('home')"><span><i class="fas fa-bolt"></i></span>SubVault</a>
  <div class="nav-r" id="navR">
    <a onclick="go('home')">Home</a>
    <a onclick="go('plans')">Plans</a>
    <span id="navGuest"><button class="btn-solid" onclick="go('auth')">Get Started</button></span>
    <span id="navUser" style="display:none;align-items:center;gap:8px" class="nav-user">
      <a id="navDash" onclick="go('dashboard')">Dashboard</a>
      <div class="avatar" id="navAv">U</div>
      <span id="navName"></span>
      <button style="color:#ef4444;font-size:.82rem;background:none;border:none;cursor:pointer;font-weight:600" onclick="doLogout()">Logout</button>
    </span>
  </div>
</nav>

<!-- AUTH -->
<div class="pg" id="pg-auth">
<div class="auth-wrap">
<div class="auth-box">
  <div id="fLogin">
    <div class="auth-icon"><i class="fas fa-shield-halved"></i></div>
    <h2>Welcome Back</h2><p>Sign in to your account</p>
    <div class="err" id="lerr"></div>
    <div class="fg"><label>USERNAME OR EMAIL</label><input id="lu" placeholder="Enter username or email"></div>
    <div class="fg"><label>PASSWORD</label><input id="lp" type="password" placeholder="Your password"></div>
    <button class="btn-full" onclick="doLogin(this)">Sign In</button>
    <div class="switch-link">No account? <a href="#" onclick="sw('reg')">Register here →</a></div>
  </div>
  <div id="fReg" style="display:none">
    <div class="auth-icon"><i class="fas fa-rocket"></i></div>
    <h2>Create Account</h2><p>Join SubVault today</p>
    <div class="err" id="rerr"></div>
    <div class="row2">
      <div class="fg"><label>FULL NAME</label><input id="rn" placeholder="Your name"></div>
      <div class="fg"><label>PHONE</label><input id="rph" placeholder="+91 ..."></div>
    </div>
    <div class="fg"><label>EMAIL</label><input id="re" type="email" placeholder="you@example.com"></div>
    <div class="fg"><label>USERNAME</label><input id="ru" placeholder="username"></div>
    <div class="fg"><label>PASSWORD</label><input id="rp" type="password" placeholder="Min 6 characters"></div>
    <button class="btn-full" onclick="doReg(this)">Create Account</button>
    <div class="switch-link">Have an account? <a href="#" onclick="sw('login')">Sign in →</a></div>
  </div>
</div></div></div>

<!-- HOME -->
<div class="pg on" id="pg-home">
  <div class="hero">
    <div class="hero-badge"><i class="fas fa-star"></i> Trusted by 50,000+ members</div>
    <h1>Manage Subscriptions<br>Like <em>Never Before</em></h1>
    <p>Automate billing, tier upgrades, renewals, and access control — all in one elegant platform.</p>
    <div class="hero-btns">
      <button class="btn-primary" onclick="go('plans')"><i class="fas fa-bolt"></i> Explore Plans</button>
      <button class="btn-outline" onclick="go('auth')">Get Started Free</button>
    </div>
    <div class="stats">
      <div><div class="stat-n">50K+</div><div class="stat-l">Active Users</div></div>
      <div><div class="stat-n">3</div><div class="stat-l">Tier Plans</div></div>
      <div><div class="stat-n">99.9%</div><div class="stat-l">Uptime</div></div>
      <div><div class="stat-n">4.9★</div><div class="stat-l">Rating</div></div>
    </div>
  </div>
  <div class="feats">
    <div class="fc"><div class="fc-icon" style="background:#eff6ff"><i class="fas fa-layer-group" style="color:var(--blue)"></i></div><h3>Tiered Access</h3><p>Granular permissions per membership tier with automatic content gating.</p></div>
    <div class="fc"><div class="fc-icon" style="background:#ecfdf5"><i class="fas fa-credit-card" style="color:var(--green)"></i></div><h3>Smart Payments</h3><p>Card, UPI, Net Banking & Wallets. Auto-retry on failures.</p></div>
    <div class="fc"><div class="fc-icon" style="background:#fef3c7"><i class="fas fa-chart-line" style="color:#f59e0b"></i></div><h3>Analytics</h3><p>Real-time MRR, churn rate, and lifetime value insights.</p></div>
    <div class="fc"><div class="fc-icon" style="background:#f5f3ff"><i class="fas fa-shield-alt" style="color:#8b5cf6"></i></div><h3>Bank-Grade Security</h3><p>End-to-end encryption and PCI-DSS compliant infrastructure.</p></div>
  </div>
  <footer>
    <a class="logo" onclick="go('home')"><span><i class="fas fa-bolt"></i></span>SubVault</a>
    <div class="footer-inner"><span>© 2025 SubVault. All rights reserved.</span><span>Privacy · Terms · Security</span></div>
  </footer>
</div>

<!-- PLANS -->
<div class="pg" id="pg-plans">
<div class="plans-wrap">
  <div class="section-head"><h2>Simple, Transparent Pricing</h2><p>No hidden fees. Upgrade or cancel anytime.</p></div>
  <div class="plans-grid" id="plansGrid"><p style="text-align:center;color:var(--muted);padding:40px"><i class="fas fa-spinner fa-spin"></i> Loading...</p></div>
  <p style="text-align:center;margin-top:28px;font-size:.82rem;color:var(--muted)"><i class="fas fa-shield-alt" style="color:var(--green)"></i> 30-day money-back guarantee on all plans</p>
</div></div>

<!-- PAYMENT -->
<div class="pg" id="pg-payment">
<div class="pay-wrap">
  <div class="pay-left">
    <div class="card-box">
      <h3>Secure Checkout</h3>
      <div class="sub">SSL encrypted · PCI DSS compliant</div>
      <div class="tabs">
        <div class="tab on" onclick="tab('card',this)"><i class="fas fa-credit-card"></i>Card</div>
        <div class="tab" onclick="tab('upi',this)"><i class="fas fa-mobile-alt"></i>UPI</div>
        <div class="tab" onclick="tab('bank',this)"><i class="fas fa-university"></i>Net Banking</div>
        <div class="tab" onclick="tab('wallet',this)"><i class="fas fa-wallet"></i>Wallet</div>
      </div>

      <div class="mc on" id="mc-card">
        <div class="cv"><div class="cv-chip"></div>
          <div class="cv-num" id="pvNum">•••• •••• •••• ••••</div>
          <div class="cv-bot">
            <div><div class="lbl">CARD HOLDER</div><div id="pvName">YOUR NAME</div></div>
            <div><div class="lbl">EXPIRES</div><div id="pvExp">MM/YY</div></div>
            <div><div class="lbl">NETWORK</div><div id="pvNet">VISA</div></div>
          </div>
        </div>
        <div class="fg"><label>CARD NUMBER</label><input id="cnum" placeholder="1234 5678 9012 3456" maxlength="19" oninput="fmtC(this);pvCard()"></div>
        <div class="fg"><label>CARD HOLDER NAME</label><input id="cname" placeholder="Name on card" oninput="pvCard()"></div>
        <div class="row2">
          <div class="fg"><label>EXPIRY</label><input id="cexp" placeholder="MM/YY" maxlength="5" oninput="fmtE(this);pvCard()"></div>
          <div class="fg"><label>CVV</label><input id="ccvv" type="password" placeholder="•••" maxlength="4"></div>
        </div>
        <button class="btn-pay" onclick="pay(this,'card')"><i class="fas fa-lock"></i>Pay Securely</button>
      </div>

      <div class="mc" id="mc-upi">
        <div class="upi-grid">
          <div class="upi-opt" onclick="sel(this,'.upi-opt')"><i class="fas fa-google"></i>Google Pay</div>
          <div class="upi-opt" onclick="sel(this,'.upi-opt')"><i class="fas fa-mobile-alt"></i>PhonePe</div>
          <div class="upi-opt" onclick="sel(this,'.upi-opt')"><i class="fas fa-wallet"></i>Paytm</div>
          <div class="upi-opt" onclick="sel(this,'.upi-opt')"><i class="fas fa-rupee-sign"></i>BHIM</div>
          <div class="upi-opt" onclick="sel(this,'.upi-opt')"><i class="fas fa-shopping-bag"></i>Amazon</div>
          <div class="upi-opt" onclick="sel(this,'.upi-opt')"><i class="fas fa-ellipsis-h"></i>Other</div>
        </div>
        <div class="fg"><label>UPI ID</label><input id="upiId" placeholder="yourname@upi"></div>
        <button class="btn-pay" onclick="pay(this,'upi')"><i class="fas fa-lock"></i>Verify & Pay</button>
      </div>

      <div class="mc" id="mc-bank">
        <div class="bank-list">
          <div class="bank-r" onclick="sel(this,'.bank-r')"><i class="fas fa-landmark"></i>State Bank of India</div>
          <div class="bank-r" onclick="sel(this,'.bank-r')"><i class="fas fa-landmark"></i>HDFC Bank</div>
          <div class="bank-r" onclick="sel(this,'.bank-r')"><i class="fas fa-landmark"></i>ICICI Bank</div>
          <div class="bank-r" onclick="sel(this,'.bank-r')"><i class="fas fa-landmark"></i>Axis Bank</div>
        </div>
        <button class="btn-pay" onclick="pay(this,'netbanking')"><i class="fas fa-university"></i>Proceed to Bank</button>
      </div>

      <div class="mc" id="mc-wallet">
        <div class="wallet-grid">
          <div class="w-opt" onclick="sel(this,'.w-opt')"><i class="fas fa-wallet"></i>Paytm</div>
          <div class="w-opt" onclick="sel(this,'.w-opt')"><i class="fas fa-mobile-alt"></i>Mobikwik</div>
          <div class="w-opt" onclick="sel(this,'.w-opt')"><i class="fas fa-shopping-bag"></i>Amazon Pay</div>
          <div class="w-opt" onclick="sel(this,'.w-opt')"><i class="fas fa-store"></i>Freecharge</div>
        </div>
        <div class="fg"><label>MOBILE NUMBER</label><input id="wPhone" placeholder="10-digit number" maxlength="10"></div>
        <button class="btn-pay" onclick="pay(this,'wallet')"><i class="fas fa-wallet"></i>Pay from Wallet</button>
      </div>
    </div>
  </div>

  <div class="pay-right">
    <div class="summary">
      <h3><i class="fas fa-receipt" style="color:var(--blue)"></i> Order Summary</h3>
      <div class="sum-plan"><div class="sum-icon"><i class="fas fa-bolt"></i></div><div><div class="sum-name" id="sPlanName">—</div><div class="sum-per">Monthly · Auto-renews</div></div></div>
      <div class="sum-row"><span>Plan Price</span><span id="sPrice">—</span></div>
      <div class="sum-row"><span>Tax (18% GST)</span><span id="sTax">—</span></div>
      <div class="sum-row total"><span>Total</span><span id="sTotal">—</span></div>
      <div class="badges">
        <div class="sec"><i class="fas fa-shield-alt"></i>SSL</div>
        <div class="sec"><i class="fas fa-lock"></i>PCI DSS</div>
        <div class="sec"><i class="fas fa-undo"></i>30-Day Refund</div>
      </div>
    </div>
  </div>
</div></div>

<!-- DASHBOARD -->
<div class="pg" id="pg-dashboard">
<div class="dash">
  <div class="dash-top">
    <div><h2>👋 Hello, <span id="dName">User</span>!</h2><p id="dDate"></p></div>
    <button class="btn-primary" onclick="go('plans')"><i class="fas fa-arrow-up"></i> Upgrade Plan</button>
  </div>
  <div class="d-grid">
    <div class="dc"><div class="dc-icon" style="background:#eff6ff"><i class="fas fa-crown" style="color:var(--blue)"></i></div><div class="dc-val" id="dPlan">—</div><div class="dc-lbl">Current Plan</div></div>
    <div class="dc"><div class="dc-icon" style="background:#ecfdf5"><i class="fas fa-calendar-check" style="color:var(--green)"></i></div><div class="dc-val" id="dDays">—</div><div class="dc-lbl">Days Left</div></div>
    <div class="dc"><div class="dc-icon" style="background:#fef3c7"><i class="fas fa-dollar-sign" style="color:#f59e0b"></i></div><div class="dc-val" id="dAmt">—</div><div class="dc-lbl">Last Payment</div></div>
    <div class="dc"><div class="dc-icon" style="background:#ecfdf5"><i class="fas fa-shield-alt" style="color:var(--green)"></i></div><div class="dc-val" style="color:var(--green)">Active</div><div class="dc-lbl">Account Status</div></div>
  </div>
  <div id="dSubSec" style="margin-bottom:22px"></div>
  <div class="info-grid" id="dInfo"><p style="color:var(--muted);font-size:.85rem"><i class="fas fa-spinner fa-spin"></i> Loading...</p></div>
</div></div>

<!-- MODAL -->
<div class="overlay" id="modal">
<div class="modal">
  <div style="font-size:1.8rem;margin-bottom:6px">🎉</div>
  <div class="modal-icon"><i class="fas fa-check"></i></div>
  <h2>Payment Successful!</h2>
  <p>You're now on the <strong id="mPlan"></strong> plan</p>
  <p>Transaction ID</p>
  <div class="txn" id="mTxn"></div>
  <button class="btn-full" onclick="closeModal()">Go to Dashboard</button>
</div></div>

<!-- TOAST -->
<div class="toast" id="toast"><i class="fas fa-check-circle"></i><span id="tMsg"></span></div>

<script>
let loggedIn=<?=json_encode($loggedIn)?>,uName=<?=json_encode($uname)?>;
let selPlan=null,selName='',selPrice=0,curTab='card';

window.onload=()=>{
  updNav();
  document.getElementById('dDate').textContent='Today: '+new Date().toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
};

function go(pg){
  document.querySelectorAll('.pg').forEach(p=>p.classList.remove('on'));
  document.getElementById('pg-'+pg).classList.add('on');
  window.scrollTo({top:0,behavior:'smooth'});
  if(pg==='plans')loadPlans();
  if(pg==='dashboard')loadDash();
}

function updNav(){
  document.getElementById('navGuest').style.display=loggedIn?'none':'inline';
  const nu=document.getElementById('navUser');
  nu.style.display=loggedIn?'flex':'none';
  if(loggedIn){
    document.getElementById('navAv').textContent=(uName[0]||'U').toUpperCase();
    document.getElementById('navName').textContent=uName.split(' ')[0];
  }
}

function sw(mode){
  document.getElementById('fLogin').style.display=mode==='login'?'block':'none';
  document.getElementById('fReg').style.display=mode==='reg'?'block':'none';
  document.getElementById('lerr').style.display=document.getElementById('rerr').style.display='none';
}

async function doLogin(btn){
  const u=document.getElementById('lu').value.trim(),p=document.getElementById('lp').value;
  if(!u||!p){showErr('lerr','Fill in all fields');return;}
  setBL(btn,1);
  const d=await post({action:'login',u,p});
  setBL(btn,0);
  if(d.err){showErr('lerr',d.err);return;}
  loggedIn=1;uName=d.name;updNav();toast('Welcome back, '+d.name.split(' ')[0]+'! 👋');go('home');
}

async function doReg(btn){
  const name=document.getElementById('rn').value.trim(),email=document.getElementById('re').value.trim(),
        uname=document.getElementById('ru').value.trim(),pass=document.getElementById('rp').value,phone=document.getElementById('rph').value.trim();
  if(!name||!email||!uname||!pass){showErr('rerr','Fill in all required fields');return;}
  setBL(btn,1);
  const d=await post({action:'register',name,email,phone,uname,pass});
  setBL(btn,0);
  if(d.err){showErr('rerr',d.err);return;}
  loggedIn=1;uName=d.name;updNav();toast('Account created! Welcome 🎉');go('home');
}

async function doLogout(){
  await post({action:'logout'});loggedIn=0;uName='';updNav();toast('Logged out');go('home');
}

async function loadPlans(){
  const g=document.getElementById('plansGrid');
  const plans=await post({action:'plans'});
  if(!Array.isArray(plans)||!plans.length){g.innerHTML='<p style="text-align:center;color:var(--muted)">No plans. Run database.sql first.</p>';return;}
  const em=['🌱','⚡','👑'];
  g.innerHTML=plans.map((p,i)=>`
    <div class="pc ${p.badge?'hot':''}">
      ${p.badge?`<div class="badge">${p.badge}</div>`:''}
      <div class="plan-emoji">${em[i]||'✦'}</div>
      <div class="plan-name">${p.name}</div>
      <div class="plan-amt"><sup>$</sup>${parseFloat(p.price).toFixed(2)}</div>
      <div class="plan-mo">per month</div>
      <ul class="plan-feats">${(p.features||'').split('|').map(f=>`<li><i class="fas fa-check-circle"></i>${f}</li>`).join('')}</ul>
      <button class="btn-plan" onclick="pickPlan(${p.id},'${p.name}',${p.price})">Choose ${p.name}</button>
    </div>`).join('');
}

function pickPlan(id,name,price){
  if(!loggedIn){toast('Please login first');go('auth');return;}
  selPlan=id;selName=name;selPrice=parseFloat(price);
  document.getElementById('sPlanName').textContent=name+' Plan';
  document.getElementById('sPrice').textContent='$'+price.toFixed(2);
  const tax=(price*.18).toFixed(2);
  document.getElementById('sTax').textContent='$'+tax;
  document.getElementById('sTotal').textContent='$'+(price+parseFloat(tax)).toFixed(2);
  go('payment');
}

function tab(m,el){
  curTab=m;
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('on'));
  document.querySelectorAll('.mc').forEach(c=>c.classList.remove('on'));
  el.classList.add('on');document.getElementById('mc-'+m).classList.add('on');
}

function sel(el,cls){document.querySelectorAll(cls).forEach(e=>e.classList.remove('on'));el.classList.add('on');}

function fmtC(el){let v=el.value.replace(/\D/g,'').slice(0,16);el.value=v.match(/.{1,4}/g)?.join(' ')||v;}
function fmtE(el){let v=el.value.replace(/\D/g,'');if(v.length>2)v=v.slice(0,2)+'/'+v.slice(2);el.value=v;}
function pvCard(){
  const n=document.getElementById('cnum').value||'•••• •••• •••• ••••';
  document.getElementById('pvNum').textContent=n;
  document.getElementById('pvName').textContent=(document.getElementById('cname').value||'YOUR NAME').toUpperCase();
  document.getElementById('pvExp').textContent=document.getElementById('cexp').value||'MM/YY';
  const r=n.replace(/\s/g,'');
  document.getElementById('pvNet').textContent=/^5[1-5]/.test(r)?'MASTERCARD':/^3[47]/.test(r)?'AMEX':/^6/.test(r)?'RUPAY':'VISA';
}

async function pay(btn,method){
  if(!selPlan){toast('Select a plan first');go('plans');return;}
  setBL(btn,1);await new Promise(r=>setTimeout(r,1800));
  const d=await post({action:'pay',pid:selPlan,method});
  setBL(btn,0);
  if(d.err){toast(d.err);return;}
  document.getElementById('mPlan').textContent=d.plan;
  document.getElementById('mTxn').textContent=d.txn;
  document.getElementById('modal').classList.add('on');
}

function closeModal(){document.getElementById('modal').classList.remove('on');go('dashboard');}

async function loadDash(){
  if(!loggedIn){go('auth');return;}
  document.getElementById('dName').textContent=uName.split(' ')[0];
  const d=await post({action:'dash'});
  const sub=d.sub,user=d.user;
  if(sub){
    const s=new Date(sub.start_date),e=new Date(sub.end_date),tot=Math.round((e-s)/864e5),used=Math.round((new Date()-s)/864e5),left=Math.max(0,tot-used),pct=Math.min(100,Math.round(used/tot*100));
    document.getElementById('dPlan').textContent=sub.pn;
    document.getElementById('dDays').textContent=left+'d';
    document.getElementById('dAmt').textContent='$'+parseFloat(sub.amount_paid).toFixed(2);
    document.getElementById('dSubSec').innerHTML=`
      <div class="sub-card">
        <div class="sub-info">
          <div class="sub-title">${sub.pn} Plan</div>
          <div class="status-dot"><span></span>Active</div>
          <div class="meta">
            <div class="meta-i"><label>STARTED</label><span>${sub.start_date}</span></div>
            <div class="meta-i"><label>RENEWS</label><span>${sub.end_date}</span></div>
            <div class="meta-i"><label>METHOD</label><span>${(sub.payment_method||'card').toUpperCase()}</span></div>
          </div>
          <div class="prog"><div class="prog-lbl"><span>Usage</span><span>${pct}%</span></div><div class="prog-bar"><div class="prog-fill" style="width:${pct}%"></div></div><div style="font-size:.73rem;color:var(--muted);margin-top:4px">${left} of ${tot} days remaining</div></div>
        </div>
        <div class="sub-right"><div class="sub-amt">$${parseFloat(sub.price).toFixed(2)}</div><div class="sub-mo">/month</div><button class="btn-plan" style="margin-top:14px;min-width:100px" onclick="go('plans')">Upgrade</button></div>
      </div>`;
  } else {
    document.getElementById('dPlan').textContent='None';
    document.getElementById('dDays').textContent='0';
    document.getElementById('dAmt').textContent='$0';
    document.getElementById('dSubSec').innerHTML=`<div class="no-sub"><i class="fas fa-gem"></i><h3>No Active Subscription</h3><p>Pick a plan to get started.</p><button class="btn-primary" onclick="go('plans')"><i class="fas fa-bolt"></i> Browse Plans</button></div>`;
  }
  if(user) document.getElementById('dInfo').innerHTML=`
    <div class="info-item"><label>FULL NAME</label><span>${user.full_name}</span></div>
    <div class="info-item"><label>EMAIL</label><span>${user.email}</span></div>
    <div class="info-item"><label>USERNAME</label><span>@${user.username}</span></div>
    <div class="info-item"><label>PHONE</label><span>${user.phone||'—'}</span></div>
    <div class="info-item"><label>MEMBER SINCE</label><span>${new Date(user.created_at).toLocaleDateString('en-US',{year:'numeric',month:'short'})}</span></div>`;
}

async function post(data){
  const fd=new FormData();for(const k in data)fd.append(k,data[k]);
  const r=await fetch('',{method:'POST',body:fd});return r.json();
}

function showErr(id,msg){const e=document.getElementById(id);e.textContent=msg;e.style.display='block';setTimeout(()=>e.style.display='none',4000);}
function setBL(btn,on){if(on){btn._h=btn.innerHTML;btn.innerHTML='<div class="spin"></div>';btn.disabled=1;}else{btn.innerHTML=btn._h;btn.disabled=0;}}
function toast(msg){const t=document.getElementById('toast');document.getElementById('tMsg').textContent=msg;t.classList.add('on');setTimeout(()=>t.classList.remove('on'),3000);}
sw('login');
</script>
</body>
</html>