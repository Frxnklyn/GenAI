<?php
namespace axenox\GenAI\Common\Workflows;

use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepResultInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Immutable output of one graph workflow step.
 */
class AiWorkflowStepResult implements AiWorkflowStepResultInterface
{
    private string $outcome;

    private ?DataSheetInterface $data;

    private ?AiResponseInterface $response;

    /**
     * Creates a result with an outcome and optional data or response.
     */
    public function __construct(
        string $outcome = 'success',
        DataSheetInterface $data = null,
        AiResponseInterface $response = null
    ) {
        $this->outcome = $outcome;
        $this->data = $data;
        $this->response = $response;
    }

    /**
     * {@inheritDoc}
     */
    public function getOutcome() : string
    {
        return $this->outcome;
    }

    /**
     * {@inheritDoc}
     */
    public function getData() : ?DataSheetInterface
    {
        return $this->data;
    }

    /**
     * {@inheritDoc}
     */
    public function getResponse() : ?AiResponseInterface
    {
        return $this->response;
    }
}