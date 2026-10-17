<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

const RENEWAL_MOMO_PHONE = '0867612409';
const RENEWAL_MOMO_OWNER = 'LÊ ANH TUẤN';

function renewalStartDate(?string $currentEndDate): string {
    $today = new DateTime('today');
    if ($currentEndDate) {
        $currentEnd = new DateTime($currentEndDate);
        if ($currentEnd >= $today) {
            return $currentEnd->format('Y-m-d');
        }
    }
    return $today->format('Y-m-d');
}

if (!isLoggedIn() || !isAdmin()) {
    redirect('../login.php', 'Bạn không có quyền truy cập!', 'error');
}
$managedFamilyId = $_SESSION['managed_family_id'] ?? null;

if (!$managedFamilyId) {
    redirect('../login.php', 'Bạn không có quyền truy cập!', 'error');
}
requireAdminFamilyAccess((int) $managedFamilyId, $pdo);
$family_id = (int) $managedFamilyId;

// Admin vừa đăng ký gói PAID chỉ được quản lý gia tộc sau khi Owner xác nhận.
$statusStmt = $pdo->prepare('SELECT package_status FROM families WHERE family_id = ?');
$statusStmt->execute([$family_id]);
if ($statusStmt->fetchColumn() === 'PENDING_PAYMENT') {
    redirect('pending_confirmation.php');
}

// Xử lý thêm/sửa/xóa gia tộc
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['edit', 'merge', 'delete', 'request_renewal'], true)) {
        $targetFamilyId = filter_var($_POST['family_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$targetFamilyId) {
            redirect('manage_family.php', 'Không tìm thấy gia tộc cần thao tác.', 'error');
        }
        requireAdminFamilyAccess((int) $targetFamilyId, $pdo);
    }

    if ($action == 'edit') {
        $family_id   = (int) $_POST['family_id'];
        $family_name = trim($_POST['family_name']);
        $description = trim($_POST['description']);

        // Tìm cụ tổ (generation = 1) của gia tộc này
        $stmtAnc = $pdo->prepare("
            SELECT TOP 1 full_name 
            FROM members 
            WHERE family_id = ? AND generation = 1 
            ORDER BY member_id ASC
        ");
        $stmtAnc->execute([$family_id]);
        $ancestor = $stmtAnc->fetch();

        $canUpdate = true;
        $warning   = null;

        if (!$ancestor) {
            // Không tìm thấy cụ tổ → chỉ cảnh báo, vẫn cho cập nhật
            $warning = 'Lưu ý: Gia tộc này hiện chưa có cụ tổ (thành viên đời 1).';
        } else {
            // Lấy họ của cụ tổ = từ đầu tiên trong full_name
            $ancestorSurname = strtolower(
                explode(' ', trim($ancestor['full_name']))[0]
            );

            // Lấy từ đầu tiên của family_name để so sánh
            // Bỏ qua các từ mô tả như "Dòng họ", "Họ", "Gia tộc" nếu có
            $stopWords   = ['dòng', 'họ', 'gia', 'tộc', 'chi', 'nhánh', 'đình', 'ông', 'bà'];
            $nameParts   = preg_split('/\s+/u', strtolower(trim($family_name)));
            $familySurname = null;
            foreach ($nameParts as $part) {
                if (!in_array($part, $stopWords)) {
                    $familySurname = $part;
                    break;
                }
            }

            if ($familySurname === null || $familySurname !== $ancestorSurname) {
                $canUpdate = false;
                $error = 'Họ gia tộc không trùng với họ của cụ tổ '
                       . '(<strong>' . htmlspecialchars($ancestor['full_name']) . '</strong>). '
                       . 'Vui lòng kiểm tra lại tên gia tộc.';
            }
        }

        if ($canUpdate) {
            try {
                $stmt = $pdo->prepare("UPDATE families SET family_name=?, description=? WHERE family_id=?");
                $stmt->execute([$family_name, $description, $family_id]);
                // Nếu có cảnh báo thì flash kèm cảnh báo, ngược lại thông báo thành công
                $msg = $warning
                    ? 'Cập nhật thành công! ⚠️ ' . $warning
                    : 'Cập nhật gia tộc thành công!';
                redirect('manage_family.php', $msg);
            } catch (PDOException $e) {
                $error = 'Lỗi: Không thể cập nhật gia tộc!';
            }
        }
    }

    if ($action === 'request_renewal') {
        $familyId = (int) $_POST['family_id'];
        try {
            $stmt = $pdo->prepare('
                SELECT f.family_id, f.package_id, f.parent_family_id, f.package_start_date, f.package_end_date,
                       sp.package_name, sp.price, sp.status, sp.payment_type
                FROM families f
                JOIN service_packages sp ON sp.package_id = f.package_id
                WHERE f.family_id = ?
            ');
            $stmt->execute([$familyId]);
            $renewal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$renewal) {
                throw new InvalidArgumentException('Gia tộc chưa có gói dịch vụ để gia hạn.');
            }
            if (!empty($renewal['parent_family_id'])) {
                throw new InvalidArgumentException('Chỉ gia tộc gốc mới có thể gia hạn. Gói dịch vụ sẽ tự áp dụng cho toàn bộ chi nhánh.');
            }
            if ($renewal['status'] !== 'ACTIVE') {
                throw new InvalidArgumentException('Gói dịch vụ này hiện không hoạt động.');
            }

            if ($renewal['payment_type'] === 'FREE') {
                $startDate = renewalStartDate($renewal['package_end_date']);
                [, $startDate, $endDate] = getPackageSubscription($pdo, (int) $renewal['package_id'], $startDate);
                propagateFamilySubscription($pdo, $familyId, (int) $renewal['package_id'], $startDate, $endDate, 'ACTIVE');
                redirect('manage_family.php', 'Đã gia hạn gói miễn phí cho toàn bộ cụm gia tộc thành công!');
            }

            // Admin chỉ tạo yêu cầu. Owner là người duy nhất có quyền xác nhận giao dịch.
            propagateFamilySubscription($pdo, $familyId, (int) $renewal['package_id'], $renewal['package_start_date'], $renewal['package_end_date'], 'PENDING_PAYMENT');
            redirect('manage_family.php', 'Đã tạo yêu cầu gia hạn. Vui lòng thanh toán; Owner sẽ kiểm tra và xác nhận giao dịch.');
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = 'Không thể gia hạn gói dịch vụ. Vui lòng thử lại.';
        }
    }

        if ($action == 'merge') {

        $family_id = (int)$_POST['family_id'];

        try {

            $pdo->beginTransaction();

            // Lấy thông tin gia tộc cần nhập
            $stmt = $pdo->prepare("
                SELECT *
                FROM families
                WHERE family_id = ?
            ");
            $stmt->execute([$family_id]);

            $family = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$family) {
                throw new Exception('Không tìm thấy gia tộc!');
            }

            // Chỉ cho phép nhập gia tộc chi
            if (empty($family['parent_family_id'])) {
                throw new Exception('Gia tộc gốc không thể nhập vào gia tộc khác!');
            }

            $parentFamilyId = (int)$family['parent_family_id'];

            // Kiểm tra còn chi nhánh con không
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM families
                WHERE parent_family_id = ?
            ");
            $stmt->execute([$family_id]);

            if ($stmt->fetchColumn() > 0) {
                throw new Exception(
                    'Không thể nhập nhánh này vì vẫn còn các chi nhánh con!'
                );
            }

            // Chuyển thành viên sang gia tộc cha
            $offset = ((int)$family['split_from_generation']) - 1;

            $stmt = $pdo->prepare("
                UPDATE members
                SET
                    generation = generation + ?,
                    family_id = ?
                WHERE family_id = ?
            ");

            $stmt->execute([
                $offset,
                $parentFamilyId,
                $family_id
            ]);

            // Chuyển con dâu sang gia tộc cha
            $stmt = $pdo->prepare("
                UPDATE daughters_in_law
                SET family_id = ?
                WHERE family_id = ?
            ");
            $stmt->execute([$parentFamilyId, $family_id]);

            // Gỡ tài khoản đang quản lý gia tộc này
            $stmt = $pdo->prepare("
                DELETE users
                FROM users
                WHERE managed_family_id = ?
            ");
            $stmt->execute([$family_id]);

            // Xóa gia tộc
            $stmt = $pdo->prepare("
                DELETE FROM families
                WHERE family_id = ?
            ");
            $stmt->execute([$family_id]);

            $pdo->commit();

            redirect(
                'manage_family.php',
                'Nhập nhánh về gia tộc gốc thành công!'
            );

        } catch (Exception $e) {

            $pdo->rollBack();

            $error = 'Lỗi: ' . $e->getMessage();
        }
    }

    if ($action == 'delete') {
        $family_id = (int) $_POST['family_id'];

        try {
            // B1: Lấy tất cả member_id và daughter_in_law_id thuộc gia tộc này
            $stmtM = $pdo->prepare("SELECT member_id FROM members WHERE family_id = ?");
            $stmtM->execute([$family_id]);
            $memberIds = $stmtM->fetchAll(PDO::FETCH_COLUMN);

            $stmtD = $pdo->prepare("SELECT daughter_in_law_id FROM daughters_in_law WHERE family_id = ?");
            $stmtD->execute([$family_id]);
            $daughterIds = $stmtD->fetchAll(PDO::FETCH_COLUMN);

            // Gỡ liên kết tách nhánh đang trỏ tới gia tộc/thành viên sắp bị xóa
            $pdo->prepare("
                UPDATE families
                SET parent_family_id = NULL,
                    split_from_member_id = NULL,
                    split_from_generation = NULL
                WHERE parent_family_id = ?
            ")->execute([$family_id]);

            // B2+B3: Xóa tất cả quan hệ trong relationships và marriages dựa vào id đã lấy
            if (!empty($memberIds)) {
                $inPlaceholder = implode(',', array_fill(0, count($memberIds), '?'));

                $pdo->prepare("
                    UPDATE families
                    SET split_from_member_id = NULL,
                        split_from_generation = NULL
                    WHERE split_from_member_id IN ($inPlaceholder)
                ")->execute($memberIds);

                // Xóa relationships (cha-con)
                $pdo->prepare("DELETE FROM relationships WHERE parent_id IN ($inPlaceholder) OR child_id IN ($inPlaceholder)")
                    ->execute(array_merge($memberIds, $memberIds));

                // Xóa marriages theo husband_id (chồng là thành viên gia tộc)
                $pdo->prepare("DELETE FROM marriages WHERE husband_id IN ($inPlaceholder)")
                    ->execute($memberIds);
            }

            if (!empty($daughterIds)) {
                $inPlaceholderD = implode(',', array_fill(0, count($daughterIds), '?'));

                // Xóa marriages theo wife_id (vợ là con dâu gia tộc)
                $pdo->prepare("DELETE FROM marriages WHERE wife_id IN ($inPlaceholderD)")
                    ->execute($daughterIds);
            }

            // B4: Xóa tất cả thành viên và con dâu thuộc gia tộc
            $pdo->prepare("DELETE FROM members WHERE family_id = ?")->execute([$family_id]);
            $pdo->prepare("DELETE FROM daughters_in_law WHERE family_id = ?")->execute([$family_id]);

            // B5: Xóa gia tộc
            $pdo->prepare("DELETE FROM families WHERE family_id = ?")->execute([$family_id]);

            redirect('manage_family.php', 'Xóa gia tộc và toàn bộ dữ liệu liên quan thành công!');
        } catch (PDOException $e) {
            $error = 'Lỗi: Không thể xóa gia tộc! ' . $e->getMessage();
        }
    }
}

// Lấy danh sách gia tộc kèm số thành viên
$stmt = $pdo->prepare("
    WITH FamilyTree AS (
        -- ⚠️ ĐIỂM SỬA QUAN TRỌNG: chỉ bắt đầu từ ĐÚNG gia tộc admin này được quản lý
        -- (trước đây là 'WHERE parent_family_id IS NULL' → lấy MỌI gia tộc gốc của toàn hệ thống)
        SELECT
            f.*,
            CAST(
                RIGHT('000000' + CAST(f.family_id AS VARCHAR(6)), 6)
                AS VARCHAR(MAX)
            ) AS path,
            0 AS level
        FROM families f
        WHERE f.family_id = ?

        UNION ALL

        -- Các chi nhánh con/cháu/chắt của riêng gia tộc đó
        SELECT
            f.*,
            CAST(
                ft.path + '-' +
                RIGHT('000000' + CAST(f.family_id AS VARCHAR(6)), 6)
                AS VARCHAR(MAX)
            ) AS path,
            ft.level + 1
        FROM families f
        INNER JOIN FamilyTree ft
            ON f.parent_family_id = ft.family_id
    ),
    FamilyDescendants AS (
        SELECT
            ft.family_id  AS root_family_id,
            ft2.family_id AS descendant_family_id
        FROM FamilyTree ft
        INNER JOIN FamilyTree ft2
            ON ft2.path = ft.path
            OR ft2.path LIKE ft.path + '-%'
    )

    SELECT
        ft.*,
        u.full_name AS creator_name,
        parent_f.family_name AS parent_family_name,
        split_m.full_name AS split_member_name,
        sp.package_name,
        sp.member_limit,
        sp.price AS package_price,
        sp.payment_type,
        CASE
            WHEN ft.package_id IS NOT NULL
             AND ft.package_start_date <= CAST(GETDATE() AS date)
             AND ft.package_end_date >= CAST(GETDATE() AS date)
            THEN 'ACTIVE'
            ELSE 'INACTIVE'
        END AS effective_package_status,

        -- ⚠️ ĐIỂM SỬA THỨ 2: dùng 'level = 0' (= chính gia tộc được quản lý, đầu danh sách)
        -- thay vì 'parent_family_id IS NULL', vì Huỳnh Kim có thể CHÍNH NÓ cũng là
        -- một chi đã tách ra từ gia tộc khác (parent_family_id khác NULL),
        -- nhưng vẫn cần được cộng dồn số liệu của các chi/cháu/chắt thuộc phạm vi quản lý này
        CASE
            WHEN ft.level = 0 THEN (
                SELECT COUNT(*)
                FROM members m
                INNER JOIN FamilyDescendants fd ON m.family_id = fd.descendant_family_id
                WHERE fd.root_family_id = ft.family_id
            )
            ELSE (
                SELECT COUNT(*)
                FROM members m
                WHERE m.family_id = ft.family_id
            )
        END AS total_members,

        CASE
            WHEN ft.level = 0 THEN (
                SELECT COUNT(*)
                FROM daughters_in_law d
                INNER JOIN FamilyDescendants fd ON d.family_id = fd.descendant_family_id
                WHERE fd.root_family_id = ft.family_id
            )
            ELSE (
                SELECT COUNT(*)
                FROM daughters_in_law d
                WHERE d.family_id = ft.family_id
            )
        END AS total_daughters

    FROM FamilyTree ft
    LEFT JOIN users u
        ON ft.created_by = u.user_id
    LEFT JOIN families parent_f
        ON parent_f.family_id = ft.parent_family_id
    LEFT JOIN members split_m
        ON split_m.member_id = ft.split_from_member_id
    LEFT JOIN service_packages sp
        ON sp.package_id = ft.package_id
    ORDER BY ft.path
    OPTION (MAXRECURSION 32767);
");

$stmt->execute([$managedFamilyId]);
$families = $stmt->fetchAll();

// Dùng chung cách dựng cây với màn hình Owner, nhưng $families ở đây đã bị
// giới hạn từ gia tộc mà Admin được phân quyền quản lý.
$familyChildren = [];
foreach ($families as $family) {
    $parentKey = $family['parent_family_id'] === null ? 'root' : (string) $family['parent_family_id'];
    $familyChildren[$parentKey][] = $family;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý gia tộc - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .sidebar {
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin: 0.25rem 0;
            transition: all 0.3s ease;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background-color: rgba(255,255,255,0.2);
            color: white;
            transform: translateX(5px);
        }

        .main-content {
            padding: 2rem;
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
        }

        .badge {
            font-size: 0.75rem;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        .btn-enter {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            border-radius: 8px;
            padding: 0.3rem 0.7rem;
            font-size: 0.75rem;
            transition: opacity 0.2s;
        }

        .btn-enter:hover {
            opacity: 0.85;
            color: white;
        }

        .family-description {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #6c757d;
            font-size: 0.875rem;
        }

        .family-tree-row { transition: background-color .18s ease; }
        .family-tree-row[data-level]:not([data-level="0"]) td:first-child { border-left: 3px solid #dbe4f0; }
        .tree-toggle { width: 1.7rem; height: 1.7rem; padding: 0; border: 0; background: #eef4fb; color: #315579; border-radius: 50%; }
        .tree-toggle:hover { background: #dceaf8; }
        .tree-toggle i { transition: transform .18s ease; }
        .tree-toggle[aria-expanded="true"] i { transform: rotate(90deg); }
        .tree-spacer { display: inline-block; width: 1.7rem; }
        .tree-level { display: inline-block; min-width: 3.3rem; font-size: .69rem; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }

        .btn-renew { background: #f59e0b; border-color: #f59e0b; color: #1f2937; font-weight: 600; }
        .btn-renew:hover { background: #d97706; border-color: #d97706; color: #fff; }
        .expiring-soon > * { background-color: #fff3cd !important; }
        .expired-service > * { background-color: #f8d7da !important; }
        .subscription-note { display: block; font-size: .74rem; margin-top: .25rem; }
        .payment-qr { width: min(100%, 270px); border-radius: 14px; border: 1px solid #f1d2e2; box-shadow: 0 10px 24px rgba(181,47,122,.16); }
        .payment-amount { font-size: 2rem; font-weight: 700; color: #b52f7a; }
        .payment-account { background: #fff0f7; border-radius: 12px; padding: 1rem; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <h4 class="text-white">
                            <i class="fas fa-sitemap me-2"></i>Admin Panel
                        </h4>
                        <small class="text-white-50">Xin chào, <?= htmlspecialchars($_SESSION['full_name']) ?></small>
                    </div>

                    <ul class="nav flex-column">    
                        <li class="nav-item">
                            <a class="nav-link active" href="manage_family.php">
                                <i class="fas fa-home me-2"></i>Quản lý gia tộc
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="update_service_packages.php">
                                <i class="fas fa-arrow-up-right-dots me-2"></i>Nâng cấp gói dịch vụ
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

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content">
                <?= showMessage() ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= $error ?></div>
                <?php endif; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
                    <h1 class="h2">Quản lý gia tộc</h1>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Tên gia tộc</th>
                                        <th>Mô tả</th>
                                        <th>Thành viên</th>
                                        <th>Con dâu</th>
                                        <th>Gói dịch vụ</th>
                                        <th>Đăng ký</th>
                                        <th>Hết hạn</th>
                                        <th>Trạng thái</th>
                                        <th>Giới hạn TV</th>
                                        <th>Tách ra từ gia tộc</th>
                                        <th>Thành viên tách</th>
                                        <th>Tách từ đời</th>
                                        <th>Người tạo</th>
                                        <th>Ngày tạo</th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($families as $family): ?>
                                    <?php
                                        $endDate = $family['package_end_date'] ?? null;
                                        $today = date('Y-m-d');
                                        $isExpired = $endDate && $endDate < $today;
                                        $isExpiring = $endDate && $endDate >= $today && $endDate <= date('Y-m-d', strtotime('+3 days'));
                                        $isPendingPayment = ($family['package_status'] ?? '') === 'PENDING_PAYMENT';
                                        $rowClass = $isExpired ? 'expired-service' : ($isExpiring ? 'expiring-soon' : '');
                                        $canRequestRenewal = !empty($family['package_id'])
                                            && $endDate
                                            && empty($family['parent_family_id'])
                                            && !$isPendingPayment
                                            && ($endDate < $today || $endDate <= date('Y-m-d', strtotime('+3 days')));
                                        $hasChildren = !empty($familyChildren[(string) $family['family_id']]);
                                    ?>
                                    <tr class="family-tree-row <?= $rowClass ?>" data-family-id="<?= (int) $family['family_id'] ?>" data-parent-id="<?= $family['parent_family_id'] === null ? '' : (int) $family['parent_family_id'] ?>" data-level="<?= (int) $family['level'] ?>" data-expanded="false">
                                        <td><?= $family['family_id'] ?></td>
                                        <td>
                                            <span style="display:inline-block;width:<?= (int) $family['level'] * 1.4 ?>rem"></span>
                                            <?php if ($hasChildren): ?>
                                            <button type="button" class="tree-toggle me-1" aria-expanded="false" onclick="toggleFamilyBranch(<?= (int) $family['family_id'] ?>, this)" title="Mở/đóng chi nhánh"><i class="fas fa-chevron-right"></i></button>
                                            <?php else: ?><span class="tree-spacer me-1"></span><?php endif; ?>
                                            <strong><?= htmlspecialchars($family['family_name']) ?></strong>
                                            <span class="tree-level ms-1">Cấp <?= (int) $family['level'] + 1 ?></span>
                                        </td>
                                        <td>
                                            <span class="family-description" title="<?= htmlspecialchars($family['description'] ?? '') ?>">
                                                <?= htmlspecialchars($family['description'] ?? '—') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?= $family['total_members'] ?> người</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= $family['total_daughters'] ?> người</span>
                                        </td>
                                        <td>
                                            <?php if ($family['package_name']): ?>
                                                <strong><?= htmlspecialchars($family['package_name']) ?></strong>
                                            <?php else: ?>
                                                <span class="text-muted">Chưa đăng ký</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $family['package_start_date'] ? date('d/m/Y', strtotime($family['package_start_date'])) : '—' ?></td>
                                        <td>
                                            <?= $endDate ? date('d/m/Y', strtotime($endDate)) : '—' ?>
                                            <?php if ($endDate && $rowClass === 'expiring-soon'): ?><span class="subscription-note text-warning-emphasis"><i class="fas fa-clock me-1"></i>Sắp hết hạn</span><?php endif; ?>
                                            <?php if ($endDate && $rowClass === 'expired-service'): ?><span class="subscription-note text-danger"><i class="fas fa-circle-xmark me-1"></i>Đã hết hạn</span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!$family['package_id']): ?>
                                                <span class="badge bg-secondary">Chưa kích hoạt</span>
                                            <?php elseif ($isPendingPayment): ?>
                                                <span class="badge text-bg-warning">Chờ xác nhận</span>
                                            <?php elseif ($family['effective_package_status'] === 'ACTIVE'): ?>
                                                <span class="badge bg-success">Hoạt động</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Không hoạt động</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $family['member_limit'] === null ? 'Không giới hạn' : number_format((int) $family['member_limit']) . ' người' ?></td>
                                        <td>
                                            <?php if (!empty($family['parent_family_name'])): ?>
                                                <?= htmlspecialchars($family['parent_family_name']) ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Gia tộc gốc</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($family['split_member_name'] ?? '—') ?></td>
                                        <td>
                                            <?= $family['split_from_generation'] ? 'Đời ' . (int) $family['split_from_generation'] : '—' ?>
                                        </td>
                                        <td><?= htmlspecialchars($family['creator_name'] ?? '—') ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($family['created_at'])) ?></td>
                                        <td class="d-flex gap-1 flex-wrap">
                                            <!-- Vào quản lý gia tộc -->
                                            <?php if (!$isExpired): ?>
                                            <a href="dashboard.php?family_id=<?= $family['family_id'] ?>"
                                               class="btn btn-enter btn-sm"
                                               title="Vào quản lý gia tộc này">
                                                <i class="fas fa-sign-in-alt me-1"></i>Vào quản lý
                                            </a>
                                            <?php endif; ?>
                                            <?php if (empty($family['parent_family_id'])): ?>
                                            <a href="update_service_packages.php?family_id=<?= (int) $family['family_id'] ?>"
                                               class="btn btn-sm btn-outline-primary" title="Cập nhật gói dịch vụ">
                                                <i class="fas fa-box-open me-1"></i>Gói dịch vụ
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($canRequestRenewal): ?>
                                            <button type="button" class="btn btn-renew btn-sm"
                                                    onclick='renewPackage(<?= htmlspecialchars(json_encode($family), ENT_QUOTES, "UTF-8") ?>)'
                                                    title="Gia hạn gói dịch vụ">
                                                <i class="fas fa-rotate me-1"></i>Gia hạn
                                            </button>
                                            <?php endif; ?>
                                            <!-- Sửa -->
                                            <button class="btn btn-sm btn-outline-primary"
                                                    onclick="editFamily(<?= htmlspecialchars(json_encode($family)) ?>)"
                                                    title="Sửa">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                             <!-- Nhập nhánh về gia tộc gốc -->
                                            <?php if (!empty($family['parent_family_id'])): ?>
                                            <button class="btn btn-sm btn-outline-success"
                                                    onclick="mergeFamily(
                                                        <?= $family['family_id'] ?>,
                                                        '<?= htmlspecialchars($family['family_name'], ENT_QUOTES) ?>'
                                                    )"
                                                    title="Nhập nhánh về gia tộc gốc">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($families)): ?>
                                    <tr>
                                        <td colspan="16" class="text-center text-muted py-4">
                                            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                            Chưa có gia tộc nào. Hãy thêm gia tộc đầu tiên!
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <form method="POST" id="freeRenewalForm" class="d-none">
        <input type="hidden" name="action" value="request_renewal">
        <input type="hidden" name="family_id" id="free_renewal_family_id">
    </form>

    <!-- Admin chỉ được tạo yêu cầu gia hạn gói trả phí; Owner sẽ xác nhận. -->
    <div class="modal fade" id="renewalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #b52f7a, #7d1d55);">
                <h5 class="modal-title"><i class="fas fa-qrcode me-2"></i>Gia hạn dịch vụ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="mb-1 text-muted" id="renewal_family_name"></p>
                <div class="payment-amount mb-3" id="renewal_amount"></div>
                <img src="../uploads/payment/momo-qr-source.png" class="payment-qr mb-3" alt="Mã QR nhận tiền MoMo">
                <div class="payment-account text-start mb-3">
                    <div class="small text-muted">Chuyển khoản đến ví MoMo</div>
                    <strong><?= RENEWAL_MOMO_PHONE ?></strong> · <?= RENEWAL_MOMO_OWNER ?><br>
                    <span class="small">Nội dung: <strong id="renewal_content"></strong></span>
                </div>
                <p class="small text-muted mb-0">Sau khi thanh toán, Owner sẽ kiểm tra giao dịch và xác nhận gia hạn. Admin không thể tự xác nhận.</p>
            </div>
            <form method="POST"><input type="hidden" name="action" value="request_renewal"><input type="hidden" name="family_id" id="renewal_family_id">
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Hủy</button><button class="btn btn-renew"><i class="fas fa-paper-plane me-1"></i>Tạo yêu cầu gia hạn</button></div>
            </form>
        </div></div>
    </div>

    <!-- Modal sửa gia tộc -->
    <div class="modal fade" id="editFamilyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Sửa thông tin gia tộc</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="family_id" id="edit_family_id">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Tên gia tộc <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="family_name" id="edit_family_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Mô tả</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="4"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Cập nhật</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editFamily(family) {
            document.getElementById('edit_family_id').value   = family.family_id;
            document.getElementById('edit_family_name').value = family.family_name;
            document.getElementById('edit_description').value = family.description ?? '';
            new bootstrap.Modal(document.getElementById('editFamilyModal')).show();
        }

        function renewPackage(family) {
            if (family.payment_type === 'FREE') {
                if (!confirm(`Gia hạn gói miễn phí cho gia tộc "${family.family_name}" ngay bây giờ?`)) return;
                document.getElementById('free_renewal_family_id').value = family.family_id;
                document.getElementById('freeRenewalForm').submit();
                return;
            }

            document.getElementById('renewal_family_id').value = family.family_id;
            document.getElementById('renewal_family_name').textContent = `Gia tộc ${family.family_name} · ${family.package_name}`;
            document.getElementById('renewal_amount').textContent = `${Number(family.package_price).toLocaleString('vi-VN')} VNĐ`;
            document.getElementById('renewal_content').textContent = `GH-${family.family_id}`;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('renewalModal')).show();
        }

        function updateFamilyTreeVisibility() {
            const rows = [...document.querySelectorAll('.family-tree-row')];
            const rowsById = Object.fromEntries(rows.map(row => [row.dataset.familyId, row]));
            rows.forEach(row => {
                let parentId = row.dataset.parentId;
                let visible = true;
                while (parentId) {
                    const parent = rowsById[parentId];
                    if (!parent || parent.dataset.expanded !== 'true') {
                        visible = false;
                        break;
                    }
                    parentId = parent.dataset.parentId;
                }
                row.hidden = !visible;
            });
        }

        function toggleFamilyBranch(familyId, button) {
            const row = document.querySelector(`.family-tree-row[data-family-id="${familyId}"]`);
            const expanded = row.dataset.expanded === 'true';
            row.dataset.expanded = String(!expanded);
            button.setAttribute('aria-expanded', String(!expanded));
            updateFamilyTreeVisibility();
        }

        updateFamilyTreeVisibility();

        function deleteFamily(familyId, familyName) {
            if (confirm(`Bạn có chắc muốn xóa gia tộc "${familyName}"?\n⚠️ Cảnh báo: Thao tác này sẽ xóa TOÀN BỘ thành viên, con dâu và các quan hệ thuộc gia tộc này!\nKhông thể hoàn tác!`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action"    value="delete">
                    <input type="hidden" name="family_id" value="${familyId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function mergeFamily(familyId, familyName) {

        if (!confirm(
            `Bạn có chắc muốn nhập gia tộc "${familyName}" về gia tộc gốc?\n\n` +
            `Toàn bộ thành viên và con dâu sẽ được chuyển sang gia tộc cha.\n` +
            `Gia tộc này sẽ bị xóa.\n\n` +
            `Thao tác này không thể hoàn tác!`
        )) {
            return;
        }

        const form = document.createElement('form');

        form.method = 'POST';

        form.innerHTML = `
            <input type="hidden" name="action" value="merge">
            <input type="hidden" name="family_id" value="${familyId}">
        `;

        document.body.appendChild(form);

        form.submit();
        }
    </script>
</body>
</html>
