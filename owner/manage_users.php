<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../login.php', 'Bạn không có quyền truy cập!', 'error');
}

// Xử lý thêm/sửa/xóa user
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'add') {
        $username         = trim($_POST['username']);
        $email            = trim($_POST['email']);
        $full_name        = trim($_POST['full_name']);
        $password         = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role             = $_POST['role'];

        // Nếu là admin, lấy thêm managed_family_id (có thể là null nếu chọn "all")
        $managed_family_id = null;
        if ($role === 'admin') {

            if ($_POST['managed_family_id'] !== 'all') {
                $managed_family_id = (int)$_POST['managed_family_id'];
            }

        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, email, full_name, password, role, managed_family_id)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$username, $email, $full_name, $password, $role, $managed_family_id]);
            redirect('manage_users.php', 'Thêm tài khoản thành công!');
        } catch (PDOException $e) {
            $error = 'Lỗi: Email hoặc tên đăng nhập đã tồn tại!';
        }
    }

    if ($action == 'edit') {
        $user_id          = (int)$_POST['user_id'];
        $username         = trim($_POST['username']);
        $email            = trim($_POST['email']);
        $full_name        = trim($_POST['full_name']);
        $role             = $_POST['role'];
        $status           = $_POST['status'];
        // Nếu là admin, lấy thêm managed_family_id (có thể là null nếu chọn "all")
        $managed_family_id = null;
        if ($role === 'admin') {

            if ($_POST['managed_family_id'] !== 'all') {
                $managed_family_id = (int)$_POST['managed_family_id'];
            }

        }

        try {
            if (!empty($_POST['password'])) {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET username=?, email=?, full_name=?, password=?, role=?, status=?, managed_family_id=?
                     WHERE user_id=?"
                );
                $stmt->execute([$username, $email, $full_name, $password, $role, $status, $managed_family_id, $user_id]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET username=?, email=?, full_name=?, role=?, status=?, managed_family_id=?
                     WHERE user_id=?"
                );
                $stmt->execute([$username, $email, $full_name, $role, $status, $managed_family_id, $user_id]);
            }
            redirect('manage_users.php', 'Cập nhật tài khoản thành công!');
        } catch (PDOException $e) {
            $error = 'Lỗi: Email hoặc tên đăng nhập đã tồn tại!';
        }
    }

    if ($action == 'delete') {
        $user_id = (int)$_POST['user_id'];
        if ($user_id != $_SESSION['user_id']) {
            try {
                $pdo->beginTransaction();

                // Giữ nguyên dữ liệu gia phả và chuyển người tạo sang Owner hiện tại
                // trước khi xóa tài khoản để không vi phạm các khóa ngoại created_by.
                $newOwnerId = (int) $_SESSION['user_id'];
                foreach (['families', 'members', 'daughters_in_law'] as $table) {
                    $stmt = $pdo->prepare("UPDATE $table SET created_by = ? WHERE created_by = ?");
                    $stmt->execute([$newOwnerId, $user_id]);
                }

                $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $pdo->commit();
                redirect('manage_users.php', 'Đã xóa tài khoản. Dữ liệu do tài khoản này tạo đã được chuyển sang Owner hiện tại.');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Không thể xóa tài khoản. Vui lòng thử lại.';
            }
        } else {
            $error = 'Không thể xóa tài khoản đang đăng nhập!';
        }
    }
}

// Lấy danh sách gia tộc
$stmt     = $pdo->query("SELECT family_id, family_name FROM families ORDER BY family_name");
$families = $stmt->fetchAll();

// Map nhanh family_id => family_name để hiển thị trong bảng
$familyMap = [];
foreach ($families as $f) {
    $familyMap[$f['family_id']] = $f['family_name'];
}

// Lấy danh sách users (kèm tên gia tộc qua JOIN)
$stmt  = $pdo->query(
    "SELECT u.*, f.family_name
     FROM users u
     LEFT JOIN families f ON f.family_id = u.managed_family_id
     ORDER BY u.created_at DESC"
);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý tài khoản - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar {
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            box-shadow: 2px 0 10px rgba(0,0,0,.1);
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,.8);
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin: .25rem 0;
            transition: all .3s ease;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background-color: rgba(255,255,255,.2);
            color: #fff;
            transform: translateX(5px);
        }
        .main-content { padding: 2rem; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,.08); }
        .table th { border-top: none; font-weight: 600; color: #495057; }
        .badge { font-size: .75rem; }
        .btn-sm { padding: .25rem .5rem; font-size: .75rem; }
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
                    <li class="nav-item">
                        <a class="nav-link active" href="manage_users.php">
                            <i class="fas fa-users me-2"></i>Quản lý tài khoản
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_service_packages.php">
                            <i class="fas fa-box-open me-2"></i>Quản lý gói dịch vụ
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_family.php">
                            <i class="fas fa-home me-2"></i>Quản lý gia tộc
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
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
                <h1 class="h2">Quản lý tài khoản</h1>
                <button type="button" class="btn btn-primary"
                        data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fas fa-plus me-2"></i>Thêm tài khoản
                </button>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tên đăng nhập</th>
                                    <th>Họ tên</th>
                                    <th>Email</th>
                                    <th>Vai trò</th>
                                    <th>Gia tộc QL</th>
                                    <th>Trạng thái</th>
                                    <th>Ngày tạo</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?= $user['user_id'] ?></td>
                                    <td><?= htmlspecialchars($user['username']) ?></td>
                                    <td><?= htmlspecialchars($user['full_name']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td>
                                        <span class="badge <?= $user['role'] === 'admin' ? 'bg-danger' : 'bg-info' ?>">
                                            <?= $user['role'] === 'admin' ? 'Quản trị' : 'Người dùng' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['role'] === 'admin' && $user['family_name']): ?>
                                            <span class="badge bg-warning text-dark">
                                                <?= htmlspecialchars($user['family_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $user['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                            <?= $user['status'] === 'active' ? 'Hoạt động' : 'Tạm khóa' ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary"
                                                onclick='editUser(<?= json_encode($user, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                        <button class="btn btn-sm btn-outline-danger"
                                                onclick="deleteUser(<?= $user['user_id'] ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- ===================== MODAL THÊM USER ===================== -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Thêm tài khoản mới</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addUserForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">

                    <div class="mb-3">
                        <label class="form-label">Tên đăng nhập</label>
                        <input type="text" class="form-control" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Họ tên</label>
                        <input type="text" class="form-control" name="full_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mật khẩu</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Vai trò</label>
                        <select class="form-select" name="role" id="add_role" required>
                            <option value="user">Người dùng</option>
                            <option value="admin">Quản trị</option>
                        </select>
                    </div>

                    <!-- Dropdown gia tộc — chỉ hiện khi chọn Admin -->
                    <div class="mb-3" id="add_family_group" style="display:none;">
                        <label class="form-label">Gia tộc quản lý <span class="text-danger">*</span></label>
                        <select class="form-select" name="managed_family_id" id="add_managed_family_id">
                            <option value="all">Quản lý tất cả gia tộc</option>
                            <?php foreach ($families as $family): ?>
                                
                                <option value="<?= $family['family_id'] ?>">
                                    <?= htmlspecialchars($family['family_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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

<!-- ===================== MODAL SỬA USER ===================== -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Sửa thông tin tài khoản</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editUserForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="user_id" id="edit_user_id">

                    <div class="mb-3">
                        <label class="form-label">Tên đăng nhập</label>
                        <input type="text" class="form-control" name="username" id="edit_username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Họ tên</label>
                        <input type="text" class="form-control" name="full_name" id="edit_full_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="edit_email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mật khẩu mới (để trống nếu không đổi)</label>
                        <input type="password" class="form-control" name="password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Vai trò</label>
                        <select class="form-select" name="role" id="edit_role" required>
                            <option value="user">Người dùng</option>
                            <option value="admin">Quản trị</option>
                        </select>
                    </div>

                    <!-- Dropdown gia tộc — chỉ hiện khi chọn Admin -->
                    <div class="mb-3" id="edit_family_group" style="display:none;">
                        <label class="form-label">Gia tộc quản lý <span class="text-danger">*</span></label>
                        <select class="form-select" name="managed_family_id" id="edit_managed_family_id">
                            <option value="all">Quản lý tất cả gia tộc</option>
                            <?php foreach ($families as $family): ?>
                                <option value="<?= $family['family_id'] ?>">
                                    <?= htmlspecialchars($family['family_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Trạng thái</label>
                        <select class="form-select" name="status" id="edit_status" required>
                            <option value="active">Hoạt động</option>
                            <option value="inactive">Tạm khóa</option>
                        </select>
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

<!-- Form ẩn để xóa -->
<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="delete_user_id">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Hiện/ẩn dropdown gia tộc và bật/tắt required trên select bên trong.
 */
function toggleFamilyGroup(groupId, selectId, show) {
    const group  = document.getElementById(groupId);
    const select = document.getElementById(selectId);
    group.style.display = show ? 'block' : 'none';
    select.required = show;          // bắt buộc chọn khi là admin
    if (!show) select.value = '';    // reset khi chuyển về user
}

// ─── Modal THÊM ─────────────────────────────────────────────────────────────

document.getElementById('add_role').addEventListener('change', function () {
    toggleFamilyGroup('add_family_group', 'add_managed_family_id', this.value === 'admin');
});

// Validate trước khi submit form thêm
document.getElementById('addUserForm').addEventListener('submit', function (e) {
    const role   = document.getElementById('add_role').value;
    const family = document.getElementById('add_managed_family_id');
    if (role === 'admin' && !family.value) {
        e.preventDefault();
        family.classList.add('is-invalid');
        family.focus();
    } else {
        family.classList.remove('is-invalid');
    }
});

// Reset form khi đóng modal thêm
document.getElementById('addUserModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('addUserForm').reset();
    toggleFamilyGroup('add_family_group', 'add_managed_family_id', false);
    document.getElementById('add_managed_family_id').classList.remove('is-invalid');
});

// ─── Modal SỬA ──────────────────────────────────────────────────────────────

document.getElementById('edit_role').addEventListener('change', function () {
    toggleFamilyGroup('edit_family_group', 'edit_managed_family_id', this.value === 'admin');
});

// Validate trước khi submit form sửa
document.getElementById('editUserForm').addEventListener('submit', function (e) {
    const role   = document.getElementById('edit_role').value;
    const family = document.getElementById('edit_managed_family_id');
    if (role === 'admin' && !family.value) {
        e.preventDefault();
        family.classList.add('is-invalid');
        family.focus();
    } else {
        family.classList.remove('is-invalid');
    }
});

/**
 * Mở modal sửa và điền đúng dữ liệu (kể cả gia tộc).
 */
function editUser(user) {
    document.getElementById('edit_user_id').value  = user.user_id;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_full_name').value = user.full_name;
    document.getElementById('edit_email').value    = user.email;
    document.getElementById('edit_role').value     = user.role;
    document.getElementById('edit_status').value   = user.status;

    const isAdmin = user.role === 'admin';
    toggleFamilyGroup('edit_family_group', 'edit_managed_family_id', isAdmin);

    if (isAdmin) {
        document.getElementById('edit_managed_family_id').value =
            user.managed_family_id ?? '';
    }

    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

// ─── Xóa ────────────────────────────────────────────────────────────────────

function deleteUser(userId) {
    if (confirm('Bạn có chắc muốn xóa tài khoản này?')) {
        document.getElementById('delete_user_id').value = userId;
        document.getElementById('deleteForm').submit();
    }
}
</script>
</body>
</html>
