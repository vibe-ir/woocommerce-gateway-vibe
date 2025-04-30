# Changelog

All notable changes to the WooCommerce Vibe Payment Gateway plugin will be documented in this file.

## 1.0.1 - 2024-06-10

### Added
- Automatic currency conversion from IRT (Toman) to IRR (Rial) when sending data to Vibe API
- Plugin usage tracking system to monitor installations and activations
- Detailed logging of currency conversion for debugging purposes

### Changed
- Updated API endpoints to use the production Vibe credit API
- Improved error handling and debug logging

### Removed
- Removed functionality that forced Vibe to be the first payment gateway

## 1.0.0 - 2024-05-15

### Added
- Initial release of the WooCommerce Vibe Payment Gateway
- Support for processing payments through the Vibe payment system
- Admin settings page for configuring the gateway
- WooCommerce blocks integration for checkout block support
- Order completion and verification flows
- Support for processing refunds
- Comprehensive logging system for debugging 