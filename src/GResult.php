<?php

declare(strict_types=1);

namespace Galaxy\Result;

use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use OverflowException;
use ReflectionReference;

final class GResult
{
    private const array BASE_LIMITS = [
        'maxDepth' => 32,
        'maxValueNodes' => 100000,
        'maxStringBytes' => 16777216,
        'maxEntries' => 4096,
    ];

    private bool $initialized = false;
    private readonly string $name;
    private readonly array $limits;
    private array $data = [];
    private int $revision = 0;
    private array $entries = ['messages' => [], 'warnings' => [], 'errors' => []];

    public function __construct(array $limits = [], ?string $name = null)
    {
        if ($this->initialized) {
            throw new LogicException('A result cannot be initialized twice.');
        }
        $this->initialized = true;
        $settings = self::BASE_LIMITS;
        foreach ($limits as $key => $limit) {
            if (!array_key_exists($key, $settings) || !is_int($limit) || $limit < 1) {
                throw new InvalidArgumentException('Limits must be known positive integer settings.');
            }
            $settings[$key] = $limit;
        }
        if ($name === null) {
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $name = $frames[1]['class'] ?? 'GResult';
            unset($frames);
        }
        $this->name = $name;
        $this->limits = $settings;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isSuccess(): bool
    {
        return $this->entries['errors'] === [];
    }

    public function getCode(): int
    {
        foreach ($this->entries['errors'] as $entry) {
            return $entry['code'];
        }

        return 0;
    }

    public function getConsoleCode(): int
    {
        foreach ($this->entries['errors'] as $entry) {
            return $entry['consoleCode'];
        }

        return 0;
    }

    public function hasData(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function getData(string $key): mixed
    {
        if (!$this->hasData($key)) {
            throw new OutOfBoundsException('The requested data key does not exist.');
        }

        return $this->data[$key];
    }

    public function getAllData(): array
    {
        return $this->data;
    }

    public function getDataRevision(): int
    {
        return $this->revision;
    }

    public function setData(string $key, #[\SensitiveParameter] mixed $value): self
    {
        $replacement = $this->data;
        $replacement[$key] = $value;

        return $this->replaceData($replacement);
    }

    public function replaceData(#[\SensitiveParameter] array $data): self
    {
        $budget = $this->newBudget();
        $replacement = $this->copyValue($data, 0, $budget);
        $revision = $this->revisionAfter($replacement);
        $this->data = $replacement;
        $this->revision = $revision;

        return $this;
    }

    public function removeData(string $key): bool
    {
        if (!$this->hasData($key)) {
            return false;
        }
        $replacement = $this->data;
        unset($replacement[$key]);
        $this->replaceData($replacement);

        return true;
    }

    public function clearData(): self
    {
        return $this->replaceData([]);
    }

    public function copyDataFrom(self $source, bool $clearSource = false): self
    {
        if ($source === $this) {
            throw new LogicException('A result cannot copy data from itself.');
        }
        $replacement = $this->data;
        foreach ($source->data as $key => $value) {
            $replacement[$key] = $value;
        }
        $budget = $this->newBudget();
        $replacement = $this->copyValue($replacement, 0, $budget);
        $sourceData = $clearSource ? [] : $source->data;
        $receiverRevision = $this->revisionAfter($replacement);
        $sourceRevision = $source->revisionAfter($sourceData);

        $this->data = $replacement;
        $this->revision = $receiverRevision;
        $source->data = $sourceData;
        $source->revision = $sourceRevision;

        return $this;
    }

    public function addMessage(#[\SensitiveParameter] string $text, #[\SensitiveParameter] array $context = []): string
    {
        return $this->appendEntry('messages', null, $text, $context);
    }

    public function addWarning(int $code, #[\SensitiveParameter] string $text, #[\SensitiveParameter] array $context = []): string
    {
        return $this->appendEntry('warnings', $code, $text, $context);
    }

    public function addError(int $code, #[\SensitiveParameter] string $text, #[\SensitiveParameter] array $context = [], int $consoleCode = 1): string
    {
        return $this->appendEntry('errors', $code, $text, $context, $consoleCode);
    }

    public function getMessages(): array
    {
        return $this->entries['messages'];
    }

    public function getWarnings(): array
    {
        return $this->entries['warnings'];
    }

    public function getErrors(): array
    {
        return $this->entries['errors'];
    }

    public function getEntry(string $uid): array
    {
        return $this->entries[$this->locateEntry($uid)][$uid];
    }

    public function updateEntry(string $uid, #[\SensitiveParameter] array $changes): self
    {
        $collection = $this->locateEntry($uid);
        $entry = $this->entries[$collection][$uid];
        foreach ($changes as $field => $value) {
            $valid = match ($field) {
                'text' => is_string($value),
                'context' => is_array($value),
                'code' => $collection !== 'messages' && is_int($value) && $value > 0,
                'consoleCode' => $collection === 'errors' && is_int($value) && $value >= 1 && $value <= 255,
                default => false,
            };
            if (!$valid) {
                throw new InvalidArgumentException('The entry update contains an unsupported field or value.');
            }
            $entry[$field] = $value;
        }
        $replacement = $this->entries;
        $replacement[$collection][$uid] = $entry;
        $this->entries = $this->entriesSnapshot($replacement);

        return $this;
    }

    public function removeMessage(string $uid): bool
    {
        return $this->removeFrom('messages', $uid);
    }

    public function removeWarning(string $uid): bool
    {
        return $this->removeFrom('warnings', $uid);
    }

    public function removeError(string $uid): bool
    {
        return $this->removeFrom('errors', $uid);
    }

    public function clearMessages(): self
    {
        $this->entries['messages'] = [];

        return $this;
    }

    public function clearWarnings(): self
    {
        $this->entries['warnings'] = [];

        return $this;
    }

    public function clearErrors(): self
    {
        $this->entries['errors'] = [];

        return $this;
    }

    public function copyMessagesFrom(self $source, bool $clearSource = false): self
    {
        return $this->copyCollection($source, 'messages', $clearSource);
    }

    public function copyWarningsFrom(self $source, bool $clearSource = false): self
    {
        return $this->copyCollection($source, 'warnings', $clearSource);
    }

    public function copyErrorsFrom(self $source, bool $clearSource = false): self
    {
        return $this->copyCollection($source, 'errors', $clearSource);
    }

    private function appendEntry(
        string $collection,
        ?int $code,
        #[\SensitiveParameter] string $text,
        #[\SensitiveParameter] array $context,
        ?int $consoleCode = null,
    ): string {
        if ($code !== null && $code < 1) {
            throw new InvalidArgumentException('Warning and error codes must be positive integers.');
        }
        if ($consoleCode !== null && ($consoleCode < 1 || $consoleCode > 255)) {
            throw new InvalidArgumentException('Console codes must be integers from 1 to 255.');
        }
        $uid = bin2hex(random_bytes(16));
        foreach ($this->entries as $records) {
            if (isset($records[$uid])) {
                throw new LogicException('The generated UID already exists.');
            }
        }
        $replacement = $this->entries;
        $replacement[$collection][$uid] = [
            'uid' => $uid,
            'originPath' => $this->name,
            'text' => $text,
            'code' => $code,
            'context' => $context,
        ];
        if ($consoleCode !== null) {
            $replacement[$collection][$uid]['consoleCode'] = $consoleCode;
        }
        $this->entries = $this->entriesSnapshot($replacement);

        return $uid;
    }

    private function locateEntry(string $uid): string
    {
        foreach ($this->entries as $collection => $records) {
            if (isset($records[$uid])) {
                return $collection;
            }
        }

        throw new OutOfBoundsException('The requested entry UID does not exist.');
    }

    private function removeFrom(string $collection, string $uid): bool
    {
        if (!isset($this->entries[$collection][$uid])) {
            return false;
        }
        unset($this->entries[$collection][$uid]);

        return true;
    }

    private function copyCollection(self $source, string $collection, bool $clearSource): self
    {
        if ($source === $this) {
            throw new LogicException('A result cannot copy entries from itself.');
        }
        $replacement = $this->entries;
        foreach ($source->entries[$collection] as $uid => $entry) {
            foreach ($this->entries as $records) {
                if (isset($records[$uid])) {
                    throw new LogicException('An incoming UID already exists in the receiving result.');
                }
            }
            $entry['originPath'] = $this->name . '->' . $entry['originPath'];
            $replacement[$collection][$uid] = $entry;
        }
        $receiverEntries = $this->entriesSnapshot($replacement);
        $sourceEntries = $source->entries;
        if ($clearSource) {
            $sourceEntries[$collection] = [];
        }
        $sourceEntries = $source->entriesSnapshot($sourceEntries);

        $this->entries = $receiverEntries;
        $source->entries = $sourceEntries;

        return $this;
    }

    private function newBudget(): array
    {
        return ['nodesLeft' => $this->limits['maxValueNodes'],
            'bytesLeft' => $this->limits['maxStringBytes'], 'references' => []];
    }

    private function consumeNode(array &$budget): void
    {
        if ($budget['nodesLeft'] === 0) {
            throw new InvalidArgumentException('The logical value node limit was exceeded.');
        }
        --$budget['nodesLeft'];
    }

    private function consumeString(#[\SensitiveParameter] string $value, array &$budget): void
    {
        $size = strlen($value);
        if ($size > $budget['bytesLeft']) {
            throw new InvalidArgumentException('The string byte limit was exceeded.');
        }
        $budget['bytesLeft'] -= $size;
    }

    private function copyValue(#[\SensitiveParameter] mixed $value, int $depth, array &$budget): mixed
    {
        if ($depth > $this->limits['maxDepth']) {
            throw new InvalidArgumentException('The value depth limit was exceeded.');
        }
        $this->consumeNode($budget);
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $element) {
                if (is_string($key)) {
                    $this->consumeString($key, $budget);
                }
                $reference = ReflectionReference::fromArrayElement($value, $key)?->getId();
                if ($reference !== null) {
                    if (isset($budget['references'][$reference])) {
                        throw new InvalidArgumentException('Cyclic values are not supported.');
                    }
                    $budget['references'][$reference] = true;
                }
                $copy[$key] = $this->copyValue($element, $depth + 1, $budget);
                if ($reference !== null) {
                    unset($budget['references'][$reference]);
                }
            }

            return $copy;
        }
        if (is_string($value)) {
            $this->consumeString($value, $budget);

            return $value;
        }
        if ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }

        throw new InvalidArgumentException('Only arrays and finite plain scalar values are supported.');
    }

    private function entriesSnapshot(#[\SensitiveParameter] array $entries): array
    {
        $count = 0;
        foreach ($entries as $records) {
            $count += count($records);
        }
        if ($count > $this->limits['maxEntries']) {
            throw new InvalidArgumentException('The aggregate entry limit was exceeded.');
        }
        $budget = $this->newBudget();
        $this->consumeNode($budget);
        $snapshot = [];
        foreach ($entries as $collection => $records) {
            $this->consumeString($collection, $budget);
            $this->consumeNode($budget);
            $snapshot[$collection] = [];
            foreach ($records as $uid => $entry) {
                $this->consumeString($uid, $budget);
                $this->consumeNode($budget);
                $snapshot[$collection][$uid] = [];
                foreach ($entry as $field => $value) {
                    $this->consumeString($field, $budget);
                    $snapshot[$collection][$uid][$field] = $this->copyValue($value, 0, $budget);
                }
            }
        }

        return $snapshot;
    }

    private function revisionAfter(#[\SensitiveParameter] array $replacement): int
    {
        if (self::equalValues($this->data, $replacement)) {
            return $this->revision;
        }
        if ($this->revision === PHP_INT_MAX) {
            throw new OverflowException('The data revision cannot be incremented.');
        }

        return $this->revision + 1;
    }

    private static function equalValues(
        #[\SensitiveParameter] mixed $left,
        #[\SensitiveParameter] mixed $right,
    ): bool {
        if (gettype($left) !== gettype($right)) {
            return false;
        }
        if (is_array($left)) {
            if (array_keys($left) !== array_keys($right)) {
                return false;
            }
            foreach ($left as $key => $value) {
                if (!self::equalValues($value, $right[$key])) {
                    return false;
                }
            }

            return true;
        }
        if (is_float($left)) {
            return pack('E', $left) === pack('E', $right);
        }

        return $left === $right;
    }

    private function __clone(): void
    {
    }

    public function __serialize(): array
    {
        throw new LogicException('Native serialization of a result is not supported.');
    }

    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        throw new LogicException('Native unserialization of a result is not supported.');
    }
}
