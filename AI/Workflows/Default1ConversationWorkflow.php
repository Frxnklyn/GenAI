<?php
namespace axenox\GenAI\AI\Workflows;

use axenox\GenAI\Common\AbstractAiWorkflow;
use axenox\GenAI\Exceptions\AiWorkflowNotFoundError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;

/**
 * Runs one configured agent once and returns its response unchanged.
 *
 * Use this workflow to make the existing single-agent chat flow explicit. Set `agent_alias` to the
 * same versioned agent alias that would otherwise be passed directly to AiChatFacade.
 *
 * ## Example
 *
 * ```
 * {
 *   "agent_alias": "axenox.GenAI.workbench_ai_assistant:1.0"
 * }
 * 
 * ```
 *
 * ```mermaid
 * flowchart LR
 *     Prompt[AI prompt] --> Agent[Configured agent]
 *     Agent --> Response[AI response]
 * 
 * ```
 */
class Default1ConversationWorkflow extends AbstractAiWorkflow
{
    private ?string $agentAlias = null;

    private ?AiAgentInterface $agent = null;

    /**
     * Delegates the prompt to the configured agent exactly once.
     */
    public function handle(AiPromptInterface $prompt) : AiResponseInterface
    {
        return $this->getAgent()->handle($prompt);
    }

    /**
     * Describes the single-agent compatibility workflow.
     */
    public function getDescription() : string
    {
        return 'Passes the prompt to the selected agent and returns its response unchanged.';
    }

    /**
     * Returns the static single-agent flow as Mermaid syntax.
     */
    public function getMermaidDiagram() : string
    {
        return $this->appendMermaidLegend("flowchart LR\n"
            . "    Prompt[AI prompt] --> Agent[Configured agent]\n"
            . "    Agent --> Response[AI response]");
    }

    /**
     * Selects the agent that handles the conversation.
     *
     * @uxon-property agent_alias
     * @uxon-type metamodel:axenox.GenAI.AI_AGENT:ALIAS_WITH_NS
     * @uxon-required true
     */
    protected function setAgentAlias(string $agentAlias) : Default1ConversationWorkflow
    {
        $this->agentAlias = trim($agentAlias);
        $this->agent = null;
        return $this;
    }

    /**
     * Returns the configured agent, loading it only when the workflow is executed.
     */
    protected function getAgent() : AiAgentInterface
    {
        if ($this->agent !== null) {
            return $this->agent;
        }
        if ($this->agentAlias === null || $this->agentAlias === '') {
            throw new AiWorkflowNotFoundError(
                'AI workflow "' . $this->getAliasWithNamespace() . '" requires the UXON property "agent_alias"'
            );
        }
        $this->agent = AiFactory::createAgentFromString($this->getWorkbench(), $this->agentAlias);
        return $this->agent;
    }
}
