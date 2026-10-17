<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

const UPGRADE_MOMO_PHONE = '0867612409';
const UPGRADE_MOMO_OWNER = 'LÊ ANH TUẤN';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php', 'Bạn không có quyền truy cập!', 'error');

$familyId = filter_var($_POST['family_id'] ?? $_GET['family_id'] ?? $_SESSION['managed_family_id'] ?? null, FILTER_VALIDATE_INT);
if (!$familyId) redirect('manage_family.php', 'Không tìm thấy gia tộc được phân quyền.', 'error');
requireAdminFamilyAccess((int) $familyId, $pdo);

$familyStmt = $pdo->prepare('
    SELECT f.family_id, f.family_name, f.parent_family_id, f.package_id, f.package_status,
           f.package_start_date, f.package_end_date, sp.package_name, sp.payment_type
    FROM families f LEFT JOIN service_packages sp ON sp.package_id = f.package_id
    WHERE f.family_id = ?
');
$familyStmt->execute([$familyId]);
$family = $familyStmt->fetch(PDO::FETCH_ASSOC);
if (!$family) redirect('manage_family.php', 'Gia tộc không tồn tại.', 'error');
if ($family['parent_family_id'] !== null) redirect('manage_family.php', 'Chỉ gia tộc gốc mới có thể nâng cấp gói dịch vụ.', 'error');
if ($family['package_status'] === 'PENDING_PAYMENT') {
    redirect('pending_confirmation.php', 'Yêu cầu thanh toán của gia tộc đang chờ Owner xác nhận.', 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $packageId = filter_var($_POST['package_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    try {
        if (!$packageId) throw new InvalidArgumentException('Vui lòng chọn gói dịch vụ.');
        if ((int) $packageId === (int) $family['package_id']) throw new InvalidArgumentException('Gia tộc đang sử dụng gói dịch vụ này.');

        if ($action === 'start_registration') {
            // Kiểm tra hạn mức ngay khi Admin bấm "Đăng ký", trước khi hiển thị QR.
            [$selectedPackage] = getPackageSubscription($pdo, $packageId, date('Y-m-d'));
            ensureFamilyFitsPackageMemberLimit($pdo, (int) $family['family_id'], $selectedPackage);

            if ($selectedPackage['payment_type'] === 'PAID') {
                $paymentPackage = $selectedPackage;
            } else {
                $pdo->beginTransaction();
                update_service_packages($pdo, (int) $family['family_id'], $packageId, date('Y-m-d'));
                $pdo->commit();
                redirect('manage_family.php', 'Đăng ký gói miễn phí thành công. Gói đã được kích hoạt cho toàn bộ cụm gia tộc.');
            }
        } elseif ($action === 'create_payment_request') {
            $pdo->beginTransaction();
            // Kiểm tra lại tại server khi ghi dữ liệu để không thể bỏ qua bằng POST thủ công.
            update_service_packages($pdo, (int) $family['family_id'], $packageId, date('Y-m-d'));
            $pdo->commit();
            redirect('manage_family.php', 'Đã tạo yêu cầu đăng ký gói trả phí. Owner sẽ kiểm tra và xác nhận thanh toán.');
        } else {
            throw new InvalidArgumentException('Thao tác đăng ký không hợp lệ.');
        }
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Không thể đăng ký gói dịch vụ. Vui lòng thử lại.';
    }
}

$countStmt = $pdo->prepare('
    WITH FamilyBranches AS (
        SELECT family_id FROM families WHERE family_id = ?
        UNION ALL SELECT f.family_id FROM families f INNER JOIN FamilyBranches fb ON f.parent_family_id = fb.family_id
    )
    SELECT COUNT(m.member_id) FROM FamilyBranches fb LEFT JOIN members m ON m.family_id = fb.family_id
    OPTION (MAXRECURSION 32767)
');
$countStmt->execute([(int) $family['family_id']]);
$totalMembers = (int) $countStmt->fetchColumn();
$packages = $pdo->query("SELECT package_id, package_name, price, duration_month, member_limit, payment_type FROM service_packages WHERE status = 'ACTIVE' ORDER BY price, package_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="vi"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Nâng cấp gói dịch vụ</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f6f8fc}.package-card{border:2px solid transparent;height:100%;transition:.2s ease}.package-card:hover{transform:translateY(-4px);box-shadow:0 .75rem 1.5rem rgba(24,39,75,.12)}.package-current{border-color:#198754;background:#f1fff7}.package-price{color:#4f46e5;font-size:1.8rem;font-weight:700}.current-ribbon{color:#fff;background:#198754;border-radius:999px;font-size:.8rem;padding:.35rem .65rem}.payment-qr{width:min(100%,270px);border-radius:14px;border:1px solid #f1d2e2}.payment-amount{color:#b52f7a;font-size:2rem;font-weight:700}.payment-account{background:#fff0f7;border-radius:12px;padding:1rem}
</style></head><body><main class="container py-5">
<a class="btn btn-outline-secondary btn-sm mb-3" href="manage_family.php">← Quản lý gia tộc</a>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><h1 class="h2 mb-1">Nâng cấp gói dịch vụ</h1><p class="text-muted mb-0">Gia tộc: <strong><?= htmlspecialchars($family['family_name']) ?></strong> · Hiện có <?= number_format($totalMembers) ?> thành viên.</p></div><?php if ($family['package_status'] === 'PENDING_PAYMENT'): ?><span class="badge text-bg-warning p-2">Đang chờ Owner xác nhận thanh toán</span><?php endif; ?></div>
<?php if (isset($error)): ?><div class="alert alert-danger"><i class="fas fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="alert alert-info">Gói miễn phí kích hoạt ngay. Với gói trả phí, Admin chỉ tạo yêu cầu; Owner sẽ xác nhận sau khi kiểm tra thanh toán.</div>
<div class="row g-4"><?php foreach ($packages as $package): $isCurrent = (int) $package['package_id'] === (int) $family['package_id']; ?>
<div class="col-md-6 col-xl-4"><article class="card package-card <?= $isCurrent ? 'package-current' : '' ?> shadow-sm"><div class="card-body d-flex flex-column p-4"><div class="d-flex justify-content-between gap-2 align-items-start mb-3"><h2 class="h5 mb-0"><?= htmlspecialchars($package['package_name']) ?></h2><?php if ($isCurrent): ?><span class="current-ribbon"><i class="fas fa-check me-1"></i>Đang dùng</span><?php endif; ?></div>
<div class="package-price mb-3"><?= $package['payment_type'] === 'FREE' ? 'Miễn phí' : number_format((float) $package['price'], 0, ',', '.') . ' <small class="fs-6 fw-normal">VNĐ</small>' ?></div><ul class="list-unstyled text-muted small mb-4"><li class="mb-2"><i class="fas fa-calendar text-primary me-2"></i><?= (int) $package['duration_month'] ?> tháng sử dụng</li><li class="mb-2"><i class="fas fa-users text-primary me-2"></i><?= $package['member_limit'] === null ? 'Không giới hạn thành viên' : 'Tối đa ' . number_format((int) $package['member_limit']) . ' thành viên' ?></li><li><i class="fas fa-wallet text-primary me-2"></i><?= $package['payment_type'] === 'FREE' ? 'Kích hoạt ngay' : 'Chờ Owner xác nhận thanh toán' ?></li></ul><div class="mt-auto">
<?php if ($isCurrent): ?><button class="btn btn-success w-100" disabled><i class="fas fa-check me-1"></i>Đang dùng</button>
<?php elseif ($family['package_status'] === 'PENDING_PAYMENT'): ?><button class="btn btn-secondary w-100" disabled>Đang chờ xác nhận</button>
<?php else: ?><form method="post"><input type="hidden" name="action" value="start_registration"><input type="hidden" name="family_id" value="<?= (int) $family['family_id'] ?>"><input type="hidden" name="package_id" value="<?= (int) $package['package_id'] ?>"><button class="btn btn-primary w-100" <?= $package['payment_type'] === 'FREE' ? "onclick=\"return confirm('Đăng ký gói miễn phí này ngay?')\"" : '' ?>>Đăng ký</button></form><?php endif; ?>
</div></div></article></div><?php endforeach; ?></div></main>
<?php if (isset($paymentPackage)): ?><div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-header text-white" style="background:linear-gradient(135deg,#b52f7a,#7d1d55)"><h5 class="modal-title"><i class="fas fa-qrcode me-2"></i>Đăng ký gói trả phí</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><p class="text-muted mb-1"><?= htmlspecialchars($paymentPackage['package_name']) ?></p><div class="payment-amount mb-3"><?= number_format((float) $paymentPackage['price'], 0, ',', '.') ?> VNĐ</div><img src="../uploads/payment/momo-qr-source.png" class="payment-qr mb-3" alt="Mã QR nhận tiền MoMo"><div class="payment-account text-start mb-3"><div class="small text-muted">Chuyển khoản đến ví MoMo</div><strong><?= UPGRADE_MOMO_PHONE ?></strong> · <?= UPGRADE_MOMO_OWNER ?><br><span class="small">Nội dung: <strong>DK-<?= (int) $family['family_id'] ?></strong></span></div><p class="small text-muted mb-0">Owner sẽ kiểm tra giao dịch và xác nhận; Admin không thể tự xác nhận.</p></div><form method="post"><input type="hidden" name="action" value="create_payment_request"><input type="hidden" name="family_id" value="<?= (int) $family['family_id'] ?>"><input type="hidden" name="package_id" value="<?= (int) $paymentPackage['package_id'] ?>"><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Hủy</button><button class="btn btn-primary">Tạo yêu cầu đăng ký</button></div></form></div></div></div><?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script><?php if (isset($paymentPackage)): ?><script>bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal')).show();</script><?php endif; ?></body></html>
