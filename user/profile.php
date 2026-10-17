<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    redirect('../login.php', 'Vui lòng đăng nhập!', 'error');
}

// Xử lý cập nhật thông tin cá nhân
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    
    if ($full_name && $email) {
        try {
            $user_id = $_SESSION['user_id'];
            
            // Kiểm tra mật khẩu hiện tại nếu muốn đổi mật khẩu
            if (!empty($new_password)) {
                $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                if (!password_verify($current_password, $user['password'])) {
                    $error = 'Mật khẩu hiện tại không đúng!';
                } else {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, password = ? WHERE user_id = ?");
                    $stmt->execute([$full_name, $email, $hashed, $user_id]);
                    $_SESSION['full_name'] = $full_name; // cập nhật session
                    redirect('profile.php', 'Cập nhật thông tin và mật khẩu thành công!', 'success');
                }
            } else {
                // Chỉ cập nhật thông tin không đổi mật khẩu
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?");
                $stmt->execute([$full_name, $email, $user_id]);
                $_SESSION['full_name'] = $full_name;
                redirect('profile.php', 'Cập nhật thông tin cá nhân thành công!', 'success');
            }
        } catch (PDOException $e) {
            $error = 'Lỗi: Email đã tồn tại hoặc có vấn đề kỹ thuật!';
        }
    } else {
        $error = 'Vui lòng nhập đầy đủ họ tên và email!';
    }
}

// Lấy thông tin hiện tại
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cập nhật thông tin cá nhân</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { background: linear-gradient(180deg, #667eea 0%, #764ba2 100%); min-height: 100vh; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 1rem 1.5rem; border-radius: 10px; margin: 0.25rem 0; transition: all 0.3s ease; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: rgba(255,255,255,0.2); color: white; transform: translateX(5px); }
        .main-content { padding: 2rem; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar chung cho user -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <h4 class="text-white"><i class="fas fa-sitemap me-2"></i>Gia Phả Gia Đình</h4>
                        <small class="text-white-50">Xin chào, <?= htmlspecialchars($_SESSION['full_name']) ?></small>
                    </div>
                    <ul class="nav flex-column">
                        <li class="nav-item"><a class="nav-link" href="manage_family.php"><i class="fas fa-home me-2"></i>Quản lý gia tộc</a></li>
                        <li class="nav-item"><a class="nav-link" href="register_service_packages.php"><i class="fas fa-box-open me-2"></i>Đăng ký gói dịch vụ</a></li>
                        <li class="nav-item"><a class="nav-link" href="contact.php"><i class="fas fa-address-card me-2"></i>Liên hệ</a></li>
                        <li class="nav-item"><a class="nav-link" href="profile.php"><i class="fas fa-user-circle me-3"></i>Hồ sơ cá nhân</a></li>
                        <li class="nav-item mt-5"><a class="nav-link text-danger" href="../logout.php" onclick="return confirm('Bạn thực sự muốn đăng xuất?')"><i class="fas fa-sign-out-alt me-3"></i>Đăng xuất</a></li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content">
                <?= showMessage() ?>
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h2">Cập nhật thông tin cá nhân</h1>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Tên đăng nhập (không thay đổi)</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Vai trò</label>
                                    <input type="text" class="form-control" value="<?= $user['role'] === 'admin' ? 'Quản trị viên' : 'Người dùng' ?>" readonly>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Họ và tên *</label>
                                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
                            </div>

                            <hr>
                            <h5 class="mb-3">Đổi mật khẩu (tùy chọn)</h5>
                            <div class="mb-3">
                                <label class="form-label">Mật khẩu hiện tại</label>
                                <input type="password" name="current_password" class="form-control" placeholder="Nhập nếu muốn đổi mật khẩu">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Mật khẩu mới</label>
                                <input type="password" name="new_password" class="form-control" placeholder="Để trống nếu không đổi">
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-save me-2"></i>Lưu thay đổi
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
