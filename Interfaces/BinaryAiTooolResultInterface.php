<?php
namespace axenox\GenAI\Interfaces;

/**
 * Tool result interface for binary/media outputs that can provide multimodal content blocks.
 */
interface BinaryAiTooolResultInterface extends AiToolResultInterface
{
    /**
     * Returns optional multimodal content blocks to send to the LLM after the textual tool response.
     *
     * Media tools may return Responses API compatible blocks such as `input_image` or `input_file`.
     *
     * @return array
     */
    public function getValueAsContentBlocks() : array;
}
