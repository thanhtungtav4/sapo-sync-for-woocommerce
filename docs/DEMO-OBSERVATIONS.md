# Sapo Demo — kiểm tra thực tế

Ngày kiểm tra ban đầu: `2026-08-17`
Ngày bổ sung fixture: `2026-08-18`

Các quan sát dưới đây được đọc từ phiên Sapo Demo đang đăng nhập, không sửa dữ liệu:

- Màn quản lý chi nhánh cho thấy một chi nhánh quản lý kho đang hoạt động: **Cửa hàng chính**.
- Màn “Cài đặt chi nhánh nhận đơn online” chỉ có chi nhánh này trong danh sách ưu tiên.
- Màn tồn kho dùng URL có `location_ids=941850`; đây chỉ là ID của demo, không hard-code vào plugin.
- Quy trình xử lý đơn đang chọn **Cơ bản**.
- Lần kiểm tra ban đầu catalog demo chưa có sản phẩm; UI hiển thị “Cửa hàng của bạn chưa có sản phẩm nào” và
  `GET /admin/products.json` trả `{"products":[]}`.
- Theo ủy quyền của chủ tài khoản, đã tạo hai dữ liệu giả để capture contract: một sản phẩm thường
  `Sapo Sync for WooCommerce Demo Simple` (SKU `PX-DEMO-SIMPLE-001`, giá `99.000`, tồn khả dụng `5`) và một sản phẩm
  có thuộc tính Màu với hai biến thể `PX-DEMO-VARIANT-RED` (149.000, tồn `3`) và
  `PX-DEMO-VARIANT-BLUE` (129.000, tồn `4`). Chi tiết đã khử thông tin nhạy cảm nằm ở
  [Sapo Demo Contract](SAPO-DEMO-CONTRACT.md).
- Từ session trình duyệt đã đăng nhập, UI gọi các request đọc nội bộ gồm product detail,
  location và inventory level. Các request này chỉ là bằng chứng của Sapo Web admin; không được
  dùng cookie trình duyệt, ID demo hoặc đường dẫn `/admin/*.json` trong plugin production.

## Smoke test sau khi cấp quyền Chi nhánh

Ngày `2026-08-18`, Private App `Sapo Sync for WooCommerce Test` được cấp `Chi nhánh — Chỉ đọc`.
Các request read-only mà adapter dùng đã trả kết quả:

| Request | Status | Kết quả khử thông tin nhạy cảm |
| --- | ---: | --- |
| `GET /admin/products.json?page=1&limit=5` | 200 | 2 sản phẩm, 3 biến thể có SKU |
| `GET /admin/locations.json?inventory_management=true&page=1&limit=5` | 200 | 1 chi nhánh đang quản lý kho |
| `GET /admin/inventory_levels.json` | 200 | 3 dòng tồn, có `available` theo location |
| `GET /admin/customers.json?limit=5` | 200 | 0 khách hàng demo |
| `GET /admin/orders.json?page=1&limit=5` | 200 | 0 đơn hàng demo |
| `GET /admin/webhooks.json?page=1&limit=250` | 200 | Chưa đăng ký webhook nào |

Smoke test read-side này chỉ gọi GET, không tạo đơn, không tạo webhook và không lưu credential.
Adapter đọc đã đủ dữ liệu để chạy mapping/tồn. Sau đó contract write-side được kiểm tra riêng
bằng order test có thể hủy và hoàn nguyên, được ghi lại trong [Sapo Demo Contract](SAPO-DEMO-CONTRACT.md).

## Ý nghĩa đối với plugin

1. Bảng `locations` và thứ tự ưu tiên phải là dữ liệu đồng bộ/cấu hình, không dùng ID cố định.
2. Test SKU, variation và tồn khả dụng cần fixture riêng; fixture đã được bổ sung từ dữ liệu giả,
   nhưng vẫn được đánh dấu là **Sapo Web admin observation**, chưa phải contract Omni/POS public.
3. `/admin/*.json` là endpoint của giao diện Sapo Web. Chưa coi đó là contract API Omni/POS cho plugin.
4. Authentication, phân trang, tồn theo location và polling đã được smoke test trên Private App.
   Contract POST cho tạo/duyệt, external reference, trạng thái và hủy đã có fixture và nút
   smoke test chạy lại trên từng connection trước khi mở capability ghi.
