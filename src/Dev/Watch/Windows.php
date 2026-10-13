<?php

declare(strict_types=1);

namespace Hanji\Dev\Watch;

final class Windows implements Backend
{
    private readonly Native $api;
    private Scope $scope;
    /** @var array<string, WindowsDirectory> */
    private array $directories = [];

    public function __construct(Scope $scope)
    {
        $calling = PHP_INT_SIZE === 4 ? '__stdcall ' : '';
        $this->api = new Native(WindowsDirectory::TYPES . <<<C
            HANDLE {$calling}CreateFileW(const WCHAR *, DWORD, DWORD, void *, DWORD, DWORD, HANDLE);
            HANDLE {$calling}CreateEventW(void *, BOOL, BOOL, const WCHAR *);
            BOOL {$calling}ReadDirectoryChangesW(HANDLE, void *, DWORD, BOOL, DWORD, DWORD *, OVERLAPPED *, void *);
            BOOL {$calling}GetOverlappedResult(HANDLE, OVERLAPPED *, DWORD *, BOOL);
            BOOL {$calling}CancelIoEx(HANDLE, OVERLAPPED *);
            BOOL {$calling}CloseHandle(HANDLE);
            BOOL {$calling}ResetEvent(HANDLE);
            DWORD {$calling}WaitForSingleObject(HANDLE, DWORD);
            DWORD {$calling}GetLastError(void);
            C, 'kernel32.dll');
        try {
            $this->configure($scope);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function configure(Scope $scope): void
    {
        $this->scope = $scope;
        $wanted = [];
        foreach ($scope->roots() as $root) {
            $wanted[$root . '|recursive'] = [$root, true];
            $parent = str_replace('\\', '/', dirname($root));
            if ($parent !== $root && is_dir($parent)) {
                $wanted[$parent . '|parent'] = [$parent, false];
            }
        }
        foreach ($this->directories as $key => $directory) {
            if (!isset($wanted[$key])) {
                $directory->close();
                unset($this->directories[$key]);
            }
        }
        foreach ($wanted as $key => [$root, $recursive]) {
            if (!isset($this->directories[$key])) {
                $this->directories[$key] = new WindowsDirectory($this->api, $root, $recursive);
            }
        }
    }

    public function events(): array
    {
        $paths = [];
        $refresh = false;
        foreach ($this->directories as $key => $directory) {
            $paths = [...$paths, ...$directory->events()];
            if (!$directory->active()) {
                $directory->close();
                unset($this->directories[$key]);
                $refresh = true;
            }
        }
        if ($refresh) {
            $this->configure($this->scope);
        }
        return array_values(array_unique($paths));
    }

    public function name(): string
    {
        return 'ReadDirectoryChangesW · native';
    }

    public function close(): void
    {
        foreach ($this->directories as $directory) {
            $directory->close();
        }
        $this->directories = [];
    }

    public function __destruct()
    {
        $this->close();
    }
}
