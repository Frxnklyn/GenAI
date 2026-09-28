<?php
namespace axenox\GenAI\Common\Workflows;

use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\ConditionGroupFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * One ordered ConditionGroup case of a SwitchStep.
 */
class AiWorkflowSwitchCase implements iCanBeConvertedToUxon
{
    use ICanBeConvertedToUxonTrait;

    private WorkbenchInterface $workbench;

    private string $outcome = '';

    private string $caption = '';

    private ?UxonObject $conditionsUxon = null;

    /**
     * Creates one switch case from UXON.
     */
    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon)
    {
        $this->workbench = $workbench;
        $this->importUxonObject($uxon);
        if ($this->outcome === '' || $this->conditionsUxon === null) {
            throw new InvalidArgumentException('Switch cases require outcome and conditions');
        }
    }

    /**
     * Returns whether this case matches one input row.
     */
    public function matches(DataSheetInterface $data, int $rowIndex) : bool
    {
        return ConditionGroupFactory::createFromUxon(
            $this->workbench,
            $this->conditionsUxon->copy(),
            $data->getMetaObject()
        )->evaluate($data, $rowIndex);
    }

    /** Returns the transition outcome. */
    public function getOutcome() : string
    {
        return $this->outcome;
    }

    /** Returns the case caption. */
    public function getCaption() : string
    {
        return $this->caption === '' ? $this->outcome : $this->caption;
    }

    /**
     * @uxon-property outcome
     * @uxon-type string
     * @uxon-required true
     */
    protected function setOutcome(string $outcome) : AiWorkflowSwitchCase
    {
        $this->outcome = strtolower(trim($outcome));
        return $this;
    }

    /**
     * @uxon-property caption
     * @uxon-type string
     */
    protected function setCaption(string $caption) : AiWorkflowSwitchCase
    {
        $this->caption = trim($caption);
        return $this;
    }

    /**
     * @uxon-property conditions
     * @uxon-type \exface\Core\CommonLogic\Model\ConditionGroup
     * @uxon-required true
     */
    protected function setConditions(UxonObject $conditions) : AiWorkflowSwitchCase
    {
        $this->conditionsUxon = $conditions->copy();
        return $this;
    }
}