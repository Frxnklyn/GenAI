<?php
namespace axenox\GenAI\AI\WorkflowSteps;

use axenox\GenAI\Common\Workflows\AbstractAiWorkflowStep;
use axenox\GenAI\Common\Workflows\AiResponseDataMapper;
use axenox\GenAI\Common\Workflows\AiWorkflowStepResult;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiGraphWorkflowInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepResultInterface;
use exface\Core\CommonLogic\UxonObject;

/**
 * Invokes one persisted AI workflow as a graph step.
 */
class SubworkflowStep extends AbstractAiWorkflowStep
{
    private string $workflowAlias = '';

    private ?UxonObject $responseMapperUxon = null;

    /** {@inheritDoc} */
    public function getType() : string
    {
        return 'workflow';
    }

    /**
     * Returns the configured persisted workflow selector.
     */
    public function getWorkflowAlias() : string
    {
        return $this->workflowAlias;
    }

    /** {@inheritDoc} */
    public function execute(AiWorkflowExecutionContextInterface $context) : AiWorkflowStepResultInterface
    {
        $workflow = AiFactory::createWorkflowFromModelAlias($this->getWorkbench(), $this->workflowAlias);
        if ($workflow instanceof AiGraphWorkflowInterface) {
            if ($this->hasInputMapper()) {
                $context->setData($this->mapInput($context->getData()));
            }
            $response = $workflow->executeInContext($context);
        } else {
            $prompt = $context->getPrompt()->copy()->setInputData($this->mapInput($context->getData()));
            if (! $prompt instanceof AiPromptInterface) {
                throw new \LogicException('Copied AI prompt must implement ' . AiPromptInterface::class);
            }
            $response = $workflow->handle($prompt);
        }
        $context->setResponse($this->getId(), $response);
        if ($this->responseMapperUxon !== null) {
            (new AiResponseDataMapper($this->getWorkbench(), $this->responseMapperUxon->copy()))
                ->map($response, $context);
        }
        return new AiWorkflowStepResult('success', $context->getData(), $response);
    }

    /**
     * @uxon-property workflow_alias
     * @uxon-type metamodel:axenox.GenAI.AI_WORKFLOW:ALIAS_WITH_NS
     * @uxon-required true
     */
    protected function setWorkflowAlias(string $workflowAlias) : SubworkflowStep
    {
        $this->workflowAlias = trim($workflowAlias);
        return $this;
    }

    /**
     * @uxon-property response_mapper
     * @uxon-type \axenox\GenAI\Common\Workflows\AiResponseDataMapper
     */
    protected function setResponseMapper(UxonObject $mapper) : SubworkflowStep
    {
        $this->responseMapperUxon = $mapper->copy();
        return $this;
    }
}