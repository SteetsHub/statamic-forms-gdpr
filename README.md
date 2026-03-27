# Statamic Addon: Forms Gdpr

Forms Gdpr is a Statamic addon that deletes old Statamic form submissions and optionally delete referenced assets.

## Requirements

- Statamic v5 || v6

## How to Install

You can install this addon via Composer:

``` bash
composer require steetshub/statamic-forms-gdpr
```

Publish globals blueprint and content: 

``` bash
php artisan vendor:publish --tag=forms-gdpr-setup
```

## How to Use

After installation new Global set Forms GDPR will be created where you can adjust clean up and schedule settings. Depending of the Planning settings a clean up schedule in your app automatically will be created.
