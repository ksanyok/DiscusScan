<?php
declare(strict_types=1);

/**
 * DomainRules — декларативные переопределения для домена.
 * По умолчанию возвращает пустой массив (универсальный режим эвристик Playwright).
 */
final class DomainRules
{
    /** @return array<string,mixed> */
    public static function forHost(string $host): array
    {
        static $map = [
            // 'example.com' => [
            //     'loginFormSelector' => 'form#login',
            //     'replyFormSelector' => 'form.reply',
            //     'titleSelector' => 'h1.thread-title',
            //     'postTextareaSelector' => 'textarea[name=message]',
            //     'submitSelector' => 'button[type=submit]'
            // ]
        ];
        $h = strtolower($host);
        return $map[$h] ?? [];
    }
}
