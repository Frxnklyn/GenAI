<?php
namespace axenox\GenAI\Common\Workflows;

use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Request-local state for one graph workflow execution.
 */
class AiWorkflowExecutionContext implements AiWorkflowExecutionContextInterface
{
    private const MAX_WORKFLOW_DEPTH = 10;

    private AiPromptInterface $prompt;

    private DataSheetInterface $data;

    /** @var AiResponseInterface[] */
    private array $responses = [];

    /** @var array<string, mixed> */
    private array $variables = [];

    private int $stepsConsumed = 0;

    private int $maxSteps;

    private WorkflowRunLog $runLog;

    private ?string $parentStepUid = null;

    private ?string $currentStepUid = null;

    /** @var string[] */
    private array $workflowStack = [];

    /**
     * Creates an isolated context from the prompt's structured input.
     */
    public function __construct(AiPromptInterface $prompt, int $maxSteps, WorkflowRunLog $runLog)
    {
        if (! $prompt->hasInputData()) {
            throw new InvalidArgumentException('Graph workflows require structured input data');
        }
        if ($maxSteps < 1) {
            throw new InvalidArgumentException('Graph workflow max_steps must be greater than zero');
        }

        $this->prompt = $prompt;
        $this->data = $prompt->getInputData();
        $this->maxSteps = $maxSteps;
        $this->runLog = $runLog;
    }

    /**
     * {@inheritDoc}
     */
    public function getPrompt() : AiPromptInterface
    {
        return $this->prompt;
    }

    /**
     * {@inheritDoc}
     */
    public function getData() : DataSheetInterface
    {
        return $this->data;
    }

    /**
     * {@inheritDoc}
     */
    public function setData(DataSheetInterface $data) : AiWorkflowExecutionContextInterface
    {
        $this->data = $data;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setResponse(string $stepId, AiResponseInterface $response) : AiWorkflowExecutionContextInterface
    {
        $this->responses[$stepId] = $response;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getResponse(string $stepId) : ?AiResponseInterface
    {
        return $this->responses[$stepId] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function setVariable(string $name, $value) : AiWorkflowExecutionContextInterface
    {
        $this->variables[$name] = $value;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getVariable(string $name)
    {
        return $this->variables[$name] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function consumeStep(string $stepId) : void
    {
        $this->stepsConsumed++;
        if ($this->stepsConsumed > $this->maxSteps) {
            throw new InvalidArgumentException(
                'Graph workflow exceeded max_steps at step "' . $stepId . '"'
            );
        }
    }

    /** {@inheritDoc} */
    public function enterWorkflow(string $workflowAlias) : void
    {
        if (in_array($workflowAlias, $this->workflowStack, true)) {
            throw new InvalidArgumentException(
                'Recursive AI workflow call detected for "' . $workflowAlias . '"'
            );
        }
        if (count($this->workflowStack) >= self::MAX_WORKFLOW_DEPTH) {
            throw new InvalidArgumentException(
                'AI workflow nesting exceeds maximum depth ' . self::MAX_WORKFLOW_DEPTH
            );
        }
        $this->workflowStack[] = $workflowAlias;
    }

    /** {@inheritDoc} */
    public function leaveWorkflow(string $workflowAlias) : void
    {
        $activeAlias = array_pop($this->workflowStack);
        if ($activeAlias !== $workflowAlias) {
            throw new \LogicException(
                'AI workflow execution stack is inconsistent: expected "'
                . $workflowAlias . '", got "' . ($activeAlias ?? '') . '"'
            );
        }
    }

    /** {@inheritDoc} */
    public function getRunLog() : WorkflowRunLog
    {
        return $this->runLog;
    }

    /** {@inheritDoc} */
    public function getParentStepUid() : ?string
    {
        return $this->parentStepUid;
    }

    /** {@inheritDoc} */
    public function setParentStepUid(?string $stepUid) : AiWorkflowExecutionContextInterface
    {
        $this->parentStepUid = $stepUid;
        return $this;
    }

    /** {@inheritDoc} */
    public function getCurrentStepUid() : ?string
    {
        return $this->currentStepUid;
    }

    /** {@inheritDoc} */
    public function setCurrentStepUid(?string $stepUid) : AiWorkflowExecutionContextInterface
    {
        $this->currentStepUid = $stepUid;
        return $this;
    }
}