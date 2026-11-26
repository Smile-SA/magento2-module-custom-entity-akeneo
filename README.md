# Akeneo's Reference Entity to Magento Custom Entity

## What is this module ?

Import Akeneo's Reference Entities into Smile Custom Entity module.

## Compatibility

| Module version | Magento | Mage-OS | PHP |
| --- | --- | --- | --- |
| 1.1.x | 2.4.6 and later (tested up to 2.4.9) | 1.0.0 and later (tested up to 4.0) | 8.1 to 8.5, as supported by the platform |
| 1.0.x, from 1.0.6 | 2.4.4 and 2.4.5 | not available | 8.1 |

Releases up to 1.0.5 are not covered by this table: they declared `php ^8.0` and any `magento/framework` version, and their method signatures use native `mixed` or union types, which the code generator of Magento 2.4.4 and 2.4.5 rejects (`setup:di:compile` fails).

This module depends on the private Akeneo connector, so it is not part of the automated compile check.

## Who is developing this module ?

![Smile](doc/static/smile.png)

This module is built by Smile.

Smile is the **European leader of Open Source** and also a four-times **Europe Partner of the the Year** (2010-2014), two-times **Spirit of Excellence** (2015-2016), **Global Elite Partner** (2017-now), and **Regional Commercial Partner** (2018) awarded by Magento.

Our multidisciplinary team brings together experts in technology, innovation, and new applications.

Together we explore, invent, and test technologies of the future, to better serve our clients.

## Main Features

It imports the Akeneo's References Entities, and the associated attributes, options, records and medias into Smile Custom Entity module.
A configuration lets you the possibility to choose which entities you want to import.

## Documentation

- [Installation](doc/install.md)
- [Reference Entity](https://help.akeneo.com/pim/serenity/articles/what-about-reference-entities.html)
- [Custom Entity](https://github.com/Smile-SA/magento2-module-custom-entity)
- [Configuration](doc/configuration.md)
- [Import](doc/import.md)

## Contributing

You can contribute to this module by submitting issues or pull requests.

For more details, please take a look at the [contribution guidelines](CONTRIBUTING.md).

## Standards & Code Quality

[Smile Lab Quality Suite (Magento)](https://github.com/Smile-SA/magento2-smilelab-quality-suite/) provides coding standards / rulesets that can be used on Magento projects.
