<?php
namespace axenox\GenAI\Common\Selectors;

use axenox\GenAI\Interfaces\Selectors\AiCriterionSelectorInterface;
use exface\Core\CommonLogic\Selectors\AbstractSelector;
use exface\Core\CommonLogic\Selectors\Traits\ResolvableNameSelectorTrait;

/**
 * Generic implementation of the AiMetricSelectorInterface.
 * 
 * @see AiCriterionSelectorInterface
 * 
 * @author Andrej Kabachnik
 *
 */
class AiCriterionSelector extends AbstractSelector implements AiCriterionSelectorInterface
{
    use ResolvableNameSelectorTrait;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Selectors\SelectorInterface::getComponentType()
     */
    public function getComponentType() : string
    {
        return 'AI test criterion';
    }
}