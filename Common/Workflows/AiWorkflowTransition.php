<?php
namespace axenox\GenAI\Common\Workflows;

use axenox\GenAI\Interfaces\AiWorkflowTransitionInterface;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * UXON-configurable transition between two workflow steps.
 */
class AiWorkflowTransition implements AiWorkflowTransitionInterface
{
    use ICanBeConvertedToUxonTrait;

    private string $fromStep = '';

    private string $outcome = 'success';

    private string $toStep = '';

    private string $caption = '';

    private string $color = '#52606d';

    /**
     * Creates a transition from UXON.
     */
    public function __construct(UxonObject $uxon)
    {
        $this->importUxonObject($uxon);
        if ($this->fromStep === '' || $this->toStep === '') {
            throw new InvalidArgumentException('AI workflow transitions require from_step and to_step');
        }
    }

    /** {@inheritDoc} */
    public function getFromStep() : string
    {
        return $this->fromStep;
    }

    /** {@inheritDoc} */
    public function getOutcome() : string
    {
        return $this->outcome;
    }

    /** {@inheritDoc} */
    public function getToStep() : string
    {
        return $this->toStep;
    }

    /** {@inheritDoc} */
    public function getCaption() : string
    {
        return $this->caption;
    }

    /** {@inheritDoc} */
    public function getColor() : string
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $this->color) === 1
            ? $this->color
            : '#52606d';
    }

    /**
     * @uxon-property from_step
     * @uxon-type string
     * @uxon-required true
     */
    protected function setFromStep(string $stepId) : AiWorkflowTransition
    {
        $this->fromStep = trim($stepId);
        return $this;
    }

    /**
     * @uxon-property outcome
     * @uxon-type string
     * @uxon-default success
     */
    protected function setOutcome(string $outcome) : AiWorkflowTransition
    {
        $this->outcome = strtolower(trim($outcome));
        return $this;
    }

    /**
     * @uxon-property to_step
     * @uxon-type string
     * @uxon-required true
     */
    protected function setToStep(string $stepId) : AiWorkflowTransition
    {
        $this->toStep = trim($stepId);
        return $this;
    }

    /**
     * @uxon-property caption
     * @uxon-type string
     */
    protected function setCaption(string $caption) : AiWorkflowTransition
    {
        $this->caption = trim($caption);
        return $this;
    }

    /**
     * @uxon-property color
     * @uxon-type color
     */
    protected function setColor(string $color) : AiWorkflowTransition
    {
        $this->color = trim($color);
        return $this;
    }
}