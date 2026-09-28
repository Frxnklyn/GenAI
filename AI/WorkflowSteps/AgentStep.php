<?php
namespace axenox\GenAI\AI\WorkflowSteps;

use axenox\GenAI\Common\Workflows\AbstractAiWorkflowStep;
use axenox\GenAI\Common\Workflows\AiResponseDataMapper;
use axenox\GenAI\Common\Workflows\AiWorkflowStepResult;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepResultInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\DataRowPlaceholders;

/**
 * Invokes one configured AI agent with mapped workflow data.
 */
class AgentStep extends AbstractAiWorkflowStep
{
    private string $agentAlias = '';

    private string $connectionAlias = '';

    private ?string $promptTemplate = null;

    private ?UxonObject $responseMapperUxon = null;

    /** {@inheritDoc} */
    public function getType() : string
    {
        return 'agent';
    }

    /**
     * Returns the configured agent selector for previews and diagnostics.
     */
    public function getAgentAlias() : string
    {
        return $this->agentAlias;
    }

    /** {@inheritDoc} */
    public function execute(AiWorkflowExecutionContextInterface $context) : AiWorkflowStepResultInterface
    {
        $data = $this->mapInput($context->getData());
        $prompt = $context->getPrompt()->copy()->setInputData($data);
        if (! $prompt instanceof AiPromptInterface) {
            throw new \LogicException('Copied AI prompt must implement ' . AiPromptInterface::class);
        }
        if ($this->promptTemplate !== null) {
            $renderer = new BracketHashStringTemplateRenderer($this->getWorkbench());
            if (! $data->isEmpty()) {
                $renderer->addPlaceholder(new DataRowPlaceholders($data, 0, '~input:'));
            }
            $prompt->setPrompt($renderer->render($this->promptTemplate));
        }

        $response = AiFactory::createAgentFromString(
            $this->getWorkbench(),
            $this->agentAlias,
            $this->connectionAlias === '' ? null : $this->connectionAlias
        )->handle($prompt);
        $context->setResponse($this->getId(), $response);
        if ($this->responseMapperUxon !== null) {
            (new AiResponseDataMapper($this->getWorkbench(), $this->responseMapperUxon->copy()))
                ->map($response, $context);
        }

        return new AiWorkflowStepResult('success', $context->getData(), $response);
    }

    /**
     * @uxon-property agent_alias
     * @uxon-type metamodel:axenox.GenAI.AI_AGENT:ALIAS_WITH_NS
     * @uxon-required true
     */
    protected function setAgentAlias(string $agentAlias) : AgentStep
    {
        $this->agentAlias = trim($agentAlias);
        return $this;
    }

    /**
     * Overrides this invocation's connection without changing the persisted agent.
     *
     * @uxon-property connection_alias
     * @uxon-type metamodel:exface.Core.CONNECTION:ALIAS_WITH_NS
     */
    protected function setConnectionAlias(string $connectionAlias) : AgentStep
    {
        $this->connectionAlias = trim($connectionAlias);
        return $this;
    }

    /**
     * @uxon-property prompt_template
     * @uxon-type string
     */
    protected function setPromptTemplate(string $template) : AgentStep
    {
        $this->promptTemplate = $template;
        return $this;
    }

    /**
     * @uxon-property response_mapper
     * @uxon-type \axenox\GenAI\Common\Workflows\AiResponseDataMapper
     */
    protected function setResponseMapper(UxonObject $mapper) : AgentStep
    {
        $this->responseMapperUxon = $mapper->copy();
        return $this;
    }
}