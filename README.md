# GalaxyResult

[Русский](README.ru.md)

A standalone PHP 8.4+ library for accumulating an operation result. The `denetorus/galaxy-result` package uses PSR-4 and the `Galaxy\Result` namespace. Only PHP is required at runtime.

`GResult` holds a name, data and ordered collections of messages, warnings and errors. Entries can be explicitly copied between results. The result code is the first current error's code, or `0` when there are no errors. Warnings leave the operation outcome unchanged. Formatting and delivery belong to the calling application or external components.

The full contract is defined in [ADR-001](docs/adr/ADR-001-GalaxyResult.md).

## Getting Started

Create the application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Denetorus/GalaxyResult.git"
        }
    ],
    "require": {
        "denetorus/galaxy-result": "0.1.0"
    }
}
```

Run `composer install`. The `0.1.0` constraint selects the published `v0.1.0` tag, and `composer.lock` records the selected revision.

Save the following PHP file beside the `vendor` directory and run it:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Galaxy\Result\GResult;

$source = new GResult(name: 'Source');
$source->setData('processed', 2);
if ($source->getData('processed') !== 2) {
    throw new RuntimeException('Unexpected data');
}
echo $source->getCode(), PHP_EOL; // 0

$uid = $source->addError(2, 'Import interrupted');
$receiver = new GResult(name: 'Receiver');
$receiver->copyErrorsFrom($source);
$entry = $receiver->getEntry($uid);
if ($entry['uid'] !== $uid || $entry['originPath'] !== 'Receiver->Source') {
    throw new RuntimeException('Unexpected copied entry');
}
echo $receiver->getCode(), PHP_EOL; // 2
```

`hasData()` distinguishes a missing key from an existing `null`. For an existing `null`, `getData()` returns `null`. Reading a missing key or UID throws `OutOfBoundsException`.

Data and context accept plain values — `null`, bool, int, finite float, string and arrays of these values. References are isolated on input and when returning snapshots. Data has a separate limit budget from the combined budget of the three entry collections. A type or limit violation rejects the operation atomically, preserving the previous state.

Warning and error codes must be positive integers. A code `<= 0` causes `InvalidArgumentException`. Self-copy and a UID collision during copying cause `LogicException`. Copying preserves UIDs and prefixes `originPath` with the receiver's name.

## Tests

After installing development dependencies with `composer install`, run from the package root:

```text
php vendor/bin/phpunit --configuration phpunit.xml
```

## License

The library is distributed under the MIT license. See [LICENSE](LICENSE).
