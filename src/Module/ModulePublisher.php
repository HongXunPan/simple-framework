<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module;

use HongXunPan\Framework\Filesystem\AtomicFile;
use HongXunPan\Framework\Module\Exception\ModuleException;
use RuntimeException;
use Throwable;

final readonly class ModulePublisher
{
    private string $projectPath;

    public function __construct(string $projectPath)
    {
        $realProjectPath = realpath($projectPath);
        if ($realProjectPath === false || !is_dir($realProjectPath)) {
            throw new ModuleException("项目目录不存在：{$projectPath}");
        }

        $this->projectPath = rtrim($realProjectPath, DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<string>
     */
    public function publish(ModulePackage $package, string $name, bool $dryRun = false): array
    {
        $resource = $this->resource($package, $name);
        $source = $this->sourcePath($package, $resource['source']);
        $target = $this->targetPath($resource['target']);
        $content = file_get_contents($source);
        if ($content === false) {
            throw new ModuleException("Module 资源读取失败：{$source}");
        }

        if ($this->sameContent($target, $content)) {
            return [];
        }
        if (is_link($target) || file_exists($target)) {
            throw new ModuleException("发布目标已存在且内容不同：{$resource['target']}");
        }
        if ($dryRun) {
            return ["创建 {$resource['target']}"];
        }

        $createdDirectories = $this->createDirectories(dirname($target));
        try {
            if (!$this->createFile($target, $content)) {
                return [];
            }
        } catch (Throwable $throwable) {
            $this->removeEmptyDirectories($createdDirectories);
            throw $throwable;
        }

        return ["创建 {$resource['target']}"];
    }

    /**
     * @return array{source: string, target: string}
     */
    private function resource(ModulePackage $package, string $name): array
    {
        $file = $package->path . DIRECTORY_SEPARATOR . 'config/resources.php';
        if (!is_file($file)) {
            throw new ModuleException("Module 未声明可发布资源：{$package->module->name()}");
        }
        $realFile = realpath($file);
        if ($realFile === false || !$this->pathIsInside($realFile, $package->path)) {
            throw new ModuleException("Module 资源声明文件越界：{$file}");
        }

        $resources = require $realFile;
        if (!is_array($resources)) {
            throw new ModuleException("Module 资源声明必须返回数组：{$realFile}");
        }

        $validated = [];
        foreach ($resources as $resourceName => $resource) {
            if (!is_string($resourceName)
                || preg_match('/^[a-z][a-z0-9-]*$/', $resourceName) !== 1) {
                throw new ModuleException("Module 资源名称不合法：{$realFile}");
            }
            if (!is_array($resource)
                || !array_key_exists('source', $resource)
                || !array_key_exists('target', $resource)
                || array_diff(array_keys($resource), ['source', 'target']) !== []
                || !is_string($resource['source'])
                || $resource['source'] === ''
                || !is_string($resource['target'])
                || $resource['target'] === '') {
                throw new ModuleException("Module 资源声明格式错误：{$resourceName}");
            }
            $validated[$resourceName] = [
                'source' => $resource['source'],
                'target' => $resource['target'],
            ];
        }

        if (!isset($validated[$name])) {
            throw new ModuleException(
                "Module 未声明资源：{$package->module->name()}/{$name}",
            );
        }

        return $validated[$name];
    }

    private function sourcePath(ModulePackage $package, string $source): string
    {
        $realSource = realpath($source);
        if ($realSource === false || !is_file($realSource)) {
            throw new ModuleException("Module 资源源文件不存在：{$source}");
        }
        if (!$this->pathIsInside($realSource, $package->path)) {
            throw new ModuleException("Module 资源源文件越界：{$source}");
        }

        return $realSource;
    }

    private function targetPath(string $target): string
    {
        $normalized = str_replace('\\', '/', $target);
        if (preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            throw new ModuleException("Module 资源目标必须使用项目内相对路径：{$target}");
        }

        $segments = explode('/', $normalized);
        if ($segments === []
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)) {
            throw new ModuleException("Module 资源目标路径不合法：{$target}");
        }

        $current = $this->projectPath;
        foreach (array_slice($segments, 0, -1) as $segment) {
            $candidate = $current . DIRECTORY_SEPARATOR . $segment;
            if (is_link($candidate)) {
                $realCandidate = realpath($candidate);
                if ($realCandidate === false
                    || !is_dir($realCandidate)
                    || !$this->pathIsInside($realCandidate, $this->projectPath)) {
                    throw new ModuleException("Module 资源目标目录越界：{$target}");
                }
                $current = $realCandidate;
                continue;
            }
            if (file_exists($candidate) && !is_dir($candidate)) {
                throw new ModuleException("Module 资源目标目录不可用：{$target}");
            }
            $current = $candidate;
        }

        return $current . DIRECTORY_SEPARATOR . end($segments);
    }

    private function sameContent(string $target, string $content): bool
    {
        if (is_link($target) || !is_file($target)) {
            return false;
        }

        $existing = file_get_contents($target);
        if ($existing === false) {
            throw new ModuleException("发布目标读取失败：{$target}");
        }

        return hash_equals($content, $existing);
    }

    /**
     * @return list<string>
     */
    private function createDirectories(string $directory): array
    {
        $missing = [];
        $current = $directory;
        while (!is_dir($current)) {
            if (is_link($current) || file_exists($current)) {
                throw new ModuleException("发布目标目录创建失败：{$current}");
            }
            $missing[] = $current;
            $parent = dirname($current);
            if ($parent === $current || !$this->pathIsInside($parent, $this->projectPath)) {
                throw new ModuleException("发布目标目录越界：{$directory}");
            }
            $current = $parent;
        }

        $created = [];
        foreach (array_reverse($missing) as $path) {
            if (!mkdir($path, 0755) && !is_dir($path)) {
                $this->removeEmptyDirectories($created);
                throw new ModuleException("发布目标目录创建失败：{$path}");
            }
            $created[] = $path;
        }

        return $created;
    }

    private function createFile(string $target, string $content): bool
    {
        try {
            $created = AtomicFile::create($target, $content, 0644);
        } catch (RuntimeException $exception) {
            throw new ModuleException(
                'Module 资源原子创建失败：' . $exception->getMessage(),
                0,
                $exception,
            );
        }
        if ($created) {
            return true;
        }
        if ($this->sameContent($target, $content)) {
            return false;
        }
        if (!is_link($target) && !file_exists($target)) {
            throw new ModuleException("Module 资源原子创建失败：{$target}");
        }

        throw new ModuleException("发布目标已存在且内容不同：{$target}");
    }

    /**
     * @param list<string> $directories
     */
    private function removeEmptyDirectories(array $directories): void
    {
        foreach (array_reverse($directories) as $directory) {
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    private function pathIsInside(string $path, string $parent): bool
    {
        $parent = rtrim($parent, DIRECTORY_SEPARATOR);

        return $path === $parent
            || str_starts_with($path, $parent . DIRECTORY_SEPARATOR);
    }
}
