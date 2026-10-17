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
requireAdminFamilyAccess((int) $family_id, $pdo);

// Lấy thông tin gia tộc
$stmtF = $pdo->prepare("SELECT * FROM families WHERE family_id = ?");
$stmtF->execute([$family_id]);
$family = $stmtF->fetch();
if (!$family) {
    redirect('manage_family.php', 'Gia tộc không tồn tại!', 'error');
}
requireActiveFamilySubscription((int) $family_id, $pdo);

// Kiểm tra gia tộc đã có thành viên chưa → hiện modal khởi tạo cụ tổ
$stmt = $pdo->prepare("SELECT COUNT(*) FROM members WHERE family_id = ?");
$stmt->execute([$family_id]);
$totalMembers = (int) $stmt->fetchColumn();
if ($totalMembers == 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $showInitRootModal = true;
}

// ── XỬ LÝ POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    // ── KHỞI TẠO CỤ TỔ ──────────────────────────────────────
    if ($action == 'init_root') {
        $full_name   = trim($_POST['full_name']);
        $birth_date  = $_POST['birth_date'] ?: null;
        $death_date  = $_POST['death_date'] ?: null;
        $gender      = $_POST['gender'];        // luôn = 'male'
        $birth_place = trim($_POST['birth_place']);
        $occupation  = trim($_POST['occupation']);
        $education   = trim($_POST['education']);
        $phone       = trim($_POST['phone']);
        $address     = trim($_POST['address']);
        $biography   = trim($_POST['biography']);

        $wife_id_root = (!empty($_POST['wife_id'])) ? (int)$_POST['wife_id'] : null;

        try {
            ensureFamilyMemberLimitAvailable((int) $family_id, $pdo);
            $avatar = null;
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
                $avatar = uploadImage($_FILES['avatar']);
            }
            $stmt = $pdo->prepare("
                INSERT INTO members
                    (family_id, full_name, birth_date, death_date, gender, generation,
                    birth_place, occupation, education, phone, address, biography, avatar, created_by)
                VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $family_id, $full_name, $birth_date, $death_date, $gender,
                $birth_place, $occupation, $education, $phone, $address, $biography,
                $avatar, $_SESSION['user_id']
            ]);

            $root_id = $pdo->lastInsertId();

            // Nếu có chọn vợ → thêm quan hệ hôn nhân cho cụ tổ
            if ($wife_id_root) {
                $stmtMar = $pdo->prepare("
                    INSERT INTO marriages (husband_id, wife_id, created_at)
                    VALUES (?, ?, GETDATE())
                ");
                $stmtMar->execute([$root_id, $wife_id_root]);
            }

            redirect('manage_members.php', 'Khởi tạo cụ tổ thành công!');
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = 'Lỗi: ' . $e->getMessage();
        }
    }

    // ── THÊM THÀNH VIÊN ──────────────────────────────────────
    if ($action == 'add') {
        $full_name   = trim($_POST['full_name']);
        $birth_date  = $_POST['birth_date'] ?: null;
        $death_date  = $_POST['death_date'] ?: null;
        $gender      = $_POST['gender'];
        $father_id   = $_POST['father_id'] ?? '';
        $wife_id     = ($gender == 'male' && !empty($_POST['wife_id'])) ? $_POST['wife_id'] : null;
        $birth_place = trim($_POST['birth_place']);
        $occupation  = trim($_POST['occupation']);
        $education   = trim($_POST['education']);
        $phone       = trim($_POST['phone']);
        $address     = trim($_POST['address']);
        $biography   = trim($_POST['biography']);
        $generation  = null;

        if (empty($father_id)) {
            $error = 'Bạn phải chọn cha.';
        }

        if (!isset($error)) {
            // Lấy thông tin cha (phải thuộc cùng gia tộc hiện tại)
            $stmt = $pdo->prepare("
                SELECT m.full_name, m.generation,
                       ma.wife_id AS daughter_in_law_id,
                       d.full_name AS mother_name
                FROM members m
                LEFT JOIN marriages ma ON ma.husband_id = m.member_id
                LEFT JOIN daughters_in_law d ON d.daughter_in_law_id = ma.wife_id
                WHERE m.member_id = ? AND m.family_id = ?
            ");
            $stmt->execute([$father_id, $family_id]);
            $fatherInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$fatherInfo) {
                $error = 'Không tìm thấy người cha trong gia tộc này.';
            } else {
                $generation = $fatherInfo['generation'] + 1;

                // Kiểm tra họ con phải trùng họ cha
                $fatherSurname = normalizeVietnameseSurname($fatherInfo['full_name']);
                $childSurname  = normalizeVietnameseSurname($full_name);
                if ($fatherSurname !== $childSurname) {
                    $error = 'Họ của con phải trùng với họ của cha (' . htmlspecialchars($fatherInfo['full_name']) . ').';
                }

                // Không được chọn mẹ làm vợ
                if (!empty($wife_id) && $wife_id == $fatherInfo['daughter_in_law_id']) {
                    $error = 'Không thể chọn mẹ làm vợ của thành viên.';
                }
            }
        }

        if (!isset($error)) {
            try {
                ensureFamilyMemberLimitAvailable((int) $family_id, $pdo);
                $avatar = null;
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
                    $avatar = uploadImage($_FILES['avatar']);
                }
                // Thêm thành viên mới
                $stmt = $pdo->prepare("
                    INSERT INTO members
                        (family_id, full_name, birth_date, death_date, gender, generation,
                         birth_place, occupation, education, phone, address, biography, avatar, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $family_id, $full_name, $birth_date, $death_date, $gender, $generation,
                    $birth_place, $occupation, $education, $phone, $address, $biography,
                    $avatar, $_SESSION['user_id']
                ]);

                $child_id = $pdo->lastInsertId();

                // Thêm quan hệ cha-con
                $stmt = $pdo->prepare("
                    INSERT INTO relationships (parent_id, child_id, relationship_type, created_at)
                    VALUES (?, ?, 'father', GETDATE())
                ");
                $stmt->execute([$father_id, $child_id]);

                // Nếu là Nam và có chọn vợ → thêm quan hệ hôn nhân
                if ($gender == 'male' && !empty($wife_id)) {
                    $stmtMar = $pdo->prepare("
                        INSERT INTO marriages (husband_id, wife_id, created_at)
                        VALUES (?, ?, GETDATE())
                    ");
                    $stmtMar->execute([$child_id, $wife_id]);
                }

                redirect('manage_members.php', 'Thêm thành viên thành công!');
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (PDOException $e) {
                $error = 'Lỗi: ' . $e->getMessage();
            }
        }
    }

    // ── SỬA THÀNH VIÊN ───────────────────────────────────────
    // CHỈ áp dụng cho member thuộc đúng gia tộc hiện tại (family_id = $family_id)
    if ($action == 'edit') {
        $member_id   = (int) $_POST['member_id'];
        $full_name   = trim($_POST['full_name']);
        $birth_date  = $_POST['birth_date'] ?: null;
        $death_date  = $_POST['death_date'] ?: null;
        $gender      = $_POST['gender'];
        $generation  = (int) $_POST['generation'];
        $birth_place = trim($_POST['birth_place']);
        $occupation  = trim($_POST['occupation']);
        $education   = trim($_POST['education']);
        $phone       = trim($_POST['phone']);
        $address     = trim($_POST['address']);
        $biography   = trim($_POST['biography']);

        // Xác nhận member này thuộc đúng gia tộc hiện tại trước khi cho sửa
        $stmtOwn = $pdo->prepare("SELECT COUNT(*) FROM members WHERE member_id = ? AND family_id = ?");
        $stmtOwn->execute([$member_id, $family_id]);
        if ((int) $stmtOwn->fetchColumn() == 0) {
            $error = 'Bạn không thể sửa thành viên này vì họ thuộc một nhánh gia tộc khác.';
        }

        // Không đổi giới tính sang nữ nếu đang có vợ
        if (!isset($error) && $gender == 'female') {
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM marriages WHERE husband_id = ?");
            $stmtCheck->execute([$member_id]);
            if ((int) $stmtCheck->fetchColumn() > 0) {
                $error = 'Không thể đổi giới tính thành Nữ vì thành viên này đã có vợ trong hệ thống.';
            }
        }

        if (!isset($error)) {
            $avatar = null;
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
                $avatar = uploadImage($_FILES['avatar']);
            }

            try {
                if ($avatar) {
                    $stmt = $pdo->prepare("
                        UPDATE members
                        SET full_name=?, birth_date=?, death_date=?, gender=?, generation=?,
                            birth_place=?, occupation=?, education=?, phone=?, address=?, biography=?, avatar=?
                        WHERE member_id=? AND family_id=?
                    ");
                    $stmt->execute([
                        $full_name, $birth_date, $death_date, $gender, $generation,
                        $birth_place, $occupation, $education, $phone, $address, $biography,
                        $avatar, $member_id, $family_id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE members
                        SET full_name=?, birth_date=?, death_date=?, gender=?, generation=?,
                            birth_place=?, occupation=?, education=?, phone=?, address=?, biography=?
                        WHERE member_id=? AND family_id=?
                    ");
                    $stmt->execute([
                        $full_name, $birth_date, $death_date, $gender, $generation,
                        $birth_place, $occupation, $education, $phone, $address, $biography,
                        $member_id, $family_id
                    ]);
                }
                redirect('manage_members.php', 'Cập nhật thành viên thành công!');
            } catch (PDOException $e) {
                $error = 'Lỗi: ' . $e->getMessage();
            }
        }
    }

    // ── TÁCH NHÁNH GIA TỘC ────────────────────────────────────
    // CHỈ áp dụng cho member thuộc đúng gia tộc hiện tại
    if ($action == 'split_family') {
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $new_family_name = trim($_POST['new_family_name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // Xác nhận member này thuộc đúng gia tộc hiện tại trước khi cho tách
        $stmtOwn = $pdo->prepare("SELECT COUNT(*) FROM members WHERE member_id = ? AND family_id = ?");
        $stmtOwn->execute([$member_id, $family_id]);
        if ((int) $stmtOwn->fetchColumn() == 0) {
            $error = 'Bạn không thể tách thành viên này vì họ thuộc một nhánh gia tộc khác.';
        } else {
            $result = splitMemberToNewFamily(
                $member_id,
                $family_id,
                $new_family_name,
                $description,
                $_SESSION['user_id']
            );

            if (is_array($result)) {
                redirect(
                    'manage_family.php',
                    'Tách nhánh thành gia tộc mới thành công! Đã chuyển '
                    . $result['moved_members'] . ' thành viên và '
                    . $result['moved_wives'] . ' con dâu liên quan.'
                );
            } else {
                $error = $result;
            }
        }
    }

    // ── XÓA THÀNH VIÊN ───────────────────────────────────────
    // CHỈ áp dụng cho member thuộc đúng gia tộc hiện tại
    if ($action == 'delete') {
        $member_id = (int) $_POST['member_id'];

        // Xác nhận member này thuộc đúng gia tộc hiện tại trước khi cho xóa
        $stmtOwn = $pdo->prepare("SELECT COUNT(*) FROM members WHERE member_id = ? AND family_id = ?");
        $stmtOwn->execute([$member_id, $family_id]);
        if ((int) $stmtOwn->fetchColumn() == 0) {
            $error = 'Bạn không thể xóa thành viên này vì họ thuộc một nhánh gia tộc khác.';
        } else {
            // Kiểm tra có con không (là cha của ai đó)
            $stmtC = $pdo->prepare("SELECT COUNT(*) FROM relationships WHERE parent_id = ?");
            $stmtC->execute([$member_id]);
            $hasChildren = (int) $stmtC->fetchColumn();

            // Kiểm tra có hôn nhân không
            $stmtM = $pdo->prepare("SELECT COUNT(*) FROM marriages WHERE husband_id = ?");
            $stmtM->execute([$member_id]);
            $hasMarriage = (int) $stmtM->fetchColumn();

            if ($hasChildren > 0 || $hasMarriage > 0) {
                $parts = [];
                if ($hasChildren > 0) $parts[] = "<strong>{$hasChildren} con</strong>";
                if ($hasMarriage > 0) $parts[] = "<strong>{$hasMarriage} hôn nhân</strong>";
                $error = 'Không thể xóa thành viên này vì đang có ' . implode(' và ', $parts)
                       . ' liên kết. Vui lòng xóa các liên kết đó trước.';
            } else {
                try {
                    // Xóa khỏi relationships (là con của ai đó)
                    $stmt = $pdo->prepare("DELETE FROM relationships WHERE child_id = ?");
                    $stmt->execute([$member_id]);

                    $stmt = $pdo->prepare("DELETE FROM members WHERE member_id = ? AND family_id = ?");
                    $stmt->execute([$member_id, $family_id]);
                    redirect('manage_members.php', 'Xóa thành viên thành công!');
                } catch (PDOException $e) {
                    $error = 'Lỗi: Không thể xóa thành viên này!';
                }
            }
        }
    }
}

// ── LẤY DỮ LIỆU HIỂN THỊ ────────────────────────────────────

$search            = $_GET['search'] ?? '';
$generation_filter = $_GET['generation'] ?? '';

// Lấy toàn bộ family_id thuộc cây con của gia tộc hiện tại (đệ quy theo parent_family_id)
// Bao gồm chính gia tộc hiện tại + mọi nhánh đã tách ra (nhiều cấp)
$stmt = $pdo->prepare("
    WITH FamilyTree AS (
        SELECT family_id, family_name FROM families WHERE family_id = ?
        UNION ALL
        SELECT f.family_id, f.family_name
        FROM families f
        INNER JOIN FamilyTree ft ON f.parent_family_id = ft.family_id
    )
    SELECT family_id, family_name FROM FamilyTree
");
$stmt->execute([$family_id]);
$familyTreeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$familyIds   = array_column($familyTreeRows, 'family_id');
$familyNames = array_column($familyTreeRows, 'family_name', 'family_id'); // map family_id => family_name

$placeholders = implode(',', array_fill(0, count($familyIds), '?'));

$sql    = "SELECT * FROM members WHERE family_id IN ($placeholders)";
$params = $familyIds;

if ($search) {
    if (is_numeric($search) && strlen($search) == 4) {
        $sql     .= " AND (full_name LIKE ? OR YEAR(birth_date) = ?)";
        $params[] = "%$search%";
        $params[] = (int) $search;
    } else {
        $sql     .= " AND full_name LIKE ?";
        $params[] = "%$search%";
    }
}
if ($generation_filter) {
    $sql     .= " AND generation = ?";
    $params[] = $generation_filter;
}
$sql .= " ORDER BY generation, full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

// Mapping child_id → thông tin cha (trong toàn bộ cây gia tộc, để hiển thị đúng cho cả nhánh con)
$stmt = $pdo->prepare("
    SELECT r.child_id, m.full_name AS father_name, m.birth_date AS father_birth_date
    FROM relationships r
    JOIN members m ON m.member_id = r.parent_id
    WHERE r.relationship_type = 'father' AND m.family_id IN ($placeholders)
");
$stmt->execute($familyIds);
$fatherMap = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $fatherMap[$row['child_id']] = $row;
}

// Mapping child_id → thông tin mẹ (suy ra qua: cha của child_id → vợ của cha đó, nếu có)
// Dùng để hiển thị "Mẹ" trong modal chi tiết
$stmt = $pdo->prepare("
    SELECT r.child_id, d.full_name AS mother_name, d.birth_date AS mother_birth_date
    FROM relationships r
    JOIN marriages ma ON ma.husband_id = r.parent_id
    JOIN daughters_in_law d ON d.daughter_in_law_id = ma.wife_id
    WHERE r.relationship_type = 'father' AND d.family_id IN ($placeholders)
");
$stmt->execute($familyIds);
$motherMap = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $motherMap[$row['child_id']] = $row;
}

// Mapping member_id (chồng) → thông tin vợ, dùng để hiển thị "Vợ" trong modal chi tiết
$stmt = $pdo->prepare("
    SELECT ma.husband_id, d.daughter_in_law_id, d.full_name AS wife_name, d.birth_date AS wife_birth_date
    FROM marriages ma
    JOIN daughters_in_law d ON d.daughter_in_law_id = ma.wife_id
    WHERE d.family_id IN ($placeholders)
");
$stmt->execute($familyIds);
$wifeMap = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $wifeMap[$row['husband_id']] = $row;
}

// Mapping parent_id → danh sách các con (tên + giới tính), dùng để hiển thị "Con" trong modal chi tiết
$stmt = $pdo->prepare("
    SELECT r.parent_id, m.member_id AS child_member_id, m.full_name AS child_name, m.gender AS child_gender
    FROM relationships r
    JOIN members m ON m.member_id = r.child_id
    WHERE r.relationship_type = 'father' AND m.family_id IN ($placeholders)
    ORDER BY m.birth_date
");
$stmt->execute($familyIds);
$childrenMap = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $childrenMap[$row['parent_id']][] = $row;
}

// Danh sách thế hệ có trong gia tộc hiện tại (dùng để lọc) — chỉ tính riêng gia tộc hiện tại
$stmt = $pdo->prepare("SELECT DISTINCT generation FROM members WHERE family_id = ? ORDER BY generation");
$stmt->execute([$family_id]);
$generations = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Danh sách con dâu chưa kết hôn của gia tộc hiện tại (giữ riêng theo nhánh hiện tại)
$stmt = $pdo->prepare("
    SELECT d.daughter_in_law_id, d.full_name
    FROM daughters_in_law d
    WHERE d.family_id = ?
      AND d.daughter_in_law_id NOT IN (SELECT wife_id FROM marriages)
    ORDER BY d.full_name
");
$stmt->execute([$family_id]);
$daughtersInLaw = $stmt->fetchAll();

// Mapping chồng → tên mẹ (nếu có) để hiển thị trong chi tiết thành viên
$stmt = $pdo->prepare("
    SELECT ma.husband_id, d.full_name
    FROM marriages ma
    JOIN daughters_in_law d ON d.daughter_in_law_id = ma.wife_id
    WHERE d.family_id = ?
");
$stmt->execute([$family_id]);
$fatherMotherMap = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $fatherMotherMap[$row['husband_id']] = $row['full_name'];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý thành viên - <?= htmlspecialchars($family['family_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
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
        .main-content { padding: 2rem; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        .avatar-preview { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; }
        .avatar-placeholder {
            width: 60px; height: 60px; border-radius: 50%;
            background: #f0f0f0; display: flex; align-items: center;
            justify-content: center; margin: 0 auto;
        }
        .member-card { transition: transform 0.2s ease; }
        .member-card:hover { transform: translateY(-2px); }
        .family-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white; border-radius: 8px;
            padding: 0.3rem 0.8rem; font-size: 0.85rem;
        }
        .generation-badge {
            background: #e8ecff; color: #667eea;
            border-radius: 6px; padding: 0.15rem 0.5rem;
            font-size: 0.75rem; font-weight: 600;
        }
        .branch-badge {
            background: #fff3cd; color: #997404;
            border: 1px solid #ffe69c;
            border-radius: 6px; padding: 0.15rem 0.6rem;
            font-size: 0.75rem; font-weight: 600;
            display: inline-block; margin-bottom: 0.5rem;
        }
        .suggestion-item {
            padding: 8px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .suggestion-item:last-child { border-bottom: none; }
        .suggestion-item:hover, .suggestion-item.active { background-color: #f0f4ff; }
        .suggestion-item .gen-badge {
            background: #e8ecff; color: #667eea;
            border-radius: 5px; padding: 2px 7px;
            font-size: 0.75rem; font-weight: 600; white-space: nowrap;
        }
        .suggestion-item .name-text { font-weight: 500; color: #222; }
        .suggestion-item .name-text mark { background: #fff3cd; border-radius: 2px; padding: 0; }
        #father_search:focus { box-shadow: 0 0 0 0.2rem rgba(102,126,234,0.25); border-color: #667eea; }
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
            <li class="nav-item"><a class="nav-link active" href="manage_members.php"><i class="fas fa-user-friends me-2"></i>Quản lý thành viên</a></li>
            <li class="nav-item"><a class="nav-link" href="manage_daughter_in_law.php"><i class="fas fa-user-friends me-2"></i>Quản lý con dâu</a></li>
            <li class="nav-item"><a class="nav-link" href="manage_relationships.php"><i class="fas fa-heart me-2"></i>Quản lý quan hệ</a></li>
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

    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2 mb-1">Quản lý thành viên</h1>
            <span class="family-badge">
                <i class="fas fa-home me-1"></i><?= htmlspecialchars($family['family_name']) ?>
            </span>
            <small class="text-muted ms-2">
                <i class="fas fa-info-circle me-1"></i>Tìm kiếm bao gồm cả các nhánh đã tách ra
            </small>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
            <i class="fas fa-plus me-2"></i>Thêm thành viên
        </button>
    </div>

    <!-- Tìm kiếm & lọc -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Tìm kiếm theo tên hoặc năm sinh</label>
                    <input type="text" class="form-control" name="search"
                           value="<?= htmlspecialchars($search) ?>" placeholder="Nhập tên hoặc năm sinh...">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Lọc theo thế hệ</label>
                    <select class="form-control" name="generation">
                        <option value="">Tất cả thế hệ</option>
                        <?php foreach ($generations as $gen): ?>
                            <option value="<?= $gen ?>" <?= $generation_filter == $gen ? 'selected' : '' ?>>
                                Đời <?= $gen ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="fas fa-search"></i>
                    </button>
                    <a href="manage_members.php" class="btn btn-outline-secondary">
                        <i class="fas fa-sync"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Danh sách thành viên -->
    <div class="row">
        <?php foreach ($members as $member): ?>
        <?php $isOtherBranch = ($member['family_id'] != $family_id); ?>
        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
            <div class="card member-card h-100">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <?php if ($member['avatar']): ?>
                            <img src="../uploads/<?= htmlspecialchars($member['avatar']) ?>"
                                class="avatar-preview" alt="Avatar">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <i class="fas <?= $member['gender'] == 'female' ? 'fa-person-dress' : 'fa-person' ?> fa-2x text-muted"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($isOtherBranch): ?>
                        <div>
                            <span class="branch-badge">
                                <i class="fas fa-code-branch me-1"></i><?= htmlspecialchars($familyNames[$member['family_id']] ?? 'Chi khác') ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <h6 class="card-title"><?= htmlspecialchars($member['full_name']) ?></h6>

                    <div class="text-muted small mb-2">
                        <div>
                            <span class="generation-badge">Đời <?= $member['generation'] ?></span>
                            &nbsp;
                            <i class="fas fa-<?= $member['gender'] == 'male' ? 'mars text-primary' : 'venus text-danger' ?> me-1"></i>
                            <?= $member['gender'] == 'male' ? 'Nam' : 'Nữ' ?>
                        </div>
                        <?php if ($member['birth_date']): ?>
                            <div><i class="fas fa-calendar me-1"></i><?= date('d/m/Y', strtotime($member['birth_date'])) ?></div>
                        <?php endif; ?>
                        <?php $isDeceased = !empty($member['death_date']) || !empty(trim($member['address'] ?? '')); ?>

                        <?php if ($member['birth_date'] && !$isDeceased): ?>
                            <div class="text-success">
                                <i class="fas fa-heartbeat me-1"></i><?= calculateAge($member['birth_date']) ?> tuổi
                            </div>
                        <?php elseif ($isDeceased): ?>
                            <div class="text-secondary">
                                <i class="fas fa-cross me-1"></i>
                                <?= $member['death_date'] ? date('d/m/Y', strtotime($member['death_date'])) : 'Đã mất' ?>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($fatherMap[$member['member_id']])): ?>
                            <div class="text-muted">
                                <i class="fas fa-male me-1"></i><?= htmlspecialchars($fatherMap[$member['member_id']]['father_name']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php
                        $memberId   = $member['member_id'];
                        $extraInfo  = [
                            'father'   => $fatherMap[$memberId]   ?? null,
                            'mother'   => $motherMap[$memberId]   ?? null,
                            'wife'     => $wifeMap[$memberId]     ?? null,
                            'children' => $childrenMap[$memberId] ?? [],
                        ];
                    ?>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-info"
                                onclick="viewMember(<?= htmlspecialchars(json_encode($member)) ?>, <?= htmlspecialchars(json_encode($extraInfo)) ?>)"
                                title="Xem chi tiết">
                            <i class="fas fa-eye"></i>
                        </button>

                        <?php if (!$isOtherBranch): ?>
                            <button class="btn btn-outline-primary"
                                    onclick="editMember(<?= htmlspecialchars(json_encode($member)) ?>)"
                                    title="Sửa">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ((int) $member['generation'] > 1): ?>
                            <button class="btn btn-outline-success"
                                    onclick="splitFamily(<?= htmlspecialchars(json_encode($member)) ?>)"
                                    title="Tách thành gia tộc mới">
                                <i class="fas fa-code-branch"></i>
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-outline-danger"
                                    onclick="deleteMember(<?= $member['member_id'] ?>, '<?= htmlspecialchars($member['full_name'], ENT_QUOTES) ?>')"
                                    title="Xóa">
                                <i class="fas fa-trash"></i>
                            </button>
                        <?php else: ?>
                            <span class="btn btn-outline-secondary disabled" title="Thuộc nhánh khác — chỉ xem, không thể sửa/xóa tại đây">
                                <i class="fas fa-lock"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($members)): ?>
    <div class="text-center py-5">
        <i class="fas fa-users fa-3x text-muted mb-3"></i>
        <h5 class="text-muted">Chưa có thành viên nào</h5>
        <p class="text-muted">Hãy thêm thành viên đầu tiên cho gia tộc <strong><?= htmlspecialchars($family['family_name']) ?></strong></p>
    </div>
    <?php endif; ?>
</main>
</div>
</div>

<!-- ── Modal khởi tạo cụ tổ ── -->
<div class="modal fade" id="initRootModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning bg-opacity-10">
                <h5 class="modal-title">
                    <i class="fas fa-crown me-2 text-warning"></i>
                    Khởi tạo cụ tổ — <?= htmlspecialchars($family['family_name']) ?>
                </h5>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Gia tộc này chưa có thành viên nào. Vui lòng khởi tạo cụ tổ (thế hệ 1) trước.
                    </div>
                    <input type="hidden" name="action" value="init_root">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Họ tên <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="full_name" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Giới tính</label>
                            <input type="text" class="form-control" value="Nam (Cụ tổ)" readonly>
                            <input type="hidden" name="gender" value="male">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Thế hệ</label>
                            <input type="text" class="form-control" value="1 (Cụ tổ)" readonly>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Ngày sinh</label>
                            <input type="date" class="form-control" name="birth_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Ngày mất</label>
                            <input type="date" class="form-control" name="death_date">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Nơi sinh</label>
                            <input type="text" class="form-control" name="birth_place">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Nghề nghiệp</label>
                            <input type="text" class="form-control" name="occupation">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Học vấn</label>
                            <input type="text" class="form-control" name="education">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Số điện thoại</label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">An táng</label>
                        <textarea class="form-control" name="address" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tiểu sử</label>
                        <textarea class="form-control" name="biography" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Vợ (con dâu)</label>
                        <select class="form-control" name="wife_id">
                            <option value="">Không có</option>
                            <?php foreach ($daughtersInLaw as $wife): ?>
                                <option value="<?= $wife['daughter_in_law_id'] ?>">
                                    <?= htmlspecialchars($wife['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ảnh đại diện</label>
                        <input type="file" class="form-control" name="avatar" accept="image/*">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-crown me-1"></i>Khởi tạo cụ tổ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal thêm thành viên ── -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Thêm thành viên mới</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-5 mb-3">
                            <label class="form-label fw-semibold">Họ tên <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="full_name" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Giới tính <span class="text-danger">*</span></label>
                            <select class="form-control" name="gender" id="gender" required>
                                <option value="male">Nam</option>
                                <option value="female">Nữ</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Thế hệ</label>
                            <input type="number" class="form-control" name="generation" id="generation" readonly>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3 position-relative">
                            <label class="form-label fw-semibold">Cha <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="father_search"
                                placeholder="Nhập tên cha..." autocomplete="off">
                            <input type="hidden" name="father_id" id="father_id">
                            <div id="fatherSuggestions"
                                class="position-absolute w-100 bg-white border rounded-3 shadow-lg mt-1"
                                style="display:none; z-index:9999; max-height:240px; overflow-y:auto;"></div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Mẹ</label>
                            <input type="text" id="mother_name" class="form-control" readonly placeholder="(tự động theo cha)">
                        </div>
                        <div class="col-md-4 mb-3" id="wife_container">
                            <label class="form-label fw-semibold">Vợ</label>
                            <select class="form-control" name="wife_id">
                                <option value="">Không có</option>
                                <?php foreach ($daughtersInLaw as $wife): ?>
                                    <option value="<?= $wife['daughter_in_law_id'] ?>">
                                        <?= htmlspecialchars($wife['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Ngày sinh</label>
                            <input type="date" class="form-control" name="birth_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Ngày mất</label>
                            <input type="date" class="form-control" name="death_date">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Nơi sinh</label>
                            <input type="text" class="form-control" name="birth_place">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Nghề nghiệp</label>
                            <input type="text" class="form-control" name="occupation">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Học vấn</label>
                            <input type="text" class="form-control" name="education">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Số điện thoại</label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">An táng</label>
                        <textarea class="form-control" name="address" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tiểu sử</label>
                        <textarea class="form-control" name="biography" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ảnh đại diện</label>
                        <input type="file" class="form-control" name="avatar" accept="image/*">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Thêm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal tách thành gia tộc mới ── -->
<div class="modal fade" id="splitFamilyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-code-branch me-2"></i>Tách thành gia tộc mới</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="split_family">
                    <input type="hidden" name="member_id" id="split_member_id">

                    <div class="alert alert-info small">
                        Thành viên được chọn và toàn bộ con cháu sẽ được chuyển sang gia tộc mới.
                        Người được tách sẽ trở thành đời 1 trong gia tộc mới.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Thành viên tách</label>
                        <input type="text" class="form-control" id="split_member_name" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Đời hiện tại</label>
                        <input type="text" class="form-control" id="split_generation" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tên gia tộc mới <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="new_family_name" id="split_new_family_name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mô tả</label>
                        <textarea class="form-control" name="description" rows="3"
                                  placeholder="VD: Chi nhánh tách ra từ đời ..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-code-branch me-1"></i>Tách gia tộc
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal sửa thành viên ── -->
<div class="modal fade" id="editMemberModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Sửa thông tin thành viên</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="member_id" id="edit_member_id">
                    <div class="row">
                        <div class="col-md-5 mb-3">
                            <label class="form-label fw-semibold">Họ tên <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="full_name" id="edit_full_name" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Giới tính <span class="text-danger">*</span></label>
                            <select class="form-control" name="gender" id="edit_gender" required>
                                <option value="male">Nam</option>
                                <option value="female">Nữ</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Thế hệ</label>
                            <input type="number" class="form-control" name="generation" id="edit_generation" readonly>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Ngày sinh</label>
                            <input type="date" class="form-control" name="birth_date" id="edit_birth_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Ngày mất</label>
                            <input type="date" class="form-control" name="death_date" id="edit_death_date">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Nơi sinh</label>
                            <input type="text" class="form-control" name="birth_place" id="edit_birth_place">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Nghề nghiệp</label>
                            <input type="text" class="form-control" name="occupation" id="edit_occupation">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Học vấn</label>
                            <input type="text" class="form-control" name="education" id="edit_education">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Số điện thoại</label>
                            <input type="text" class="form-control" name="phone" id="edit_phone">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">An táng</label>
                        <textarea class="form-control" name="address" id="edit_address" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tiểu sử</label>
                        <textarea class="form-control" name="biography" id="edit_biography" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ảnh mới (để trống nếu không đổi)</label>
                        <input type="file" class="form-control" name="avatar" accept="image/*">
                        <div id="current_avatar" class="mt-2"></div>
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

<!-- ── Modal xem chi tiết ── -->
<div class="modal fade" id="viewMemberModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Thông tin chi tiết</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="memberDetails"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const fatherMotherMap = <?= json_encode($fatherMotherMap) ?>;

    // Danh sách cha hợp lệ (Nam, thuộc gia tộc hiện tại) cho autocomplete
    const allFathers = <?= json_encode(array_values(array_map(fn($m) => [
        'id'         => (int) $m['member_id'],
        'name'       => $m['full_name'],
        'generation' => (int) $m['generation']
    ], array_filter($members, fn($m) => $m['gender'] == 'male' && $m['family_id'] == $family_id)))) ?>;

    document.addEventListener('DOMContentLoaded', function () {
        const fatherSearch      = document.getElementById('father_search');
        const fatherIdInput     = document.getElementById('father_id');
        const fatherSuggestions = document.getElementById('fatherSuggestions');
        const generationInput   = document.getElementById('generation');
        const motherInput       = document.getElementById('mother_name');
        let activeIndex = -1;

        if (fatherSearch) {
            fatherSearch.addEventListener('input', function () {
                const query = this.value.trim().toLowerCase();
                activeIndex = -1;

                // Chưa chọn lại từ gợi ý → reset các trường phụ thuộc
                fatherIdInput.value   = '';
                generationInput.value = '';
                motherInput.value     = '';

                if (!query) {
                    fatherSuggestions.style.display = 'none';
                    return;
                }

                const matches = allFathers
                    .filter(f => f.name.toLowerCase().includes(query))
                    .slice(0, 10);

                if (!matches.length) {
                    fatherSuggestions.innerHTML = '<div class="px-3 py-2 text-muted small">Không tìm thấy người cha phù hợp</div>';
                    fatherSuggestions.style.display = 'block';
                    return;
                }

                fatherSuggestions.innerHTML = matches.map(f => {
                    const hl = f.name.replace(
                        new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi'),
                        '<mark>$1</mark>'
                    );
                    return `<div class="suggestion-item" data-id="${f.id}" data-name="${f.name}" data-generation="${f.generation}">
                                <span class="name-text">${hl}</span>
                                <span class="gen-badge ms-auto">Đời ${f.generation}</span>
                            </div>`;
                }).join('');

                fatherSuggestions.style.display = 'block';

                fatherSuggestions.querySelectorAll('.suggestion-item').forEach(item => {
                    item.addEventListener('click', function () {
                        selectFather(this.dataset.id, this.dataset.name, parseInt(this.dataset.generation));
                    });
                });
            });

            // Điều hướng bằng phím
            fatherSearch.addEventListener('keydown', function (e) {
                const items = fatherSuggestions.querySelectorAll('.suggestion-item');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, items.length - 1);
                    updateActiveFather(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = Math.max(activeIndex - 1, 0);
                    updateActiveFather(items);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeIndex >= 0 && items[activeIndex]) {
                        selectFather(items[activeIndex].dataset.id, items[activeIndex].dataset.name, parseInt(items[activeIndex].dataset.generation));
                    }
                } else if (e.key === 'Escape') {
                    fatherSuggestions.style.display = 'none';
                }
            });
        }

        function updateActiveFather(items) {
            items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
        }

        function selectFather(id, name, generation) {
            fatherIdInput.value     = id;
            fatherSearch.value      = name;
            generationInput.value   = generation + 1;
            motherInput.value       = fatherMotherMap[id] || '';
            fatherSuggestions.style.display = 'none';
        }

        // Ẩn gợi ý khi click ra ngoài
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#father_search') && !e.target.closest('#fatherSuggestions')) {
                fatherSuggestions.style.display = 'none';
            }
        });

        // Ẩn/hiện trường vợ theo giới tính (giữ nguyên logic cũ)
        const genderSelect  = document.getElementById('gender');
        const wifeContainer = document.getElementById('wife_container');
        function toggleWife() {
            wifeContainer.style.display = genderSelect.value === 'female' ? 'none' : 'block';
        }
        if (genderSelect) {
            genderSelect.addEventListener('change', toggleWife);
            toggleWife();
        }

        // Reset form khi modal Thêm đóng (tránh giữ lại gợi ý/giá trị cũ lần mở sau)
        const addModalEl = document.getElementById('addMemberModal');
        if (addModalEl) {
            addModalEl.addEventListener('hidden.bs.modal', function () {
                fatherSearch.value      = '';
                fatherIdInput.value     = '';
                generationInput.value   = '';
                motherInput.value       = '';
                fatherSuggestions.style.display = 'none';
            });
        }
    });

    function editMember(m) {
        document.getElementById('edit_member_id').value    = m.member_id;
        document.getElementById('edit_full_name').value    = m.full_name;
        document.getElementById('edit_gender').value       = m.gender;
        document.getElementById('edit_generation').value   = m.generation;
        document.getElementById('edit_birth_date').value   = m.birth_date  ?? '';
        document.getElementById('edit_death_date').value   = m.death_date  ?? '';
        document.getElementById('edit_birth_place').value  = m.birth_place ?? '';
        document.getElementById('edit_occupation').value   = m.occupation  ?? '';
        document.getElementById('edit_education').value    = m.education   ?? '';
        document.getElementById('edit_phone').value        = m.phone       ?? '';
        document.getElementById('edit_address').value      = m.address     ?? '';
        document.getElementById('edit_biography').value    = m.biography   ?? '';

        const avatarDiv = document.getElementById('current_avatar');
        avatarDiv.innerHTML = m.avatar
            ? `<small class="text-muted">Ảnh hiện tại:</small><br>
               <img src="../uploads/${m.avatar}" class="mt-2 rounded" style="max-width:80px;">`
            : '';

        new bootstrap.Modal(document.getElementById('editMemberModal')).show();
    }

    function splitFamily(m) {
        document.getElementById('split_member_id').value = m.member_id;
        document.getElementById('split_member_name').value = m.full_name;
        document.getElementById('split_generation').value = 'Đời ' + m.generation;
        document.getElementById('split_new_family_name').value = 'Chi nhánh ' + m.full_name;

        new bootstrap.Modal(document.getElementById('splitFamilyModal')).show();
    }

    function deleteMember(id, name) {
        if (confirm(`Bạn có chắc muốn xóa thành viên "${name}"?\nThao tác này không thể hoàn tác!`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="member_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    function viewMember(m, extra) {
        extra = extra || {};
        const father   = extra.father   || null;
        const mother    = extra.mother    || null;
        const wife      = extra.wife      || null;
        const children  = extra.children  || [];

        const genderText = m.gender === 'male' ? 'Nam' : 'Nữ';
        const birthDate  = m.birth_date ? new Date(m.birth_date).toLocaleDateString('vi-VN') : 'Chưa xác định';
        const isDeceased = !!m.death_date || !!(m.address && m.address.trim());
        const deathDate  = m.death_date ? new Date(m.death_date).toLocaleDateString('vi-VN') : null;

        let ageText = '';
        if (m.birth_date) {
            const birth = new Date(m.birth_date);
            const end   = m.death_date ? new Date(m.death_date) : new Date(); // nếu ko có ngày mất, tạm tính theo hiện tại
            const age   = Math.floor((end - birth) / (365.25 * 24 * 60 * 60 * 1000));
            ageText = isDeceased ? `(Thọ ${age} tuổi)` : `(${age} tuổi)`;
        }

        const placeholderIcon = m.gender === 'female' ? 'fa-person-dress' : 'fa-person';
        const avatar = m.avatar
            ? `<img src="../uploads/${m.avatar}" class="img-fluid rounded mb-3" style="max-width:150px;">`
            : `<div class="bg-light rounded p-4 mb-3 d-inline-block"><i class="fas ${placeholderIcon} fa-4x text-muted"></i></div>`;

        // ── Thứ 1: Cha mẹ (nếu không phải cụ tổ) ──
        let parentsHtml = '';
        if (father) {
            const fb = father.father_birth_date
                ? new Date(father.father_birth_date).toLocaleDateString('vi-VN')
                : 'Chưa xác định';
            parentsHtml += `<tr><td class="fw-bold">Cha:</td><td>${father.father_name} <span class="text-muted small">(sinh ${fb})</span></td></tr>`;
        }
        if (mother) {
            const mb = mother.mother_birth_date
                ? new Date(mother.mother_birth_date).toLocaleDateString('vi-VN')
                : 'Chưa xác định';
            parentsHtml += `<tr><td class="fw-bold">Mẹ:</td><td>${mother.mother_name} <span class="text-muted small">(sinh ${mb})</span></td></tr>`;
        }
        if (!father && !mother) {
            if (m.generation == 1) {
                parentsHtml = `<tr><td class="fw-bold">Vai trò:</td><td><span class="badge bg-warning text-dark">Cụ tổ</span></td></tr>`;
            } else {
                parentsHtml = `<tr><td class="fw-bold">Cha mẹ:</td><td class="text-muted">Chưa có thông tin</td></tr>`;
            }
        }

        // ── Thứ 2: Vợ (nếu chưa có thì "Độc thân") ──
        let wifeHtml = '';
        if (m.gender === 'male') {
            wifeHtml = wife
                ? `<tr><td class="fw-bold">Vợ:</td><td>${wife.wife_name}</td></tr>`
                : `<tr><td class="fw-bold">Vợ:</td><td><span class="badge bg-secondary">Độc thân</span></td></tr>`;
        }

        // ── Thứ 3: Con (liệt kê tên tất cả) ──
        let childrenHtml = '';
        if (children.length > 0) {
            const childList = children.map(c => {
                const icon = c.child_gender === 'male' ? 'fa-mars text-primary' : 'fa-venus text-danger';
                return `<span class="badge bg-light text-dark border me-1 mb-1"><i class="fas ${icon} me-1"></i>${c.child_name}</span>`;
            }).join('');
            childrenHtml = `
                <div class="mt-3">
                    <h6 class="fw-bold">Các con (${children.length}):</h6>
                    <div>${childList}</div>
                </div>`;
        } else {
            childrenHtml = `
                <div class="mt-3">
                    <h6 class="fw-bold">Các con:</h6>
                    <p class="text-muted small mb-0">Chưa có con trong hệ thống</p>
                </div>`;
        }

        document.getElementById('memberDetails').innerHTML = `
            <div class="text-center mb-4">
                ${avatar}
                <h4>${m.full_name}</h4>
                <p class="text-muted mb-0">
                    Đời ${m.generation} — ${genderText}
                    ${isDeceased ? '<span class="badge bg-secondary ms-1"><i class="fas fa-cross me-1"></i>Đã mất</span>' : ''}
                </p>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-borderless table-sm">
                        <tr><td class="fw-bold">Ngày sinh:</td><td>${birthDate}</td></tr>
                        ${m.death_date
                            ? `<tr><td class="fw-bold">Ngày mất:</td><td>${deathDate} ${ageText}</td></tr>`
                            : isDeceased
                                ? `<tr><td class="fw-bold">Trạng thái:</td><td><span class="badge bg-secondary">Đã mất</span> ${ageText}</td></tr>`
                                : `<tr><td class="fw-bold">Tuổi:</td><td>${ageText || 'Chưa xác định'}</td></tr>`}
                        <tr><td class="fw-bold">Nơi sinh:</td><td>${m.birth_place || 'Chưa cập nhật'}</td></tr>
                        <tr><td class="fw-bold">Nghề nghiệp:</td><td>${m.occupation || 'Chưa cập nhật'}</td></tr>
                        ${parentsHtml}
                        ${wifeHtml}
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-borderless table-sm">
                        <tr><td class="fw-bold">Học vấn:</td><td>${m.education || 'Chưa cập nhật'}</td></tr>
                        <tr><td class="fw-bold">Điện thoại:</td><td>${m.phone || 'Chưa cập nhật'}</td></tr>
                        <tr><td class="fw-bold">An táng:</td><td>${m.address || 'Chưa cập nhật'}</td></tr>
                    </table>
                </div>
            </div>
            ${childrenHtml}
            ${m.biography ? `
            <div class="mt-2">
                <h6 class="fw-bold">Tiểu sử:</h6>
                <p>${m.biography.replace(/\n/g, '<br>')}</p>
            </div>` : ''}
        `;
        new bootstrap.Modal(document.getElementById('viewMemberModal')).show();
    }
</script>

<?php if (!empty($showInitRootModal)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('initRootModal')).show();
    });
</script>
<?php endif; ?>
</body>
</html>
