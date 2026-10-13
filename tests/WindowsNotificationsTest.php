<?php

declare(strict_types=1);

namespace Hanji\Tests;

use Hanji\BuildException;
use Hanji\Dev\Watch\Native;
use Hanji\Dev\Watch\WindowsDirectory;
use Hanji\Dev\Watch\WindowsNotifications;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WindowsNotificationsTest extends TestCase
{
    public function testNotificationsDecodeUnicodeNestedPathsAndBothRenameEntries(): void
    {
        $first = mb_convert_encoding('글\\한지.php', 'UTF-16LE', 'UTF-8');
        $second = mb_convert_encoding('글\\한지2.php', 'UTF-16LE', 'UTF-8');
        $offset = 12 + strlen($first);
        $offset += (4 - $offset % 4) % 4;
        $data = pack('VVV', $offset, 4, strlen($first)) . str_pad($first, $offset - 12, "\0");
        $data .= pack('VVV', 0, 5, strlen($second)) . $second;
        self::assertSame(['C:/site/글/한지.php', 'C:/site/글/한지2.php'], WindowsNotifications::paths('C:\\site', $data));
        self::assertSame([], WindowsNotifications::paths('C:/site', ''));
    }

    public function testLongDriveAndUncPathsUseTheExtendedWindowsNamespace(): void
    {
        self::assertSame('\\\\?\\C:\\한지\\site', WindowsNotifications::nativePath('C:/한지/site'));
        self::assertSame('\\\\?\\UNC\\server\\share\\site', WindowsNotifications::nativePath('//server/share/site'));
        self::assertSame('\\\\?\\C:\\site', WindowsNotifications::nativePath('\\\\?\\C:\\site'));
        self::assertSame('\\\\?\\C:\\' . str_repeat('a', 300), WindowsNotifications::nativePath('C:/' . str_repeat('a', 300)));
    }

    public function testOverlappedStructureUsesPointerSizedFieldsAndAlignedBuffers(): void
    {
        if (!class_exists(\FFI::class) || in_array(ini_get('ffi.enable'), ['', '0', false], true)) {
            self::markTestSkipped('PHP FFI is required to verify native layouts.');
        }
        $api = new Native(WindowsDirectory::TYPES);
        $overlapped = $api->allocate('OVERLAPPED');
        self::assertSame(PHP_INT_SIZE === 8 ? 32 : 20, \FFI::sizeof($overlapped));
        self::assertSame(65536, \FFI::sizeof($api->allocate('DWORD[16384]')));
    }

    public function testOpaqueHandleValuesAreReadWithoutDereferencingThem(): void
    {
        if (!class_exists(\FFI::class) || in_array(ini_get('ffi.enable'), ['', '0', false], true)) {
            self::markTestSkipped('PHP FFI is required to verify native handles.');
        }
        $api = new Native(WindowsDirectory::TYPES);
        $handle = $api->allocate('HANDLE');
        self::assertSame(0, $api->pointerValue($handle));
        \FFI::memset(\FFI::addr($handle), 0xff, PHP_INT_SIZE);
        self::assertSame(-1, $api->pointerValue($handle));
    }

    #[DataProvider('invalidNotifications')]
    public function testMalformedNotificationsAreRejected(string $data): void
    {
        $this->expectException(BuildException::class);
        WindowsNotifications::paths('C:/site', $data);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNotifications(): iterable
    {
        yield 'short header' => ["\0"];
        yield 'short name' => [pack('VVV', 0, 1, 20) . "a\0"];
        yield 'odd byte length' => [pack('VVV', 0, 1, 1) . 'a'];
        yield 'unknown action' => [pack('VVV', 0, 6, 2) . "a\0"];
        yield 'overlapping entry' => [pack('VVV', 4, 1, 2) . "a\0"];
        yield 'missing next entry' => [pack('VVV', 20, 1, 2) . "a\0"];
        $path = mb_convert_encoding('..\\private.php', 'UTF-16LE', 'UTF-8');
        yield 'relative escape' => [pack('VVV', 0, 1, strlen($path)) . $path];
    }
}
