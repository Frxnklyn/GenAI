<?php
namespace axenox\GenAI\Interfaces;

/**
 * Represents a single status message that can be displayed in the AI response.
 * 
 * Status messages provide transparency about what tools or agents did.
 * They are rendered as colored banners in the chat interface.
 */
interface AiResponseStatusMessageInterface
{
    /**
     * Get the type of the status message (e.g., 'ok', 'error', 'info', 'warning')
     * 
     * @return string
     */
    public function getType(): string;

    /**
     * Get the text content of the message
     * 
     * @return string
     */
    public function getText(): string;

    /**
     * Get the display color for this message
     * 
     * @return string CSS color value (e.g., 'green', 'red', '#FF0000')
     */
    public function getColor(): string;

    /**
     * Get the rendered HTML for this message
     * 
     * @return string HTML that can be displayed in the chat
     */
    public function getHtml(): string;

    /**
     * Get the role for DeepChat format (typically 'ai')
     * 
     * @return string
     */
    public function getRole(): string;
}
