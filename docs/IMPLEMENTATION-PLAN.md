# Kế hoạch triển khai WooCommerce – Sapo Omni/POS

## 1. Mục tiêu và quyết định đã chốt

Sapo Omni/POS là hệ thống gốc cho sản phẩm, tồn khả dụng, chi nhánh xử lý và tiến trình giao hàng. WooCommerce quản lý nội dung website, giỏ hàng, checkout và đơn phát sinh trên `woocommerce-sapo-sync.example`.

Các quyết định nghiệp vụ:

| Hạng mục | Quyết định |
| --- | --- |
| Ghép sản phẩm | Dùng SKU để tìm mapping lần đầu; sau đó dùng Woo ID và Sapo ID |
| Loại sản phẩm MVP | Sản phẩm thường và biến thể |
| Loại ngoài MVP | Combo, quy đổi, lô–HSD, serial/IMEI |
| Tồn kho gốc | `available`/“Có thể bán” của Sapo theo chi nhánh |
| Phân kho | Đúng vùng giao → đủ toàn bộ SKU → thứ tự ưu tiên Sapo |
| Tách đơn | Không tách một đơn qua nhiều chi nhánh |
| Thiếu hàng | Chặn trước thanh toán; lỗi cạnh tranh sau thanh toán chuyển duyệt tay |
| Thời điểm giữ tồn | Khi đơn được chấp nhận: COD hoặc thanh toán thành công vào `processing` |
| Giá | Chọn nguồn `Woo` hoặc `Sapo` theo từng SKU, có cấu hình mặc định toàn hệ thống |
| Khách hàng | Tìm theo SĐT Việt Nam đã chuẩn hóa, sau đó theo email |
| Quà tặng | Là dòng hàng Woo thật có SKU, số lượng và giá đơn `0`/chiết khấu đã xác minh |
| Hủy/hoàn | Hoàn tiền không tự hoàn kho; chỉ hoàn kho khi Sapo xác nhận đã nhận hàng |

Không sử dụng tên, slug, content hoặc ảnh để tự động ghép sản phẩm.

## 2. Cổng xác minh API Sapo

Tài liệu API public `/admin/*.json` hiện tìm thấy thuộc Sapo Web, không được dùng để suy đoán contract của Sapo Omni/POS. Trước khi viết tính năng, integration spike phải xác minh trên tài khoản test:

1. Base URL, xác thực, scopes, vòng đời token và giới hạn request.
2. Danh sách chi nhánh và vùng phục vụ.
3. Danh sách sản phẩm/biến thể có phân trang và mốc `modified_at`.
4. Tồn `available` theo `variant_id + location_id`.
5. Giá theo chính sách giá.
6. Tìm/tạo khách hàng.
7. Tạo, duyệt, tìm theo external reference và hủy đơn.
8. Trạng thái đơn, thanh toán, xử lý, giao hàng, trả hàng và mã vận đơn.
9. Webhook, chữ ký, event ID và hành vi gửi lại; nếu không có thì xác nhận polling thay thế.
10. Đơn thử gồm COD, thanh toán online, phí vận chuyển, giảm giá và quà tặng.

Mỗi response cần được lưu thành fixture đã xóa token và PII để làm contract test. Nếu không tìm được đơn bằng external reference hoặc không lấy được tồn theo chi nhánh, dừng triển khai đồng bộ thật và hiển thị capability bị thiếu trong trang chẩn đoán.

### Bằng chứng tài liệu và giới hạn suy luận

- Sapo Web Private Apps mô tả Basic Authentication cho các endpoint `/admin/*.json`; đây là
  contract của Sapo Web, không tự động suy ra contract Omni/POS.
- Tài liệu Product Variant của Sapo Web có `sku`, `inventory_quantity`, `modified_on` và phân
  trang theo `limit/page`; các trường này hữu ích để đối chiếu nhưng không thay thế tồn
  `available` theo chi nhánh của Omni.
- Tài liệu Sapo Omni phân biệt tồn thực tế với “Có thể bán” (đã trừ lượng đang giữ) và cho phép
  xem theo từng chi nhánh. Vì vậy adapter phải lấy đúng chỉ số `available` theo location, không
  được dùng `inventory_quantity` của Sapo Web làm tồn gốc.
- Tài liệu tạo/duyệt đơn Omni và cấu hình chi nhánh là bằng chứng nghiệp vụ; endpoint, auth,
  payload và event schema vẫn phải được capture trên tài khoản test trước khi code adapter.
- Tài liệu Webhook của Sapo có topic sản phẩm, đơn hàng, khách hàng, fulfillment và store/update,
  nhưng không công bố topic tồn kho riêng. Vì vậy realtime tồn kho dùng hai lớp: webhook sản phẩm/
  store kích hoạt reconciliation ngay, cộng polling `inventory_levels` mỗi phút làm safety net.
  Không gọi đây là push tồn kho tuyệt đối cho đến khi Sapo xác nhận event inventory.

Contract nội bộ dự kiến:

```php
interface SapoGateway
{
    public function testConnection(): ConnectionResult;
    public function listLocations(): LocationCollection;
    public function listVariants(VariantCursor $cursor): VariantPage;
    public function getAvailability(AvailabilityQuery $query): AvailabilityCollection;
    public function getPrices(PriceQuery $query): PriceCollection;
    public function findCustomer(CustomerLookup $lookup): ?SapoCustomer;
    public function createCustomer(CreateCustomerCommand $command): SapoCustomer;
    public function findOrderByExternalReference(string $reference): ?SapoOrder;
    public function createAndApproveOrder(CreateSapoOrderCommand $command): SapoOrder;
    public function getOrderState(string $sapoOrderId): SapoOrderState;
    public function cancelOrder(CancelSapoOrderCommand $command): CancelResult;
}

Integration spike ngày `2026-08-18` đã capture được hình dạng sản phẩm thường, product parent
có option/biến thể và `inventory_levels.available` theo location từ Sapo Demo; xem
[fixture quan sát đã khử](SAPO-DEMO-CONTRACT.md). Capture này xác nhận SKU và `available` là
điểm nối đúng cho MVP, nhưng các URL `/admin/*.json` vẫn là request của Sapo Web admin. Adapter
đọc `SapoAdminGateway` đã được nối vào `GatewayFactory` khi admin cấu hình Private App/OAuth;
capability gate vẫn khóa toàn bộ thao tác ghi cho đến khi contract Omni/POS được xác minh.
```

Adapter chịu trách nhiệm validate response Sapo và chuyển lỗi về một taxonomy thống nhất: `AUTH`, `RATE_LIMIT`, `TIMEOUT`, `VALIDATION`, `NOT_FOUND`, `CONFLICT`, `REMOTE_SERVER`, `UNSUPPORTED_CAPABILITY`.

Nền tảng đã triển khai trong phiên bản `0.4.0`: PSR-4-style autoloader, bootstrap lifecycle,
activation migration cho ba bảng, `CapabilityGate`, trang chẩn đoán, `SapoGateway` an toàn
(`UnavailableGateway`), repository mapping/outbox/event inbox, domain service thuần và
WordPress HTTP transport có response validator/error taxonomy. REST webhook receiver đã có
HMAC/signed-token verification, topic header compatibility và dedupe event inbox; read-side route/job chạy
khi connection được cấu hình, còn order write vẫn cần full capability gate. Luồng
order `processing` đã tạo outbox idempotent, snapshot bằng Woo CRUD, resolve customer theo
điện thoại/email, kiểm tra mapping/giá/quà và retry timeout/rate-limit/5xx. Gateway mặc định
vẫn fail-closed khi chưa cấu hình; khi có connection, `SapoAdminGateway` phục vụ read-side.
Inventory
reconciler đã tính stock bằng `available` lớn nhất của các location online và hỗ trợ shadow mode;
không ghi Woo trong shadow mode. Trang Sapo Sync có Settings API cho shadow/write, HMAC/token secret,
location policy, base URL HTTPS và field Basic/Bearer không render credential; quyền lưu là
`manage_woocommerce`, secret không được render lại. Event worker
đã có stale-event guard và map trạng thái/tracking Sapo → Woo; event không tìm thấy aggregate
được giữ lỗi để reconciliation xử lý sau. Hủy Woo đã có outbox riêng, chặn tự hủy khi Sapo
đã đóng gói/xuất kho và không cộng tồn trước khi Sapo xác nhận.
Mapping synchronizer đã quét sản phẩm thường/variation bằng SKU theo trang và giữ Sapo ID cũ
khi SKU đổi hoặc bị trùng để chuyển `NEEDS_REVIEW`, không tự tạo liên kết mới.
Trang mapping đã có tìm/lọc theo SKU/trạng thái và manual link có nonce; liên kết thủ công chỉ
được ACTIVE khi có đủ Sapo product/variant ID và không trùng variant đang liên kết.
Checkout allocation đã kiểm tra cart theo một location đủ toàn bộ SKU, ghi location vào order và
worker kiểm tra lại `available` tại location đó trước khi POST order để tránh race tồn.
Location policy và inventory reconciliation fail-closed: không allowlist location hoặc thiếu
availability response thì không expose/ghi tồn bằng 0.
Capability page có nút chạy probe; chỉ kết quả từ gateway mới ghi vào gate, không có thao tác
đánh dấu thủ công. `GatewayFactory` trả `SapoAdminGateway` cho connection đã cấu hình và
`UnavailableGateway` khi thiếu credential; order write vẫn cần adapter/fixture Omni/POS được duyệt.
Worker đã ghi log vận hành qua Woo logger với correlation ID và redaction token/secret/PII;
không dùng log để thay thế event inbox hoặc outbox ledger. Admin dashboard hiển thị backlog,
mapping status và recent operation metadata để xử lý `NEEDS_REVIEW`.
Inventory job có lịch 1 phút và mapping reconciliation có lịch hằng ngày qua Action Scheduler,
fallback WP-Cron, đều được dọn khi deactivate; chỉ đăng ký khi connection đã được cấu hình.
Concrete read adapter, response schema fixture và capability verification đã có cho các endpoint
đọc `/admin/products`, `/admin/locations`, `/admin/inventory_levels`, `/admin/customers` và
`/admin/orders`; gateway mặc định chỉ bật các primitive customer/create, order lookup/state/cancel
đã có contract public. Create-and-approve Omni/POS vẫn phải nối qua filter
`woo_sapo_gateway` hoặc mở rộng `SapoAdminGateway` sau contract test; không bypass
`CapabilityGate`.

Hardening đã hoàn tất trong `0.4.0`: inventory availability được chia batch theo variant/location;
scheduled reconciliation có lock tự thu hồi; event inbox có claim lease, retry bounded và xử lý lỗi
lưu/queue với HTTP status phù hợp; fingerprint không phụ thuộc whitespace JSON và thời gian event được
chuẩn hóa về UTC `datetime`. Manual mapping kiểm tra remote SKU/type trước khi ACTIVE. Adapter đã có
customer create, order lookup, state và cancel theo Admin API công khai. Adapter có smoke test order
tạo + hủy test dùng `inventory_behaviour=bypass`; chỉ sau khi smoke test thành công trên connection
hiện tại mới lưu capability contract và mở order write. Create order demo đã xác nhận `status=open`,
`confirmed_on`, `processed_on`; cancel dùng `order_cancel.cancel_reason`.

Bộ fixture contract normalized mẫu nằm trong `tests/fixtures/contract/` và
`tests/FixtureGateway.php`. Đây chỉ là harness kiểm thử offline, không được dùng để đánh dấu
capability production; sau khi có response Omni/POS thật cần thay fixture bằng dữ liệu đã khử
token/PII và chạy lại contract test trước khi nối `GatewayFactory`.

## 3. Kiến trúc và dữ liệu

Kiến trúc đích:

```text
sapo-sync-for-woocommerce/
├── sapo-sync-for-woocommerce.php
├── src/
│   ├── Contracts/         # SapoGateway và typed commands/results
│   ├── Domain/            # Mapping, allocation, order state, price source
│   ├── Application/       # Use cases và orchestration
│   ├── Infrastructure/    # Sapo client, WordPress repositories, Action Scheduler
│   ├── Webhook/           # Endpoint, signature, dedupe và stale-event guard
│   └── Admin/             # Settings, mapping, orders, logs, diagnostics
├── templates/
├── assets/
└── docs/
```

Contract, migration và domain service có thể được chuẩn bị trước; không đăng ký hook
đồng bộ thật hoặc gọi API ghi dữ liệu trước khi capability gate hoàn tất.

### 3.1. Bảng mapping sản phẩm

`{$wpdb->prefix}wss_sapo_product_mappings`

```text
id
woo_product_id
woo_variation_id nullable
sku_raw
sku_match_key
sapo_product_id nullable
sapo_variant_id nullable
product_type = SIMPLE | VARIATION
price_source = WOO | SAPO
sapo_price_list_id nullable
mapping_status
last_verified_at
last_inventory_sync_at
created_at
updated_at
```

Ràng buộc:

- Unique Woo product/variation target.
- Unique Sapo variant target.
- SKU rỗng, trùng hoặc không tồn tại không được chuyển sang `ACTIVE`.
- Chỉ auto-map khi SKU khớp chính xác sau khi trim; khác hoa/thường chỉ là gợi ý.
- SKU đổi nhưng Sapo ID còn tồn tại chuyển `NEEDS_REVIEW`, không tự tạo mapping mới.
- `product_type` dùng enum mở rộng để sau này thêm handler mà không đổi contract hàng đợi.

### 3.2. Sync outbox/ledger

`{$wpdb->prefix}wss_sapo_sync_operations`

```text
id
operation_type
aggregate_type
aggregate_id
external_reference
request_hash
sapo_object_id nullable
status
attempt_count
next_attempt_at nullable
last_error_code nullable
last_error_message nullable
created_at
updated_at
completed_at nullable
```

`external_reference + operation_type` phải unique. Đây là lớp idempotency chính khi Sapo đã xử lý request nhưng WordPress bị timeout trước khi nhận response.

### 3.3. Webhook/event inbox

`{$wpdb->prefix}wss_sapo_events`

Lưu event ID hoặc fingerprint, object ID, remote modified time, trạng thái xử lý và thời gian nhận. Event trùng hoặc cũ hơn phiên bản đã áp dụng phải bị bỏ qua.

### 3.4. WooCommerce storage

- Dùng Woo CRUD API và tương thích HPOS; không đọc/ghi trực tiếp `wp_posts` hoặc `wp_postmeta` cho đơn hàng.
- Order meta lưu Sapo order ID/code, assigned location, sync status và thời điểm đồng bộ cuối.
- Dữ liệu truy vấn hàng loạt, idempotency và webhook nằm trong custom table thay vì phụ thuộc hoàn toàn vào order meta.

## 4. Các luồng triển khai

### Giai đoạn 0 — Connector và capability gate

- Trang cấu hình kết nối, scopes và chế độ test.
- Read adapter Sapo Admin, validation schema và fixture contract tests.
- Công cụ kiểm tra từng capability, không chỉ “API trả 200”.
- Chỉ đăng ký read-side mapping/inventory khi connection hợp lệ; order write vẫn khóa.

### Giai đoạn 1 — Mapping, giá và tồn kho

- Quét sản phẩm thường và từng variation của Woo/Sapo.
- Báo cáo SKU khớp, thiếu, trùng, sai loại và cần duyệt.
- Nguồn giá mặc định toàn hệ thống, cho phép override theo SKU.
- Đồng bộ `available` Sapo → Woo theo lô nhỏ.
- Shadow mode 48–72 giờ chỉ so sánh, chưa ghi Woo.
- Webhook product/store kích hoạt reconciliation ngay; polling `inventory_levels` mỗi phút là safety
  net. Full reconciliation mapping vẫn chạy hằng ngày.

### Giai đoạn 2 — Phân kho và checkout

- Cấu hình chi nhánh online, vùng giao và thứ tự ưu tiên.
- Woo `_stock` dùng mức `available` lớn nhất của các kho online vì không tách đơn.
- Sau khi có địa chỉ giao, validate lại toàn bộ giỏ hàng ở server.
- Tìm chi nhánh đầu tiên đúng vùng và đủ tất cả sản phẩm/quà.
- Không có chi nhánh phù hợp thì chặn trước bước thanh toán.

### Giai đoạn 3 — Đơn và khách hàng

- `pending`, `on-hold`: chưa gửi, trừ khi trạng thái được admin cấu hình là đã chấp nhận.
- `processing`: ghi outbox, khóa theo Woo order ID, tìm đơn theo external reference rồi mới tạo.
- External reference cố định: `WOOSAPO-{site_uuid}-{woo_order_id}`.
- Gửi đúng assigned location, variant ID, SKU snapshot, số lượng, giá chốt, giảm giá, thuế, vận chuyển và trạng thái thanh toán.
- Tìm khách theo SĐT chuẩn hóa rồi email; chỉ tạo mới nếu không tìm thấy.
- Không ghi đè trường Sapo hiện hữu bằng dữ liệu Woo rỗng.
- Quà tặng phải nằm trong cart/order trước khi checkout stock validation chạy.

### Giai đoạn 4 — Trạng thái, vận đơn, hủy và hoàn

- Xử lý riêng năm trục trạng thái: order, financial, processing, delivery, return.
- Sapo giao thành công và thanh toán đủ mới chuyển Woo `completed`.
- Mã vận đơn lưu vào order meta và order note.
- Woo hủy trước khi Sapo xử lý giao hàng thì gửi yêu cầu hủy.
- Đã đóng gói/xuất kho/đang giao thì chuyển `NEEDS_REVIEW`.
- Woo refund không tự hoàn kho; Sapo xác nhận nhận hàng hoàn mới cộng tồn.

### Giai đoạn 5 — Vận hành

- Dashboard, mapping, đơn lỗi, backlog, tồn lệch, webhook và nhật ký.
- Action Scheduler với exponential backoff + jitter.
- Tự retry timeout, rate limit và `5xx`; lỗi auth dừng connector; validation/conflict chuyển duyệt tay.
- Log có correlation ID, che token và PII; cấu hình thời hạn lưu log.
- Cảnh báo khi order sync trễ, backlog tăng, token lỗi hoặc reconciliation lệch.

## 5. Kiểm thử và nghiệm thu

Test bắt buộc:

- Tên/content khác nhưng cùng SKU; SKU rỗng, trùng, khác hoa/thường hoặc bị đổi.
- Sản phẩm thường, variation và SKU thuộc loại ngoài MVP.
- Nguồn giá Woo/Sapo trong cùng đơn và thay đổi giá giữa lúc xem hàng/checkout.
- Quà đủ/hết tồn, nhiều quà và quà trùng SKU với hàng mua.
- Hai chi nhánh: đúng vùng, kho ưu tiên thiếu, tổng hai kho đủ nhưng không kho nào đủ toàn giỏ.
- COD, thanh toán online thành công/thất bại, chuyển khoản on-hold.
- Timeout sau khi Sapo đã tạo đơn, hai worker đồng thời và retry nhiều lần.
- Webhook trùng, sai thứ tự, đến trễ và polling bù webhook bị mất.
- Hủy trước/sau đóng gói, refund không nhập kho, nhận hàng hoàn mới nhập kho.
- Action Scheduler backlog, API rate limit và WooCommerce HPOS.

Tiêu chí nghiệm thu:

- Một Woo order có tối đa một Sapo order.
- Không tạo đơn thiếu dòng hàng hoặc quà.
- Đơn chỉ được chấp nhận khi một chi nhánh đủ toàn bộ SKU.
- Woo phản ánh đúng tồn khả dụng theo chính sách phân kho.
- Tổng tiền, giảm giá, thuế và phí vận chuyển khớp.
- Lỗi API không làm chậm checkout và luôn có trạng thái xử lý rõ ràng.
- Hủy/refund không cộng tồn trước khi hàng thực tế quay về kho.
- Có thể thêm product type handler mới mà không phá mapping, outbox hoặc lịch sử đồng bộ cũ.

## 6. Tài liệu Sapo tham chiếu

- [Chỉ số kho và tồn kho sản phẩm trên Sapo Omni](https://help.sapo.vn/chi-so-kho-va-ton-kho-san-pham-tren-sapo-omni)
- [Cài đặt chi nhánh nhận đơn online](https://help.sapo.vn/quan-ly-chi-nhanh-tren-phan-mem-sapo-omniai)
- [Tạo và duyệt đơn hàng online](https://help.sapo.vn/tao-va-duyet-don-hang-online)
- [Các trạng thái của đơn hàng](https://help.sapo.vn/cac-trang-thai-cua-don-hang)
- [Hủy đơn hàng](https://help.sapo.vn/Huy-don-hang-tu-man-chi-tiet-don)
- [Private Apps Sapo Web — không dùng để suy đoán API Omni](https://support.sapo.vn/ung-dung-rieng-private-apps)
- [Webhook Sapo Web — cần xác minh lại với Omni](https://support.sapo.vn/sapo-webhook)
