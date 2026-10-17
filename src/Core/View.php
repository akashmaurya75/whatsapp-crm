<?php

declare(strict_types=1);

namespace App\Core;

/**
 * PHP template renderer. Templates must escape every dynamic value with e().
 * Layout: a view may call $this->layout('layouts/app', [...]) to be wrapped.
 */
final class View
{
    private ?string $layoutName = null;
    private array $layoutData = [];
    /** @var array<string,string> */
    private array $sections = [];

    public function __construct(private string $dir, private array $shared = [])
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function render(string $name, array $data = []): string
    {
        $content = $this->renderFile($name, array_merge($this->shared, $data));
        while ($this->layoutName !== null) {
            $layout = $this->layoutName;
            $layoutData = array_merge($this->shared, $data, $this->layoutData, ['content' => $content]);
            $this->layoutName = null;
            $content = $this->renderFile($layout, $layoutData);
        }
        return $content;
    }

    public function layout(string $name, array $data = []): void
    {
        $this->layoutName = $name;
        $this->layoutData = $data;
    }

    public function partial(string $name, array $data = []): string
    {
        return $this->renderFile($name, array_merge($this->shared, $data));
    }

    private function renderFile(string $name, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/\-]+$#', $name)) {
            throw new \InvalidArgumentException('Invalid view name');
        }
        $file = $this->dir . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $name");
        }
        $render = function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        };
        return $render($file, $data);
    }
}
