<?php
namespace axenox\GenAI\AI\WorkflowSteps;

use axenox\GenAI\Common\AiResponse;
use axenox\GenAI\Common\Workflows\AbstractAiWorkflowStep;
use axenox\GenAI\Common\Workflows\AiWorkflowStepResult;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use axenox\GenAI\Interfaces\AiWorkflowStepResultInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * Terminates a graph and selects or creates its final AI response.
 */
class EndStep extends AbstractAiWorkflowStep
{
    private string $responseStep = '';

    private ?string $responseTemplate = null;

    private ?array $responseJson = null;

    /** {@inheritDoc} */
    public function getType() : string
    {
        return 'end';
    }

    /** {@inheritDoc} */
    public function getExpectedOutcomes() : array
    {
        return [];
    }

    /**
     * Returns the configured response-producing step ID.
     */
    public function getResponseStep() : string
    {
        return $this->responseStep;
    }

    /**
     * Returns whether an explicit response template is configured.
     */
    public function hasResponseTemplate() : bool
    {
        return $this->responseTemplate !== null;
    }

    /** {@inheritDoc} */
    public function execute(AiWorkflowExecutionContextInterface $context) : AiWorkflowStepResultInterface
    {
        if ($this->responseStep !== '') {
            $response = $context->getResponse($this->responseStep);
            if ($response === null) {
                throw new InvalidArgumentException(
                    'End step "' . $this->getId() . '" cannot find response_step "' . $this->responseStep . '"'
                );
            }
        } elseif ($this->responseTemplate !== null) {
            $response = new AiResponse(
                $context->getPrompt(),
                $this->responseTemplate,
                '',
                $this->responseJson
            );
            $response->setData($context->getData());
        } else {
            throw new InvalidArgumentException(
                'End step "' . $this->getId() . '" requires response_step or response_template'
            );
        }

        return new AiWorkflowStepResult('success', $context->getData(), $response);
    }

    /**
     * Selects the step whose AI response is returned.
     *
     * @uxon-property response_step
     * @uxon-type string
     */
    protected function setResponseStep(string $stepId) : EndStep
    {
        $this->responseStep = trim($stepId);
        return $this;
    }

    /**
     * Sets an explicit response used when no AI-producing step is required.
     *
     * @uxon-property response_template
     * @uxon-type string
     */
    protected function setResponseTemplate(string $template) : EndStep
    {
        $this->responseTemplate = $template;
        return $this;
    }

    /**
     * Sets optional structured JSON returned with the explicit response.
     *
     * @uxon-property response_json
     * @uxon-type object
     */
    protected function setResponseJson(UxonObject $json) : EndStep
    {
        $this->responseJson = $json->toArray();
        return $this;
    }
}