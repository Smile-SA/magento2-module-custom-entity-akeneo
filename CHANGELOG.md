# Changelog

All notable changes to this project will be documented in this file.

## 1.0.6 - Unreleased

- Supported platforms: Magento 2.4.4 and 2.4.5 only, PHP 8.1
- `composer.json` now states it: `php ^8.1` and `magento/framework >=103.0.4 <103.0.6` (previously `php ^8.0` and any `magento/framework` version)
- Magento 2.4.6 and later and Mage-OS are supported by the 1.1.x line
- Fix `setup:di:compile` on Magento 2.4.4 and 2.4.5: native `mixed` and union types are removed from method signatures and kept in docblocks
- Require the Smile modules of the same line: `smile/module-custom-entity ~1.3.17`, `smile/module-custom-entity-product-link ~1.4.5`

## 1.0.5 - 2025-07-30

- Fix import of smile_custom_entity type attributes when frontend input type does not match between akeneo and magento [#25](https://github.com/Smile-SA/magento2-module-custom-entity-akeneo/pull/25)

## 1.0.4 - 2024-03-05

- Removed dependency from parent constructor in ReferenceEntity helper [#24](https://github.com/Smile-SA/magento2-module-custom-entity-akeneo/pull/24)

## 1.0.3 - 2024-02-13

- Adaptation of the import of custom entity attributes and custom entity records to manage all image attributes and value by channel and/or locale in [#19](https://github.com/Smile-SA/magento2-module-custom-entity-akeneo/pull/19), [#20](https://github.com/Smile-SA/magento2-module-custom-entity-akeneo/pull/20), [#21](https://github.com/Smile-SA/magento2-module-custom-entity-akeneo/pull/21)

## 1.0.2 - 2023-08-10

- Updating the version of the required modules by @dmytro-khrusch in [#16](https://github.com/Smile-SA/magento2-module-custom-entity-akeneo/pull/16)
- Fixed reference_entity import for custom entity by @dmytro-khrusch in [#17](https://github.com/Smile-SA/magento2-module-custom-entity-akeneo/pull/17)

## 1.0.1 - 2023-07-26

- Add entity in custom entity import table in [#15](https://github.com/Smile-SA/magento2-module-custom-entity-akeneo/pull/15)
- Updated import for attributes of type single_link, multiple_links for reference entity based on new input type for custom entity attributes in [#14](https://github.com/Smile-SA/magento2-module-custom-entity-akeneo/pull/14)

## 1.0.0 - 2022-10-10

- Initial Release
