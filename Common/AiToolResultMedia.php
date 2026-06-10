<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\BinaryAiTooolResultInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;

/**
 * AI tool result carrying a normal textual response plus optional multimodal content blocks.
 */
class AiToolResultMedia extends AiToolResultString implements BinaryAiTooolResultInterface
{
    private array $contentBlocks;

    public function __construct(AiToolInterface $tool, array $arguments, mixed $value, DataTypeInterface $type = null, array $contentBlocks = [], array $appendix = [], array $exceptions = [])
    {
        parent::__construct($tool, $arguments, $value, $type, $appendix, $exceptions);
        $this->contentBlocks = $contentBlocks;
    }

    public function getValueAsContentBlocks(): array
    {
        return $this->contentBlocks;
    }
}