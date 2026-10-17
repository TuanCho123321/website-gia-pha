<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../login.php', 'Bạn không có quyền truy cập!', 'error');
}

$familyId = (int) ($_SESSION['managed_family_id'] ?? 0);
if (!$familyId) {
    redirect('../login.php', 'Không tìm thấy gia tộc được phân quyền.', 'error');
}
requireAdminFamilyAccess($familyId, $pdo);

$stmt = $pdo->prepare('
    SELECT f.family_name, f.package_status, f.package_start_date, f.package_end_date,
           sp.package_name, sp.payment_type
    FROM families f
    LEFT JOIN service_packages sp ON sp.package_id = f.package_id
    WHERE f.family_id = ?
');
$stmt->execute([$familyId]);
$family = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$family) {
    redirect('../login.php', 'Gia tộc không tồn tại.', 'error');
}
if (familySubscriptionIsActive($familyId, $pdo)) {
    redirect('manage_family.php');
}

$isPendingPayment = ($family['package_status'] ?? '') === 'PENDING_PAYMENT';
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chờ xác nhận đăng ký</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; display: grid; place-items: center; background: #f7f4ed; color: #243229; }
        .notice { max-width: 610px; border: 0; border-radius: 20px; box-shadow: 0 18px 48px rgba(39, 57, 42, .14); }
        .seal { width: 70px; height: 70px; display: grid; place-items: center; margin: 0 auto 1.5rem; border-radius: 50%; background: #e7f0e6; color: #356148; font-size: 1.9rem; }
    </style>
</head>
<body class="p-3">
    <main class="card notice text-center">
        <div class="card-body p-4 p-md-5">
            <div class="seal"><i class="fas <?= $isPendingPayment ? 'fa-clock' : 'fa-calendar-xmark' ?>"></i></div>
            <p class="text-uppercase text-success fw-semibold small mb-2">Gia phả điện tử</p>
            <h1 class="h3 mb-3"><?= $isPendingPayment ? 'Yêu cầu đang chờ xác nhận' : 'Gói dịch vụ chưa có hiệu lực' ?></h1>
            <p class="text-muted mb-4">
                Gia tộc <strong><?= htmlspecialchars($family['family_name']) ?></strong>
                <?= $isPendingPayment
                    ? 'đã gửi yêu cầu đăng ký gói ' . htmlspecialchars($family['package_name'] ?? '') . '. Owner sẽ mở quyền quản lý sau khi xác nhận thanh toán.'
                    : 'hiện chưa có gói dịch vụ còn hiệu lực.' ?>
            </p>
            <div class="rounded-3 bg-light p-3 mb-4 small text-start">
                <div><span class="text-muted">Gói dịch vụ:</span> <strong><?= htmlspecialchars($family['package_name'] ?? 'Chưa chọn') ?></strong></div>
                <div class="mt-1"><span class="text-muted">Trạng thái:</span> <strong><?= $isPendingPayment ? 'Chờ Owner xác nhận' : 'Chưa kích hoạt / đã hết hạn' ?></strong></div>
            </div>
            <a class="btn btn-outline-secondary px-4" href="../logout.php"><i class="fas fa-right-from-bracket me-2"></i>Đăng xuất</a>
        </div>
    </main>
</body>
</html>
