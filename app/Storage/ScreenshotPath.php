<?php
declare(strict_types=1);

final class ScreenshotPath
{
    public static function build(string $host, int $threadId, string $step): string
    {
        $date = date('Ymd');
        $base = __DIR__ . '/../../storage/screens/' . $date . '/' . $host;
        if(!is_dir($base)) @mkdir($base, 0775, true);
        $safeStep = preg_replace('~[^a-z0-9_\-]~i','_', $step);
        return $base . '/' . $threadId . '_' . $safeStep . '.png';
    }
}
