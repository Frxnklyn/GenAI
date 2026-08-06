<?php
namespace axenox\GenAI\Interfaces;

/**
 * A tool result that signals that the user must confirm before the action is executed.
 *
 * When a tool returns this result, the agent pauses the LLM conversation, presents
 * the confirmation question to the user as a chat status message with Yes/No buttons
 * and waits for the next user turn. If the user confirms, the tool is re-invoked with
 * the `__confirmed` flag set to `true`; if the user cancels, the pending action is
 * discarded.
 *
 * @see AiToolResultInterface
 */
interface AiToolConfirmationResultInterface extends AiToolResultInterface
{
    /**
     * Returns the question displayed to the user before the action is executed.
     *
     * @return string
     */
    public function getConfirmationQuestion(): string;
}
