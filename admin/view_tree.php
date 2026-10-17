<?php
// admin2/view_tree.php - PHIÊN BẢN HOÀN CHỈNH
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
if (!function_exists('buildFamilyTreeHTML')) {
    die('KHÔNG LOAD ĐƯỢC functions.php');
}
if (!isLoggedIn()) {
    redirect('../login.php', 'Vui lòng đăng nhập!', 'error');
}
// Lấy family_id từ URL hoặc session
$family_id = $_SESSION['current_family_id'] ?? null;

// Ví dụ trong dashboard.php hoặc bất kỳ trang nào set current_family_id
$requestedFamilyId = (int) ($_GET['family_id'] ?? 0);

if ($requestedFamilyId) {
    requireAdminFamilyAccess($requestedFamilyId, $pdo, 'dashboard.php');
    $_SESSION['current_family_id'] = $requestedFamilyId;
    $family_id = $requestedFamilyId;
}

if (!$family_id) {
    redirect('manage_family.php', 'Vui lòng chọn gia tộc trước!', 'error');
}
requireAdminFamilyAccess((int) $family_id, $pdo);
requireActiveFamilySubscription((int) $family_id, $pdo);

$familySplitInfo = getFamilySplitInfo($family_id);

$root_id = $_GET['root'] ?? null;

if (!$root_id) {

    $stmt = $pdo->prepare("
        SELECT TOP 1 member_id
        FROM members
        WHERE family_id = ?
        ORDER BY generation ASC, member_id ASC
    ");

    $stmt->execute([$family_id]);

    $root_id = $stmt->fetchColumn();

    if (!$root_id) {
        $root_id = 0;
    }
}

// kiểm tra root thuộc gia tộc hiện tại
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM members
    WHERE member_id = ?
    AND family_id = ?
");

$stmt->execute([$root_id, $family_id]);

if (!$stmt->fetchColumn()) {
    redirect(
        'dashboard.php',
        'Thành viên không thuộc gia tộc hiện tại!',
        'error'
    );
}

// Lấy thông tin người được chọn (để hiển thị trên thanh tìm kiếm)
$stmtRoot = $pdo->prepare("SELECT member_id, full_name, generation FROM members WHERE member_id = ?");
$stmtRoot->execute([$root_id]);
$rootMember = $stmtRoot->fetch();

// Lấy danh sách thành viên cho autocomplete
$stmt = $pdo->prepare("
    SELECT
        m.member_id,
        m.full_name,
        m.generation,
        0 AS is_split_root
    FROM members m
    WHERE m.family_id = ?

    UNION

    SELECT
        m.member_id,
        m.full_name,
        f.split_from_generation,
        1 AS is_split_root
    FROM families f
    INNER JOIN members m
        ON m.member_id = f.split_from_member_id
    WHERE f.parent_family_id = ?

    ORDER BY generation, full_name
");
$stmt->execute([$family_id, $family_id]);
$membersForSelect = $stmt->fetchAll();

// Luôn lấy cụ tổ (đời 1) làm gốc để hiển thị toàn bộ cây
if (!empty($familySplitInfo['split_from_member_id'])) {
    $ancestorId = (int) $familySplitInfo['split_from_member_id'];
} else {
    $stmtAncestor = $pdo->prepare("
        SELECT TOP 1 member_id
        FROM members
        WHERE family_id = ? AND generation = 1
        ORDER BY member_id ASC
    ");
    $stmtAncestor->execute([$family_id]);
    $ancestorId = $stmtAncestor->fetchColumn() ?: $root_id;
}

// Dựng cây từ cụ tổ
$treeHTML = buildFamilyTreeHTML($ancestorId, $family_id);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cây Gia Phả - Hiển thị dạng cây</title>
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

        /* CSS cho cây gia phả */
        /* ===== CÂY GIA PHẢ ===== */
        .family-tree {
            background: white;
            border-radius: 20px;
            padding: 0;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            min-height: 600px;
            height: 75vh;
            position: relative;
            cursor: grab;
            user-select: none;
        }
        .family-tree.dragging { cursor: grabbing; }

        /* Lớp canvas chứa toàn bộ cây, được pan/zoom bằng transform */
        .tree-canvas {
            position: absolute;
            top: 0;
            left: 0;
            padding: 40px;
            transform-origin: 0 0;
            will-change: transform;
        }

        /* Nút điều khiển zoom */
        .tree-controls {
            position: absolute;
            bottom: 16px;
            right: 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            z-index: 50;
        }
        .tree-controls button {
            width: 40px; height: 40px;
            border-radius: 50%;
            border: none;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            font-size: 1rem;
            color: #667eea;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.15s;
        }
        .tree-controls button:hover { background: #f0f4ff; }

        .zoom-level-badge {
            position: absolute;
            bottom: 16px;
            left: 16px;
            background: rgba(255,255,255,0.9);
            padding: 4px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            color: #667eea;
            font-weight: 700;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            z-index: 50;
        }

        .pan-hint {
            position: absolute;
            top: 14px;
            left: 16px;
            background: rgba(255,255,255,0.9);
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 0.75rem;
            color: #888;
            z-index: 50;
            pointer-events: none;
        }

        /* Node chứa 1 thành viên + vợ/chồng + con */
        .tree-node {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            padding-top: 0;
        }

        /* Card thành viên chính */
        .person-card {
            background: #fff;
            border: 3px solid #667eea;
            border-radius: 15px;
            padding: 12px;
            text-align: center;
            width: 160px;
            box-shadow: 0 5px 15px rgba(102,126,234,0.2);
            position: relative;
            z-index: 1;
        }
        .person-card.female {
            border-color: #e91e8c;
            box-shadow: 0 5px 15px rgba(233,30,140,0.2);
        }

        /* Avatar */
        .tree-avatar {
            width: 65px; height: 65px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 3px 8px rgba(0,0,0,0.2);
        }
        .tree-avatar-placeholder {
            width: 65px; height: 65px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; color: #fff;
            margin: 0 auto;
        }
        .tree-avatar-placeholder.female {
            background: linear-gradient(135deg, #e91e8c, #f06292);
        }
        .person-info h5 { margin: 8px 0 3px; font-size: 0.95rem; font-weight: 700; }
        .person-info small { color: #888; font-size: 0.78rem; }

        /* ===== COUPLE ROW: thành viên chính + vợ/chồng nằm ngang ===== */
        .couple-wrapper {
            display: flex;
            align-items: center;
            gap: 0;
            position: relative;
        }

        /* Đường nối đôi vợ chồng (nét đứt) */
        .marriage-line {
            width: 40px;
            height: 3px;
            background: repeating-linear-gradient(
                90deg,
                #e91e8c 0px, #e91e8c 6px,
                transparent 6px, transparent 12px
            );
            position: relative;
            flex-shrink: 0;
        }
        /* Tim nhỏ ở giữa đường nối vợ chồng */
        .marriage-line::after {
            content: '♥';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #e91e8c;
            font-size: 12px;
            background: white;
            padding: 0 2px;
        }

        /* Card vợ/chồng */
        .spouse-card {
            background: #fff8fc;
            border: 2px dashed #e91e8c;
            border-radius: 12px;
            padding: 8px;
            text-align: center;
            width: 130px;
            box-shadow: 0 3px 8px rgba(233,30,140,0.1);
        }
        .spouse-avatar {
            width: 50px; height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }
        .spouse-avatar-placeholder {
            width: 50px; height: 50px;
            background: linear-gradient(135deg, #e91e8c, #f06292);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: #fff;
            margin: 0 auto;
        }
        .spouse-card small { display: block; margin-top: 5px; font-size: 0.78rem; color: #555; }

        /* ===== ĐƯỜNG NỐI CHA/MẸ → CON ===== */
        .children-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            margin-top: 0;
            padding-top: 0;
            width: 100%;
        }

        /* Đường thẳng đứng từ cha/mẹ xuống thanh ngang */
        .connector-vertical-top {
            width: 3px;
            height: 30px;
            background: #667eea;
            position: relative;
        }

        /* Thanh ngang nối các con — vị trí/độ rộng được JS tính chính xác theo vị trí thật của từng con,
        không còn dùng align-self:stretch + margin đoán mò (gây ra đường thừa khi các nhánh lệch rộng) */
        .connector-horizontal {
            height: 3px;
            background: #667eea;
            position: absolute;
            top: 30px;   /* canh đúng điểm kết thúc của .connector-vertical-top (cao 30px) phía trên */
            left: 0;     /* JS sẽ set lại bằng inline style */
            width: 0;    /* JS sẽ set lại bằng inline style */
            margin: 0;
        }

        /* Hàng các con */
        .children-row {
            display: flex;
            justify-content: center;
            gap: 40px;
            position: relative;
        }

        /* Mỗi child node có đường thẳng đứng từ thanh ngang xuống card */
        .child-node {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }
        /* Đường đứng từ thanh ngang xuống card của con */
        .child-node::before {
            content: '';
            display: block;
            width: 3px;
            height: 30px;
            background: #667eea;
            flex-shrink: 0;
        }
        /* Chấm tròn nhỏ tại điểm giao */
        .child-node::after {
            content: '';
            position: absolute;
            top: 28px; /* ngay đầu đường đứng */
            left: 50%;
            transform: translateX(-50%);
            width: 9px; height: 9px;
            background: #667eea;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 0 0 1px #667eea;
            z-index: 2;
        }
        /* Highlight card được tìm kiếm */
        .person-card.highlighted {
            border-color: #f5a623;
            box-shadow: 0 0 0 4px rgba(245,166,35,0.35), 0 5px 20px rgba(245,166,35,0.4);
            animation: pulseHighlight 1.5s ease-in-out 3;
        }
        @keyframes pulseHighlight {
            0%   { box-shadow: 0 0 0 4px rgba(245,166,35,0.35), 0 5px 20px rgba(245,166,35,0.4); }
            50%  { box-shadow: 0 0 0 8px rgba(245,166,35,0.15), 0 5px 30px rgba(245,166,35,0.6); }
            100% { box-shadow: 0 0 0 4px rgba(245,166,35,0.35), 0 5px 20px rgba(245,166,35,0.4); }
        }
        /* Autocomplete */
        .suggestion-item {
            padding: 10px 16px;
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
        #memberSearch:focus { box-shadow: 0 0 0 0.2rem rgba(102,126,234,0.25); border-color: #667eea; }
        .split-info-band {
            background: #fff7e6;
            border: 1px solid #ffd88a;
            border-left: 5px solid #f5a623;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 1rem;
        }
        .split-root-badge,
        .split-branch-meta {
            margin-top: 6px;
            display: inline-block;
            background: #fff3cd;
            color: #8a5a00;
            border-radius: 6px;
            padding: 2px 7px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .split-out-badge { background: #e8f5e9; color: #237a3b; }
        .split-branch-card {
            border-color: #22a06b;
            border-style: dashed;
            background: #f7fffb;
        }
        .split-branch-avatar { background: linear-gradient(135deg, #22a06b, #54c392); }
        .split-branch-link {
            display: inline-block;
            margin-top: 7px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #1769aa;
            text-decoration: none;
        }
        .split-branch-link:hover { text-decoration: underline; }
        .split-branch-card.highlighted {
            border: 3px solid #f5a623;
            box-shadow:
                0 0 0 4px rgba(245,166,35,.35),
                0 5px 20px rgba(245,166,35,.4);

            animation: pulseHighlight 1.5s ease-in-out 3;
        }
        /* Highlight nhánh từ cụ tổ đến người tìm được */
        .person-card.path-highlight,
        .spouse-card.path-highlight {
            border-color: #f5a623 !important;
            box-shadow: 0 0 0 3px rgba(245,166,35,0.3), 0 4px 12px rgba(245,166,35,0.35);
        }
        .connector-vertical-top.path-highlight,
        .connector-horizontal.path-highlight {
            background: #f5a623 !important;
        }
        .child-node.path-highlight::before {
            background: #f5a623 !important;
        }
        .child-node.path-highlight::after {
            background: #f5a623 !important;
            box-shadow: 0 0 0 1px #f5a623;
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
                        <h4 class="text-white"><i class="fas fa-sitemap me-2"></i>Gia Phả Gia Đình</h4>
                        <small class="text-white-50">Xin chào, <?= htmlspecialchars($_SESSION['full_name']) ?></small>
                    </div>
                    <ul class="nav flex-column">
                        <li class="nav-item"><a class="nav-link" href="manage_family.php"><i class="fas fa-home me-2"></i>Quản lý gia tộc</a></li>                      
                        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="manage_members.php"><i class="fas fa-user-friends me-2"></i>Quản lý thành viên</a></li>
                        <li class="nav-item"><a class="nav-link" href="manage_daughter_in_law.php"><i class="fas fa-user-friends me-2"></i>Quản lý con dâu</a></li>
                        <li class="nav-item"><a class="nav-link" href="manage_relationships.php"><i class="fas fa-heart me-2"></i>Quản lý quan hệ</a></li>
                        <li class="nav-item"><a class="nav-link" href="statistics.php"><i class="fas fa-chart-bar me-2"></i>Thống kê</a></li>
                        <li class="nav-item mt-3"><a class="nav-link active" href="view_tree.php"><i class="fas fa-eye me-2"></i>Xem gia phả</a></li>                        
                        <li class="nav-item mt-3"><a class="nav-link" href="../logout.php" onclick="return confirm('Bạn có chắc muốn đăng xuất?')"><i class="fas fa-sign-out-alt me-2"></i>Đăng xuất</a></li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content">
                <?= showMessage() ?>

                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h1 class="h2 mb-0">Cây Gia Phả Gia Đình</h1>
                    <button onclick="exportPDF()" class="btn btn-success">
                        <i class="fas fa-file-pdf me-2"></i>Xuất PDF A0
                    </button>
                </div>

                <!-- Thanh tìm kiếm -->
                <?php if (!empty($familySplitInfo['parent_family_id'])): ?>
                <div class="split-info-band d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="fw-semibold">
                            <i class="fas fa-code-branch me-1"></i>
                            Gia tộc này được tách từ <?= htmlspecialchars($familySplitInfo['parent_family_name'] ?? 'gia tộc gốc') ?>
                        </div>
                        <div class="small text-muted">
                            Người khai chi: <?= htmlspecialchars($familySplitInfo['split_member_name'] ?? 'Chưa xác định') ?>
                            • Trước đây là đời <?= (int) $familySplitInfo['split_from_generation'] ?>
                        </div>
                    </div>
                    <?php if (!empty($familySplitInfo['parent_family_id']) 
                            && adminCanManageFamily((int)$familySplitInfo['parent_family_id'], $pdo)): ?>
                    <a class="btn btn-outline-primary btn-sm" href="dashboard.php?family_id=<?= (int) $familySplitInfo['parent_family_id'] ?>">
                        <i class="fas fa-arrow-left me-1"></i>Về gia tộc gốc
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="card mb-4 shadow-sm">
                    <div class="card-body py-3">
                        <div class="d-flex gap-2 align-items-center">
                            <div class="position-relative flex-grow-1">
                                <input type="text" id="memberSearch"
                                       class="form-control form-control-lg"
                                       placeholder="Nhập tên thành viên để tìm kiếm..."
                                       autocomplete="off">
                                <input type="hidden" id="selectedMemberId" value="">
                                <!-- Dropdown gợi ý -->
                                <div id="searchSuggestions"
                                     class="position-absolute w-100 bg-white border rounded-3 shadow-lg mt-1"
                                     style="display:none; z-index:9999; max-height:280px; overflow-y:auto;">
                                </div>
                            </div>
                            <button class="btn btn-primary btn-lg px-4" onclick="doSearch()">
                                <i class="fas fa-search me-1"></i>Tìm
                            </button>
                        </div>
                    </div>
                </div>

                <div class="family-tree" id="familyTree">
                    <?php if ($treeHTML): ?>
                        <div class="tree-canvas" id="treeCanvas">
                            <?= $treeHTML ?>
                        </div>
                        <div class="tree-controls">
                            <button type="button" onclick="zoomIn()" title="Phóng to"><i class="fas fa-plus"></i></button>
                            <button type="button" onclick="zoomOut()" title="Thu nhỏ"><i class="fas fa-minus"></i></button>
                            <button type="button" onclick="resetView()" title="Về mặc định"><i class="fas fa-compress"></i></button>
                        </div>
                        <div class="zoom-level-badge" id="zoomLevelBadge">100%</div>
                        <div class="pan-hint">
                            <i class="fas fa-hand-pointer me-1"></i>Kéo để di chuyển • Ctrl + cuộn để zoom
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-users fa-4x text-muted mb-3"></i>
                            <h4>Chưa có dữ liệu gia phả</h4>
                            <p>Hãy vào Admin → Quản lý thành viên để thêm người đầu tiên.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <script>
    async function exportPDF() {

        alert("Đang tạo PDF A0, vui lòng chờ...");

        const { jsPDF } = window.jspdf;

        // Lấy lớp canvas chứa toàn bộ cây (không phải khung viewport bị overflow:hidden)
        const canvasEl = document.getElementById('treeCanvas');
        if (!canvasEl) { alert('Không có dữ liệu cây để xuất PDF!'); return; }

        // Tạm reset pan/zoom hiện tại để chụp đúng toàn bộ cây không bị lệch/scale
        const prevTransform = canvasEl.style.transform;
        canvasEl.style.transform = 'translate(0px, 0px) scale(1)';

        // Chụp toàn bộ cây
        const canvas = await html2canvas(canvasEl, {
            scale: 1.5,
            useCORS: true,
            backgroundColor: "#ffffff",
            windowWidth: canvasEl.scrollWidth,
            windowHeight: canvasEl.scrollHeight
        });

        // Chuyển thành ảnh
        const imgData = canvas.toDataURL('image/png');

        // Tạo PDF A0 ngang
        const pdf = new jsPDF({
            orientation: 'landscape',
            unit: 'mm',
            format: 'a0'
        });

        // Kích thước A0 ngang
        const pdfWidth = 1189;
        const pdfHeight = 841;

        // Tính scale để fit toàn bộ cây
        const ratio = Math.min(
            pdfWidth / canvas.width,
            pdfHeight / canvas.height
        );

        const imgWidth = canvas.width * ratio;
        const imgHeight = canvas.height * ratio;

        // Căn giữa PDF
        const x = (pdfWidth - imgWidth) / 2;
        const y = (pdfHeight - imgHeight) / 2;

        // Thêm ảnh vào PDF
        pdf.addImage(
            imgData,
            'PNG',
            x,
            y,
            imgWidth,
            imgHeight
        );

        // Xuất file
        pdf.save('gia-pha-A0.pdf');
    }
    </script>
    <script>
    (function () {
        const viewportEl = document.getElementById('familyTree');
        const canvasEl   = document.getElementById('treeCanvas');
        if (!viewportEl || !canvasEl) return;

        let scale = 1;
        let originX = 0;
        let originY = 0;
        const MIN_SCALE = 0.3;
        const MAX_SCALE = 2.5;

        function applyTransform() {
            canvasEl.style.transform = `translate(${originX}px, ${originY}px) scale(${scale})`;
            const badge = document.getElementById('zoomLevelBadge');
            if (badge) badge.textContent = Math.round(scale * 100) + '%';
        }

        // ── Pan bằng kéo thả chuột ──
        let isDragging = false;
        let startX = 0, startY = 0;
        let startOriginX = 0, startOriginY = 0;

        viewportEl.addEventListener('mousedown', function (e) {
            if (e.target.closest('.tree-controls')) return;
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            startOriginX = originX;
            startOriginY = originY;
            viewportEl.classList.add('dragging');
            e.preventDefault();
        });

        window.addEventListener('mousemove', function (e) {
            if (!isDragging) return;
            originX = startOriginX + (e.clientX - startX);
            originY = startOriginY + (e.clientY - startY);
            applyTransform();
        });

        window.addEventListener('mouseup', function () {
            if (isDragging) {
                isDragging = false;
                viewportEl.classList.remove('dragging');
            }
        });

        // ── Zoom bằng Ctrl + lăn chuột (zoom theo đúng vị trí con trỏ) ──
        viewportEl.addEventListener('wheel', function (e) {
            if (!e.ctrlKey) return; // không giữ Ctrl thì không chặn cuộn trang bình thường
            e.preventDefault();

            const rect = viewportEl.getBoundingClientRect();
            const cursorX = e.clientX - rect.left;
            const cursorY = e.clientY - rect.top;

            const delta = e.deltaY < 0 ? 1.1 : 0.9;
            const newScale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale * delta));

            originX = cursorX - (cursorX - originX) * (newScale / scale);
            originY = cursorY - (cursorY - originY) * (newScale / scale);
            scale = newScale;

            applyTransform();
        }, { passive: false });

        // ── Nút zoom +/− và reset ──
        window.zoomIn = function () {
            const rect = viewportEl.getBoundingClientRect();
            const cx = rect.width / 2, cy = rect.height / 2;
            const newScale = Math.min(MAX_SCALE, scale * 1.2);
            originX = cx - (cx - originX) * (newScale / scale);
            originY = cy - (cy - originY) * (newScale / scale);
            scale = newScale;
            applyTransform();
        };
        window.zoomOut = function () {
            const rect = viewportEl.getBoundingClientRect();
            const cx = rect.width / 2, cy = rect.height / 2;
            const newScale = Math.max(MIN_SCALE, scale / 1.2);
            originX = cx - (cx - originX) * (newScale / scale);
            originY = cy - (cy - originY) * (newScale / scale);
            scale = newScale;
            applyTransform();
        };
        window.resetView = function () {
            scale = 1;
            originX = 0;
            originY = 0;
            applyTransform();
        };

        // Dùng cho highlightMember(): pan tới giữa khung nhìn khi tìm được 1 thành viên
        window.panToElement = function (el) {
            const viewportRect = viewportEl.getBoundingClientRect();
            const elRect = el.getBoundingClientRect();

            const elCenterX = elRect.left + elRect.width / 2 - viewportRect.left;
            const elCenterY = elRect.top + elRect.height / 2 - viewportRect.top;

            const viewportCenterX = viewportRect.width / 2;
            const viewportCenterY = viewportRect.height / 2;

            originX += (viewportCenterX - elCenterX);
            originY += (viewportCenterY - elCenterY);

            applyTransform();
        };

        applyTransform();
    })();
    </script>
    <script>
    const allMembers = <?= json_encode(array_map(fn($m) => [
        'id'         => (int) $m['member_id'],
        'name'       => $m['full_name'],
        'generation' => (int) $m['generation']
    ], $membersForSelect)) ?>;

    const searchInput    = document.getElementById('memberSearch');
    const suggestionsBox = document.getElementById('searchSuggestions');
    const hiddenId       = document.getElementById('selectedMemberId');
    let activeIndex = -1;

    // Lắng nghe input → hiện gợi ý
    searchInput.addEventListener('input', function () {
        const query = this.value.trim().toLowerCase();
        activeIndex = -1;

        if (!query) {
            suggestionsBox.style.display = 'none';
            hiddenId.value = '';
            return;
        }

        const matches = allMembers
            .filter(m => m.name.toLowerCase().includes(query))
            .slice(0, 10);

        if (!matches.length) {
            suggestionsBox.style.display = 'none';
            return;
        }

        suggestionsBox.innerHTML = matches.map((m, i) => {
            const hl = m.name.replace(
                new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi'),
                '<mark>$1</mark>'
            );
            return `<div class="suggestion-item" data-id="${m.id}" data-name="${m.name}">
                        <span class="name-text">${hl}</span>
                        <span class="gen-badge ms-auto">Đời ${m.generation}</span>
                    </div>`;
        }).join('');

        suggestionsBox.style.display = 'block';

        suggestionsBox.querySelectorAll('.suggestion-item').forEach(item => {
            item.addEventListener('click', function () {
                searchInput.value = this.dataset.name;
                hiddenId.value    = this.dataset.id;
                suggestionsBox.style.display = 'none';
            });
        });
    });

    // Điều hướng bằng phím
    searchInput.addEventListener('keydown', function (e) {
        const items = suggestionsBox.querySelectorAll('.suggestion-item');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            updateActive(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            updateActive(items);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0 && items[activeIndex]) {
                searchInput.value = items[activeIndex].dataset.name;
                hiddenId.value    = items[activeIndex].dataset.id;
                suggestionsBox.style.display = 'none';
            }
            doSearch();
        } else if (e.key === 'Escape') {
            suggestionsBox.style.display = 'none';
        }
    });

    function updateActive(items) {
        items.forEach((el, i) => {
            el.classList.toggle('active', i === activeIndex);
            if (i === activeIndex) {
                hiddenId.value    = el.dataset.id;
                searchInput.value = el.dataset.name;
            }
        });
    }

    // Ẩn gợi ý khi click ra ngoài
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#memberSearch') && !e.target.closest('#searchSuggestions')) {
            suggestionsBox.style.display = 'none';
        }
    });

    // Scroll đến card + highlight — không reload trang
    function doSearch() {
        const id = hiddenId.value;
        if (!id) {
            alert('Vui lòng chọn một thành viên từ danh sách gợi ý!');
            return;
        }
        highlightMember(id);
    }

    function highlightMember(id) {
        // Xóa highlight cũ (cả card và đường nối)
        document.querySelectorAll('.path-highlight')
                .forEach(el => el.classList.remove('path-highlight'));
        document.querySelectorAll('.person-card.highlighted')
                .forEach(el => el.classList.remove('highlighted'));

        const target = document.getElementById('member-' + id);
        if (!target) {
            alert('Không tìm thấy thành viên này trong cây gia phả!');
            return;
        }

        // Highlight chính card được tìm (giữ hiệu ứng nhấp nháy cũ)
        target.classList.add('highlighted');

        // Đi ngược từ node hiện tại lên tới cụ tổ, highlight toàn bộ đường đi
        const pathElements = [];
        let treeNode = target.closest('.tree-node');

        while (treeNode) {
            // Highlight card chính của node hiện tại (vợ/chồng nếu có cũng được highlight nhẹ)
            const ownCard = treeNode.querySelector(':scope > .couple-wrapper > .person-card');
            if (ownCard) pathElements.push(ownCard);

            // Tìm child-node bọc treeNode này (nếu có nghĩa là còn cha phía trên)
            const childNode = treeNode.parentElement && treeNode.parentElement.classList.contains('child-node')
                ? treeNode.parentElement
                : null;

            if (!childNode) break; // đã tới cụ tổ (root), dừng

            pathElements.push(childNode); // để highlight đường ::before/::after của child-node

            const childrenRow = childNode.parentElement;       // .children-row
            const childrenContainer = childrenRow ? childrenRow.parentElement : null; // .children-container
            if (!childrenContainer) break;

            const vTop = childrenContainer.querySelector(':scope > .connector-vertical-top');
            const hLine = childrenContainer.querySelector(':scope > .connector-horizontal');
            if (vTop) pathElements.push(vTop);
            if (hLine) pathElements.push(hLine);

            // Lên tiếp tới tree-node của cha/mẹ
            treeNode = childrenContainer.parentElement && childrenContainer.parentElement.classList.contains('tree-node')
                ? childrenContainer.parentElement
                : null;
        }

        pathElements.forEach(el => el.classList.add('path-highlight'));

        if (window.panToElement) {
            window.panToElement(target);
        } else {
            target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        }

        // Tự xóa highlight sau 6 giây
        setTimeout(() => {
            target.classList.remove('highlighted');
            pathElements.forEach(el => el.classList.remove('path-highlight'));
        }, 6000);
    }
    </script>
    <script>
(function () {
    // Đo vị trí TRUNG TÂM thật của card (theo offsetLeft/offsetParent — không bị ảnh hưởng
    // bởi pan/zoom transform của #treeCanvas, vì offsetLeft/offsetParent là giá trị layout,
    // không phải giá trị hiển thị sau transform)
    function getCenterXRelativeTo(el, ancestor) {
        let x = el.offsetWidth / 2;
        let node = el;
        while (node && node !== ancestor) {
            x += node.offsetLeft;
            node = node.offsetParent;
        }
        return x;
    }

    function layoutTreeConnectors() {
        document.querySelectorAll('.connector-horizontal').forEach(function (bar) {
            const container = bar.closest('.children-container');
            if (!container) return;

            const row = container.querySelector(':scope > .children-row');
            if (!row) return;

            const childNodes = row.querySelectorAll(':scope > .child-node');
            if (!childNodes.length) { bar.style.width = '0px'; return; }

            let minCenter = Infinity, maxCenter = -Infinity;

            childNodes.forEach(function (childNode) {

                // Lấy toàn bộ cặp vợ chồng
                const coupleWrapper = childNode.querySelector(
                    ':scope > .tree-node > .couple-wrapper'
                );

                // Nếu là nhánh tách (split branch)
                const splitCard = childNode.querySelector(
                    ':scope > .split-branch-card'
                );

                let targetElement = coupleWrapper || splitCard;

                if (!targetElement) return;

                const x = getCenterXRelativeTo(targetElement, container);

                if (x < minCenter) minCenter = x;
                if (x > maxCenter) maxCenter = x;
            });

            if (minCenter === Infinity) { bar.style.width = '0px'; return; }

            // Chỉ có 1 con → không cần thanh ngang, đường dọc riêng của con đó là đủ
            if (childNodes.length <= 1 || maxCenter === minCenter) {
                bar.style.width = '0px';
                return;
            }

            bar.style.left  = minCenter + 'px';
            bar.style.width = (maxCenter - minCenter) + 'px';
        });
    }

    if (document.readyState === 'complete') {
        layoutTreeConnectors();
    } else {
        window.addEventListener('load', layoutTreeConnectors);
    }

    // Tính lại nếu khung nhìn đổi kích thước (đề phòng font/ảnh load xong đổi layout)
    window.addEventListener('resize', layoutTreeConnectors);

    // Expose để gọi lại thủ công nếu sau này có thêm node động vào cây
    window.layoutTreeConnectors = layoutTreeConnectors;
    })();
    </script>
</body>
</html>
