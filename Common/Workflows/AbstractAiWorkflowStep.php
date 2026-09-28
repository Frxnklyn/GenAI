<?php
namespace axenox\GenAI\Common\Workflows;

use axenox\GenAI\Interfaces\AiWorkflowStepInterface;
use axenox\GenAI\Interfaces\Selectors\AiWorkflowStepSelectorInterface;
use axenox\GenAI\Uxon\AiWorkflowStepUxonSchema;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\DataSheetMapperFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSheets\DataSheetMapperInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Base class for configurable workflow steps.
 */
abstract class AbstractAiWorkflowStep implements AiWorkflowStepInterface
{
    use ICanBeConvertedToUxonTrait;

    private AiWorkflowStepSelectorInterface $selector;

    private string $id = '';

    private string $caption = '';

    private ?string $color = null;

    private ?UxonObject $inputMapperUxon = null;

    private ?UxonObject $outputMapperUxon = null;

    /**
     * Creates a step and imports its UXON configuration.
     */
    public function __construct(AiWorkflowStepSelectorInterface $selector, UxonObject $uxon = null)
    {
        $this->selector = $selector;
        $this->importUxonObject($uxon ?? new UxonObject());
        if ($this->id === '') {
            throw new InvalidArgumentException('AI workflow steps require a non-empty id');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->selector->getWorkbench();
    }

    /**
     * Returns the prototype selector used to construct this step.
     */
    public function getSelector() : AiWorkflowStepSelectorInterface
    {
        return $this->selector;
    }

    /**
     * {@inheritDoc}
     */
    public function getId() : string
    {
        return $this->id;
    }

    /**
     * {@inheritDoc}
     */
    public function getCaption() : string
    {
        return $this->caption === '' ? $this->id : $this->caption;
    }

    /**
     * {@inheritDoc}
     */
    public function getColor() : ?string
    {
        return $this->color;
    }

    /**
     * {@inheritDoc}
     */
    public function getTextColor() : string
    {
        if ($this->color === null) {
            return '#111827';
        }
        $hex = ltrim($this->color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));
        return (($red * 299 + $green * 587 + $blue * 114) / 1000) >= 150
            ? '#111827'
            : '#ffffff';
    }

    /**
     * {@inheritDoc}
     */
    public function getExpectedOutcomes() : array
    {
        return ['success'];
    }

    /**
     * Selects the polymorphic UXON schema for nested step aliases.
     */
    public static function getUxonSchemaClass() : ?string
    {
        return AiWorkflowStepUxonSchema::class;
    }

    /**
     * Maps the current workflow data into this step's input shape.
     */
    protected function mapInput(DataSheetInterface $data) : DataSheetInterface
    {
        if ($this->inputMapperUxon === null) {
            return $data->copy();
        }
        return $this->createMapper($this->inputMapperUxon, $data)->map($data);
    }

    /**
     * Maps this step's structured result into the next workflow state.
     */
    protected function mapOutput(DataSheetInterface $data) : DataSheetInterface
    {
        if ($this->outputMapperUxon === null) {
            return $data->copy();
        }
        return $this->createMapper($this->outputMapperUxon, $data)->map($data);
    }

    /**
     * Returns whether this step has an explicit input mapper.
     */
    protected function hasInputMapper() : bool
    {
        return $this->inputMapperUxon !== null;
    }

    /**
     * Returns whether this step has an explicit output mapper.
     */
    protected function hasOutputMapper() : bool
    {
        return $this->outputMapperUxon !== null;
    }

    /**
     * Sets the transition-safe step identifier.
     *
     * @uxon-property id
     * @uxon-type string
     * @uxon-required true
     */
    protected function setId(string $id) : AbstractAiWorkflowStep
    {
        $id = trim($id);
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $id) !== 1) {
            throw new InvalidArgumentException(
                'AI workflow step IDs must start with a letter and contain only letters, digits and underscores'
            );
        }
        $this->id = $id;
        return $this;
    }

    /**
     * Sets the human-readable step label.
     *
     * @uxon-property caption
     * @uxon-type string
     */
    protected function setCaption(string $caption) : AbstractAiWorkflowStep
    {
        $this->caption = trim($caption);
        return $this;
    }

    /**
     * Sets the node color used by Mermaid previews.
     *
     * @uxon-property color
     * @uxon-type color
     */
    protected function setColor(string $color) : AbstractAiWorkflowStep
    {
        $color = trim($color);
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) !== 1) {
            throw new InvalidArgumentException('AI workflow step colors must be hexadecimal CSS colors');
        }
        $this->color = $color;
        return $this;
    }

    /**
     * Maps workflow data into this step's expected input object.
     *
     * @uxon-property input_mapper
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheetMapper
     */
    protected function setInputMapper(UxonObject $mapper) : AbstractAiWorkflowStep
    {
        $this->inputMapperUxon = $mapper->copy();
        return $this;
    }

    /**
     * Maps this step's structured output into the workflow state.
     *
     * @uxon-property output_mapper
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheetMapper
     */
    protected function setOutputMapper(UxonObject $mapper) : AbstractAiWorkflowStep
    {
        $this->outputMapperUxon = $mapper->copy();
        return $this;
    }

    /**
     * Builds a configured mapper for the current DataSheet object.
     */
    private function createMapper(UxonObject $uxon, DataSheetInterface $data) : DataSheetMapperInterface
    {
        return DataSheetMapperFactory::createFromUxon(
            $this->getWorkbench(),
            $uxon->copy(),
            $data->getMetaObject(),
            $data->getMetaObject()
        );
    }
}