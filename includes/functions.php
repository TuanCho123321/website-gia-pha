<?php
require_once 'config.php';

// ====================== CÁC FUNCTION CŨ (GIỮ NGUYÊN) ======================
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function redirect($url, $message = '', $type = 'success') {
    if ($message) {
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $type;
    }
    header("Location: $url");
    exit();
}

function showMessage() {
    if (isset($_SESSION['message'])) {
        $type = $_SESSION['message_type'] ?? 'info';
        $message = $_SESSION['message'];
        unset($_SESSION['message'], $_SESSION['message_type']);
        return "<div class='alert alert-$type'>$message</div>";
    }
    return '';
}

function uploadImage($file, $folder = 'avatars') {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) return false;
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowedTypes)) return false;
    
    $maxSize = 2 * 1024 * 1024;
    if ($file['size'] > $maxSize) return false;
    
    $uploadDir = UPLOAD_PATH . $folder . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    
    $fileName = time() . '_' . basename($file['name']);
    $uploadPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return $folder . '/' . $fileName;
    }
    return false;
}

function calculateAge($birthDate, $deathDate = null) {
    $birth = new DateTime($birthDate);
    $end = $deathDate ? new DateTime($deathDate) : new DateTime();
    return $birth->diff($end)->y;
}

function getStatistics() {
    global $pdo;
    $stats = [];

    // Tổng thành viên (members)
    $stmt = $pdo->query("SELECT COUNT(*) FROM members");
    $stats['total_members'] = (int) $stmt->fetchColumn();

    // Tổng con dâu (daughters_in_law)
    $stmt = $pdo->query("SELECT COUNT(*) FROM daughters_in_law");
    $stats['total_daughters'] = (int) $stmt->fetchColumn();

    // Tổng cộng tất cả (members + con dâu)
    $stats['total_all'] = $stats['total_members'] + $stats['total_daughters'];

    // Tổng số gia tộc
    $stmt = $pdo->query("SELECT COUNT(*) FROM families");
    $stats['total_families'] = (int) $stmt->fetchColumn();

    // Tổng số hôn nhân
    $stmt = $pdo->query("SELECT COUNT(*) FROM marriages");
    $stats['total_marriages'] = (int) $stmt->fetchColumn();

    // Số đời (generation) hiện có
    $stmt = $pdo->query("SELECT COUNT(DISTINCT generation) FROM members");
    $stats['total_generations'] = (int) $stmt->fetchColumn();

    // Phân bố giới tính trong members (DB lưu 'Nam' / 'Nữ')
    $stmt = $pdo->query("SELECT gender, COUNT(*) AS cnt FROM members GROUP BY gender");
    $genderRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['male']   = 0;
    $stats['female'] = 0;
    foreach ($genderRows as $row) {
        if ($row['gender'] === 'Nam') $stats['male']   = (int) $row['cnt'];
        if ($row['gender'] === 'Nữ')  $stats['female'] = (int) $row['cnt'];
    }

    // Phân bố theo thế hệ
    $stmt = $pdo->query("
        SELECT generation, COUNT(*) AS count
        FROM members
        GROUP BY generation
        ORDER BY generation ASC
    ");
    $stats['generations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Số thành viên theo gia tộc (dùng cho biểu đồ)
    $stmt = $pdo->query("
        SELECT f.family_name,
               COUNT(DISTINCT m.member_id)  AS total_members,
               COUNT(DISTINCT d.daughter_in_law_id) AS total_daughters
        FROM families f
        LEFT JOIN members         m ON m.family_id = f.family_id
        LEFT JOIN daughters_in_law d ON d.family_id = f.family_id
        GROUP BY f.family_id, f.family_name
        ORDER BY f.family_name ASC
    ");
    $stats['by_family'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $stats;
}

// ====================== CÁC FUNCTION MỚI (BẮT BUỘC PHẢI CÓ) ======================
function getMemberById($id, $family_id = null) {
    global $pdo;

    if ($family_id !== null) {

        $stmt = $pdo->prepare("
            SELECT *
            FROM members
            WHERE member_id = ?
            AND family_id = ?
        ");

        $stmt->execute([$id, $family_id]);

    } else {

        $stmt = $pdo->prepare("
            SELECT *
            FROM members
            WHERE member_id = ?
        ");

        $stmt->execute([$id]);
    }

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllMembers() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM members ORDER BY generation, full_name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getParents($child_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT m.*, r.relationship_type FROM relationships r JOIN members m ON m.member_id = r.parent_id WHERE r.child_id = ?");
    $stmt->execute([$child_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getChildren($parent_id, $family_id) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT
            m.*,
            r.relationship_type,
            split_f.family_id AS split_family_id,
            split_f.family_name AS split_family_name,
            split_f.split_from_generation,
            CASE
                WHEN m.family_id <> ? AND split_f.family_id IS NOT NULL THEN 1
                ELSE 0
            END AS is_split_branch
        FROM relationships r
        JOIN members m
            ON m.member_id = r.child_id
        LEFT JOIN families split_f
            ON split_f.split_from_member_id = m.member_id
           AND split_f.parent_family_id = ?
        WHERE r.parent_id = ?
          AND (
                m.family_id = ?
                OR split_f.family_id IS NOT NULL
          )
        ORDER BY m.generation, m.member_id
    ");

    $stmt->execute([$family_id, $family_id, $parent_id, $family_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSpouses($member_id, $family_id) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT
            d.daughter_in_law_id AS spouse_id,
            d.full_name,
            d.avatar,
            'wife' AS role
        FROM marriages mar
        JOIN daughters_in_law d
            ON d.daughter_in_law_id = mar.wife_id
        WHERE mar.husband_id = ?
          AND d.family_id = ?
    ");

    $stmt->execute([$member_id, $family_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function getFamilySplitInfo($family_id) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT
            f.*,
            parent_f.family_name AS parent_family_name,
            split_m.full_name AS split_member_name
        FROM families f
        LEFT JOIN families parent_f ON parent_f.family_id = f.parent_family_id
        LEFT JOIN members split_m ON split_m.member_id = f.split_from_member_id
        WHERE f.family_id = ?
    ");
    $stmt->execute([$family_id]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getSplitFamilyByMember($member_id, $parent_family_id) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT family_id, family_name, split_from_generation
        FROM families
        WHERE split_from_member_id = ?
          AND parent_family_id = ?
    ");
    $stmt->execute([$member_id, $parent_family_id]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function buildSplitBranchCardHTML($member, $splitFamily) {
    $avatar = $member['avatar']
        ? "<img src='/uploads/" . htmlspecialchars($member['avatar']) . "' class='tree-avatar' alt='Avatar'>"
        : "<div class='tree-avatar-placeholder split-branch-avatar'><i class='fas fa-code-branch'></i></div>";

    $memberName = htmlspecialchars($member['full_name']);
    $familyName = htmlspecialchars($splitFamily['family_name'] ?? 'gia tộc mới');
    $oldGeneration = (int) ($splitFamily['split_from_generation'] ?? $member['generation']);
    $targetUrl = 'dashboard.php?family_id=' . (int) $splitFamily['family_id'];

    return "
        <div class='tree-node'>
            <div class='couple-wrapper'>
                <div class='person-card split-branch-card' id='member-{$member['member_id']}'>
                    {$avatar}
                    <div class='person-info'>
                        <h5>{$memberName}</h5>
                        <small>Đã tách thành gia tộc riêng</small>
                        <div class='split-branch-meta'>Tách từ đời {$oldGeneration}</div>
                        <a class='split-branch-link' href='{$targetUrl}'>Xem {$familyName}</a>
                    </div>
                </div>
            </div>
        </div>";
}

// === HÀM QUAN TRỌNG: XÂY DỰNG CÂY GIA PHẢ ===
function buildFamilyTreeHTML($root_id, $family_id, $level = 0, $visited = []) {
    global $pdo;

    // Chống vòng lặp vô tận
    if (in_array($root_id, $visited)) {
        return '';
    }

    $visited[] = $root_id;

    $member = getMemberById($root_id, $family_id);

    if (!$member) {
        return '';
    }

    $isFemale = ($member['gender'] === 'female');
    $familySplitInfo = getFamilySplitInfo($family_id);
    $isSplitRoot = !empty($familySplitInfo['split_from_member_id'])
        && (int) $familySplitInfo['split_from_member_id'] === (int) $root_id;
    $childSplitFamily = getSplitFamilyByMember($root_id, $family_id);
    $splitRootBadge = $isSplitRoot
        ? "<div class='split-root-badge'>Khai chi - Đời cũ " . (int) $familySplitInfo['split_from_generation'] . "</div>"
        : '';
    $splitOutBadge = (!$isSplitRoot && $childSplitFamily)
        ? "<div class='split-root-badge split-out-badge'>Đã tách chi</div>"
        : '';

    $femaleClass = $isFemale ? ' female' : '';

    // Avatar thành viên chính
    $genderIcon = $isFemale ? 'fa-person-dress' : 'fa-person';

    $avatar = $member['avatar']
        ? "<img src='/uploads/" . htmlspecialchars($member['avatar']) . "' class='tree-avatar' alt='Avatar'>"
        : "<div class='tree-avatar-placeholder{$femaleClass}'><i class='fas {$genderIcon}'></i></div>";

    // Card thành viên chính
    $memberCard = "
        <div class='person-card{$femaleClass}' id='member-{$root_id}'>
            {$avatar}
            <div class='person-info'>
                <h5>" . htmlspecialchars($member['full_name']) . "</h5>
                <small>Đời {$member['generation']} • {$member['gender']}</small>
            </div>
        </div>";

    // Vợ/chồng
    $memberCard = str_replace(
        "</small>",
        "</small>{$splitRootBadge}{$splitOutBadge}",
        $memberCard
    );

    $spouses = getSpouses($root_id, $family_id);

    $spouseHTML = '';

    foreach ($spouses as $spouse) {

        $s_avatar = $spouse['avatar']
            ? "<img src='/uploads/" . htmlspecialchars($spouse['avatar']) . "' class='spouse-avatar' alt='Spouse'>"
            : "<div class='spouse-avatar-placeholder'><i class='fas fa-person-dress'></i></div>";

        $spouseHTML .= "
            <div class='marriage-line'></div>

            <div class='spouse-card'>
                {$s_avatar}
                <small>" . htmlspecialchars($spouse['full_name']) . "</small>
            </div>";
    }

    // Thành viên + vợ/chồng
    $coupleHTML = "
        <div class='couple-wrapper'>
            {$memberCard}
            {$spouseHTML}
        </div>";

    // Con cái
    $children = getChildren($root_id, $family_id);

    $childrenHTML = '';

    if (!empty($children)) {

        $childNodes = '';

        foreach ($children as $child) {

            if (!empty($child['is_split_branch'])) {
                $childHTML = buildSplitBranchCardHTML($child, [
                    'family_id' => $child['split_family_id'],
                    'family_name' => $child['split_family_name'],
                    'split_from_generation' => $child['split_from_generation']
                ]);
            } else {
                $childHTML = buildFamilyTreeHTML(
                    $child['member_id'],
                    $family_id,
                    $level + 1,
                    $visited
                );
            }

            $childNodes .= "
                <div class='child-node'>"
                    . $childHTML .
                "</div>";
        }

        $childrenHTML = "
            <div class='children-container'>
                <div class='connector-vertical-top'></div>
                <div class='connector-horizontal'></div>

                <div class='children-row'>
                    {$childNodes}
                </div>
            </div>";
    }

    return "
        <div class='tree-node'>
            {$coupleHTML}
            {$childrenHTML}
        </div>";
}

function getDetailedStatistics() {
    global $pdo;
    $stats = getStatistics();
    $stmt = $pdo->query("SELECT COUNT(*) as total_marriages FROM marriages");
    $stats['total_marriages'] = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) as total_relationships FROM relationships");
    $stats['total_relationships'] = $stmt->fetchColumn();
    return $stats;
}

function validateGenerationRelationship($parent_id, $child_id) {
    $parent = getMemberById($parent_id);
    $child = getMemberById($child_id);

    if (!$parent || !$child) {
        return "Không tìm thấy thành viên!";
    }

    if ($parent['generation'] >= $child['generation']) {
        return "Người ở thế hệ sau hoặc cùng thế hệ không thể làm cha/mẹ của người ở thế hệ trước.";
    }

    return true;
}

function validateGenderRelationship($parent_id, $relationship_type) {
    $parent = getMemberById($parent_id);

    if (!$parent) {
        return "Không tìm thấy thành viên!";
    }

    if ($relationship_type == 'father' && $parent['gender'] != 'male') {
        return "Chỉ người có giới tính Nam mới có thể là Cha.";
    }

    if ($relationship_type == 'mother' && $parent['gender'] != 'female') {
        return "Chỉ người có giới tính Nữ mới có thể là Mẹ.";
    }

    return true;
}

function isAncestor($ancestor_id, $descendant_id) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT child_id 
        FROM relationships 
        WHERE parent_id = ?
    ");
    $stmt->execute([$ancestor_id]);

    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($children as $child) {
        if ($child == $descendant_id || isAncestor($child, $descendant_id)) {
            return true;
        }
    }

    return false;
}

function areSiblings($member1_id, $member2_id) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT parent_id 
        FROM relationships
        WHERE child_id = ?
    ");

    $stmt->execute([$member1_id]);
    $parents1 = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt->execute([$member2_id]);
    $parents2 = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return count(array_intersect($parents1, $parents2)) > 0;
}

function isValidMarriageRelation($member1_id, $member2_id) {

    // Cấm cha/mẹ - con và ông/bà - cháu
    if (isAncestor($member1_id, $member2_id) || isAncestor($member2_id, $member1_id)) {
        return false;
    }

    // Cấm anh/chị/em ruột
    if (areSiblings($member1_id, $member2_id)) {
        return false;
    }

    return true;
}

function isSpouseRelation($person1_id, $person2_id) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM marriages
        WHERE 
            (husband_id = ? AND wife_id = ?)
            OR
            (husband_id = ? AND wife_id = ?)
    ");

    $stmt->execute([
        $person1_id, $person2_id,
        $person2_id, $person1_id
    ]);

    return $stmt->fetchColumn() > 0;
}

function updateGenerationRecursive($member_id, $pdo) {
    // Lấy generation của cha hiện tại
    $stmt = $pdo->prepare("
        SELECT m.generation
        FROM relationships r
        JOIN members m ON m.member_id = r.parent_id
        WHERE r.child_id = ? AND r.relationship_type = 'father'
    ");
    $stmt->execute([$member_id]);
    $father = $stmt->fetch();

    if (!$father) return; // Không tìm thấy cha thì thôi

    $new_generation = $father['generation'] + 1;

    // Cập nhật generation của thành viên này
    $stmt = $pdo->prepare("UPDATE members SET generation = ? WHERE member_id = ?");
    $stmt->execute([$new_generation, $member_id]);

    // Lấy danh sách con của thành viên này rồi đệ quy xuống
    $stmt = $pdo->prepare("
        SELECT child_id FROM relationships
        WHERE parent_id = ? AND relationship_type = 'father'
    ");
    $stmt->execute([$member_id]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($children as $child_id) {
        updateGenerationRecursive($child_id, $pdo);
    }
}

function isCoupleAlreadyMarried($husband_id, $wife_id, $exclude_marriage_id = null) {
    global $pdo;

    $sql = "SELECT 
                SUM(CASE WHEN husband_id = ? THEN 1 ELSE 0 END) AS husband_count,
                SUM(CASE WHEN wife_id = ? THEN 1 ELSE 0 END) AS wife_count
            FROM marriages
            WHERE 1=1";
    $params = [$husband_id, $wife_id];

    if ($exclude_marriage_id) {
        $sql .= " AND marriage_id != ?";
        $params[] = $exclude_marriage_id;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();

    if ($result['husband_count'] > 0) return 'husband';
    if ($result['wife_count'] > 0)   return 'wife';
    return false;
}

function getDescendantMemberIdsInFamily($root_id, $family_id) {
    global $pdo;

    $result = [];
    $visited = [];
    $stack = [(int) $root_id];

    while (!empty($stack)) {
        $current_id = array_pop($stack);

        if (isset($visited[$current_id])) {
            continue;
        }

        $visited[$current_id] = true;

        $stmtMember = $pdo->prepare("
            SELECT member_id
            FROM members
            WHERE member_id = ? AND family_id = ?
        ");
        $stmtMember->execute([$current_id, $family_id]);

        if (!$stmtMember->fetchColumn()) {
            continue;
        }

        $result[] = $current_id;

        $stmtChildren = $pdo->prepare("
            SELECT m.member_id
            FROM relationships r
            JOIN members m ON m.member_id = r.child_id
            WHERE r.parent_id = ?
              AND m.family_id = ?
        ");
        $stmtChildren->execute([$current_id, $family_id]);

        foreach ($stmtChildren->fetchAll(PDO::FETCH_COLUMN) as $child_id) {
            $stack[] = (int) $child_id;
        }
    }

    return $result;
}

function splitMemberToNewFamily($member_id, $family_id, $new_family_name, $description, $created_by) {
    global $pdo;

    $member_id = (int) $member_id;
    $family_id = (int) $family_id;
    $new_family_name = trim($new_family_name);
    $description = trim($description);

    if ($new_family_name === '') {
        return 'Vui lòng nhập tên gia tộc mới.';
    }

    $stmtMember = $pdo->prepare("
        SELECT member_id, full_name, generation
        FROM members
        WHERE member_id = ? AND family_id = ?
    ");
    $stmtMember->execute([$member_id, $family_id]);
    $splitMember = $stmtMember->fetch(PDO::FETCH_ASSOC);

    if (!$splitMember) {
        return 'Không tìm thấy thành viên cần tách trong gia tộc hiện tại.';
    }

    $oldGeneration = (int) $splitMember['generation'];

    if ($oldGeneration <= 1) {
        return 'Không thể tách cụ tổ đời 1 thành gia tộc mới.';
    }

    $memberIds = getDescendantMemberIdsInFamily($member_id, $family_id);

    if (empty($memberIds)) {
        return 'Không tìm thấy nhánh thành viên cần tách.';
    }

    try {
        $pdo->beginTransaction();

        $stmtFamily = $pdo->prepare("
            INSERT INTO families
                (family_name, description, created_by, parent_family_id, split_from_member_id, split_from_generation,
                 package_id, package_start_date, package_end_date, package_status)
            SELECT ?, ?, ?, ?, ?, ?,
                   package_id, package_start_date, package_end_date, package_status
            FROM families
            WHERE family_id = ?
        ");
        $stmtFamily->execute([
            $new_family_name,
            $description,
            $created_by,
            $family_id,
            $member_id,
            $oldGeneration,
            $family_id
        ]);

        $newFamilyId = (int) $pdo->lastInsertId();
        $memberPlaceholders = implode(',', array_fill(0, count($memberIds), '?'));

        $stmtWives = $pdo->prepare("
            SELECT DISTINCT wife_id
            FROM marriages
            WHERE husband_id IN ($memberPlaceholders)
        ");
        $stmtWives->execute($memberIds);
        $wifeIds = array_values(array_filter($stmtWives->fetchAll(PDO::FETCH_COLUMN)));

        $stmtUpdateMembers = $pdo->prepare("
            UPDATE members
            SET family_id = ?,
                generation = generation - ? + 1
            WHERE member_id IN ($memberPlaceholders)
        ");
        $stmtUpdateMembers->execute(array_merge([$newFamilyId, $oldGeneration], $memberIds));

        if (!empty($wifeIds)) {
            $wifePlaceholders = implode(',', array_fill(0, count($wifeIds), '?'));
            $stmtUpdateWives = $pdo->prepare("
                UPDATE daughters_in_law
                SET family_id = ?
                WHERE family_id = ?
                  AND daughter_in_law_id IN ($wifePlaceholders)
            ");
            $stmtUpdateWives->execute(array_merge([$newFamilyId, $family_id], $wifeIds));
        }

        $pdo->commit();

        return [
            'new_family_id' => $newFamilyId,
            'moved_members' => count($memberIds),
            'moved_wives' => count($wifeIds),
            'old_generation' => $oldGeneration,
            'split_member_name' => $splitMember['full_name']
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return 'Lỗi: Không thể tách gia tộc mới. ' . $e->getMessage();
    }
}

// Hàm kiểm tra xem admin có quyền quản lý gia tộc $family_id hay không
function adminCanManageFamily(int $family_id, PDO $pdo): bool {
    $managedFamilyId = $_SESSION['managed_family_id'] ?? null;
    if (!$managedFamilyId) return false;

    if ((int)$managedFamilyId === $family_id) return true;

    // Kiểm tra $family_id có phải là con/cháu (mọi cấp) của managedFamilyId không
    // Dùng truy vấn đệ quy (CTE) để leo lên tổ tiên của $family_id
    $stmt = $pdo->prepare("
        WITH FamilyAncestors AS (
            SELECT family_id, parent_family_id
            FROM families
            WHERE family_id = ?
            UNION ALL
            SELECT f.family_id, f.parent_family_id
            FROM families f
            INNER JOIN FamilyAncestors fa ON f.family_id = fa.parent_family_id
        )
        SELECT COUNT(*) FROM FamilyAncestors WHERE family_id = ?
    ");
    $stmt->execute([$family_id, $managedFamilyId]);
    return (bool) $stmt->fetchColumn();
}

function requireAdminFamilyAccess(int $familyId, PDO $pdo, string $redirectUrl = 'manage_family.php'): void {
    if (!adminCanManageFamily($familyId, $pdo)) {
        redirect($redirectUrl, 'Bạn không có quyền quản lý gia tộc này!', 'error');
    }
}

/**
 * Chuẩn hóa họ tên tiếng Việt trước khi so sánh.
 * Dấu có thể được nhập/lưu ở dạng ký tự ghép hoặc ký tự Unicode dựng sẵn,
 * tuy hiển thị giống nhau nhưng giá trị chuỗi lại khác nhau.
 */
function normalizeVietnameseSurname(string $fullName): string {
    $parts = preg_split('/\s+/u', trim($fullName));
    $surname = $parts[0] ?? '';

    // Chuyển dạng dấu ghép (ví dụ: y + dấu huyền) về ký tự nền.
    $surname = preg_replace('/[\x{0300}-\x{036F}]/u', '', $surname) ?? $surname;

    $accentMap = [
        'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a',
        'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a',
        'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
        'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e',
        'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
        'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o',
        'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o',
        'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u',
        'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
        'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y', 'đ' => 'd',
        'À' => 'A', 'Á' => 'A', 'Ả' => 'A', 'Ã' => 'A', 'Ạ' => 'A',
        'Ă' => 'A', 'Ằ' => 'A', 'Ắ' => 'A', 'Ẳ' => 'A', 'Ẵ' => 'A', 'Ặ' => 'A',
        'Â' => 'A', 'Ầ' => 'A', 'Ấ' => 'A', 'Ẩ' => 'A', 'Ẫ' => 'A', 'Ậ' => 'A',
        'È' => 'E', 'É' => 'E', 'Ẻ' => 'E', 'Ẽ' => 'E', 'Ẹ' => 'E',
        'Ê' => 'E', 'Ề' => 'E', 'Ế' => 'E', 'Ể' => 'E', 'Ễ' => 'E', 'Ệ' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Ỉ' => 'I', 'Ĩ' => 'I', 'Ị' => 'I',
        'Ò' => 'O', 'Ó' => 'O', 'Ỏ' => 'O', 'Õ' => 'O', 'Ọ' => 'O',
        'Ô' => 'O', 'Ồ' => 'O', 'Ố' => 'O', 'Ổ' => 'O', 'Ỗ' => 'O', 'Ộ' => 'O',
        'Ơ' => 'O', 'Ờ' => 'O', 'Ớ' => 'O', 'Ở' => 'O', 'Ỡ' => 'O', 'Ợ' => 'O',
        'Ù' => 'U', 'Ú' => 'U', 'Ủ' => 'U', 'Ũ' => 'U', 'Ụ' => 'U',
        'Ư' => 'U', 'Ừ' => 'U', 'Ứ' => 'U', 'Ử' => 'U', 'Ữ' => 'U', 'Ự' => 'U',
        'Ỳ' => 'Y', 'Ý' => 'Y', 'Ỷ' => 'Y', 'Ỹ' => 'Y', 'Ỵ' => 'Y', 'Đ' => 'D',
    ];

    return strtolower(strtr($surname, $accentMap));
}

// Gia tộc cũ chưa được gán gói vẫn được phép truy cập để Owner hoàn tất việc cấu hình gói.
// Khi đã có gói, việc truy cập khu vực quản lý phụ thuộc trực tiếp vào khoảng ngày hiệu lực.
function familySubscriptionIsActive(int $family_id, PDO $pdo): bool {
    $stmt = $pdo->prepare('
        SELECT package_id, package_start_date, package_end_date, package_status
        FROM families
        WHERE family_id = ?
    ');
    $stmt->execute([$family_id]);
    $family = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$family || $family['package_id'] === null) {
        return true;
    }
    if (!$family['package_start_date'] || !$family['package_end_date']) {
        return false;
    }
    if (($family['package_status'] ?? '') !== 'ACTIVE') {
        return false;
    }

    $today = date('Y-m-d');
    return $family['package_start_date'] <= $today && $family['package_end_date'] >= $today;
}

/**
 * Chặn việc thêm thành viên khi tổng số thành viên của cả gia tộc gốc và
 * mọi chi nhánh đã đạt giới hạn của gói dịch vụ gia tộc gốc.
 */
function ensureFamilyMemberLimitAvailable(int $familyId, PDO $pdo): void {
    $stmt = $pdo->prepare(' 
        WITH FamilyAncestors AS (
            SELECT family_id, parent_family_id, package_id
            FROM families
            WHERE family_id = ?
            UNION ALL
            SELECT f.family_id, f.parent_family_id, f.package_id
            FROM families f
            INNER JOIN FamilyAncestors fa ON fa.parent_family_id = f.family_id
        ),
        RootFamily AS (
            SELECT TOP 1 family_id, package_id
            FROM FamilyAncestors
            WHERE parent_family_id IS NULL
        ),
        FamilyBranches AS (
            SELECT family_id
            FROM RootFamily
            UNION ALL
            SELECT f.family_id
            FROM families f
            INNER JOIN FamilyBranches fb ON f.parent_family_id = fb.family_id
        )
        SELECT sp.member_limit, COUNT(m.member_id) AS total_members
        FROM RootFamily rf
        LEFT JOIN service_packages sp ON sp.package_id = rf.package_id
        LEFT JOIN FamilyBranches fb ON 1 = 1
        LEFT JOIN members m ON m.family_id = fb.family_id
        GROUP BY sp.member_limit
        OPTION (MAXRECURSION 32767)
    ');
    $stmt->execute([$familyId]);
    $usage = $stmt->fetch(PDO::FETCH_ASSOC);

    // Gia tộc chưa gán gói hoặc gói không giới hạn thành viên.
    if (!$usage || $usage['member_limit'] === null) {
        return;
    }

    if ((int) $usage['total_members'] >= (int) $usage['member_limit']) {
        throw new InvalidArgumentException(
            'Gia tộc đã đạt giới hạn ' . number_format((int) $usage['member_limit'])
            . ' thành viên của gói dịch vụ. Vui lòng nâng cấp gói dịch vụ để thêm thành viên mới.'
        );
    }
}

/**
 * Lấy thông tin gói và khoảng thời gian sử dụng tương ứng.
 *
 * Ngày kết thúc là ngày liền trước cùng ngày của tháng kế tiếp sau khi hết
 * thời hạn. Ví dụ: gói 1 tháng bắt đầu 22/08 sẽ hết hạn vào 21/09.
 *
 * @return array{0: array<string, mixed>, 1: string, 2: string}
 */
function getPackageSubscription(PDO $pdo, int $packageId, string $startDate): array {
    $start = DateTime::createFromFormat('!Y-m-d', $startDate);
    $dateErrors = DateTime::getLastErrors();
    if (!$start || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
        || $start->format('Y-m-d') !== $startDate) {
        throw new InvalidArgumentException('Ngày bắt đầu gói dịch vụ không hợp lệ.');
    }

    $stmt = $pdo->prepare('
        SELECT package_id, package_name, price, duration_month, member_limit, status, payment_type
        FROM service_packages
        WHERE package_id = ?
    ');
    $stmt->execute([$packageId]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$package) {
        throw new InvalidArgumentException('Gói dịch vụ không tồn tại.');
    }
    if ($package['status'] !== 'ACTIVE') {
        throw new InvalidArgumentException('Gói dịch vụ này hiện không hoạt động.');
    }
    if ((int) $package['duration_month'] <= 0) {
        throw new InvalidArgumentException('Thời hạn của gói dịch vụ không hợp lệ.');
    }
    if (!in_array($package['payment_type'], ['FREE', 'PAID'], true)) {
        throw new InvalidArgumentException('Loại thanh toán của gói dịch vụ không hợp lệ.');
    }

    $end = (clone $start)
        ->modify('+' . (int) $package['duration_month'] . ' months')
        ->modify('-1 day');

    return [$package, $start->format('Y-m-d'), $end->format('Y-m-d')];
}

/**
 * Kiểm tra tổng số thành viên của gia tộc gốc và mọi chi nhánh có phù hợp
 * với giới hạn của gói chuẩn bị áp dụng hay không.
 *
 * @param array<string, mixed> $package
 */
function ensureFamilyFitsPackageMemberLimit(PDO $pdo, int $rootFamilyId, array $package): void {
    if ($package['member_limit'] === null) {
        return;
    }

    $stmt = $pdo->prepare('
        WITH FamilyBranches AS (
            SELECT family_id
            FROM families
            WHERE family_id = ?
            UNION ALL
            SELECT f.family_id
            FROM families f
            INNER JOIN FamilyBranches fb ON f.parent_family_id = fb.family_id
        )
        SELECT COUNT(m.member_id)
        FROM FamilyBranches fb
        LEFT JOIN members m ON m.family_id = fb.family_id
        OPTION (MAXRECURSION 32767)
    ');
    $stmt->execute([$rootFamilyId]);
    $totalMembers = (int) $stmt->fetchColumn();
    $memberLimit = (int) $package['member_limit'];

    if ($totalMembers > $memberLimit) {
        throw new InvalidArgumentException(
            'Không thể áp dụng gói "' . $package['package_name'] . '" vì gia tộc đang có '
            . number_format($totalMembers) . ' thành viên, vượt quá giới hạn '
            . number_format($memberLimit) . ' thành viên của gói.'
        );
    }
}

/**
 * Đăng ký gói dịch vụ lần đầu cho một gia tộc gốc.
 * Gói FREE được kích hoạt ngay; gói PAID chuyển sang chờ xác nhận thanh toán.
 *
 * @return array{0: array<string, mixed>, 1: string, 2: string, 3: string}
 */
function register_service_packages(PDO $pdo, int $familyId, int $packageId, string $startDate): array {
    $stmt = $pdo->prepare('SELECT family_id, parent_family_id FROM families WHERE family_id = ?');
    $stmt->execute([$familyId]);
    $family = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$family) {
        throw new InvalidArgumentException('Gia tộc không tồn tại.');
    }
    if ($family['parent_family_id'] !== null) {
        throw new InvalidArgumentException('Chỉ có thể đăng ký gói dịch vụ từ gia tộc gốc.');
    }

    [$package, $subscriptionStart, $subscriptionEnd] = getPackageSubscription($pdo, $packageId, $startDate);
    // Kiểm tra trước khi cập nhật để transaction có thể dừng hoàn toàn nếu vượt hạn mức.
    ensureFamilyFitsPackageMemberLimit($pdo, $familyId, $package);
    $packageStatus = $package['payment_type'] === 'FREE' ? 'ACTIVE' : 'PENDING_PAYMENT';

    propagateFamilySubscription(
        $pdo,
        $familyId,
        $packageId,
        $subscriptionStart,
        $subscriptionEnd,
        $packageStatus
    );

    return [$package, $packageStatus, $subscriptionStart, $subscriptionEnd];
}

/**
 * Cập nhật gói dịch vụ. Quy tắc thanh toán giống đăng ký mới để không thể
 * kích hoạt gói PAID khi đổi gói, còn gói FREE luôn có hiệu lực ngay.
 *
 * @return array{0: array<string, mixed>, 1: string, 2: string, 3: string}
 */
function update_service_packages(PDO $pdo, int $familyId, int $packageId, string $startDate): array {
    return register_service_packages($pdo, $familyId, $packageId, $startDate);
}

/** Đồng bộ thông tin gói từ gia tộc gốc đến toàn bộ chi nhánh bên dưới. */
function propagateFamilySubscription(
    PDO $pdo,
    int $rootFamilyId,
    ?int $packageId,
    string $startDate,
    string $endDate,
    string $packageStatus
): void {
    $stmt = $pdo->prepare(" 
        WITH FamilyBranches AS (
            SELECT family_id
            FROM families
            WHERE family_id = ?
            UNION ALL
            SELECT f.family_id
            FROM families f
            INNER JOIN FamilyBranches fb ON f.parent_family_id = fb.family_id
        )
        UPDATE f
        SET package_id = COALESCE(?, f.package_id),
            package_start_date = ?,
            package_end_date = ?,
            package_status = ?
        FROM families f
        INNER JOIN FamilyBranches fb ON f.family_id = fb.family_id
        OPTION (MAXRECURSION 32767)
    ");
    $stmt->execute([$rootFamilyId, $packageId, $startDate, $endDate, $packageStatus]);
}

function requireActiveFamilySubscription(int $family_id, PDO $pdo, string $redirectUrl = 'manage_family.php'): void {
    if (!familySubscriptionIsActive($family_id, $pdo)) {
        redirect($redirectUrl, 'Dịch vụ của gia tộc đã hết hạn. Vui lòng gia hạn để tiếp tục quản lý.', 'error');
    }
}
?>

