# Changelog

## 1.1.1
- Fixed My account fields bug - when OPTION_COMPANY_FIELDS_ENABLED is now checked when displaying fields under "My Account".

## 1.1.0

- Added IČO validation (8 digits + checksum mod 11) on checkout and admin invoice creation
- Added DIČ validation (10 digits) on checkout and admin invoice creation
- Added IČ DPH validation (SK+10 digits / CZ+8-10 digits) on checkout and admin invoice creation
- Added client-side validation with real-time visual feedback (green/red using WooCommerce's `woocommerce-validated` / `woocommerce-invalid` classes)
- Replaced duplicated validation in custom-invoice.php with calls to `M4W_SK_Company_Fields::validate_*()`
