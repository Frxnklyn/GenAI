<?php
namespace axenox\GenAI\Common\Workflows;

use axenox\GenAI\Interfaces\AiResponseDataMapperInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiWorkflowExecutionContextInterface;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Applies a declared list of AI response mappings in order.
 */
class AiResponseDataMapper implements AiResponseDataMapperInterface
{
    use ICanBeConvertedToUxonTrait;

    private WorkbenchInterface $workbench;

    /** @var AiResponseDataMapping[] */
    private array $mappings = [];

    /**
     * Creates a response mapper from UXON.
     */
    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon)
    {
        $this->workbench = $workbench;
        $this->importUxonObject($uxon);
    }

    /** {@inheritDoc} */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }

    /** {@inheritDoc} */
    public function map(
        AiResponseInterface $response,
        AiWorkflowExecutionContextInterface $context
    ) : AiWorkflowExecutionContextInterface {
        foreach ($this->mappings as $mapping) {
            $mapping->apply($response, $context);
        }
        return $context;
    }

    /**
     * Defines explicit response source-to-state mappings.
     *
     * @uxon-property mappings
     * @uxon-type \axenox\GenAI\Common\Workflows\AiResponseDataMapping[]
     * @uxon-required true
     */
    protected function setMappings(UxonObject $mappings) : AiResponseDataMapper
    {
        $this->mappings = [];
        foreach ($mappings as $mapping) {
            $this->mappings[] = new AiResponseDataMapping(UxonObject::fromAnything($mapping));
        }
        return $this;
    }
}