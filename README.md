# Laravel TypeScript

[![Latest Version on Packagist](https://img.shields.io/packagist/v/BasedCollective/laravel-typescript.svg?style=flat-square)](https://packagist.org/packages/BasedCollective/laravel-typescript)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/BasedCollective/laravel-typescript/run-tests.yml?label=tests&branch=main)](https://github.com/BasedCollective/laravel-typescript/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/basedcollective/laravel-typescript.svg?style=flat-square)](https://packagist.org/packages/basedcollective/laravel-typescript)

> [!NOTE]  
> This is an up-to-date maintained fork of the abandoned [laravel-typescript](https://github.com/lepikhinb/laravel-typescript) package.

The package lets you generate TypeScript interfaces from your Laravel models.

## Installation

**Laravel 10+ and PHP 8.1 are required.**

You can install the package via composer:

```bash
composer require basedcollective/laravel-typescript
```

## Usage

Generate TypeScript interfaces:

```bash
php artisan typescript:generate
```

Example usage with Vue 3:

```vue
<script setup lang="ts">
defineProps<{
    product: App.Models.Product;
}>();
</script>

<template>
    <h1>{{ product.name }}</h1>
    <p>Price: ${{ product.price }}</p>
</template>
```

## Configuration

Optionally, you can publish the config file:

```bash
php artisan vendor:publish --provider="Based\TypeScript\TypeScriptServiceProvider" --tag="typescript-config"
```

This is the contents of the published config file:

```php
return [
    'generators' => [
        Model::class => ModelGenerator::class,
    ],

    'output' => resource_path('js/models.d.ts'),

    // load namespaces from composer's `dev-autoload`
    'autoloadDev' => false,
];
```

## Introduction

Say you have a model which has several properties (database columns) and multiple relations.

```php
class Product extends Model
{
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }
}
```

Laravel TypeScript will generate the following TypeScript interface:

```typescript
declare namespace App.Models {
    export interface Product {
        id: number;
        category_id: number;
        name: string;
        price: number;
        created_at: string | null;
        updated_at: string | null;
        category?: App.Models.Category | null;
        features?: Array<App.Models.Feature> | null;
    }
    ...
}
```

**Laravel TypeScript** supports:
- [x] Database columns
- [x] Model relations
- [x] Model accessors
- [ ] Casted attributes

## Testing

```bash
composer test
```

## Credits

- [Ostap Brehin](https://github.com/osbre)
- [Boris Lepikhin](https://github.com/lepikhinb) (original author)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
