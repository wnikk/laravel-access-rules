# Changelog

All notable changes to `laravel-access-rules` will be documented in this file

## 2.0.17 - 2025-06-11

- Optimized cache initialization
- Implemented the creation of a basic admin role,
  assigned a test permission to it,
  and ensured inheritance for the first user within this role.
- Add unit tests for the base functionality

## 2.0.16 - 2025-03-12

- Add optionality to check cache and throw if not working

## 2.0.15 - 2025-03-11

- Update Laravel 12.x Compatibility

## 2.0.14 - 2024-10-15

- Add permission inheritance management via Artisan command

## 2.0.11 - 2024-04-29

- Update Laravel 11.x Compatibility

## 2.0.11 - 2023-04-10

- Add method refreshPermission, forgetSelectedCachePermission

## 2.0.10 - 2023-04-07

- Added clear cache after changing permission

## 2.0.9 - 2023-03-28

- Fix support policies

## 2.0.8 - 2023-03-27

- Add method getLastRule on Exception
- Fix bug of add inherit to new user
- Fix of checkUserIsAuthor

## 2.0.1 - 2023-03-15

- Add support change the list of existing types on a running project
- Add title to rules

## 2.0.0 - 2023-03-11

- Add magic rule "self"
- Fix bug on hasPermission method

## 1.0.4 - 2023-03-10

- Add support OwnerContract on setOwner method
- Fix bug on inheritance

## 1.0.3 - 2023-03-09

- Add getListTypes method to trait AccessRulesTypeOwner
- Update name classes of Aggregator
- Update PermissionOption validator
- Add delRule method to trait AccessRulesPermission

## 1.0.1 - 2023-02-11

- Added the main methods to classes


## 1.0.0 - 2023-02-08

- Everything, initial release
