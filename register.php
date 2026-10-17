<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Nếu đã đăng nhập thì chuyển hướng
if (isLoggedIn()) {
    if (isAdmin()) {
        redirect('admin/dashboard.php');
    } else {
        redirect('user/dashboard.php');
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username   = trim($_POST['username'] ?? '');
    $full_name  = trim($_POST['full_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($username) || empty($full_name) || empty($email) || empty($password)) {
        $error = 'Vui lòng điền đầy đủ thông tin!';
    } elseif ($password !== $confirm_password) {
        $error = 'Mật khẩu xác nhận không khớp!';
    } elseif (strlen($password) < 6) {
        $error = 'Mật khẩu phải có ít nhất 6 ký tự!';
    } else {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO users (username, full_name, email, password, role) VALUES (?, ?, ?, ?, 'user')");
            $stmt->execute([$username, $full_name, $email, $hashed_password]);

            $success = 'Đăng ký tài khoản thành công! Bạn có thể đăng nhập ngay bây giờ.';
            
            // Tự động chuyển hướng sau 3 giây
            header("refresh:3;url=login.php");
            
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = 'Email này đã tồn tại!';
            } else {
                $error = 'Đã xảy ra lỗi, vui lòng thử lại!';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký tài khoản - Gia Phả Dòng Họ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .register-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
            overflow: hidden;
            max-width: 1000px;
            width: 100%;
        }
        .register-image {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem;
        }
        .register-form {
            padding: 3rem 2.5rem;
        }
        .form-control {
            border: none;
            border-bottom: 2px solid #eee;
            border-radius: 0;
            padding: 12px 0;
            margin-bottom: 1.5rem;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: none;
        }
        .btn-register {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 50px;
            padding: 14px 30px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
        }
        .btn-register:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(102,126,234,0.3);
        }
        .brand-logo {
            font-size: 3.8rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="register-container">
                    <div class="row g-0">
                        <!-- Bên trái - Hình ảnh -->
                        <div class="col-lg-5 register-image">
                            <div class="text-center">
                                <div class="brand-logo">
                                    <i class="fas fa-sitemap"></i>
                                </div>
                                <h2 class="fw-bold">Gia Phả Dòng Họ</h2>
                                <p class="lead mb-0">Kết nối quá khứ<br> Lưu giữ tương lai</p>
                            </div>
                        </div>

                        <!-- Bên phải - Form đăng ký -->
                        <div class="col-lg-7">
                            <div class="register-form">
                                <h3 class="text-center mb-4">Đăng ký tài khoản mới</h3>

                                <?php if ($error): ?>
                                    <div class="alert alert-danger"><?= $error ?></div>
                                <?php endif; ?>

                                <?php if ($success): ?>
                                    <div class="alert alert-success"><?= $success ?></div>
                                <?php endif; ?>

                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label class="form-label"><i class="fas fa-user me-2"></i>Tên đăng nhập</label>
                                            <input type="text" name="username" class="form-control" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label"><i class="fas fa-envelope me-2"></i>Email</label>
                                            <input type="email" name="email" class="form-control" required>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-user-circle me-2"></i>Họ và tên</label>
                                        <input type="text" name="full_name" class="form-control" required>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <label class="form-label"><i class="fas fa-lock me-2"></i>Mật khẩu</label>
                                            <input type="password" name="password" class="form-control" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label"><i class="fas fa-lock me-2"></i>Xác nhận mật khẩu</label>
                                            <input type="password" name="confirm_password" class="form-control" required>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-register text-white mt-3">
                                        <i class="fas fa-user-plus me-2"></i>Đăng ký
                                    </button>
                                </form>

                                <div class="text-center mt-4">
                                    <small class="text-muted">
                                        Đã có tài khoản? 
                                        <a href="login.php" class="text-primary fw-semibold">Đăng nhập ngay</a>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>