# Sapo Demo Contract — dữ liệu đã khử

Ngày capture: `2026-08-18`

Tài liệu này ghi lại response đọc được từ session Sapo Demo đã đăng nhập sau khi tạo hai
sản phẩm giả theo yêu cầu của chủ tài khoản. Read adapter trong plugin đã có fixture tương ứng
với các shape này; đây vẫn là quan sát Sapo Web Admin, không phải cam kết contract Omni/POS public.

## Dữ liệu quan sát

### Sản phẩm thường

| Trường | Giá trị |
| --- | --- |
| Sapo product ID | `88880667` |
| Variant ID | `222377256` |
| Inventory item ID | `222377254` |
| SKU | `PX-DEMO-SIMPLE-001` |
| Option | `Default Title` |
| Giá | `99000` |
| Đơn vị | `cái` |
| Inventory management | `bizweb` |
| Inventory policy | `deny` |

Inventory level theo location `941850`: `on_hand=5`, `available=5`, `committed=0`,
`incoming=0`, `packed=0`, `reserved=0`, `unavailable=0`.

### Sản phẩm có biến thể

| Variant ID | Inventory item ID | SKU | Option | Giá | Available |
| --- | --- | --- | --- | ---: | ---: |
| `222377267` | `222377265` | `PX-DEMO-VARIANT-RED` | `Đỏ` | `149000` | `3` |
| `222377268` | `222377266` | `PX-DEMO-VARIANT-BLUE` | `Xanh` | `129000` | `4` |

Product parent có một option `Màu` với values `Đỏ`, `Xanh`; tổng “Có thể bán” trên UI là `7`.
Inventory level của từng variant có cùng các trường tồn như sản phẩm thường và cùng location
`941850`.

## Request đã quan sát

Các URL dưới đây được đọc trong DevTools từ browser session, không lưu header/cookie/token:

```text
GET /admin/products/{product_id}.json
GET /admin/locations.json?inventory_management=true&page=0&limit=0
GET /admin/inventory_levels.json?inventory_item_ids={ids}&location_ids={ids}
```

Response shape quan sát được:

```json
{
  "product": {
    "id": 88880677,
    "name": "Sapo Sync for WooCommerce Demo Variant",
    "status": "active",
    "type": "normal",
    "options": [{"name": "Màu", "values": ["Đỏ", "Xanh"]}],
    "variants": [{
      "id": 222377267,
      "inventory_item_id": 222377265,
      "sku": "PX-DEMO-VARIANT-RED",
      "price": 149000,
      "option1": "Đỏ",
      "inventory_quantity": 3
    }]
  }
}
```

Inventory response dùng `available` theo `variant_id + location_id`. Đây là chỉ số khớp với
“Có thể bán” trên UI demo và phù hợp với nghiệp vụ Sapo Omni đã nêu trong kế hoạch. Tuy nhiên,
đường dẫn và authentication của các request trên thuộc admin UI; chúng không đủ để kết luận
plugin có thể gọi Sapo Omni/POS từ server.

## Smoke test adapter ngày `2026-08-18`

Sau khi cấp quyền `Chi nhánh — Chỉ đọc` cho Private App, các endpoint adapter gọi đều trả `200`:

- `products`: 2 sản phẩm, 3 biến thể và SKU.
- `locations`: 1 chi nhánh quản lý kho.
- `inventory_levels`: 3 dòng tồn có `available`.
- `customers`, `orders`, `webhooks`: response hợp lệ, hiện không có dữ liệu demo.

Đã chạy smoke test ghi trên order test với `send_webhooks=false`, `send_receipt=false` và
`inventory_behaviour=bypass`:

```text
POST /admin/orders.json                         → 201 Created
POST /admin/orders/{id}/cancel.json             → 200 OK
payload hủy: {"order_cancel":{"cancel_reason":"other"}}
GET  /admin/orders/{id}.json                    → 200 OK
```

Order tạo qua API có `status=open`, `confirmed_on` và `processed_on`; không cần endpoint duyệt
riêng trong contract demo. Lookup external reference có thể dùng `note` hoặc
`note_attributes[name=woo_sapo_external_reference]`. Thử thêm `inventory_behaviour=
decrement_obeying_policy` cho thấy `available` giảm theo location; hủy order không tự hoàn tồn,
đúng với quyết định nghiệp vụ chỉ hoàn kho khi hàng hoàn được xác nhận. Tồn sản phẩm demo đã được
hoàn nguyên sau test.

Vì smoke test đã xác minh contract trên connection demo, plugin có nút **Chạy smoke test order
(tạo + hủy test)** để operator xác minh connection thực tế trước khi capability ghi đơn được mở.

## Quyết định kỹ thuật

- Mapping lần đầu dùng SKU của từng variant; không dùng tên, alias, content hoặc ảnh.
- Product parent chỉ là cấu trúc option; tồn và giá phải đọc ở variant.
- Tồn dùng `available` theo location. `inventory_quantity` trong product detail chỉ là field
  đối chiếu, không thay thế availability theo chi nhánh.
- ID demo chỉ được giữ trong tài liệu/fixture, không hard-code vào runtime.
- Adapter production chỉ mở order write sau smoke test của chính connection. Endpoint/payload
  vẫn phải được kiểm thử lại khi đổi shop, quyền hoặc biến thể Sapo Omni/POS; token/PII không được
  lưu trong fixture hay log.
