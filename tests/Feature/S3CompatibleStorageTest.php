<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use UniFileManager\Core\Services\FileManager;

beforeEach(function (): void {
    File::ensureDirectoryExists(storage_path('framework/testing/disks/testing-s3-compatible'));
    File::cleanDirectory(storage_path('framework/testing/disks/testing-s3-compatible'));

    Storage::extend('s3-compatible-test', static function ($app, array $config): FilesystemAdapter {
        $adapter = new LocalFilesystemAdapter($config['root']);
        $filesystem = new Filesystem($adapter, $config);

        return new FilesystemAdapter($filesystem, $adapter, $config);
    });

    config()->set('filesystems.disks.testing_s3_compatible', [
        'driver' => 's3-compatible-test',
        'root' => storage_path('framework/testing/disks/testing-s3-compatible'),
        'url' => 'https://assets.example.com',
    ]);
});

it('manages private files on an S3 compatible disk within the configured root', function (): void {
    config()->set('unifilemanager.storage_areas.private', [
        'enabled' => true,
        'disk' => 'testing_s3_compatible',
        'root' => 'applications/acme/private',
        'visibility' => 'private',
    ]);

    Storage::disk('testing_s3_compatible')->put('applications/acme/private/contracts/terms.txt', 'terms');
    Storage::disk('testing_s3_compatible')->put('applications/acme/other.txt', 'outside');

    $manager = app(FileManager::class);

    expect($manager->list((object) ['id' => 1]))->toHaveCount(1)
        ->and($manager->downloadPath((object) ['id' => 1], 'contracts/terms.txt'))
        ->toBe('applications/acme/private/contracts/terms.txt')
        ->and($manager->publicUrl((object) ['id' => 1], 'contracts/terms.txt'))->toBeNull();
});

it('lists object-store files with metadata from the directory listing response', function (): void {
    config()->set('unifilemanager.storage_areas.private', [
        'enabled' => true,
        'disk' => 'testing_s3_compatible',
        'root' => 'applications/acme/private',
        'visibility' => 'private',
    ]);

    Storage::disk('testing_s3_compatible')->put('applications/acme/private/report.pdf', 'terms');

    $items = app(FileManager::class)->list((object) ['id' => 1]);

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray([
            'name' => 'report.pdf',
            'path' => 'report.pdf',
            'type' => 'file',
            'mime_type' => 'application/pdf',
        ])
        ->and($items[0]['size'])->toBe(5)
        ->and($items[0]['modified_at'])->toBeInt();
});

it('uploads files to an S3 compatible disk using the storage area visibility', function (): void {
    config()->set('unifilemanager.storage_areas.private', [
        'enabled' => true,
        'disk' => 'testing_s3_compatible',
        'root' => 'applications/acme/private',
        'visibility' => 'private',
    ]);

    $path = app(FileManager::class)->upload(
        (object) ['id' => 1],
        UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
        'invoices',
    );

    expect($path)->toBe('invoices/report.pdf')
        ->and(Storage::disk('testing_s3_compatible')->exists('applications/acme/private/invoices/report.pdf'))->toBeTrue()
        ->and(Storage::disk('testing_s3_compatible')->visibility('applications/acme/private/invoices/report.pdf'))->toBe('private');
});

it('returns public URLs for public S3 compatible media areas', function (): void {
    config()->set('unifilemanager.storage_areas.public', [
        'enabled' => true,
        'disk' => 'testing_s3_compatible',
        'root' => 'applications/acme/public',
        'visibility' => 'public',
    ]);

    Storage::disk('testing_s3_compatible')->put('applications/acme/public/images/cover.jpg', 'image');

    $url = app(FileManager::class)
        ->forArea('public')
        ->publicUrl((object) ['id' => 1], 'images/cover.jpg');

    expect($url)->toBe('https://assets.example.com/applications/acme/public/images/cover.jpg');
});
