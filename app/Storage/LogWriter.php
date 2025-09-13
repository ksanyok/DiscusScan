<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';

/**
 * LogWriter — JSON Lines ежедневные логи публикаций.
 */
final class LogWriter
{
    private string $dir;
    private int $retentionDays;

    public function __construct(?string $dir=null, ?int $retentionDays=null)
    {
        $this->dir = $dir ?? (__DIR__.'/../../storage/logs');
        if(!is_dir($this->dir)) @mkdir($this->dir,0775,true);
        $this->retentionDays = $retentionDays ?? (int)(get_setting('pub_logs_retention_days',14));
    }

    public function filePathForDate(?string $date=null): string
    {
        $d = $date ?: date('Y-m-d');
        return $this->dir.'/publish-'.$d.'.jsonl';
    }

    /**
     * Запись шага.
     * @param string|null $host
     * @param int|null $threadId
     * @param int|null $accountId
     */
    public function step(?string $host, ?int $threadId, ?int $accountId, string $action, string $status, string $msg, array $extra=[]): void
    {
        $row = [
            'ts' => date('c'),
            'host' => $host,
            'thread' => $threadId,
            'account' => $accountId,
            'action' => $action,
            'status' => $status,
            'msg' => $msg,
        ] + $extra;
        $line = json_encode($row, JSON_UNESCAPED_UNICODE);
        file_put_contents($this->filePathForDate(), $line.PHP_EOL, FILE_APPEND);
    }

    /** Удаляет файлы старше retention */
    public function rotate(): void
    {
        $files = glob($this->dir.'/publish-*.jsonl');
        if(!$files) return;
        $threshold = time() - $this->retentionDays*86400;
        foreach($files as $f){
            if(preg_match('~publish-(\d{4}-\d{2}-\d{2})\.jsonl$~',$f,$m)){
                $ts = strtotime($m[1]);
                if($ts !== false && $ts < $threshold){ @unlink($f); }
            }
        }
    }
}
