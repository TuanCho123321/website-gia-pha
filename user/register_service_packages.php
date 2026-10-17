<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

const REGISTER_MOMO_PHONE = '0867612409';
const REGISTER_MOMO_OWNER = 'LÊ ANH TUẤN';

if (!isLoggedIn() || isAdmin()) {
    redirect('../login.php', 'Bạn không có quyền truy cập!', 'error');
}

function createFamilySubscription(PDO $pdo, int $userId, string $familyName, string $description, int $packageId, string $startDate): array {
    if ($familyName === '') throw new InvalidArgumentException('Vui lòng nhập tên gia tộc.');

    $stmt = $pdo->prepare('INSERT INTO families (family_name, description, created_by) VALUES (?, ?, ?)');
    $stmt->execute([$familyName, $description, $userId]);
    $familyId = (int) $pdo->lastInsertId();
    [, $status] = register_service_packages($pdo, $familyId, $packageId, $startDate);

    return [$status, $familyId];
}

function promoteCurrentUserToAdmin(PDO $pdo, int $userId, int $familyId): void {
    $promote = $pdo->prepare("UPDATE users SET role = 'admin', managed_family_id = ? WHERE user_id = ?");
    $promote->execute([$familyId, $userId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $familyName = trim($_POST['family_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $packageId = filter_var($_POST['package_id'] ?? null, FILTER_VALIDATE_INT);
    $startDate = $_POST['package_start_date'] ?? '';

    try {
        if (!$packageId) throw new InvalidArgumentException('Vui lòng chọn gói dịch vụ.');
        if ($familyName === '') throw new InvalidArgumentException('Vui lòng nhập tên gia tộc.');

        if ($action === 'prepare_registration') {
            // Kiểm tra gói và ngày trước khi hiển thị QR của gói trả phí.
            [$selectedPackage] = getPackageSubscription($pdo, $packageId, $startDate);
            if ($selectedPackage['payment_type'] === 'FREE') {
                $pdo->beginTransaction();
                [, $newFamilyId] = createFamilySubscription($pdo, (int) $_SESSION['user_id'], $familyName, $description, $packageId, $startDate);
                promoteCurrentUserToAdmin($pdo, (int) $_SESSION['user_id'], $newFamilyId);
                $pdo->commit();
                $_SESSION['role'] = 'admin';
                $_SESSION['managed_family_id'] = $newFamilyId;
                redirect('../admin/manage_family.php', 'Đăng ký gói miễn phí thành công. Tài khoản của bạn đã được nâng cấp thành Admin.');
            }

            // Gói PAID: chỉ hiển thị QR. Chưa tạo gia tộc/chuyển quyền ở bước này.
            $paymentPackage = $selectedPackage;
            $paymentForm = compact('familyName', 'description', 'packageId', 'startDate');
        } elseif ($action === 'submit_paid_request') {
            [$selectedPackage] = getPackageSubscription($pdo, $packageId, $startDate);
            if ($selectedPackage['payment_type'] !== 'PAID') throw new InvalidArgumentException('Gói đã chọn không phải gói trả phí.');
            $pdo->beginTransaction();
            [, $newFamilyId] = createFamilySubscription($pdo, (int) $_SESSION['user_id'], $familyName, $description, $packageId, $startDate);
            promoteCurrentUserToAdmin($pdo, (int) $_SESSION['user_id'], $newFamilyId);
            $pdo->commit();
            $_SESSION['role'] = 'admin';
            $_SESSION['managed_family_id'] = $newFamilyId;
            redirect('../admin/pending_confirmation.php', 'Đã gửi yêu cầu đăng ký. Tài khoản đã được nâng cấp thành Admin và đang chờ Owner xác nhận thanh toán.');
        } else {
            throw new InvalidArgumentException('Thao tác đăng ký không hợp lệ.');
        }
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Không thể tạo yêu cầu đăng ký. Vui lòng thử lại.';
    }
}

$packages = $pdo->query("SELECT package_id, package_name, price, duration_month, member_limit, payment_type FROM service_packages WHERE status = 'ACTIVE' ORDER BY price, package_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="vi"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Đăng ký gói dịch vụ</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f6f8fc}.package-card{border:2px solid transparent;height:100%;transition:.2s ease}.package-card:hover{transform:translateY(-4px);box-shadow:0 .75rem 1.5rem rgba(24,39,75,.12)}.package-price{color:#4f46e5;font-size:1.8rem;font-weight:700}.payment-qr{width:min(100%,270px);border-radius:14px;border:1px solid #f1d2e2}.payment-amount{color:#b52f7a;font-size:2rem;font-weight:700}.payment-account{background:#fff0f7;border-radius:12px;padding:1rem}
</style></head><body><main class="container py-5">
<a class="btn btn-outline-secondary btn-sm mb-3" href="manage_family.php">← Quản lý gia tộc</a>
<div class="mb-4"><h1 class="h2 mb-1">Đăng ký gói dịch vụ</h1><p class="text-muted mb-0">Chọn gói phù hợp để tạo gia tộc và bắt đầu quản lý gia phả.</p></div>
<?php if (isset($error)): ?><div class="alert alert-danger"><i class="fas fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="alert alert-info">Gói miễn phí được kích hoạt ngay. Gói trả phí cần được Owner xác nhận thanh toán trước khi bạn có thể quản lý gia tộc.</div>
<div class="row g-4"><?php foreach ($packages as $package): ?><div class="col-md-6 col-xl-4"><article class="card package-card shadow-sm"><div class="card-body d-flex flex-column p-4"><h2 class="h5 mb-3"><?= htmlspecialchars($package['package_name']) ?></h2><div class="package-price mb-3"><?= $package['payment_type'] === 'FREE' ? 'Miễn phí' : number_format((float) $package['price'], 0, ',', '.') . ' <small class="fs-6 fw-normal">VNĐ</small>' ?></div><ul class="list-unstyled text-muted small mb-4"><li class="mb-2"><i class="fas fa-calendar text-primary me-2"></i><?= (int) $package['duration_month'] ?> tháng sử dụng</li><li class="mb-2"><i class="fas fa-users text-primary me-2"></i><?= $package['member_limit'] === null ? 'Không giới hạn thành viên' : 'Tối đa ' . number_format((int) $package['member_limit']) . ' thành viên' ?></li><li><i class="fas fa-wallet text-primary me-2"></i><?= $package['payment_type'] === 'FREE' ? 'Kích hoạt ngay' : 'Thanh toán và chờ Owner xác nhận' ?></li></ul><button class="btn btn-primary w-100 mt-auto" onclick='openRegistration(<?= htmlspecialchars(json_encode($package), ENT_QUOTES, "UTF-8") ?>)'>Đăng ký</button></div></article></div><?php endforeach; ?></div>
</main>

<div class="modal fade" id="registrationModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Tạo yêu cầu đăng ký</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="post"><input type="hidden" name="action" value="prepare_registration"><input type="hidden" name="package_id" id="registration_package_id"><div class="modal-body"><div class="mb-3"><label class="form-label">Gói dịch vụ</label><input class="form-control" id="registration_package_name" readonly></div><div class="mb-3"><label class="form-label">Tên gia tộc <span class="text-danger">*</span></label><input class="form-control" name="family_name" required></div><div class="mb-3"><label class="form-label">Mô tả</label><textarea class="form-control" name="description" rows="3"></textarea></div><div class="mb-2"><label class="form-label">Ngày đăng ký <span class="text-danger">*</span></label><input class="form-control" name="package_start_date" type="date" value="<?= date('Y-m-d') ?>" required></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Hủy</button><button class="btn btn-primary">Tạo yêu cầu đăng ký</button></div></form></div></div></div>

<?php if (isset($paymentPackage, $paymentForm)): ?><div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-header text-white" style="background:linear-gradient(135deg,#b52f7a,#7d1d55)"><h5 class="modal-title"><i class="fas fa-qrcode me-2"></i>Thanh toán gói dịch vụ</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><p class="text-muted mb-1"><?= htmlspecialchars($paymentPackage['package_name']) ?></p><div class="payment-amount mb-3"><?= number_format((float) $paymentPackage['price'], 0, ',', '.') ?> VNĐ</div><img src="../uploads/payment/momo-qr-source.png" class="payment-qr mb-3" alt="Mã QR nhận tiền MoMo"><div class="payment-account text-start mb-3"><div class="small text-muted">Chuyển khoản đến ví MoMo</div><strong><?= REGISTER_MOMO_PHONE ?></strong> · <?= REGISTER_MOMO_OWNER ?></div><p class="small text-muted mb-0">Sau khi gửi yêu cầu, tài khoản sẽ thành Admin nhưng chỉ có thể vào quản lý khi Owner xác nhận thanh toán.</p></div><form method="post"><input type="hidden" name="action" value="submit_paid_request"><input type="hidden" name="package_id" value="<?= (int) $paymentForm['packageId'] ?>"><input type="hidden" name="family_name" value="<?= htmlspecialchars($paymentForm['familyName']) ?>"><input type="hidden" name="description" value="<?= htmlspecialchars($paymentForm['description']) ?>"><input type="hidden" name="package_start_date" value="<?= htmlspecialchars($paymentForm['startDate']) ?>"><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Hủy</button><button class="btn btn-primary">Gửi yêu cầu đăng ký cho Owner</button></div></form></div></div></div><?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script><script>function openRegistration(pkg){document.getElementById('registration_package_id').value=pkg.package_id;document.getElementById('registration_package_name').value=`${pkg.package_name} · ${pkg.payment_type==='FREE'?'Miễn phí':Number(pkg.price).toLocaleString('vi-VN')+' VNĐ'}`;bootstrap.Modal.getOrCreateInstance(document.getElementById('registrationModal')).show()}</script><?php if (isset($paymentPackage)): ?><script>bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal')).show()</script><?php endif; ?></body></html>
