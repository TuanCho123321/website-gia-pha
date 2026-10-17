<div class="mb-3">
    <label class="form-label">Tên gói <span class="text-danger">*</span></label>
    <input class="form-control" name="package_name" maxlength="100" required placeholder="Ví dụ: Gói Cơ bản">
</div>
<div class="row">
    <div class="col-md-6 mb-3"><label class="form-label">Giá (VNĐ) <span class="text-danger">*</span></label><input class="form-control" name="price" type="number" min="0" step="0.01" value="0" required></div>
    <div class="col-md-6 mb-3"><label class="form-label">Thời hạn (tháng) <span class="text-danger">*</span></label><input class="form-control" name="duration_month" type="number" min="1" value="1" required></div>
</div>
<div class="mb-3"><label class="form-label">Giới hạn thành viên</label><input class="form-control" name="member_limit" type="number" min="1" placeholder="Để trống nếu không giới hạn"></div>
<div class="row">
    <div class="col-md-6 mb-3"><label class="form-label">Loại thanh toán</label><select class="form-select" name="payment_type"><option value="FREE">Miễn phí</option><option value="PAID">Trả phí</option></select></div>
    <div class="col-md-6 mb-3"><label class="form-label">Trạng thái</label><select class="form-select" name="status"><option value="ACTIVE">Đang hoạt động</option><option value="INACTIVE">Ngừng hoạt động</option></select></div>
</div>
