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

// Lấy thông tin gia tộc (lấy đầy đủ cột để biết parent_family_id)
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

// ── Thống kê cơ bản ──────────────────────────────────────────

// Tổng thành viên (gốc: cộng dồn cả cây con; chi nhánh: chỉ chính nó)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM members WHERE family_id IN ($statsPlaceholders)");
$stmt->execute($statsFamilyIds);
$total_members = (int) $stmt->fetchColumn();

// Nam / Nữ (cùng quy tắc trên)
$stmt = $pdo->prepare("SELECT gender, COUNT(*) AS cnt FROM members WHERE family_id IN ($statsPlaceholders) GROUP BY gender");
$stmt->execute($statsFamilyIds);
$male = 0; $female = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ($row['gender'] === 'male')   $male   = (int) $row['cnt'];
    if ($row['gender'] === 'female') $female = (int) $row['cnt'];
}

// Tổng con dâu (cùng quy tắc trên)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM daughters_in_law WHERE family_id IN ($statsPlaceholders)");
$stmt->execute($statsFamilyIds);
$total_daughters = (int) $stmt->fetchColumn();

// Tổng hôn nhân (chồng thuộc các gia tộc cần tính)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM marriages ma
    JOIN members h ON h.member_id = ma.husband_id
    WHERE h.family_id IN ($statsPlaceholders)
");
$stmt->execute($statsFamilyIds);
$total_marriages = (int) $stmt->fetchColumn();

// Tổng quan hệ cha-con (cùng quy tắc trên)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM relationships r
    JOIN members p ON p.member_id = r.parent_id
    WHERE p.family_id IN ($statsPlaceholders)
");
$stmt->execute($statsFamilyIds);
$total_relationships = (int) $stmt->fetchColumn();

// Số đời:
// - Gia tộc gốc: quy đổi generation của mọi chi/cháu/chắt về "khung đời" của cụ tổ gốc
//   (cộng thêm offset = split_from_generation - 1, lũy theo từng cấp cha) rồi đếm DISTINCT
// - Chi nhánh đã tách: chỉ đếm riêng generation của chính nó
if ($isRootFamily) {
    $stmtGen = $pdo->prepare("
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

// ── Phân bố theo thế hệ ──────────────────────────────────────
// - Gia tộc gốc: quy đổi generation theo offset rồi GROUP BY theo đời thật
// - Chi nhánh đã tách: chỉ tính riêng generation của chính nó
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
        FROM members WHERE family_id = ?
        GROUP BY generation ORDER BY generation
    ");
    $stmt->execute([$family_id]);
    $generations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Thành viên còn sống / đã mất ─────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN death_date IS NULL
                  AND (address IS NULL OR LTRIM(RTRIM(address)) = '')
                 THEN 1 ELSE 0 END) AS alive,
        SUM(CASE WHEN death_date IS NOT NULL
                  OR (address IS NOT NULL AND LTRIM(RTRIM(address)) <> '')
                 THEN 1 ELSE 0 END) AS deceased
    FROM members WHERE family_id IN ($statsPlaceholders)
");
$stmt->execute($statsFamilyIds);
$lifeStats = $stmt->fetch(PDO::FETCH_ASSOC);
$alive    = (int) ($lifeStats['alive']    ?? 0);
$deceased = (int) ($lifeStats['deceased'] ?? 0);

// ── Danh sách thành viên theo thế hệ (bảng chi tiết) ─────────
// - Gia tộc gốc: gồm cả chi/cháu/chắt, cột "generation" trả về là đời THẬT (đã cộng offset)
// - Chi nhánh đã tách: chỉ chính nó, generation giữ nguyên như cũ
if ($isRootFamily) {
    $stmt = $pdo->prepare("
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
        SELECT m.full_name, m.gender,
               (m.generation + fo.gen_offset) AS generation,
               m.birth_date, m.death_date,
               m.occupation, m.address,
               par.full_name AS father_name
        FROM members m
        INNER JOIN FamilyOffsets fo ON m.family_id = fo.family_id
        LEFT JOIN relationships r  ON r.child_id  = m.member_id AND r.relationship_type = 'father'
        LEFT JOIN members par      ON par.member_id = r.parent_id
        ORDER BY generation, m.full_name
        OPTION (MAXRECURSION 32767)
    ");
    $stmt->execute([$family_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT m.full_name, m.gender, m.generation, m.birth_date, m.death_date,
               m.occupation, m.address,
               par.full_name AS father_name
        FROM members m
        LEFT JOIN relationships r  ON r.child_id  = m.member_id AND r.relationship_type = 'father'
        LEFT JOIN members par      ON par.member_id = r.parent_id
        WHERE m.family_id = ?
        ORDER BY m.generation, m.full_name
    ");
    $stmt->execute([$family_id]);
}
$memberList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Tuổi trung bình (thành viên có ngày sinh) ─────────────────
$stmt = $pdo->prepare("
    SELECT AVG(
        DATEDIFF(year, birth_date,
            CASE WHEN death_date IS NOT NULL THEN death_date ELSE GETDATE() END)
    ) AS avg_age
    FROM members
    WHERE family_id IN ($statsPlaceholders)
      AND birth_date IS NOT NULL
      AND NOT (
          death_date IS NULL
          AND address IS NOT NULL
          AND LTRIM(RTRIM(address)) <> ''
      )
");
$stmt->execute($statsFamilyIds);
$avgAge = round((float) $stmt->fetchColumn(), 1);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thống kê - <?= htmlspecialchars($family['family_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { background: linear-gradient(180deg, #667eea 0%, #764ba2 100%); min-height: 100vh; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 1rem 1.5rem; border-radius: 10px; margin: 0.25rem 0; transition: all 0.3s ease; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: rgba(255,255,255,0.2); color: white; transform: translateX(5px); }
        .main-content { padding: 2rem; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        .stat-card { background: white; border-radius: 15px; padding: 1.5rem; box-shadow: 0 5px 15px rgba(0,0,0,0.08); transition: transform .3s; }
        .stat-card:hover { transform: translateY(-4px); }
        .stat-icon { width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; color: white; flex-shrink: 0; }
        .chart-box { background: white; border-radius: 15px; padding: 1.5rem; box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        .family-badge { background: linear-gradient(135deg,#667eea,#764ba2); color:white; border-radius:8px; padding:.3rem .8rem; font-size:.85rem; }
        .gen-badge { background:#e8ecff; color:#667eea; border-radius:5px; padding:.1rem .45rem; font-size:.75rem; font-weight:600; }
        .table th { font-weight: 600; color: #495057; }
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
            <li class="nav-item"><a class="nav-link" href="manage_relationships.php"><i class="fas fa-heart me-2"></i>Quản lý quan hệ</a></li>
            <li class="nav-item"><a class="nav-link active" href="statistics.php"><i class="fas fa-chart-bar me-2"></i>Thống kê</a></li>
            <li class="nav-item mt-3"><a class="nav-link" href="view_tree.php"><i class="fas fa-eye me-2"></i>Xem gia phả</a></li>
            <li class="nav-item"><a class="nav-link" href="../logout.php" onclick="return confirm('Bạn có chắc muốn đăng xuất?')"><i class="fas fa-sign-out-alt me-2"></i>Đăng xuất</a></li>
        </ul>
    </div>
</nav>

<!-- Main content -->
<main class="col-md-9 ms-sm-auto col-lg-10 main-content">
    <?= showMessage() ?>

    <div class="d-flex justify-content-between flex-wrap align-items-center pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2 mb-1">Thống kê gia phả</h1>
            <span class="family-badge">
                <i class="fas fa-home me-1"></i><?= htmlspecialchars($family['family_name']) ?>
            </span>
        </div>
    </div>

    <!-- ── Thẻ thống kê tổng quan ── -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 col-6 mb-3">
            <div class="stat-card text-center">
                <div class="stat-icon bg-primary mx-auto mb-2"><i class="fas fa-users"></i></div>
                <div class="fw-bold fs-4"><?= $total_members ?></div>
                <div class="text-muted small">Thành viên</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6 mb-3">
            <div class="stat-card text-center">
                <div class="stat-icon bg-info mx-auto mb-2"><i class="fas fa-female"></i></div>
                <div class="fw-bold fs-4"><?= $total_daughters ?></div>
                <div class="text-muted small">Con dâu</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6 mb-3">
            <div class="stat-card text-center">
                <div class="stat-icon bg-success mx-auto mb-2"><i class="fas fa-layer-group"></i></div>
                <div class="fw-bold fs-4"><?= $total_generations ?></div>
                <div class="text-muted small">Số đời</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6 mb-3">
            <div class="stat-card text-center">
                <div class="stat-icon bg-warning mx-auto mb-2"><i class="fas fa-ring"></i></div>
                <div class="fw-bold fs-4"><?= $total_marriages ?></div>
                <div class="text-muted small">Hôn nhân</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6 mb-3">
            <div class="stat-card text-center">
                <div class="stat-icon bg-secondary mx-auto mb-2"><i class="fas fa-heartbeat"></i></div>
                <div class="fw-bold fs-4"><?= $alive ?></div>
                <div class="text-muted small">Còn sống</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6 mb-3">
            <div class="stat-card text-center">
                <div class="stat-icon bg-danger mx-auto mb-2"><i class="fas fa-cross"></i></div>
                <div class="fw-bold fs-4"><?= $avgAge > 0 ? $avgAge : '—' ?></div>
                <div class="text-muted small">Tuổi TB<?= $avgAge > 0 ? '' : ' (chưa có)' ?></div>
            </div>
        </div>
    </div>

    <!-- ── Biểu đồ ── -->
    <div class="row mb-4">
        <!-- Giới tính -->
        <div class="col-xl-4 col-lg-5 mb-4">
            <div class="chart-box h-100">
                <h6 class="fw-bold mb-3"><i class="fas fa-sitemap"></i>Cơ cấu thành phần gia tộc</h6>
                <canvas id="genderChart"></canvas>
                <div class="mt-3 text-center text-muted" style="font-size:.85rem">
                    Nam: <strong><?= $male ?></strong> &nbsp;|&nbsp;
                    Nữ: <strong><?= $female ?></strong> &nbsp;|&nbsp;
                    Con dâu: <strong><?= $total_daughters ?></strong>
                </div>
            </div>
        </div>

        <!-- Theo thế hệ -->
        <div class="col-xl-8 col-lg-7 mb-4">
            <div class="chart-box h-100">
                <h6 class="fw-bold mb-3"><i class="fas fa-layer-group me-2 text-info"></i>Thành viên theo từng đời</h6>
                <canvas id="generationChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ── Còn sống / Đã mất ── -->
    <div class="row mb-4">
        <div class="col-xl-4 mb-4">
            <div class="chart-box h-100">
                <h6 class="fw-bold mb-3"><i class="fas fa-heartbeat me-2 text-success"></i>Còn sống / Đã mất</h6>
                <canvas id="lifeChart"></canvas>
                <div class="mt-3 text-center text-muted" style="font-size:.85rem">
                    Còn sống: <strong class="text-success"><?= $alive ?></strong> &nbsp;|&nbsp;
                    Đã mất: <strong class="text-danger"><?= $deceased ?></strong>
                </div>
            </div>
        </div>

        <!-- Bảng tóm tắt theo thế hệ -->
        <div class="col-xl-8 mb-4">
            <div class="chart-box h-100">
                <h6 class="fw-bold mb-3"><i class="fas fa-table me-2 text-warning"></i>Tóm tắt theo thế hệ</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Thế hệ</th>
                                <th>Số thành viên</th>
                                <th>Tỉ lệ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($generations as $gen): ?>
                            <tr>
                                <td><span class="gen-badge">Đời <?= $gen['generation'] ?></span></td>
                                <td><strong><?= $gen['count'] ?></strong></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height:6px;">
                                            <div class="progress-bar bg-primary"
                                                 style="width:<?= $total_members > 0 ? round($gen['count']/$total_members*100) : 0 ?>%">
                                            </div>
                                        </div>
                                        <small><?= $total_members > 0 ? round($gen['count']/$total_members*100) : 0 ?>%</small>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Danh sách chi tiết tất cả thành viên ── -->
    <div class="card mb-4">
        <div class="card-header fw-semibold">
            <i class="fas fa-list me-2"></i>Danh sách thành viên
            <span class="badge bg-primary ms-2"><?= count($memberList) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Họ tên</th>
                        <th>Đời</th>
                        <th>Giới tính</th>
                        <th>Ngày sinh</th>
                        <th>Ngày mất</th>
                        <th>Cha</th>
                        <th>Nghề nghiệp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($memberList)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">Chưa có thành viên nào</td></tr>
                    <?php endif; ?>
                    <?php foreach ($memberList as $i => $m): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><strong><?= htmlspecialchars($m['full_name']) ?></strong></td>
                        <td><span class="gen-badge">Đời <?= $m['generation'] ?></span></td>
                        <td>
                            <?php if ($m['gender'] === 'male'): ?>
                                <i class="fas fa-mars text-primary"></i> Nam
                            <?php else: ?>
                                <i class="fas fa-venus text-danger"></i> Nữ
                            <?php endif; ?>
                        </td>
                        <td><?= $m['birth_date'] ? date('d/m/Y', strtotime($m['birth_date'])) : '—' ?></td>
                        <?php $mIsDeceased = !empty($m['death_date']) || !empty(trim($m['address'] ?? '')); ?>
                        <td>
                            <?php if ($m['death_date']): ?>
                                <span class="text-danger"><?= date('d/m/Y', strtotime($m['death_date'])) ?></span>
                            <?php elseif ($mIsDeceased): ?>
                                <span class="text-secondary small"><i class="fas fa-cross me-1"></i>Đã mất</span>
                            <?php else: ?>
                                <span class="text-success small">Còn sống</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= $m['father_name'] ? htmlspecialchars($m['father_name']) : ($m['generation'] == 1 ? '<em>Cụ tổ</em>' : '—') ?></td>
                        <td class="text-muted"><?= $m['occupation'] ? htmlspecialchars($m['occupation']) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    // Biểu đồ thế hệ
    const genData = <?= json_encode($generations) ?>;
    new Chart(document.getElementById('generationChart'), {
        type: 'bar',
        data: {
            labels: genData.map(g => `Đời ${g.generation}`),
            datasets: [{
                label: 'Số thành viên',
                data: genData.map(g => g.count),
                backgroundColor: '#667eea',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });

    // Biểu đồ còn sống / đã mất
    new Chart(document.getElementById('lifeChart'), {
        type: 'doughnut',
        data: {
            labels: ['Còn sống', 'Đã mất'],
            datasets: [{
                data: [<?= $alive ?>, <?= $deceased ?>],
                backgroundColor: ['#4BC0C0', '#FF6384'],
                borderWidth: 0
            }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
</script>
</body>
</html>
