<?php
session_start();
$host="localhost";
$dbuser="root";
$dbpass="";
$dbname="membervault";


mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $dbuser, $dbpass, $dbname);
if ($conn->connect_error) {
    die("DB Error: " . $conn->connect_error . "<br>Fix credentials in index.php: host=$host, user=$dbuser, pass=" . ($dbpass!=='' ? '***' : '(empty)') . ", db=$dbname");
}
$conn->set_charset("utf8mb4");

$msg=""; $msgok=false; $msgType='error';

function esc($v){ return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }
function logActivity($conn,$uid,$action,$desc=''){
  $ip=$_SERVER['REMOTE_ADDR']??'';
  $ua=substr($_SERVER['HTTP_USER_AGENT']??'',0,200);
  $s=$conn->prepare("INSERT INTO activity_log(user_id,action,description,ip_address,user_agent) VALUES(?,?,?,?,?)");
  $s->bind_param("issss",$uid,$action,$desc,$ip,$ua);
  $s->execute();
}
function notify($conn,$uid,$title,$message,$type='info'){
  $s=$conn->prepare("INSERT INTO notifications(user_id,title,message,type) VALUES(?,?,?,?)");
  $s->bind_param("isss",$uid,$title,$message,$type);
  $s->execute();
}
function genTxn(){ return 'TXN'.strtoupper(substr(md5(uniqid(rand(),true)),0,10)); }
function genInvoice(){ return 'INV-'.date('Y').'-'.strtoupper(substr(md5(uniqid()),0,6)); }

$plans = [];
$pr = $conn->query("SELECT p.*, GROUP_CONCAT(CONCAT(pf.feature,'|',pf.included) ORDER BY pf.id SEPARATOR ';;') AS features FROM plans p LEFT JOIN plan_features pf ON pf.plan_id=p.id WHERE p.is_active=1 GROUP BY p.id ORDER BY p.sort_order");
while($row=$pr->fetch_assoc()){
  $feats=[];
  if($row['features']){
    foreach(explode(';;',$row['features']) as $f){
      [$txt,$inc]=explode('|',$f);
      $feats[]=['text'=>$txt,'inc'=>(bool)$inc];
    }
  }
  $row['feature_list']=$feats;
  $plans[$row['slug']]=$row;
}

if(isset($_POST['register'])){
  $name=trim($_POST['name']);
  $email=trim($_POST['email']);
  $phone=trim($_POST['phone']);
  $pwd=$_POST['password'];
  $conf=$_POST['confirm_password']??'';
  if(strlen($pwd)<8){ $msg="Password must be at least 8 characters."; }
  elseif($pwd!==$conf){ $msg="Passwords do not match."; }
  else {
    $chk=$conn->prepare("SELECT id FROM users WHERE email=?");
    $chk->bind_param("s",$email);
    $chk->execute();
    $chk->store_result();
    if($chk->num_rows){ $msg="Email already registered. Please login."; }
    else {
      $ref='MV'.strtoupper(substr(md5($email.time()),0,8));
      $phash=password_hash($pwd,PASSWORD_DEFAULT);
      $s=$conn->prepare("INSERT INTO users(name,email,phone,password,referral_code) VALUES(?,?,?,?,?)");
      $s->bind_param("sssss",$name,$email,$phone,$phash,$ref);
      if($s->execute()){
        $uid=$conn->insert_id;
        notify($conn,$uid,'Welcome to MemberVault!','Your account was created successfully. Explore our plans to get started.','success');
        logActivity($conn,$uid,'register','New user registered');
        $_SESSION['uid']=$uid;
        $_SESSION['uname']=$name;
        header("Location: index.php");
        exit;
      } else $msg="Registration failed. Please try again.";
    }
  }
}

if(isset($_POST['login'])){
  $email=trim($_POST['email']);
  $s=$conn->prepare("SELECT id,name,password,status FROM users WHERE email=?");
  $s->bind_param("s",$email);
  $s->execute();
  $r=$s->get_result();
  $u=$r->fetch_assoc();
  if($u && password_verify($_POST['password'],$u['password'])){
    if($u['status']==='banned'){ $msg="Your account has been suspended. Contact support."; }
    else {
      $_SESSION['uid']=$u['id'];
      $_SESSION['uname']=$u['name'];
      $uid_upd=(int)$u['id'];
      $conn->query("UPDATE users SET last_login=NOW(),login_count=login_count+1 WHERE id=$uid_upd");
      logActivity($conn,$u['id'],'login','User logged in');
      header("Location: index.php");
      exit;
    }
  } else $msg="Invalid email or password.";
}

if(isset($_GET['logout'])){
  if(isset($_SESSION['uid'])) logActivity($conn,$_SESSION['uid'],'logout','User logged out');
  session_destroy();
  header("Location: index.php");
  exit;
}

$me=null; $myPayments=[]; $myNotifs=[]; $unreadCount=0; $myTickets=[];
if(isset($_SESSION['uid'])){
  $uid=$_SESSION['uid'];
  $r=$conn->query("SELECT * FROM users WHERE id=$uid");
  if($r && $r->num_rows > 0){
    $me=$r->fetch_assoc();
  } else {
    $me = null;
  }
  if(!$me){
    session_destroy();
    header("Location: index.php");
    exit;
  }
  $r2=$conn->query("SELECT p.*,pl.color as plan_color FROM payments p LEFT JOIN plans pl ON LOWER(pl.slug)=LOWER(p.plan) WHERE p.user_id=$uid ORDER BY p.id DESC");
  while($row=$r2->fetch_assoc()) $myPayments[]=$row;
  $r3=$conn->query("SELECT * FROM notifications WHERE user_id=$uid ORDER BY id DESC LIMIT 10");
  while($row=$r3->fetch_assoc()) $myNotifs[]=$row;
  $unreadCount=(int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
  $r4=$conn->query("SELECT * FROM support_tickets WHERE user_id=$uid ORDER BY id DESC LIMIT 20");
  while($row=$r4->fetch_assoc()) $myTickets[]=$row;
}

if(isset($_POST['pay'])&&isset($_SESSION['uid'])){
  $uid=$_SESSION['uid'];
  $planSlug=strtolower(trim($_POST['plan']));
  $cycle=$_POST['billing_cycle']??'monthly';
  $planRow=$plans[$planSlug]??null;
  if($planRow){
    $amt=$cycle==='annual'?$planRow['price_annual']:$planRow['price_monthly'];
    $couponCode=trim($_POST['coupon']??'');
    $discountAmt=0;
    if($couponCode){
      $cp=$conn->prepare("SELECT * FROM coupons WHERE code=? AND is_active=1 AND (valid_until IS NULL OR valid_until>=NOW()) AND (max_uses IS NULL OR used_count<max_uses)");
      $cp->bind_param("s",$couponCode);
      $cp->execute();
      $cpRow=$cp->get_result()->fetch_assoc();
      if($cpRow){
        $discountAmt=$cpRow['discount_type']==='percent' ? round($amt*$cpRow['discount_val']/100,2) : min($cpRow['discount_val'],$amt);
        $amt=max(0,$amt-$discountAmt);
        $cpid=(int)$cpRow['id'];
        $conn->query("UPDATE coupons SET used_count=used_count+1 WHERE id=$cpid");
      }
    }
    $method=$_POST['method']??'card';
    $card=''; $upi=''; $bank=''; $cardType='';
    if($method==='card'){
      $raw=preg_replace('/\s/','',$_POST['card']??'');
      $card='••••••••••••'.substr($raw,-4);
      $cardType=substr($raw,0,1)==='4'?'Visa':(substr($raw,0,1)==='5'?'Mastercard':'Card');
    }
    if($method==='upi') $upi=trim($_POST['upi']??'');
    if($method==='netbanking') $bank=trim($_POST['bank']??'');
    $txn=genTxn();
    $inv=genInvoice();
    $tax=round($amt*0.18,2);
    $total=round($amt+$tax,2);
    $expires=date('Y-m-d H:i:s',strtotime(($cycle==='annual'?'+1 year':'+1 month')));
    $s=$conn->prepare("INSERT INTO payments(user_id,plan_id,plan,billing_cycle,amount,pay_method,card_masked,card_type,upi_id,bank_name,txn_id,invoice_no,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'success')");
    $s->bind_param("iissdsssssss",$uid,$planRow['id'],$planRow['name'],$cycle,$total,$method,$card,$cardType,$upi,$bank,$txn,$inv);
    if($s->execute()){
      $payId=$conn->insert_id;
      $si=$conn->prepare("INSERT INTO invoices(payment_id,user_id,invoice_no,amount,tax_amount,total) VALUES(?,?,?,?,?,?)");
      $si->bind_param("iisddd",$payId,$uid,$inv,$amt,$tax,$total);
      $si->execute();
      $planName=$conn->real_escape_string($planRow['name']);
      $conn->query("UPDATE users SET plan='$planName',plan_expires='$expires' WHERE id=$uid");
      $me['plan']=$planRow['name'];
      $me['plan_expires']=$expires;
      notify($conn,$uid,'Payment Successful','Your '.ucfirst($cycle).' '.($planRow['name']).' plan is now active. TXN: '.$txn,'success');
      logActivity($conn,$uid,'payment','Paid ₹'.number_format($total,2).' for '.$planRow['name'].' plan');
      $msg="Payment successful! Invoice: $inv  |  TXN: $txn";
      $msgok=true;
      $msgType='success';
      $myPayments=[];
      $r2=$conn->query("SELECT p.*,pl.color as plan_color FROM payments p LEFT JOIN plans pl ON LOWER(pl.slug)=LOWER(p.plan) WHERE p.user_id=$uid ORDER BY p.id DESC");
      while($row=$r2->fetch_assoc()) $myPayments[]=$row;
    } else { $msg="Payment processing failed. Please try again."; }
  } else $msg="Invalid plan selected.";
}

if(isset($_POST['update_profile'])&&isset($_SESSION['uid'])){
  $uid=$_SESSION['uid'];
  $name=trim($_POST['name']);
  $phone=trim($_POST['phone']);
  $dob=$_POST['dob']??'';
  $gender=$_POST['gender']??'';
  $city=trim($_POST['city']??'');
  $country=trim($_POST['country']??'');
  $s=$conn->prepare("UPDATE users SET name=?,phone=?,dob=?,gender=?,city=?,country=? WHERE id=?");
  $s->bind_param("ssssssi",$name,$phone,$dob,$gender,$city,$country,$uid);
  if($s->execute()){
    $_SESSION['uname']=$name;
    $me['name']=$name;
    $msg="Profile updated successfully!";
    $msgok=true;
    $msgType='success';
    logActivity($conn,$uid,'profile_update','User updated profile');
  } else $msg="Failed to update profile.";
}

if(isset($_POST['change_password'])&&isset($_SESSION['uid'])){
  $uid=$_SESSION['uid'];
  $cur=$_POST['current_password']??'';
  $new=$_POST['new_password']??'';
  $conf=$_POST['confirm_new']??'';
  $r=$conn->query("SELECT password FROM users WHERE id=$uid")->fetch_assoc();
  if(!password_verify($cur,$r['password'])){ $msg="Current password is incorrect."; }
  elseif(strlen($new)<8){ $msg="New password must be at least 8 characters."; }
  elseif($new!==$conf){ $msg="New passwords do not match."; }
  else {
    $hash=password_hash($new,PASSWORD_DEFAULT);
    $s=$conn->prepare("UPDATE users SET password=? WHERE id=?");
    $s->bind_param("si",$hash,$uid);
    $s->execute();
    $msg="Password changed successfully!";
    $msgok=true;
    $msgType='success';
    logActivity($conn,$uid,'password_change','Password updated');
  }
}

if(isset($_POST['submit_ticket'])&&isset($_SESSION['uid'])){
  $uid=$_SESSION['uid'];
  $sub=trim($_POST['subject']??'');
  $body=trim($_POST['message']??'');
  $prio=$_POST['priority']??'medium';
  if($sub&&$body){
    $s=$conn->prepare("INSERT INTO support_tickets(user_id,subject,message,priority) VALUES(?,?,?,?)");
    $s->bind_param("isss",$uid,$sub,$body,$prio);
    if($s->execute()){
      $msg="Ticket submitted! We will respond within 24 hours.";
      $msgok=true;
      $msgType='success';
      notify($conn,$uid,'Ticket Opened','Your support ticket "'.$sub.'" has been received.','info');
      $r4=$conn->query("SELECT * FROM support_tickets WHERE user_id=$uid ORDER BY id DESC LIMIT 20");
      $myTickets=[];
      while($row=$r4->fetch_assoc()) $myTickets[]=$row;
    } else $msg="Failed to submit ticket.";
  } else $msg="Please fill in all fields.";
}

if(isset($_GET['mark_read'])&&isset($_SESSION['uid'])){
  $conn->query("UPDATE notifications SET is_read=1 WHERE user_id=".(int)$_SESSION['uid']);
  header("Location: index.php?p=".($_GET['p']??'dashboard'));
  exit;
}

if(isset($_POST['check_coupon'])&&isset($_SESSION['uid'])){
  $code=trim($_POST['check_coupon']);
  $cp=$conn->prepare("SELECT * FROM coupons WHERE code=? AND is_active=1 AND (valid_until IS NULL OR valid_until>=NOW()) AND (max_uses IS NULL OR used_count<max_uses)");
  $cp->bind_param("s",$code);
  $cp->execute();
  $cpRow=$cp->get_result()->fetch_assoc();
  echo json_encode($cpRow?['valid'=>true,'type'=>$cpRow['discount_type'],'val'=>$cpRow['discount_val'],'desc'=>$cpRow['description']]:['valid'=>false]);
  exit;
}

$page=$_GET['p']??'dashboard';
$totalSpent=array_sum(array_column($myPayments,'amount'));
$currentPlanData=$plans[strtolower($me['plan']??'free')]??null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MemberVault — Premium Membership Platform</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
[data-theme="light"]{
  --bg:#f0f8ff;
  --bg2:#e4f1fc;
  --bg3:#d9ecfa;
  --card:#ffffff;
  --card2:#eaf4fd;
  --border:#b8d9f4;
  --border2:#8fc4e8;
  --txt:#0a1f33;
  --txt2:#2d6a9f;
  --txt3:#6ba3c8;
  --pri:#0284c7;
  --pri2:#0369a1;
  --pri3:#075985;
  --acc:#0ea5e9;
  --acc2:#38bdf8;
  --grn:#059669;
  --grn2:#047857;
  --red:#dc2626;
  --amber:#d97706;
  --cyan:#0891b2;
  --glow:rgba(2,132,199,.18);
  --glow2:rgba(14,165,233,.14);
  --shadow:0 4px 24px rgba(0,100,180,.12);
  --shadow2:0 8px 48px rgba(0,100,180,.18);
}
[data-theme="dark"]{
  --bg:#0f1117;
  --bg2:#161b22;
  --bg3:#1c2128;
  --card:#21262d;
  --card2:#30363d;
  --border:#30363d;
  --border2:#444c56;
  --txt:#e6edf3;
  --txt2:#8b949e;
  --txt3:#6e7681;
  --pri:#f97316;
  --pri2:#ea580c;
  --pri3:#c2410c;
  --acc:#0ea5e9;
  --acc2:#06b6d4;
  --grn:#3fb950;
  --grn2:#238636;
  --red:#f85149;
  --amber:#d29922;
  --cyan:#79c0ff;
  --glow:rgba(249,115,22,.2);
  --glow2:rgba(14,165,233,.15);
  --shadow:0 4px 24px rgba(0,0,0,.6);
  --shadow2:0 8px 48px rgba(0,0,0,.7);
}
:root{
  --font:'Outfit',sans-serif;
  --mono:'JetBrains Mono',monospace;
  --r:14px;
  --r2:20px;
  --r3:28px;
}
*{margin:0;padding:0;box-sizing:border-box;font-family:var(--font);}
/* Ensure FontAwesome icons always render centered */
.fa,.fas,.far,.fab,.fal,.fad{line-height:1!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}
body{background:var(--bg);color:var(--txt);min-height:100vh;overflow-x:hidden;transition:background .25s,color .25s;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;}
a{text-decoration:none;color:inherit;}
button{cursor:pointer;font-family:var(--font);}
input,select,textarea{font-family:var(--font);}
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:var(--bg2);}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:4px;}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
@keyframes glow-pulse{0%,100%{box-shadow:0 0 20px var(--glow)}50%{box-shadow:0 0 40px rgba(249,115,22,.4)}}
@keyframes slide-in{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes slideDown{from{transform:translateY(-20px);opacity:0}to{transform:translateY(0);opacity:1}}
@keyframes rotateIn{from{transform:rotate(-10deg) scale(.95);opacity:0}to{transform:rotate(0) scale(1);opacity:1}}
@keyframes bounceIn{0%{transform:scale(.3);opacity:0}50%{opacity:1}100%{transform:scale(1)}}
@keyframes slideInTitle{0%{opacity:0;transform:translateY(30px);letter-spacing:2px}100%{opacity:1;transform:translateY(0);letter-spacing:-1.5px}}
@keyframes scaleIn{from{opacity:0;transform:scale(.85)}to{opacity:1;transform:scale(1)}}
@keyframes slideInLeft{from{opacity:0;transform:translateX(-30px)}to{opacity:1;transform:translateX(0)}}
@keyframes slideInRight{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
@keyframes scaleYIn{from{transform:scaleY(0)}to{transform:scaleY(1)}}
@keyframes slideUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
@keyframes slideInUp{from{opacity:0;transform:translateY(40px);filter:blur(4px)}to{opacity:1;transform:translateY(0);filter:blur(0)}}

.auth-page{min-height:100vh;display:flex;align-items:stretch;background:var(--bg);position:relative;overflow:hidden;flex-wrap:wrap;}
.auth-left{flex:1;display:flex;flex-direction:column;justify-content:center;padding:80px 60px;position:relative;background:var(--bg);}
.auth-left::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 80% 60% at 25% 35%,rgba(249,115,22,.08) 0%,transparent 60%),radial-gradient(ellipse 60% 50% at 75% 65%,rgba(249,115,22,.05) 0%,transparent 60%);}
.modern-hero{position:relative;z-index:1;}
.auth-hero h1 .hl{background:linear-gradient(90deg,var(--pri),var(--acc));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}



.modern-hero{position:relative;z-index:1;}
.hero-label{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--pri);margin-bottom:24px;display:inline-block;}
.hero-title{font-size:clamp(2.8rem,6vw,4.5rem);font-weight:900;line-height:1.15;margin-bottom:32px;color:var(--txt);letter-spacing:-1.5px;}
.hero-title .title-word{display:block;animation:fadeUp .7s ease-out both;}
.hero-title .title-word:nth-child(2){animation-delay:.15s;}
.hero-title .title-word:nth-child(3){animation-delay:.3s;background:linear-gradient(135deg,var(--pri),#ea580c);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.hero-desc{font-size:clamp(1rem,2vw,1.2rem);color:var(--txt2);line-height:1.8;max-width:540px;margin-bottom:52px;animation:fadeUp .8s ease-out .2s both;}
.hero-grid{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-bottom:60px;max-width:540px;}
.hero-card{padding:40px 32px;background:var(--card);border:1px solid var(--border);border-radius:var(--r2);text-align:center;transition:all .4s cubic-bezier(.34,1.56,.64,1);position:relative;overflow:hidden;}
.hero-card::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--pri) 0%,transparent 100%);opacity:0;transition:opacity .4s;pointer-events:none;}
.hero-card:hover{border-color:var(--pri);transform:translateY(-12px);box-shadow:0 20px 48px rgba(249,115,22,.15);}
.hero-card:hover::before{opacity:.08;}
.hero-card-icon{font-size:2rem;color:var(--pri);margin-bottom:16px;animation:bounce 2s ease-in-out infinite;}
.hero-card-stat{font-size:2.2rem;font-weight:900;color:var(--pri);margin-bottom:8px;}
.hero-card-text{font-size:.85rem;color:var(--txt2);text-transform:uppercase;letter-spacing:.5px;font-weight:600;}
.hero-features{display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-top:60px;max-width:600px;}
.hero-feature{padding:32px;background:var(--card);border-radius:var(--r2);position:relative;border:1px solid var(--border);transition:all .4s;}
.hero-feature::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:var(--pri);border-radius:var(--r2);transform:scaleY(0);transform-origin:top;animation:scaleYIn .6s ease-out forwards;}
.hero-feature:nth-child(2)::before{animation-delay:.2s;}
.hero-feature:hover{transform:translateY(-8px);border-color:var(--pri);box-shadow:0 12px 32px rgba(249,115,22,.12);}
.feature-mark{width:6px;height:6px;background:var(--pri);border-radius:50%;display:inline-block;margin-right:8px;}
.hero-feature h3{font-size:1.1rem;font-weight:700;color:var(--txt);margin-bottom:12px;}
.hero-feature p{font-size:.85rem;color:var(--txt2);line-height:1.6;}
.auth-right{width:480px;display:flex;flex-direction:column;justify-content:center;padding:60px 52px;background:var(--bg3);border-left:1px solid var(--border);position:relative;}
.auth-form-head{margin-bottom:32px;}
.auth-form-head h2{font-size:1.6rem;font-weight:800;letter-spacing:-.5px;}
.auth-form-head p{color:var(--txt2);font-size:.88rem;margin-top:6px;}
.auth-tabs{display:flex;gap:0;background:var(--card);border-radius:var(--r);padding:4px;margin-bottom:28px;border:1px solid var(--border);}
.auth-tab{flex:1;padding:9px 16px;text-align:center;border-radius:10px;font-size:.88rem;font-weight:600;color:var(--txt3);transition:all .25s;cursor:pointer;border:none;background:none;}
.auth-tab.on{background:var(--pri);color:#fff;box-shadow:0 2px 12px var(--glow);}
.form-group{margin-bottom:12px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.lbl{display:block;font-size:.65rem;font-weight:700;color:var(--txt2);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.inp{width:100%;padding:11px 14px;background:var(--card);border:1.5px solid var(--border);border-radius:var(--r);color:var(--txt);font-size:.9rem;outline:none;transition:all .2s;min-height:44px;-webkit-appearance:none;}
.inp:focus{border-color:var(--pri);background:var(--card2);box-shadow:0 0 0 3px var(--glow);}
.inp::placeholder{color:var(--txt3);}
.inp-icon{position:relative;}
.inp-icon .inp{padding-left:40px;}
.inp-icon .ico{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--txt3);font-size:.9rem;}
.btn-primary{
width:100%;padding:13px;background:linear-gradient(135deg,var(--pri),var(--acc));color:#fff;border:none;border-radius:var(--r);font-size:.95rem;font-weight:700;letter-spacing:.3px;transition:all .25s;box-shadow:0 4px 20px var(--glow);margin-top:6px;min-height:44px;-webkit-appearance:none;cursor:pointer;position:relative;overflow:hidden;
}
.btn-primary::before{
content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.3),transparent);animation:shimmerMove 3s infinite;
}
.btn-primary:hover{
transform:translateY(-2px);box-shadow:0 8px 28px var(--glow);
}
.amsg{padding:12px 14px;border-radius:var(--r);font-size:.84rem;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.amsg.ok{background:rgba(16,217,168,.12);color:var(--grn);border:1px solid rgba(16,217,168,.2);}
.amsg.er{background:rgba(220,38,38,.1);color:var(--red);border:1px solid rgba(220,38,38,.2);}
.pass-strength{height:3px;border-radius:2px;background:var(--border);margin-top:6px;overflow:hidden;}
.pass-strength-bar{height:100%;border-radius:2px;transition:width .3s,background .3s;}

.app{display:flex;min-height:100vh;overflow-x:hidden;}
.sidebar{width:240px;background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;transition:transform .3s,background .25s;}
.sidebar-logo{display:flex;align-items:center;gap:10px;padding:22px 20px 18px;border-bottom:1px solid var(--border);}
.logo-icon{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--pri),var(--acc));display:flex;align-items:center;justify-content:center;font-size:1rem;box-shadow:0 0 14px var(--glow);}
.logo-name{font-size:1.05rem;font-weight:800;letter-spacing:-.4px;}
.logo-name span{color:var(--pri);}
.nav-section{padding:10px 12px 4px;font-size:.62rem;font-weight:700;color:var(--txt3);text-transform:uppercase;letter-spacing:1.2px;}
.nav-item{
display:flex;align-items:center;gap:10px;padding:10px 16px;margin:1px 8px;border-radius:var(--r);font-size:.875rem;font-weight:500;color:var(--txt2);cursor:pointer;transition:all .3s cubic-bezier(.34,.1,.64,.1);position:relative;min-height:44px;border:1px solid transparent;
}
.nav-item:hover{
background:var(--card);color:var(--txt);border-color:var(--border);
transform:translateX(4px);
}
.nav-item.on{
background:linear-gradient(135deg,var(--glow),var(--glow2));color:var(--pri);font-weight:600;border-color:var(--pri);
}
[data-theme="dark"] .nav-item.on{background:rgba(107,114,128,.15);}
.nav-item.on::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;border-radius:0 2px 2px 0;background:var(--pri);}
.nav-item .ni{font-size:1rem;width:24px;text-align:center;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
.nav-item span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.nav-badge{margin-left:auto;background:var(--red);color:#fff;font-size:.6rem;font-weight:700;padding:2px 7px;border-radius:50px;min-width:18px;text-align:center;}
.sidebar-plan{margin:12px 10px;padding:14px;background:linear-gradient(135deg,var(--glow),var(--glow2));border:1px solid var(--border2);border-radius:var(--r2);}
.sp-label{font-size:.62rem;font-weight:700;color:var(--txt3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;}
.sp-plan{font-size:.9rem;font-weight:700;color:var(--pri);}
.sp-expires{font-size:.68rem;color:var(--txt3);margin-top:2px;}
.sp-upgrade{
display:block;margin-top:10px;padding:8px;text-align:center;background:linear-gradient(135deg,var(--pri),var(--acc));color:#fff;border-radius:9px;font-size:.75rem;font-weight:700;transition:all .3s!important;border:none;position:relative;overflow:hidden;
}
.sp-upgrade::before{
content:'';position:absolute;inset:0;background:rgba(255,255,255,.2);transform:translateX(-100%);transition:transform .6s;
}
.sp-upgrade:hover{
transform:translateY(-2px) scale(1.05);box-shadow:0 6px 20px var(--glow);filter:brightness(1.1);
}
.sp-upgrade:hover::before{transform:translateX(100%)}
.sidebar-user{padding:14px 16px;border-top:1px solid var(--border);margin-top:auto;display:flex;align-items:center;gap:10px;}
.user-ava{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--acc),var(--pri));display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;flex-shrink:0;color:#fff;}
.user-info{flex:1;min-width:0;}
.user-info .un{font-size:.82rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.user-info .ue{font-size:.66rem;color:var(--txt3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.btn-logout{background:rgba(220,38,38,.1);color:var(--red);border:none;padding:6px 8px;border-radius:8px;font-size:.75rem;font-weight:600;transition:all .2s;white-space:nowrap;}
.btn-logout:hover{background:rgba(220,38,38,.2);}
.topbar{height:60px;background:var(--bg2);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 24px 0 16px;position:sticky;top:0;z-index:90;gap:12px;transition:background .25s;width:100%;box-sizing:border-box;}
.topbar-title{font-size:1rem;font-weight:700;flex:1;}
.topbar-actions{display:flex;align-items:center;gap:8px;}
.topbar-btn{width:36px;height:36px;border-radius:10px;background:var(--card);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:.9rem;cursor:pointer;transition:all .2s;position:relative;}
.topbar-btn:hover{background:var(--card2);border-color:var(--border2);}
.notif-dot{position:absolute;top:6px;right:6px;width:8px;height:8px;border-radius:50%;background:var(--red);border:2px solid var(--bg2);}
.hamburger{display:none;flex-direction:column;gap:4px;cursor:pointer;padding:6px;}
.hamburger span{display:block;width:20px;height:2px;background:var(--txt2);border-radius:2px;transition:all .3s;}
.content{margin-left:240px;width:calc(100% - 240px);min-height:100vh;display:flex;flex-direction:column;overflow-x:hidden;}
.page-wrap{padding:28px;flex:1;animation:fadeUp .35s ease;box-sizing:border-box;width:100%;}

.dash-hero{background:linear-gradient(135deg,#0c1a2e 0%,#0d2244 40%,#071830 100%);border-radius:24px;padding:40px 36px;position:relative;overflow:hidden;margin-bottom:36px;animation:slideDown .6s ease-out;border:1px solid rgba(14,165,233,.15);width:100%;box-sizing:border-box;}
.dash-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 100% 80% at 20% 30%,rgba(14,165,233,.18) 0%,transparent 60%),radial-gradient(ellipse 80% 60% at 85% 70%,rgba(56,189,248,.1) 0%,transparent 60%);pointer-events:none;border-radius:24px;}
.hero-gradient-bg{position:absolute;inset:0;overflow:hidden;pointer-events:none;border-radius:24px;}
.hero-gradient-bg::before{content:'';position:absolute;width:200%;height:200%;top:-50%;left:-50%;background:radial-gradient(circle,rgba(14,165,233,.06) 1px,transparent 1px);background-size:50px 50px;animation:float 20s linear infinite;border-radius:24px;}
.hero-content{position:relative;z-index:2;margin-bottom:42px;}
.hero-greet{font-size:1rem;font-weight:600;color:rgba(148,210,240,.8);margin-bottom:12px;display:flex;align-items:center;gap:8px;}
.hero-name{color:#38bdf8;font-weight:800;}
.hero-main{font-size:clamp(2rem,4.5vw,3.2rem);font-weight:900;line-height:1.15;color:#e0f2fe;margin-bottom:14px;letter-spacing:-1px;animation:slideUp .6s ease-out}
.hero-sub{font-size:1.05rem;color:rgba(148,210,240,.75);max-width:480px;line-height:1.6;animation:slideUp .7s ease-out .1s both;}
.hero-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;position:relative;z-index:2;width:100%;}
.hstat{background:rgba(14,165,233,.08);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border:1px solid rgba(14,165,233,.2);border-radius:16px;padding:22px 16px;text-align:center;transition:all .4s cubic-bezier(.34,1.56,.64,1);cursor:pointer;min-width:0;word-break:break-word;}
.hstat:hover{transform:translateY(-8px);box-shadow:0 16px 40px rgba(0,0,0,.3);}
.hstat.c1{border-color:rgba(249,115,22,.35);}.hstat.c1:hover{background:rgba(249,115,22,.15);border-color:var(--pri);box-shadow:0 16px 40px rgba(249,115,22,.2);}
.hstat.c2{border-color:rgba(14,165,233,.35);}.hstat.c2:hover{background:rgba(14,165,233,.15);border-color:#38bdf8;box-shadow:0 16px 40px rgba(14,165,233,.2);}
.hstat.c3{border-color:rgba(63,185,80,.35);}.hstat.c3:hover{background:rgba(63,185,80,.15);border-color:var(--grn);box-shadow:0 16px 40px rgba(63,185,80,.2);}
.hstat.c4{border-color:rgba(217,119,6,.35);}.hstat.c4:hover{background:rgba(217,119,6,.15);border-color:var(--amber);box-shadow:0 16px 40px rgba(217,119,6,.2);}
.hs-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin:0 auto 14px;color:#fff;flex-shrink:0;}
.hstat.c1 .hs-icon{background:linear-gradient(135deg,#f97316,#ea580c);box-shadow:0 6px 16px rgba(249,115,22,.4);}
.hstat.c2 .hs-icon{background:linear-gradient(135deg,#0ea5e9,#06b6d4);box-shadow:0 6px 16px rgba(14,165,233,.4);}
.hstat.c3 .hs-icon{background:linear-gradient(135deg,#22c55e,#16a34a);box-shadow:0 6px 16px rgba(34,197,94,.4);}
.hstat.c4 .hs-icon{background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 6px 16px rgba(245,158,11,.4);}
.hstat.c1 .hs-val{color:#fb923c;}
.hstat.c2 .hs-val{color:#38bdf8;}
.hstat.c3 .hs-val{color:#4ade80;}
.hstat.c4 .hs-val{color:#fbbf24;}
.hs-val{font-size:1.8rem;font-weight:900;margin-bottom:6px;letter-spacing:-.5px;}
.hs-lbl{font-size:.72rem;color:rgba(148,210,240,.7);text-transform:uppercase;letter-spacing:.6px;font-weight:600;}

.page-header{margin-bottom:28px;}
.page-title{font-size:1.5rem;font-weight:800;letter-spacing:-.5px;display:flex;align-items:center;gap:10px;}
.page-title .ti{width:38px;height:38px;border-radius:11px;background:var(--glow);display:flex;align-items:center;justify-content:center;font-size:1rem;line-height:1;}
.page-title .ti i{display:block;line-height:1;}
.page-sub{color:var(--txt2);font-size:.9rem;margin-top:4px;margin-left:48px;}

.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
.stat-card{background:linear-gradient(135deg,var(--card),var(--card2));border:1px solid var(--border);border-radius:var(--r2);padding:22px;position:relative;overflow:hidden;transition:all .3s cubic-bezier(.34,.1,.64,.1);}
.stat-card:hover{border-color:var(--pri);transform:translateY(-4px) scale(1.02);box-shadow:0 12px 32px var(--glow);}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r2) var(--r2) 0 0;}
.stat-card.c1::before{background:linear-gradient(90deg,var(--pri),var(--acc));}
.stat-card.c2::before{background:linear-gradient(90deg,var(--grn),var(--cyan));}
.stat-card.c3::before{background:linear-gradient(90deg,var(--amber),#f97316);}
.stat-card.c4::before{background:linear-gradient(90deg,var(--red),#ec4899);}
.stat-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:14px;animation:rotateIn .6s ease-out;flex-shrink:0;line-height:1;}
.stat-icon i{display:block;line-height:1;}
.c1 .stat-icon{background:linear-gradient(135deg,rgba(249,115,22,.25),rgba(14,165,233,.25));}
.c2 .stat-icon{background:linear-gradient(135deg,rgba(16,217,168,.25),rgba(6,182,212,.25));}
.c3 .stat-icon{background:linear-gradient(135deg,rgba(245,158,11,.25),rgba(249,115,22,.25));}
.c4 .stat-icon{background:linear-gradient(135deg,rgba(220,38,38,.25),rgba(236,72,153,.25));}
.stat-val{font-size:1.7rem;font-weight:800;letter-spacing:-.5px;line-height:1;}
.stat-lbl{font-size:.72rem;color:var(--txt2);font-weight:600;text-transform:uppercase;letter-spacing:.8px;margin-top:6px;}
.stat-trend{font-size:.72rem;margin-top:8px;display:flex;align-items:center;gap:4px;}
.trend-up{color:var(--grn);}
.trend-down{color:var(--red);}

.section-card{
  background:linear-gradient(135deg,var(--card),var(--card2));border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;margin-bottom:20px;transition:all .3s cubic-bezier(.34,.1,.64,.1);animation:slideDown .6s ease-out;
}\n.section-card:hover{\n  border-color:var(--pri);box-shadow:0 8px 24px var(--glow);transform:translateY(-2px);\n}\n.section-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--bg3);}
.section-head h3{font-size:.95rem;font-weight:700;background:linear-gradient(135deg,var(--txt),var(--acc));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.section-body{padding:22px;}

.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
table{width:100%;border-collapse:collapse;min-width:400px;}
thead tr{border-bottom:1px solid var(--border);}
th{padding:11px 16px;font-size:.68rem;font-weight:700;color:var(--txt3);text-transform:uppercase;letter-spacing:.8px;text-align:left;background:var(--bg3);white-space:nowrap;}
td{padding:13px 16px;font-size:.85rem;border-bottom:1px solid var(--border);}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--glow);color:var(--txt);}
.empty-row td{text-align:center;padding:36px;color:var(--txt3);}
.empty-row .empty-icon{font-size:2rem;display:block;margin-bottom:8px;}

.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:50px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px;transition:all .3s;}
.badge:hover{transform:scale(1.05);}
.badge-free{background:rgba(107,114,128,.2);color:var(--txt2);}
.badge-starter{background:rgba(14,165,233,.15);color:var(--acc);}
.badge-pro{background:rgba(124,58,237,.15);color:#a78bfa;}
.badge-business{background:linear-gradient(135deg,rgba(249,115,22,.25),rgba(217,119,6,.15));color:var(--amber);}
.badge-enterprise{background:rgba(248,81,73,.15);color:var(--red);}
.badge-success{background:rgba(63,185,80,.15);color:var(--grn);}
.badge-pending{background:rgba(217,119,6,.15);color:var(--amber);}
.badge-failed{background:rgba(220,38,38,.1);color:var(--red);}
.badge-open{background:rgba(8,145,178,.1);color:var(--cyan);}
.badge-resolved{background:rgba(5,150,105,.1);color:var(--grn);}
.badge-card{background:var(--glow);color:var(--pri);}
.badge-upi{background:rgba(5,150,105,.1);color:var(--grn);}
.badge-netbanking{background:rgba(217,119,6,.1);color:var(--amber);}

.billing-toggle{display:flex;align-items:center;gap:12px;margin-bottom:28px;background:var(--card);border:1px solid var(--border);border-radius:50px;padding:4px;width:fit-content;}
.billing-opt{padding:8px 22px;border-radius:50px;font-size:.85rem;font-weight:600;cursor:pointer;transition:all .25s;border:none;background:none;color:var(--txt2);}
.billing-opt.on{background:var(--pri);color:#fff;box-shadow:0 2px 12px var(--glow);}
.save-badge{background:rgba(5,150,105,.12);color:var(--grn);font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:50px;border:1px solid rgba(5,150,105,.2);}
.plans-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;margin-bottom:28px;}
.plan-card{background:var(--card);border:2px solid var(--border);border-radius:var(--r2);padding:26px 22px;transition:all .3s;position:relative;overflow:hidden;display:flex;flex-direction:column;}
.plan-card:hover{transform:translateY(-4px);box-shadow:var(--shadow2);}
.plan-card.popular{border-color:var(--pri);background:var(--glow);}
.plan-card.popular::before{content:'MOST POPULAR';position:absolute;top:14px;right:-28px;background:linear-gradient(90deg,var(--pri),var(--acc));color:#fff;font-size:.58rem;font-weight:800;letter-spacing:1px;padding:4px 36px;transform:rotate(45deg);}
.plan-card.current{border-color:var(--grn);}
.plan-icon{font-size:2rem;margin-bottom:12px;display:block;}
.plan-name{font-size:1.05rem;font-weight:800;margin-bottom:4px;}
.plan-price-wrap{margin:14px 0;}
.plan-price{font-size:2.2rem;font-weight:900;letter-spacing:-1px;line-height:1;}
.plan-price sup{font-size:.9rem;font-weight:700;vertical-align:top;margin-top:6px;}
.plan-price-period{font-size:.75rem;color:var(--txt3);display:block;margin-top:4px;}
.plan-price-annual{font-size:.72rem;color:var(--grn);margin-bottom:12px;}
.plan-divider{height:1px;background:var(--border);margin:14px 0;}
.plan-features{list-style:none;flex:1;}
.plan-features li{font-size:.82rem;color:var(--txt2);padding:5px 0;display:flex;align-items:center;gap:8px;}
.plan-features li .fi{font-size:.8rem;flex-shrink:0;}
.plan-features li .fi.yes{color:var(--grn);}
.plan-features li .fi.no{color:var(--txt3);}
.plan-features li.disabled{color:var(--txt3);}
.btn-plan{width:100%;padding:12px;margin-top:18px;border-radius:var(--r);font-size:.88rem;font-weight:700;border:none;transition:all .25s;}
.btn-plan-get{background:var(--pri);color:#fff;box-shadow:0 4px 16px var(--glow);}
.btn-plan-get:hover{background:var(--pri2);transform:translateY(-1px);}
.btn-plan-current{background:rgba(5,150,105,.1);color:var(--grn);cursor:default;border:1px solid rgba(5,150,105,.2);}
.btn-plan-free{background:var(--card2);color:var(--txt);border:1px solid var(--border);}

.compare-table{width:100%;border-collapse:collapse;font-size:.85rem;}
.compare-table th{padding:14px 18px;text-align:left;background:var(--bg3);border-bottom:1px solid var(--border);font-size:.72rem;color:var(--txt3);text-transform:uppercase;letter-spacing:.8px;}
.compare-table td{padding:13px 18px;border-bottom:1px solid var(--border);}
.compare-table td:first-child{font-weight:600;color:var(--txt);}
.compare-table td .check{color:var(--grn);font-size:.9rem;}
.compare-table td .cross{color:var(--txt3);}
.compare-table tr:hover td{background:var(--bg3);}

.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(8px);z-index:999;align-items:center;justify-content:center;padding:20px;}
.overlay.show{display:flex;}
.pay-modal{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r3);width:100%;max-width:450px;box-shadow:0 20px 80px rgba(0,0,0,.4);animation:fadeUp .3s ease;overflow:hidden;max-height:90vh;display:flex;flex-direction:column;}
.pay-modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,var(--glow),var(--glow2));flex-shrink:0;}
.pay-modal-head h3{font-size:.95rem;font-weight:800;display:flex;align-items:center;gap:6px;}
.pay-modal-body{padding:16px 18px;overflow-y:auto;flex:1;min-height:0;}
.modal-close{width:30px;height:30px;border-radius:8px;background:var(--card);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--txt2);cursor:pointer;font-size:.9rem;}
.modal-close:hover{background:var(--card2);}
.pay-summary{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:10px 12px;margin-bottom:12px;display:flex;align-items:center;gap:8px;}
.pay-summary-icon{font-size:1.4rem;flex-shrink:0;}
.pay-summary-info{flex:1;}
.pay-summary-info .ps-plan{font-size:.88rem;font-weight:700;}
.pay-summary-info .ps-cycle{font-size:.73rem;color:var(--txt3);}
.pay-summary .ps-amt{font-size:1.2rem;font-weight:800;color:var(--pri);}
.method-tabs{display:flex;gap:4px;margin-bottom:10px;}
.mtab{flex:1;padding:7px 4px;border:1.5px solid var(--border);border-radius:var(--r);text-align:center;cursor:pointer;font-size:.7rem;font-weight:600;color:var(--txt2);transition:all .2s;background:var(--card);min-height:36px;}
.mtab:hover{border-color:var(--border2);}
.mtab.on{border-color:var(--pri);color:var(--pri);background:var(--glow);}
.mtab .mi{display:block;font-size:1.1rem;margin-bottom:3px;}
.pane{display:none;}.pane.on{display:block;animation:fadeUp .2s ease;}
.card-visual{background:linear-gradient(135deg,var(--bg2),var(--card2));border-radius:14px;padding:20px;margin-bottom:14px;border:1px solid var(--border2);position:relative;overflow:hidden;height:140px;}
.card-visual::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;border-radius:50%;background:var(--glow);}
.card-visual::after{content:'';position:absolute;bottom:-40px;left:20px;width:100px;height:100px;border-radius:50%;background:var(--glow2);}
.card-chip{font-size:1.5rem;position:relative;z-index:1;}
.card-num-display{font-family:var(--mono);font-size:.95rem;letter-spacing:2px;color:var(--txt);margin-top:16px;position:relative;z-index:1;}
.card-meta{display:flex;justify-content:space-between;margin-top:8px;position:relative;z-index:1;}
.card-meta span{font-size:.65rem;color:var(--txt3);display:block;}
.card-meta .cv{font-family:var(--mono);font-size:.8rem;color:var(--txt);}
.coupon-row{display:flex;gap:6px;margin-top:8px;}
.coupon-row .inp{flex:1;}
.btn-coupon{padding:11px 16px;background:var(--card2);border:1.5px solid var(--border2);border-radius:var(--r);color:var(--txt);font-size:.82rem;font-weight:600;white-space:nowrap;transition:all .2s;}
.btn-coupon:hover{border-color:var(--pri);color:var(--pri);}
.coupon-status{font-size:.75rem;margin-top:6px;padding:6px 10px;border-radius:8px;}
.cs-ok{background:rgba(5,150,105,.1);color:var(--grn);border:1px solid rgba(5,150,105,.2);}
.cs-er{background:rgba(220,38,38,.1);color:var(--red);}
.pay-breakdown{background:var(--bg3);border-radius:var(--r);padding:8px 10px;margin:10px 0;font-size:.75rem;}
.pb-row{display:flex;justify-content:space-between;padding:4px 0;}
.pb-row.total{border-top:1px solid var(--border);margin-top:6px;padding-top:10px;font-weight:700;font-size:.9rem;}
.pb-row.discount{color:var(--grn);}
.btn-pay{width:100%;padding:11px;background:linear-gradient(135deg,var(--pri),var(--acc));color:#fff;border:none;border-radius:var(--r);font-size:.9rem;font-weight:800;letter-spacing:.3px;transition:all .25s;display:flex;align-items:center;justify-content:center;gap:6px;box-shadow:0 6px 24px var(--glow);margin-top:3px;min-height:40px;}
.btn-pay:hover{transform:translateY(-1px);box-shadow:0 8px 32px var(--glow);}
.secure-note{text-align:center;font-size:.65rem;color:var(--txt3);margin-top:6px;display:flex;align-items:center;justify-content:center;gap:3px;}

.dash-content{display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:36px;}
.content-main{display:flex;flex-direction:column;gap:24px;}
.content-sidebar{display:flex;flex-direction:column;gap:24px;}
.dash-services{margin-bottom:0;padding:48px 0;border-top:1px solid var(--border);margin-top:32px;}
.section-title{font-size:1.4rem;font-weight:900;margin-bottom:28px;color:var(--txt);letter-spacing:-.4px;}
.services-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;width:100%;}
.service-card{background:linear-gradient(135deg,var(--card) 0%,rgba(249,115,22,.05) 100%);border:1.5px solid var(--border);border-radius:18px;padding:24px 18px;position:relative;overflow:hidden;transition:all .4s cubic-bezier(.34,1.56,.64,1);display:flex;flex-direction:column;cursor:pointer;text-decoration:none;color:var(--txt);min-width:0;word-break:break-word;}
.service-card::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at top right,rgba(249,115,22,.2) 0%,transparent 70%);opacity:0;transition:opacity .4s;pointer-events:none;}
.service-card:hover{border-color:var(--pri);background:linear-gradient(135deg,rgba(249,115,22,.08) 0%,#1a1f2e 100%);transform:translateY(-12px);box-shadow:0 24px 48px rgba(249,115,22,.2);}
.service-card:hover::before{opacity:1;}
.service-card:hover .service-arrow{transform:translateX(6px);}
.service-icon{width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff;margin-bottom:16px;flex-shrink:0;line-height:1;box-shadow:0 8px 20px rgba(0,0,0,.2);transition:all .4s;}
.service-icon i{display:block;line-height:1;font-size:1.5rem;}
.service-card:hover .service-icon{transform:scale(1.15) rotate(-8deg);}
.gradient-1{background:linear-gradient(135deg,#f97316,#ea580c);}
.gradient-2{background:linear-gradient(135deg,#0ea5e9,#06b6d4);}
.gradient-3{background:linear-gradient(135deg,#8b5cf6,#7c3aed);}
.gradient-4{background:linear-gradient(135deg,#ec4899,#d946ef);}
.service-name{font-size:1.05rem;font-weight:700;margin-bottom:6px;color:var(--txt);}
.service-desc{font-size:.85rem;color:var(--txt2);margin-bottom:12px;flex-grow:1;}
.service-arrow{font-size:1.4rem;color:var(--pri);transition:transform .3s;position:relative;top:2px;}
.dash-about{background:linear-gradient(135deg,rgba(249,115,22,.08) 0%,transparent 100%);border-radius:24px;padding:56px 42px;margin-top:32px;margin-bottom:0;position:relative;overflow:hidden;border:1px solid rgba(249,115,22,.1);}
.dash-about::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 120% 100% at 50% 0%,rgba(249,115,22,.1) 0%,transparent 60%);pointer-events:none;}
.about-container{position:relative;z-index:2;max-width:1200px;margin:0 auto;}
.about-header{text-align:center;margin-bottom:48px;}
.about-title{font-size:clamp(1.8rem,4vw,2.8rem);font-weight:900;color:var(--txt);letter-spacing:-1px;margin-bottom:12px;}
.about-sub{font-size:1.1rem;color:var(--txt2);max-width:400px;margin:0 auto;}
.about-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:24px;margin-bottom:48px;}
.about-card{background:linear-gradient(135deg,var(--card) 0%,rgba(14,165,233,.03) 100%);border:1.5px solid var(--border);border-radius:20px;padding:32px 26px;text-align:center;transition:all .4s cubic-bezier(.34,1.56,.64,1);position:relative;overflow:hidden;}
.about-card::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at top,rgba(249,115,22,.1) 0%,transparent 70%);opacity:0;transition:opacity .4s;}
.about-card:hover{border-color:var(--pri);background:linear-gradient(135deg,rgba(249,115,22,.1) 0%,rgba(14,165,233,.05) 100%);transform:translateY(-12px);box-shadow:0 24px 48px rgba(249,115,22,.15);}
.about-card:hover::before{opacity:1;}
.about-icon{width:64px;height:64px;border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:1.7rem;color:#fff;margin:0 auto 18px;flex-shrink:0;line-height:1;box-shadow:0 12px 24px rgba(0,0,0,.2);}
.about-icon i{display:block;line-height:1;font-size:1.7rem;}
.about-card h3{font-size:1.15rem;font-weight:800;color:var(--txt);margin-bottom:12px;}
.about-card p{font-size:.9rem;color:var(--txt2);line-height:1.7;}
.about-cta{background:linear-gradient(135deg,var(--card) 0%,rgba(249,115,22,.1) 100%);border:2px solid var(--border);border-radius:20px;padding:40px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:24px;position:relative;overflow:hidden;}
.about-cta::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at bottom right,rgba(249,115,22,.15) 0%,transparent 60%);pointer-events:none;}
.cta-content{position:relative;z-index:2;flex:1;min-width:300px;}
.cta-content h3{font-size:1.4rem;font-weight:800;color:var(--txt);margin-bottom:8px;}
.cta-content p{font-size:.95rem;color:var(--txt2);}
.cta-button{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,var(--pri),#ea580c);color:#fff;padding:14px 32px;border-radius:12px;font-weight:700;font-size:.95rem;text-decoration:none;transition:all .3s;box-shadow:0 8px 24px rgba(249,115,22,.3);position:relative;z-index:2;cursor:pointer;}
.cta-button:hover{transform:translateY(-3px);box-shadow:0 12px 36px rgba(249,115,22,.4);}
.quick-actions{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:20px;}
.qa-item{background:var(--card);border:1px solid var(--border);border-radius:var(--r2);padding:18px;text-align:center;cursor:pointer;transition:all .25s;}
.qa-item:hover{border-color:var(--pri);background:var(--glow);transform:translateY(-2px);}
.qa-icon{font-size:1.6rem;margin-bottom:8px;}
.qa-label{font-size:.82rem;font-weight:600;color:var(--txt);}
.qa-sub{font-size:.72rem;color:var(--txt3);margin-top:2px;}
.activity-list{list-style:none;}
.activity-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);}
.activity-item:last-child{border:none;}
.act-dot{width:8px;height:8px;border-radius:50%;margin-top:5px;flex-shrink:0;}
.act-dot.login{background:var(--grn);}
.act-dot.payment{background:var(--pri);}
.act-dot.register{background:var(--amber);}
.act-text{font-size:.82rem;color:var(--txt2);flex:1;}
.act-time{font-size:.68rem;color:var(--txt3);white-space:nowrap;}
.notif-list{list-style:none;}
.notif-item{padding:12px 0;border-bottom:1px solid var(--border);display:flex;gap:10px;}
.notif-item:last-child{border:none;}
.notif-item.unread{background:var(--glow);}
.ni-type{font-size:1rem;flex-shrink:0;}
.ni-body{flex:1;}
.ni-title{font-size:.82rem;font-weight:700;}
.ni-msg{font-size:.76rem;color:var(--txt2);margin-top:2px;line-height:1.5;}
.ni-time{font-size:.65rem;color:var(--txt3);margin-top:4px;}

.profile-grid{display:grid;grid-template-columns:300px 1fr;gap:20px;}
.profile-sidebar-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r2);padding:28px;text-align:center;}
.profile-ava{width:80px;height:80px;border-radius:20px;background:linear-gradient(135deg,var(--acc),var(--pri));display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:800;margin:0 auto 16px;box-shadow:0 0 30px var(--glow);color:#fff;}
.profile-name{font-size:1.15rem;font-weight:800;}
.profile-email{font-size:.8rem;color:var(--txt2);margin-top:4px;}
.profile-since{font-size:.72rem;color:var(--txt3);margin-top:6px;}
.profile-stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:20px;}
.ps-stat{background:var(--bg3);border-radius:var(--r);padding:12px;}
.ps-stat .sv{font-size:1.1rem;font-weight:800;color:var(--pri);}
.ps-stat .sl{font-size:.65rem;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}
.form-section{margin-bottom:24px;}
.form-section h4{font-size:.9rem;font-weight:700;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid var(--border);}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.btn-save{padding:11px 24px;background:var(--pri);color:#fff;border:none;border-radius:var(--r);font-size:.88rem;font-weight:700;transition:all .25s;box-shadow:0 4px 16px var(--glow);}
.btn-save:hover{background:var(--pri2);transform:translateY(-1px);}
.btn-danger{padding:11px 24px;background:rgba(220,38,38,.1);color:var(--red);border:1px solid rgba(220,38,38,.2);border-radius:var(--r);font-size:.88rem;font-weight:700;transition:all .25s;}
.btn-danger:hover{background:rgba(220,38,38,.15);}

.support-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.priority-select{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:4px;}
.prio-opt{padding:8px;border-radius:var(--r);border:1.5px solid var(--border);text-align:center;cursor:pointer;font-size:.75rem;font-weight:600;color:var(--txt2);transition:all .2s;background:var(--card);}
.prio-opt:hover{border-color:var(--border2);}
.prio-opt.on.low{border-color:var(--grn);color:var(--grn);background:rgba(5,150,105,.06);}
.prio-opt.on.medium{border-color:var(--cyan);color:var(--cyan);background:rgba(8,145,178,.06);}
.prio-opt.on.high{border-color:var(--amber);color:var(--amber);background:rgba(217,119,6,.06);}
.prio-opt.on.urgent{border-color:var(--red);color:var(--red);background:rgba(220,38,38,.06);}

.toast{position:fixed;bottom:24px;right:24px;padding:14px 20px;border-radius:var(--r2);font-size:.88rem;font-weight:700;z-index:9999;max-width:360px;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow2);animation:slide-in .3s ease;}
.toast.ok{background:rgba(5,150,105,.12);color:var(--grn);border:1px solid rgba(5,150,105,.25);}
.toast.er{background:rgba(220,38,38,.12);color:var(--red);border:1px solid rgba(220,38,38,.25);}

.theme-btn{
width:48px;height:48px;background:var(--card);border:1.5px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--txt2);cursor:pointer;transition:all .3s cubic-bezier(.34,.1,.64,.1);position:relative;overflow:hidden;
}
.theme-btn:hover{
background:var(--card2);color:var(--acc);border-color:var(--acc);transform:translateY(-2px);box-shadow:0 4px 16px var(--glow);
}
.theme-btn:active{transform:translateY(0)}
.theme-toggle{
  display:flex;align-items:center;gap:8px;background:transparent;border:none;cursor:pointer;position:relative;transition:all .3s;
}
.toggle-track{
  width:56px;height:28px;background:var(--border);border-radius:50px;position:relative;display:flex;align-items:center;transition:all .3s cubic-bezier(.34,.1,.64,.1);border:1.5px solid var(--border);
}
.toggle-thumb{
  width:24px;height:24px;background:var(--card);border-radius:50%;position:absolute;left:2px;display:flex;align-items:center;justify-content:center;color:var(--txt2);font-size:.8rem;box-shadow:0 2px 8px rgba(0,0,0,.2);transition:all .4s cubic-bezier(.34,.1,.64,.1);
}
[data-theme="dark"] .toggle-track{
  background:var(--acc);border-color:var(--acc);
}
[data-theme="dark"] .toggle-thumb{
  left:30px;color:var(--acc);
}
.toggle-label{
  font-size:.75rem;font-weight:600;color:var(--txt2);text-transform:uppercase;letter-spacing:.5px;min-width:35px;transition:all .3s;
}
[data-theme="dark"] .toggle-label{
  color:var(--acc);
}
.theme-toggle:hover .toggle-track{
  box-shadow:0 0 12px var(--glow);
}
.theme-btn:hover{border-color:var(--pri);color:var(--pri);}
.mono{font-family:var(--mono);font-size:.78rem;}
.link-btn{background:none;border:none;color:var(--pri);font-size:.82rem;font-weight:600;cursor:pointer;padding:0;}
.link-btn:hover{color:var(--pri2);text-decoration:underline;}
.tag{display:inline-flex;align-items:center;padding:2px 8px;border-radius:6px;font-size:.65rem;font-weight:700;background:var(--glow);color:var(--pri);}
.sep{height:1px;background:var(--border);margin:20px 0;}
.text-center{text-align:center;}
.text-muted{color:var(--txt2);}
.fw-bold{font-weight:700;}
select.inp option{background:var(--bg2);color:var(--txt);}
textarea.inp{resize:vertical;min-height:90px;}

@media(max-width:1400px){
  .sidebar{width:220px;}
  .content{margin-left:220px;width:calc(100% - 220px);}
  .auth-right{width:420px;}
}
@media(max-width:1200px){
  :root{--r:12px;--r2:18px;}
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .dash-grid{grid-template-columns:1fr;}
  .profile-grid{grid-template-columns:1fr;}
  .plans-grid{grid-template-columns:repeat(2,1fr);}
  .sidebar{width:200px;}
  .content{margin-left:200px;width:calc(100% - 200px);}
  .page-wrap{padding:20px;}
}
@media(max-width:1024px){
  .sidebar{width:180px;}
  .content{margin-left:180px;width:calc(100% - 180px);}
  .auth-right{width:100%;padding:32px 20px;}
  .auth-left{display:none;}
  .plans-grid{grid-template-columns:1fr;}
  .form-grid{grid-template-columns:1fr;}
  .support-grid{grid-template-columns:1fr;}
  .tbl-wrap{font-size:.8rem;}
  th,td{padding:10px 12px;}
}
@media(max-width:768px){
  :root{--r:10px;--r2:14px;}
  .auth-left{padding:60px 32px;}
  .hero-title{font-size:clamp(2.2rem,5vw,3.5rem);}
  .hero-grid{grid-template-columns:1fr;max-width:100%;}
  .hero-features{grid-template-columns:1fr;max-width:100%;}
  .sidebar{transform:translateX(-100%);width:240px;transition:transform .3s ease;}
  .sidebar.open{transform:translateX(0);box-shadow:2px 0 20px rgba(0,0,0,.3);}
  .content{margin-left:0;width:100%;}
  .hamburger{display:flex;}
  .topbar{padding:0 16px;}
  .page-wrap{padding:12px;}
  .dash-hero{padding:36px 24px;}
  .hero-main{font-size:clamp(1.6rem,3.5vw,2.4rem);}
  .hero-stats{gap:12px;}
  .hstat{padding:18px 14px;}
  .services-grid{grid-template-columns:repeat(2,1fr);gap:12px;}
  .about-grid{gap:16px;}
  .about-card{padding:22px 18px;}
  .about-cta{padding:28px 20px;flex-direction:column;text-align:center;}
  .cta-button{width:100%;}
  .page-title{font-size:1.3rem;}
  .page-header{margin-bottom:16px;}
  .stats-grid{grid-template-columns:1fr 1fr;gap:12px;}
  .plans-grid{grid-template-columns:1fr;}
  .quick-actions{grid-template-columns:1fr 1fr;gap:8px;}
  .qa-item{padding:12px;}
  .qa-icon{font-size:1.3rem;}
  .qa-label{font-size:.75rem;}
  .form-grid{grid-template-columns:1fr;}
  .form-row{grid-template-columns:1fr;}
  .support-grid{grid-template-columns:1fr;}
  .auth-tabs{margin-bottom:20px;}
  .compare-table{font-size:.72rem;}
  th,td{padding:8px 10px;font-size:.75rem;}
  .pay-modal{max-width:92%;margin:8px;max-height:85vh;}
  .pay-modal-body{padding:12px 14px;}
  .feature-card{margin:0 !important;}
  .section-body{padding:16px;}
  .bi{display:none;}
  .nav-section{padding:8px 12px 2px;font-size:.6rem;}
  .nav-item{padding:8px 14px;margin:0 6px;font-size:.8rem;}
  .logo-name{font-size:.9rem;}
}
@media(max-width:640px){
  :root{--r:8px;--r2:12px;}
  .auth-left{padding:48px 24px;}
  .hero-label{font-size:.7rem;margin-bottom:16px;}
  .hero-title{font-size:clamp(1.8rem,4.5vw,2.8rem);}
  .hero-desc{font-size:clamp(.95rem,2vw,1.05rem);max-width:100%;}
  .hero-grid{gap:20px;margin-bottom:40px;}
  .hero-card{padding:28px 20px;}
  .hero-card-stat{font-size:1.8rem;}
  .hero-features{gap:20px;margin-top:40px;grid-template-columns:1fr;}
  .hero-feature{padding:24px;}
  .page-title{font-size:1.1rem;gap:6px;}
  .page-title .ti{width:32px;height:32px;font-size:.85rem;}
  .page-sub{font-size:.8rem;margin-left:38px;}
  .dash-hero{padding:28px 18px;}
  .hero-main{font-size:clamp(1.4rem,3vw,2rem);}
  .hero-stats{grid-template-columns:repeat(2,1fr);gap:10px;}
  .hstat{padding:16px 12px;}
  .hs-icon{width:48px;height:48px;font-size:1.4rem;}
  .hs-val{font-size:1.5rem;}
  .hs-lbl{font-size:.65rem;}
  .services-grid{grid-template-columns:1fr;gap:10px;}
  .about-card{padding:18px 14px;}
  .about-title{font-size:clamp(1.4rem,3.5vw,1.8rem);}
  .about-cta{gap:16px;padding:20px;}
  .cta-content h3{font-size:1.1rem;}
  .cta-button{padding:12px 20px;font-size:.85rem;}
  .stats-grid{grid-template-columns:1fr;gap:10px;}
  .stat-card{padding:16px;}
  .stat-val{font-size:1.4rem;}
  .stat-icon{width:32px;height:32px;font-size:.9rem;}
  .section-card{border-radius:12px;}
  .section-head{padding:14px 16px;}
  .section-head h3{font-size:.85rem;}
  .section-body{padding:12px;}
  .form-group{margin-bottom:12px;}
  .lbl{font-size:.65rem;margin-bottom:4px;}
  .inp{padding:9px 12px;font-size:.85rem;}
  .btn-primary{padding:11px;font-size:.85rem;}
  .btn-plan{padding:10px;font-size:.8rem;}
  .plan-card{padding:18px 16px;}
  .plan-name{font-size:.95rem;}
  .plan-price{font-size:1.8rem;}
  .plan-features li{font-size:.75rem;padding:3px 0;}
  .pay-modal-head h3{font-size:.9rem;}
  .method-tabs{gap:4px;margin-bottom:12px;}
  .mtab{padding:8px 4px;font-size:.7rem;}
  .compare-table td:first-child{min-width:120px;}
  .topbar-title{font-size:.9rem;}
  .topbar-actions{gap:6px;}
  .topbar-btn{width:32px;height:32px;font-size:.8rem;}
  .profile-sidebar-card{background:var(--card);padding:16px;}
  .profile-ava{width:60px;height:60px;font-size:1.4rem;}
  .profile-name{font-size:1rem;}
  .profile-email{font-size:.75rem;}
  .profile-stats{gap:8px;}
  .ps-stat{padding:10px;}
  .ps-stat .sv{font-size:.95rem;}
  .user-info .un{font-size:.75rem;}
  .user-info .ue{font-size:.6rem;}
  .user-ava{width:30px;height:30px;font-size:.7rem;}
  .sidebar-user{padding:10px 12px;}
  .activity-item{padding:6px 0;}
  .act-text{font-size:.75rem;}
  .act-time{font-size:.6rem;}
  .notif-item{padding:8px 0;}
  .ni-body{flex:1;}
  .ni-title{font-size:.75rem;}
  .ni-msg{font-size:.7rem;}
  .ni-time{font-size:.6rem;}
  table{font-size:.7rem;}
  .badge{font-size:.6rem;padding:2px 8px;}
  .empty-row{padding:24px 12px;}
  .empty-row .empty-icon{font-size:1.5rem;margin-bottom:6px;}
  .billing-toggle{width:100%;justify-content:space-between;}
  .billing-opt{padding:6px 16px;font-size:.75rem;}
  .form-section h4{font-size:.8rem;margin-bottom:12px;}
  .priority-select{grid-template-columns:repeat(2,1fr);gap:6px;}
  .prio-opt{padding:6px;font-size:.65rem;}
}
@media(max-width:480px){
  :root{--r:6px;--r2:10px;}
  .auth-left{padding:36px 16px;}
  .hero-label{font-size:.65rem;margin-bottom:12px;}
  .hero-title{font-size:clamp(1.5rem,4vw,2.2rem);}
  .hero-desc{font-size:.9rem;margin-bottom:32px;}
  .hero-grid{gap:16px;margin-bottom:32px;}
  .hero-card{padding:20px 16px;}
  .hero-card-icon{font-size:1.6rem;}
  .hero-card-stat{font-size:1.6rem;}
  .hero-card-text{font-size:.8rem;}
  .hero-features{gap:16px;margin-top:32px;grid-template-columns:1fr;}
  .hero-feature{padding:20px;}
  .hero-feature h3{font-size:.95rem;}
  .hero-feature p{font-size:.75rem;}
  .sidebar{width:220px;}
  .page-wrap{padding:8px;}
  .stats-grid{grid-template-columns:1fr;gap:8px;}
  .quick-actions{grid-template-columns:1fr;gap:6px;}
  .qa-item{padding:10px;border-radius:8px;}
  .qa-icon{font-size:1.1rem;margin-bottom:4px;}
  .qa-label{font-size:.7rem;}
  .qa-sub{font-size:.65rem;}
  .plan-card{padding:14px 12px;}
  .plan-name{font-size:.85rem;}
  .plan-price{font-size:1.5rem;}
  .plan-divider{margin:10px 0;}
  .plan-features li{font-size:.7rem;padding:2px 0;}
  .btn-plan{padding:9px;font-size:.75rem;margin-top:12px;}
  .stat-val{font-size:1.2rem;}
  .stat-lbl{font-size:.65rem;}
  .section-head{padding:10px 12px;}
  .section-head h3{font-size:.75rem;}
  .section-body{padding:10px;}
  .lbl{font-size:.6rem;}
  .inp{padding:8px 10px;font-size:.8rem;}
  .btn-primary{padding:10px;font-size:.8rem;}
  .topbar{height:50px;padding:0 12px;gap:6px;}
  .topbar-title{font-size:.8rem;}
  .pay-modal{max-width:94%;width:100%;max-height:80vh;}
  .pay-modal-head{padding:14px 16px;}
  .pay-modal-body{padding:10px 12px;}
  .pay-summary{gap:8px;padding:10px;}
  .pay-summary-icon{font-size:1.4rem;}
  .ps-amt{font-size:1rem;}
  .card-visual{height:100px;}
  .card-num-display{font-size:.8rem;letter-spacing:1px;margin-top:10px;}
  .card-meta span{font-size:.6rem;}
  .coupon-row{gap:6px;}
  .btn-coupon{padding:8px 12px;font-size:.75rem;}
  .pay-breakdown{font-size:.75rem;padding:10px 12px;}
  .pb-row{padding:3px 0;}
  .btn-pay{padding:12px;font-size:.85rem;gap:6px;}
  .secure-note{font-size:.65rem;}
  .method-tabs{gap:3px;}
  .mtab{padding:6px 3px;font-size:.65rem;}
  .compare-table{font-size:.65rem;}
  .tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
  .activity-item{gap:8px;}
  .toast{bottom:12px;right:12px;padding:10px 14px;font-size:.75rem;max-width:calc(100% - 24px);}
  .theme-btn{padding:4px 10px;font-size:.7rem;}
  .link-btn{font-size:.75rem;}
}
@media(max-width:360px){
  :root{--r:6px;--r2:10px;}
  .page-title{font-size:.95rem;}
  .page-title .ti{width:28px;height:28px;}
  .page-sub{display:none;}
  .stats-grid{gap:6px;}
  .stat-card{padding:12px;}
  .stat-val{font-size:1rem;}
  .stat-icon{width:28px;height:28px;}
  .plan-card{padding:12px 10px;}
  .plan-price{font-size:1.3rem;}
  .form-row{gap:8px;}
  .quick-actions{gap:4px;}
  .qa-item{padding:8px;}
  .qa-icon{font-size:.9rem;}
  .navbar-item{gap:6px;}
}
.feature-card:hover { transform: translateY(-6px) scale(1.03); box-shadow: 0 12px 32px rgba(0,0,0,.15); transition: all 0.3s ease; }
.feature-card:hover .feature-icon { transform: scale(1.2) rotate(5deg); transition: transform 0.3s ease; }
.feature-card:hover h4 { color: var(--pri); transition: color 0.3s ease; }
</style>
</head>
<body data-theme="dark">

<?php if(!isset($_SESSION['uid'])): ?>

<div class="auth-page">
  <div class="auth-left">
    <div class="modern-hero">
      <div class="hero-label" style="animation:fadeIn .8s ease-out">Premium Membership Platform</div>
      <h1 class="hero-title" style="animation:slideInTitle 1s cubic-bezier(0.34, 1.56, 0.64, 1)">
        <span class="title-word">Manage</span><span class="title-word">Your</span><span class="title-word">Community</span>
      </h1>
      <p class="hero-desc" style="animation:fadeIn 1.2s ease-out">Enterprise-grade subscription platform designed for modern businesses</p>
      
      <div class="hero-grid">
        <div class="hero-card" style="animation:scaleIn .6s cubic-bezier(0.34, 1.56, 0.64, 1)">
          <div class="hero-card-icon"><i class="fas fa-users"></i></div>
          <div class="hero-card-stat">5K+</div>
          <div class="hero-card-text">Active Members</div>
        </div>
        <div class="hero-card" style="animation:scaleIn .8s cubic-bezier(0.34, 1.56, 0.64, 1)">
          <div class="hero-card-icon"><i class="fas fa-chart-line"></i></div>
          <div class="hero-card-stat">₹2M+</div>
          <div class="hero-card-text">Revenue Processed</div>
        </div>
      </div>

      <div class="hero-features">
        <div class="hero-feature" style="animation:slideInLeft .8s ease-out">
          <div class="feature-mark"></div>
          <h3>Flexible Plans</h3>
          <p>Create unlimited membership tiers with custom pricing and features</p>
        </div>
        <div class="hero-feature" style="animation:slideInRight .8s ease-out">
          <div class="feature-mark"></div>
          <h3>Secure Payments</h3>
          <p>Accept payments via card, UPI, and net banking with full encryption</p>
        </div>
      </div>
    </div>
  </div>

  <div class="auth-right">
    <div style="position:absolute;top:20px;right:20px;">
      <button class="theme-toggle" id="themeToggleAuth" onclick="toggleTheme()" title="Toggle Theme">
        <span class="toggle-track">
          <span class="toggle-thumb">
            <i class="fas fa-sun"></i>
          </span>
        </span>
        <span class="toggle-label" id="toggleLabelAuth">Light</span>
      </button>
    </div>
    <div class="auth-form-head">
      <h2>Welcome back <i class="fas fa-hand" style="font-size:0.85em"></i></h2>
      <p>Login to your account or create a new one below</p>
    </div>
    <?php if($msg): ?><div class="amsg <?=$msgok?'ok':'er'?>"><i class="fas <?=$msgok?'fa-check':'fa-triangle-exclamation'?>"></i> <?=esc($msg)?></div><?php endif; ?>

    <div class="auth-tabs">
      <button class="auth-tab on" id="tab-login" onclick="authTab('login')">Login</button>
      <button class="auth-tab" id="tab-reg" onclick="authTab('register')">Register</button>
    </div>

    <div id="fLogin">
      <form method="POST" autocomplete="on">
        <div class="form-group">
          <label class="lbl">Email Address</label>
          <div class="inp-icon">
            <i class="fas fa-envelope ico"></i>
            <input class="inp" type="email" name="email" required autocomplete="email">
          </div>
        </div>
        <div class="form-group">
          <label class="lbl">Password</label>
          <div class="inp-icon">
            <i class="fas fa-key ico"></i>
            <input class="inp" type="password" name="password" required autocomplete="current-password">
          </div>
        </div>
        <button class="btn-primary" name="login">Login to Dashboard →</button>
        <p style="text-align:center;margin-top:16px;font-size:.8rem;color:var(--txt3);">Don't have an account? <button type="button" class="link-btn" onclick="authTab('register')">Create one →</button></p>
      </form>
    </div>

    <div id="fReg" style="display:none">
      <form method="POST" autocomplete="off">
        <div class="form-row">
          <div class="form-group">
            <label class="lbl">Full Name</label>
            <input class="inp" name="name" required>
          </div>
          <div class="form-group">
            <label class="lbl">Phone Number</label>
            <input class="inp" name="phone" required pattern="[0-9]{10,13}">
          </div>
        </div>
        <div class="form-group">
          <label class="lbl">Email Address</label>
          <input class="inp" type="email" name="email" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="lbl">Password</label>
            <input class="inp" type="password" name="password" id="regPass" minlength="8" required oninput="checkStrength(this)">
            <div class="pass-strength"><div class="pass-strength-bar" id="strengthBar"></div></div>
          </div>
          <div class="form-group">
            <label class="lbl">Confirm Password</label>
            <input class="inp" type="password" name="confirm_password" required>
          </div>
        </div>
        <button class="btn-primary" name="register">Create Account →</button>
        <p style="text-align:center;margin-top:16px;font-size:.8rem;color:var(--txt3);">Already have an account? <button type="button" class="link-btn" onclick="authTab('login')">Login →</button></p>
      </form>
    </div>
  </div>
</div>

<?php else: ?>

<div class="overlay" id="payModal">
  <div class="pay-modal">
    <div class="pay-modal-head">
      <h3><i class="fas fa-credit-card"></i> Complete Purchase — <span id="mPlanLabel"></span></h3>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="pay-modal-body">
      <?php if($msg&&!$msgok): ?><div class="amsg er"><i class="fas fa-triangle-exclamation"></i> <?=esc($msg)?></div><?php endif; ?>
      <form method="POST" id="payForm">
        <input type="hidden" name="plan" id="mPlanInput">
        <input type="hidden" name="method" id="mMethod" value="card">
        <input type="hidden" name="billing_cycle" id="mCycle" value="monthly">

        <div class="pay-summary">
          <div class="pay-summary-icon" id="mPlanIcon"><i class="fas fa-gem"></i></div>
          <div class="pay-summary-info">
            <div class="ps-plan"><span id="mPlanN"></span> Plan</div>
            <div class="ps-cycle" id="mCycleLabel">Monthly billing</div>
          </div>
          <div class="ps-amt" id="mAmtDisplay">₹0</div>
        </div>

        <label class="lbl">Payment Method</label>
        <div class="method-tabs">
          <div class="mtab on" onclick="setM('card',this)"><i class="fas fa-credit-card mi"></i>Debit/Credit</div>
          <div class="mtab" onclick="setM('upi',this)"><span class="mi">📱</span>UPI</div>
          <div class="mtab" onclick="setM('netbanking',this)"><span class="mi">🏦</span>Net Banking</div>
        </div>

        <div class="pane on" id="pm-card">
          <div class="card-visual">
            <div class="card-chip"><i class="fas fa-credit-card"></i></div>
            <div class="card-num-display" id="cardDisplay">•••• •••• •••• ••••</div>
            <div class="card-meta">
              <div><span>Card Holder</span><span class="cv" id="cardHolder"><?=strtoupper(esc(explode(' ',$me['name'] ?? 'User')[0]))?></span></div>
              <div style="text-align:right"><span>Expires</span><span class="cv" id="cardExpDisplay">MM/YY</span></div>
            </div>
          </div>
          <div class="form-group">
            <label class="lbl">Card Number</label>
            <input class="inp" name="card" id="cardInput" maxlength="19" oninput="fmtCard(this)" autocomplete="cc-number">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="lbl">Expiry Date</label>
              <input class="inp" name="expiry" id="expInput" maxlength="5" oninput="fmtExp(this)" autocomplete="cc-exp">
            </div>
            <div class="form-group">
              <label class="lbl">CVV</label>
              <input class="inp" type="password" name="cvv" maxlength="4" autocomplete="cc-csc">
            </div>
          </div>
        </div>

        <div class="pane" id="pm-upi">
          <div class="form-group" style="margin-top:8px">
            <label class="lbl">UPI ID</label>
            <input class="inp" name="upi">
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
            <?php foreach(['@paytm','@okhdfc','@oksbi','@ybl','@ibl'] as $h): ?>
            <span onclick="this.closest('.pane').querySelector('[name=upi]').value='<?=esc(explode(' ',$me['name'] ?? 'User')[0]).$h?>'"
              style="background:var(--card);border:1px solid var(--border);padding:5px 12px;border-radius:50px;font-size:.72rem;cursor:pointer;color:var(--txt2);transition:all .2s"
              onmouseover="this.style.borderColor='var(--pri)'" onmouseout="this.style.borderColor='var(--border)'"><?=$h?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="pane" id="pm-netbanking">
          <div class="form-group" style="margin-top:8px">
            <label class="lbl">Select Bank</label>
            <select class="inp" name="bank">
              <option value="">Select your bank</option>
              <?php foreach(['SBI','HDFC Bank','ICICI Bank','Axis Bank','Kotak Mahindra','Bank of Baroda','PNB','IndusInd','Yes Bank','Federal Bank','Canara Bank','IDFC First'] as $b): ?>
              <option><?=$b?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="lbl">Customer ID</label>
            <input class="inp" name="buid">
          </div>
        </div>

        <div class="sep" style="margin:14px 0 10px"></div>
        <label class="lbl">Promo / Coupon Code <span style="color:var(--txt3);text-transform:none;letter-spacing:0">(optional)</span></label>
        <div class="coupon-row">
          <input class="inp" name="coupon" id="couponInput" style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
          <button type="button" class="btn-coupon" onclick="applyCoupon()">Apply</button>
        </div>
        <div id="couponStatus"></div>

        <div class="pay-breakdown" id="payBreakdown">
          <div class="pb-row"><span>Subtotal</span><span id="pbBase">₹0</span></div>
          <div class="pb-row discount" id="pbDiscountRow" style="display:none"><span>Discount</span><span id="pbDiscount">-₹0</span></div>
          <div class="pb-row"><span>GST (18%)</span><span id="pbTax">₹0</span></div>
          <div class="pb-row total"><span>Total Payable</span><span id="pbTotal" style="color:var(--pri)">₹0</span></div>
        </div>

        <button class="btn-pay" name="pay"><i class="fas fa-lock"></i> Pay Now — <span id="payBtnAmt">₹0</span></button>
        <p class="secure-note"><i class="fas fa-shield"></i> 256-bit SSL Encrypted · PCI-DSS Compliant</p>
      </form>
    </div>
  </div>
</div>

<div id="sidebarOverlay" onclick="closeSidebar()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99;backdrop-filter:blur(4px)"></div>

<div class="app">
  <div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
      <div class="logo-icon"><i class="fas fa-gem"></i></div>
      <div class="logo-name">Member<span>Vault</span></div>
    </div>

    <div style="overflow-y:auto;flex:1;">
      <div class="nav-section">Main</div>
      <a class="nav-item <?=$page=='dashboard'?'on':''?>" href="?p=dashboard"><i class="fas fa-home ni"></i><span>Dashboard</span></a>
      <a class="nav-item <?=$page=='plans'?'on':''?>" href="?p=plans"><i class="fas fa-star ni"></i><span>Plans</span></a>
      <a class="nav-item <?=$page=='payments'?'on':''?>" href="?p=payments"><i class="fas fa-wallet ni"></i><span>Payments</span></a>
      <a class="nav-item <?=$page=='invoices'?'on':''?>" href="?p=invoices"><i class="fas fa-file-invoice ni"></i><span>Invoices</span></a>

      <div class="nav-section">Account</div>
      <a class="nav-item <?=$page=='profile'?'on':''?>" href="?p=profile"><i class="fas fa-user-circle ni"></i><span>Profile</span></a>
      <a class="nav-item <?=$page=='notifications'?'on':''?>" href="?p=notifications">
        <i class="fas fa-inbox ni"></i><span>Alerts</span>
        <?php if($unreadCount>0): ?><span class="nav-badge"><?=$unreadCount?></span><?php endif; ?>
      </a>
      <a class="nav-item <?=$page=='support'?'on':''?>" href="?p=support"><i class="fas fa-headset ni"></i><span>Support</span></a>
      <a class="nav-item <?=$page=='activity'?'on':''?>" href="?p=activity"><i class="fas fa-clock-rotate-left ni"></i><span>History</span></a>

      <div class="nav-section">Info</div>
      <a class="nav-item <?=$page=='about'?'on':''?>" href="?p=about"><i class="fas fa-info-circle ni"></i><span>About</span></a>
    </div>

    <div class="sidebar-plan">
      <div class="sp-label">Current Plan</div>
      <div class="sp-plan"><?=esc($me['plan'] ?? 'Free')?> <i class="fas <?=$currentPlanData['icon'] ?? 'fa-star'?>"></i></div>
      <?php if(($me['plan_expires'] ?? null)): ?>
        <div class="sp-expires">Expires <?=date('d M Y',strtotime($me['plan_expires']))?></div>
      <?php else: ?><div class="sp-expires">No expiry set</div><?php endif; ?>
      <?php if(strtolower($me['plan'] ?? 'free') !== 'enterprise'): ?>
        <a class="sp-upgrade" href="?p=plans"><i class="fas fa-arrow-up"></i> Upgrade Plan</a>
      <?php endif; ?>
    </div>

    <div class="sidebar-user">
      <div class="user-ava"><?=strtoupper(substr($me['name'] ?? 'U',0,1))?></div>
      <div class="user-info">
        <div class="un"><?=esc($me['name'] ?? 'Unknown')?></div>
        <div class="ue"><?=esc($me['email'] ?? 'unknown@example.com')?></div>
      </div>
      <a href="?logout"><button class="btn-logout"><i class="fas fa-power-off"></i></button></a>
    </div>
  </div>

  <div class="content">
    <div class="topbar">
      <div class="hamburger" onclick="toggleSidebar()" id="hamBtn">
        <span></span><span></span><span></span>
      </div>
      <div class="topbar-title">
        <?php
        $titles=['dashboard'=>'Dashboard','plans'=>'Plans &amp; Pricing','payments'=>'Payments','invoices'=>'Invoices','profile'=>'My Profile','notifications'=>'Notifications','support'=>'Support Center','activity'=>'Activity Log','about'=>'About MemberVault'];
        echo $titles[$page]??'Dashboard';
        ?>
      </div>
      <div class="topbar-actions">
        <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()" title="Toggle Theme">
          <span class="toggle-track">
            <span class="toggle-thumb">
              <i class="fas fa-sun"></i>
            </span>
          </span>
          <span class="toggle-label" id="toggleLabel">Light</span>
        </button>
        <a href="?p=notifications&mark_read=1" class="topbar-btn" title="Notifications">
          <i class="fas fa-bell"></i><?php if($unreadCount>0): ?><span class="notif-dot"></span><?php endif; ?>
        </a>
        <a class="topbar-btn" href="?p=plans" title="Upgrade Plan"><i class="fas fa-rocket"></i></a>
        <a class="topbar-btn" href="?logout" title="Logout" onclick="return confirm('Log out?')\"><i class="fas fa-arrow-right-from-bracket"></i></a>
      </div>
    </div>

    <div class="page-wrap">

    <?php if($msgok && $msg): ?>
    <div class="amsg ok" style="margin-bottom:20px"><i class="fas fa-check"></i> <?=esc($msg)?></div>
    <?php elseif(!$msgok && $msg && $page!='plans'): ?>
    <div class="amsg er" style="margin-bottom:20px"><i class="fas fa-triangle-exclamation"></i> <?=esc($msg)?></div>
    <?php endif; ?>

    <?php if($page=='dashboard'): ?>
    
    <div class="dash-hero">
      <div class="hero-gradient-bg"></div>
      <div class="hero-content">
        <div class="hero-greet">Welcome back, <span class="hero-name"><?=esc($me['name'] ?? 'User')?></span> 👋</div>
        <h1 class="hero-main">Your Membership Hub</h1>
        <p class="hero-sub">Manage your plans, track payments, and unlock exclusive benefits</p>
      </div>
      <div class="hero-stats">
        <div class="hstat c1" style="animation:slideUp .6s ease-out">
          <div class="hs-icon"><i class="fas fa-crown"></i></div>
          <div class="hs-val"><?=esc($me['plan'] ?? 'Free')?></div>
          <div class="hs-lbl">Active Plan</div>
        </div>
        <div class="hstat c2" style="animation:slideUp .7s ease-out .1s both">
          <div class="hs-icon"><i class="fas fa-receipt"></i></div>
          <div class="hs-val"><?=count($myPayments)?></div>
          <div class="hs-lbl">Transactions</div>
        </div>
        <div class="hstat c3" style="animation:slideUp .7s ease-out .2s both">
          <div class="hs-icon"><i class="fas fa-indian-rupee-sign"></i></div>
          <div class="hs-val">₹<?=number_format($totalSpent,0)?></div>
          <div class="hs-lbl">Total Spent</div>
        </div>
        <div class="hstat c4" style="animation:slideUp .7s ease-out .3s both">
          <div class="hs-icon"><i class="fas fa-headset"></i></div>
          <div class="hs-val"><?=count($myTickets)?></div>
          <div class="hs-lbl">Support Tickets</div>
        </div>
      </div>
    </div>

    <div class="dash-services">
      <h2 class="section-title">Quick Services</h2>
      <div class="services-grid">
        <a href="?p=plans" class="service-card" style="animation:slideInUp .5s ease-out">
          <div class="service-icon gradient-1"><i class="fas fa-rocket"></i></div>
          <div class="service-name">Upgrade Plan</div>
          <div class="service-desc">Unlock premium features and scale your membership</div>
          <span class="service-arrow">→</span>
        </a>
        <a href="?p=payments" class="service-card" style="animation:slideInUp .6s ease-out .1s both">
          <div class="service-icon gradient-2"><i class="fas fa-wallet"></i></div>
          <div class="service-name">Payment History</div>
          <div class="service-desc">View all your transactions and download invoices</div>
          <span class="service-arrow">→</span>
        </a>
        <a href="?p=support" class="service-card" style="animation:slideInUp .6s ease-out .2s both">
          <div class="service-icon gradient-3"><i class="fas fa-life-ring"></i></div>
          <div class="service-name">Support Tickets</div>
          <div class="service-desc">Raise issues and get expert assistance 24/7</div>
          <span class="service-arrow">→</span>
        </a>
        <a href="?p=profile" class="service-card" style="animation:slideInUp .6s ease-out .3s both">
          <div class="service-icon gradient-4"><i class="fas fa-user-pen"></i></div>
          <div class="service-name">Edit Profile</div>
          <div class="service-desc">Update your personal info and account settings</div>
          <span class="service-arrow">→</span>
        </a>
      </div>
    </div>

    <div class="dash-about">
      <div class="about-container">
        <div class="about-header">
          <h2 class="about-title">About MemberVault</h2>
          <p class="about-sub">Enterprise-grade membership management platform</p>
        </div>
        <div class="about-grid">
          <div class="about-card" style="animation:slideInUp .6s ease-out">
            <div class="about-icon gradient-1"><i class="fas fa-shield-halved"></i></div>
            <h3>Secure &amp; Reliable</h3>
            <p>256-bit encryption, 99.9% uptime guarantee, and full PCI-DSS compliance for safe transactions</p>
          </div>
          <div class="about-card" style="animation:slideInUp .7s ease-out .1s both">
            <div class="about-icon gradient-2"><i class="fas fa-bolt"></i></div>
            <h3>Fast &amp; Scalable</h3>
            <p>Handle millions of transactions seamlessly. Built for growth with cloud-native infrastructure</p>
          </div>
          <div class="about-card" style="animation:slideInUp .7s ease-out .2s both">
            <div class="about-icon gradient-3"><i class="fas fa-headset"></i></div>
            <h3>24/7 Support</h3>
            <p>Dedicated support team ready to help. Live chat, email, and phone support available</p>
          </div>
          <div class="about-card" style="animation:slideInUp .7s ease-out .3s both">
            <div class="about-icon gradient-4"><i class="fas fa-chart-pie"></i></div>
            <h3>Advanced Analytics</h3>
            <p>Real-time insights and reports. Track metrics that matter to your business growth</p>
          </div>
        </div>
        <div class="about-cta">
          <div class="cta-content">
            <h3>Ready to unlock full potential?</h3>
            <p>Upgrade your plan and get access to all premium features today.</p>
          </div>
          <a href="?p=plans" class="cta-button"><i class="fas fa-rocket"></i> Explore Plans</a>
        </div>
      </div>
    </div>

    <?php elseif($page=='plans'): ?>
    <div class="page-header">
      <div class="page-title"><i class="fas fa-gem ti"></i> Plans &amp; Pricing</div>
      <div class="page-sub">Choose the perfect plan for your needs. Switch anytime.</div>
    </div>

    <?php if($msg&&!$msgok): ?><div class="amsg er" style="margin-bottom:20px"><i class="fas fa-triangle-exclamation"></i> <?=esc($msg)?></div><?php endif; ?>
    <?php if($msg&&$msgok): ?><div class="amsg ok" style="margin-bottom:20px"><i class="fas fa-check"></i> <?=esc($msg)?></div><?php endif; ?>

    <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;flex-wrap:wrap">
      <div class="billing-toggle">
        <button class="billing-opt on" id="bm" onclick="setBilling('monthly',this)">Monthly</button>
        <button class="billing-opt" id="ba" onclick="setBilling('annual',this)">Annual <span class="save-badge">Save 17%</span></button>
      </div>
      <span style="color:var(--txt3);font-size:.82rem"><i class="fas fa-lightbulb" style="margin-right:4px"></i>Annual billing saves up to ₹5,988/year</span>
    </div>

    <div class="plans-grid">
      <?php foreach($plans as $slug=>$p): $isCur=strtolower($me['plan'])==strtolower($p['name']); ?>
      <div class="plan-card <?=$p['is_popular']?'popular':''?> <?=$isCur?'current':''?>">
        <i class="fas <?=$p['icon']?> plan-icon"></i>
        <div class="plan-name"><?=esc($p['name'])?></div>
        <?php if($p['badge_label']): ?><span class="tag" style="margin-bottom:8px"><?=esc($p['badge_label'])?></span><?php endif; ?>
        <div class="plan-price-wrap">
          <div class="plan-price" data-monthly="<?=$p['price_monthly']?>" data-annual="<?=$p['price_annual']?>" id="price-<?=$slug?>">
            <?php if($p['price_monthly']==0): ?>
              <span>FREE</span>
            <?php else: ?>
              <sup>₹</sup><?=number_format($p['price_monthly'],0)?>
            <?php endif; ?>
          </div>
          <span class="plan-price-period" id="period-<?=$slug?>">per month</span>
          <?php if($p['price_annual']>0): ?><div class="plan-price-annual" id="annual-note-<?=$slug?>">₹<?=number_format($p['price_annual'],0)?>/yr · save ₹<?=number_format(($p['price_monthly']*12)-$p['price_annual'],0)?></div><?php endif; ?>
        </div>
        <div class="plan-divider"></div>
        <ul class="plan-features">
          <?php foreach($p['feature_list'] as $f): ?>
          <li <?=!$f['inc']?'class="disabled"':''?>><span class="fi <?=$f['inc']?'yes':'no'?>"><?=$f['inc']?'✓':'✗'?></span><?=esc($f['text'])?></li>
          <?php endforeach; ?>
          <li><span class="fi yes">✓</span><?=$p['max_projects']==-1?'Unlimited':$p['max_projects']?> Projects</li>
          <li><span class="fi yes">✓</span><?=$p['storage_gb']?> GB Storage</li>
          <li><span class="fi yes">✓</span><?=$p['team_members']?> Team Member<?=$p['team_members']>1?'s':''?></li>
          <?php if($p['api_calls']>0||$p['api_calls']==-1): ?>
          <li><span class="fi yes">✓</span><?=$p['api_calls']==-1?'Unlimited API':number_format($p['api_calls']).' API Calls/mo'?></li>
          <?php endif; ?>
        </ul>
        <?php if($isCur): ?>
          <button class="btn-plan btn-plan-current" disabled><i class="fas fa-check"></i> Current Plan</button>
        <?php elseif($p['price_monthly']==0): ?>
          <button class="btn-plan btn-plan-free" disabled>Free Forever</button>
        <?php else: ?>
          <button class="btn-plan btn-plan-get" onclick="openModal('<?=esc($slug)?>','<?=esc($p['name'])?>','<?=$p['icon']?>',<?=$p['price_monthly']?>,<?=$p['price_annual']?>)">
            Get <?=esc($p['name'])?> →
          </button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="section-card">
      <div class="section-head"><h3><i class="fas fa-chart-bar"></i> Feature Comparison</h3></div>
      <div class="tbl-wrap">
        <table class="compare-table">
          <thead>
            <tr>
              <th>Feature</th>
              <?php foreach($plans as $slug=>$p): ?>
              <th><i class="fas <?=$p['icon']?>"></i> <?=esc($p['name'])?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php
            $rows=[
              'Max Projects'       => array_map(fn($p)=>$p['max_projects']==-1?'∞':$p['max_projects'],$plans),
              'Storage (GB)'       => array_map(fn($p)=>$p['storage_gb'].' GB',$plans),
              'Team Members'       => array_map(fn($p)=>$p['team_members']==-1?'∞':$p['team_members'],$plans),
              'API Calls / Month'  => array_map(fn($p)=>$p['api_calls']==0?'—':($p['api_calls']==-1?'∞':number_format($p['api_calls'])),$plans),
              'Custom Domain'      => [false,false,true,true,true],
              'Advanced Analytics' => [false,false,true,true,true],
              'Priority Support'   => [false,false,true,true,true],
              'White-label'        => [false,false,false,true,true],
              'SLA / Uptime'       => ['—','—','99.5%','99.9%','99.99%'],
              'SSO Integration'    => [false,false,false,false,true],
            ];
            foreach($rows as $feat=>$vals):
            ?>
            <tr>
              <td><?=$feat?></td>
              <?php foreach(array_values($vals) as $v): ?>
              <td><?php if($v===true): ?><span class="check"><i class="fas fa-check"></i></span><?php elseif($v===false): ?><span class="cross">—</span><?php else: ?><?=$v?><?php endif; ?></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif($page=='payments'): ?>
    <div class="page-header">
      <div class="page-title"><i class="fas fa-credit-card ti"></i> Payments</div>
      <div class="page-sub">All your transaction records and payment history.</div>
    </div>
    <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
      <div class="stat-card c2"><div class="stat-icon"><i class="fas fa-money-bill"></i></div><div class="stat-val">₹<?=number_format($totalSpent,0)?></div><div class="stat-lbl">Total Spent</div></div>
      <div class="stat-card c1"><div class="stat-icon"><i class="fas fa-receipt"></i></div><div class="stat-val"><?=count($myPayments)?></div><div class="stat-lbl">Transactions</div></div>
      <div class="stat-card c3"><div class="stat-icon"><i class="fas fa-check"></i></div><div class="stat-val"><?=count(array_filter($myPayments,fn($p)=>$p['status']==='success'))?></div><div class="stat-lbl">Successful</div></div>
    </div>
    <div class="section-card">
      <div class="section-head"><h3><i class="fas fa-list"></i> Transaction History</h3></div>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>#</th><th>Plan</th><th>Billing</th><th>Amount</th><th>Method</th><th>Card / UPI / Bank</th><th>Status</th><th>Txn ID</th><th>Date</th></tr></thead>
          <tbody>
          <?php if(empty($myPayments)): ?>
            <tr class="empty-row"><td colspan="9"><i class="fas fa-credit-card empty-icon"></i>No transactions found</td></tr>
          <?php else: foreach($myPayments as $i=>$pay): ?>
            <tr>
              <td style="color:var(--txt3)"><?=$i+1?></td>
              <td><span class="badge badge-<?=strtolower($pay['plan'])?>"><?=esc($pay['plan'])?></span></td>
              <td><span class="tag"><?=ucfirst($pay['billing_cycle']??'monthly')?></span></td>
              <td class="fw-bold" style="color:var(--pri)">₹<?=number_format($pay['amount'],2)?></td>
              <td><span class="badge badge-<?=$pay['pay_method']?>"><?=strtoupper($pay['pay_method'])?></span></td>
              <td style="color:var(--txt2);font-size:.78rem"><?=esc($pay['card_masked']?:($pay['upi_id']?:($pay['bank_name']?:'—')))?></td>
              <td><span class="badge badge-<?=$pay['status']??'success'?>"><?=ucfirst($pay['status']??'success')?></span></td>
              <td><span class="mono"><?=esc($pay['txn_id'])?></span></td>
              <td style="color:var(--txt2)"><?=date('d M Y, h:i A',strtotime($pay['paid_at']))?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif($page=='invoices'): ?>
    <div class="page-header">
      <div class="page-title"><i class="fas fa-receipt ti"></i> Invoices</div>
      <div class="page-sub">Download and manage your invoices.</div>
    </div>
    <?php
    $invs=[];
    $ir=$conn->query("SELECT i.*,p.plan,p.pay_method FROM invoices i JOIN payments p ON p.id=i.payment_id WHERE i.user_id=$uid ORDER BY i.id DESC");
    while($row=$ir->fetch_assoc()) $invs[]=$row;
    ?>
    <div class="section-card">
      <div class="section-head"><h3><i class="fas fa-list"></i> All Invoices</h3></div>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>#</th><th>Invoice No</th><th>Plan</th><th>Subtotal</th><th>Tax (18%)</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
          <?php if(empty($invs)): ?>
            <tr class="empty-row"><td colspan="8"><i class="fas fa-receipt empty-icon"></i>No invoices yet</td></tr>
          <?php else: foreach($invs as $i=>$inv): ?>
            <tr>
              <td style="color:var(--txt3)"><?=$i+1?></td>
              <td><span class="mono" style="color:var(--pri)"><?=esc($inv['invoice_no'])?></span></td>
              <td><span class="badge badge-<?=strtolower($inv['plan'])?>"><?=esc($inv['plan'])?></span></td>
              <td>₹<?=number_format($inv['amount'],2)?></td>
              <td>₹<?=number_format($inv['tax_amount'],2)?></td>
              <td class="fw-bold" style="color:var(--pri)">₹<?=number_format($inv['total'],2)?></td>
              <td><span class="badge badge-<?=$inv['status']==='paid'?'success':'pending'?>"><?=ucfirst($inv['status'])?></span></td>
              <td style="color:var(--txt2)"><?=date('d M Y',strtotime($inv['issued_at']))?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif($page=='profile'): ?>
    <div class="page-header">
      <div class="page-title"><i class="fas fa-user ti"></i> My Profile</div>
      <div class="page-sub">Manage your personal information and security settings.</div>
    </div>
    <?php if($msg): ?><div class="amsg <?=$msgok?'ok':'er'?>" style="margin-bottom:20px"><i class="fas <?=$msgok?'fa-check':'fa-triangle-exclamation'?>"></i> <?=esc($msg)?></div><?php endif; ?>
    <div class="profile-grid">
      <div>
        <div class="profile-sidebar-card" style="margin-bottom:16px">
          <div class="profile-ava"><?=strtoupper(substr($me['name'],0,1))?></div>
          <div class="profile-name"><?=esc($me['name'] ?? 'Unknown')?></div>
          <div class="profile-email"><?=esc($me['email'] ?? 'unknown@example.com')?></div>
          <div class="profile-since">Member since <?=date('F Y',strtotime($me['created_at']))?></div>
          <div class="profile-stats">
            <div class="ps-stat"><div class="sv"><?=count($myPayments)?></div><div class="sl">Payments</div></div>
            <div class="ps-stat"><div class="sv">₹<?=number_format($totalSpent,0)?></div><div class="sl">Spent</div></div>
            <div class="ps-stat"><div class="sv"><?=esc($me['plan'])?></div><div class="sl">Plan</div></div>
            <div class="ps-stat"><div class="sv"><?=$me['login_count']??0?></div><div class="sl">Logins</div></div>
          </div>
        </div>

        <div class="section-card">
          <div class="section-head"><h3><i class="fas fa-key"></i> Account Details</h3></div>
          <div class="section-body">
            <div style="font-size:.82rem;display:flex;flex-direction:column;gap:10px">
              <?php $rows2=['Referral Code'=>['mono',$me['referral_code']??'—'],'Last Login'=>['',$me['last_login']?date('d M Y, h:i A',strtotime($me['last_login'])):'Never'],'Plan Expires'=>['',$me['plan_expires']?date('d M Y',strtotime($me['plan_expires'])):'N/A'],'Gender'=>['',$me['gender']??'—'],'City'=>['',$me['city']??'—'],'Country'=>['',$me['country']??'—']]; ?>
              <?php foreach($rows2 as $k=>[$cls,$v]): ?>
              <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border)">
                <span style="color:var(--txt2)"><?=$k?></span>
                <span class="<?=$cls?>" style="font-weight:600"><?=esc($v)?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div>
        <div class="section-card" style="margin-bottom:16px">
          <div class="section-head"><h3><i class="fas fa-pen"></i> Edit Profile</h3></div>
          <div class="section-body">
            <form method="POST">
              <div class="form-section">
                <h4>Personal Information</h4>
                <div class="form-grid">
                  <div class="form-group"><label class="lbl">Full Name</label><input class="inp" name="name" value="<?=esc($me['name'])?>" required></div>
                  <div class="form-group"><label class="lbl">Phone</label><input class="inp" name="phone" value="<?=esc($me['phone'])?>"></div>
                  <div class="form-group"><label class="lbl">Date of Birth</label><input class="inp" type="date" name="dob" value="<?=esc($me['dob']??'')?>"></div>
                  <div class="form-group">
                    <label class="lbl">Gender</label>
                    <select class="inp" name="gender">
                      <?php foreach(['Male','Female','Other','Prefer not to say'] as $g): ?>
                      <option <?=($me['gender']??'')===$g?'selected':''?>><?=$g?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group"><label class="lbl">City</label><input class="inp" name="city" value="<?=esc($me['city']??'')?>"></div>
                  <div class="form-group">
                    <label class="lbl">Country</label>
                    <select class="inp" name="country">
                      <?php foreach(['India','United States','United Kingdom','Canada','Australia','Singapore','UAE','Other'] as $c): ?>
                      <option <?=($me['country']??'India')===$c?'selected':''?>><?=$c?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </div>
              <div class="form-group"><label class="lbl">Email Address (read-only)</label><input class="inp" type="email" value="<?=esc($me['email'])?>" disabled style="opacity:.5"></div>
              <button class="btn-save" name="update_profile">Save Changes</button>
            </form>
          </div>
        </div>

        <div class="section-card">
          <div class="section-head"><h3><i class="fas fa-lock"></i> Change Password</h3></div>
          <div class="section-body">
            <form method="POST">
              <div class="form-grid">
                <div class="form-group" style="grid-column:1/-1"><label class="lbl">Current Password</label><input class="inp" type="password" name="current_password" required></div>
                <div class="form-group"><label class="lbl">New Password</label><input class="inp" type="password" name="new_password" minlength="8" required></div>
                <div class="form-group"><label class="lbl">Confirm New Password</label><input class="inp" type="password" name="confirm_new" required></div>
              </div>
              <button class="btn-danger" name="change_password">Update Password</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <?php elseif($page=='notifications'): ?>
    <div class="page-header">
      <div class="page-title"><i class="fas fa-bell ti"></i> Notifications</div>
      <div class="page-sub">Stay updated with your account activity.</div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-bottom:14px">
      <?php if($unreadCount>0): ?><a href="?p=notifications&mark_read=1" class="btn-save" style="padding:8px 18px;font-size:.82rem"><i class="fas fa-check"></i> Mark all as read</a><?php endif; ?>
    </div>
    <div class="section-card">
      <div class="tbl-wrap">
        <table>
          <thead><tr><th></th><th>Title</th><th>Message</th><th>Type</th><th>Date</th></tr></thead>
          <tbody>
          <?php if(empty($myNotifs)): ?>
            <tr class="empty-row"><td colspan="5"><i class="fas fa-bell empty-icon"></i>No notifications yet</td></tr>
          <?php else: foreach($myNotifs as $n): $icons=['info'=>'fa-circle-info','success'=>'fa-check','warning'=>'fa-triangle-exclamation','error'=>'fa-circle-xmark']; ?>
            <tr style="<?=$n['is_read']?'':'background:var(--glow)'?>">
              <td><i class="fas <?=$icons[$n['type']]??'fa-bell'?>"></i></td>
              <td class="fw-bold"><?=esc($n['title'])?><?=$n['is_read']?'':' <span class="tag" style="margin-left:6px">New</span>'?></td>
              <td style="color:var(--txt2)"><?=esc($n['message'])?></td>
              <td><span class="badge badge-<?=$n['type']==='success'?'success':($n['type']==='warning'?'pending':'card')?>"><?=ucfirst($n['type'])?></span></td>
              <td style="color:var(--txt3)"><?=date('d M Y, h:i A',strtotime($n['created_at']))?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif($page=='support'): ?>
    <div class="page-header">
      <div class="page-title"><i class="fas fa-comments ti"></i> Support Center</div>
      <div class="page-sub">We are here to help. Our team typically responds within 24 hours.</div>
    </div>
    <?php if($msg): ?><div class="amsg <?=$msgok?'ok':'er'?>" style="margin-bottom:20px"><i class="fas <?=$msgok?'fa-check':'fa-triangle-exclamation'?>"></i> <?=esc($msg)?></div><?php endif; ?>
    <div class="support-grid">
      <div class="section-card">
        <div class="section-head"><h3><i class="fas fa-mailbox"></i> Submit a Ticket</h3></div>
        <div class="section-body">
          <form method="POST">
            <div class="form-group">
              <label class="lbl">Subject</label>
              <input class="inp" name="subject" required>
            </div>
            <div class="form-group">
              <label class="lbl">Priority</label>
              <div class="priority-select" id="prioSelect">
                <?php foreach(['low','medium','high','urgent'] as $p2): ?>
                <div class="prio-opt <?=$p2=='medium'?'on medium':$p2?>" data-p="<?=$p2?>" onclick="setPrio(this)"><?=ucfirst($p2)?></div>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="priority" id="prioInput" value="medium">
            </div>
            <div class="form-group">
              <label class="lbl">Message</label>
              <textarea class="inp" name="message" rows="5" required></textarea>
            </div>
            <button class="btn-save" name="submit_ticket">Submit Ticket →</button>
          </form>
        </div>
      </div>

      <div>
        <div class="section-card" style="margin-bottom:16px">
          <div class="section-head"><h3><i class="fas fa-folder"></i> Your Tickets</h3></div>
          <div class="tbl-wrap">
            <table>
              <thead><tr><th>Subject</th><th>Priority</th><th>Status</th><th>Date</th></tr></thead>
              <tbody>
              <?php if(empty($myTickets)): ?>
                <tr class="empty-row"><td colspan="4"><i class="fas fa-ticket empty-icon"></i>No tickets yet</td></tr>
              <?php else: foreach($myTickets as $t): ?>
                <tr>
                  <td class="fw-bold"><?=esc($t['subject'])?></td>
                  <td><span class="badge" style="text-transform:capitalize;<?=['low'=>'background:rgba(5,150,105,.1);color:var(--grn)','medium'=>'background:rgba(8,145,178,.1);color:var(--cyan)','high'=>'background:rgba(217,119,6,.1);color:var(--amber)','urgent'=>'background:rgba(220,38,38,.1);color:var(--red)'][$t['priority']]??''?>"><?=ucfirst($t['priority'])?></span></td>
                  <td><span class="badge badge-<?=$t['status']==='resolved'||$t['status']==='closed'?'resolved':'open'?>"><?=ucfirst($t['status'])?></span></td>
                  <td style="color:var(--txt3)"><?=date('d M Y',strtotime($t['created_at']))?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="section-card">
          <div class="section-head"><h3><i class="fas fa-lightbulb"></i> Quick Help</h3></div>
          <div class="section-body">
            <div style="display:flex;flex-direction:column;gap:10px">
              <?php $faqs=[['How to upgrade plan?','Go to Plans &amp; Pricing and click "Get [Plan] →" on any plan card.'],['When does my plan expire?','Check your profile or sidebar for the expiry date. Annual plans last 12 months.'],['How to get invoice?','Visit the Invoices page to see all your auto-generated invoices.'],['Forgot password?','Use the Change Password section in your Profile page.']]; ?>
              <?php foreach($faqs as [$q,$a]): ?>
              <div style="background:var(--bg3);padding:12px;border-radius:var(--r);cursor:pointer" onclick="this.querySelector('.faq-ans').style.display=this.querySelector('.faq-ans').style.display==='none'?'block':'none'">
                <div style="font-size:.85rem;font-weight:600"><i class="fas fa-question"></i> <?=$q?></div>
                <div class="faq-ans" style="display:none;color:var(--txt2);font-size:.8rem;margin-top:6px;line-height:1.6"><?=$a?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php elseif($page=='activity'): ?>
    <div class="page-header">
      <div class="page-title"><i class="fas fa-list ti"></i> Activity Log</div>
      <div class="page-sub">A complete history of your account actions.</div>
    </div>
    <?php
    $acts=[];
    $ar=$conn->query("SELECT * FROM activity_log WHERE user_id=$uid ORDER BY id DESC LIMIT 50");
    while($row=$ar->fetch_assoc()) $acts[]=$row;
    ?>
    <div class="section-card">
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>#</th><th>Action</th><th>Description</th><th>IP Address</th><th>Date &amp; Time</th></tr></thead>
          <tbody>
          <?php if(empty($acts)): ?>
            <tr class="empty-row"><td colspan="5"><i class="fas fa-list empty-icon"></i>No activity recorded yet</td></tr>
          <?php else: foreach($acts as $i=>$a): $aicons=['login'=>'fa-circle-check','logout'=>'fa-circle','register'=>'fa-user-plus','payment'=>'fa-credit-card','profile_update'=>'fa-pen','password_change'=>'fa-lock']; ?>
            <tr>
              <td style="color:var(--txt3)"><?=$i+1?></td>
              <td><span style="display:flex;align-items:center;gap:6px"><i class="fas <?=$aicons[$a['action']]??'fa-thumbtack'?>"></i> <?=ucwords(str_replace('_',' ',$a['action']))?></span></td>
              <td style="color:var(--txt2)"><?=esc($a['description']??'—')?></td>
              <td><span class="mono" style="color:var(--txt3)"><?=esc($a['ip_address']??'—')?></span></td>
              <td style="color:var(--txt3)"><?=date('d M Y, h:i A',strtotime($a['created_at']))?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif($page=='about'): ?>
    <div class="page-header">
      <div class="page-title"><i class="fas fa-circle-info ti"></i> About MemberVault</div>
      <div class="page-sub">Modern subscription &amp; membership management platform v2.0</div>
    </div>
    <div class="section-card" style="margin-bottom:20px">
      <div class="section-body">
        <div style="display:flex;align-items:center;gap:20px;margin-bottom:24px">
          <div style="width:64px;height:64px;background:linear-gradient(135deg,var(--pri),var(--acc));border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:2rem"><i class="fas fa-gem"></i></div>
          <div>
            <h2 style="font-size:1.4rem;font-weight:900;letter-spacing:-.5px">MemberVault <span style="color:var(--pri)">v2.0</span></h2>
            <p style="color:var(--txt2);font-size:.88rem">Enterprise-grade subscription management system</p>
          </div>
        </div>
        <p style="color:var(--txt2);line-height:1.8;font-size:.9rem;max-width:680px">MemberVault is a full-stack PHP subscription platform with 5-tier membership plans, multi-method payments, automated invoicing, support ticketing, activity logging, notification system, and a fully responsive dashboard. Built with security-first architecture using prepared statements, password hashing, and session validation.</p>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
      <?php $feats2=[['fa-shield','Secure Auth','bcrypt hashing, session management, brute-force protection'],['fa-gem','5 Tier Plans','Free → Starter → Pro → Business → Enterprise'],['fa-credit-card','Multi-Payment','Card, UPI, Net Banking with breakdown &amp; tax'],['fa-receipt','Auto Invoicing','GST-compliant invoices generated per payment'],['fa-chart-bar','Dashboard','Real-time stats, charts &amp; quick actions'],['fa-bell','Notifications','Auto-push system &amp; read/unread tracking'],['fa-comments','Support','Priority ticketing with status tracking'],['fa-list','Activity Log','Complete audit trail per user'],['fa-ticket','Coupons','Flexible promo code system with discount types'],['fa-mobile','Responsive','Mobile-first, works on all screen sizes']]; ?>
      <?php foreach($feats2 as [$ic,$tt,$dd]): ?>
      <div class="section-card feature-card" style="margin:0">
        <div class="section-body" style="text-align:center">
          <div class="feature-icon" style="font-size:2rem;margin-bottom:10px"><i class="fas <?=$ic?>"></i></div>
          <h4 style="font-size:.9rem;font-weight:700;margin-bottom:6px"><?=$tt?></h4>
          <p style="font-size:.76rem;color:var(--txt2);line-height:1.6"><?=$dd?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div style="text-align:center;padding:60px 20px;color:var(--txt2)">
      <div style="font-size:3rem;margin-bottom:16px"><i class="fas fa-magnifying-glass"></i></div>
      <h3>Page not found</h3>
      <p style="margin-top:8px"><a href="?p=dashboard" style="color:var(--pri)">← Back to Dashboard</a></p>
    </div>
    <?php endif; ?>

    </div>
  </div>
</div>

<?php endif; ?>

<script>
const planData = <?=json_encode(array_map(fn($p)=>['name'=>$p['name'],'icon'=>$p['icon'],'monthly'=>$p['price_monthly'],'annual'=>$p['price_annual']],$plans))?>;
let currentModalPlan=null, currentCycle='monthly', discountAmount=0, discountApplied=false;

function toggleTheme(){
  const b=document.body;
  const next=b.getAttribute('data-theme')==='dark'?'light':'dark';
  b.setAttribute('data-theme',next);
  localStorage.setItem('mv_theme',next);
  
  // Update theme toggle labels
  const label=document.getElementById('toggleLabel');
  const labelAuth=document.getElementById('toggleLabelAuth');
  if(label) label.textContent=next==='dark'?'Dark':'Light';
  if(labelAuth) labelAuth.textContent=next==='dark'?'Dark':'Light';
}
(function(){
  const saved=localStorage.getItem('mv_theme');
  if(saved) document.body.setAttribute('data-theme',saved);
  
  // Set initial label
  const theme=document.body.getAttribute('data-theme')||'light';
  const label=document.getElementById('toggleLabel');
  const labelAuth=document.getElementById('toggleLabelAuth');
  if(label) label.textContent=theme==='dark'?'Dark':'Light';
  if(labelAuth) labelAuth.textContent=theme==='dark'?'Dark':'Light';
})();


function authTab(t){
  document.getElementById('tab-login').classList.toggle('on',t==='login');
  document.getElementById('tab-reg').classList.toggle('on',t==='register');
  document.getElementById('fLogin').style.display=t==='login'?'block':'none';
  document.getElementById('fReg').style.display=t==='register'?'block':'none';
}

function checkStrength(inp){
  const v=inp.value;
  const bar=document.getElementById('strengthBar');
  if(!bar)return;
  let s=0;
  if(v.length>=8)s++;
  if(/[A-Z]/.test(v))s++;
  if(/[0-9]/.test(v))s++;
  if(/[^a-zA-Z0-9]/.test(v))s++;
  const w=['0%','30%','55%','78%','100%'];
  const c=['#ef4444','#f97316','#eab308','#22c55e','#10d9a8'];
  bar.style.width=w[s];
  bar.style.background=c[s];
}

function toggleSidebar(){
  const s=document.getElementById('mainSidebar');
  const o=document.getElementById('sidebarOverlay');
  if(!s||!o)return;
  const open=s.classList.toggle('open');
  o.style.display=open?'block':'none';
}
function closeSidebar(){
  const s=document.getElementById('mainSidebar');
  const o=document.getElementById('sidebarOverlay');
  if(!s||!o)return;
  s.classList.remove('open');
  o.style.display='none';
}

function openModal(slug,name,icon,monthly,annual){
  currentModalPlan={slug,name,icon,monthly:parseFloat(monthly),annual:parseFloat(annual)};
  discountAmount=0;
  discountApplied=false;
  document.getElementById('mPlanInput').value=slug;
  document.getElementById('mPlanLabel').textContent=name;
  document.getElementById('mPlanN').textContent=name;
  document.getElementById('mPlanIcon').textContent=icon;
  const ci=document.getElementById('couponInput');
  const cs=document.getElementById('couponStatus');
  if(ci)ci.value='';
  if(cs)cs.innerHTML='';
  setPayCycle('monthly');
  document.getElementById('payModal').classList.add('show');
}
function closeModal(){
  document.getElementById('payModal').classList.remove('show');
}
document.getElementById('payModal')?.addEventListener('click',function(e){
  if(e.target===this)closeModal();
});

function setPayCycle(c){
  currentCycle=c;
  document.getElementById('mCycle').value=c;
  document.getElementById('mCycleLabel').textContent=c==='annual'?'Annual billing (save 17%)':'Monthly billing';
  updateBreakdown();
}

function updateBreakdown(){
  if(!currentModalPlan)return;
  const base=currentCycle==='annual'?currentModalPlan.annual:currentModalPlan.monthly;
  const disc=discountApplied?discountAmount:0;
  const afterDisc=Math.max(0,base-disc);
  const tax=Math.round(afterDisc*0.18*100)/100;
  const total=Math.round((afterDisc+tax)*100)/100;
  const fmt=v=>'₹'+v.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
  document.getElementById('mAmtDisplay').textContent=fmt(total);
  document.getElementById('pbBase').textContent=fmt(base);
  document.getElementById('pbTax').textContent=fmt(tax);
  document.getElementById('pbTotal').textContent=fmt(total);
  document.getElementById('payBtnAmt').textContent=fmt(total);
  const dr=document.getElementById('pbDiscountRow');
  if(disc>0){
    dr.style.display='flex';
    document.getElementById('pbDiscount').textContent='-'+fmt(disc);
  } else {
    dr.style.display='none';
  }
}

function setM(m,el){
  document.querySelectorAll('.mtab').forEach(t=>t.classList.remove('on'));
  el.classList.add('on');
  document.querySelectorAll('.pane').forEach(p=>p.classList.remove('on'));
  document.getElementById('pm-'+m).classList.add('on');
  document.getElementById('mMethod').value=m;
}

function fmtCard(el){
  let v=el.value.replace(/\D/g,'').slice(0,16);
  el.value=v.replace(/(.{4})/g,'$1 ').trim();
  const disp=v.padEnd(16,'•').replace(/(.{4})/g,'$1 ').trim();
  const d=document.getElementById('cardDisplay');
  if(d)d.textContent=disp;
}
function fmtExp(el){
  let v=el.value.replace(/\D/g,'').slice(0,4);
  if(v.length>=3)v=v.slice(0,2)+'/'+v.slice(2);
  el.value=v;
  const d=document.getElementById('cardExpDisplay');
  if(d)d.textContent=v||'MM/YY';
}

async function applyCoupon(){
  const code=document.getElementById('couponInput').value.trim();
  if(!code)return;
  const st=document.getElementById('couponStatus');
  st.innerHTML='<span style="color:var(--txt3);font-size:.78rem">Checking…</span>';
  try{
    const fd=new FormData();
    fd.append('check_coupon',code);
    const r=await fetch('index.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.valid){
      const base=currentCycle==='annual'?currentModalPlan.annual:currentModalPlan.monthly;
      discountAmount=d.type==='percent'?Math.round(base*d.val/100*100)/100:Math.min(parseFloat(d.val),base);
      discountApplied=true;
      st.innerHTML=`<div class="coupon-status cs-ok"><i class="fas fa-check"></i> "${code}" applied — ${d.type==='percent'?d.val+'% off':'₹'+d.val+' off'}: ${d.desc}</div>`;
      updateBreakdown();
    } else {
      discountApplied=false;
      discountAmount=0;
      st.innerHTML='<div class="coupon-status cs-er"><i class="fas fa-circle-xmark"></i> Invalid or expired coupon code.</div>';
      updateBreakdown();
    }
  }catch(e){
    st.innerHTML='<span style="color:var(--red);font-size:.78rem">Error checking coupon.</span>';
  }
}

function setBilling(c,el){
  document.querySelectorAll('.billing-opt').forEach(b=>b.classList.remove('on'));
  el.classList.add('on');
  const annual=c==='annual';
  document.querySelectorAll('.plan-price').forEach(el2=>{
    const slug=el2.id.replace('price-','');
    const p=planData[slug];
    if(!p)return;
    const price=annual?p.annual:p.monthly;
    el2.innerHTML=price==0?'<span>FREE</span>':'<sup>₹</sup>'+Math.round(price).toLocaleString('en-IN');
  });
  document.querySelectorAll('[id^=period-]').forEach(el2=>{
    el2.textContent=annual?'per year (billed annually)':'per month';
  });
}

function setPrio(el){
  document.querySelectorAll('.prio-opt').forEach(e=>e.className='prio-opt '+e.dataset.p);
  el.classList.add('on');
  document.getElementById('prioInput').value=el.dataset.p;
}

<?php if(isset($_POST['pay'])&&!$msgok&&isset($_POST['plan'])): ?>
document.addEventListener('DOMContentLoaded',()=>{
  const s='<?=esc($_POST['plan']??'')?>';
  const p=planData[s];
  if(p)openModal(s,p.name,p.icon,p.monthly,p.annual);
});
<?php endif; ?>

<?php if(isset($_POST['register'])&&$msg): ?>
document.addEventListener('DOMContentLoaded',()=>authTab('register'));
<?php endif; ?>
</script>
</body>
</html>