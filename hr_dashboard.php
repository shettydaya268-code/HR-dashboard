<?php
session_start();
if(!isset($_SESSION['role'])||$_SESSION['role']!=='hr'){header("Location: login.php");exit;}

$host="localhost";$dbname="employee_management";$user="root";$pass="";
try{$pdo=new PDO("mysql:host=$host;dbname=$dbname;charset=utf8",$user,$pass);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);}
catch(PDOException $e){die("DB Error: ".$e->getMessage());}

if(isset($_GET['logout'])){session_destroy();header("Location: login.php");exit;}

$msg=$msg_type="";
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';

    // Add employee
    if($action==='add'){
        $hashed=password_hash($_POST['password'],PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO employees (full_name,email,password,date_of_birth,address,department,position,salary,phone,hire_date,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$_POST['full_name'],$_POST['email'],$hashed,$_POST['date_of_birth'],$_POST['address'],$_POST['department'],$_POST['position'],$_POST['salary'],$_POST['phone'],$_POST['hire_date'],'active']);
        $msg="Employee added successfully!";$msg_type="success";
    }
    // Edit employee
    if($action==='edit'){
        $fields="full_name=?,date_of_birth=?,address=?,department=?,position=?,salary=?,phone=?,hire_date=?,status=?";
        $params=[$_POST['full_name'],$_POST['date_of_birth'],$_POST['address'],$_POST['department'],$_POST['position'],$_POST['salary'],$_POST['phone'],$_POST['hire_date'],$_POST['status']];
        if(!empty($_POST['password'])){$fields.=",password=?";$params[]=password_hash($_POST['password'],PASSWORD_BCRYPT);}
        $params[]=$_POST['emp_id'];
        $pdo->prepare("UPDATE employees SET $fields WHERE id=?")->execute($params);
        $msg="Employee updated!";$msg_type="success";
    }
    // Deactivate employee
    if($action==='delete'){
        $pdo->prepare("UPDATE employees SET status='inactive' WHERE id=?")->execute([$_POST['emp_id']]);
        $msg="Employee deactivated.";$msg_type="warning";
    }
    // Approve/Reject leave
    if($action==='leave_action'){
        $pdo->prepare("UPDATE leave_requests SET status=?,hr_note=? WHERE id=?")->execute([$_POST['leave_status'],$_POST['hr_note'],$_POST['leave_id']]);
        $msg="Leave request ".ucfirst($_POST['leave_status'])."!";$msg_type="success";
    }
    // Post announcement
    if($action==='post_announcement'){
        $pdo->prepare("INSERT INTO announcements (hr_id,title,body,priority) VALUES (?,?,?,?)")
            ->execute([$_SESSION['user_id'],trim($_POST['title']),trim($_POST['body']),$_POST['priority']]);
        $msg="Announcement posted!";$msg_type="success";
    }
    // Delete announcement
    if($action==='delete_announcement'){
        $pdo->prepare("DELETE FROM announcements WHERE id=?")->execute([$_POST['ann_id']]);
        $msg="Announcement deleted.";$msg_type="warning";
    }
    // Update salary
    if($action==='update_salary'){
        $pdo->prepare("UPDATE employees SET salary=? WHERE id=?")->execute([$_POST['salary'],$_POST['emp_id']]);
        $msg="Salary updated!";$msg_type="success";
    }
}

// ── Filters for employee list ──
$search=$_GET['search']??'';$dept=$_GET['dept']??'';$empStatus=$_GET['status']??'active';
$sql="SELECT * FROM employees WHERE 1=1";$params=[];
if($search){$sql.=" AND (full_name LIKE ? OR email LIKE ? OR position LIKE ?)";$s="%$search%";$params=[$s,$s,$s];}
if($dept){$sql.=" AND department=?";$params[]=$dept;}
if($empStatus!==''){$sql.=" AND status=?";$params[]=$empStatus;}
$sql.=" ORDER BY id DESC";
$stmt=$pdo->prepare($sql);$stmt->execute($params);
$employees=$stmt->fetchAll(PDO::FETCH_ASSOC);

$totals=$pdo->query("SELECT COUNT(*) as total,COALESCE(SUM(salary),0) as payroll FROM employees WHERE status='active'")->fetch();
$total_inactive=$pdo->query("SELECT COUNT(*) as cnt FROM employees WHERE status='inactive'")->fetch()['cnt'];

$departments=[
  ['name'=>'Engineering','icon'=>'🛠️','color'=>'#6c63ff','light'=>'rgba(108,99,255,0.12)'],
  ['name'=>'HR',         'icon'=>'👥','color'=>'#ff6b9d','light'=>'rgba(255,107,157,0.12)'],
  ['name'=>'Finance',    'icon'=>'💰','color'=>'#f5c842','light'=>'rgba(245,200,66,0.12)'],
  ['name'=>'Marketing',  'icon'=>'📣','color'=>'#4ade80','light'=>'rgba(74,222,128,0.12)'],
  ['name'=>'Operations', 'icon'=>'⚙️','color'=>'#38bdf8','light'=>'rgba(56,189,248,0.12)'],
  ['name'=>'Sales',      'icon'=>'📈','color'=>'#fb923c','light'=>'rgba(251,146,60,0.12)'],
];
$deptStats=[];
foreach($departments as $d){
  $s=$pdo->prepare("SELECT COUNT(*) as cnt,COALESCE(SUM(salary),0) as total FROM employees WHERE department=? AND status='active'");
  $s->execute([$d['name']]);$row=$s->fetch();
  $deptStats[$d['name']]=['count'=>$row['cnt'],'payroll'=>$row['total'],'color'=>$d['color']];
}

// ── Attendance data ──
$todayAtt=$pdo->query("SELECT COUNT(*) as cnt FROM attendance WHERE date=CURDATE() AND status='present'")->fetch()['cnt'];
$todayAbsent=$pdo->query("SELECT COUNT(*) as cnt FROM attendance WHERE date=CURDATE() AND status='absent'")->fetch()['cnt'];
$todayLate=$pdo->query("SELECT COUNT(*) as cnt FROM attendance WHERE date=CURDATE() AND status='late'")->fetch()['cnt'];
$attLog=$pdo->query("SELECT a.*,e.full_name,e.department FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE a.date=CURDATE() ORDER BY a.check_in DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
// 7-day attendance for chart
$attChart=[];
for($i=6;$i>=0;$i--){
    $d=date('Y-m-d',strtotime("-$i days"));
    $r=$pdo->prepare("SELECT COUNT(*) as present, (SELECT COUNT(*) FROM attendance WHERE date=? AND status='absent') as absent FROM attendance WHERE date=? AND status='present'");
    $r->execute([$d,$d]);$row=$r->fetch();
    $attChart[]=[ 'day'=>date('D',strtotime("-$i days")), 'present'=>(int)$row['present'], 'absent'=>(int)$row['absent'] ];
}

// ── Leave data ──
$pendingLeaves=$pdo->query("SELECT l.*,e.full_name,e.department,e.position FROM leave_requests l JOIN employees e ON e.id=l.employee_id WHERE l.status='pending' ORDER BY l.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$allLeaves=$pdo->query("SELECT l.*,e.full_name,e.department FROM leave_requests l JOIN employees e ON e.id=l.employee_id ORDER BY l.created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$leaveStats=$pdo->query("SELECT status,COUNT(*) as cnt FROM leave_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Announcements ──
$announcements=$pdo->query("SELECT a.*,h.full_name as hr_name FROM announcements a LEFT JOIN hr h ON h.id=a.hr_id ORDER BY a.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// ── Analytics ──
$avgSalary=$pdo->query("SELECT COALESCE(AVG(salary),0) as avg FROM employees WHERE status='active'")->fetch()['avg'];
$newThisMonth=$pdo->query("SELECT COUNT(*) as cnt FROM employees WHERE MONTH(hire_date)=MONTH(CURDATE()) AND YEAR(hire_date)=YEAR(CURDATE())")->fetch()['cnt'];
$salaryByDept=[];
foreach($departments as $d){
    $r=$pdo->prepare("SELECT COALESCE(SUM(salary),0) as total FROM employees WHERE department=? AND status='active'");
    $r->execute([$d['name']]);$salaryByDept[$d['name']]=(int)$r->fetch()['total'];
}
// Payroll all employees
$allEmpPayroll=$pdo->query("SELECT id,full_name,department,position,salary,status FROM employees ORDER BY salary DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>HR Dashboard — ACE International</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--ink:#0f0f13;--surface:#16161e;--card:#1e1e2a;--border:#2a2a3a;--accent:#6c63ff;--accent2:#ff6b9d;--gold:#f5c842;--green:#4ade80;--blue:#38bdf8;--orange:#fb923c;--text:#e8e8f0;--muted:#888899;--error:#ff6b6b;--sidebar-w:260px}
body{font-family:'DM Sans',sans-serif;background:var(--ink);color:var(--text);display:flex;min-height:100vh}

/* ── Sidebar ── */
.sidebar{width:var(--sidebar-w);background:var(--card);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;overflow-y:auto}
.sidebar-brand{padding:24px 20px 18px;border-bottom:1px solid var(--border)}
.brand-mark{display:flex;align-items:center;gap:10px}
.brand-logo{width:38px;height:38px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:10px;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:13px;color:#fff;flex-shrink:0}
.brand-text{font-family:'Syne',sans-serif;font-weight:700;font-size:15px}
.brand-role{font-size:10px;color:var(--accent);font-weight:600;letter-spacing:1px;text-transform:uppercase;margin-top:2px}
.sidebar-nav{padding:16px 12px;flex:1}
.nav-section{font-size:10px;letter-spacing:1.5px;color:var(--muted);text-transform:uppercase;padding:0 8px;margin:14px 0 6px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:9px;cursor:pointer;color:var(--muted);font-size:13px;font-weight:500;transition:all .15s;margin-bottom:2px;background:none;border:none;width:100%;text-align:left}
.nav-item:hover{background:rgba(108,99,255,.1);color:var(--text)}
.nav-item.active{background:rgba(108,99,255,.15);border-left:3px solid var(--accent);padding-left:9px;color:var(--accent)}
.nav-item .icon{font-size:16px;width:20px;text-align:center;flex-shrink:0}
.nav-badge{margin-left:auto;background:var(--error);color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;min-width:20px;text-align:center}
.sidebar-footer{padding:16px 12px;border-top:1px solid var(--border)}
.user-card{display:flex;align-items:center;gap:10px}
.avatar{width:36px;height:36px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff;flex-shrink:0}
.user-info .name{font-size:13px;font-weight:500}.user-info .role{font-size:11px;color:var(--muted)}
.logout-btn{margin-left:auto;background:none;border:none;color:var(--muted);cursor:pointer;font-size:16px;transition:color .2s;text-decoration:none}
.logout-btn:hover{color:var(--error)}

/* ── Main ── */
.main{margin-left:var(--sidebar-w);flex:1;min-height:100vh}
.page{display:none;padding:28px;animation:fadeIn .25s ease}
.page.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px}
.page-title{font-family:'Syne',sans-serif;font-size:26px;font-weight:800}
.page-sub{font-size:13px;color:var(--muted);margin-top:3px}

/* ── Shared ── */
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:22px}
.card-title{font-family:'Syne',sans-serif;font-weight:700;font-size:14px;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.full{grid-column:1/-1}
.mb16{margin-bottom:16px}.mb24{margin-bottom:24px}
.alert{display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:10px;font-size:13px;margin-bottom:18px}
.alert-success{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.3);color:var(--green)}
.alert-warning{background:rgba(245,200,66,.1);border:1px solid rgba(245,200,66,.3);color:var(--gold)}

/* ── Badges ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-active,.badge-present,.badge-approved{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--green)}
.badge-pending,.badge-half-day{background:rgba(245,200,66,.12);border:1px solid rgba(245,200,66,.3);color:var(--gold)}
.badge-inactive,.badge-absent,.badge-rejected{background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.3);color:var(--error)}
.badge-late{background:rgba(251,146,60,.12);border:1px solid rgba(251,146,60,.3);color:var(--orange)}
.badge-urgent{background:rgba(255,107,107,.15);border:1px solid rgba(255,107,107,.4);color:var(--error)}
.badge-important{background:rgba(245,200,66,.15);border:1px solid rgba(245,200,66,.4);color:var(--gold)}
.badge-normal{background:rgba(108,99,255,.12);border:1px solid rgba(108,99,255,.3);color:var(--accent)}

/* ── Stat cards ── */
.top-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px 20px;display:flex;align-items:center;gap:14px}
.stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.stat-value{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;line-height:1}
.stat-label{font-size:12px;color:var(--muted);margin-top:4px}
.stat-card:nth-child(1){border-color:rgba(108,99,255,.3)}.stat-card:nth-child(1) .stat-icon{background:rgba(108,99,255,.15)}
.stat-card:nth-child(2){border-color:rgba(255,107,157,.3)}.stat-card:nth-child(2) .stat-icon{background:rgba(255,107,157,.15)}
.stat-card:nth-child(3){border-color:rgba(74,222,128,.3)}.stat-card:nth-child(3) .stat-icon{background:rgba(74,222,128,.15)}
.stat-card:nth-child(4){border-color:rgba(245,200,66,.3)}.stat-card:nth-child(4) .stat-icon{background:rgba(245,200,66,.15)}

/* ── Dept cards ── */
.dept-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px}
.dept-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;cursor:pointer;transition:all .2s;position:relative;overflow:hidden}
.dept-card:hover{transform:translateY(-3px);box-shadow:0 8px 32px rgba(0,0,0,.3)}
.dept-card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.dept-icon{width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px}
.dept-count{font-family:'Syne',sans-serif;font-size:32px;font-weight:800;line-height:1;margin-bottom:4px}
.dept-name{font-size:14px;font-weight:600;margin-bottom:2px}
.dept-payroll{font-size:12px;color:var(--muted)}

/* ── Table ── */
.table-wrap{overflow-x:auto}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{padding:10px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
.tbl td{padding:12px 14px;border-bottom:1px solid rgba(42,42,58,.5);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(108,99,255,.04)}
.emp-name{font-weight:500;font-size:13px}
.emp-email{font-size:11px;color:var(--muted);margin-top:2px}
.actions{display:flex;gap:6px}

/* ── Buttons ── */
.btn{padding:10px 18px;border-radius:10px;border:none;font-family:'Syne',sans-serif;font-weight:600;font-size:13px;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;box-shadow:0 4px 14px rgba(108,99,255,.3)}
.btn-primary:hover{opacity:.9;transform:translateY(-1px)}
.btn-sm{padding:6px 12px;font-size:12px;border-radius:8px}
.btn-edit{background:rgba(108,99,255,.15);border:1px solid rgba(108,99,255,.3);color:var(--accent)}
.btn-edit:hover{background:rgba(108,99,255,.25)}
.btn-delete{background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.3);color:var(--error)}
.btn-delete:hover{background:rgba(255,107,107,.2)}
.btn-approve{background:rgba(74,222,128,.15);border:1px solid rgba(74,222,128,.3);color:var(--green)}
.btn-approve:hover{background:rgba(74,222,128,.25)}
.btn-reject{background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.3);color:var(--error)}
.btn-reject:hover{background:rgba(255,107,107,.2)}
.btn-cancel{background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--muted)}
.btn-ghost{background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--muted)}
.btn-ghost:hover{color:var(--text);border-color:var(--accent)}

/* ── Forms ── */
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group label{font-size:12px;font-weight:500;color:var(--muted)}
.form-group input,.form-group select,.form-group textarea{padding:10px 13px;background:var(--surface);border:1.5px solid var(--border);border-radius:10px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:13.5px;outline:none;transition:border-color .2s;resize:vertical}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--accent)}
.form-group input::placeholder,.form-group textarea::placeholder{color:var(--muted)}
.form-group select option{background:var(--card)}
.form-section{grid-column:1/-1;font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--accent);padding:10px 0 2px;border-bottom:1px solid var(--border)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}

/* ── Search/Filter bar ── */
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.filter-bar input,.filter-bar select{padding:9px 13px;background:var(--surface);border:1.5px solid var(--border);border-radius:10px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:13px;outline:none;transition:border-color .2s}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--accent)}
.filter-bar input{flex:1;min-width:200px}
.filter-bar select option{background:var(--card)}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:28px;width:90%;max-width:640px;max-height:90vh;overflow-y:auto;position:relative}
.modal-title{font-family:'Syne',sans-serif;font-weight:700;font-size:18px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between}
.modal-close{background:none;border:none;color:var(--muted);font-size:22px;cursor:pointer;line-height:1}
.modal-close:hover{color:var(--error)}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:22px;padding-top:18px;border-top:1px solid var(--border)}

/* ── Chart bars ── */
.bar-chart{display:flex;align-items:flex-end;gap:8px;height:140px;padding:10px 0}
.bar-group{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1}
.bar-wrap{display:flex;gap:3px;align-items:flex-end;height:110px}
.bar{border-radius:6px 6px 0 0;min-width:14px;transition:opacity .2s}
.bar:hover{opacity:.8}
.bar-label{font-size:10px;color:var(--muted);text-align:center}

/* ── Attendance overview ── */
.att-stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.att-stat{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center}
.att-stat-val{font-family:'Syne',sans-serif;font-size:28px;font-weight:800}
.att-stat-label{font-size:12px;color:var(--muted);margin-top:3px}

/* ── Leave card ── */
.leave-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:10px}
.leave-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.leave-emp{font-weight:600;font-size:14px}
.leave-sub{font-size:12px;color:var(--muted);margin-top:2px}
.leave-actions{display:flex;gap:8px;margin-top:12px}

/* ── Announcement card ── */
.ann-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:12px;position:relative;overflow:hidden}
.ann-card.urgent{border-color:rgba(255,107,107,.4)}
.ann-card.important{border-color:rgba(245,200,66,.3)}
.ann-stripe{position:absolute;left:0;top:0;bottom:0;width:4px}
.ann-stripe.urgent{background:var(--error)}
.ann-stripe.important{background:var(--gold)}
.ann-stripe.normal{background:var(--accent)}

/* ── Payroll bar ── */
.payroll-bar-wrap{background:var(--surface);border-radius:6px;height:8px;overflow:hidden;margin-top:6px}
.payroll-bar{height:100%;border-radius:6px;background:linear-gradient(90deg,var(--accent),var(--accent2))}

/* ── Analytics donut (CSS only) ── */
.donut-wrap{display:flex;gap:24px;align-items:center;flex-wrap:wrap}
.legend-item{display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:8px}
.legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}

/* ── Notification toast ── */
.notif-toast{position:fixed;top:20px;right:20px;z-index:9999;min-width:280px;animation:slideIn .3s ease}
@keyframes slideIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-mark">
      <div class="brand-logo">ACE</div>
      <div><div class="brand-text">ACE International</div><div class="brand-role">● HR Admin</div></div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Overview</div>
    <button class="nav-item active" onclick="showPage('overview',this)"><span class="icon">🏠</span> Dashboard</button>
    <button class="nav-item" onclick="showPage('employees',this)"><span class="icon">👤</span> Employees <span class="nav-badge" style="background:rgba(108,99,255,.2);color:var(--accent)"><?=count($employees)?></span></button>
    <div class="nav-section">Features</div>
    <button class="nav-item" onclick="showPage('attendance',this)"><span class="icon">📅</span> Attendance</button>
    <button class="nav-item" onclick="showPage('leaves',this)"><span class="icon">🗓️</span> Leave Approvals <?php if(count($pendingLeaves)): ?><span class="nav-badge"><?=count($pendingLeaves)?></span><?php endif; ?></button>
    <button class="nav-item" onclick="showPage('payroll',this)"><span class="icon">💰</span> Payroll</button>
    <button class="nav-item" onclick="showPage('analytics',this)"><span class="icon">📊</span> Analytics</button>
    <button class="nav-item" onclick="showPage('announcements',this)"><span class="icon">📝</span> Announcements</button>
    <div class="nav-section">Departments</div>
    <?php foreach($departments as $d): ?>
    <button class="nav-item" onclick="filterDept('<?=$d['name']?>')"><span class="icon"><?=$d['icon']?></span> <?=$d['name']?> <span class="nav-badge" style="background:rgba(255,255,255,.05);color:var(--muted)"><?=$deptStats[$d['name']]['count']?></span></button>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="user-card">
      <div class="avatar"><?=strtoupper(substr($_SESSION['user_name']??'H',0,1))?></div>
      <div class="user-info"><div class="name"><?=htmlspecialchars($_SESSION['user_name']??'HR Admin')?></div><div class="role">HR Administrator</div></div>
      <a href="?logout=1" class="logout-btn">⏻</a>
    </div>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<main class="main">

<?php if($msg): ?>
<div class="notif-toast">
  <div class="alert alert-<?=$msg_type?>"><?=$msg_type==='success'?'✅':'⚠️'?> <?=htmlspecialchars($msg)?></div>
</div>
<script>setTimeout(()=>document.querySelector('.notif-toast')?.remove(),3500)</script>
<?php endif; ?>

<!-- ══════════════ OVERVIEW ══════════════ -->
<div class="page active" id="page-overview">
  <div class="page-header">
    <div><div class="page-title">HR Dashboard</div><div class="page-sub"><?=date('l, F j, Y')?></div></div>
    <button class="btn btn-primary" onclick="openModal()">➕ Add Employee</button>
  </div>

  <div class="top-stats">
    <div class="stat-card"><div class="stat-icon">👥</div><div><div class="stat-value"><?=$totals['total']?></div><div class="stat-label">Active Employees</div></div></div>
    <div class="stat-card"><div class="stat-icon">🏢</div><div><div class="stat-value"><?=count($departments)?></div><div class="stat-label">Departments</div></div></div>
    <div class="stat-card"><div class="stat-icon">💰</div><div><div class="stat-value">₹<?=number_format($totals['payroll']/100000,1)?>L</div><div class="stat-label">Monthly Payroll</div></div></div>
    <div class="stat-card"><div class="stat-icon">⏳</div><div><div class="stat-value"><?=count($pendingLeaves)?></div><div class="stat-label">Pending Leaves</div></div></div>
  </div>

  <div class="dept-grid">
    <?php foreach($departments as $d): $ds=$deptStats[$d['name']]; ?>
    <div class="dept-card" onclick="filterDept('<?=$d['name']?>')" style="border-color:<?=$d['color']?>22">
      <div class="dept-card-top">
        <div class="dept-icon" style="background:<?=$d['light']?>"><?=$d['icon']?></div>
        <span style="font-size:11px;font-weight:600;color:<?=$d['color']?>;background:<?=$d['light']?>;padding:3px 10px;border-radius:20px"><?=$ds['count']?> staff</span>
      </div>
      <div class="dept-count" style="color:<?=$d['color']?>"><?=$ds['count']?></div>
      <div class="dept-name"><?=$d['name']?></div>
      <div class="dept-payroll">₹<?=number_format($ds['payroll'],0)?> / month</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Attendance today snapshot -->
  <div class="grid2 mb24">
    <div class="card">
      <div class="card-title">📅 Today's Attendance Snapshot</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">
        <div style="text-align:center;padding:14px;background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2);border-radius:12px">
          <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:var(--green)"><?=$todayAtt?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px">Present</div>
        </div>
        <div style="text-align:center;padding:14px;background:rgba(255,107,107,.08);border:1px solid rgba(255,107,107,.2);border-radius:12px">
          <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:var(--error)"><?=$todayAbsent?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px">Absent</div>
        </div>
        <div style="text-align:center;padding:14px;background:rgba(251,146,60,.08);border:1px solid rgba(251,146,60,.2);border-radius:12px">
          <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:var(--orange)"><?=$todayLate?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px">Late</div>
        </div>
      </div>
      <button onclick="showPage('attendance',document.querySelectorAll('.nav-item')[4])" style="font-size:13px;color:var(--accent);background:none;border:none;cursor:pointer">View full attendance →</button>
    </div>
    <div class="card">
      <div class="card-title">🗓️ Pending Leave Approvals</div>
      <?php if(empty($pendingLeaves)): ?>
      <div style="text-align:center;padding:20px;color:var(--muted);font-size:13px">No pending requests ✓</div>
      <?php else: ?>
      <?php foreach(array_slice($pendingLeaves,0,3) as $l): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(42,42,58,.5);font-size:13px">
        <div>
          <div style="font-weight:500"><?=htmlspecialchars($l['full_name'])?></div>
          <div style="font-size:11px;color:var(--muted)"><?=ucfirst($l['leave_type'])?> · <?=date('d M',strtotime($l['from_date']))?> – <?=date('d M',strtotime($l['to_date']))?></div>
        </div>
        <span class="badge badge-pending">Pending</span>
      </div>
      <?php endforeach; ?>
      <button onclick="showPage('leaves',document.querySelectorAll('.nav-item')[5])" style="font-size:13px;color:var(--accent);background:none;border:none;cursor:pointer;margin-top:12px">Manage leaves →</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent announcements preview -->
  <?php if(!empty($announcements)): ?>
  <div class="card">
    <div class="card-title" style="justify-content:space-between">📝 Recent Announcements <button onclick="showPage('announcements',document.querySelectorAll('.nav-item')[8])" style="font-size:12px;color:var(--accent);background:none;border:none;cursor:pointer">Manage →</button></div>
    <?php foreach(array_slice($announcements,0,2) as $a): ?>
    <div class="ann-card <?=htmlspecialchars($a['priority'])?>" style="margin-bottom:10px">
      <div class="ann-stripe <?=htmlspecialchars($a['priority'])?>"></div>
      <div style="padding-left:12px;display:flex;align-items:center;justify-content:space-between">
        <div>
          <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px"><?=htmlspecialchars($a['title'])?></span>
          <span class="badge badge-<?=$a['priority']?>" style="margin-left:8px"><?=ucfirst($a['priority'])?></span>
          <div style="font-size:12px;color:var(--muted);margin-top:4px"><?=date('d M Y',strtotime($a['created_at']))?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══════════════ EMPLOYEES ══════════════ -->
<div class="page" id="page-employees">
  <div class="page-header">
    <div><div class="page-title">Employees</div><div class="page-sub"><?=$totals['total']?> active · <?=$total_inactive?> inactive</div></div>
    <button class="btn btn-primary" onclick="openModal()">➕ Add Employee</button>
  </div>
  <form method="GET">
    <div class="filter-bar">
      <input type="text" name="search" placeholder="🔍 Search name, email, position..." value="<?=htmlspecialchars($search)?>">
      <select name="dept"><option value="">All Departments</option><?php foreach($departments as $d): ?><option value="<?=$d['name']?>" <?=$dept===$d['name']?'selected':''?>><?=$d['icon']?> <?=$d['name']?></option><?php endforeach; ?></select>
      <select name="status"><option value="active" <?=$empStatus==='active'?'selected':''?>>Active</option><option value="inactive" <?=$empStatus==='inactive'?'selected':''?>>Inactive</option><option value="" <?=$empStatus===''?'selected':''?>>All</option></select>
      <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
    </div>
  </form>
  <div class="card">
    <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Employee</th><th>Department</th><th>Position</th><th>Salary</th><th>Hire Date</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if(empty($employees)): ?>
      <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--muted)">No employees found.</td></tr>
      <?php else: ?>
      <?php foreach($employees as $e): ?>
      <tr>
        <td><div class="emp-name"><?=htmlspecialchars($e['full_name'])?></div><div class="emp-email"><?=htmlspecialchars($e['email'])?></div></td>
        <td><?=htmlspecialchars($e['department'])?></td>
        <td><?=htmlspecialchars($e['position'])?></td>
        <td style="font-weight:600">₹<?=number_format($e['salary'],0)?></td>
        <td style="color:var(--muted)"><?=htmlspecialchars($e['hire_date']??'—')?></td>
        <td><span class="badge badge-<?=$e['status']?>">● <?=ucfirst($e['status'])?></span></td>
        <td>
          <div class="actions">
            <button class="btn btn-sm btn-edit" onclick="openEditModal(<?=htmlspecialchars(json_encode($e))?>)">Edit</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Deactivate this employee?')">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="emp_id" value="<?=$e['id']?>">
              <button type="submit" class="btn btn-sm btn-delete">Remove</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ══════════════ ATTENDANCE ══════════════ -->
<div class="page" id="page-attendance">
  <div class="page-header">
    <div><div class="page-title">📅 Attendance Overview</div><div class="page-sub">Today — <?=date('l, d F Y')?></div></div>
  </div>

  <div class="att-stat-row mb24">
    <div class="att-stat"><div class="att-stat-val" style="color:var(--green)"><?=$todayAtt?></div><div class="att-stat-label">Present Today</div></div>
    <div class="att-stat"><div class="att-stat-val" style="color:var(--error)"><?=$todayAbsent?></div><div class="att-stat-label">Absent Today</div></div>
    <div class="att-stat"><div class="att-stat-val" style="color:var(--orange)"><?=$todayLate?></div><div class="att-stat-label">Late Today</div></div>
  </div>

  <!-- 7-day bar chart -->
  <div class="card mb24">
    <div class="card-title">📈 7-Day Attendance Trend</div>
    <div class="bar-chart">
      <?php
      $maxVal=1;
      foreach($attChart as $a) $maxVal=max($maxVal,$a['present']+$a['absent']);
      foreach($attChart as $a):
        $ph=$maxVal>0?round(($a['present']/$maxVal)*100):0;
        $ah=$maxVal>0?round(($a['absent']/$maxVal)*100):0;
      ?>
      <div class="bar-group">
        <div class="bar-wrap">
          <div class="bar" style="height:<?=$ph?>%;width:18px;background:var(--green)" title="Present: <?=$a['present']?>"></div>
          <div class="bar" style="height:<?=$ah?>%;width:18px;background:var(--error)" title="Absent: <?=$a['absent']?>"></div>
        </div>
        <div class="bar-label"><?=$a['day']?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:16px;margin-top:10px">
      <span style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted)"><span style="width:10px;height:10px;background:var(--green);border-radius:3px;display:inline-block"></span>Present</span>
      <span style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted)"><span style="width:10px;height:10px;background:var(--error);border-radius:3px;display:inline-block"></span>Absent</span>
    </div>
  </div>

  <!-- Today's log -->
  <div class="card">
    <div class="card-title">📋 Today's Check-in Log</div>
    <?php if(empty($attLog)): ?>
    <div style="text-align:center;padding:30px;color:var(--muted)">No check-ins recorded today.</div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Employee</th><th>Department</th><th>Check In</th><th>Check Out</th><th>Hours</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($attLog as $r):
        $hrs='—';
        if($r['check_in']&&$r['check_out']){$diff=(strtotime($r['check_out'])-strtotime($r['check_in']))/3600;$hrs=number_format($diff,1).' hrs';}
      ?>
      <tr>
        <td style="font-weight:500"><?=htmlspecialchars($r['full_name'])?></td>
        <td><?=htmlspecialchars($r['department'])?></td>
        <td><?=$r['check_in']?date('h:i A',strtotime($r['check_in'])):'—'?></td>
        <td><?=$r['check_out']?date('h:i A',strtotime($r['check_out'])):'—'?></td>
        <td><?=$hrs?></td>
        <td><span class="badge badge-<?=$r['status']?>"><?=ucfirst($r['status'])?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ══════════════ LEAVE APPROVALS ══════════════ -->
<div class="page" id="page-leaves">
  <div class="page-header">
    <div><div class="page-title">🗓️ Leave Management</div><div class="page-sub"><?=count($pendingLeaves)?> pending approvals</div></div>
  </div>

  <div class="grid3 mb24">
    <div class="card" style="text-align:center"><div style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--gold)"><?=$leaveStats['pending']??0?></div><div style="font-size:12px;color:var(--muted);margin-top:4px">Pending</div></div>
    <div class="card" style="text-align:center"><div style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--green)"><?=$leaveStats['approved']??0?></div><div style="font-size:12px;color:var(--muted);margin-top:4px">Approved</div></div>
    <div class="card" style="text-align:center"><div style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--error)"><?=$leaveStats['rejected']??0?></div><div style="font-size:12px;color:var(--muted);margin-top:4px">Rejected</div></div>
  </div>

  <?php if(!empty($pendingLeaves)): ?>
  <div class="card mb24">
    <div class="card-title">⏳ Pending Approvals</div>
    <?php foreach($pendingLeaves as $l): ?>
    <div class="leave-card">
      <div class="leave-card-head">
        <div>
          <div class="leave-emp"><?=htmlspecialchars($l['full_name'])?> <span style="font-size:11px;color:var(--muted);font-weight:400">· <?=htmlspecialchars($l['department'])?></span></div>
          <div class="leave-sub"><?=ucfirst($l['leave_type'])?> Leave &nbsp;·&nbsp; <?=date('d M Y',strtotime($l['from_date']))?> → <?=date('d M Y',strtotime($l['to_date']))?> &nbsp;·&nbsp; <?=max(1,(strtotime($l['to_date'])-strtotime($l['from_date']))/86400+1)?> day(s)</div>
          <?php if($l['reason']): ?><div style="font-size:12px;color:var(--muted);margin-top:5px;font-style:italic">"<?=htmlspecialchars($l['reason'])?>"</div><?php endif; ?>
        </div>
        <span class="badge badge-pending">Pending</span>
      </div>
      <form method="POST" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <input type="hidden" name="action" value="leave_action">
        <input type="hidden" name="leave_id" value="<?=$l['id']?>">
        <input type="text" name="hr_note" placeholder="Optional HR note..." style="flex:1;padding:8px 12px;background:var(--ink);border:1.5px solid var(--border);border-radius:8px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:13px;outline:none;min-width:160px">
        <button type="submit" name="leave_status" value="approved" class="btn btn-sm btn-approve">✅ Approve</button>
        <button type="submit" name="leave_status" value="rejected" class="btn btn-sm btn-reject" onclick="return confirm('Reject this leave?')">❌ Reject</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title">📋 All Leave History (Last 30)</div>
    <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th>HR Note</th></tr></thead>
      <tbody>
      <?php foreach($allLeaves as $l): ?>
      <tr>
        <td><div style="font-weight:500"><?=htmlspecialchars($l['full_name'])?></div><div style="font-size:11px;color:var(--muted)"><?=htmlspecialchars($l['department'])?></div></td>
        <td style="text-transform:capitalize"><?=$l['leave_type']?></td>
        <td><?=date('d M Y',strtotime($l['from_date']))?></td>
        <td><?=date('d M Y',strtotime($l['to_date']))?></td>
        <td><?=max(1,(strtotime($l['to_date'])-strtotime($l['from_date']))/86400+1)?></td>
        <td><span class="badge badge-<?=$l['status']?>"><?=ucfirst($l['status'])?></span></td>
        <td style="font-size:12px;color:var(--muted)"><?=htmlspecialchars($l['hr_note']?:'—')?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ══════════════ PAYROLL ══════════════ -->
<div class="page" id="page-payroll">
  <div class="page-header">
    <div><div class="page-title">💰 Payroll Management</div><div class="page-sub">Total monthly payroll: ₹<?=number_format($totals['payroll'],0)?></div></div>
  </div>

  <div class="top-stats mb24">
    <div class="stat-card"><div class="stat-icon">💰</div><div><div class="stat-value">₹<?=number_format($totals['payroll'],0)?></div><div class="stat-label">Total Monthly Payroll</div></div></div>
    <div class="stat-card"><div class="stat-icon">📊</div><div><div class="stat-value">₹<?=number_format($avgSalary,0)?></div><div class="stat-label">Avg Salary</div></div></div>
    <div class="stat-card"><div class="stat-icon">👥</div><div><div class="stat-value"><?=$totals['total']?></div><div class="stat-label">On Payroll</div></div></div>
    <div class="stat-card"><div class="stat-icon">🏢</div><div><div class="stat-value">₹<?=number_format($totals['payroll']*12/100000,1)?>L</div><div class="stat-label">Annual Cost</div></div></div>
  </div>

  <!-- Payroll by dept -->
  <div class="card mb24">
    <div class="card-title">🏢 Payroll by Department</div>
    <?php $maxPay=max(array_values($salaryByDept)?:[1]); ?>
    <?php foreach($departments as $d): $pay=$salaryByDept[$d['name']]??0; ?>
    <div style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
        <span><?=$d['icon']?> <?=$d['name']?> <span style="color:var(--muted)">(<?=$deptStats[$d['name']]['count']?> staff)</span></span>
        <span style="font-family:'Syne',sans-serif;font-weight:700">₹<?=number_format($pay,0)?></span>
      </div>
      <div class="payroll-bar-wrap"><div class="payroll-bar" style="width:<?=$maxPay>0?round($pay/$maxPay*100):0?>%;background:<?=$d['color']?>"></div></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Employee salary table with inline edit -->
  <div class="card">
    <div class="card-title">👤 Employee Salaries</div>
    <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Employee</th><th>Department</th><th>Position</th><th>Current Salary</th><th>Status</th><th>Update</th></tr></thead>
      <tbody>
      <?php foreach($allEmpPayroll as $e): ?>
      <tr>
        <td style="font-weight:500"><?=htmlspecialchars($e['full_name'])?></td>
        <td><?=htmlspecialchars($e['department'])?></td>
        <td style="color:var(--muted)"><?=htmlspecialchars($e['position'])?></td>
        <td style="font-family:'Syne',sans-serif;font-weight:700;color:var(--green)">₹<?=number_format($e['salary'],0)?></td>
        <td><span class="badge badge-<?=$e['status']?>">● <?=ucfirst($e['status'])?></span></td>
        <td>
          <form method="POST" style="display:flex;gap:6px;align-items:center">
            <input type="hidden" name="action" value="update_salary">
            <input type="hidden" name="emp_id" value="<?=$e['id']?>">
            <input type="number" name="salary" value="<?=$e['salary']?>" style="width:110px;padding:6px 10px;background:var(--surface);border:1.5px solid var(--border);border-radius:8px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:13px;outline:none">
            <button type="submit" class="btn btn-sm btn-edit">Save</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ══════════════ ANALYTICS ══════════════ -->
<div class="page" id="page-analytics">
  <div class="page-header">
    <div><div class="page-title">📊 Employee Analytics</div><div class="page-sub">Workforce insights at a glance</div></div>
  </div>

  <div class="top-stats mb24">
    <div class="stat-card"><div class="stat-icon">👥</div><div><div class="stat-value"><?=$totals['total']?></div><div class="stat-label">Active Employees</div></div></div>
    <div class="stat-card"><div class="stat-icon">📅</div><div><div class="stat-value"><?=$newThisMonth?></div><div class="stat-label">Hired This Month</div></div></div>
    <div class="stat-card"><div class="stat-icon">💰</div><div><div class="stat-value">₹<?=number_format($avgSalary,0)?></div><div class="stat-label">Avg Salary</div></div></div>
    <div class="stat-card"><div class="stat-icon">🚫</div><div><div class="stat-value"><?=$total_inactive?></div><div class="stat-label">Inactive</div></div></div>
  </div>

  <div class="grid2 mb24">
    <!-- Dept distribution -->
    <div class="card">
      <div class="card-title">🏢 Staff by Department</div>
      <?php $totalEmp=max($totals['total'],1); ?>
      <?php foreach($departments as $d): $cnt=$deptStats[$d['name']]['count']; $pct=$totalEmp>0?round($cnt/$totalEmp*100):0; ?>
      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
          <span><?=$d['icon']?> <?=$d['name']?></span>
          <span style="color:var(--muted)"><?=$cnt?> (<?=$pct?>%)</span>
        </div>
        <div style="background:var(--surface);border-radius:6px;height:8px;overflow:hidden">
          <div style="width:<?=$pct?>%;height:100%;border-radius:6px;background:<?=$d['color']?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Leave analytics -->
    <div class="card">
      <div class="card-title">🗓️ Leave Summary</div>
      <?php
      $totalLeaves=array_sum($leaveStats)?:1;
      $leaveCols=['approved'=>'var(--green)','pending'=>'var(--gold)','rejected'=>'var(--error)'];
      foreach($leaveCols as $ls=>$lc):
        $lc2=$leaveStats[$ls]??0; $lpct=round($lc2/$totalLeaves*100);
      ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
          <span style="text-transform:capitalize"><?=$ls?></span>
          <span style="color:<?=$lc?>;font-family:'Syne',sans-serif;font-weight:700"><?=$lc2?></span>
        </div>
        <div style="background:var(--surface);border-radius:6px;height:8px;overflow:hidden">
          <div style="width:<?=$lpct?>%;height:100%;border-radius:6px;background:<?=$lc?>"></div>
        </div>
      </div>
      <?php endforeach; ?>

      <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
        <div class="card-title" style="margin-bottom:12px">📅 Attendance (Today)</div>
        <?php $attTotal=max($todayAtt+$todayAbsent+$todayLate,1); ?>
        <?php foreach([['Present',$todayAtt,'var(--green)'],['Absent',$todayAbsent,'var(--error)'],['Late',$todayLate,'var(--orange)']] as [$albl,$aval,$aclr]): $apct=round($aval/$attTotal*100); ?>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
          <span><?=$albl?></span><span style="color:<?=$aclr?>;font-weight:700"><?=$aval?> (<?=$apct?>%)</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Salary distribution -->
  <div class="card mb24">
    <div class="card-title">💰 Salary Ranges</div>
    <?php
    $ranges=[['< ₹40K',0,40000],['₹40–60K',40000,60000],['₹60–80K',60000,80000],['₹80K–1L',80000,100000],['> ₹1L',100000,9999999]];
    $rangeCounts=[];
    foreach($ranges as $r){
        $cnt=$pdo->prepare("SELECT COUNT(*) as cnt FROM employees WHERE salary>=? AND salary<? AND status='active'");
        $cnt->execute([$r[1],$r[2]]); $rangeCounts[$r[0]]=$cnt->fetch()['cnt'];
    }
    $maxR=max(array_values($rangeCounts)?:[1]);
    ?>
    <div style="display:flex;align-items:flex-end;gap:12px;height:130px;padding:10px 0">
    <?php foreach($rangeCounts as $label=>$cnt): $h=$maxR>0?round($cnt/$maxR*100):0; ?>
      <div style="display:flex;flex-direction:column;align-items:center;gap:6px;flex:1">
        <span style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--accent)"><?=$cnt?></span>
        <div style="width:100%;height:<?=$h?>px;min-height:4px;background:linear-gradient(180deg,var(--accent),var(--accent2));border-radius:6px 6px 0 0"></div>
        <span style="font-size:10px;color:var(--muted);text-align:center"><?=$label?></span>
      </div>
    <?php endforeach; ?>
    </div>
  </div>

  <!-- Top earners -->
  <div class="card">
    <div class="card-title">🏆 Top 5 Earners</div>
    <?php $topEarners=$pdo->query("SELECT full_name,department,position,salary FROM employees WHERE status='active' ORDER BY salary DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC); ?>
    <?php foreach($topEarners as $i=>$e): ?>
    <div style="display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid rgba(42,42,58,.5)">
      <div style="width:28px;height:28px;background:<?=$i===0?'var(--gold)':($i===1?'rgba(136,136,153,.3)':'rgba(251,146,60,.2)')?>;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:13px"><?=$i+1?></div>
      <div style="flex:1"><div style="font-weight:500;font-size:13px"><?=htmlspecialchars($e['full_name'])?></div><div style="font-size:11px;color:var(--muted)"><?=htmlspecialchars($e['department'])?> · <?=htmlspecialchars($e['position'])?></div></div>
      <div style="font-family:'Syne',sans-serif;font-weight:800;color:var(--green)">₹<?=number_format($e['salary'],0)?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══════════════ ANNOUNCEMENTS ══════════════ -->
<div class="page" id="page-announcements">
  <div class="page-header">
    <div><div class="page-title">📝 Announcements</div><div class="page-sub">Post notices visible to all employees</div></div>
  </div>

  <div class="grid2 mb24">
    <div class="card">
      <div class="card-title">📢 Post New Announcement</div>
      <form method="POST">
        <input type="hidden" name="action" value="post_announcement">
        <div style="display:flex;flex-direction:column;gap:14px">
          <div class="form-group"><label>Title *</label><input type="text" name="title" placeholder="Announcement title..." required></div>
          <div class="form-group"><label>Message *</label><textarea name="body" rows="4" placeholder="Write your announcement here..." required></textarea></div>
          <div class="form-group"><label>Priority</label>
            <select name="priority">
              <option value="normal">🔵 Normal</option>
              <option value="important">🟡 Important</option>
              <option value="urgent">🔴 Urgent</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary" style="align-self:flex-start">📢 Post Announcement</button>
        </div>
      </form>
    </div>

    <div>
      <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;margin-bottom:14px">📋 Posted Announcements (<?=count($announcements)?>)</div>
      <?php if(empty($announcements)): ?>
      <div class="card" style="text-align:center;padding:30px;color:var(--muted)">No announcements yet.</div>
      <?php else: ?>
      <?php foreach($announcements as $a): ?>
      <div class="ann-card <?=htmlspecialchars($a['priority'])?>" style="margin-bottom:12px">
        <div class="ann-stripe <?=htmlspecialchars($a['priority'])?>"></div>
        <div style="padding-left:12px">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px">
            <div>
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px"><?=htmlspecialchars($a['title'])?></span>
                <span class="badge badge-<?=$a['priority']?>"><?=ucfirst($a['priority'])?></span>
              </div>
              <div style="font-size:13px;color:var(--muted);line-height:1.6"><?=nl2br(htmlspecialchars($a['body']))?></div>
              <div style="font-size:11px;color:var(--muted);margin-top:8px"><?=date('d M Y, h:i A',strtotime($a['created_at']))?></div>
            </div>
            <form method="POST" style="flex-shrink:0" onsubmit="return confirm('Delete this announcement?')">
              <input type="hidden" name="action" value="delete_announcement">
              <input type="hidden" name="ann_id" value="<?=$a['id']?>">
              <button type="submit" class="btn btn-sm btn-delete">🗑</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

</main>

<!-- ══ Add Employee Modal ══ -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-title">➕ Add New Employee <button class="modal-close" onclick="closeModal()">×</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="form-grid">
        <div class="form-section">Personal Information</div>
        <div class="form-group full"><label>Full Name *</label><input type="text" name="full_name" placeholder="John Doe" required></div>
        <div class="form-group"><label>Email *</label><input type="email" name="email" placeholder="john@aceinternational.com" required></div>
        <div class="form-group"><label>Password *</label><input type="password" name="password" placeholder="Set password" required></div>
        <div class="form-group"><label>Date of Birth</label><input type="date" name="date_of_birth"></div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" placeholder="+91 00000 00000"></div>
        <div class="form-group full"><label>Address</label><textarea name="address" placeholder="Street, City, State - PIN"></textarea></div>
        <div class="form-section">Employment Details</div>
        <div class="form-group"><label>Department</label><select name="department"><?php foreach($departments as $d): ?><option value="<?=$d['name']?>"><?=$d['icon']?> <?=$d['name']?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Position</label><input type="text" name="position" placeholder="Software Engineer"></div>
        <div class="form-group"><label>Salary (₹)</label><input type="number" name="salary" placeholder="50000"></div>
        <div class="form-group"><label>Hire Date</label><input type="date" name="hire_date" value="<?=date('Y-m-d')?>"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Employee</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ Edit Employee Modal ══ -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-title">✏️ Edit Employee <button class="modal-close" onclick="closeEditModal()">×</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="emp_id" id="edit_id">
      <div class="form-grid">
        <div class="form-section">Personal Information</div>
        <div class="form-group full"><label>Full Name</label><input type="text" name="full_name" id="edit_name" required></div>
        <div class="form-group"><label>Date of Birth</label><input type="date" name="date_of_birth" id="edit_dob"></div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" id="edit_phone"></div>
        <div class="form-group full"><label>Address</label><textarea name="address" id="edit_address" placeholder="Street, City, State - PIN"></textarea></div>
        <div class="form-group"><label>New Password (optional)</label><input type="password" name="password" placeholder="Leave blank to keep"></div>
        <div class="form-group"><label>Status</label><select name="status" id="edit_status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="form-section">Employment Details</div>
        <div class="form-group"><label>Department</label><select name="department" id="edit_dept"><?php foreach($departments as $d): ?><option value="<?=$d['name']?>"><?=$d['icon']?> <?=$d['name']?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Position</label><input type="text" name="position" id="edit_pos"></div>
        <div class="form-group"><label>Salary (₹)</label><input type="number" name="salary" id="edit_salary"></div>
        <div class="form-group"><label>Hire Date</label><input type="date" name="hire_date" id="edit_hire"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-cancel" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function showPage(id,btn){
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  document.getElementById('page-'+id)?.classList.add('active');
  if(btn) btn.classList.add('active');
}
function filterDept(dept){
  window.location.href='?dept='+encodeURIComponent(dept)+'#employees';
  // switch page
  setTimeout(()=>showPage('employees',document.querySelectorAll('.nav-item')[1]),100);
}
function openModal(){document.getElementById('addModal').classList.add('open')}
function closeModal(){document.getElementById('addModal').classList.remove('open')}
function openEditModal(e){
  document.getElementById('edit_id').value     =e.id;
  document.getElementById('edit_name').value   =e.full_name;
  document.getElementById('edit_dob').value    =e.date_of_birth||'';
  document.getElementById('edit_phone').value  =e.phone||'';
  document.getElementById('edit_address').value=e.address||'';
  document.getElementById('edit_status').value =e.status;
  document.getElementById('edit_dept').value   =e.department;
  document.getElementById('edit_pos').value    =e.position;
  document.getElementById('edit_salary').value =e.salary;
  document.getElementById('edit_hire').value   =e.hire_date||'';
  document.getElementById('editModal').classList.add('open');
}
function closeEditModal(){document.getElementById('editModal').classList.remove('open')}
window.onclick=e=>{
  if(e.target.id==='addModal')closeModal();
  if(e.target.id==='editModal')closeEditModal();
}
// Keep correct page active on page reload after form submit
const urlParams=new URLSearchParams(window.location.search);
if(urlParams.has('dept')){
  showPage('employees',document.querySelectorAll('.nav-item')[1]);
}
</script>
</body>
</html>