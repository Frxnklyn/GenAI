<?php
namespace axenox\GenAI\Common\Workflows;

use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\iCanBeConvertedToUxon;

/**
 * One configurable color explanation in a workflow Mermaid legend.
 */
class AiWorkflowLegendItem implements iCanBeConvertedToUxon
{
    use ICanBeConvertedToUxonTrait;

    private string $caption = '';

    private string $color = '';

    /**
     * Creates and validates a legend item from UXON.
     */
    public function __construct(UxonObject $uxon)
    {
        $this->importUxonObject($uxon);
        if ($this->caption === '' || $this->color === '') {
            throw new InvalidArgumentException('AI workflow legend items require caption and color');
        }
    }

    /**
     * Returns the explanation shown in the legend.
     */
    public function getCaption() : string
    {
        return $this->caption;
    }

    /**
     * Returns the validated node color.
     */
    public function getColor() : string
    {
        return $this->color;
    }

    /**
     * Returns black or white text with readable contrast against the item color.
     */
    public function getTextColor() : string
    {
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
     * Sets the legend label.
     *
     * @uxon-property caption
     * @uxon-type string
     * @uxon-required true
     */
    protected function setCaption(string $caption) : AiWorkflowLegendItem
    {
        $this->caption = trim($caption);
        return $this;
    }

    /**
     * Sets the legend swatch color.
     *
     * @uxon-property color
     * @uxon-type color
     * @uxon-required true
     */
    protected function setColor(string $color) : AiWorkflowLegendItem
    {
        $color = trim($color);
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) !== 1) {
            throw new InvalidArgumentException('AI workflow legend colors must be hexadecimal CSS colors');
        }
        $this->color = $color;
        return $this;
    }
}