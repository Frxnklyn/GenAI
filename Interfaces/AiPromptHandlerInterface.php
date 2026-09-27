<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\WorkbenchDependantInterface;

/**
 * Handles an AI prompt and returns one final response.
 *
 * This common contract allows callers such as AiChatFacade to invoke an agent or an orchestration
 * workflow without knowing which implementation performs the work.
 */
interface AiPromptHandlerInterface extends WorkbenchDependantInterface
{
    /**
     * Processes the prompt and returns the final response for the caller.
     *
     * @param AiPromptInterface $prompt Prompt and request context to process.
     * @return AiResponseInterface Final response returned to the caller.
     */
    public function handle(AiPromptInterface $prompt) : AiResponseInterface;
}
