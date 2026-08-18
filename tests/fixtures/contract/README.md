# Contract fixtures

Các file JSON trong thư mục này là dữ liệu normalized ở boundary `SapoGateway`,
không phải response live của Sapo. Khi lấy được contract Omni/POS thật:

1. Chụp response cho từng capability trên tài khoản test.
2. Xóa token, cookie, email, số điện thoại, địa chỉ và ID có thể truy ngược PII.
3. Map response về shape mà `SapoGateway` công bố.
4. Thay fixture mẫu và chạy `php tests/run.php` trước khi nối adapter vào `GatewayFactory`.

Không dùng fixture mẫu để đánh dấu capability production. Capability gate chỉ được ghi
từ probe chạy qua adapter thật.
