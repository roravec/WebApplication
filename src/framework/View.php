<?php
namespace WebApplication;

/**
 * Plain-PHP templates with escaping.
 *
 *   $view = new View(__DIR__ . '/views');
 *   return $view->render('invoice', ['invoice' => $inv], 'layout');
 *
 * Inside a template: <?= $e($invoice->number) ?> escapes; $content holds the inner page in a layout.
 * Always escape data that comes from users or the database.
 */
class View
{
    /** @var string */
    private $dir;
    /** @var array */
    private $shared = [];

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    /** Variables available in every template (e.g. 'app', 'user'). */
    public function share(string $name, $value): self
    {
        $this->shared[$name] = $value;
        return $this;
    }

    public function render(string $template, array $vars = [], ?string $layout = null): string
    {
        $html = $this->renderFile($template, $vars);
        if ($layout !== null) {
            $html = $this->renderFile($layout, array_merge($vars, ['content' => $html]));
        }
        return $html;
    }

    public static function escape($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function renderFile(string $template, array $vars): string
    {
        if (!preg_match('/^[A-Za-z0-9_\/-]+$/', $template)) {
            throw new \InvalidArgumentException('Invalid template name: ' . $template);
        }
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Template not found: ' . $file);
        }
        $vars = array_merge($this->shared, $vars);
        $vars['e'] = [self::class, 'escape'];
        $vars['view'] = $this;
        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $ex) {
                ob_end_clean();
                throw $ex;
            }
            return (string)ob_get_clean();
        })($file, $vars);
    }

    /** Render a partial from inside a template: <?= $view->partial('row', ['item' => $i]) ?> */
    public function partial(string $template, array $vars = []): string
    {
        return $this->renderFile($template, $vars);
    }
}
