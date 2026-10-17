<?php
// 14. user/search.php (FULL code)
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (!isLoggedIn()) {
    redirect('../login.php', 'Vui lòng đăng nhập!', 'error');
}

// Lấy family_id từ session
$family_id = $_SESSION['current_family_id'] ?? null;
if (!$family_id) {
    redirect('manage_family.php', 'Vui lòng chọn gia tộc trước!', 'error');
}

$search = trim($_GET['search'] ?? '');
$members = [];

// Lấy toàn bộ family_id thuộc cây con của gia tộc hiện tại (đệ quy theo parent_family_id)
// Bao gồm chính gia tộc hiện tại + mọi nhánh đã tách ra (nhiều cấp)
$stmtTree = $pdo->prepare("
    WITH FamilyTree AS (
        SELECT family_id, family_name FROM families WHERE family_id = ?
        UNION ALL
        SELECT f.family_id, f.family_name
        FROM families f
        INNER JOIN FamilyTree ft ON f.parent_family_id = ft.family_id
    )
    SELECT family_id, family_name FROM FamilyTree
");
$stmtTree->execute([$family_id]);
$familyTreeRows = $stmtTree->fetchAll(PDO::FETCH_ASSOC);

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

$sql .= " ORDER BY generation, full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$members = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tìm kiếm thành viên</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { background: linear-gradient(180deg, #667eea 0%, #764ba2 100%); min-height: 100vh; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 1rem 1.5rem; border-radius: 10px; margin: 0.25rem 0; transition: all 0.3s ease; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: rgba(255,255,255,0.2); color: white; transform: translateX(5px); }
        .main-content { padding: 2rem; }
        .member-card { transition: transform 0.2s; }
        .member-card:hover { transform: translateY(-5px); }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <h4 class="text-white"><i class="fas fa-sitemap me-2"></i>Gia Phả Gia Đình</h4>
                        <small class="text-white-50">Xin chào, <?= htmlspecialchars($_SESSION['full_name']) ?></small>
                    </div>
                    <ul class="nav flex-column">
                        <li class="nav-item"><a class="nav-link" href="manage_family.php"><i class="fas fa-home me-2"></i>Quản lý gia tộc</a></li>
                        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-home me-3"></i>Trang chủ</a></li>
                        <li class="nav-item"><a class="nav-link" href="view_tree.php"><i class="fas fa-tree me-3"></i>Cây Gia Phả</a></li>
                        <li class="nav-item"><a class="nav-link" href="search.php"><i class="fas fa-search me-3"></i>Tìm kiếm thành viên</a></li>
                        <li class="nav-item"><a class="nav-link" href="register_service_packages.php"><i class="fas fa-box-open me-3"></i>Đăng ký gói dịch vụ</a></li>
                        <li class="nav-item"><a class="nav-link" href="contact.php"><i class="fas fa-address-card me-3"></i>Liên hệ</a></li>
                        <li class="nav-item"><a class="nav-link" href="profile.php"><i class="fas fa-user-circle me-3"></i>Hồ sơ cá nhân</a></li>
                        <li class="nav-item mt-5"><a class="nav-link text-danger" href="../logout.php" onclick="return confirm('Bạn thực sự muốn đăng xuất?')"><i class="fas fa-sign-out-alt me-3"></i>Đăng xuất</a></li>
                    </ul>
                </div>
            </nav>
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content">
                <h1 class="h2 mb-1">Tìm kiếm thành viên</h1>
                <p class="text-muted small mb-4">
                    <i class="fas fa-info-circle me-1"></i>Tìm kiếm bao gồm cả các nhánh đã tách ra
                </p>
                <form method="GET" class="mb-4">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" placeholder="Tên thành viên hoặc năm sinh..." value="<?= htmlspecialchars($search) ?>">
                        <button class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </div>
                </form>
                
                <div class="row">
                        <?php if ($members): foreach ($members as $member): ?>
                            <?php $isOtherBranch = ($member['family_id'] != $family_id); ?>
                            <div class="col-md-4 mb-4">
                                <div class="card member-card">
                                    <div class="card-body text-center">
                                        <?php if ($member['avatar']): ?>
                                            <img src="../<?= htmlspecialchars($member['avatar']) ?>" class="rounded-circle mb-3" width="80" height="80" alt="">
                                        <?php else: ?>
                                            <i class="fas fa-user-circle fa-5x text-muted mb-3"></i>
                                        <?php endif; ?>
                                        <?php if ($isOtherBranch): ?>
                                            <div class="mb-1">
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-code-branch me-1"></i><?= htmlspecialchars($familyNames[$member['family_id']] ?? 'Chi khác') ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <h5><?= htmlspecialchars($member['full_name']) ?></h5>
                                        <p class="text-muted">Đời <?= $member['generation'] ?> • <?= $member['gender'] == 'male' ? 'Nam' : 'Nữ' ?></p>
                                        <?php if ($member['birth_date']): ?>
                                            <small><?= date('d/m/Y', strtotime($member['birth_date'])) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; else: ?>
                        <div class="col-12 text-center py-5">
                            <i class="fas fa-search fa-3x text-muted"></i>
                            <h5>Không tìm thấy thành viên nào</h5>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
