<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use UniFileManager\Core\Exceptions\FolderNotEmpty;
use UniFileManager\Core\Exceptions\InvalidFilePath;
use UniFileManager\Core\Exceptions\UnsafeDiskConfiguration;
use UniFileManager\Core\Services\FileManager;

beforeEach(function (): void {
    Storage::fake('testing_core');
});

it('lists only files below its configured root', function (): void {
    Storage::disk('testing_core')->put('tenant-a/contracts/terms.txt', 'terms');
    Storage::disk('testing_core')->put('outside.txt', 'private');

    $items = app(FileManager::class)->list((object) ['id' => 1]);

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['name' => 'contracts', 'path' => 'contracts', 'type' => 'directory']);
});

it('does not list dot-prefixed files or directories', function (): void {
    Storage::disk('testing_core')->put('tenant-a/.gitignore', '*');
    Storage::disk('testing_core')->put('tenant-a/visible.txt', 'visible');
    Storage::disk('testing_core')->makeDirectory('tenant-a/.private');
    Storage::disk('testing_core')->makeDirectory('tenant-a/documents');

    $items = app(FileManager::class)->list((object) ['id' => 1]);

    expect(array_column($items, 'name'))->toBe(['documents', 'visible.txt'])
        ->and(array_column($items, 'name'))->not->toContain('.gitignore', '.private');
});

it('rejects a public disk configured for private storage', function (): void {
    config()->set('unifilemanager.storage_areas.private.disk', 'public');

    app(FileManager::class)->list((object) ['id' => 1]);
})->throws(UnsafeDiskConfiguration::class, 'File Manager must use a private disk. The public disk and web-served local storage roots are not supported.');

it('rejects directory traversal in browser supplied paths', function (): void {
    app(FileManager::class)->list((object) ['id' => 1], '../outside');
})->throws(InvalidFilePath::class);

it('never allows deleting the configured root', function (): void {
    app(FileManager::class)->delete((object) ['id' => 1], '');
})->throws(InvalidFilePath::class);

it('never allows renaming the configured root', function (): void {
    app(FileManager::class)->rename((object) ['id' => 1], '', 'renamed-root');
})->throws(InvalidFilePath::class, 'The configured root cannot be renamed.');

it('never allows moving the configured root', function (): void {
    app(FileManager::class)->move((object) ['id' => 1], '', 'destination');
})->throws(InvalidFilePath::class, 'The configured root cannot be moved.');

it('does not move a folder into one of its own subfolders', function (): void {
    Storage::disk('testing_core')->makeDirectory('tenant-a/folder/child');

    app(FileManager::class)->move((object) ['id' => 1], 'folder', 'folder/child');
})->throws(InvalidFilePath::class, 'A folder cannot be moved into itself or one of its subfolders.');

it('requires an existing folder as a move destination', function (): void {
    Storage::disk('testing_core')->put('tenant-a/document.txt', 'contents');

    app(FileManager::class)->move((object) ['id' => 1], 'document.txt', 'missing-folder');
})->throws(InvalidFilePath::class, 'The destination must be an existing folder.');

it('moves a file into an eligible destination folder', function (): void {
    Storage::disk('testing_core')->put('tenant-a/document.txt', 'contents');
    Storage::disk('testing_core')->makeDirectory('tenant-a/archive');

    $path = app(FileManager::class)->move((object) ['id' => 1], 'document.txt', 'archive');

    expect($path)->toBe('archive/document.txt')
        ->and(Storage::disk('testing_core')->exists('tenant-a/document.txt'))->toBeFalse()
        ->and(Storage::disk('testing_core')->exists('tenant-a/archive/document.txt'))->toBeTrue();
});

it('identifies whether a folder is a valid move destination', function (): void {
    Storage::disk('testing_core')->put('tenant-a/document.txt', 'contents');
    Storage::disk('testing_core')->makeDirectory('tenant-a/archive');
    Storage::disk('testing_core')->makeDirectory('tenant-a/occupied');
    Storage::disk('testing_core')->put('tenant-a/occupied/document.txt', 'contents');

    $manager = app(FileManager::class);

    expect($manager->canMoveTo((object) ['id' => 1], 'document.txt', 'archive'))->toBeTrue()
        ->and($manager->canMoveTo((object) ['id' => 1], 'document.txt', ''))->toBeFalse()
        ->and($manager->canMoveTo((object) ['id' => 1], 'document.txt', 'occupied'))->toBeFalse();
});

it('offers only valid destination folders for a move', function (): void {
    Storage::disk('testing_core')->makeDirectory('tenant-a/projects/current/child');
    Storage::disk('testing_core')->makeDirectory('tenant-a/archive');
    Storage::disk('testing_core')->makeDirectory('tenant-a/finished');
    Storage::disk('testing_core')->put('tenant-a/archive/current', 'existing');

    $destinations = app(FileManager::class)->moveDestinations((object) ['id' => 1], 'projects/current');

    expect($destinations)
        ->toHaveKey('')
        ->toHaveKey('finished')
        ->not->toHaveKey('archive')
        ->not->toHaveKey('projects')
        ->not->toHaveKey('projects/current')
        ->not->toHaveKey('projects/current/child');
});

it('does not delete a folder that contains files or folders', function (): void {
    Storage::disk('testing_core')->put('tenant-a/occupied/document.txt', 'contents');
    Storage::disk('testing_core')->makeDirectory('tenant-a/occupied/nested');

    app(FileManager::class)->delete((object) ['id' => 1], 'occupied');
})->throws(FolderNotEmpty::class, 'This folder is not empty. Delete or move its contents first.');

it('recursively deletes a folder and its contents', function (): void {
    Storage::disk('testing_core')->put('tenant-a/occupied/document.txt', 'contents');
    Storage::disk('testing_core')->put('tenant-a/occupied/nested/child.txt', 'child');

    app(FileManager::class)->deleteFolder((object) ['id' => 1], 'occupied');

    expect(Storage::disk('testing_core')->directoryMissing('tenant-a/occupied'))->toBeTrue();
});

it('does not recursively delete a file', function (): void {
    Storage::disk('testing_core')->put('tenant-a/document.txt', 'contents');

    app(FileManager::class)->deleteFolder((object) ['id' => 1], 'document.txt');
})->throws(InvalidFilePath::class, 'The selected item is not a folder.');

it('detects whether a folder is empty', function (): void {
    Storage::disk('testing_core')->makeDirectory('tenant-a/empty');
    Storage::disk('testing_core')->put('tenant-a/occupied/document.txt', 'contents');

    $manager = app(FileManager::class);

    expect($manager->isFolderEmpty((object) ['id' => 1], 'empty'))->toBeTrue()
        ->and($manager->isFolderEmpty((object) ['id' => 1], 'occupied'))->toBeFalse();
});

it('creates sequentially named folders without overwriting an existing folder', function (): void {
    Storage::disk('testing_core')->makeDirectory('tenant-a/New folder');

    $path = app(FileManager::class)->createNewDirectory((object) ['id' => 1], '');

    expect($path)->toBe('New folder (2)')
        ->and(Storage::disk('testing_core')->directoryExists('tenant-a/New folder (2)'))->toBeTrue();
});

it('limits folder nesting to the configured maximum depth', function (): void {
    $parentPath = '';

    for ($level = 1; $level <= 7; $level++) {
        $name = 'Level '.$level;
        app(FileManager::class)->createDirectory((object) ['id' => 1], $parentPath, $name);
        $parentPath = $parentPath === '' ? $name : $parentPath.'/'.$name;
    }

    app(FileManager::class)->createDirectory((object) ['id' => 1], $parentPath, 'Level 8');
})->throws(InvalidFilePath::class, 'Folders can be nested up to 7 levels deep.');

it('allows saving a rename without changing the item name', function (): void {
    Storage::disk('testing_core')->makeDirectory('tenant-a/New folder');

    $path = app(FileManager::class)->rename((object) ['id' => 1], 'New folder', 'New folder');

    expect($path)->toBe('New folder')
        ->and(Storage::disk('testing_core')->directoryExists('tenant-a/New folder'))->toBeTrue();
});

it('renames a file within the configured root', function (): void {
    Storage::disk('testing_core')->put('tenant-a/old-name.txt', 'contents');

    $path = app(FileManager::class)->rename((object) ['id' => 1], 'old-name.txt', 'new-name.txt');

    expect($path)->toBe('new-name.txt')
        ->and(Storage::disk('testing_core')->exists('tenant-a/new-name.txt'))->toBeTrue()
        ->and(Storage::disk('testing_core')->exists('tenant-a/old-name.txt'))->toBeFalse();
});

it('does not allow changing a file extension while renaming', function (): void {
    Storage::disk('testing_core')->put('tenant-a/report.pdf', 'contents');

    app(FileManager::class)->rename((object) ['id' => 1], 'report.pdf', 'report.txt');
})->throws(InvalidFilePath::class, 'File extensions cannot be changed while renaming.');

it('preserves upload names and adds a suffix instead of overwriting a file', function (): void {
    Storage::disk('testing_core')->put('tenant-a/report.pdf', 'existing');

    $path = app(FileManager::class)->upload(
        (object) ['id' => 1],
        UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
    );

    expect($path)->toBe('report (2).pdf')
        ->and(Storage::disk('testing_core')->exists('tenant-a/report.pdf'))->toBeTrue()
        ->and(Storage::disk('testing_core')->exists('tenant-a/report (2).pdf'))->toBeTrue();
});

it('preserves normal client filename characters such as plus signs', function (): void {
    $path = app(FileManager::class)->upload(
        (object) ['id' => 1],
        UploadedFile::fake()->create('Amperative_Blue+Icon_filled_RGB-1920w.png', 10, 'image/png'),
    );

    expect($path)->toBe('Amperative_Blue+Icon_filled_RGB-1920w.png');
});

it('stores uploaded files from a remote temporary upload stream', function (): void {
    $path = app(FileManager::class)->upload(
        (object) ['id' => 1],
        new RemoteStreamUploadedFileFake('remote-report.pdf', 'remote report'),
    );

    expect($path)->toBe('remote-report.pdf')
        ->and(Storage::disk('testing_core')->get('tenant-a/remote-report.pdf'))->toBe('remote report');
});

final class RemoteStreamUploadedFileFake extends UploadedFile
{
    private string $streamPath;

    public function __construct(string $name, string $contents)
    {
        $this->streamPath = tempnam(sys_get_temp_dir(), 'ufm-remote-upload-');
        file_put_contents($this->streamPath, $contents);

        parent::__construct($this->streamPath, $name, 'application/pdf', null, true);
    }

    public function __destruct()
    {
        if (is_file($this->streamPath)) {
            unlink($this->streamPath);
        }
    }

    public function getRealPath(): string
    {
        return 'livewire-tmp/remote-report.pdf';
    }

    public function readStream()
    {
        return fopen($this->streamPath, 'r');
    }
}
