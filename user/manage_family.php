<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Xử lý thêm/sửa/xóa gia tộc
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'add') {
        $family_name = trim($_POST['family_name']);
        $description = trim($_POST['description']);
        $created_by  = $_SESSION['user_id'];

        try {
            $stmt = $pdo->prepare("INSERT INTO families (family_name, description, created_by) VALUES (?, ?, ?)");
            $stmt->execute([$family_name, $description, $created_by]);
            redirect('manage_family.php', 'Thêm gia tộc thành công!');
        } catch (PDOException $e) {
            $error = 'Lỗi: Không thể thêm gia tộc!';
        }
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
            $stopWords   = ['dòng', 'họ', 'gia', 'tộc', 'chi'];
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

    if ($action == 'delete') {
        $family_id = (int) $_POST['family_id'];

        // Kiểm tra còn thành viên không
        $stmtM = $pdo->prepare("SELECT COUNT(*) FROM members WHERE family_id = ?");
        $stmtM->execute([$family_id]);
        $totalMembers = (int) $stmtM->fetchColumn();

        // Kiểm tra còn con dâu không
        $stmtD = $pdo->prepare("SELECT COUNT(*) FROM daughters_in_law WHERE family_id = ?");
        $stmtD->execute([$family_id]);
        $totalDaughters = (int) $stmtD->fetchColumn();

        if ($totalMembers > 0 || $totalDaughters > 0) {
            $parts = [];
            if ($totalMembers > 0)   $parts[] = "<strong>{$totalMembers} thành viên</strong>";
            if ($totalDaughters > 0) $parts[] = "<strong>{$totalDaughters} con dâu</strong>";
            $error = 'Không thể xóa gia tộc này! Hiện còn ' . implode(' và ', $parts)
                   . ' đang liên kết. Vui lòng xóa hết thành viên và con dâu của gia tộc trước.';
        } else {
            try {
                // Gỡ liên kết tách nhánh đang trỏ tới gia tộc sắp bị xóa
                $pdo->prepare("
                    UPDATE families
                    SET parent_family_id = NULL,
                        split_from_member_id = NULL,
                        split_from_generation = NULL
                    WHERE parent_family_id = ?
                ")->execute([$family_id]);

                $stmt = $pdo->prepare("DELETE FROM families WHERE family_id = ?");
                $stmt->execute([$family_id]);
                redirect('manage_family.php', 'Xóa gia tộc thành công!');
            } catch (PDOException $e) {
                $error = 'Lỗi: Không thể xóa gia tộc!';
            }
        }
    }
}

// Lấy danh sách gia tộc kèm số thành viên
$stmt = $pdo->query("
    WITH FamilyTree AS (
        -- Gia tộc gốc
        SELECT
            f.*,
            CAST(
                RIGHT('000000' + CAST(f.family_id AS VARCHAR(6)), 6)
                AS VARCHAR(MAX)
            ) AS path,
            0 AS level
        FROM families f
        WHERE f.parent_family_id IS NULL

        UNION ALL

        -- Các chi nhánh
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
    )

    SELECT
        ft.*,
        u.full_name AS creator_name,
        parent_f.family_name AS parent_family_name,
        split_m.full_name AS split_member_name,
        sp.package_name,
        sp.member_limit,
        CASE
            WHEN ft.package_id IS NULL THEN 'NOT_REGISTERED'
            WHEN ft.package_status = 'PENDING_PAYMENT' THEN 'PENDING_PAYMENT'
            WHEN ft.package_start_date <= CAST(GETDATE() AS date)
             AND ft.package_end_date >= CAST(GETDATE() AS date)
             AND ft.package_status = 'ACTIVE' THEN 'ACTIVE'
            ELSE 'INACTIVE'
        END AS effective_package_status,

        -- Gia tộc gốc: cộng dồn chính nó + toàn bộ chi/cháu/chắt (đệ quy)
        -- Chi nhánh đã tách: chỉ tính riêng số thành viên thuộc chính nó
        CASE
            WHEN ft.parent_family_id IS NULL THEN (
                SELECT COUNT(*)
                FROM members m
                INNER JOIN FamilyTree ft2 ON m.family_id = ft2.family_id
                WHERE ft2.path = ft.path
                OR ft2.path LIKE ft.path + '-%'
            )
            ELSE (
                SELECT COUNT(*)
                FROM members m
                WHERE m.family_id = ft.family_id
            )
        END AS total_members,

        CASE
            WHEN ft.parent_family_id IS NULL THEN (
                SELECT COUNT(*)
                FROM daughters_in_law d
                INNER JOIN FamilyTree ft2 ON d.family_id = ft2.family_id
                WHERE ft2.path = ft.path
                OR ft2.path LIKE ft.path + '-%'
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
$families = $stmt->fetchAll();
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
                            <i class="fas fa-sitemap me-2"></i>User Dashboard
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
                            <a class="nav-link" href="register_service_packages.php">
                                <i class="fas fa-box-open me-2"></i>Đăng ký gói dịch vụ
                            </a>
                        </li>
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
                                        <th>Trạng thái</th>
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
                                    <?php $hasChildren = !empty($familyChildren[(string) $family['family_id']]); ?>
                                    <tr class="family-tree-row" data-family-id="<?= (int) $family['family_id'] ?>" data-parent-id="<?= $family['parent_family_id'] === null ? '' : (int) $family['parent_family_id'] ?>" data-level="<?= (int) $family['level'] ?>" data-expanded="false">
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
                                        <td>
                                            <?php if ($family['effective_package_status'] === 'ACTIVE'): ?>
                                                <span class="badge bg-success">Hoạt động</span>
                                            <?php elseif ($family['effective_package_status'] === 'PENDING_PAYMENT'): ?>
                                                <span class="badge text-bg-warning">Chờ xác nhận</span>
                                            <?php elseif ($family['effective_package_status'] === 'NOT_REGISTERED'): ?>
                                                <span class="badge bg-secondary">Chưa kích hoạt</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Không hoạt động</span>
                                            <?php endif; ?>
                                        </td>
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
                                            <a href="dashboard.php?family_id=<?= $family['family_id'] ?>"
                                               class="btn btn-enter btn-sm"
                                               title="Vào quản lý gia tộc này">
                                                <i class="fas fa-sign-in-alt me-1"></i>Vào quản lý
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($families)): ?>
                                    <tr><td colspan="13" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>Chưa có gia tộc nào.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateFamilyTreeVisibility() {
            const rows = [...document.querySelectorAll('.family-tree-row')];
            const rowsById = Object.fromEntries(rows.map(row => [row.dataset.familyId, row]));
            rows.forEach(row => {
                let parentId = row.dataset.parentId;
                let visible = true;
                while (parentId) {
                    const parent = rowsById[parentId];
                    if (!parent || parent.dataset.expanded !== 'true') { visible = false; break; }
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
    </script>
</body>
</html>
