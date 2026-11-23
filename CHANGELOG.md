# Changelog

## 1.2.0
- Added Peppos (Dativery Pepposh) e-invoicing integration for B2B orders.
- Added a single WooCommerce > B2B nastavenia page with "Všeobecné", "Peppol" and "Faktúry" tabs; all settings are in Slovak.

## 1.1.2
- Fixed checkout Blocks (Block API) showing literal `{ico}`, `{dic}`, `{ic_dph}` placeholders: the `countryData` sent to the Blocks checkout now uses the default address format with the custom tokens removed, while server-side rendering (orders, emails, admin, account) keeps the original token-based format.

## 1.1.1
- Changed default translatable strings from Slovak to English
- Added .mo and .po translation files for sk_SK and cs_CZ
- Made JavaScript validation strings translatable via wp_localize_script
- Changed the way how the plugin is loaded (Woocommerce dependency loading mechanism changed)
- Fixed My account fields bug - when OPTION_COMPANY_FIELDS_ENABLED is now checked when displaying fields under "My Account".

## 1.1.0

- Added IČO validation (8 digits + checksum mod 11) on checkout and admin invoice creation
- Added DIČ validation (10 digits) on checkout and admin invoice creation
- Added IČ DPH validation (SK+10 digits / CZ+8-10 digits) on checkout and admin invoice creation
- Added client-side validation with real-time visual feedback (green/red using WooCommerce's `woocommerce-validated` / `woocommerce-invalid` classes)
- Replaced duplicated validation in custom-invoice.php with calls to `M4W_SK_Company_Fields::validate_*()`
