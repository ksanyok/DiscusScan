<?php
declare(strict_types=1);

/**
 * AgentProvider — стратегия принятия решений и генерации ответа.
 * TODO: позже добавить реализацию через OpenAI/LLM.
 */
interface AgentProvider
{
    /** Decide next action for a thread (login, post, verify, skip, manual, cooldown) */
    public function decide(array $thread): string; // returns action keyword

    /** Compose reply content for a thread+account */
    public function composeReply(array $thread, array $account): string;
}
