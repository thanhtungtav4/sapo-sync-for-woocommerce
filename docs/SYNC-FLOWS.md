# Luồng đồng bộ WooCommerce – Sapo

Tài liệu này mô tả flow đang triển khai. Plugin `0.4.0` đã có read adapter Sapo và realtime
reconciliation; thao tác ghi đơn vẫn bị khóa cho đến khi capability gate/contract test đạt.

## 1. Mapping sản phẩm

```mermaid
flowchart TD
    A["Quét sản phẩm và variation Woo"] --> B["Đọc sản phẩm và variant Sapo theo trang"]
    B --> C["Chuẩn hóa SKU để đối chiếu"]
    C --> D{"SKU khớp chính xác và duy nhất?"}
    D -- "Có" --> E["Lưu Woo ID ↔ Sapo product/variant ID"]
    D -- "Không" --> F{"Chỉ khác hoa/thường?"}
    F -- "Có" --> G["Gợi ý mapping, chờ xác nhận"]
    F -- "Không" --> H["Đánh dấu thiếu/trùng/không hỗ trợ"]
    E --> I["Mapping ACTIVE"]
    G --> J["NEEDS_REVIEW"]
    H --> J
```

Nguyên tắc:

- SKU chỉ tìm liên kết lần đầu.
- Sau khi `ACTIVE`, mọi order/inventory flow dùng Sapo variant ID đã lưu.
- Tên và content chỉ hiển thị cho admin, không ảnh hưởng quyết định mapping.
- Nếu SKU thay đổi trong khi Sapo ID còn nguyên, tạm dừng mapping để xác nhận.

## 2. Đồng bộ tồn kho

```mermaid
flowchart TD
    A["Webhook đã xác minh hoặc polling 1 phút"] --> B["Nhận variant ID + location ID + available"]
    B --> C["Validate payload và kiểm tra event cũ/trùng"]
    C --> D["Tìm mapping ACTIVE"]
    D --> E{"Tìm thấy mapping?"}
    E -- "Không" --> F["Ghi NEEDS_REVIEW, không sửa Woo"]
    E -- "Có" --> G["Cập nhật tồn theo từng chi nhánh trong repository"]
    G --> H["Tính max available của các kho online"]
    H --> I["Cập nhật Woo _stock và stock status"]
    I --> J["Ghi thời điểm và kết quả sync"]
```

Không gửi thay đổi `_stock` này ngược về Sapo. Tài liệu Sapo hiện không công bố topic tồn kho
riêng, nên topic product/store kích hoạt reconciliation ngay; polling mỗi phút sửa sai lệch do
webhook bị mất hoặc event đến sai thứ tự.

## 3. Phân kho tại checkout

```mermaid
flowchart TD
    A["Khách nhập địa chỉ giao hàng"] --> B["Lấy tất cả dòng mua + quà"]
    B --> C["Lọc chi nhánh online phục vụ tỉnh/thành"]
    C --> D["Sắp xếp theo ưu tiên đã cấu hình"]
    D --> E{"Chi nhánh đầu tiên đủ mọi SKU?"}
    E -- "Có" --> F["Gắn assigned_location vào checkout/order"]
    E -- "Không" --> G["Chặn thanh toán và yêu cầu đổi giỏ/địa chỉ"]
    F --> H["Woo tạo đơn"]
```

Không cộng tồn nhiều chi nhánh để đáp ứng một đơn. Trước khi gửi Sapo, worker kiểm tra lại assigned location để xử lý tình huống tồn thay đổi đồng thời.

## 4. WooCommerce → Sapo

```mermaid
sequenceDiagram
    participant Customer as Khách hàng
    participant Woo as WooCommerce
    participant AS as Action Scheduler
    participant Ledger as Sync ledger
    participant Sapo as Sapo Omni/POS

    Customer->>Woo: Hoàn tất checkout
    Woo->>Woo: Tạo order và assigned location
    Woo-->>Customer: Hiển thị trang cảm ơn
    Woo->>AS: Enqueue khi order được chấp nhận
    AS->>Ledger: Insert external reference duy nhất
    Ledger-->>AS: Created hoặc existing operation
    AS->>Sapo: Tìm order theo external reference
    alt Order đã tồn tại
        Sapo-->>AS: Trả Sapo order
        AS->>Ledger: Liên kết và hoàn thành
    else Chưa tồn tại
        AS->>Sapo: Tạo + duyệt order
        Sapo-->>AS: Sapo order ID/code
        AS->>Ledger: Lưu ID và hoàn thành
        AS->>Woo: Lưu sync status/order note
    end
```

Nếu request tạo đơn timeout, operation không được POST lại ngay. Worker kế tiếp phải tìm theo external reference trước, rồi mới quyết định retry.

## 5. Điều kiện gửi đơn

| Woo status/tình huống | Hành động |
| --- | --- |
| `pending` | Chưa gửi |
| `on-hold` | Chưa gửi theo mặc định |
| COD vào `processing` | Tạo và duyệt Sapo |
| Online payment vào `processing` | Tạo và duyệt Sapo |
| `failed` trước khi gửi | Đánh dấu bỏ qua |
| `cancelled` trước khi gửi | Đánh dấu bỏ qua |
| Tồn assigned location thay đổi và không còn đủ | Không tạo đơn thiếu; chuyển `NEEDS_REVIEW` |

## 6. Sapo → WooCommerce

```mermaid
flowchart TD
    A["Webhook/poll trạng thái Sapo"] --> B["Validate, dedupe, stale-event guard"]
    B --> C["Đọc riêng order/financial/processing/delivery/return"]
    C --> D{"Đã giao và đã thanh toán đủ?"}
    D -- "Có" --> E["Woo completed"]
    D -- "Không" --> F{"Sapo đã hủy hợp lệ?"}
    F -- "Có" --> G["Woo cancelled + note"]
    F -- "Không" --> H["Giữ Woo status, cập nhật meta/note"]
    C --> I["Cập nhật mã vận đơn nếu có"]
```

```text
Webhook product/store (và inventory/stock nếu tài khoản phát) → event inbox → Action Scheduler
reconciliation → đọc `inventory_levels.available` theo location → cập nhật Woo. Polling mỗi phút
là safety net bắt buộc cho tồn kho.
```

Không cố ép mọi trạng thái giao hàng của Sapo thành một Woo status. Các trạng thái chi tiết được lưu ở meta và order note; chỉ chuyển Woo khi có quy tắc chắc chắn.

## 7. Hủy và hoàn hàng

```mermaid
flowchart TD
    A["Woo yêu cầu hủy/refund"] --> B{"Sapo order đã tạo?"}
    B -- "Chưa" --> C["Hủy tác vụ CREATE đang chờ, không gọi Sapo"]
    B -- "Rồi" --> D{"Đã đóng gói/xuất kho/đang giao?"}
    D -- "Chưa" --> E["Gửi yêu cầu hủy Sapo"]
    D -- "Rồi" --> F["NEEDS_REVIEW, nhân viên xử lý"]
    A --> G["Refund tài chính không tự cộng tồn"]
    G --> H{"Sapo xác nhận đã nhận hàng hoàn?"}
    H -- "Có" --> I["Sapo cập nhật tồn; plugin nhận available mới"]
    H -- "Không" --> J["Giữ nguyên tồn"]
```

## 8. Retry và lỗi

| Nhóm lỗi | Xử lý |
| --- | --- |
| Timeout/network | Tìm lại bằng external reference, sau đó retry có backoff |
| Rate limit | Tôn trọng retry hint nếu có, thêm jitter |
| Sapo `5xx` | Retry có giới hạn |
| Auth/scope | Dừng connector và cảnh báo admin |
| Mapping/SKU | Chuyển `NEEDS_REVIEW` |
| Validation dữ liệu | Không retry tự động cho đến khi dữ liệu được sửa |
| Conflict/tồn không đủ | Không tạo đơn một phần; duyệt tay |
| Webhook trùng/cũ | Bỏ qua nhưng vẫn ghi nhận audit |

Retry không chỉ ghi timestamp: worker phải enqueue lại operation theo `next_attempt_at`; nếu
không có Action Scheduler/WP-Cron thì chuyển `NEEDS_REVIEW` để tránh backlog giả.

## 9. Flow mở rộng loại sản phẩm

Domain sử dụng `ProductTypeHandler`:

```text
SIMPLE       → triển khai trong MVP
VARIATION    → triển khai trong MVP
COMBO        → handler tương lai
CONVERTED    → handler tương lai
LOT          → handler tương lai
SERIAL       → handler tương lai
```

Handler tương lai chịu trách nhiệm xác định inventory subject, cách tạo order line và yêu cầu fulfillment riêng. Outbox, webhook inbox, retry và audit log tiếp tục dùng chung.
