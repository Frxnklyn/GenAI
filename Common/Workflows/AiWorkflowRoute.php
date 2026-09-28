<?php
namespace axenox\GenAI\Common\Workflows;

use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Configures one deterministic DataSheet value-to-agent route.
 */
class AiWorkflowRoute implements iCanBeConvertedToUxon
{
    use ICanBeConvertedToUxonTrait;

    private WorkbenchInterface $workbench;

    private string $attributeAlias = '';

    private array $values = [];

    private string $agentAlias = '';

    private string $color = '#5b8def';

    /**
     * Creates a route and imports its UXON configuration.
     */
    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon = null)
    {
        $this->workbench = $workbench;
        $this->importUxonObject($uxon ?? new UxonObject());
    }

    /**
     * Returns the DataSheet attribute inspected by this route.
     */
    public function getAttributeAlias() : string
    {
        return $this->attributeAlias;
    }

    /**
     * Returns the accepted values in configured order.
     */
    public function getValues() : array
    {
        return $this->values;
    }

    /**
     * Returns the target agent alias.
     */
    public function getAgentAlias() : string
    {
        return $this->agentAlias;
    }

    /**
     * Returns a safe hexadecimal color for Mermaid output.
     */
    public function getColor() : string
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $this->color) === 1
            ? $this->color
            : '#5b8def';
    }

    /**
     * Returns black or white text, whichever has better contrast with the configured color.
     */
    public function getTextColor() : string
    {
        $hex = ltrim($this->getColor(), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));

        return (($red * 299 + $green * 587 + $blue * 114) / 1000) >= 128
            ? '#000'
            : '#fff';
    }

    /**
     * Checks whether any input value exactly matches a configured value, ignoring string case.
     */
    public function matches($inputValue, ?string $listDelimiter = null) : bool
    {
        $inputValues = is_array($inputValue) ? $inputValue : [$inputValue];
        if (is_string($inputValue) && $listDelimiter !== null && $listDelimiter !== '' && strpos($inputValue, $listDelimiter) !== false) {
            $inputValues = explode($listDelimiter, $inputValue);
        }

        $configuredValues = array_map([$this, 'normalizeValue'], $this->values);
        foreach ($inputValues as $value) {
            if (in_array($this->normalizeValue($value), $configuredValues, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Selects the DataSheet attribute whose value is inspected.
     *
     * @uxon-property attribute_alias
     * @uxon-type metamodel:attribute
     * @uxon-required true
     */
    protected function setAttributeAlias(string $attributeAlias) : AiWorkflowRoute
    {
        $this->attributeAlias = trim($attributeAlias);
        return $this;
    }

    /**
     * Defines the exact values accepted by this route.
     *
     * @uxon-property values
     * @uxon-type string[]
     * @uxon-template [""]
     * @uxon-required true
     */
    protected function setValues(UxonObject $values) : AiWorkflowRoute
    {
        $this->values = array_values($values->toArray());
        return $this;
    }

    /**
     * Selects the agent invoked when this route matches.
     *
     * @uxon-property agent_alias
     * @uxon-type metamodel:axenox.GenAI.AI_AGENT:ALIAS_WITH_NS
     * @uxon-required true
     */
    protected function setAgentAlias(string $agentAlias) : AiWorkflowRoute
    {
        $this->agentAlias = trim($agentAlias);
        return $this;
    }

    /**
     * Sets the route color used by Mermaid previews.
     *
     * @uxon-property color
     * @uxon-type color
     * @uxon-default #5b8def
     */
    protected function setColor(string $color) : AiWorkflowRoute
    {
        $this->color = trim($color);
        return $this;
    }

    /**
     * Normalizes scalar values for deterministic case-insensitive comparison.
     */
    private function normalizeValue($value) : string
    {
        return mb_strtolower(trim((string) $value));
    }
}