<?php
namespace axenox\GenAI\AI\Workflows;

use axenox\GenAI\Common\AbstractAiWorkflow;
use axenox\GenAI\Common\Workflows\AiWorkflowExecutionContext;
use axenox\GenAI\Common\Workflows\AiWorkflowTransition;
use axenox\GenAI\Common\Workflows\StepWorkflowRunner;
use axenox\GenAI\Common\Workflows\StepWorkflowMermaidRenderer;
use axenox\GenAI\Common\Workflows\StepWorkflowValidator;
use axenox\GenAI\Common\Workflows\WorkflowRunLog;
use axenox\GenAI\Factories\AiWorkflowStepFactory;
use axenox\GenAI\Interfaces\AiGraphWorkflowInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepInterface;
use axenox\GenAI\Interfaces\AiWorkflowTransitionInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use axenox\GenAI\Uxon\AiWorkflowStepUxonSchema;

/**
 * Executes an Action-like UXON graph of AI workflow steps and transitions.
 */
class StepWorkflow extends AbstractAiWorkflow implements AiGraphWorkflowInterface
{
    private const MAX_STEPS_HARD_LIMIT = 10000;

    /** @var AiWorkflowStepInterface[] */
    private array $steps = [];

    /** @var AiWorkflowTransitionInterface[] */
    private array $transitions = [];

    private int $maxSteps = 100;

    private bool $validated = false;

    /** {@inheritDoc} */
    public function handle(AiPromptInterface $prompt) : AiResponseInterface
    {
        $runLog = new WorkflowRunLog(
            $this->getWorkbench(),
            $this->getAliasWithNamespace(),
            $this->getAliasWithNamespace()
        );
        $runLog->start();
        try {
            $response = $this->executeInContext(
                new AiWorkflowExecutionContext($prompt, $this->maxSteps, $runLog)
            );
            $runLog->finish(WorkflowRunLog::STATUS_COMPLETED, 'success');
            return $response;
        } catch (\Throwable $error) {
            $runLog->finish(WorkflowRunLog::STATUS_FAILED, 'error', $error);
            throw $error;
        }
    }

    /** {@inheritDoc} */
    public function executeInContext(AiWorkflowExecutionContextInterface $context) : AiResponseInterface
    {
        $this->validate();
        $workflowAlias = $this->getAliasWithNamespace();
        $context->enterWorkflow($workflowAlias);
        try {
            return (new StepWorkflowRunner())->execute($this, $context);
        } finally {
            $context->leaveWorkflow($workflowAlias);
        }
    }

    /** {@inheritDoc} */
    public function getSteps() : array
    {
        return $this->steps;
    }

    /** {@inheritDoc} */
    public function getTransitions() : array
    {
        return $this->transitions;
    }

    /** {@inheritDoc} */
    public function getDescription() : string
    {
        return 'Executes a configurable graph of agents, actions, decisions and nested workflows.';
    }

    /**
     * Uses the polymorphic step schema for nested `steps[].alias` autosuggest.
     */
    public static function getUxonSchemaClass() : ?string
    {
        return AiWorkflowStepUxonSchema::class;
    }

    /**
     * Renders this graph from the same step and transition objects used at runtime.
     */
    public function getMermaidDiagram() : string
    {
        $this->validate();
        return $this->appendMermaidLegend(
            (new StepWorkflowMermaidRenderer($this->getWorkbench()))->render($this)
        );
    }

    /**
     * Defines the polymorphic workflow steps.
     *
     * @uxon-property steps
     * @uxon-type \axenox\GenAI\Common\Workflows\AbstractAiWorkflowStep[]
     * @uxon-template [{"alias":"axenox.GenAI.StartStep","id":"start"},{"alias":"axenox.GenAI.EndStep","id":"end","response_template":"Done"}]
     * @uxon-required true
     */
    protected function setSteps(UxonObject $steps) : StepWorkflow
    {
        $this->steps = [];
        foreach ($steps as $stepUxon) {
            $step = AiWorkflowStepFactory::createFromUxon(
                $this->getWorkbench(),
                UxonObject::fromAnything($stepUxon)
            );
            if (isset($this->steps[$step->getId()])) {
                throw new InvalidArgumentException('Duplicate AI workflow step id "' . $step->getId() . '"');
            }
            $this->steps[$step->getId()] = $step;
        }
        $this->validated = false;
        return $this;
    }

    /**
     * Defines outcome-driven transitions between steps.
     *
     * @uxon-property transitions
     * @uxon-type \axenox\GenAI\Common\Workflows\AiWorkflowTransition[]
     * @uxon-template [{"from_step":"start","outcome":"success","to_step":"end"}]
     * @uxon-required true
     */
    protected function setTransitions(UxonObject $transitions) : StepWorkflow
    {
        $this->transitions = [];
        foreach ($transitions as $transitionUxon) {
            $this->transitions[] = new AiWorkflowTransition(UxonObject::fromAnything($transitionUxon));
        }
        $this->validated = false;
        return $this;
    }

    /**
     * Sets the maximum number of executed steps, including loop iterations.
     *
     * @uxon-property max_steps
     * @uxon-type integer
     * @uxon-default 100
     */
    protected function setMaxSteps(int $maxSteps) : StepWorkflow
    {
        if ($maxSteps < 1 || $maxSteps > self::MAX_STEPS_HARD_LIMIT) {
            throw new InvalidArgumentException(
                'Graph workflow max_steps must be between 1 and ' . self::MAX_STEPS_HARD_LIMIT
            );
        }
        $this->maxSteps = $maxSteps;
        return $this;
    }

    /**
     * Validates the graph once after all UXON setters have run.
     */
    private function validate() : void
    {
        if (! $this->validated) {
            (new StepWorkflowValidator())->validate($this);
            $this->validated = true;
        }
    }

}