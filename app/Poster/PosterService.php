<?php
declare(strict_types=1);

require_once __DIR__ . '/../Storage/LogWriter.php';
require_once __DIR__ . '/../Storage/ScreenshotPath.php';
require_once __DIR__ . '/../Storage/CookieStore.php';
require_once __DIR__ . '/../Agent/AgentProvider.php';
require_once __DIR__ . '/../Agent/NullAgent.php';
require_once __DIR__ . '/DomainRules.php';
require_once __DIR__ . '/../../db.php';

final class PosterService
{
    private LogWriter $log;
    private AgentProvider $agent;

    public function __construct(?AgentProvider $agent=null)
    {
        $this->log = new LogWriter();
        $this->agent = $agent ?? new NullAgent();
    }

    /** Try auto login or registration. Returns associative Result */
    public function loginOrRegister(array $forum, array $account): array
    {
        $host = $forum['host'] ?? '';
        $this->log->step($host, null, $account['id'] ?? null, 'login_attempt', 'info', 'Start auto login');
        $rules = DomainRules::forHost($host);
        // TODO: integrate Playwright automation (headless) using rules
        $ok = true; // stub always success
        if ($ok) {
            pdo()->prepare('UPDATE accounts SET last_login_at=NOW() WHERE id=?')->execute([$account['id']]);
            $this->log->step($host, null, $account['id'] ?? null, 'login_success', 'ok', 'Auto login stub success');
            return ['ok'=>true,'mode'=>'auto'];
        }
        return ['ok'=>false,'needs_manual'=>true];
    }

    /** Compose content via agent provider (stub) */
    public function compose(array $thread, array $account): string
    {
        return $this->agent->composeReply($thread, $account);
    }

    /** Generic posting (stub) */
    public function post(array $thread, array $account, string $content): array
    {
        $host = $thread['host'] ?? '';
        $this->log->step($host, $thread['id'] ?? null, $account['id'] ?? null, 'post_begin', 'info', 'Posting started');
        // Insert draft post
        pdo()->prepare('INSERT INTO posts(thread_id,account_id,content,status,created_at) VALUES(?,?,?,?,NOW())')
            ->execute([$thread['id'],$account['id'],$content,'sent']);
        pdo()->prepare('UPDATE threads SET status="posting", last_attempt_at=NOW() WHERE id=?')->execute([$thread['id']]);
        // TODO: real submission via Playwright; capture screenshot
        $this->log->step($host, $thread['id'] ?? null, $account['id'] ?? null, 'post_submit', 'ok', 'Submitted stub');
        pdo()->prepare('UPDATE threads SET status="posted" WHERE id=?')->execute([$thread['id']]);
        return ['ok'=>true,'status'=>'posted'];
    }

    /** Verify thread after posting (stub) */
    public function verify(array $thread): array
    {
        $host = $thread['host'] ?? '';
        $this->log->step($host, $thread['id'] ?? null, null, 'verify', 'info', 'Verification stub');
        // TODO: Load page via headless, extract permalink
        return ['ok'=>true];
    }

    /** Mark thread to manual flow */
    public function fallbackToManual(array $thread): void
    {
        pdo()->prepare('UPDATE threads SET status="needs_manual" WHERE id=?')->execute([$thread['id']]);
        $this->log->step($thread['host'] ?? '', $thread['id'] ?? null, null, 'fallback_manual', 'warn', 'Manual intervention required');
        // Real implementation: request browser-service session
    }
}
