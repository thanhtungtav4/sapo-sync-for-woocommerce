# Inventory Bridge for Sapo

Plugin tích hợp WooCommerce với Sapo Omni/POS, không phụ thuộc theme hoặc thương hiệu cửa hàng.

## Trạng thái

Phiên bản `0.5.2` bổ sung hardening cho bảo mật credential, reconciliation, order contract và event delivery trên nền branding công khai phù hợp guideline WordPress:

- Có plugin header hợp lệ để WordPress nhận diện plugin.
- Có autoload/bootstrap, activation migration và ba bảng mapping–outbox–event inbox.
- Có contract `SapoGateway`, capability gate và các domain service độc lập để kiểm thử.
- Khi admin cấu hình connection, plugin đọc sản phẩm/biến thể, location và `inventory_levels`
  qua Sapo Admin API; webhook product/store enqueue reconciliation và polling tồn kho chạy mỗi phút.
- Capability gate vẫn khóa toàn bộ thao tác ghi đơn cho đến khi contract Omni/POS được xác minh.
- Tồn kho được chia batch theo variant/location; scheduled inventory và mapping có lock tự thu hồi
  khi worker chết, tránh chạy chồng và vượt giới hạn URL.
- Event inbox có claim/lease, retry exponential backoff, trạng thái `RETRY` và trả lỗi 5xx khi
  webhook không lưu được hoặc không đưa được vào queue.
- Fingerprint webhook ổn định qua khác biệt format JSON; `remote_modified_at` được chuẩn hóa về
  UTC MySQL datetime.
- Manual mapping xác minh remote product/variant, SKU và product type trước khi chuyển `ACTIVE`.
- Adapter có customer create, order lookup, order state và cancel primitives theo Admin API công khai;
  create-and-approve dùng `POST /admin/orders.json` (Sapo demo tự xác nhận order) và cancel
  `order_cancel.cancel_reason`; capability chỉ mở sau nút smoke test tạo + hủy order test trên
  connection hiện tại.
- Có ba execution profile: Automatic (Action Scheduler/WP-Cron), External (cron server gọi REST runner)
  và Hybrid (cả hai). External runner dùng token riêng, job lock và chạy reconciliation/queue idempotent.
- Khi Sapo trả 401/403, capability snapshot bị vô hiệu hóa ngay; lịch sử event/outbox terminal được dọn
  theo retention window để database không phình vô hạn.
- Màn hình quản trị ưu tiên trạng thái production; contract test tạo + hủy order được chuyển vào mục
  Advanced để không nhầm với luồng đồng bộ hằng ngày.

Giữ chế độ Shadow trong giai đoạn đối soát; chỉ bật Write sau khi mapping và contract test đạt.

Kiểm tra nhanh trong repository:

```bash
php tests/run.php
```

Smoke test này không cần WordPress hoặc credentials Sapo; nó kiểm tra chuẩn hóa SKU,
external reference, phân kho không tách đơn, mapping trạng thái và adapter đọc bằng HTTP fixture.

## Tài liệu tham chiếu

- [Kế hoạch triển khai](docs/IMPLEMENTATION-PLAN.md)
- [Luồng đồng bộ](docs/SYNC-FLOWS.md)
- [Quan sát Sapo Demo](docs/DEMO-OBSERVATIONS.md)
- [Contract Sapo Demo đã khử](docs/SAPO-DEMO-CONTRACT.md)

## Thành phần runtime đã có

- `src/Autoloader.php` và `src/Application/Plugin.php`: bootstrap, activation/deactivation lifecycle.
- `src/Infrastructure/WordPress/Installer.php`: migration cho mapping, outbox và event inbox.
- `src/Contracts/SapoGateway.php`: boundary duy nhất để adapter Sapo thật được nối sau capability gate.
- `src/Infrastructure/Sapo/Http/`: WordPress HTTP transport và response validator với error taxonomy thống nhất.
- `src/Webhook/`: REST endpoint nhận webhook bằng HMAC hoặc signed URL token, hỗ trợ topic header, chuẩn hóa envelope và dedupe event inbox.
- `src/Application/SapoEventWorker.php`: đọc state theo event, bỏ event cũ/trùng, cập nhật trạng thái và tracking qua Woo CRUD.
- Event product/store (và inventory/stock nếu tài khoản phát) được dedupe trong inbox rồi enqueue mapping/inventory reconciliation nền, không chạy API tồn trực tiếp trong request webhook.
- `src/Application/OrderSyncHooks.php` và `OrderSyncWorker.php`: nhận đơn `processing`, tạo outbox idempotent, resolve customer/mapping/giá và retry lỗi tạm thời.
- Retry timeout/rate-limit/5xx được lưu `next_attempt_at` và enqueue lại đúng thời điểm qua Action Scheduler/WP-Cron; thiếu queue chuyển `NEEDS_REVIEW`.
- Hook hủy đơn tạo operation riêng; worker chỉ gọi hủy Sapo khi đơn chưa đóng gói/xuất kho, không tự hoàn tồn khi refund.
- Nếu Woo hủy trước khi Sapo có ID, các create operation còn `PENDING/RETRY` được chuyển `CANCELLED` để không tạo đơn muộn.
- `src/Infrastructure/WooCommerce/OrderSnapshotBuilder.php`: đọc order bằng Woo CRUD/HPOS-safe, không truy cập trực tiếp posts/postmeta.
- `src/Application/CheckoutLocationAllocator.php`: kiểm tra toàn bộ cart đủ tồn tại một location và ghi `_woo_sapo_assigned_location` vào order; worker re-check lại trước khi tạo đơn.
- `src/Application/InventoryReconciler.php`: đọc `available` theo location, shadow reconciliation và chỉ ghi Woo khi bật write mode.
- Location policy fail-closed: chưa allowlist chi nhánh hoặc thiếu response tồn thì không ghi Woo về 0.
- `src/Application/MappingSynchronizer.php`: quét catalog Woo/Sapo theo trang, lưu mapping SKU và bảo toàn mapping cũ khi cần duyệt.
- `src/Admin/Settings.php`: cấu hình shadow/write, execution profile, external cron token, webhook secret
  (không echo lại) và location policy JSON.
- `src/Admin/ConnectionSettings.php`: lưu base URL HTTPS và thông tin Basic/Bearer ở field mật khẩu; không render lại credential.
- `src/Infrastructure/WordPress/SyncLogger.php`: log vận hành qua Woo logger, có correlation ID và che dữ liệu nhạy cảm.
- `src/Admin/OperationsPage.php`: dashboard backlog/status mapping–outbox–event inbox, không hiển thị payload/credentials.
- `src/Admin/MappingPage.php`: tìm/lọc mapping theo SKU/trạng thái và liên kết thủ công có nonce, kiểm tra trùng Sapo variant.
- `src/Infrastructure/WordPress/Scheduler.php`: lịch inventory reconciliation mỗi phút bằng Action Scheduler hoặc WP-Cron fallback; webhook vẫn là đường nhanh hơn.
- `src/Cron/ExternalCronController.php`: REST runner có token cho máy chủ cron bên ngoài khi WP-Cron bị tắt.
- Mapping reconciliation nightly được chạy cùng scheduler để phát hiện SKU đổi/trùng/mất.
- `src/Admin/CapabilityPage.php`: trang chẩn đoán capability, không cho phép đánh dấu thủ công.
- `src/Application/CapabilityVerifier.php`: probe gateway và ghi kết quả capability; factory dùng `SapoAdminGateway` khi connection đã cấu hình và fail-closed khi chưa có.
- `GatewayFactory` có filter `woo_sapo_gateway` làm composition seam cho adapter đã qua contract test; giá trị không phải `SapoGateway` vẫn bị bỏ qua.
- `tests/fixtures/contract/` và `tests/FixtureGateway.php`: fixture normalized/harness offline để kiểm tra contract trước khi nối adapter thật.
- `src/Infrastructure/WordPress/Repository/`: persistence idempotency và dedupe ở database.
- `src/Domain/`: SKU, product type, price source, allocation và mapping trạng thái độc lập với WordPress.

## Cron bên ngoài

Mở WooCommerce → Inventory Bridge, chọn `External` hoặc `Hybrid`, đặt một token riêng rồi lưu cấu hình. Cron
server gọi endpoint mỗi phút bằng header Bearer:

```bash
curl -fsS -X POST \
  -H 'Authorization: Bearer YOUR_CRON_TOKEN' \
  https://example.com/wp-json/woo-sapo/v1/cron
```

Runner trả HTTP `202`, chạy inventory/event reconciliation mỗi tick, mapping theo nhịp hằng ngày
và giải phóng queue Action Scheduler/WP-Cron. Không đưa token vào URL hoặc commit token vào
repository.

## Phạm vi MVP

- Sản phẩm thường và biến thể.
- Mapping ban đầu bằng SKU; vận hành bằng Woo ID và Sapo ID đã lưu.
- Tồn kho Sapo → WooCommerce theo chi nhánh xử lý đơn.
- Đơn hàng WooCommerce → Sapo qua hàng đợi.
- Trạng thái xử lý và mã vận đơn Sapo → WooCommerce.
- Giá được chọn nguồn theo từng SKU.
- Quà tặng có quản lý tồn phải là dòng hàng WooCommerce có SKU.

Combo, sản phẩm quy đổi, lô–HSD và serial/IMEI nằm ngoài MVP. Kiến trúc phải cho phép bổ sung type handler về sau mà không phá vỡ mapping hoặc hàng đợi hiện có.

## Quy ước

- Plugin slug: `inventory-bridge-for-sapo`
- Text domain: `sapo-sync-for-woocommerce` (retained for upgrade compatibility)
- PHP namespace dự kiến: `WooSapoSync`
- Prefix constant: `WOO_SAPO_SYNC_`
- Prefix bảng dữ liệu dự kiến: `wss_sapo_`
