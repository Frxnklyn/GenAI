<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use axenox\GenAI\Common\Workflows\WorkflowRunLog;

/**
 * Mutable execution state shared by steps while preserving the original prompt.
 */
interface AiWorkflowExecutionContextInterface
{
    /**
     * Returns the original prompt supplied to the root workflow.
     */
    public function getPrompt() : AiPromptInterface;

    /**
     * Returns the current structured workflow data.
     */
    public function getData() : DataSheetInterface;

    /**
     * Replaces the current structured workflow data with server-produced data.
     */
    public function setData(DataSheetInterface $data) : AiWorkflowExecutionContextInterface;

    /**
     * Stores an AI response under the ID of the step that produced it.
     */
    public function setResponse(string $stepId, AiResponseInterface $response) : AiWorkflowExecutionContextInterface;

    /**
     * Returns a previously stored AI response, or NULL when none exists.
     */
    public function getResponse(string $stepId) : ?AiResponseInterface;

    /**
     * Stores one explicitly mapped workflow variable.
     *
     * @param mixed $value
     */
    public function setVariable(string $name, $value) : AiWorkflowExecutionContextInterface;

    /**
     * Returns one explicitly mapped workflow variable.
     *
     * @return mixed
     */
    public function getVariable(string $name);

    /**
     * Consumes one unit from the shared execution budget.
     */
    public function consumeStep(string $stepId) : void;

    /**
     * Enters a nested workflow and rejects cycles or excessive nesting.
     */
    public function enterWorkflow(string $workflowAlias) : void;

    /**
     * Leaves the currently active workflow.
     */
    public function leaveWorkflow(string $workflowAlias) : void;

    /**
     * Returns the audit log shared by the complete workflow run.
     */
    public function getRunLog() : WorkflowRunLog;

    /**
     * Returns the parent log step for nested execution.
     */
    public function getParentStepUid() : ?string;

    /**
     * Sets the parent log step for nested execution.
     */
    public function setParentStepUid(?string $stepUid) : AiWorkflowExecutionContextInterface;

    /**
     * Returns the currently executing log step UID.
     */
    public function getCurrentStepUid() : ?string;

    /**
     * Sets the currently executing log step UID.
     */
    public function setCurrentStepUid(?string $stepUid) : AiWorkflowExecutionContextInterface;
}