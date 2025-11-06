# 📦 MediaModule

پکیج MediaModule یک سیستم مدیریت رسانه (Media Management System) برای پروژه‌های لاراول است.
با استفاده از این پکیج می‌توانید فایل‌ها و رسانه‌های خود را به‌راحتی مدیریت، ذخیره و بازیابی کنید.

---

## 🚀 نصب

ابتدا پکیج را از طریق مخزن گیتهاب در مسیر مورد نظر نصب کنید:

```bash
git clone https://github.com/lhaamed/MediaModule.git
```

## ⚙️ پیکربندی 

پس از درون ریزی پکیج‌ ابتدا مسیر اصلی را در فایل composer.json در بخش autoload psr-4 ثبت کنید:

```bash
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Database\\Factories\\": "database/factories/",
        "Database\\Seeders\\": "database/seeders/",
        
        // در سمت راست مسیر دسترسی به پروژه را مشخص کنید.
        "lhaamed\\MediaModule\\": "lhaamed/MediaModule/src"
    }
},
```

بعد از آن خط زیر را در لیست Provider ها قرار دهید:
```bash
lhaamed\MediaModule\MediaServiceProvider::class
```

سپس در ترمینال دستور زیر را وارد کنید تا پکیج توسط کامپوزر شناسایی شود:

```bash
composer du
```

پس از درون ریزی پکیج با استفاده از دستور زیر فایل های پکیج را publish کنید:

```bash
php artisan vendor:publish --provider="lhaamed\MediaModule\MediaServiceProvider"
```

یا فقط بخش مورد نظر خود را:

```bash
# فقط Config
php artisan vendor:publish --tag=MediaModule-config

# فقط Migration
php artisan vendor:publish --tag=MediaModule-migrations

# فقط Service
php artisan vendor:publish --tag=MediaModule-Services

# فقط Provider
php artisan vendor:publish --tag=MediaModule-provider

# فقط Models
php artisan vendor:publish --tag=MediaModule-Models

# فقط Traits
php artisan vendor:publish --tag=MediaModule-traits
```

و سپس در فایل .env مقادیر اساسی را تنظیم کنید.


```bash
MEDIAMODULE_DISK=public
MEDIAMODULE_DISK_DISKS=public,other_disks
```


## 🗄️️ Migration

برای ایجاد جداول مورد نیاز باید حداقل فایل های مربوط به migration را publish کرده باشید.
بعد از publish با استفاده از دستور زیر جداول را ایجاد کنید.

 ⚠️ **هشدار:**  شما در پروژه نباید از مدل های Media و MediaThumbnail استفاده کرده باشید و همچنین جداول medias و media_thumbnails و mediaables نباید وجود داشته باشد.


```bash
php artisan migrate
```

## 🛠️ استفاده 

1. دسترسی به سرویس
```bash
use MediaModule;

Media::upload($file);
Media::get($id);
```
2. استفاده از کلاس سرویس

```bash
use lhaamed\MediaModule\Services\MediaService;

$service = new MediaService();
$service->upload($file);
```
3. استفاده از مدل‌ها

```bash
use lhaamed\MediaModule\Models\Media;

$media = Media::find(1);
```

## 📂 ساختار پکیج 

```bash
MediaModule/
├── config/
│ └── MediaModule.php
├── database/
├── src/
│ ├── Models/
│ │ ├── Media.php
│ │ └── MediaThumbnail.php
│ ├── Services/
│ │ └── MediaService.php
│ ├── Traits/
│ │ └── hasFileManager.php
│ ├── MediaFacade.php
│ └── MediaServiceProvider.php
├── .gitignore
├── composer.json
├── composer.lock
└── README.md
```


## 🧩 امکانات

- 📌 مدیریت آسان رسانه‌ها (آپلود، ذخیره و بازیابی)
- 📌 مدل‌های آماده برای توسعه و سفارشی‌سازی
- 📌 قابل توسعه و قابل سفارشی‌سازی
- 📌 دارای migration و config اختصاصی
- 📌 امکان publish کردن سرویس‌ها، provider و مدل‌ها  


