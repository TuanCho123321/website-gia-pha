<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../login.php', 'Bạn không có quyền truy cập!', 'error');
}

// Lấy family_id từ URL hoặc session
if (isset($_GET['family_id'])) { 
    $_SESSION['current_family_id'] = (int) $_GET['family_id']; 
} 

$family_id = $_SESSION['current_family_id'] ?? null; 

$stmtF = $pdo->prepare("SELECT * FROM families WHERE family_id = ?");
$stmtF->execute([$family_id]);
$family = $stmtF->fetch();

if (!$family_id) {
    redirect('manage_family.php', 'Vui lòng chọn gia tộc trước!', 'error'); 
}

// Lấy thông tin gia tộc
$stmtF = $pdo->prepare("SELECT * FROM families WHERE family_id = ?");
$stmtF->execute([$family_id]);
$family = $stmtF->fetch();

if (!$family) {
    redirect('manage_family.php', 'Gia tộc không tồn tại!', 'error');
}
requireActiveFamilySubscription((int) $family_id, $pdo);
// Xác định đây là gia tộc gốc hay chi nhánh đã tách
$isRootFamily = empty($family['parent_family_id']);

// Danh sách family_id cần tính thống kê:
// - Gia tộc gốc: chính nó + toàn bộ chi/cháu/chắt (đệ quy nhiều cấp)
// - Chi nhánh đã tách: chỉ chính nó
if ($isRootFamily) {
    $stmtIds = $pdo->prepare("
        WITH FamilySubtree AS (
            SELECT family_id FROM families WHERE family_id = ?
            UNION ALL
            SELECT f.family_id
            FROM families f
            INNER JOIN FamilySubtree fs ON f.parent_family_id = fs.family_id
        )
        SELECT family_id FROM FamilySubtree
        OPTION (MAXRECURSION 32767)
    ");
    $stmtIds->execute([$family_id]);
    $statsFamilyIds = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
} else {
    $statsFamilyIds = [$family_id];
}

$statsPlaceholders = implode(',', array_fill(0, count($statsFamilyIds), '?'));

// ── Thống kê riêng cho gia tộc này ──────────────────────────

// Tổng thành viên (gốc: cộng dồn cả cây con; chi nhánh: chỉ chính nó)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM members WHERE family_id IN ($statsPlaceholders)");
$stmt->execute($statsFamilyIds);
$total_members = (int) $stmt->fetchColumn();

// Tổng con dâu (cùng quy tắc trên)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM daughters_in_law WHERE family_id IN ($statsPlaceholders)");
$stmt->execute($statsFamilyIds);
$total_daughters = (int) $stmt->fetchColumn();

// Số đời:
// - Gia tộc gốc: quy đổi generation của mọi chi/cháu/chắt về "khung đời" của cụ tổ gốc
//   (cộng thêm offset = split_from_generation - 1, lũy theo từng cấp cha) rồi đếm DISTINCT
// - Chi nhánh đã tách: chỉ đếm riêng generation của chính nó (giữ như cũ)
if ($isRootFamily) {
    $stmtGen = $pdo->prepare("
        WITH FamilyOffsets AS (
            -- Gia tộc gốc: offset = 0 (khung đời chuẩn)
            SELECT family_id, 0 AS gen_offset
            FROM families
            WHERE family_id = ?

            UNION ALL

            -- Mỗi chi nhánh: offset = offset(cha) + (split_from_generation - 1)
            SELECT
                f.family_id,
                fo.gen_offset + (f.split_from_generation - 1) AS gen_offset
            FROM families f
            INNER JOIN FamilyOffsets fo ON f.parent_family_id = fo.family_id
        )
        SELECT COUNT(DISTINCT (m.generation + fo.gen_offset)) AS total_generations
        FROM members m
        INNER JOIN FamilyOffsets fo ON m.family_id = fo.family_id
        OPTION (MAXRECURSION 32767)
    ");
    $stmtGen->execute([$family_id]);
    $total_generations = (int) $stmtGen->fetchColumn();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT generation) FROM members WHERE family_id = ?");
    $stmt->execute([$family_id]);
    $total_generations = (int) $stmt->fetchColumn();
}

// Số hôn nhân (chồng thuộc các gia tộc cần tính)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM marriages m
    JOIN members h ON m.husband_id = h.member_id
    WHERE h.family_id IN ($statsPlaceholders)
");
$stmt->execute($statsFamilyIds);
$total_marriages = (int) $stmt->fetchColumn();

// Phân bố giới tính (cùng quy tắc cộng dồn)
$stmt = $pdo->prepare("SELECT gender, COUNT(*) AS cnt FROM members WHERE family_id IN ($statsPlaceholders) GROUP BY gender");
$stmt->execute($statsFamilyIds);
$genderRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$male = 0; $female = 0;
foreach ($genderRows as $row) {
    if ($row['gender'] === 'male') $male   = (int) $row['cnt'];
    if ($row['gender'] === 'female')  $female = (int) $row['cnt'];
}

// Phân bố theo thế hệ:
// - Gia tộc gốc: quy đổi generation của mọi chi/cháu/chắt về "khung đời" của cụ tổ gốc
//   (cùng công thức offset đã dùng ở total_generations) rồi GROUP BY theo đời thật
// - Chi nhánh đã tách: chỉ tính riêng generation của chính nó (giữ như cũ)
if ($isRootFamily) {
    $stmtGenDist = $pdo->prepare("
        WITH FamilyOffsets AS (
            SELECT family_id, 0 AS gen_offset
            FROM families
            WHERE family_id = ?

            UNION ALL

            SELECT
                f.family_id,
                fo.gen_offset + (f.split_from_generation - 1) AS gen_offset
            FROM families f
            INNER JOIN FamilyOffsets fo ON f.parent_family_id = fo.family_id
        )
        SELECT
            (m.generation + fo.gen_offset) AS generation,
            COUNT(*) AS count
        FROM members m
        INNER JOIN FamilyOffsets fo ON m.family_id = fo.family_id
        GROUP BY (m.generation + fo.gen_offset)
        ORDER BY generation ASC
        OPTION (MAXRECURSION 32767)
    ");
    $stmtGenDist->execute([$family_id]);
    $generations = $stmtGenDist->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT generation, COUNT(*) AS count
        FROM members
        WHERE family_id = ?
        GROUP BY generation
        ORDER BY generation ASC
    ");
    $stmt->execute([$family_id]);
    $generations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Cụ tổ (generation = 1)
$stmt = $pdo->prepare("SELECT TOP 1 full_name, birth_date, death_date FROM members WHERE family_id = ? AND generation = 1 ORDER BY member_id ASC");
$stmt->execute([$family_id]);
$ancestor = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= htmlspecialchars($family['family_name']) ?></title>
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
        .main-content { padding: 2rem; }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
            transition: transform 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon {
            width: 60px; height: 60px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; color: white; flex-shrink: 0;
        }
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-top: 1.5rem;
        }
        .family-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 1.5rem 2rem;
            color: white;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }
        @media (max-width: 768px) {
            .sidebar { min-height: auto; }
            .main-content { padding: 1rem; }
        }
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
                        <a class="nav-link" href="manage_family.php">
                            <i class="fas fa-home me-2"></i>Quản lý gia tộc
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_members.php">
                            <i class="fas fa-user-friends me-2"></i>Quản lý thành viên
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_daughter_in_law.php">
                            <i class="fas fa-user-friends me-2"></i>Quản lý con dâu
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_relationships.php">
                            <i class="fas fa-heart me-2"></i>Quản lý quan hệ
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="statistics.php">
                            <i class="fas fa-chart-bar me-2"></i>Thống kê
                        </a>
                    </li>
                    <li class="nav-item mt-3">
                        <a class="nav-link" href="view_tree.php">
                            <i class="fas fa-eye me-2"></i>Xem gia phả
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php"
                           onclick="return confirm('Bạn có chắc muốn đăng xuất?')">
                            <i class="fas fa-sign-out-alt me-2"></i>Đăng xuất
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Main content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 main-content">
            <?= showMessage() ?>

            <!-- Banner tên gia tộc -->
            <div class="family-banner d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h4 class="mb-1 fw-bold">
                        <i class="fas fa-home me-2"></i><?= htmlspecialchars($family['family_name']) ?>
                    </h4>
                    <?php if ($family['description']): ?>
                        <small class="opacity-75"><?= htmlspecialchars($family['description']) ?></small>
                    <?php endif; ?>
                    <?php if ($ancestor): ?>
                        <div class="mt-1">
                            <small class="opacity-75">
                                <i class="fas fa-user me-1"></i>Cụ tổ:
                                <strong><?= htmlspecialchars($ancestor['full_name']) ?></strong>
                                <?php if ($ancestor['birth_date']): ?>
                                    (<?= date('d/m/Y', strtotime($ancestor['birth_date'])) ?>
                                    <?= $ancestor['death_date'] ? ' – ' . date('d/m/Y', strtotime($ancestor['death_date'])) : '' ?>)
                                <?php endif; ?>
                            </small>
                        </div>
                    <?php else: ?>
                        <div class="mt-1">
                            <small class="opacity-75"><i class="fas fa-exclamation-triangle me-1"></i>Chưa có cụ tổ (đời 1)</small>
                        </div>
                    <?php endif; ?>
                </div>
                <a href="manage_family.php" class="btn btn-light btn-sm">
                    <i class="fas fa-exchange-alt me-1"></i>Đổi gia tộc
                </a>
            </div>

            <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
                <h1 class="h2">Dashboard</h1>
                <button type="button" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-calendar me-1"></i><?= date('d/m/Y') ?>
                </button>
            </div>

            <!-- Thống kê tổng quan -->
            <div class="row">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="ms-3">
                                <div class="text-uppercase fw-bold text-primary mb-1" style="font-size:.75rem">Thành viên</div>
                                <div class="h5 mb-0 fw-bold"><?= $total_members ?></div>
                                <small class="text-muted">+ <?= $total_daughters ?> con dâu</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <div class="ms-3">
                                <div class="text-uppercase fw-bold text-success mb-1" style="font-size:.75rem">Số đời</div>
                                <div class="h5 mb-0 fw-bold"><?= $total_generations ?></div>
                                <small class="text-muted">thế hệ trong gia phả</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-warning">
                                <i class="fas fa-ring"></i>
                            </div>
                            <div class="ms-3">
                                <div class="text-uppercase fw-bold text-warning mb-1" style="font-size:.75rem">Hôn nhân</div>
                                <div class="h5 mb-0 fw-bold"><?= $total_marriages ?></div>
                                <small class="text-muted">cặp đã kết hôn</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-info">
                                <i class="fas fa-venus-mars"></i>
                            </div>
                            <div class="ms-3">
                                <div class="text-uppercase fw-bold text-info mb-1" style="font-size:.75rem">Giới tính</div>
                                <div class="h5 mb-0 fw-bold"><?= $male ?> / <?= $female ?></div>
                                <small class="text-muted">Nam / Nữ</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Biểu đồ -->
            <div class="row">
                <div class="col-xl-4 col-lg-5 mb-4">
                    <div class="chart-container h-100">
                        <h6 class="fw-bold mb-4">
                            <i class="fas fa-sitemap"></i>Cơ cấu thành phần gia tộc
                        </h6>
                        <canvas id="genderChart"></canvas>
                        <div class="mt-3 text-center text-muted" style="font-size:.85rem">
                            Nam: <strong><?= $male ?></strong> &nbsp;|&nbsp;
                            Nữ: <strong><?= $female ?></strong> &nbsp;|&nbsp;
                            Con dâu: <strong><?= $total_daughters ?></strong>
                        </div>
                    </div>
                </div>

                <div class="col-xl-8 col-lg-7 mb-4">
                    <div class="chart-container h-100">
                        <h6 class="fw-bold mb-4">
                            <i class="fas fa-layer-group me-2 text-info"></i>Số thành viên theo từng đời
                        </h6>
                        <canvas id="generationChart"></canvas>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Biểu đồ giới tính
    new Chart(document.getElementById('genderChart'), {
        type: 'doughnut',
        data: {
            labels: ['Nam', 'Nữ', 'Con dâu'],
            datasets: [{
                data: [<?= $male ?>, <?= $female ?>, <?= $total_daughters ?>],
                backgroundColor: ['#36A2EB', '#FF6384', '#FFCE56'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } }
        }
    });

    // Biểu đồ thế hệ
    const generationData = <?= json_encode($generations) ?>;
    new Chart(document.getElementById('generationChart'), {
        type: 'bar',
        data: {
            labels: generationData.map(item => `Đời ${item.generation}`),
            datasets: [{
                label: 'Số thành viên',
                data: generationData.map(item => item.count),
                backgroundColor: '#667eea',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
</script>
</body>
</html>
