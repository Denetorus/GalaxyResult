<?php

declare(strict_types=1);

namespace Galaxy\Result {
    function random_bytes(int $length): string
    {
        return \Galaxy\Result\Tests\FreshEntropy::$bytes ?? \random_bytes($length);
    }
}

namespace Galaxy\Result\Tests {
    use Galaxy\Result\GResult;
    use InvalidArgumentException;
    use LogicException;
    use OutOfBoundsException;
    use OverflowException;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionMethod;
    use ReflectionProperty;

    final class FreshEntropy
    {
        public static ?string $bytes = null;
    }

    final class FreshResultFactory
    {
        public static function make(): GResult
        {
            return new GResult();
        }
    }

    function freshResultWithoutClass(): GResult
    {
        return new GResult();
    }

    final class GResultTest extends TestCase
    {
        protected function tearDown(): void
        {
            FreshEntropy::$bytes = null;
        }

        private function resultState(GResult $result): array
        {
            return [$result->getName(), $result->getAllData(), $result->getDataRevision(),
                $result->getMessages(), $result->getWarnings(), $result->getErrors(),
                $result->isSuccess(), $result->getCode()];
        }

        private function rejects(GResult $result, callable $action, string $exception = InvalidArgumentException::class, ?GResult $source = null): void
        {
            $before = $this->resultState($result);
            $sourceBefore = $source === null ? null : $this->resultState($source);
            try {
                $action();
                self::fail('The invalid mutation must reject.');
            } catch (\Throwable $error) {
                self::assertInstanceOf($exception, $error);
                self::assertSame($before, $this->resultState($result));
                if ($source !== null) {
                    self::assertSame($sourceBefore, $this->resultState($source));
                }
            }
        }

        public function testEmptyResultAndExactExplicitName(): void
        {
            $result = new GResult([], '  Task -> Name  ');
            self::assertSame('  Task -> Name  ', $result->getName());
            self::assertSame('', (new GResult(name: ''))->getName());
            self::assertTrue($result->isSuccess());
            self::assertSame(0, $result->getCode());
            self::assertSame(0, $result->getDataRevision());
            self::assertSame([], $result->getAllData());
            self::assertSame([], $result->getMessages());
            self::assertSame([], $result->getWarnings());
            self::assertSame([], $result->getErrors());
        }

        public function testDerivedNameFallbackAndSafeTrace(): void
        {
            self::assertSame(self::class, (new GResult())->getName());
            self::assertSame(FreshResultFactory::class, FreshResultFactory::make()->getName());
            self::assertSame('GResult', freshResultWithoutClass()->getName());
            $source = file_get_contents((new ReflectionClass(GResult::class))->getFileName());
            self::assertStringContainsString('DEBUG_BACKTRACE_IGNORE_ARGS', $source);
            self::assertStringNotContainsString('DEBUG_BACKTRACE_PROVIDE_OBJECT', $source);
        }

        public function testNativeLifecycleAndRepeatedConstructionAreClosed(): void
        {
            $result = new GResult(name: 'Original');
            $result->setData('value', 1);
            $this->rejects($result, static fn () => $result->__construct(name: 'Changed'), LogicException::class);
            self::assertTrue((new ReflectionMethod(GResult::class, '__clone'))->isPrivate());
            try { clone $result; self::fail('Native cloning must be inaccessible.'); }
            catch (\Error) { self::assertSame('Original', $result->getName()); }
            $this->rejects($result, static fn () => serialize($result), LogicException::class);
            $this->expectException(LogicException::class);
            unserialize('O:21:"Galaxy\\Result\\GResult":0:{}');
        }

        public function testApiAndSourceInventoryMatchTheCleanAdr(): void
        {
            $class = new ReflectionClass(GResult::class);
            self::assertTrue($class->isFinal());
            $expected = ['__construct', '__serialize', '__unserialize', 'getName', 'isSuccess', 'getCode',
                'hasData', 'getData', 'getAllData', 'getDataRevision', 'setData', 'replaceData', 'removeData',
                'clearData', 'copyDataFrom', 'addMessage', 'addWarning', 'addError', 'getMessages', 'getWarnings',
                'getErrors', 'getEntry', 'updateEntry', 'removeMessage', 'removeWarning', 'removeError',
                'clearMessages', 'clearWarnings', 'clearErrors', 'copyMessagesFrom', 'copyWarningsFrom', 'copyErrorsFrom'];
            $actual = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $class->getMethods(ReflectionMethod::IS_PUBLIC));
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual);
            self::assertSame(['limits', 'name'], array_map(static fn ($parameter): string => $parameter->getName(), $class->getConstructor()->getParameters()));
            foreach (['DeliveryMode', 'DispatchFailure', 'MessageDispatcher', 'MessageHandler', 'MessageRetention', 'MessageStyle', 'SilentHandler'] as $type) {
                self::assertFalse(class_exists('Galaxy\\Result\\' . $type));
                self::assertFalse(interface_exists('Galaxy\\Result\\' . $type));
            }
        }

        public function testDataKeysTypesMissingAndNull(): void
        {
            $result = new GResult();
            $data = ['null' => null, 'bool' => false, 'zero' => 0, 'float' => 0.0, 'bytes' => "\xFF\0", 'array' => [5 => true, '05' => []]];
            self::assertSame($result, $result->replaceData($data));
            self::assertSame($data, $result->getAllData());
            self::assertTrue($result->hasData('null'));
            self::assertNull($result->getData('null'));
            self::assertFalse($result->hasData('missing'));
            self::assertFalse($result->removeData('missing'));
            $this->expectException(OutOfBoundsException::class);
            $result->getData('missing');
        }

        public function testInputAliasesAndReturnedSnapshotsAreDetached(): void
        {
            $value = 'original';
            $shared = ['value' => &$value];
            $input = ['first' => &$shared, 'second' => &$shared];
            $result = new GResult();
            $result->replaceData($input);
            $uid = $result->addMessage('text', $input);
            $value = 'changed';
            $shared['other'] = true;
            $data = $result->getAllData();
            $data['first']['value'] = 'caller';
            $single = $result->getData('first');
            $single['value'] = 'caller';
            $entry = $result->getEntry($uid);
            $entry['context']['first']['value'] = 'caller';
            $entries = $result->getMessages();
            $entries[$uid]['context']['second']['value'] = 'caller';
            $expected = ['first' => ['value' => 'original'], 'second' => ['value' => 'original']];
            self::assertSame($expected, $result->getAllData());
            self::assertSame($expected, $result->getEntry($uid)['context']);
            self::assertSame('original', $data['second']['value']);
            self::assertSame(1, $result->getDataRevision());
            $update = ['nested' => &$shared];
            $result->updateEntry($uid, ['context' => $update]);
            $value = 'later';
            self::assertSame('changed', $result->getEntry($uid)['context']['nested']['value']);
            $result->setData('new', $update);
            $value = 'latest';
            self::assertSame('later', $result->getData('new')['nested']['value']);
        }

        public static function invalidValueKinds(): iterable
        {
            foreach (['object', 'closure', 'throwable', 'infinity', 'negative infinity', 'nan'] as $kind) {
                yield $kind => [$kind];
            }
        }

        #[DataProvider('invalidValueKinds')]
        public function testUnsupportedValuesRejectAllPayloadMutationsAtomically(string $kind): void
        {
            $invalid = match ($kind) {
                'object' => new \stdClass(), 'closure' => static fn () => null,
                'throwable' => new \RuntimeException('synthetic private detail'),
                'infinity' => INF, 'negative infinity' => -INF, 'nan' => NAN,
            };
            $result = new GResult();
            $result->setData('keep', true);
            $uid = $result->addMessage('keep');
            $actions = [
                static fn () => $result->setData('invalid', ['value' => $invalid]),
                static fn () => $result->replaceData(['candidate' => true, 'invalid' => $invalid]),
                static fn () => $result->addWarning(1, 'invalid', ['value' => $invalid]),
                static fn () => $result->updateEntry($uid, ['text' => 'changed', 'context' => ['value' => $invalid]]),
            ];
            foreach ($actions as $action) {
                $this->rejects($result, $action);
            }
        }

        public function testResourcesAndCyclesRejectWithoutPublishing(): void
        {
            $result = new GResult();
            $result->setData('keep', true);
            $resource = fopen('php://memory', 'r+');
            try {
                $this->rejects($result, static fn () => $result->setData('resource', $resource));
                $this->rejects($result, static fn () => $result->addError(1, 'resource', ['value' => $resource]));
            } finally { fclose($resource); }
            $direct = [];
            $direct['self'] = &$direct;
            $first = [];
            $second = ['first' => &$first];
            $first['second'] = &$second;
            foreach ([$direct, $first] as $cycle) {
                $this->rejects($result, static fn () => $result->replaceData(['cycle' => $cycle]));
                $this->rejects($result, static fn () => $result->addMessage('cycle', $cycle));
            }
        }

        public static function invalidLimits(): iterable
        {
            yield 'unknown' => [['other' => 1]];
            foreach (['maxDepth', 'maxValueNodes', 'maxStringBytes', 'maxEntries'] as $key) {
                foreach ([0, -1, '2', 2.0, true, null] as $index => $value) {
                    yield $key . '-' . $index => [[$key => $value]];
                }
            }
        }

        #[DataProvider('invalidLimits')]
        public function testInvalidLimitsReject(array $limits): void
        {
            $this->expectException(InvalidArgumentException::class);
            new GResult(limits: $limits);
        }

        public function testConfirmedDefaultsAndConfigurationReferenceIsolation(): void
        {
            $property = new ReflectionProperty(GResult::class, 'limits');
            self::assertTrue($property->isPrivate());
            self::assertTrue($property->isReadOnly());
            self::assertSame(['maxDepth' => 32, 'maxValueNodes' => 100000, 'maxStringBytes' => 16777216, 'maxEntries' => 4096], $property->getValue(new GResult()));
            $depth = 1;
            $result = new GResult(['maxDepth' => &$depth]);
            $depth = 20;
            $this->rejects($result, static fn () => $result->setData('deep', ['value' => 1]));
        }

        public function testDepthRootsAndExpandedAliasNodeLimit(): void
        {
            $shallow = new GResult(['maxDepth' => 1]);
            $shallow->setData('leaf', 1);
            $uid = $shallow->addMessage('context root', ['leaf' => 1]);
            $this->rejects($shallow, static fn () => $shallow->updateEntry($uid, ['context' => ['nested' => ['leaf' => 1]]]));
            $this->rejects($shallow, static fn () => $shallow->setData('deep', ['leaf' => 1]));
            $shared = [null];
            $bounded = new GResult(['maxValueNodes' => 5]);
            $bounded->replaceData(['a' => &$shared, 'b' => &$shared]);
            $this->rejects($bounded, static fn () => $bounded->setData('c', $shared));
            $entries = new GResult(['maxValueNodes' => 20]);
            $large = array_fill(0, 6, null);
            $uid = $entries->addMessage('keep');
            $this->rejects($entries, static fn () => $entries->updateEntry($uid, ['context' => ['a' => &$large, 'b' => &$large]]));
        }

        public function testStringKeysValuesAndIndependentBudgets(): void
        {
            $bounded = new GResult(['maxStringBytes' => 2]);
            $bounded->setData('a', 'x');
            $this->rejects($bounded, static fn () => $bounded->setData('b', ''));
            $independent = new GResult(['maxValueNodes' => 20], 'R');
            $independent->replaceData(array_fill(0, 19, null));
            $independent->addMessage('fits separately', ['v' => true]);
            self::assertSame(1, $independent->getDataRevision());
            self::assertCount(1, $independent->getMessages());
            $entryBytes = new GResult(['maxStringBytes' => 64], 'R');
            $entryBytes->setData('v', str_repeat('d', 63));
            $this->rejects($entryBytes, static fn () => $entryBytes->addMessage(''));
        }

        public function testRepeatedStringReferencesConsumeBytesForEveryOccurrence(): void
        {
            $value = 'xy';
            $result = new GResult(['maxStringBytes' => 6]);
            $result->replaceData(['a' => &$value, 'b' => &$value]);
            $this->rejects($result, static fn () => $result->setData('c', ''));
            $value = 'changed';
            self::assertSame(['a' => 'xy', 'b' => 'xy'], $result->getAllData());
            self::assertSame(1, $result->getDataRevision());
        }
        public function testRevisionUsesTypesOrderAndSignedFloatZero(): void
        {
            $result = new GResult();
            $result->clearData()->replaceData([]);
            self::assertFalse($result->removeData('absent'));
            self::assertSame(0, $result->getDataRevision());
            $result->setData('x', 0)->setData('x', 0)->replaceData(['x' => 0]);
            self::assertSame(1, $result->getDataRevision());
            $result->setData('x', 0.0);
            self::assertSame(2, $result->getDataRevision());
            $result->setData('x', -0.0)->setData('x', -0.0);
            self::assertSame(3, $result->getDataRevision());
            self::assertSame(pack('E', -0.0), pack('E', $result->getData('x')));
            $result->setData('nested', ['a' => 1, 'b' => 2]);
            $result->setData('nested', ['b' => 2, 'a' => 1]);
            self::assertSame(5, $result->getDataRevision());
            $result->replaceData(['nested' => ['b' => 2, 'a' => 1], 'x' => -0.0]);
            self::assertSame(6, $result->getDataRevision());
            self::assertTrue($result->removeData('x'));
            self::assertSame(7, $result->getDataRevision());
            $result->clearData()->clearData();
            self::assertSame(8, $result->getDataRevision());
        }

        public function testDataCopyPreservesNumericKeysAndAdvancesOnlyChangedSides(): void
        {
            $source = new GResult();
            $source->replaceData([5 => 'five', 'same' => ['source' => true]]);
            $receiver = new GResult();
            $receiver->replaceData(['same' => ['old' => true], 9 => 'nine']);
            self::assertSame($receiver, $receiver->copyDataFrom($source));
            self::assertSame(['same' => ['source' => true], 9 => 'nine', 5 => 'five'], $receiver->getAllData());
            self::assertSame(1, $source->getDataRevision());
            self::assertSame(2, $receiver->getDataRevision());
            $receiver->copyDataFrom($source, true);
            self::assertSame(2, $receiver->getDataRevision());
            self::assertSame(2, $source->getDataRevision());
            self::assertSame([], $source->getAllData());
            $receiver->copyDataFrom($source, true);
            self::assertSame(2, $receiver->getDataRevision());
            self::assertSame(2, $source->getDataRevision());
        }

        public function testDataCopyFailureAndSelfCopyPreserveBothSides(): void
        {
            $source = new GResult();
            $source->setData('value', str_repeat('x', 20));
            $receiver = new GResult(['maxStringBytes' => 10]);
            $receiver->setData('old', 1);
            foreach ([false, true] as $clear) {
                $this->rejects($receiver, static fn () => $receiver->copyDataFrom($source, $clear), source: $source);
            }
            $this->rejects($source, static fn () => $source->copyDataFrom($source, true), LogicException::class);
        }

        public function testSourceRevisionOverflowCannotPartiallyPublishDataCopy(): void
        {
            $source = new GResult();
            $source->setData('new', 1);
            $receiver = new GResult();
            $receiver->setData('old', 2);
            $revision = new ReflectionProperty(GResult::class, 'revision');
            self::assertTrue($revision->isPrivate());
            $revision->setValue($source, PHP_INT_MAX);
            $this->rejects($receiver, static fn () => $receiver->copyDataFrom($source, true), OverflowException::class, $source);
            $revision->setValue($receiver, PHP_INT_MAX);
            $this->rejects($receiver, static fn () => $receiver->setData('different', true), OverflowException::class);
            $receiver->setData('old', 2);
            self::assertSame(PHP_INT_MAX, $receiver->getDataRevision());
        }

        public function testEntryShapeUidOriginOrderAndBusinessStatus(): void
        {
            $result = new GResult(name: 'Origin');
            $message = $result->addMessage('message', ['null' => null]);
            $warning = $result->addWarning(1, 'warning');
            self::assertTrue($result->isSuccess());
            self::assertSame(0, $result->getCode());
            self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $message);
            self::assertSame(['uid' => $message, 'originPath' => 'Origin', 'text' => 'message', 'code' => null, 'context' => ['null' => null]], $result->getEntry($message));
            self::assertSame([$message], array_keys($result->getMessages()));
            self::assertSame([$warning], array_keys($result->getWarnings()));
            $first = $result->addError(7, 'first');
            $second = $result->addError(1, 'second');
            self::assertFalse($result->isSuccess());
            self::assertSame(7, $result->getCode());
            $result->updateEntry($first, ['text' => 'changed', 'code' => 2, 'context' => ['v' => false]]);
            self::assertSame([$first, $second], array_keys($result->getErrors()));
            self::assertSame($first, $result->getEntry($first)['uid']);
            self::assertSame('Origin', $result->getEntry($first)['originPath']);
            self::assertSame(2, $result->getCode());
            self::assertTrue($result->removeError($first));
            self::assertSame(1, $result->getCode());
            self::assertTrue($result->removeError($second));
            self::assertSame(0, $result->getCode());
            self::assertTrue($result->isSuccess());
            self::assertSame(0, $result->getDataRevision());
        }

        public static function invalidCodes(): iterable
        {
            foreach (['addWarning', 'addError'] as $method) {
                foreach ([0, -1, PHP_INT_MIN] as $code) {
                    yield $method . '-' . $code => [$method, $code];
                }
            }
        }

        #[DataProvider('invalidCodes')]
        public function testZeroAndNegativeCodesRejectAddAndUpdateAtomically(string $method, int $code): void
        {
            $result = new GResult();
            $result->setData('keep', true);
            $result->addMessage('message');
            $warning = $result->addWarning(7, 'warning');
            $error = $result->addError(9, 'error');
            $uid = $method === 'addWarning' ? $warning : $error;
            $this->rejects($result, static fn () => $result->{$method}($code, 'invalid'));
            $this->rejects($result, static fn () => $result->updateEntry($uid, ['text' => 'candidate', 'code' => $code]));
            self::assertSame(9, $result->getCode());
            self::assertSame(1, $result->getDataRevision());
            $result->updateEntry($uid, ['code' => 1]);
            self::assertSame(1, $result->getEntry($uid)['code']);
        }

        public function testExceptionsDoNotCreateEntriesAndWarningKeepsSuccess(): void
        {
            $result = new GResult();
            $this->rejects($result, static fn () => $result->addWarning(0, 'bad'));
            self::assertTrue($result->isSuccess());
            self::assertSame(0, $result->getCode());
            $result->addWarning(1, 'accepted');
            self::assertTrue($result->isSuccess());
            self::assertSame(0, $result->getCode());
            self::assertCount(1, $result->getWarnings());
            self::assertSame([], $result->getMessages());
            self::assertSame([], $result->getErrors());
        }

        public static function invalidChanges(): iterable
        {
            foreach (['uid', 'originPath', 'category', 'style', 'deliveries', 'unknown'] as $field) {
                yield $field => [[$field => null]];
            }
            yield 'message code' => [['code' => 1]];
            yield 'text' => [['text' => 1]];
            yield 'context' => [['context' => null]];
        }

        #[DataProvider('invalidChanges')]
        public function testUpdateWhitelistIsAtomic(array $changes): void
        {
            $result = new GResult();
            $uid = $result->addMessage('original');
            $this->rejects($result, static fn () => $result->updateEntry($uid, $changes));
        }

        public function testCodeUpdateRequiresAnInteger(): void
        {
            $result = new GResult();
            foreach ([$result->addWarning(1, 'warning'), $result->addError(1, 'error')] as $uid) {
                foreach (['1', 1.0, true, null, []] as $code) {
                    $this->rejects($result, static fn () => $result->updateEntry($uid, ['code' => $code]));
                }
            }
        }

        public function testUnknownUidWrongCategoryRemovalAndLocalClear(): void
        {
            $result = new GResult();
            $message = $result->addMessage('message');
            $warning = $result->addWarning(1, 'warning');
            $error = $result->addError(1, 'error');
            foreach ([static fn () => $result->getEntry('missing'), static fn () => $result->updateEntry('missing', [])] as $action) {
                try { $action(); self::fail('Unknown UID must throw.'); }
                catch (OutOfBoundsException) { self::assertCount(1, $result->getErrors()); }
            }
            self::assertFalse($result->removeMessage($warning));
            self::assertFalse($result->removeWarning($error));
            self::assertFalse($result->removeError($message));
            self::assertSame($result, $result->clearMessages());
            self::assertFalse($result->removeMessage($message));
            self::assertCount(1, $result->getWarnings());
            self::assertSame($result, $result->clearWarnings());
            self::assertCount(1, $result->getErrors());
            self::assertSame($result, $result->clearErrors());
            self::assertTrue($result->isSuccess());
            self::assertSame(0, $result->getCode());
        }

        public function testAggregateEntryLimitAndFailureAtomicity(): void
        {
            $result = new GResult(['maxEntries' => 2]);
            $message = $result->addMessage('message');
            $result->addWarning(1, 'warning');
            $this->rejects($result, static fn () => $result->addError(1, 'full'));
            $result->removeMessage($message);
            $result->addError(1, 'fits');
            self::assertSame(1, $result->getCode());
            self::assertSame(0, $result->getDataRevision());
        }

        public function testUidCollisionAcrossCollectionsIsAtomic(): void
        {
            FreshEntropy::$bytes = str_repeat('a', 16);
            $result = new GResult();
            $uid = $result->addMessage('first');
            self::assertSame(bin2hex(str_repeat('a', 16)), $uid);
            $this->rejects($result, static fn () => $result->addError(1, 'collision'), LogicException::class);
        }

        public static function copyCollections(): iterable
        {
            foreach ([false, true] as $clear) {
                yield 'messages-' . (int) $clear => ['addMessage', 'getMessages', 'copyMessagesFrom', $clear];
                yield 'warnings-' . (int) $clear => ['addWarning', 'getWarnings', 'copyWarningsFrom', $clear];
                yield 'errors-' . (int) $clear => ['addError', 'getErrors', 'copyErrorsFrom', $clear];
            }
        }

        #[DataProvider('copyCollections')]
        public function testEachCollectionCopyPreservesOrderIdentityOriginAndIsolation(string $add, string $get, string $copy, bool $clear): void
        {
            $source = new GResult(name: 'Source');
            $receiver = new GResult(name: 'Receiver');
            $value = 'original';
            $context = ['v' => &$value];
            $first = $add === 'addMessage' ? $source->addMessage('first', $context) : $source->{$add}(1, 'first', $context);
            $second = $add === 'addMessage' ? $source->addMessage('second') : $source->{$add}(2, 'second');
            $existing = $add === 'addMessage' ? $receiver->addMessage('existing') : $receiver->{$add}(3, 'existing');
            $source->setData('data', 1);
            $receiver->setData('data', 2);
            self::assertSame($receiver, $receiver->{$copy}($source, $clear));
            self::assertSame([$existing, $first, $second], array_keys($receiver->{$get}()));
            self::assertSame('Receiver->Source', $receiver->getEntry($first)['originPath']);
            self::assertSame($first, $receiver->getEntry($first)['uid']);
            self::assertSame('original', $receiver->getEntry($first)['context']['v']);
            $value = 'changed';
            $snapshot = $receiver->{$get}();
            $snapshot[$first]['context']['v'] = 'caller';
            self::assertSame('original', $receiver->getEntry($first)['context']['v']);
            self::assertCount($clear ? 0 : 2, $source->{$get}());
            if (!$clear) {
                $source->updateEntry($first, ['context' => ['v' => 'source changed']]);
                self::assertSame('original', $receiver->getEntry($first)['context']['v']);
            }
            self::assertSame(1, $source->getDataRevision());
            self::assertSame(1, $receiver->getDataRevision());
            if ($add === 'addError') {
                self::assertSame(3, $receiver->getCode());
                self::assertSame($clear ? 0 : 1, $source->getCode());
            }
            $next = new GResult(name: 'Next');
            $next->{$copy}($receiver);
            self::assertSame('Next->Receiver->Source', $next->getEntry($first)['originPath']);
        }

        public function testCollectionCopySelfCollisionAndCapacityRejectBothSides(): void
        {
            $source = new GResult(name: 'S');
            $source->addMessage('source');
            $receiver = new GResult(['maxStringBytes' => 120], 'Receiver');
            foreach ([false, true] as $clear) {
                $this->rejects($receiver, static fn () => $receiver->copyMessagesFrom($source, $clear), source: $source);
            }
            $this->rejects($source, static fn () => $source->copyMessagesFrom($source, true), LogicException::class);
            FreshEntropy::$bytes = str_repeat('b', 16);
            $collisionSource = new GResult();
            $collisionSource->addError(1, 'source');
            $collisionReceiver = new GResult();
            $collisionReceiver->addMessage('receiver');
            $this->rejects($collisionReceiver, static fn () => $collisionReceiver->copyErrorsFrom($collisionSource, true), LogicException::class, $collisionSource);
            FreshEntropy::$bytes = null;
            $full = new GResult(['maxEntries' => 1]);
            $full->addWarning(1, 'full');
            $this->rejects($full, static fn () => $full->copyMessagesFrom($source, true), source: $source);
        }

        public function testFailedSourceCandidateDoesNotPublishTheReceiver(): void
        {
            $source = new GResult();
            $source->addMessage('copy');
            $error = $source->addError(1, 'unselected');
            $property = new ReflectionProperty(GResult::class, 'entries');
            self::assertTrue($property->isPrivate());
            $entries = $property->getValue($source);
            $entries['errors'][$error]['context'] = ['invalid' => new \stdClass()];
            $property->setValue($source, $entries);
            $receiver = new GResult();
            $this->rejects($receiver, static fn () => $receiver->copyMessagesFrom($source, true), source: $source);
        }
    }
}
