<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../login.php', 'Bạn không có quyền truy cập!', 'error');
}

// Lấy family_id từ session
$family_id = $_SESSION['current_family_id'] ?? null;
if (!$family_id) {
    redirect('manage_family.php', 'Vui lòng chọn gia tộc trước!', 'error');
}

// Lấy tên gia tộc
$stmtF = $pdo->prepare("SELECT family_name FROM families WHERE family_id = ?");
$stmtF->execute([$family_id]);
$family = $stmtF->fetch();
if (!$family) {
    redirect('manage_family.php', 'Gia tộc không tồn tại!', 'error');
}
requireActiveFamilySubscription((int) $family_id, $pdo);

// ── XỬ LÝ POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    // ── THÊM HÔN NHÂN ────────────────────────────────────────
    if ($action == 'add_marriage') {
        $husband_id = (int) $_POST['husband_id'];
        $wife_id    = (int) $_POST['wife_id'];

        if (empty($husband_id) || empty($wife_id)) {
            $error = 'Vui lòng chọn đủ chồng và vợ!';
        } else {
            // Chồng đã có vợ chưa?
            $stmtC = $pdo->prepare("
                SELECT d.full_name FROM marriages ma
                JOIN daughters_in_law d ON d.daughter_in_law_id = ma.wife_id
                WHERE ma.husband_id = ?
            ");
            $stmtC->execute([$husband_id]);
            $existWife = $stmtC->fetchColumn();

            // Vợ đã có chồng chưa?
            $stmtC2 = $pdo->prepare("
                SELECT m.full_name FROM marriages ma
                JOIN members m ON m.member_id = ma.husband_id
                WHERE ma.wife_id = ?
            ");
            $stmtC2->execute([$wife_id]);
            $existHusband = $stmtC2->fetchColumn();

            if ($existWife) {
                $error = "Người chồng này đã kết hôn với <strong>{$existWife}</strong>!";
            } elseif ($existHusband) {
                $error = "Người vợ này đã kết hôn với <strong>{$existHusband}</strong>!";
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO marriages (husband_id, wife_id, created_at)
                        VALUES (?, ?, GETDATE())
                    ");
                    $stmt->execute([$husband_id, $wife_id]);
                    redirect('manage_relationships.php', 'Thêm hôn nhân thành công!');
                } catch (PDOException $e) {
                    $error = 'Lỗi: Hôn nhân này đã tồn tại!';
                }
            }
        }
    }

    // ── SỬA QUAN HỆ CHA-CON ──────────────────────────────────
    if ($action == 'edit_relationship') {
        $rel_id    = (int) $_POST['relationship_id'];
        $parent_id = (int) $_POST['parent_id'];
        $child_id  = (int) $_POST['child_id'];

        if ($parent_id == $child_id) {
            $error = 'Một người không thể là cha của chính mình!';
        } else {
            // Cha phải là Nam
            $stmtG = $pdo->prepare("SELECT gender FROM members WHERE member_id = ? AND family_id = ?");
            $stmtG->execute([$parent_id, $family_id]);
            $parentGender = $stmtG->fetchColumn();
            if ($parentGender !== 'male') {
                $error = 'Người cha phải là thành viên Nam!';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE relationships
                        SET parent_id = ?, child_id = ?, relationship_type = 'father'
                        WHERE relationship_id = ?
                    ");
                    $stmt->execute([$parent_id, $child_id, $rel_id]);

                    // Cập nhật lại generation cho con và con cháu bên dưới
                    updateGenerationRecursive($child_id, $pdo);

                    redirect('manage_relationships.php', 'Cập nhật quan hệ thành công!');
                } catch (PDOException $e) {
                    $error = 'Lỗi cập nhật quan hệ!';
                }
            }
        }
    }

    // ── SỬA HÔN NHÂN ─────────────────────────────────────────
    if ($action == 'edit_marriage') {
        $mar_id     = (int) $_POST['marriage_id'];
        $husband_id = (int) $_POST['husband_id'];
        $wife_id    = (int) $_POST['wife_id'];

        // Chồng đã có vợ khác chưa?
        $stmtC = $pdo->prepare("
            SELECT d.full_name FROM marriages ma
            JOIN daughters_in_law d ON d.daughter_in_law_id = ma.wife_id
            WHERE ma.husband_id = ? AND ma.marriage_id != ?
        ");
        $stmtC->execute([$husband_id, $mar_id]);
        $existWife = $stmtC->fetchColumn();

        // Vợ đã có chồng khác chưa?
        $stmtC2 = $pdo->prepare("
            SELECT m.full_name FROM marriages ma
            JOIN members m ON m.member_id = ma.husband_id
            WHERE ma.wife_id = ? AND ma.marriage_id != ?
        ");
        $stmtC2->execute([$wife_id, $mar_id]);
        $existHusband = $stmtC2->fetchColumn();

        if ($existWife) {
            $error = "Người chồng này đã kết hôn với <strong>{$existWife}</strong>!";
        } elseif ($existHusband) {
            $error = "Người vợ này đã kết hôn với <strong>{$existHusband}</strong>!";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE marriages SET husband_id = ?, wife_id = ?
                    WHERE marriage_id = ?
                ");
                $stmt->execute([$husband_id, $wife_id, $mar_id]);
                redirect('manage_relationships.php', 'Cập nhật hôn nhân thành công!');
            } catch (PDOException $e) {
                $error = 'Lỗi cập nhật hôn nhân!';
            }
        }
    }

    // ── XÓA QUAN HỆ CHA-CON ──────────────────────────────────
    if ($action == 'delete_relationship') {
        $rel_id = (int) $_POST['relationship_id'];
        $stmt = $pdo->prepare("DELETE FROM relationships WHERE relationship_id = ?");
        $stmt->execute([$rel_id]);
        redirect('manage_relationships.php', 'Xóa quan hệ thành công!');
    }

    // ── XÓA HÔN NHÂN ─────────────────────────────────────────
    if ($action == 'delete_marriage') {
        $mar_id = (int) $_POST['marriage_id'];
        $stmt = $pdo->prepare("DELETE FROM marriages WHERE marriage_id = ?");
        $stmt->execute([$mar_id]);
        redirect('manage_relationships.php', 'Xóa hôn nhân thành công!');
    }
}

// ── LẤY DỮ LIỆU HIỂN THỊ ────────────────────────────────────
$search_rel = $_GET['search_rel'] ?? '';
$search_mar = $_GET['search_mar'] ?? '';

// Danh sách thành viên của gia tộc để chọn làm cha/con trong quan hệ
$stmtM = $pdo->prepare("SELECT member_id, full_name, gender, generation FROM members WHERE family_id = ? ORDER BY generation, full_name");
$stmtM->execute([$family_id]);
$members = $stmtM->fetchAll();

// Thành viên Nam còn độc thân 
$stmtSingle = $pdo->prepare("
    SELECT member_id, full_name, generation
    FROM members
    WHERE family_id = ?
      AND gender = 'male'
      AND member_id NOT IN (SELECT husband_id FROM marriages)
    ORDER BY generation, full_name
");
$stmtSingle->execute([$family_id]);
$singleMaleMembers = $stmtSingle->fetchAll();

// Con dâu còn độc thân 
$stmtD = $pdo->prepare("
    SELECT daughter_in_law_id, full_name
    FROM daughters_in_law
    WHERE family_id = ?
      AND daughter_in_law_id NOT IN (SELECT wife_id FROM marriages)
    ORDER BY full_name
");
$stmtD->execute([$family_id]);
$daughtersInLaw = $stmtD->fetchAll();

// Danh sách thành viên không phải cụ tổ 
$stmtNonRoot = $pdo->prepare("
    SELECT member_id, full_name, gender, generation
    FROM members
    WHERE family_id = ?
      AND generation > 1
    ORDER BY generation, full_name
");
$stmtNonRoot->execute([$family_id]);
$nonRootMembers = $stmtNonRoot->fetchAll();

// Quan hệ cha-con (chỉ trong gia tộc này)
$rel_sql = "
    SELECT r.relationship_id, r.parent_id, r.child_id, r.relationship_type,
           p.full_name AS parent_name, p.generation AS parent_gen,
           c.full_name AS child_name,  c.generation AS child_gen
    FROM relationships r
    JOIN members p ON p.member_id = r.parent_id
    JOIN members c ON c.member_id = r.child_id
    WHERE p.family_id = ? AND c.family_id = ?
";
$rel_params = [$family_id, $family_id];
if ($search_rel) {
    $rel_sql    .= " AND (p.full_name LIKE ? OR c.full_name LIKE ?)";
    $rel_params[] = "%$search_rel%";
    $rel_params[] = "%$search_rel%";
}
$rel_sql .= " ORDER BY p.generation, p.full_name";
$stmtR = $pdo->prepare($rel_sql);
$stmtR->execute($rel_params);
$relationships = $stmtR->fetchAll();

// Hôn nhân (chồng thuộc gia tộc này)
$mar_sql = "
    SELECT ma.marriage_id, ma.husband_id, ma.wife_id,
           h.full_name AS husband_name, h.generation AS husband_gen,
           w.full_name AS wife_name
    FROM marriages ma
    JOIN members h            ON h.member_id          = ma.husband_id
    JOIN daughters_in_law w   ON w.daughter_in_law_id = ma.wife_id
    WHERE h.family_id = ?
";
$mar_params = [$family_id];
if ($search_mar) {
    $mar_sql    .= " AND (h.full_name LIKE ? OR w.full_name LIKE ?)";
    $mar_params[] = "%$search_mar%";
    $mar_params[] = "%$search_mar%";
}
$mar_sql .= " ORDER BY h.generation, h.full_name";
$stmtMar = $pdo->prepare($mar_sql);
$stmtMar->execute($mar_params);
$marriages = $stmtMar->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý quan hệ - <?= htmlspecialchars($family['family_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { background: linear-gradient(180deg, #667eea 0%, #764ba2 100%); min-height: 100vh; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 1rem 1.5rem; border-radius: 10px; margin: 0.25rem 0; transition: all 0.3s ease; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: rgba(255,255,255,0.2); color: white; transform: translateX(5px); }
        .main-content { padding: 2rem; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        .search-box { background: #fff; border-radius: 12px; padding: 1rem 1.25rem; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 1rem; }
        .result-count { font-size: 0.85rem; color: #6c757d; margin-bottom: 0.5rem; }
        .family-badge { background: linear-gradient(135deg,#667eea,#764ba2); color: white; border-radius: 8px; padding: 0.3rem 0.8rem; font-size: 0.85rem; }
        .gen-tag { background: #e8ecff; color: #667eea; border-radius: 5px; padding: 0.1rem 0.4rem; font-size: 0.75rem; font-weight: 600; }
    </style>
</head>
<body>
<div class="container-fluid">
<div class="row">

<!-- Sidebar -->
<nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
    <div class="position-sticky pt-3">
        <div class="text-center mb-4">
            <h4 class="text-white"><i class="fas fa-sitemap me-2"></i>Admin Panel</h4>
            <small class="text-white-50">Xin chào, <?= htmlspecialchars($_SESSION['full_name']) ?></small>
        </div>
        <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link" href="manage_family.php"><i class="fas fa-home me-2"></i>Quản lý gia tộc</a></li>
            <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
            <li class="nav-item"><a class="nav-link" href="manage_members.php"><i class="fas fa-user-friends me-2"></i>Quản lý thành viên</a></li>
            <li class="nav-item"><a class="nav-link" href="manage_daughter_in_law.php"><i class="fas fa-user-friends me-2"></i>Quản lý con dâu</a></li>
            <li class="nav-item"><a class="nav-link active" href="manage_relationships.php"><i class="fas fa-heart me-2"></i>Quản lý quan hệ</a></li>
            <li class="nav-item"><a class="nav-link" href="statistics.php"><i class="fas fa-chart-bar me-2"></i>Thống kê</a></li>
            <li class="nav-item mt-3"><a class="nav-link" href="view_tree.php"><i class="fas fa-eye me-2"></i>Xem gia phả</a></li>
            <li class="nav-item"><a class="nav-link" href="../logout.php" onclick="return confirm('Bạn có chắc muốn đăng xuất?')"><i class="fas fa-sign-out-alt me-2"></i>Đăng xuất</a></li>
        </ul>
    </div>
</nav>

<!-- Main content -->
<main class="col-md-9 ms-sm-auto col-lg-10 main-content">
    <?= showMessage() ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i><?= $error ?>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between flex-wrap align-items-center pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2 mb-1">Quản lý quan hệ gia đình</h1>
            <span class="family-badge">
                <i class="fas fa-home me-1"></i><?= htmlspecialchars($family['family_name']) ?>
            </span>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════
         PHẦN 1: QUAN HỆ CHA - CON
    ══════════════════════════════════════════════ -->
    <h5 class="mb-3"><i class="fas fa-users me-2 text-primary"></i>Quan hệ Cha — Con</h5>

    <div class="search-box">
        <form method="GET" class="row g-2 align-items-end">
            <?php if ($search_mar): ?>
                <input type="hidden" name="search_mar" value="<?= htmlspecialchars($search_mar) ?>">
            <?php endif; ?>
            <div class="col-md-8">
                <label class="form-label mb-1 small fw-semibold">Tìm theo tên cha hoặc tên con</label>
                <input type="text" class="form-control" name="search_rel"
                       value="<?= htmlspecialchars($search_rel) ?>"
                       placeholder="Nhập tên thành viên...">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="fas fa-search me-1"></i>Tìm kiếm
                </button>
                <?php if ($search_rel): ?>
                    <a href="manage_relationships.php<?= $search_mar ? '?search_mar='.urlencode($search_mar) : '' ?>"
                       class="btn btn-outline-secondary">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <p class="result-count">
        Tìm thấy <strong><?= count($relationships) ?></strong> quan hệ
        <?= $search_rel ? ' cho "<strong>' . htmlspecialchars($search_rel) . '</strong>"' : '' ?>
    </p>

    <div class="card mb-5">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Cha</th>
                        <th>Con</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($relationships)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">Không tìm thấy quan hệ nào</td></tr>
                    <?php endif; ?>
                    <?php foreach ($relationships as $rel): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($rel['parent_name']) ?>
                            <span class="gen-tag ms-1">Đời <?= $rel['parent_gen'] ?></span>
                        </td>
                        <td>
                            <?= htmlspecialchars($rel['child_name']) ?>
                            <span class="gen-tag ms-1">Đời <?= $rel['child_gen'] ?></span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-warning"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editRelModal<?= $rel['relationship_id'] ?>">
                                <i class="fas fa-edit me-1"></i>Sửa
                            </button>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete_relationship">
                                <input type="hidden" name="relationship_id" value="<?= $rel['relationship_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Xóa quan hệ cha-con này?')">
                                    <i class="fas fa-trash me-1"></i>Xóa
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════
         PHẦN 2: HÔN NHÂN
    ══════════════════════════════════════════════ -->
    <h5 class="mb-3"><i class="fas fa-ring me-2 text-success"></i>Quan hệ Hôn nhân</h5>

    <!-- Form thêm hôn nhân -->
    <div class="card mb-3">
        <div class="card-header fw-semibold">
            <i class="fas fa-plus me-2"></i>Thêm hôn nhân mới
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="add_marriage">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Chồng <small class="text-muted">(chỉ hiện Nam độc thân)</small></label>
                    <select name="husband_id" class="form-control" required>
                        <option value="">Chọn chồng</option>
                        <?php foreach ($singleMaleMembers as $m): ?>
                            <option value="<?= $m['member_id'] ?>">
                                <?= htmlspecialchars($m['full_name']) ?> (Đời <?= $m['generation'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Vợ <small class="text-muted">(chỉ hiện con dâu độc thân)</small></label>
                    <select name="wife_id" class="form-control" required>
                        <option value="">Chọn vợ</option>
                        <?php foreach ($daughtersInLaw as $dil): ?>
                            <option value="<?= $dil['daughter_in_law_id'] ?>">
                                <?= htmlspecialchars($dil['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="fas fa-plus me-1"></i>Thêm
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tìm kiếm hôn nhân -->
    <div class="search-box">
        <form method="GET" class="row g-2 align-items-end">
            <?php if ($search_rel): ?>
                <input type="hidden" name="search_rel" value="<?= htmlspecialchars($search_rel) ?>">
            <?php endif; ?>
            <div class="col-md-8">
                <label class="form-label mb-1 small fw-semibold">Tìm theo tên chồng hoặc tên vợ</label>
                <input type="text" class="form-control" name="search_mar"
                       value="<?= htmlspecialchars($search_mar) ?>"
                       placeholder="Nhập tên thành viên...">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-success flex-grow-1">
                    <i class="fas fa-search me-1"></i>Tìm kiếm
                </button>
                <?php if ($search_mar): ?>
                    <a href="manage_relationships.php<?= $search_rel ? '?search_rel='.urlencode($search_rel) : '' ?>"
                       class="btn btn-outline-secondary">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <p class="result-count">
        Tìm thấy <strong><?= count($marriages) ?></strong> hôn nhân
        <?= $search_mar ? ' cho "<strong>' . htmlspecialchars($search_mar) . '</strong>"' : '' ?>
    </p>

    <div class="card mb-4">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Chồng</th>
                        <th>Vợ</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($marriages)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">Không tìm thấy hôn nhân nào</td></tr>
                    <?php endif; ?>
                    <?php foreach ($marriages as $mar): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($mar['husband_name']) ?>
                            <span class="gen-tag ms-1">Đời <?= $mar['husband_gen'] ?></span>
                        </td>
                        <td><?= htmlspecialchars($mar['wife_name']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-warning"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editMarModal<?= $mar['marriage_id'] ?>">
                                <i class="fas fa-edit me-1"></i>Sửa
                            </button>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete_marriage">
                                <input type="hidden" name="marriage_id" value="<?= $mar['marriage_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Xóa hôn nhân này?')">
                                    <i class="fas fa-trash me-1"></i>Xóa
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div>
</div>

<!-- ── Modal sửa quan hệ cha-con ── -->
<?php foreach ($relationships as $rel): ?>
<div class="modal fade" id="editRelModal<?= $rel['relationship_id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_relationship">
                <input type="hidden" name="relationship_id" value="<?= $rel['relationship_id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Sửa quan hệ Cha — Con</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cha <small class="text-muted">(chỉ chọn thành viên Nam)</small></label>
                        <select name="parent_id" class="form-control" required>
                            <?php foreach ($members as $m): ?>
                                <?php if ($m['gender'] == 'male'): ?>
                                <option value="<?= $m['member_id'] ?>"
                                    <?= $m['member_id'] == $rel['parent_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['full_name']) ?> (Đời <?= $m['generation'] ?>)
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Con <small class="text-muted">(trừ cụ tổ)</small></label>
                        <select name="child_id" class="form-control" required>
                            <?php foreach ($nonRootMembers as $m): ?>
                                <option value="<?= $m['member_id'] ?>"
                                    <?= $m['member_id'] == $rel['child_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['full_name']) ?> (Đời <?= $m['generation'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="alert alert-info py-2 mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Loại quan hệ luôn là <strong>Cha — Con</strong>.
                        Thế hệ của con sẽ được tự động cập nhật lại.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>Lưu thay đổi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ── Modal sửa hôn nhân ── -->
<?php foreach ($marriages as $mar): ?>
<div class="modal fade" id="editMarModal<?= $mar['marriage_id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_marriage">
                <input type="hidden" name="marriage_id" value="<?= $mar['marriage_id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-ring me-2"></i>Sửa hôn nhân</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Chồng</label>
                        <select name="husband_id" class="form-control" required>
                            <?php foreach ($members as $m): ?>
                                <?php if ($m['gender'] == 'male'): ?>
                                <option value="<?= $m['member_id'] ?>"
                                    <?= $m['member_id'] == $mar['husband_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['full_name']) ?> (Đời <?= $m['generation'] ?>)
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Vợ</label>
                        <select name="wife_id" class="form-control" required>
                            <?php foreach ($daughtersInLaw as $dil): ?>
                                <option value="<?= $dil['daughter_in_law_id'] ?>"
                                    <?= $dil['daughter_in_law_id'] == $mar['wife_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dil['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>Lưu thay đổi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
