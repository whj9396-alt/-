# Direct USDT-TRC20 Payments for WooCommerce

面向跨境电商独立站的 WooCommerce USDT-TRC20 直连收款插件。

本插件不依赖第三方支付服务商，直接读取 TRON 区块链并验证官方 Tether USDT-TRC20 转账。只有在官方合约、收款地址、精确金额、交易回执和已固化区块全部匹配后，才会自动完成 WooCommerce 订单。

## 功能

- 直接收款到商户公开 TRON 地址
- 精确金额匹配和订单尾数防撞单
- 仅扫描已固化区块，支持可选第二节点交叉验证
- 支持经典结账、WooCommerce Checkout Blocks 和 HPOS
- 支持后台监测、审计日志、WP-Cron 和 WP-CLI
- 支持多语言和按当前域名显示商城名称
- 不保存钱包私钥、助记词或钱包密码

## 安装

1. 在 WordPress 后台进入“插件 → 安装插件 → 上传插件”。
2. 上传 `partsyhub-direct-usdt-v1.0.0.zip` 并启用。
3. 进入“WooCommerce → 设置 → 付款 → Direct USDT-TRC20”。
4. 填写本站专用的公开 TRON 收款地址、汇率和节点配置。
5. 确认节点检测通过后，再启用结账渠道。

详细操作请查看 [中文安装说明](README-zh_CN.md)。

## 重要限制

- 当前版本适合单站或少量站点部署；大规模多站点应使用中央支付中心架构。
- 仅支持 TRON 网络的 USDT-TRC20，不支持 ERC20、BEP20 或 TRX 直接转账。
- 插件没有支付商最低金额，但顾客使用的交易所可能有最低提现额，TRON 网络也可能需要 TRX 资源费用。
- 退款需要商户使用自己的钱包人工操作；插件不会保存私钥，也不会自动签名转账。

## 安全建议

- 只填写公开收款地址，不要填写私钥、助记词或钱包密码。
- 正式运营必须启用 HTTPS，并为 WordPress、数据库和服务器设置备份。
- 不要把 API Key、密码、数据库备份、日志或 `.env` 文件提交到 GitHub。
- 上线前先使用小额订单测试到账、过期、少付、多付和错误网络场景。

## 许可证

本项目使用 GPL-2.0-or-later 许可证，详见 [LICENSE](LICENSE)。
