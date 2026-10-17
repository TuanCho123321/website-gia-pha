<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    redirect('../login.php', 'Vui lòng đăng nhập!', 'error');
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Liên hệ - Gia phả điện tử</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --ink: #21352a; --leaf: #356148; --mist: #f4f7f1; --gold: #d4a64a; }
        body { background: var(--mist); color: var(--ink); font-family: Georgia, 'Times New Roman', serif; }
        .sidebar { background: linear-gradient(180deg, #315c46, #1e3b2c); min-height: 100vh; box-shadow: 2px 0 10px rgba(0,0,0,.1); }
        .sidebar .nav-link { color: rgba(255,255,255,.82); padding: 1rem 1.5rem; border-radius: 10px; margin: .25rem 0; transition: .2s ease; font-family: 'Segoe UI', sans-serif; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,.16); color: white; transform: translateX(5px); }
        .main-content { padding: 2rem; }
        .contact-card { max-width: 900px; border: 0; border-radius: 22px; overflow: hidden; box-shadow: 0 18px 45px rgba(34, 59, 42, .12); }
        .contact-hero { padding: 3rem; color: white; background: linear-gradient(145deg, #2f5b44, #1d3528); }
        .contact-hero .seal { width: 70px; height: 70px; display: grid; place-items: center; border: 1px solid rgba(255,255,255,.45); border-radius: 50%; color: #f3d888; font-size: 1.75rem; }
        .contact-list { padding: 2rem; }
        .contact-item { display: flex; align-items: center; gap: 1rem; padding: 1rem 0; border-bottom: 1px solid #e8eee5; }
        .contact-item:last-child { border-bottom: 0; }
        .contact-icon { width: 44px; height: 44px; display: grid; place-items: center; flex: 0 0 auto; border-radius: 12px; background: #e8f0e6; color: var(--leaf); }
        .contact-item a { color: var(--leaf); font-family: 'Segoe UI', sans-serif; font-weight: 600; text-decoration: none; word-break: break-word; }
        .contact-item a:hover { color: #1d3528; text-decoration: underline; }
        .label { display: block; color: #728074; font: 700 .72rem/1 'Segoe UI', sans-serif; letter-spacing: .08em; text-transform: uppercase; margin-bottom: .25rem; }
        @media (max-width: 768px) { .sidebar { min-height: auto; } .main-content { padding: 1rem; } .contact-hero { padding: 2rem; } }
    </style>
</head>
<body>
<div class="container-fluid"><div class="row">
    <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
        <div class="position-sticky pt-3">
            <div class="text-center mb-4"><h4 class="text-white"><i class="fas fa-sitemap me-2"></i>Gia Phả Gia Đình</h4><small class="text-white-50">Xin chào, <?= htmlspecialchars($_SESSION['full_name']) ?></small></div>
            <ul class="nav flex-column">
                <li class="nav-item"><a class="nav-link" href="manage_family.php"><i class="fas fa-home me-2"></i>Quản lý gia tộc</a></li>
                <li class="nav-item"><a class="nav-link" href="register_service_packages.php"><i class="fas fa-box-open me-2"></i>Đăng ký gói dịch vụ</a></li>
                <li class="nav-item"><a class="nav-link active" href="contact.php"><i class="fas fa-address-card me-2"></i>Liên hệ</a></li>
                <li class="nav-item"><a class="nav-link" href="profile.php"><i class="fas fa-user-circle me-2"></i>Hồ sơ cá nhân</a></li>
                <li class="nav-item mt-5"><a class="nav-link text-warning" href="../logout.php" onclick="return confirm('Bạn thực sự muốn đăng xuất?')"><i class="fas fa-sign-out-alt me-2"></i>Đăng xuất</a></li>
            </ul>
        </div>
    </nav>
    <main class="col-md-9 ms-sm-auto col-lg-10 main-content d-flex align-items-center">
        <section class="card contact-card mx-auto w-100">
            <div class="row g-0">
                <div class="col-lg-5 contact-hero"><div class="seal mb-4"><i class="fas fa-envelope-open-text"></i></div><p class="text-uppercase small mb-2" style="letter-spacing:.12em;color:#f3d888">Hỗ trợ gia phả</p><h1 class="display-6">Kết nối cùng chúng tôi</h1><p class="mb-0 opacity-75">Chọn phương thức phù hợp để được hỗ trợ về tài khoản, gói dịch vụ hoặc gia phả của bạn.</p></div>
                <div class="col-lg-7 contact-list">
                    <div class="contact-item"><div class="contact-icon"><i class="fas fa-envelope"></i></div><div><span class="label">Email</span><a href="mailto:leanhtuanzxt@gmail.com">leanhtuanzxt@gmail.com</a></div></div>
                    <div class="contact-item"><div class="contact-icon"><i class="fas fa-phone"></i></div><div><span class="label">Số điện thoại</span><a href="tel:0867612409">0867612409</a></div></div>
                    <div class="contact-item"><div class="contact-icon"><i class="fas fa-phone-volume"></i></div><div><span class="label">Số điện thoại 2</span><a href="tel:0346746303">0346746303</a></div></div>
                    <div class="contact-item"><div class="contact-icon"><i class="fab fa-facebook-f"></i></div><div><span class="label">Facebook</span><a href="https://www.facebook.com/profile.php?id=61587537489279" target="_blank" rel="noopener noreferrer">Mở trang Facebook <i class="fas fa-arrow-up-right-from-square ms-1 small"></i></a></div></div>
                </div>
            </div>
        </section>
    </main>
</div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
