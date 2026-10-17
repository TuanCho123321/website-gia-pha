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
$stmtF = $pdo->prepare("
    SELECT *
    FROM families
    WHERE family_id = ?
");

$stmtF->execute([$family_id]);

$family = $stmtF->fetch();

if (!$family) {
    redirect('manage_family.php', 'Gia tộc không tồn tại!', 'error');
}
requireActiveFamilySubscription((int) $family_id, $pdo);

// Xử lý thêm/sửa/xóa thành viên
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add') {
        $full_name   = trim($_POST['full_name']);
        $birth_date  = $_POST['birth_date'] ?: null;
        $death_date  = $_POST['death_date'] ?: null;
        $birth_place = trim($_POST['birth_place']);
        $occupation  = trim($_POST['occupation']);
        $education   = trim($_POST['education']);
        $phone       = trim($_POST['phone']);
        $address     = trim($_POST['address']);
        $biography   = trim($_POST['biography']);
        $husband_id  = !empty($_POST['husband_id']) ? (int) $_POST['husband_id'] : null;

        // Xử lý upload ảnh
        $avatar = null;
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
            $avatar = uploadImage($_FILES['avatar']);
        }

        try {
            // Thêm con dâu
            $stmt = $pdo->prepare("
                INSERT INTO daughters_in_law
                    (family_id, full_name, birth_date, death_date, birth_place,
                     occupation, education, phone, address, biography, avatar, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $family_id, $full_name, $birth_date, $death_date, $birth_place,
                $occupation, $education, $phone, $address, $biography,
                $avatar, $_SESSION['user_id']
            ]);

            $daughter_in_law_id = $pdo->lastInsertId();

            // Nếu có chọn chồng → thêm quan hệ hôn nhân
            if ($husband_id) {
                $stmtMar = $pdo->prepare("
                    INSERT INTO marriages (husband_id, wife_id, created_at)
                    VALUES (?, ?, GETDATE())
                ");
                $stmtMar->execute([$husband_id, $daughter_in_law_id]);
            }

            redirect('manage_daughter_in_law.php', 'Thêm con dâu thành công!');
        } catch (PDOException $e) {
            $error = 'Lỗi: ' . $e->getMessage();
        }
    }
    
    if ($action == 'edit') {
        $daughter_in_law_id = $_POST['daughter_in_law_id'];
        $full_name = trim($_POST['full_name']);
        $birth_date = $_POST['birth_date'] ?: null;
        $death_date = $_POST['death_date'] ?: null;
        $birth_place = trim($_POST['birth_place']);
        $occupation = trim($_POST['occupation']);
        $education = trim($_POST['education']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $biography = trim($_POST['biography']);
        
        // Xử lý upload ảnh mới
        $avatar = null;
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
            $avatar = uploadImage($_FILES['avatar']);
        }
        
        try {
            if ($avatar) {
                $stmt = $pdo->prepare("UPDATE daughters_in_law SET full_name=?, birth_date=?, death_date=?, birth_place=?, occupation=?, education=?, phone=?, address=?, biography=?, avatar=? WHERE daughter_in_law_id=? AND family_id = ?");
                $stmt->execute([$full_name, $birth_date, $death_date, $birth_place, $occupation, $education, $phone, $address, $biography, $avatar, $daughter_in_law_id, $family_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE daughters_in_law SET full_name=?, birth_date=?, death_date=?, birth_place=?, occupation=?, education=?, phone=?, address=?, biography=? WHERE daughter_in_law_id=? AND family_id = ?");
                $stmt->execute([$full_name, $birth_date, $death_date, $birth_place, $occupation, $education, $phone, $address, $biography, $daughter_in_law_id, $family_id]);
            }
            redirect('manage_daughter_in_law.php', 'Cập nhật con dâu thành công!');
        } catch (PDOException $e) {
            $error = 'Lỗi: ' . $e->getMessage();
        }
    }
    
    if ($action == 'delete') {
        $daughter_in_law_id = (int) $_POST['daughter_in_law_id'];
        try {
            // Xóa quan hệ hôn nhân trước (nếu có)
            $stmtDel = $pdo->prepare("DELETE FROM marriages WHERE wife_id = ?");
            $stmtDel->execute([$daughter_in_law_id]);

            // Xóa con dâu
            $stmt = $pdo->prepare("DELETE FROM daughters_in_law WHERE daughter_in_law_id = ? AND family_id = ?");
            $stmt->execute([$daughter_in_law_id, $family_id]);

            redirect('manage_daughter_in_law.php', 'Xóa con dâu thành công!');
        } catch (PDOException $e) {
            $error = 'Lỗi: Không thể xóa con dâu này!';
        }
    }
}

// Lấy danh sách con dâu
$search = $_GET['search'] ?? '';

$sql = "SELECT * FROM daughters_in_law WHERE family_id = ?";
$params = [$family_id];

if ($search) {

    if (is_numeric($search)) {
        $sql .= " AND (full_name LIKE ? OR YEAR(birth_date) = ?)";
        $params[] = "%$search%";
        $params[] = (int)$search;
    } else {
        $sql .= " AND full_name LIKE ?";
        $params[] = "%$search%";
    }

}

$sql .= " ORDER BY full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daughtersInLaw = $stmt->fetchAll();

// Danh sách Nam độc thân trong gia tộc để hiển thị trong form thêm quan hệ hôn nhân (chỉ hiện những người chưa có vợ)
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
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý con dâu - Admin</title>
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
        
        .avatar-preview {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .member-card {
            transition: transform 0.2s ease;
        }
        
        .member-card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar (tương tự như trước) -->
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
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_members.php">
                                <i class="fas fa-user-friends me-2"></i>Quản lý thành viên
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="manage_daughter_in_law.php">
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
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
                    <h1 class="h2">Quản lý con dâu</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                        <i class="fas fa-plus me-2"></i>Thêm con dâu
                    </button>
                </div>

                <!-- Bộ lọc và tìm kiếm -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Tìm kiếm theo tên hoặc năm sinh</label>
                                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Nhập tên hoặc năm sinh...">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary me-2">
                                    <i class="fas fa-search"></i>
                                </button>
                                <a href="manage_daughter_in_law.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-refresh"></i>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Danh sách con dâu -->
                <div class="row">
                    <?php foreach ($daughtersInLaw as $daughter): ?>
                        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                            <div class="card member-card h-100">
                                <div class="card-body text-center">
                                    <div class="mb-3">
                                        <?php if ($daughter['avatar']): ?>
                                            <img src="../uploads/<?= $daughter['avatar'] ?>" class="avatar-preview" alt="Avatar">
                                        <?php else: ?>
                                            <div class="avatar-preview mx-auto bg-light d-flex align-items-center justify-content-center">
                                                <i class="fas fa-person-dress fa-2x text-muted"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <h6 class="card-title"><?= htmlspecialchars($daughter['full_name']) ?></h6>
                                    
                                    <?php $isDeceased = !empty($daughter['death_date']) || !empty(trim($daughter['address'] ?? '')); ?>

                                    <div class="text-muted small mb-2">
                                        <?php if ($daughter['birth_date']): ?>
                                            <div><i class="fas fa-calendar me-1"></i><?= date('d/m/Y', strtotime($daughter['birth_date'])) ?></div>
                                        <?php endif; ?>
                                        <?php if ($daughter['birth_date'] && !$isDeceased): ?>
                                            <div class="text-success"><i class="fas fa-heartbeat me-1"></i><?= calculateAge($daughter['birth_date']) ?> tuổi</div>
                                        <?php elseif ($isDeceased): ?>
                                            <div class="text-secondary">
                                                <i class="fas fa-cross me-1"></i>
                                                <?= $daughter['death_date'] ? date('d/m/Y', strtotime($daughter['death_date'])) : 'Đã mất' ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-info" onclick="viewMember(<?= htmlspecialchars(json_encode($daughter)) ?>)" title="Xem chi tiết">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-outline-primary" onclick="editMember(<?= htmlspecialchars(json_encode($daughter)) ?>)" title="Sửa">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" onclick="deleteMember(<?= $daughter['daughter_in_law_id'] ?>)" title="Xóa">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if (empty($daughtersInLaw)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Không có thành viên nào</h5>
                        <p class="text-muted">Hãy thêm thành viên đầu tiên cho gia phả</p>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Modal thêm thành viên -->
    <div class="modal fade" id="addMemberModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Thêm thành viên mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ tên <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="full_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">
                                    Chồng <small class="text-muted">(chỉ hiện Nam độc thân)</small>
                                </label>
                                <select class="form-control" name="husband_id">
                                    <option value="">Không có / Chọn sau</option>
                                    <?php foreach ($singleMaleMembers as $m): ?>
                                        <option value="<?= $m['member_id'] ?>">
                                            <?= htmlspecialchars($m['full_name']) ?> (Đời <?= $m['generation'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày sinh</label>
                                <input type="date" class="form-control" name="birth_date">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày mất</label>
                                <input type="date" class="form-control" name="death_date">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nơi sinh</label>
                                <input type="text" class="form-control" name="birth_place">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nghề nghiệp</label>
                                <input type="text" class="form-control" name="occupation">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Học vấn</label>
                                <input type="text" class="form-control" name="education">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Số điện thoại</label>
                                <input type="text" class="form-control" name="phone">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">An táng</label>
                            <textarea class="form-control" name="address" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tiểu sử</label>
                            <textarea class="form-control" name="biography" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ảnh đại diện</label>
                            <input type="file" class="form-control" name="avatar" accept="image/*">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="btn btn-primary">Thêm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal sửa thành viên -->
    <div class="modal fade" id="editMemberModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sửa thông tin thành viên</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="editMemberForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="daughter_in_law_id" id="edit_daughter_in_law_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ tên *</label>
                                <input type="text" class="form-control" name="full_name" id="edit_full_name" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày sinh</label>
                                <input type="date" class="form-control" name="birth_date" id="edit_birth_date">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày mất</label>
                                <input type="date" class="form-control" name="death_date" id="edit_death_date">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nơi sinh</label>
                                <input type="text" class="form-control" name="birth_place" id="edit_birth_place">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nghề nghiệp</label>
                                <input type="text" class="form-control" name="occupation" id="edit_occupation">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Học vấn</label>
                                <input type="text" class="form-control" name="education" id="edit_education">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Số điện thoại</label>
                                <input type="text" class="form-control" name="phone" id="edit_phone">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">An táng</label>
                            <textarea class="form-control" name="address" id="edit_address" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tiểu sử</label>
                            <textarea class="form-control" name="biography" id="edit_biography" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ảnh đại diện mới (để trống nếu không đổi)</label>
                            <input type="file" class="form-control" name="avatar" accept="image/*">
                            <div id="current_avatar" class="mt-2"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="btn btn-primary">Cập nhật</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal xem chi tiết thành viên -->
    <div class="modal fade" id="viewMemberModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Thông tin chi tiết</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="memberDetails">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editMember(daughter) {
            document.getElementById('edit_daughter_in_law_id').value = daughter.daughter_in_law_id;
            document.getElementById('edit_full_name').value = daughter.full_name;
            document.getElementById('edit_birth_date').value = daughter.birth_date;
            document.getElementById('edit_death_date').value = daughter.death_date;
            document.getElementById('edit_birth_place').value = daughter.birth_place || '';
            document.getElementById('edit_occupation').value = daughter.occupation || '';
            document.getElementById('edit_education').value = daughter.education || '';
            document.getElementById('edit_phone').value = daughter.phone || '';
            document.getElementById('edit_address').value = daughter.address || '';
            document.getElementById('edit_biography').value = daughter.biography || '';
            
            const currentAvatarDiv = document.getElementById('current_avatar');
            if (daughter.avatar) {
                currentAvatarDiv.innerHTML = `<small class="text-muted">Ảnh hiện tại:</small><br><img src="../uploads/${daughter.avatar}" class="mt-2" style="max-width: 100px; border-radius: 10px;">`;
            } else {
                currentAvatarDiv.innerHTML = '';
            }
            
            new bootstrap.Modal(document.getElementById('editMemberModal')).show();
        }
        
        function deleteMember(daughterInLawId) {
            if (confirm('Bạn có chắc muốn xóa con dâu này? Thao tác này không thể hoàn tác!')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="daughter_in_law_id" value="${daughterInLawId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function viewMember(daughter) {
            const isDeceased = !!daughter.death_date || !!(daughter.address && daughter.address.trim());
            const birthDate  = daughter.birth_date ? new Date(daughter.birth_date).toLocaleDateString('vi-VN') : 'Chưa xác định';
            const deathDate  = daughter.death_date ? new Date(daughter.death_date).toLocaleDateString('vi-VN') : null;

            let ageText = '';
            if (daughter.birth_date) {
                const birth = new Date(daughter.birth_date);
                const end   = daughter.death_date ? new Date(daughter.death_date) : new Date();
                const age   = Math.floor((end - birth) / (365.25 * 24 * 60 * 60 * 1000));
                ageText = isDeceased ? `(Thọ ${age} tuổi)` : `(${age} tuổi)`;
            }
            
            const avatar = daughter.avatar ? 
                `<img src="../uploads/${daughter.avatar}" class="img-fluid rounded mb-3" style="max-width: 200px;">` : 
                '<div class="bg-light rounded p-4 mb-3"><i class="fas fa-person-dress fa-4x text-muted"></i></div>';    
            
            document.getElementById('memberDetails').innerHTML = `
                <div class="text-center mb-4">
                    ${avatar}
                    <h4>${daughter.full_name}</h4>
                    ${isDeceased ? '<span class="badge bg-secondary"><i class="fas fa-cross me-1"></i>Đã mất</span>' : ''}
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td><strong>Ngày sinh:</strong></td>
                                <td>${birthDate}</td>
                            </tr>
                            ${daughter.death_date
                            ? `<tr><td><strong>Ngày mất:</strong></td><td>${deathDate} ${ageText}</td></tr>`
                            : isDeceased
                                ? `<tr><td><strong>Trạng thái:</strong></td><td><span class="badge bg-secondary">Đã mất</span> ${ageText}</td></tr>`
                                : `<tr><td><strong>Tuổi:</strong></td><td>${ageText}</td></tr>`}
                            <tr>
                                <td><strong>Nơi sinh:</strong></td>
                                <td>${daughter.birth_place || 'Chưa cập nhật'}</td>
                            </tr>
                            <tr>
                                <td><strong>Nghề nghiệp:</strong></td>
                                <td>${daughter.occupation || 'Chưa cập nhật'}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td><strong>Học vấn:</strong></td>
                                <td>${daughter.education || 'Chưa cập nhật'}</td>
                            </tr>
                            <tr>
                                <td><strong>Số điện thoại:</strong></td>
                                <td>${daughter.phone || 'Chưa cập nhật'}</td>
                            </tr>
                            <tr>
                                <td><strong>An táng:</strong></td>
                                <td>${daughter.address || 'Chưa cập nhật'}</td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                ${daughter.biography ? `
                <div class="mt-4">
                    <h6><strong>Tiểu sử:</strong></h6>
                    <p class="text-justify">${daughter.biography.replace(/\n/g, '<br>')}</p>
                </div>
                ` : ''}
            `;
            
            new bootstrap.Modal(document.getElementById('viewMemberModal')).show();
        }
    </script>
</body>
</html>
