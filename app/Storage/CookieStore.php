<?php
declare(strict_types=1);

final class CookieStore
{
    private string $dir;

    public function __construct(?string $dir=null)
    {
        $this->dir = $dir ?? (__DIR__.'/../../storage/cookies');
        if(!is_dir($this->dir)) @mkdir($this->dir,0775,true);
    }

    public function pathFor(string $host, string $accountLogin): string
    {
        $safe = preg_replace('~[^a-z0-9_\-]~i','_', $host.'_'.$accountLogin);
        return $this->dir.'/'.$safe.'.json';
    }

    /** @param array<int,array<string,mixed>> $cookies */
    public function save(string $host, string $login, array $cookies): string
    {
        $path = $this->pathFor($host,$login);
        file_put_contents($path, json_encode($cookies, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        return $path;
    }

    /** @return array<int,array<string,mixed>> */
    public function load(string $host, string $login): array
    {
        $p = $this->pathFor($host,$login);
        if(!is_file($p)) return [];
        $data = json_decode((string)file_get_contents($p), true);
        return is_array($data)? $data : [];
    }
}
