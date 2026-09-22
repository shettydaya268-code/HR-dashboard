<?php
session_start();

// Database connection
$host = "localhost";
$dbname = "employee_management";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $db_error = "Database connection failed. Please check your configuration.";
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password_input = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? '';

    if (empty($email) || empty($password_input) || empty($role)) {
        $error = "All fields are required.";
    } elseif (isset($pdo)) {
        if ($role === 'hr') {
            $stmt = $pdo->prepare("SELECT * FROM hr WHERE email = ? AND status = 'active'");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE email = ? AND status = 'active'");
        }
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password_input, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_email']= $user['email'];
            $_SESSION['role']      = $role;

            if ($role === 'hr') {
                header("Location: hr_dashboard.php");
            } else {
                header("Location: employee_dashboard.php");
            }
            exit;
        } else {
            $error = "Invalid email or password.";
        }
    } else {
        $error = $db_error ?? "System error. Try again later.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EMS — Login</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --ink: #0f0f13;
    --surface: #16161e;
    --card: #1e1e2a;
    --border: #2a2a3a;
    --accent: #6c63ff;
    --accent2: #ff6b9d;
    --gold: #f5c842;
    --text: #e8e8f0;
    --muted: #888899;
    --success: #4ade80;
    --error: #ff6b6b;
  }

  body {
    font-family: 'DM Sans', sans-serif;
    background: var(--ink);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
  }

  /* Background grid + blobs */
  body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
      linear-gradient(rgba(108,99,255,0.04) 1px, transparent 1px),
      linear-gradient(90deg, rgba(108,99,255,0.04) 1px, transparent 1px);
    background-size: 40px 40px;
    z-index: 0;
  }

  .blob {
    position: fixed;
    border-radius: 50%;
    filter: blur(80px);
    opacity: 0.25;
    z-index: 0;
  }
  .blob-1 { width: 500px; height: 500px; background: var(--accent); top: -150px; right: -100px; animation: drift 12s ease-in-out infinite; }
  .blob-2 { width: 400px; height: 400px; background: var(--accent2); bottom: -100px; left: -100px; animation: drift 15s ease-in-out infinite reverse; }
  .blob-3 { width: 300px; height: 300px; background: var(--gold); top: 50%; left: 50%; transform: translate(-50%,-50%); animation: drift 18s ease-in-out infinite 3s; }

  @keyframes drift {
    0%,100% { transform: translate(0,0) scale(1); }
    33% { transform: translate(30px,-20px) scale(1.05); }
    66% { transform: translate(-20px,30px) scale(0.95); }
  }

  .container {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 480px;
    padding: 24px;
    animation: slideUp 0.6s cubic-bezier(0.16,1,0.3,1) both;
  }

  @keyframes slideUp {
    from { opacity: 0; transform: translateY(40px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  /* Brand */
  .brand {
    text-align: center;
    margin-bottom: 32px;
  }
  .brand-logo {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 64px; height: 64px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 18px;
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    font-size: 22px;
    color: #fff;
    margin-bottom: 16px;
    box-shadow: 0 8px 32px rgba(108,99,255,0.4);
  }
  .brand-name {
    font-family: 'Syne', sans-serif;
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -0.5px;
    background: linear-gradient(135deg, #fff 40%, var(--accent));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }
  .brand-sub {
    font-size: 13px;
    color: var(--muted);
    margin-top: 4px;
    letter-spacing: 0.5px;
  }

  /* Card */
  .card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 40px;
    box-shadow: 0 24px 80px rgba(0,0,0,0.4);
    backdrop-filter: blur(20px);
  }

  .card-title {
    font-family: 'Syne', sans-serif;
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 6px;
  }
  .card-sub {
    font-size: 14px;
    color: var(--muted);
    margin-bottom: 28px;
  }

  /* Role tabs */
  .role-tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 28px;
  }
  .role-tab {
    position: relative;
    cursor: pointer;
  }
  .role-tab input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0; height: 0;
  }
  .role-tab label {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 16px;
    border-radius: 12px;
    border: 1.5px solid var(--border);
    cursor: pointer;
    font-weight: 500;
    font-size: 14px;
    color: var(--muted);
    transition: all 0.2s;
    background: var(--surface);
  }
  .role-tab input:checked + label {
    border-color: var(--accent);
    color: var(--text);
    background: rgba(108,99,255,0.12);
    box-shadow: 0 0 0 3px rgba(108,99,255,0.1);
  }
  .role-tab label .icon { font-size: 18px; }

  /* Form */
  .field { margin-bottom: 20px; }
  .field label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--muted);
    margin-bottom: 8px;
    letter-spacing: 0.3px;
  }
  .field input {
    width: 100%;
    padding: 14px 16px;
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: 12px;
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    font-size: 15px;
    transition: border-color 0.2s, box-shadow 0.2s;
    outline: none;
  }
  .field input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(108,99,255,0.12);
  }
  .field input::placeholder { color: var(--muted); }

  .forgot {
    text-align: right;
    margin-top: -12px;
    margin-bottom: 20px;
  }
  .forgot a {
    font-size: 13px;
    color: var(--accent);
    text-decoration: none;
    opacity: 0.8;
    transition: opacity 0.2s;
  }
  .forgot a:hover { opacity: 1; }

  .btn {
    width: 100%;
    padding: 15px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border: none;
    border-radius: 12px;
    color: #fff;
    font-family: 'Syne', sans-serif;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.1s;
    box-shadow: 0 4px 24px rgba(108,99,255,0.35);
    letter-spacing: 0.3px;
  }
  .btn:hover { opacity: 0.9; transform: translateY(-1px); }
  .btn:active { transform: translateY(0); }

  .alert {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 14px;
    margin-bottom: 20px;
    background: rgba(255,107,107,0.1);
    border: 1px solid rgba(255,107,107,0.3);
    color: var(--error);
  }

  .divider {
    text-align: center;
    margin: 20px 0;
    position: relative;
    color: var(--muted);
    font-size: 12px;
  }
  .divider::before, .divider::after {
    content: '';
    position: absolute;
    top: 50%;
    width: 40%;
    height: 1px;
    background: var(--border);
  }
  .divider::before { left: 0; }
  .divider::after { right: 0; }

  .footer-note {
    text-align: center;
    margin-top: 20px;
    font-size: 13px;
    color: var(--muted);
  }
  .footer-note span { color: var(--text); font-weight: 500; }
</style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>
<div class="blob blob-3"></div>

<div class="container">
  <div class="brand">
    <div class="brand-logo">ACE</div>
    <div class="brand-name">ACE INTERNATIONAL LTD</div>
    <div class="brand-sub">Employee Management System</div>
  </div>

  <div class="card">
    <div class="card-title">Welcome back 👋</div>
    <div class="card-sub">Sign in to access your dashboard</div>

    <?php if ($error): ?>
    <div class="alert">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="role-tabs">
        <div class="role-tab">
          <input type="radio" name="role" id="role_hr" value="hr" <?= (($_POST['role'] ?? '') === 'hr' || !isset($_POST['role'])) ? 'checked' : '' ?>>
          <label for="role_hr"><span class="icon">🏢</span> HR Admin</label>
        </div>
        <div class="role-tab">
          <input type="radio" name="role" id="role_emp" value="employee" <?= (($_POST['role'] ?? '') === 'employee') ? 'checked' : '' ?>>
          <label for="role_emp"><span class="icon">👤</span> Employee</label>
        </div>
      </div>

      <div class="field">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="you@company.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>

      <div class="field">
        <label>Password</label>
        <input type="password" name="password" placeholder="Enter your password" required>
      </div>

      <div class="forgot"><a href="#">Forgot password?</a></div>

      <button type="submit" class="btn">Sign In →</button>
    </form>

    <div class="footer-note">
      Powered by <span>ACE INTERNATIONAL</span> &bull; Secured &amp; Encrypted
    </div>
  </div>
</div>

</body>
</html>