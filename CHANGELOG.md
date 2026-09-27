# Changelog

All notable changes to UniFileManager Core are documented here.

The project follows [Semantic Versioning](https://semver.org/).

## v0.1.5 - 2026-09-29

### Added
- Added recursive folder deletion with authorization preflight for every descendant.

## v0.1.4 - 2026-08-28

### Fixed
- Optimized S3-compatible and object-store directory listings by using Flysystem listing metadata instead of making extra metadata calls per entry.
- Added safer file-attribute handling for object-store listings.
- Added MIME type fallback support for object-store listings that do not return a MIME type.

Thanks to @FaizAhmadSE for contributing the original object-store listing optimization work.

## v0.1.3 - 2026-08-10

### Fixed
- Stream uploaded files when Livewire provides a remote temporary upload, such as Laravel Vapor/S3, instead of opening the temporary path locally.

## v0.1.2 - 2026-07-30

### Fixed
- Prevent changing file extensions while renaming files.

## v0.1.1 - 2026-07-27

### Fixed
- Hide dot-prefixed files and folders from file listings.

## v0.1.0 - 2026-07-26

### Added
- Added the shared UniFileManager core package.
