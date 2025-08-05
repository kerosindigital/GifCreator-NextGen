# GifCreator-NextGen

**Note:** This is a next-generation fork of [@Sybio/GifCreator](https://github.com/Sybio/GifCreator) by Clément Guillemain, maintained by [kerosindigital](https://github.com/kerosindigital).

## What is GifCreator-NextGen?

GifCreator-NextGen is a modern PHP library for creating animated GIFs from multiple images. It is a refactored and enhanced version of Sybio/GifCreator, supporting strict typing, improved error handling, and optimized resource management for PHP 8+.

## Features

- **PHP 8+ compatible:** Uses strict typing and modern PHP syntax.
- **Improved robustness:** Enhanced error handling and resource management.
- **Flexible API:** Accepts image resources, file paths, URLs, or binary GIF strings.
- **Per-frame control:** Supports custom frame durations and loop counts.
- **Transparency support:** Automatically detects and preserves transparency from the first frame.
- **Easy integration:** No external dependencies besides PHP GD extension.

## Requirements

- **PHP >= 8.0**
- **GD extension** enabled
- **File/URL access** for input images

## Differences from the Original (Sybio/GifCreator)

- Modernized codebase with strict typing and typed properties.
- Robust error handling using a dedicated exception class.
- Explicit freeing of GD resources.
- Improved documentation and clearer method/variable names.
- Maintained API compatibility for easy migration.

## Installation

Install via Composer:

```bash
composer require kerosindigital/gifcreator-nextgen
```

## Usage

```php
use GifCreatorNextGen\GifCreatorNextGen;

$frames = [
    imagecreatefrompng('/path/pic1.png'),
    '/path/pic2.png',
    file_get_contents('/path/pic3.jpg'),
    'http://example.com/pic4.jpg',
];
$durations = [40, 80, 40, 20]; // durations in hundredths of a second
$gc = new GifCreatorNextGen();
$gc->create($frames, $durations, 5); // 5 loops
$gifBinary = $gc->getGif();
file_put_contents('/output/animated.gif', $gifBinary);
```

## Behavior

- Transparency and dimensions are taken from the first frame.
- Animated GIFs as input frames are not supported.

## Credits

- Fork, improvements, and maintenance: [kerosindigital](https://github.com/kerosindigital)
- Original author: Clément Guillemain ([Sybio/GifCreator](https://github.com/Sybio/GifCreator))

## License

MIT – see [LICENSE](./LICENSE)