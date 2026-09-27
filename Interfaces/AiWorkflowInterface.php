<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\AliasInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;

/**
 * Coordinates how one or more AI agents process a prompt.
 *
 * A workflow owns orchestration only. Agents remain responsible for preparing model requests,
 * invoking tools and persisting their conversations. Implementations may delegate to one or more
 * agents, let agents interact, execute other steps or combine several responses, but always return
 * one AiResponseInterface to the caller.
 *
 * Every workflow also exposes a human-readable description and a Mermaid flowchart. These make the
 * configured orchestration inspectable in documentation and Power UI without executing the workflow.
 */
interface AiWorkflowInterface extends AiPromptHandlerInterface, iCanBeConvertedToUxon, AliasInterface
{
    /**
     * Returns a concise description of the workflow's orchestration behavior.
     */
    public function getDescription() : string;

    /**
     * Returns the workflow structure as a Mermaid flowchart without Markdown fences.
     */
    public function getMermaidDiagram() : string;
}
