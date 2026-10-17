<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Khu vực owner: chỉ tài khoản admin quản lý tất cả gia tộc (managed_family_id = NULL).
if (!isLoggedIn() || !isAdmin() || $_SESSION['managed_family_id'] !== null) {
    redirect('../login.php', 'Bạn không có quyền truy cập!', 'error');
}

$error = '';

function packageInput(): array {
    $packageName = trim($_POST['package_name'] ?? '');
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $duration = filter_var($_POST['duration_month'] ?? null, FILTER_VALIDATE_INT);
    $memberLimitRaw = trim($_POST['member_limit'] ?? '');
    $memberLimit = $memberLimitRaw === '' ? null : filter_var($memberLimitRaw, FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? 'ACTIVE';
    $paymentType = $_POST['payment_type'] ?? 'FREE';

    if ($packageName === '') {
        throw new InvalidArgumentException('Vui lòng nhập tên gói dịch vụ.');
    }
    if ($price === false || $price < 0) {
        throw new InvalidArgumentException('Giá gói phải là một số không âm.');
    }
    if ($duration === false || $duration < 1) {
        throw new InvalidArgumentException('Thời hạn phải ít nhất 1 tháng.');
    }
    if ($memberLimit === false || ($memberLimit !== null && $memberLimit < 1)) {
        throw new InvalidArgumentException('Giới hạn thành viên phải lớn hơn 0 hoặc để trống cho không giới hạn.');
    }
    if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
        throw new InvalidArgumentException('Trạng thái gói không hợp lệ.');
    }
    if (!in_array($paymentType, ['FREE', 'PAID'], true)) {
        throw new InvalidArgumentException('Loại thanh toán không hợp lệ.');
    }

    return [$packageName, $price, $duration, $memberLimit, $status, $paymentType];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            [$name, $price, $duration, $limit, $status, $paymentType] = packageInput();
            $stmt = $pdo->prepare('
                INSERT INTO service_packages (package_name, price, duration_month, member_limit, status, payment_type)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$name, $price, $duration, $limit, $status, $paymentType]);
            redirect('manage_service_packages.php', 'Thêm gói dịch vụ thành công!');
        }

        if ($action === 'edit') {
            $packageId = filter_var($_POST['package_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$packageId) {
                throw new InvalidArgumentException('Không tìm thấy gói dịch vụ cần cập nhật.');
            }
            [$name, $price, $duration, $limit, $status, $paymentType] = packageInput();
            $stmt = $pdo->prepare('
                UPDATE service_packages
                SET package_name = ?, price = ?, duration_month = ?, member_limit = ?, status = ?, payment_type = ?
                WHERE package_id = ?
            ');
            $stmt->execute([$name, $price, $duration, $limit, $status, $paymentType, $packageId]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare('SELECT COUNT(*) FROM service_packages WHERE package_id = ?');
                $check->execute([$packageId]);
                if (!(int) $check->fetchColumn()) {
                    throw new InvalidArgumentException('Gói dịch vụ không tồn tại.');
                }
            }
            redirect('manage_service_packages.php', 'Cập nhật gói dịch vụ thành công!');
        }

        if ($action === 'delete') {
            $packageId = filter_var($_POST['package_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$packageId) {
                throw new InvalidArgumentException('Không tìm thấy gói dịch vụ cần xóa.');
            }

            $usage = $pdo->prepare('SELECT COUNT(*) FROM families WHERE package_id = ?');
            $usage->execute([$packageId]);
            $familyCount = (int) $usage->fetchColumn();
            if ($familyCount > 0) {
                throw new InvalidArgumentException("Không thể xóa gói này vì đang được {$familyCount} gia tộc sử dụng.");
            }

            $stmt = $pdo->prepare('DELETE FROM service_packages WHERE package_id = ?');
            $stmt->execute([$packageId]);
            if ($stmt->rowCount() === 0) {
                throw new InvalidArgumentException('Gói dịch vụ không tồn tại hoặc đã bị xóa.');
            }
            redirect('manage_service_packages.php', 'Xóa gói dịch vụ thành công!');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = 'Không thể lưu dữ liệu gói dịch vụ. Vui lòng thử lại.';
    }
}

$search = trim($_GET['search'] ?? '');
$sql = '
    SELECT sp.*, COUNT(f.family_id) AS family_count
    FROM service_packages sp
    LEFT JOIN families f ON f.package_id = sp.package_id
';
$params = [];
if ($search !== '') {
    $sql .= ' WHERE sp.package_name LIKE ?';
    $params[] = '%' . $search . '%';
}
$sql .= ' GROUP BY sp.package_id, sp.package_name, sp.price, sp.duration_month, sp.member_limit, sp.status, sp.payment_type
          ORDER BY sp.package_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý gói dịch vụ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --brand: #667eea; --brand-dark: #764ba2; --ink: #253047; }
        body { background: #f8f9fa; color: var(--ink); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { background: linear-gradient(180deg, var(--brand) 0%, var(--brand-dark) 100%); min-height: 100vh; box-shadow: 2px 0 10px rgba(0,0,0,.1); }
        .sidebar .nav-link { color: rgba(255,255,255,.8); padding: 1rem 1.5rem; border-radius: 10px; margin: .25rem 0; transition: all .25s ease; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,.2); color: #fff; transform: translateX(5px); }
        .main-content { padding: 2rem; }
        .card { border: 0; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,.08); }
        .page-mark { width: 48px; height: 48px; display: grid; place-items: center; color: #fff; border-radius: 14px; background: linear-gradient(135deg, var(--brand), var(--brand-dark)); box-shadow: 0 8px 18px rgba(102,126,234,.25); }
        .package-card { position: relative; overflow: hidden; transition: transform .2s ease, box-shadow .2s ease; }
        .package-card:hover { transform: translateY(-4px); box-shadow: 0 12px 26px rgba(49,59,93,.14); }
        .package-card::before { content: ''; height: 5px; position: absolute; inset: 0 0 auto; background: linear-gradient(90deg, var(--brand), var(--brand-dark)); }
        .price { color: var(--brand-dark); font-size: 1.65rem; font-weight: 700; }
        .feature-icon { width: 34px; color: var(--brand); text-align: center; }
        .status-active { background: #dcfce7; color: #18794e; }
        .status-inactive { background: #f1f5f9; color: #64748b; }
        .payment-paid { background: #fff3cd; color: #8a5a00; }
        .payment-free { background: #e8ecff; color: #4c59b8; }
        .badge { font-weight: 600; padding: .42rem .65rem; }
        .btn-primary { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); border: 0; }
        .btn-primary:hover { opacity: .93; }
        .modal-content { border: 0; border-radius: 16px; overflow: hidden; }
        .modal-header { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; }
        .modal-header .btn-close { filter: brightness(0) invert(1); }
        @media (max-width: 767px) { .main-content { padding: 1.25rem; } .sidebar { min-height: auto; } }
    </style>
</head>
<body>
<div class="container-fluid"><div class="row">
    <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
        <div class="position-sticky pt-3">
            <div class="text-center mb-4">
                <h4 class="text-white"><i class="fas fa-sitemap me-2"></i>Owner Panel</h4>
                <small class="text-white-50">Xin chào, <?= htmlspecialchars($_SESSION['full_name']) ?></small>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="manage_users.php">
                        <i class="fas fa-users me-2"></i>Quản lý tài khoản
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="manage_service_packages.php">
                        <i class="fas fa-box-open me-2"></i>Quản lý gói dịch vụ
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_family.php">
                        <i class="fas fa-home me-2"></i>Quản lý gia tộc
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../logout.php" onclick="return confirm('Bạn có chắc muốn đăng xuất?')">
                        <i class="fas fa-sign-out-alt me-2"></i>Đăng xuất
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <main class="col-md-9 ms-sm-auto col-lg-10 main-content">
        <?= showMessage() ?>
        <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 pb-3 mb-4 border-bottom">
            <div class="d-flex align-items-center gap-3">
                <div class="page-mark"><i class="fas fa-box-open fa-lg"></i></div>
                <div><h1 class="h2 mb-1">Quản lý gói dịch vụ</h1><p class="text-muted mb-0">Thiết lập gói sử dụng cho các gia tộc</p></div>
            </div>
            <button class="btn btn-primary px-3" data-bs-toggle="modal" data-bs-target="#addPackageModal"><i class="fas fa-plus me-2"></i>Thêm gói dịch vụ</button>
        </div>

        <div class="card mb-4"><div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-7"><input type="search" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Tìm theo tên gói dịch vụ..."></div>
                <div class="col-md-5 d-flex gap-2"><button class="btn btn-outline-primary"><i class="fas fa-search me-1"></i>Tìm kiếm</button><a href="manage_service_packages.php" class="btn btn-outline-secondary"><i class="fas fa-rotate-right"></i></a></div>
            </form>
        </div></div>

        <div class="row">
            <?php foreach ($packages as $package): ?>
            <div class="col-xl-4 col-md-6 mb-4">
                <article class="card package-card h-100"><div class="card-body pt-4 d-flex flex-column">
                    <div class="d-flex justify-content-between gap-2 align-items-start mb-3">
                        <div><h5 class="mb-1"><?= htmlspecialchars($package['package_name']) ?></h5><span class="text-muted small">Mã gói #<?= (int) $package['package_id'] ?></span></div>
                        <span class="badge <?= $package['status'] === 'ACTIVE' ? 'status-active' : 'status-inactive' ?>"><?= $package['status'] === 'ACTIVE' ? 'Đang hoạt động' : 'Ngừng hoạt động' ?></span>
                    </div>
                    <div class="price mb-3"><?= number_format((float) $package['price'], 0, ',', '.') ?> <small class="fs-6 fw-normal">VNĐ</small></div>
                    <ul class="list-unstyled mb-4 small">
                        <li class="mb-2"><i class="fas fa-calendar-days feature-icon me-1"></i><?= (int) $package['duration_month'] ?> tháng sử dụng</li>
                        <li class="mb-2"><i class="fas fa-users feature-icon me-1"></i><?= $package['member_limit'] === null ? 'Không giới hạn thành viên' : number_format((int) $package['member_limit']) . ' thành viên' ?></li>
                        <li><i class="fas fa-wallet feature-icon me-1"></i><span class="badge <?= $package['payment_type'] === 'PAID' ? 'payment-paid' : 'payment-free' ?>"><?= $package['payment_type'] === 'PAID' ? 'Trả phí' : 'Miễn phí' ?></span></li>
                    </ul>
                    <div class="mt-auto pt-3 border-top d-flex justify-content-between align-items-center"><small class="text-muted"><i class="fas fa-home me-1"></i><?= (int) $package['family_count'] ?> gia tộc</small><div class="btn-group"><button type="button" class="btn btn-sm btn-outline-primary" onclick='editPackage(<?= htmlspecialchars(json_encode($package), ENT_QUOTES, "UTF-8") ?>)' title="Sửa"><i class="fas fa-pen"></i></button><button type="button" class="btn btn-sm btn-outline-danger" onclick="deletePackage(<?= (int) $package['package_id'] ?>, <?= htmlspecialchars(json_encode($package['package_name']), ENT_QUOTES, 'UTF-8') ?>, <?= (int) $package['family_count'] ?>)" title="Xóa"><i class="fas fa-trash"></i></button></div></div>
                </div></article>
            </div>
            <?php endforeach; ?>
            <?php if (!$packages): ?><div class="col-12"><div class="card"><div class="card-body text-center py-5 text-muted"><i class="fas fa-box-open fa-3x mb-3"></i><h5>Chưa có gói dịch vụ nào</h5><p class="mb-0">Hãy tạo gói đầu tiên để bắt đầu cung cấp dịch vụ.</p></div></div></div><?php endif; ?>
        </div>
    </main>
</div></div>

<div class="modal fade" id="addPackageModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="POST"><input type="hidden" name="action" value="add">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Thêm gói dịch vụ</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><?php include __DIR__ . '/package_form_fields.php'; ?></div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Hủy</button><button class="btn btn-primary"><i class="fas fa-save me-2"></i>Lưu gói dịch vụ</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="editPackageModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="package_id" id="edit_package_id">
        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-pen-to-square me-2"></i>Cập nhật gói dịch vụ</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Tên gói <span class="text-danger">*</span></label><input class="form-control" name="package_name" id="edit_package_name" maxlength="100" required></div>
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Giá (VNĐ) <span class="text-danger">*</span></label><input class="form-control" name="price" id="edit_price" type="number" min="0" step="0.01" required></div><div class="col-md-6 mb-3"><label class="form-label">Thời hạn (tháng) <span class="text-danger">*</span></label><input class="form-control" name="duration_month" id="edit_duration_month" type="number" min="1" required></div></div>
            <div class="mb-3"><label class="form-label">Giới hạn thành viên</label><input class="form-control" name="member_limit" id="edit_member_limit" type="number" min="1" placeholder="Để trống nếu không giới hạn"></div>
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Loại thanh toán</label><select class="form-select" name="payment_type" id="edit_payment_type"><option value="FREE">Miễn phí</option><option value="PAID">Trả phí</option></select></div><div class="col-md-6 mb-3"><label class="form-label">Trạng thái</label><select class="form-select" name="status" id="edit_status"><option value="ACTIVE">Đang hoạt động</option><option value="INACTIVE">Ngừng hoạt động</option></select></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Hủy</button><button class="btn btn-primary"><i class="fas fa-save me-2"></i>Cập nhật</button></div>
    </form>
</div></div></div>

<form id="deletePackageForm" method="POST" class="d-none"><input type="hidden" name="action" value="delete"><input type="hidden" name="package_id" id="delete_package_id"></form>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editPackage(pkg) {
    document.getElementById('edit_package_id').value = pkg.package_id;
    document.getElementById('edit_package_name').value = pkg.package_name;
    document.getElementById('edit_price').value = pkg.price;
    document.getElementById('edit_duration_month').value = pkg.duration_month;
    document.getElementById('edit_member_limit').value = pkg.member_limit ?? '';
    document.getElementById('edit_payment_type').value = pkg.payment_type;
    document.getElementById('edit_status').value = pkg.status;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editPackageModal')).show();
}
function deletePackage(id, name, familyCount) {
    const message = familyCount > 0
        ? `Gói “${name}” đang được ${familyCount} gia tộc sử dụng và không thể xóa.`
        : `Bạn có chắc muốn xóa gói “${name}”? Thao tác này không thể hoàn tác.`;
    if (familyCount === 0 && confirm(message)) { document.getElementById('delete_package_id').value = id; document.getElementById('deletePackageForm').submit(); }
    if (familyCount > 0) alert(message);
}
</script>
</body>
</html>
