<?php
namespace axenox\GenAI\Common\Workflows;

use axenox\GenAI\AI\WorkflowSteps\ActionStep;
use axenox\GenAI\AI\WorkflowSteps\AgentStep;
use axenox\GenAI\AI\WorkflowSteps\EndStep;
use axenox\GenAI\AI\WorkflowSteps\SubworkflowStep;
use axenox\GenAI\Interfaces\AiGraphWorkflowInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepInterface;
use axenox\GenAI\Interfaces\AiWorkflowTransitionInterface;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * Validates structural graph invariants while deliberately allowing cycles.
 */
class StepWorkflowValidator
{
    /**
     * Validates steps, transitions, entry, termination and reachability.
     */
    public function validate(AiGraphWorkflowInterface $workflow) : void
    {
        $this->validateGraph($workflow->getSteps(), $workflow->getTransitions());
    }

    /**
     * Validates a reusable graph definition.
     *
     * @param AiWorkflowStepInterface[] $steps
     * @param AiWorkflowTransitionInterface[] $transitions
     */
    public function validateGraph(array $steps, array $transitions) : void
    {
        $startIds = [];
        $endIds = [];
        foreach ($steps as $stepId => $step) {
            if ($step->getType() === 'start') {
                $startIds[] = $stepId;
            }
            if ($step->getType() === 'end') {
                $endIds[] = $stepId;
            }
        }
        if (count($startIds) !== 1) {
            throw new InvalidArgumentException('Graph workflows require exactly one StartStep');
        }
        if ($endIds === []) {
            throw new InvalidArgumentException('Graph workflows require at least one EndStep');
        }

        $adjacency = [];
        $transitionKeys = [];
        foreach ($transitions as $transition) {
            $from = $transition->getFromStep();
            $to = $transition->getToStep();
            if (! isset($steps[$from]) || ! isset($steps[$to])) {
                throw new InvalidArgumentException(
                    'Transition from "' . $from . '" to "' . $to . '" references an unknown step'
                );
            }
            if ($steps[$from]->getType() === 'end') {
                throw new InvalidArgumentException('End step "' . $from . '" cannot have outgoing transitions');
            }
            $key = $from . "\0" . $transition->getOutcome();
            if (isset($transitionKeys[$key])) {
                throw new InvalidArgumentException(
                    'Duplicate transition for step "' . $from . '" and outcome "' . $transition->getOutcome() . '"'
                );
            }
            $transitionKeys[$key] = true;
            $adjacency[$from][] = $to;
        }

        $reachable = [];
        $queue = [$startIds[0]];
        while ($queue !== []) {
            $stepId = array_shift($queue);
            if (isset($reachable[$stepId])) {
                continue;
            }
            $reachable[$stepId] = true;
            foreach ($adjacency[$stepId] ?? [] as $targetId) {
                $queue[] = $targetId;
            }
        }
        $unreachable = array_diff(array_keys($steps), array_keys($reachable));
        if ($unreachable !== []) {
            throw new InvalidArgumentException(
                'Graph workflow contains unreachable steps: ' . implode(', ', $unreachable)
            );
        }

        foreach ($endIds as $endId) {
            if (! isset($reachable[$endId])) {
                throw new InvalidArgumentException('End step "' . $endId . '" is not reachable');
            }
        }

        foreach ($steps as $stepId => $step) {
            $this->validateStepConfiguration($step, $steps);
            foreach ($step->getExpectedOutcomes() as $outcome) {
                if (! isset($transitionKeys[$stepId . "\0" . $outcome])) {
                    throw new InvalidArgumentException(
                        'Step "' . $stepId . '" requires a transition for outcome "' . $outcome . '"'
                    );
                }
            }
        }
    }

    /**
     * Validates references and required selectors that setters cannot validate in isolation.
     *
     * @param AiWorkflowStepInterface[] $steps
     */
    private function validateStepConfiguration(AiWorkflowStepInterface $step, array $steps) : void
    {
        if ($step instanceof AgentStep && $step->getAgentAlias() === '') {
            throw new InvalidArgumentException('Agent step "' . $step->getId() . '" requires agent_alias');
        }
        if ($step instanceof ActionStep && $step->getActionAlias() === '') {
            throw new InvalidArgumentException('Action step "' . $step->getId() . '" requires action.alias');
        }
        if ($step instanceof SubworkflowStep && $step->getWorkflowAlias() === '') {
            throw new InvalidArgumentException(
                'Subworkflow step "' . $step->getId() . '" requires workflow_alias'
            );
        }
        if ($step instanceof AbstractCompositeWorkflowStep) {
            $step->getGraph();
        }
        if (! $step instanceof EndStep) {
            return;
        }

        $responseStep = $step->getResponseStep();
        if ($responseStep === '' && ! $step->hasResponseTemplate()) {
            throw new InvalidArgumentException(
                'End step "' . $step->getId() . '" requires response_step or response_template'
            );
        }
        if ($responseStep !== '' && ! isset($steps[$responseStep])) {
            throw new InvalidArgumentException(
                'End step "' . $step->getId() . '" references unknown response_step "'
                . $responseStep . '"'
            );
        }
    }
}