<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'employee') {
    header("Location: login.php"); exit;
}

$host="localhost";$dbname="employee_management";$user="root";$pass="";
try {
    $pdo=new PDO("mysql:host=$host;dbname=$dbname;charset=utf8",$user,$pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e){ die("DB Error: ".$e->getMessage()); }

if(isset($_GET['logout'])){ session_destroy(); header("Location: login.php"); exit; }

$eid=$_SESSION['user_id'];
$msg=$msg_type="";

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    if($action==='update_profile'){
        $f="phone=?,address=?"; $p=[trim($_POST['phone']),trim($_POST['address'])];
        if(!empty($_POST['password'])){ $f.=",password=?"; $p[]=password_hash($_POST['password'],PASSWORD_BCRYPT); }
        $p[]=$eid;
        $pdo->prepare("UPDATE employees SET $f WHERE id=?")->execute($p);
        $msg="Profile updated!"; $msg_type="success";
    }
    if($action==='checkin'){
        $pdo->prepare("INSERT INTO attendance (employee_id,date,check_in,status) VALUES (?,CURDATE(),NOW(),'present') ON DUPLICATE KEY UPDATE check_in=IF(check_in IS NULL,NOW(),check_in)")->execute([$eid]);
        $msg="Checked in!"; $msg_type="success";
    }
    if($action==='checkout'){
        $pdo->prepare("UPDATE attendance SET check_out=NOW() WHERE employee_id=? AND date=CURDATE() AND check_out IS NULL")->execute([$eid]);
        $msg="Checked out!"; $msg_type="success";
    }
    if($action==='apply_leave'){
        $pdo->prepare("INSERT INTO leave_requests (employee_id,leave_type,from_date,to_date,reason) VALUES (?,?,?,?,?)")
            ->execute([$eid,$_POST['leave_type'],$_POST['from_date'],$_POST['to_date'],trim($_POST['reason'])]);
        $msg="Leave request submitted!"; $msg_type="success";
    }
    if($action==='add_task'){
        $pdo->prepare("INSERT INTO tasks (employee_id,title,description,priority,due_date) VALUES (?,?,?,?,?)")
            ->execute([$eid,trim($_POST['title']),trim($_POST['description']),$_POST['priority'],$_POST['due_date']?:null]);
        $msg="Task added!"; $msg_type="success";
    }
    if($action==='update_task'){
        $pdo->prepare("UPDATE tasks SET status=? WHERE id=? AND employee_id=?")->execute([$_POST['status'],$_POST['task_id'],$eid]);
    }
    if($action==='delete_task'){
        $pdo->prepare("DELETE FROM tasks WHERE id=? AND employee_id=?")->execute([$_POST['task_id'],$eid]);
    }
}

$emp=$pdo->prepare("SELECT * FROM employees WHERE id=?");
$emp->execute([$eid]); $emp=$emp->fetch(PDO::FETCH_ASSOC);
$age=null;
if(!empty($emp['date_of_birth'])) $age=(new DateTime($emp['date_of_birth']))->diff(new DateTime())->y;

$team=$pdo->prepare("SELECT full_name,position FROM employees WHERE department=? AND id!=? AND status='active' LIMIT 8");
$team->execute([$emp['department'],$eid]); $team=$team->fetchAll(PDO::FETCH_ASSOC);

$todayAtt=$pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND date=CURDATE()");
$todayAtt->execute([$eid]); $todayAtt=$todayAtt->fetch(PDO::FETCH_ASSOC);

$monthAtt=$pdo->prepare("SELECT status,COUNT(*) as cnt FROM attendance WHERE employee_id=? AND MONTH(date)=MONTH(CURDATE()) AND YEAR(date)=YEAR(CURDATE()) GROUP BY status");
$monthAtt->execute([$eid]); $attStats=[];
foreach($monthAtt->fetchAll(PDO::FETCH_ASSOC) as $r) $attStats[$r['status']]=$r['cnt'];

$recentAtt=$pdo->prepare("SELECT * FROM attendance WHERE employee_id=? ORDER BY date DESC LIMIT 10");
$recentAtt->execute([$eid]); $recentAtt=$recentAtt->fetchAll(PDO::FETCH_ASSOC);

$leaves=$pdo->prepare("SELECT * FROM leave_requests WHERE employee_id=? ORDER BY created_at DESC LIMIT 10");
$leaves->execute([$eid]); $leaves=$leaves->fetchAll(PDO::FETCH_ASSOC);
$leaveStats=$pdo->prepare("SELECT status,COUNT(*) as cnt FROM leave_requests WHERE employee_id=? GROUP BY status");
$leaveStats->execute([$eid]); $lStats=[];
foreach($leaveStats->fetchAll(PDO::FETCH_ASSOC) as $r) $lStats[$r['status']]=$r['cnt'];

$ann=$pdo->query("SELECT a.*,h.full_name as hr_name FROM announcements a LEFT JOIN hr h ON h.id=a.hr_id ORDER BY a.created_at DESC LIMIT 10");
$announcements=$ann->fetchAll(PDO::FETCH_ASSOC);

$tasks=$pdo->prepare("SELECT * FROM tasks WHERE employee_id=? ORDER BY FIELD(status,'in_progress','todo','done'),FIELD(priority,'high','medium','low')");
$tasks->execute([$eid]); $tasks=$tasks->fetchAll(PDO::FETCH_ASSOC);
$taskStats=['todo'=>0,'in_progress'=>0,'done'=>0];
foreach($tasks as $t) $taskStats[$t['status']]++;

$slips=[];
for($i=0;$i<6;$i++){
    $slips[]=['month'=>date('F Y',strtotime("-$i months")),'salary'=>$emp['salary'],'basic'=>round($emp['salary']*0.5),'hra'=>round($emp['salary']*0.2),'allowance'=>round($emp['salary']*0.2),'pf'=>round($emp['salary']*0.12),'tax'=>round($emp['salary']*0.05),'net'=>round($emp['salary']-(($emp['salary']*0.12)+($emp['salary']*0.05)))];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My Dashboard — ACE International</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--ink:#0f0f13;--surface:#16161e;--card:#1e1e2a;--border:#2a2a3a;--accent:#6c63ff;--accent2:#ff6b9d;--gold:#f5c842;--green:#4ade80;--blue:#38bdf8;--orange:#fb923c;--text:#e8e8f0;--muted:#888899;--error:#ff6b6b;--sidebar-w:260px}
body{font-family:'DM Sans',sans-serif;background:var(--ink);color:var(--text);display:flex;min-height:100vh}
.sidebar{width:var(--sidebar-w);background:var(--card);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;overflow-y:auto}
.sidebar-brand{padding:24px 20px 18px;border-bottom:1px solid var(--border)}
.brand-mark{display:flex;align-items:center;gap:10px}
.brand-logo{width:38px;height:38px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:10px;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:13px;color:#fff;flex-shrink:0}
.brand-text{font-family:'Syne',sans-serif;font-weight:700;font-size:15px}
.brand-role{font-size:10px;color:var(--green);font-weight:600;letter-spacing:1px;text-transform:uppercase;margin-top:2px}
.sidebar-nav{padding:16px 12px;flex:1}
.nav-section{font-size:10px;letter-spacing:1.5px;color:var(--muted);text-transform:uppercase;padding:0 8px;margin:16px 0 6px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:var(--muted);font-size:13.5px;font-weight:500;transition:all .15s;margin-bottom:2px;text-decoration:none;cursor:pointer;border:none;background:none;width:100%;text-align:left}
.nav-item:hover{background:rgba(108,99,255,.1);color:var(--text)}
.nav-item.active{background:rgba(108,99,255,.15);border-left:3px solid var(--accent);padding-left:9px;color:var(--accent)}
.nav-item .icon{font-size:16px;width:20px;text-align:center;flex-shrink:0}
.sidebar-footer{padding:16px;border-top:1px solid var(--border)}
.user-card{display:flex;align-items:center;gap:10px}
.avatar-sm{width:36px;height:36px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0}
.user-info .name{font-size:13px;font-weight:500}.user-info .role{font-size:11px;color:var(--muted)}
.logout-btn{margin-left:auto;background:none;border:none;color:var(--muted);cursor:pointer;font-size:16px;transition:color .2s;text-decoration:none}
.logout-btn:hover{color:var(--error)}
.main{margin-left:var(--sidebar-w);flex:1;min-height:100vh}
.page{display:none;padding:28px;animation:fadeIn .25s ease}
.page.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.page-title{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--muted);margin-bottom:22px}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:22px}
.card-title{font-family:'Syne',sans-serif;font-weight:700;font-size:14px;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.full{grid-column:1/-1}
.mb16{margin-bottom:16px}.mb24{margin-bottom:24px}
.alert{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:10px;font-size:14px;margin-bottom:18px}
.alert-success{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.3);color:var(--green)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-active,.badge-present,.badge-approved{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--green)}
.badge-pending,.badge-half-day{background:rgba(245,200,66,.12);border:1px solid rgba(245,200,66,.3);color:var(--gold)}
.badge-inactive,.badge-absent,.badge-rejected{background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.3);color:var(--error)}
.badge-late{background:rgba(251,146,60,.12);border:1px solid rgba(251,146,60,.3);color:var(--orange)}
.badge-todo{background:rgba(136,136,153,.12);border:1px solid rgba(136,136,153,.3);color:var(--muted)}
.badge-in_progress{background:rgba(56,189,248,.12);border:1px solid rgba(56,189,248,.3);color:var(--blue)}
.badge-done{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--green)}
.badge-high{background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.3);color:var(--error)}
.badge-medium{background:rgba(245,200,66,.12);border:1px solid rgba(245,200,66,.3);color:var(--gold)}
.badge-low{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--green)}
.badge-urgent{background:rgba(255,107,107,.15);border:1px solid rgba(255,107,107,.4);color:var(--error)}
.badge-important{background:rgba(245,200,66,.15);border:1px solid rgba(245,200,66,.4);color:var(--gold)}
.badge-normal{background:rgba(108,99,255,.12);border:1px solid rgba(108,99,255,.3);color:var(--accent)}
.stat-mini{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;text-align:center}
.stat-mini-icon{font-size:22px;margin-bottom:6px}
.stat-mini-value{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;line-height:1}
.stat-mini-label{font-size:11px;color:var(--muted);margin-top:4px}
.hero-card{background:linear-gradient(135deg,rgba(108,99,255,.2),rgba(255,107,157,.15));border:1px solid rgba(108,99,255,.3);border-radius:20px;padding:28px;display:flex;align-items:center;gap:22px;margin-bottom:20px;position:relative;overflow:hidden}
.hero-card::before{content:'';position:absolute;top:-50%;right:-5%;width:260px;height:260px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));opacity:.08;filter:blur(40px)}
.hero-avatar{width:72px;height:72px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:18px;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:26px;color:#fff;flex-shrink:0;box-shadow:0 8px 28px rgba(108,99,255,.4)}
.hero-name{font-family:'Syne',sans-serif;font-size:22px;font-weight:800}
.hero-pos{font-size:14px;color:var(--muted);margin-top:3px}
.hero-dept{display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:5px 12px;background:rgba(108,99,255,.15);border:1px solid rgba(108,99,255,.3);border-radius:20px;font-size:12px;color:var(--accent);font-weight:500}
.hero-right{margin-left:auto;text-align:right}
.info-row{display:flex;justify-content:space-between;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border);font-size:13.5px;gap:12px}
.info-row:last-child{border-bottom:none}
.info-label{color:var(--muted);flex-shrink:0}
.info-value{font-weight:500;text-align:right;word-break:break-word}
.salary-big{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:var(--green)}
.btn{padding:10px 18px;border-radius:10px;border:none;font-family:'Syne',sans-serif;font-weight:600;font-size:13px;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;box-shadow:0 4px 14px rgba(108,99,255,.3)}
.btn-primary:hover{opacity:.9;transform:translateY(-1px)}
.btn-green{background:rgba(74,222,128,.15);border:1px solid rgba(74,222,128,.3);color:var(--green)}
.btn-green:hover{background:rgba(74,222,128,.25)}
.btn-red{background:rgba(255,107,107,.15);border:1px solid rgba(255,107,107,.3);color:var(--error)}
.btn-red:hover{background:rgba(255,107,107,.25)}
.btn-ghost{background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--muted)}
.btn-ghost:hover{color:var(--text);border-color:var(--accent)}
.btn-sm{padding:6px 12px;font-size:11px;border-radius:7px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group label{font-size:12px;font-weight:500;color:var(--muted)}
.form-group input,.form-group select,.form-group textarea{padding:10px 13px;background:var(--surface);border:1.5px solid var(--border);border-radius:10px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:13.5px;outline:none;transition:border-color .2s;resize:vertical}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--accent)}
.form-group input::placeholder,.form-group textarea::placeholder{color:var(--muted)}
.form-group input:disabled{opacity:.5;cursor:not-allowed}
.form-group select option{background:var(--card)}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{padding:10px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
.tbl td{padding:12px 14px;border-bottom:1px solid rgba(42,42,58,.5);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(108,99,255,.04)}
.clock-card{background:linear-gradient(135deg,rgba(56,189,248,.15),rgba(108,99,255,.1));border:1px solid rgba(56,189,248,.25);border-radius:18px;padding:24px;display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px}
.clock-time{font-family:'Syne',sans-serif;font-size:40px;font-weight:800;letter-spacing:-1px}
.clock-date{font-size:13px;color:var(--muted);margin-top:3px}
.task-cols{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.task-col-head{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between}
.task-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:8px;transition:border-color .2s}
.task-card:hover{border-color:var(--accent)}
.task-title{font-size:13px;font-weight:500;margin-bottom:6px}
.task-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:8px}
.task-actions-row{display:flex;gap:6px;margin-top:10px}
.slip-list{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.slip-item{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;cursor:pointer;transition:all .2s}
.slip-item:hover,.slip-item.selected{border-color:var(--accent);background:rgba(108,99,255,.08)}
.slip-month{font-family:'Syne',sans-serif;font-weight:700;font-size:14px}
.slip-net{font-size:13px;color:var(--green);margin-top:3px}
.payslip-detail{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;display:none}
.payslip-detail.show{display:block}
.slip-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border)}
.slip-logo{font-family:'Syne',sans-serif;font-weight:800;font-size:18px}
.slip-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(42,42,58,.4);font-size:13px}
.slip-row:last-child{border-bottom:none}
.slip-row.total{font-family:'Syne',sans-serif;font-weight:700;font-size:15px;padding-top:12px;border-top:2px solid var(--border)}
.earning{color:var(--green)}.deduction{color:var(--error)}
.ann-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:12px;position:relative;overflow:hidden}
.ann-card.urgent{border-color:rgba(255,107,107,.4)}
.ann-card.important{border-color:rgba(245,200,66,.3)}
.ann-stripe{position:absolute;left:0;top:0;bottom:0;width:4px}
.ann-stripe.urgent{background:var(--error)}
.ann-stripe.important{background:var(--gold)}
.ann-stripe.normal{background:var(--accent)}
.team-member{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid rgba(42,42,58,.5)}
.team-member:last-child{border-bottom:none}
.member-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0}
.member-name{font-size:13px;font-weight:500}.member-pos{font-size:11px;color:var(--muted);margin-top:1px}
.notif-toast{position:fixed;top:20px;right:20px;z-index:9999;min-width:280px;animation:slideIn .3s ease}
@keyframes slideIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-mark">
      <div class="brand-logo">ACE</div>
      <div><div class="brand-text">ACE International</div><div class="brand-role">● Employee Portal</div></div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <button class="nav-item active" onclick="showPage('overview',this)"><span class="icon">🏠</span> Overview</button>
    <button class="nav-item" onclick="showPage('attendance',this)"><span class="icon">📅</span> Attendance</button>
    <button class="nav-item" onclick="showPage('leave',this)"><span class="icon">🗓️</span> Leave Requests</button>
    <div class="nav-section">Work</div>
    <button class="nav-item" onclick="showPage('tasks',this)"><span class="icon">🎯</span> My Tasks</button>
    <button class="nav-item" onclick="showPage('salary',this)"><span class="icon">💰</span> Salary Slips</button>
    <button class="nav-item" onclick="showPage('notices',this)"><span class="icon">📝</span> Announcements <?php if(count($announcements)): ?><span style="margin-left:auto;background:var(--accent);color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px"><?=count($announcements)?></span><?php endif; ?></button>
    <div class="nav-section">Account</div>
    <button class="nav-item" onclick="showPage('profile',this)"><span class="icon">✏️</span> Edit Profile</button>
  </nav>
  <div class="sidebar-footer">
    <div class="user-card">
      <div class="avatar-sm"><?=strtoupper(substr($emp['full_name'],0,1))?></div>
      <div class="user-info"><div class="name"><?=htmlspecialchars($emp['full_name'])?></div><div class="role"><?=htmlspecialchars($emp['position'])?></div></div>
      <a href="?logout=1" class="logout-btn">⏻</a>
    </div>
  </div>
</aside>

<main class="main">

<?php if($msg): ?>
<div class="notif-toast">
  <div class="alert alert-<?=$msg_type?>"><?=$msg_type==='success'?'✅':'⚠️'?> <?=htmlspecialchars($msg)?></div>
</div>
<script>setTimeout(()=>document.querySelector('.notif-toast')?.remove(),3500)</script>
<?php endif; ?>

<!-- OVERVIEW -->
<div class="page active" id="page-overview">
  <div class="page-title">Dashboard</div>
  <div class="page-sub">Welcome back, <?=htmlspecialchars($emp['full_name'])?>! — <?=date('l, F j, Y')?></div>

  <div class="hero-card mb24">
    <div class="hero-avatar"><?=strtoupper(substr($emp['full_name'],0,1))?></div>
    <div>
      <div class="hero-name"><?=htmlspecialchars($emp['full_name'])?></div>
      <div class="hero-pos"><?=htmlspecialchars($emp['position'])?></div>
      <div class="hero-dept">🏢 <?=htmlspecialchars($emp['department'])?></div>
    </div>
    <div class="hero-right"><span class="badge badge-active">● Active</span><div style="font-size:12px;color:var(--muted);margin-top:8px">ID #<?=str_pad($emp['id'],4,'0',STR_PAD_LEFT)?></div></div>
  </div>

  <div class="grid4 mb24">
    <div class="stat-mini"><div class="stat-mini-icon">📆</div><div class="stat-mini-value"><?=(new DateTime($emp['hire_date']))->diff(new DateTime())->y?> yrs</div><div class="stat-mini-label">Tenure</div></div>
    <div class="stat-mini"><div class="stat-mini-icon">✅</div><div class="stat-mini-value"><?=$attStats['present']??0?></div><div class="stat-mini-label">Present This Month</div></div>
    <div class="stat-mini"><div class="stat-mini-icon">🗓️</div><div class="stat-mini-value"><?=$lStats['approved']??0?></div><div class="stat-mini-label">Leaves Approved</div></div>
    <div class="stat-mini"><div class="stat-mini-icon">🎯</div><div class="stat-mini-value"><?=$taskStats['done']?></div><div class="stat-mini-label">Tasks Done</div></div>
  </div>

  <div class="grid2 mb24">
    <div class="card">
      <div class="card-title">📋 Personal Details</div>
      <div class="info-row"><span class="info-label">Employee ID</span><span class="info-value">#<?=str_pad($emp['id'],4,'0',STR_PAD_LEFT)?></span></div>
      <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?=htmlspecialchars($emp['email'])?></span></div>
      <div class="info-row"><span class="info-label">Phone</span><span class="info-value"><?=htmlspecialchars($emp['phone']?:'—')?></span></div>
      <div class="info-row"><span class="info-label">Date of Birth</span><span class="info-value"><?=!empty($emp['date_of_birth'])?date('d M Y',strtotime($emp['date_of_birth'])).($age?' ('.$age.' yrs)':''):'—'?></span></div>
      <div class="info-row"><span class="info-label">Address</span><span class="info-value" style="max-width:55%"><?=!empty($emp['address'])?nl2br(htmlspecialchars($emp['address'])):'—'?></span></div>
    </div>
    <div class="card">
      <div class="card-title">💼 Employment Info</div>
      <div class="info-row"><span class="info-label">Department</span><span class="info-value"><?=htmlspecialchars($emp['department'])?></span></div>
      <div class="info-row"><span class="info-label">Position</span><span class="info-value"><?=htmlspecialchars($emp['position'])?></span></div>
      <div class="info-row"><span class="info-label">Hire Date</span><span class="info-value"><?=date('d M Y',strtotime($emp['hire_date']))?></span></div>
      <div class="info-row"><span class="info-label">Monthly Salary</span><span class="info-value salary-big">₹<?=number_format($emp['salary'],0)?></span></div>
      <div class="info-row"><span class="info-label">Status</span><span class="info-value"><span class="badge badge-active">● Active</span></span></div>
    </div>
  </div>

  <?php if(!empty($announcements)): ?>
  <div class="card mb24">
    <div class="card-title">📝 Latest Announcements</div>
    <?php foreach(array_slice($announcements,0,2) as $a): ?>
    <div class="ann-card <?=htmlspecialchars($a['priority'])?>" style="margin-bottom:10px">
      <div class="ann-stripe <?=htmlspecialchars($a['priority'])?>"></div>
      <div style="padding-left:12px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px">
          <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px"><?=htmlspecialchars($a['title'])?></span>
          <span class="badge badge-<?=$a['priority']?>"><?=ucfirst($a['priority'])?></span>
        </div>
        <div style="font-size:13px;color:var(--muted)"><?=htmlspecialchars(substr($a['body'],0,120)).(strlen($a['body'])>120?'...':'')?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <button onclick="showPage('notices',document.querySelectorAll('.nav-item')[5])" style="font-size:13px;color:var(--accent);background:none;border:none;cursor:pointer;padding:0">View all →</button>
  </div>
  <?php endif; ?>

  <?php if(!empty($team)): ?>
  <div class="card">
    <div class="card-title">👥 My Team — <?=htmlspecialchars($emp['department'])?> <span style="font-size:11px;color:var(--muted);font-weight:400">(<?=count($team)?> members)</span></div>
    <?php foreach($team as $t): ?>
    <div class="team-member">
      <div class="member-avatar"><?=strtoupper(substr($t['full_name'],0,1))?></div>
      <div><div class="member-name"><?=htmlspecialchars($t['full_name'])?></div><div class="member-pos"><?=htmlspecialchars($t['position'])?></div></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ATTENDANCE -->
<div class="page" id="page-attendance">
  <div class="page-title">📅 Attendance</div>
  <div class="page-sub">Track your daily check-in and check-out</div>

  <div class="clock-card mb24">
    <div>
      <div class="clock-time" id="liveClock">--:--:--</div>
      <div class="clock-date"><?=date('l, d F Y')?></div>
      <div style="margin-top:10px">
        <?php if($todayAtt): ?>
          <?php if($todayAtt['check_in']&&!$todayAtt['check_out']): ?><span class="badge badge-active">● In since <?=date('h:i A',strtotime($todayAtt['check_in']))?></span>
          <?php elseif($todayAtt['check_out']): ?><span class="badge badge-pending">● Out at <?=date('h:i A',strtotime($todayAtt['check_out']))?></span><?php endif; ?>
        <?php else: ?><span class="badge badge-absent">● Not Checked In</span><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <?php if(!$todayAtt||!$todayAtt['check_in']): ?>
      <form method="POST"><input type="hidden" name="action" value="checkin"><button type="submit" class="btn btn-green">✅ Check In</button></form>
      <?php elseif(!$todayAtt['check_out']): ?>
      <form method="POST"><input type="hidden" name="action" value="checkout"><button type="submit" class="btn btn-red">⏹️ Check Out</button></form>
      <?php else: ?><button class="btn btn-ghost" disabled>Done for today ✓</button><?php endif; ?>
    </div>
  </div>

  <div class="grid4 mb24">
    <div class="stat-mini"><div class="stat-mini-icon">✅</div><div class="stat-mini-value"><?=$attStats['present']??0?></div><div class="stat-mini-label">Present</div></div>
    <div class="stat-mini"><div class="stat-mini-icon">❌</div><div class="stat-mini-value"><?=$attStats['absent']??0?></div><div class="stat-mini-label">Absent</div></div>
    <div class="stat-mini"><div class="stat-mini-icon">⏰</div><div class="stat-mini-value"><?=$attStats['late']??0?></div><div class="stat-mini-label">Late</div></div>
    <div class="stat-mini"><div class="stat-mini-icon">🌗</div><div class="stat-mini-value"><?=$attStats['half-day']??0?></div><div class="stat-mini-label">Half-Day</div></div>
  </div>

  <div class="card">
    <div class="card-title">📋 Recent Log (Last 10 Days)</div>
    <?php if(empty($recentAtt)): ?><div style="text-align:center;padding:30px;color:var(--muted)">No records yet.</div>
    <?php else: ?>
    <table class="tbl">
      <thead><tr><th>Date</th><th>Check In</th><th>Check Out</th><th>Hours</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($recentAtt as $r):
        $hrs='—';
        if($r['check_in']&&$r['check_out']){$diff=(strtotime($r['check_out'])-strtotime($r['check_in']))/3600;$hrs=number_format($diff,1).' hrs';}
      ?>
      <tr>
        <td><?=date('d M Y',strtotime($r['date']))?></td>
        <td><?=$r['check_in']?date('h:i A',strtotime($r['check_in'])):'—'?></td>
        <td><?=$r['check_out']?date('h:i A',strtotime($r['check_out'])):'—'?></td>
        <td><?=$hrs?></td>
        <td><span class="badge badge-<?=$r['status']?>"><?=ucfirst($r['status'])?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- LEAVE -->
<div class="page" id="page-leave">
  <div class="page-title">🗓️ Leave Requests</div>
  <div class="page-sub">Apply for leaves and track their status</div>

  <div class="grid3 mb24">
    <div class="stat-mini"><div class="stat-mini-icon">⏳</div><div class="stat-mini-value"><?=$lStats['pending']??0?></div><div class="stat-mini-label">Pending</div></div>
    <div class="stat-mini"><div class="stat-mini-icon">✅</div><div class="stat-mini-value"><?=$lStats['approved']??0?></div><div class="stat-mini-label">Approved</div></div>
    <div class="stat-mini"><div class="stat-mini-icon">❌</div><div class="stat-mini-value"><?=$lStats['rejected']??0?></div><div class="stat-mini-label">Rejected</div></div>
  </div>

  <div class="grid2 mb24">
    <div class="card">
      <div class="card-title">➕ Apply for Leave</div>
      <form method="POST">
        <input type="hidden" name="action" value="apply_leave">
        <div style="display:flex;flex-direction:column;gap:14px">
          <div class="form-group"><label>Leave Type</label>
            <select name="leave_type"><option value="casual">Casual Leave</option><option value="sick">Sick Leave</option><option value="earned">Earned Leave</option><option value="unpaid">Unpaid Leave</option></select>
          </div>
          <div class="grid2" style="gap:12px">
            <div class="form-group"><label>From Date</label><input type="date" name="from_date" required></div>
            <div class="form-group"><label>To Date</label><input type="date" name="to_date" required></div>
          </div>
          <div class="form-group"><label>Reason</label><textarea name="reason" rows="3" placeholder="Briefly describe your reason..."></textarea></div>
          <button type="submit" class="btn btn-primary" style="align-self:flex-start">Submit Request</button>
        </div>
      </form>
    </div>
    <div class="card">
      <div class="card-title">📋 My Leave History</div>
      <?php if(empty($leaves)): ?><div style="text-align:center;padding:20px;color:var(--muted)">No requests yet.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table class="tbl">
        <thead><tr><th>Type</th><th>From</th><th>To</th><th>Status</th><th>HR Note</th></tr></thead>
        <tbody>
        <?php foreach($leaves as $l): ?>
        <tr>
          <td style="text-transform:capitalize"><?=$l['leave_type']?></td>
          <td><?=date('d M',strtotime($l['from_date']))?></td>
          <td><?=date('d M',strtotime($l['to_date']))?></td>
          <td><span class="badge badge-<?=$l['status']?>"><?=ucfirst($l['status'])?></span></td>
          <td style="color:var(--muted);font-size:12px"><?=htmlspecialchars($l['hr_note']?:'—')?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- TASKS -->
<div class="page" id="page-tasks">
  <div class="page-title">🎯 My Tasks</div>
  <div class="page-sub">Manage your personal work tasks</div>

  <div class="grid3 mb24">
    <div class="stat-mini"><div class="stat-mini-icon">📋</div><div class="stat-mini-value"><?=$taskStats['todo']?></div><div class="stat-mini-label">To Do</div></div>
    <div class="stat-mini"><div class="stat-mini-icon">🔄</div><div class="stat-mini-value"><?=$taskStats['in_progress']?></div><div class="stat-mini-label">In Progress</div></div>
    <div class="stat-mini"><div class="stat-mini-icon">✅</div><div class="stat-mini-value"><?=$taskStats['done']?></div><div class="stat-mini-label">Done</div></div>
  </div>

  <div class="card mb24">
    <div class="card-title">➕ Add New Task</div>
    <form method="POST">
      <input type="hidden" name="action" value="add_task">
      <div class="grid2" style="gap:14px">
        <div class="form-group full"><label>Task Title *</label><input type="text" name="title" placeholder="What needs to be done?" required></div>
        <div class="form-group full"><label>Description (optional)</label><textarea name="description" rows="2" placeholder="Add more details..."></textarea></div>
        <div class="form-group"><label>Priority</label><select name="priority"><option value="medium">Medium</option><option value="high">High</option><option value="low">Low</option></select></div>
        <div class="form-group"><label>Due Date (optional)</label><input type="date" name="due_date"></div>
      </div>
      <div style="margin-top:14px"><button type="submit" class="btn btn-primary">Add Task</button></div>
    </form>
  </div>

  <div class="task-cols">
    <?php
    $cols=['todo'=>['label'=>'📋 To Do','color'=>'var(--muted)'],'in_progress'=>['label'=>'🔄 In Progress','color'=>'var(--blue)'],'done'=>['label'=>'✅ Done','color'=>'var(--green)']];
    foreach($cols as $ck=>$col):
      $ct=array_filter($tasks,fn($t)=>$t['status']===$ck);
    ?>
    <div>
      <div class="task-col-head" style="color:<?=$col['color']?>"><?=$col['label']?> <span style="font-size:11px;color:var(--muted)"><?=count($ct)?></span></div>
      <?php foreach($ct as $t): ?>
      <div class="task-card">
        <div class="task-title"><?=htmlspecialchars($t['title'])?></div>
        <?php if($t['description']): ?><div style="font-size:12px;color:var(--muted);line-height:1.5;margin-top:4px"><?=htmlspecialchars(substr($t['description'],0,80)).(strlen($t['description'])>80?'...':'')?></div><?php endif; ?>
        <div class="task-meta">
          <span class="badge badge-<?=$t['priority']?>"><?=ucfirst($t['priority'])?></span>
          <?php if($t['due_date']): ?><span style="font-size:11px;color:var(--muted)">📅 <?=date('d M',strtotime($t['due_date']))?></span><?php endif; ?>
        </div>
        <div class="task-actions-row">
          <?php if($ck!=='done'): ?>
          <form method="POST" style="display:inline"><input type="hidden" name="action" value="update_task"><input type="hidden" name="task_id" value="<?=$t['id']?>"><input type="hidden" name="status" value="<?=$ck==='todo'?'in_progress':'done'?>"><button type="submit" class="btn btn-sm btn-green">→ <?=$ck==='todo'?'Start':'Done'?></button></form>
          <?php endif; ?>
          <form method="POST" style="display:inline"><input type="hidden" name="action" value="delete_task"><input type="hidden" name="task_id" value="<?=$t['id']?>"><button type="submit" class="btn btn-sm btn-red" onclick="return confirm('Delete?')">🗑</button></form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if(empty($ct)): ?><div style="font-size:12px;color:var(--muted);text-align:center;padding:20px;background:var(--surface);border-radius:10px;border:1px dashed var(--border)">Empty</div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- SALARY SLIPS -->
<div class="page" id="page-salary">
  <div class="page-title">💰 Salary Slips</div>
  <div class="page-sub">View your monthly payslips (last 6 months)</div>

  <div class="slip-list">
    <?php foreach($slips as $i=>$s): ?>
    <div class="slip-item <?=$i===0?'selected':''?>" onclick="selectSlip(<?=$i?>)" id="slip-btn-<?=$i?>">
      <div class="slip-month"><?=$s['month']?></div>
      <div class="slip-net">Net ₹<?=number_format($s['net'],0)?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php foreach($slips as $i=>$s): ?>
  <div class="payslip-detail <?=$i===0?'show':''?>" id="slip-detail-<?=$i?>">
    <div class="slip-header">
      <div><div class="slip-logo">ACE International Ltd</div><div style="font-size:12px;color:var(--muted);margin-top:3px">Payslip — <?=$s['month']?></div></div>
      <div style="text-align:right"><div style="font-size:12px;color:var(--muted)">Employee</div><div style="font-family:'Syne',sans-serif;font-weight:700"><?=htmlspecialchars($emp['full_name'])?></div><div style="font-size:12px;color:var(--muted)"><?=htmlspecialchars($emp['department'])?> · <?=htmlspecialchars($emp['position'])?></div></div>
    </div>
    <div class="grid2" style="gap:20px">
      <div>
        <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:13px;color:var(--green);margin-bottom:12px">EARNINGS</div>
        <div class="slip-row"><span>Basic Salary</span><span class="earning">₹<?=number_format($s['basic'],0)?></span></div>
        <div class="slip-row"><span>HRA (20%)</span><span class="earning">₹<?=number_format($s['hra'],0)?></span></div>
        <div class="slip-row"><span>Allowances</span><span class="earning">₹<?=number_format($s['allowance'],0)?></span></div>
        <div class="slip-row total"><span>Gross Total</span><span class="earning">₹<?=number_format($s['salary'],0)?></span></div>
      </div>
      <div>
        <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:13px;color:var(--error);margin-bottom:12px">DEDUCTIONS</div>
        <div class="slip-row"><span>PF (12%)</span><span class="deduction">−₹<?=number_format($s['pf'],0)?></span></div>
        <div class="slip-row"><span>Income Tax (5%)</span><span class="deduction">−₹<?=number_format($s['tax'],0)?></span></div>
        <div class="slip-row" style="border:none"><span></span><span></span></div>
        <div class="slip-row total"><span>Total Deductions</span><span class="deduction">−₹<?=number_format($s['pf']+$s['tax'],0)?></span></div>
      </div>
    </div>
    <div style="margin-top:20px;padding:16px;background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.25);border-radius:12px;display:flex;justify-content:space-between;align-items:center">
      <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:16px">Net Pay</span>
      <span class="salary-big">₹<?=number_format($s['net'],0)?></span>
    </div>
    <div style="margin-top:16px;text-align:right"><button onclick="window.print()" class="btn btn-ghost">🖨️ Print Slip</button></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ANNOUNCEMENTS -->
<div class="page" id="page-notices">
  <div class="page-title">📝 Announcements</div>
  <div class="page-sub">Company-wide notices from HR</div>
  <?php if(empty($announcements)): ?>
  <div class="card" style="text-align:center;padding:40px;color:var(--muted)">No announcements yet.</div>
  <?php else: ?>
  <?php foreach($announcements as $a): ?>
  <div class="ann-card <?=htmlspecialchars($a['priority'])?>" style="margin-bottom:14px">
    <div class="ann-stripe <?=htmlspecialchars($a['priority'])?>"></div>
    <div style="padding-left:12px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:16px"><?=htmlspecialchars($a['title'])?></span>
        <span class="badge badge-<?=$a['priority']?>"><?=ucfirst($a['priority'])?></span>
      </div>
      <div style="font-size:13.5px;color:var(--muted);line-height:1.7"><?=nl2br(htmlspecialchars($a['body']))?></div>
      <div style="font-size:11px;color:var(--muted);margin-top:10px">📌 <?=htmlspecialchars($a['hr_name']??'HR')?> &bull; <?=date('d M Y, h:i A',strtotime($a['created_at']))?></div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- EDIT PROFILE -->
<div class="page" id="page-profile">
  <div class="page-title">✏️ Edit Profile</div>
  <div class="page-sub">Update your contact details</div>
  <div class="card" style="max-width:700px">
    <form method="POST">
      <input type="hidden" name="action" value="update_profile">
      <div class="grid2" style="gap:16px">
        <div class="form-group"><label>Full Name (read-only)</label><input type="text" value="<?=htmlspecialchars($emp['full_name'])?>" disabled></div>
        <div class="form-group"><label>Email (read-only)</label><input type="email" value="<?=htmlspecialchars($emp['email'])?>" disabled></div>
        <div class="form-group"><label>Phone Number</label><input type="text" name="phone" value="<?=htmlspecialchars($emp['phone']??'')?>" placeholder="+91 00000 00000"></div>
        <div class="form-group"><label>New Password (optional)</label><input type="password" name="password" placeholder="Leave blank to keep current"></div>
        <div class="form-group full"><label>Residential Address</label><textarea name="address" rows="3" placeholder="Street, City, State - PIN"><?=htmlspecialchars($emp['address']??'')?></textarea></div>
      </div>
      <div style="margin-top:18px"><button type="submit" class="btn btn-primary">Save Changes</button></div>
    </form>
  </div>
</div>

</main>

<script>
function showPage(id,btn){
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  document.getElementById('page-'+id)?.classList.add('active');
  if(btn) btn.classList.add('active');
}
function updateClock(){
  const t=new Date().toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const el=document.getElementById('liveClock');
  if(el) el.textContent=t;
}
setInterval(updateClock,1000); updateClock();
function selectSlip(i){
  document.querySelectorAll('.slip-item').forEach((el,j)=>el.classList.toggle('selected',i===j));
  document.querySelectorAll('.payslip-detail').forEach((el,j)=>el.classList.toggle('show',i===j));
}
</script>
</body>
</html>